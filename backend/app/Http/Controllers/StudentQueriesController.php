<?php

namespace App\Http\Controllers;

use App\Services\StudentQueriesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class StudentQueriesController
{
    public function __construct(private readonly StudentQueriesService $queries)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $identity = $request->attributes->get('apiIdentity');
        if ($identity['role'] === 'admin') {
            $this->assertCanManage($identity['adminPermissions'] ?? []);

            return response()->json($this->queries->listAll());
        }

        return response()->json($this->queries->listForUser((string) $identity['userId']));
    }

    public function store(Request $request): JsonResponse
    {
        $identity = $request->attributes->get('apiIdentity');
        if ($identity['role'] !== 'student') {
            throw new AccessDeniedHttpException('Only student accounts can create support queries.');
        }

        $input = $request->validate([
            'title' => ['required', 'string', 'min:1', 'max:200'],
            'message' => ['required', 'string', 'min:1', 'max:4000'],
        ]);

        return response()->json(
            $this->queries->create((string) $identity['userId'], $input),
            201
        );
    }

    public function reply(Request $request, string $id): JsonResponse
    {
        $identity = $request->attributes->get('apiIdentity');
        if ($identity['role'] !== 'admin') {
            throw new AccessDeniedHttpException('Only administrators can respond to student queries.');
        }
        $this->assertCanManage($identity['adminPermissions'] ?? []);
        abort_unless(Str::isUuid($id), 400, 'Invalid query identifier.');

        $input = $request->validate([
            'response' => ['required', 'string', 'min:1', 'max:4000'],
        ]);

        return response()->json(
            $this->queries->reply($id, $input['response'], (string) $identity['userId'])
        );
    }

    private function assertCanManage(array $permissions): void
    {
        if (!in_array('admin', $permissions, true) && !in_array('student_query', $permissions, true)) {
            throw new AccessDeniedHttpException('Student query permission is required.');
        }
    }
}
