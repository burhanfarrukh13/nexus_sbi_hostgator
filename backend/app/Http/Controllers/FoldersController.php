<?php

namespace App\Http\Controllers;

use App\Services\FoldersService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class FoldersController
{
    public function __construct(private readonly FoldersService $folders)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->folders->listAll(
            $request->query('subjectId'),
            $request->query('classId'),
            $request->query('sectionId')
        ));
    }

    public function store(Request $request): JsonResponse
    {
        $input = $this->validateRequest($request, [
            'name' => ['required', 'string', 'min:1', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'subjectId' => ['sometimes', 'nullable', 'uuid:4'],
            'defaultPrice' => ['sometimes', 'nullable', 'numeric', 'min:0', 'decimal:0,2'],
        ]);
        if (array_key_exists('defaultPrice', $input) &&
            !$this->hasFinancePermission($request->attributes->get('apiIdentity')['adminPermissions'] ?? [])) {
            throw new AccessDeniedHttpException('Finance permission is required to set folder prices');
        }

        return response()->json($this->folders->create($input), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->validateUuid($id);
        $input = $this->validateRequest($request, [
            'name' => ['sometimes', 'nullable', 'string', 'min:1', 'max:120'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'coverImageUrl' => ['sometimes', 'nullable', 'string', 'max:512'],
            'subjectId' => ['sometimes', 'nullable', 'uuid:4'],
            'defaultPrice' => ['sometimes', 'nullable', 'numeric', 'min:0', 'decimal:0,2'],
        ]);
        $permissions = $request->attributes->get('apiIdentity')['adminPermissions'] ?? [];
        $canManageFolders = in_array('admin', $permissions, true) ||
            in_array('folder_manager', $permissions, true);
        if (!$canManageFolders && array_diff(array_keys($input), ['defaultPrice']) !== []) {
            throw new AccessDeniedHttpException('Folder manager permission is required');
        }
        if (array_key_exists('defaultPrice', $input) && !$this->hasFinancePermission($permissions)) {
            throw new AccessDeniedHttpException('Finance permission is required to set folder prices');
        }

        return response()->json($this->folders->update($id, $input));
    }

    public function destroy(string $id): JsonResponse
    {
        $this->validateUuid($id);
        $this->folders->delete($id);

        return response()->json(['success' => true]);
    }

    public function assignPermission(Request $request, string $id): JsonResponse
    {
        $this->validateUuid($id);
        $input = $this->validateRequest($request, ['userId' => ['required', 'uuid:4']]);

        return response()->json($this->folders->assignPermission($id, $input['userId']), 201);
    }

    public function revokePermission(Request $request): JsonResponse
    {
        $folderId = $request->route('id');
        $permissionId = $request->route('permissionId') ?? $folderId;

        if ($permissionId === null || $permissionId === '') {
            throw new BadRequestHttpException('Permission identifier is required');
        }

        $this->validateUuid($permissionId);
        if ($folderId !== null && $folderId !== '') {
            $this->validateUuid($folderId);
        }

        $this->folders->revokePermission($folderId !== null && $folderId !== '' ? $folderId : null, $permissionId);

        return response()->json(['success' => true]);
    }

    public function authorized(Request $request): JsonResponse
    {
        return response()->json($this->folders->authorized(
            $request->attributes->get('apiIdentity')['userId']
        ));
    }

    public function permissionsForUser(Request $request): JsonResponse
    {
        $input = $this->validateRequest($request, ['userId' => ['required', 'uuid:4']]);

        return response()->json($this->folders->permissionsForUser($input['userId']));
    }

    private function validateRequest(Request $request, array $rules): array
    {
        $validator = Validator::make($request->all(), $rules);
        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        $validated = $validator->validated();
        foreach (['description', 'coverImageUrl'] as $field) {
            if ($request->exists($field) && $request->input($field) === '') {
                $validated[$field] = '';
            }
        }

        return $validated;
    }

    private function validateUuid(string $id): void
    {
        if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $id)) {
            throw new BadRequestHttpException('Validation failed (uuid is expected)');
        }
    }

    private function hasFinancePermission(array $permissions): bool
    {
        return in_array('admin', $permissions, true) ||
            in_array('accountant', $permissions, true) ||
            in_array('accountant_fees', $permissions, true);
    }
}
