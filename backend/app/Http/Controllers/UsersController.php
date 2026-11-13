<?php

namespace App\Http\Controllers;

use App\Services\UsersService;
use App\Services\SubjectAccessService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class UsersController
{
    public function __construct(
        private readonly UsersService $users,
        private readonly SubjectAccessService $subjectAccess
    )
    {
    }

    public function index(Request $request): JsonResponse
    {
        $identity = $request->attributes->get('apiIdentity');
        $permissions = $identity['adminPermissions'] ?? [];
        $isSuperAdmin = in_array('admin', $permissions, true);
        $adminId = ($identity['role'] ?? null) === 'admin' && !$isSuperAdmin
            ? (string) $identity['userId']
            : null;
        $input = $this->validateQuery($request, ['email' => ['sometimes', 'string', 'email:rfc', 'min:3', 'max:254']]);
        $role = $isSuperAdmin ? null : 'student';
        if (array_key_exists('email', $input)) {
            $user = $this->users->findByEmailPublic($input['email'], $role, $adminId);

            return response()->json($user ? [$user] : []);
        }

        return response()->json($this->users->listUsers($role, $adminId));
    }

    public function store(Request $request): JsonResponse
    {
        $roleInput = $this->validateBody($request, [
            'role' => ['sometimes', 'nullable', 'in:student,admin'],
        ]);
        $role = $roleInput['role'] ?? 'student';
        $identity = $request->attributes->get('apiIdentity');
        if ($role !== 'student' && !in_array('admin', $identity['adminPermissions'] ?? [], true)) {
            throw new AccessDeniedHttpException('Only super admins can create admin accounts');
        }

        if ($role === 'student') {
            $input = $this->validateBody($request, [
                'email' => ['required', 'string', 'email:rfc', 'min:3', 'max:254', Rule::unique('users', 'email')],
                'fullName' => ['required', 'string', 'min:2', 'max:200'],
                'phone' => ['required', 'string', 'min:7', 'max:32'],
                'crn' => ['required', 'string', 'min:1', 'max:32', Rule::unique('users', 'studentId')],
                'subjectIds' => ['sometimes', 'array', 'max:100'],
                'subjectIds.*' => ['required', 'uuid', 'distinct', Rule::exists('subjects', 'id')],
            ]);
            $this->assertSubjectsAccessible($input['subjectIds'] ?? [], (string) $identity['userId']);

            return response()->json(
                $this->users->createStudent($input, (string) $identity['userId']),
                201
            );
        }

        $input = $this->validateBody($request, [
            'email' => ['required', 'string', 'email:rfc', 'min:3', 'max:254', Rule::unique('users', 'email')],
            'password' => ['required', 'string', 'min:12', 'max:200'],
            'fullName' => ['sometimes', 'nullable', 'string', 'min:2', 'max:200'],
        ]);

        return response()->json(
            $this->users->createUser($input['email'], $input['password'], $role, $input['fullName'] ?? null),
            201
        );
    }

    public function updateSubjects(Request $request, string $userId): JsonResponse
    {
        $this->validateUuid($userId);
        $input = $this->validateBody($request, [
            'subjectIds' => ['present', 'array', 'max:100'],
            'subjectIds.*' => ['required', 'uuid', 'distinct', Rule::exists('subjects', 'id')],
        ]);
        $identity = $request->attributes->get('apiIdentity');
        $this->subjectAccess->assertStudentAccessible((string) $identity['userId'], $userId);
        $this->assertSubjectsAccessible($input['subjectIds'], (string) $identity['userId']);
        $manageableSubjectIds = $this->subjectAccess->allowedSubjectIds((string) $identity['userId']);

        return response()->json($this->users->updateStudentSubjects(
            $userId,
            $input['subjectIds'],
            (string) $identity['userId'],
            $manageableSubjectIds
        ));
    }

    public function updateStudent(Request $request, string $userId): JsonResponse
    {
        $this->validateUuid($userId);
        $input = $this->validateBody($request, [
            'fullName' => ['required', 'string', 'min:2', 'max:200'],
            'email' => ['required', 'string', 'email:rfc', 'min:3', 'max:254', Rule::unique('users', 'email')->ignore($userId, 'id')],
            'phone' => ['required', 'string', 'min:7', 'max:32'],
            'crn' => ['required', 'string', 'min:1', 'max:32', Rule::unique('users', 'studentId')->ignore($userId, 'id')],
        ]);
        $identity = $request->attributes->get('apiIdentity');
        $this->subjectAccess->assertStudentAccessible((string) $identity['userId'], $userId);

        return response()->json($this->users->updateStudent($userId, $input, (string) $identity['userId']));
    }

    public function search(Request $request): JsonResponse
    {
        $identity = $request->attributes->get('apiIdentity');
        $permissions = $identity['adminPermissions'] ?? [];
        $isSuperAdmin = in_array('admin', $permissions, true);
        $isScopedTeamMember = ($identity['role'] ?? null) === 'admin' &&
            $this->subjectAccess->isScopedTeamMember((string) $identity['userId']);
        if (!$isSuperAdmin &&
            !in_array('password_manager', $permissions, true) &&
            !in_array('user_manager', $permissions, true) &&
            !$isScopedTeamMember) {
            throw new AccessDeniedHttpException('You do not have permission to search accounts.');
        }
        $role = $isSuperAdmin ? null : 'student';
        $adminId = ($identity['role'] ?? null) === 'admin' && $role === 'student'
            ? (string) $identity['userId']
            : null;

        return response()->json($this->users->searchByEmail((string) $request->query('email', ''), 25, $role, $adminId));
    }

    private function assertSubjectsAccessible(array $subjectIds, string $adminId): void
    {
        $allowed = $this->subjectAccess->allowedSubjectIds($adminId);
        if ($allowed !== null && array_diff($subjectIds, $allowed) !== []) {
            throw new AccessDeniedHttpException('You can only manage students in your assigned subjects.');
        }
    }

    private function validateBody(Request $request, array $rules): array
    {
        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        return $validator->validated();
    }

    private function validateQuery(Request $request, array $rules): array
    {
        $validator = Validator::make($request->query(), $rules);
        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        return $validator->validated();
    }

    private function validateUuid(string $id): void
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $id)) {
            throw new BadRequestHttpException('Validation failed (uuid is expected)');
        }
    }
}
