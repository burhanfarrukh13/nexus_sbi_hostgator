<?php

namespace App\Http\Controllers;

use App\Services\StudentsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class StudentsController
{
    public function __construct(private readonly StudentsService $students)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->students->list(
            $request->query('classId'),
            $request->query('sectionId'),
            $request->query('status')
        ));
    }

    public function show(string $id): JsonResponse
    {
        $this->validateUuid($id);

        return response()->json($this->students->get($id));
    }

    public function store(Request $request): JsonResponse
    {
        $input = $this->validateRequest($request, [
            'enrollmentNo' => ['required', 'string', 'min:1', 'max:32'],
            'fullName' => ['required', 'string', 'min:2', 'max:255'],
            'guardianName' => ['sometimes', 'nullable', 'string'],
            'guardianPhone' => ['sometimes', 'nullable', 'string', 'min:1', 'max:32'],
            'phone' => ['required', 'string', 'min:7', 'max:32'],
            'dob' => ['sometimes', 'nullable', 'string', 'regex:/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/'],
            'address' => ['sometimes', 'nullable', 'string'],
            'userId' => ['sometimes', 'nullable', 'uuid'],
            'currentClassId' => ['sometimes', 'nullable', 'uuid'],
            'currentSectionId' => ['sometimes', 'nullable', 'uuid'],
            'email' => ['required', 'string', 'email:rfc', 'min:3', 'max:254'],
        ]);

        return response()->json($this->students->create(
            $input,
            $request->attributes->get('apiIdentity')['userId']
        ), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $this->validateUuid($id);
        $input = $this->validateRequest($request, [
            'fullName' => ['sometimes', 'nullable', 'string', 'min:2', 'max:255'],
            'guardianName' => ['sometimes', 'nullable', 'string'],
            'guardianPhone' => ['sometimes', 'nullable', 'string', 'min:1', 'max:32'],
            'phone' => ['sometimes', 'nullable', 'string', 'min:1', 'max:32'],
            'dob' => ['sometimes', 'nullable', 'string', 'regex:/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/'],
            'address' => ['sometimes', 'nullable', 'string'],
            'userId' => ['sometimes', 'nullable', 'uuid'],
            'currentClassId' => ['sometimes', 'nullable', 'uuid'],
            'currentSectionId' => ['sometimes', 'nullable', 'uuid'],
            'status' => ['sometimes', 'nullable', 'string', 'in:active,left,graduated,suspended'],
        ]);

        return response()->json($this->students->update(
            $id,
            $input,
            $request->attributes->get('apiIdentity')['userId']
        ));
    }

    public function assign(Request $request, string $id): JsonResponse
    {
        $this->validateUuid($id);
        $input = $this->validateRequest($request, [
            'classId' => ['required', 'uuid'],
            'sectionId' => ['required', 'uuid'],
        ]);

        return response()->json($this->students->assign(
            $id,
            $input,
            $request->attributes->get('apiIdentity')['userId']
        ), 201);
    }

    public function destroy(Request $request, string $id): Response
    {
        $this->validateUuid($id);
        $this->students->archive($id, $request->attributes->get('apiIdentity')['userId']);

        return response('', 200);
    }

    public function permanentlyDestroy(Request $request, string $id): JsonResponse
    {
        $this->validateUuid($id);

        return response()->json($this->students->permanentlyDelete(
            $id,
            $request->attributes->get('apiIdentity')['userId']
        ));
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
            throw new BadRequestHttpException('Invalid identifier');
        }
    }
}
