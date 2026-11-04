<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class SubjectsService
{
    public function list(?string $schoolId, ?string $classId, ?string $sectionId): array
    {
        $query = DB::table('subjects');
        if ($schoolId) {
            $query->where('school_id', $schoolId);
        }
        if ($classId) {
            $query->where(function ($filter) use ($classId): void {
                $filter->where('class_id', $classId)
                    ->orWhereExists(function ($mapping) use ($classId): void {
                        $mapping->selectRaw('1')
                            ->from('class_subjects')
                            ->whereColumn('class_subjects.subject_id', 'subjects.id')
                            ->where('class_subjects.class_id', $classId);
                    });
            });
        }
        if ($sectionId) {
            $query->where(function ($filter) use ($sectionId): void {
                $filter->where('section_id', $sectionId)
                    ->orWhereExists(function ($assignment) use ($sectionId): void {
                        $assignment->selectRaw('1')
                            ->from('subject_teachers')
                            ->whereColumn('subject_teachers.subject_id', 'subjects.id')
                            ->where('subject_teachers.section_id', $sectionId);
                    });
            });
        }

        return $query->orderBy('name')->get()->map(fn (object $subject): array => $this->subjectResponse($subject))->all();
    }

    public function create(array $data, string $actorId): array
    {
        if (DB::table('subjects')
            ->where('school_id', $data['schoolId'])
            ->where('code', $data['code'])
            ->exists()) {
            throw new BadRequestHttpException(
                'A subject with code "'.$data['code'].'" already exists for this school.'
            );
        }

        $id = (string) Str::uuid();
        $now = now();
        DB::table('subjects')->insert([
            'id' => $id,
            'school_id' => $data['schoolId'],
            'code' => $data['code'],
            'name' => $data['name'],
            'class_id' => $data['classId'] ?? null,
            'section_id' => $data['sectionId'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if (!empty($data['classId']) && !DB::table('class_subjects')
            ->where('subject_id', $id)
            ->where('class_id', $data['classId'])
            ->exists()) {
            DB::table('class_subjects')->insert([
                'class_id' => $data['classId'],
                'subject_id' => $id,
                'created_at' => $now,
            ]);
        }

        $containerId = (string) Str::uuid();
        DB::table('folders')->insert([
            'id' => $containerId,
            'name' => $data['name'],
            'description' => null,
            'default_price' => number_format((float) ($data['defaultPrice'] ?? 0), 2, '.', ''),
            'coverImageUrl' => null,
            'subject_id' => $id,
            'createdAt' => $now,
            'updatedAt' => $now,
        ]);

        $metadata = [
            'actorId' => $actorId,
            'subjectId' => $id,
            'defaultPrice' => (float) ($data['defaultPrice'] ?? 0),
            'containerId' => $containerId,
        ];
        if (array_key_exists('classId', $data)) {
            $metadata['classId'] = $data['classId'];
        }
        if (array_key_exists('sectionId', $data)) {
            $metadata['sectionId'] = $data['sectionId'];
        }
        $this->audit('subject_created', $data['name'], $metadata, $now);

        return $this->subjectResponse(DB::table('subjects')->where('id', $id)->first());
    }

    public function update(string $id, array $patch, string $actorId): ?array
    {
        if ($patch !== []) {
            DB::table('subjects')->where('id', $id)->update([
                ...$patch,
                'updated_at' => now(),
            ]);
        }
        $this->audit('subject_updated', $id, ['actorId' => $actorId, 'patch' => $patch], now());
        $subject = DB::table('subjects')->where('id', $id)->first();

        return $subject ? $this->subjectResponse($subject) : null;
    }

    public function mapToClass(string $subjectId, string $classId, string $actorId): array
    {
        if (!DB::table('class_subjects')
            ->where('subject_id', $subjectId)
            ->where('class_id', $classId)
            ->exists()) {
            DB::table('class_subjects')->insert([
                'class_id' => $classId,
                'subject_id' => $subjectId,
                'created_at' => now(),
            ]);
            $this->audit('subject_mapped_to_class', $subjectId, [
                'actorId' => $actorId,
                'classId' => $classId,
            ], now());
        }

        return ['ok' => true];
    }

    public function assignTeacher(string $subjectId, array $data, string $actorId): array
    {
        $sectionId = $data['sectionId'] ?? null;
        $query = DB::table('subject_teachers')
            ->where('subject_id', $subjectId)
            ->where('class_id', $data['classId'])
            ->where('user_id', $data['userId']);
        $sectionId === null
            ? $query->whereNull('section_id')
            : $query->where('section_id', $sectionId);

        if (!$query->exists()) {
            DB::table('subject_teachers')->insert([
                'id' => (string) Str::uuid(),
                'subject_id' => $subjectId,
                'class_id' => $data['classId'],
                'section_id' => $sectionId,
                'user_id' => $data['userId'],
                'created_at' => now(),
            ]);
            $this->audit('subject_teacher_assigned', $subjectId, [
                'actorId' => $actorId,
                ...$data,
            ], now());
        }

        return ['ok' => true];
    }

    private function subjectResponse(object $subject): array
    {
        return [
            'id' => $subject->id,
            'schoolId' => $subject->school_id,
            'code' => $subject->code,
            'name' => $subject->name,
            'classId' => $subject->class_id,
            'sectionId' => $subject->section_id,
            'createdAt' => Carbon::parse($subject->created_at)->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'updatedAt' => Carbon::parse($subject->updated_at)->utc()->format('Y-m-d\TH:i:s.v\Z'),
        ];
    }

    private function audit(string $event, string $details, array $metadata, mixed $createdAt): void
    {
        DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid(),
            'event' => $event,
            'details' => $details,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'createdAt' => $createdAt,
        ]);
    }
}
