<?php

namespace App\Http\Controllers;

use App\Services\UsersService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class UsersController
{
    public function __construct(private readonly UsersService $users)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $identity = $request->attributes->get('apiIdentity');
        $permissions = $identity['adminPermissions'] ?? [];
        $isSuperAdmin = in_array('admin', $permissions, true);
        $input = $this->validateQuery($request, ['email' => ['sometimes', 'string', 'email:rfc', 'min:3', 'max:254']]);
        $role = $isSuperAdmin ? null : 'student';
        if (array_key_exists('email', $input)) {
            $user = $this->users->findByEmailPublic($input['email'], $role);

            return response()->json($user ? [$user] : []);
        }

        return response()->json($this->users->listUsers($role));
    }

    public function store(Request $request): JsonResponse
    {
        $input = $this->validateBody($request, [
            'email' => ['required', 'string', 'email:rfc', 'min:3', 'max:254'],
            'password' => ['required', 'string', 'min:12', 'max:200'],
            'role' => ['sometimes', 'nullable', 'in:student,admin'],
        ]);
        $identity = $request->attributes->get('apiIdentity');
        $role = $input['role'] ?? 'student';
        if ($role !== 'student' && !in_array('admin', $identity['adminPermissions'] ?? [], true)) {
            throw new AccessDeniedHttpException('Only super admins can create admin accounts');
        }

        return response()->json(
            $this->users->createUser($input['email'], $input['password'], $role),
            201
        );
    }

    public function search(Request $request): JsonResponse
    {
        $identity = $request->attributes->get('apiIdentity');
        $permissions = $identity['adminPermissions'] ?? [];
        $role = in_array('admin', $permissions, true) ? null : 'student';

        return response()->json($this->users->searchByEmail((string) $request->query('email', ''), 25, $role));
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
}
