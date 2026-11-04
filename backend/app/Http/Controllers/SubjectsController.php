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
        return response()->json($this->subjects->list(
            $request->query('schoolId'),
            $request->query('classId'),
            $request->query('sectionId')
        ));
    }

    public function store(Request $request): JsonResponse
    {
        $input = $this->validateRequest($request, [
            'schoolId' => ['required', 'uuid'],
            'code' => ['required', 'string', 'min:1', 'max:32'],
            'name' => ['required', 'string', 'min:1', 'max:128'],
            'classId' => ['sometimes', 'nullable', 'uuid'],
            'sectionId' => ['sometimes', 'nullable', 'uuid'],
            'defaultPrice' => ['sometimes', 'numeric', 'min:0', 'decimal:0,2'],
        ]);

        return response()->json($this->subjects->create(
            $input,
            $request->attributes->get('apiIdentity')['userId']
        ), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->validateUuid($id);
        $input = $this->validateRequest($request, [
            'code' => ['sometimes', 'nullable', 'string', 'min:1', 'max:32'],
            'name' => ['sometimes', 'nullable', 'string', 'min:1', 'max:128'],
        ]);

        return response()->json($this->subjects->update(
            $id,
            $input,
            $request->attributes->get('apiIdentity')['userId']
        ));
    }

    public function mapToClass(Request $request, string $id): JsonResponse
    {
        $this->validateUuid($id);
        $input = $this->validateRequest($request, ['classId' => ['required', 'uuid']]);

        return response()->json($this->subjects->mapToClass(
            $id,
            $input['classId'],
            $request->attributes->get('apiIdentity')['userId']
        ), 201);
    }

    public function assignTeacher(Request $request, string $id): JsonResponse
    {
        $this->validateUuid($id);
        $input = $this->validateRequest($request, [
            'classId' => ['required', 'uuid'],
            'sectionId' => ['sometimes', 'nullable', 'uuid'],
            'userId' => ['required', 'uuid'],
        ]);

        return response()->json($this->subjects->assignTeacher(
            $id,
            $input,
            $request->attributes->get('apiIdentity')['userId']
        ), 201);
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
