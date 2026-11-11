<?php

namespace App\Http\Controllers;

use App\Services\SubjectsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class SubjectsController
{
    public function __construct(private readonly SubjectsService $subjects)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $identity = (array) $request->attributes->get('apiIdentity');
        $isAdmin = ($identity['role'] ?? null) === 'admin' ||
            in_array('admin', $identity['adminPermissions'] ?? [], true);

        return response()->json($this->subjects->list(
            $identity['userId'] ?? null,
            $isAdmin
        ));
    }

    public function store(Request $request): JsonResponse
    {
        $input = $this->validateRequest($request, [
            'code' => ['required', 'string', 'min:1', 'max:32'],
            'name' => ['required', 'string', 'min:1', 'max:128'],
        ]);

        return response()->json($this->subjects->create(
            $input,
            (string) $request->attributes->get('apiIdentity')['userId']
        ), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->validateUuid($id);
        $input = $this->validateRequest($request, [
            'code' => ['sometimes', 'required', 'string', 'min:1', 'max:32'],
            'name' => ['sometimes', 'required', 'string', 'min:1', 'max:128'],
        ]);

        return response()->json($this->subjects->update(
            $id,
            $input,
            (string) $request->attributes->get('apiIdentity')['userId']
        ));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        $this->validateUuid($id);
        $this->subjects->delete($id, (string) $request->attributes->get('apiIdentity')['userId']);

        return response()->json(['success' => true]);
    }

    private function validateRequest(Request $request, array $rules): array
    {
        $validator = Validator::make($request->all(), $rules);
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
