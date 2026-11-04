<?php

namespace App\Http\Controllers;

use App\Services\StudentQueriesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class StudentQueriesController
{
    public function __construct(private readonly StudentQueriesService $queries)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $input = $this->validateRequest($request, [
            'title' => ['required', 'string', 'min:1', 'max:200'],
            'message' => ['required', 'string', 'min:1', 'max:4000'],
        ]);
        $identity = $request->attributes->get('apiIdentity');

        return response()->json($this->queries->create($identity['userId'], $input), 201);
    }

    public function index(Request $request): JsonResponse
    {
        $identity = $request->attributes->get('apiIdentity');
        if ($identity['role'] === 'admin') {
            $this->assertQueryHandler($identity['adminPermissions'] ?? []);

            return response()->json($this->queries->listAll());
        }

        return response()->json($this->queries->listForUser($identity['userId']));
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $this->validateUuid($id);
        $identity = $request->attributes->get('apiIdentity');
        if ($identity['role'] === 'admin') {
            $this->assertQueryHandler($identity['adminPermissions'] ?? []);

            return response()->json($this->queries->findById($id));
        }

        return response()->json($this->queries->getForUser($identity['userId'], $id));
    }

    public function reply(Request $request, string $id): JsonResponse
    {
        $this->validateUuid($id);
        $input = $this->validateRequest($request, [
            'response' => ['required', 'string', 'min:1', 'max:4000'],
        ]);
        $identity = $request->attributes->get('apiIdentity');

        return response()->json($this->queries->reply($id, $input, $identity['userId']), 201);
    }

    private function assertQueryHandler(array $permissions): void
    {
        if (!in_array('admin', $permissions, true) && !in_array('student_query', $permissions, true)) {
            throw new AccessDeniedHttpException('Student query permission is required');
        }
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
