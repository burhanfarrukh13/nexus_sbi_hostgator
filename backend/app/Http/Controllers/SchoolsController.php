<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class SchoolsController
{
    public function index(): JsonResponse
    {
        $schools = DB::table('schools')->orderBy('name')->get();

        return response()->json($schools->map(fn (object $school): array => $this->schoolResponse($school))->all());
    }

    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'min:2', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'logoUrl' => ['sometimes', 'nullable', 'string', 'max:512'],
        ]);
        if ($validator->fails()) {
            throw ValidationException::withMessages($validator->errors()->toArray());
        }

        $input = $validator->validated();
        $id = (string) Str::uuid();
        $now = now();
        DB::table('schools')->insert([
            'id' => $id,
            'name' => $input['name'],
            'address' => $input['address'] ?? null,
            'phone' => $input['phone'] ?? null,
            'logo_url' => $input['logoUrl'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid(),
            'event' => 'school_created',
            'details' => $input['name'],
            'metadata' => json_encode([
                'actorId' => $request->attributes->get('apiIdentity')['userId'],
                'schoolId' => $id,
            ], JSON_THROW_ON_ERROR),
            'createdAt' => $now,
        ]);

        return response()->json($this->schoolResponse(DB::table('schools')->where('id', $id)->first()), 201);
    }

    private function schoolResponse(object $school): array
    {
        return [
            'id' => $school->id,
            'name' => $school->name,
            'address' => $school->address,
            'phone' => $school->phone,
            'logoUrl' => $school->logo_url,
            'createdAt' => Carbon::parse($school->created_at)->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'updatedAt' => Carbon::parse($school->updated_at)->utc()->format('Y-m-d\TH:i:s.v\Z'),
        ];
    }
}
