<?php

namespace App\Http\Controllers;

use App\Services\TeamMembersService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class TeamMembersController
{
    public function __construct(private readonly TeamMembersService $teamMembers)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json($this->teamMembers->list());
    }

    public function store(Request $request): JsonResponse
    {
        $input = $this->validateRequest($request, [
            'email' => ['required', 'string', 'email', 'min:3', 'max:254'],
            'password' => ['required', 'string', 'min:9', 'max:200'],
            'fullName' => ['sometimes', 'nullable', 'string', 'min:0', 'max:200'],
            'phone' => ['sometimes', 'nullable', 'string', 'min:0', 'max:32'],
            'permissions' => ['required', 'array', 'max:12'],
            'permissions.*' => ['required', 'string', 'distinct', Rule::in($this->allowedPermissions())],
        ]);
        $this->validateAllowedPortalPermissions($input['permissions']);

        return response()->json(
            $this->teamMembers->create($input, $request->attributes->get('apiIdentity')['userId']),
            201
        );
    }

    public function updatePermissions(Request $request, string $userId): JsonResponse
    {
        $this->validateUuid($userId);
        $input = $this->validateRequest($request, [
            'permissions' => ['present', 'array', 'max:12'],
            'permissions.*' => ['required', 'string', 'distinct', Rule::in($this->allowedPermissions())],
        ]);
        $this->validateAllowedPortalPermissions($input['permissions']);

        return response()->json($this->teamMembers->updatePermissions(
            $userId,
            $input['permissions'],
            $request->attributes->get('apiIdentity')['userId']
        ));
    }

    public function changePassword(Request $request, string $userId): JsonResponse
    {
        $this->validateUuid($userId);
        $input = $this->validateRequest($request, [
            'newPassword' => ['required', 'string', 'min:9', 'max:200'],
            'currentPassword' => ['sometimes', 'nullable', 'string', 'min:1', 'max:200'],
        ]);

        return response()->json($this->teamMembers->changePassword(
            $userId,
            $input,
            $request->attributes->get('apiIdentity')['userId']
        ), 201);
    }

    private function validateRequest(Request $request, array $rules): array
    {
        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            $messages = [];
            foreach ($validator->errors()->all() as $message) {
                $messages[] = $message;
            }
            throw ValidationException::withMessages(['message' => $messages]);
        }

        return $validator->validated();
    }

    private function validateUuid(string $userId): void
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $userId)) {
            throw new BadRequestHttpException('Validation failed (uuid is expected)');
        }
    }

    private function allowedPermissions(): array
    {
        return [
            'admin',
            'teacher_assistant',
            'user_manager',
            'password_manager',
            'testing_manager',
            'student_query',
        ];
    }

    private function validateAllowedPortalPermissions(array $permissions): void
    {
        $available = [
            'admin',
            'teacher_assistant',
            'user_manager',
            'password_manager',
            'testing_manager',
            'student_query',
        ];
        $unsupported = array_values(array_diff($permissions, $available));
        if ($unsupported !== []) {
            throw new BadRequestHttpException(
                'Permissions not available in the web portal: '.implode(', ', $unsupported).'.'
            );
        }
    }
}
