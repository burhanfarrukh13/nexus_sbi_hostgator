<?php

namespace App\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class StudentsService
{
    public function list(?string $classId, ?string $sectionId, ?string $status): array
    {
        $query = DB::table('students')->orderBy('full_name');
        if ($classId) {
            $query->where('current_class_id', $classId);
        }
        if ($sectionId) {
            $query->where('current_section_id', $sectionId);
        }
        if ($status) {
            $query->where('status', $status);
        }

        return $query->get()
            ->map(fn (object $student): array => $this->studentResponse($student, true, true))
            ->all();
    }

    public function get(string $id): ?array
    {
        $student = DB::table('students')->where('id', $id)->first();
        if (!$student) {
            return null;
        }

        return $this->studentResponse($student, true, false);
    }

    public function create(array $data, string $actorId): array
    {
        $email = mb_strtolower(trim($data['email']));
        $enrollmentNo = trim($data['enrollmentNo']);
        $phone = trim($data['phone']);

        if (!empty($data['userId'])) {
            $user = DB::table('users')->where('id', $data['userId'])->first();
            if (!$user) {
                throw new RuntimeException("User {$data['userId']} not found");
            }
            if (mb_strtolower(trim($user->email)) !== $email || $user->role !== 'student') {
                throw new BadRequestHttpException(
                    'The selected login account must be a student account with the supplied email.'
                );
            }
        }

        if ($enrollmentNo !== '' && DB::table('students')->where('enrollment_no', $enrollmentNo)->exists()) {
            throw new BadRequestHttpException(
                'A student with CRN "'.$enrollmentNo.'" already exists.'
            );
        }

        $generatedPassword = null;
        $userId = $data['userId'] ?? null;
        if (!$userId) {
            if (DB::table('users')->whereRaw('LOWER(email) = ?', [$email])->exists()) {
                throw new BadRequestHttpException(
                    'A user with email "'.$email.'" already exists. Use that user\'s id only when creating a profile for the same email.'
                );
            }
            $generatedPassword = $this->generateDefaultPassword();
            $userId = $this->createStudentLogin($email, $generatedPassword);
        }

        $id = (string) Str::uuid();
        $now = now();
        $studentData = [
            'id' => $id,
            'enrollment_no' => $enrollmentNo,
            'full_name' => $data['fullName'],
            'guardian_name' => $data['guardianName'] ?? null,
            'guardian_phone' => $data['guardianPhone'] ?? null,
            'phone' => $phone,
            'dob' => $data['dob'] ?? null,
            'address' => $data['address'] ?? null,
            'joined_at' => $now->toDateString(),
            'status' => 'active',
            'user_id' => $userId,
            'current_class_id' => $data['currentClassId'] ?? null,
            'current_section_id' => $data['currentSectionId'] ?? null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        DB::table('students')->insert($studentData);

        $this->audit('student_created', $enrollmentNo, [
            'actorId' => $actorId,
            'studentId' => $id,
            'fullName' => $data['fullName'],
            'loginCreated' => $generatedPassword !== null,
        ]);

        $result = $this->studentResponse((object) $studentData, false, false);
        $result['email'] = $email;
        if ($generatedPassword !== null) {
            $result['generatedPassword'] = $generatedPassword;
        }

        return $result;
    }

    public function update(string $id, array $updates, string $actorId): ?array
    {
        $fieldMap = [
            'fullName' => 'full_name',
            'guardianName' => 'guardian_name',
            'guardianPhone' => 'guardian_phone',
            'phone' => 'phone',
            'dob' => 'dob',
            'address' => 'address',
            'userId' => 'user_id',
            'currentClassId' => 'current_class_id',
            'currentSectionId' => 'current_section_id',
            'status' => 'status',
        ];

        DB::transaction(function () use ($id, $updates, $fieldMap): void {
            $student = DB::table('students')->where('id', $id)->lockForUpdate()->first();
            if (!$student) {
                throw new NotFoundHttpException('Student not found');
            }
            $previousStatus = $student->status;
            $patch = [];
            foreach ($fieldMap as $input => $column) {
                if (array_key_exists($input, $updates)) {
                    $patch[$column] = $updates[$input];
                }
            }
            if ($patch !== []) {
                $patch['updated_at'] = now();
                DB::table('students')->where('id', $id)->update($patch);
            }

            $newStatus = $updates['status'] ?? $previousStatus;
            $linkedUserId = array_key_exists('userId', $updates)
                ? $updates['userId']
                : $student->user_id;
            if (!array_key_exists('status', $updates) ||
                $newStatus === $previousStatus ||
                !$linkedUserId) {
                return;
            }

            $this->setLinkedAccountState(
                (string) $linkedUserId,
                $newStatus !== 'active'
            );
        });

        $this->audit('student_updated', $id, [
            'actorId' => $actorId,
            'patch' => $this->redactStudentPatch($updates),
        ]);

        return $this->get($id);
    }

    public function archive(string $id, string $actorId): void
    {
        DB::transaction(function () use ($id): void {
            $student = DB::table('students')->where('id', $id)->lockForUpdate()->first();
            if (!$student) {
                throw new NotFoundHttpException('Student not found');
            }
            DB::table('students')->where('id', $id)->update([
                'status' => 'suspended',
                'updated_at' => now(),
            ]);

            if ($student->user_id) {
                $this->setLinkedAccountState((string) $student->user_id, true);
            }
        });

        $this->audit('student_archived', $id, [
            'actorId' => $actorId,
            'status' => 'suspended',
        ]);
    }

    public function permanentlyDelete(string $id, string $actorId): array
    {
        $deletedStudent = DB::transaction(function () use ($id): object {
            $student = DB::table('students')
                ->where('id', $id)
                ->lockForUpdate()
                ->first();
            if (!$student) {
                throw new NotFoundHttpException('Student not found');
            }

            $userId = $student->user_id;
            $this->deleteFolderAccessRecords($id, $userId);
            $this->deleteStudentFinanceRecords($id);
            $this->deleteStudentAttendanceAndCohortRecords($id);
            if ($userId) {
                $this->deleteLinkedUserRecords((string) $userId);
            }

            DB::table('students')->where('id', $id)->delete();
            if ($userId) {
                DB::table('users')->where('id', $userId)->delete();
            }

            return $student;
        });

        $this->audit('student_permanently_deleted', $deletedStudent->enrollment_no, [
            'actorId' => $actorId,
            'studentId' => $deletedStudent->id,
            'fullName' => $deletedStudent->full_name,
            'loginDeleted' => $deletedStudent->user_id !== null,
        ]);

        return ['deleted' => true];
    }

    public function assign(string $id, array $data, string $actorId): ?array
    {
        $class = DB::table('classes')->where('id', $data['classId'])->first();
        $section = DB::table('sections')->where('id', $data['sectionId'])->first();
        if (!$class || !$section) {
            throw new RuntimeException('class or section not found');
        }
        if ($section->class_id !== $class->id) {
            throw new RuntimeException('section does not belong to that class');
        }

        DB::table('students')->where('id', $id)->update([
            'current_class_id' => $data['classId'],
            'current_section_id' => $data['sectionId'],
            'updated_at' => now(),
        ]);
        $this->audit('student_assigned', $id, [
            'actorId' => $actorId,
            'classId' => $data['classId'],
            'sectionId' => $data['sectionId'],
        ]);

        return $this->get($id);
    }

    private function createStudentLogin(string $email, string $plainPassword): string
    {
        $passwordHash = Hash::make($plainPassword, ['rounds' => 12]);
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $id = (string) Str::uuid();
            try {
                DB::table('users')->insert([
                    'id' => $id,
                    'email' => $email,
                    'password' => $passwordHash,
                    'role' => 'student',
                    'studentId' => 'STU-'.strtoupper(substr(str_replace('-', '', (string) Str::uuid()), 0, 16)),
                    'refreshCounter' => 0,
                    'must_reset_password' => true,
                    'createdAt' => now(),
                    'updatedAt' => now(),
                ]);

                return $id;
            } catch (QueryException $exception) {
                if (!str_contains(mb_strtolower($exception->getMessage()), 'studentid') || $attempt === 4) {
                    throw $exception;
                }
            }
        }

        throw new RuntimeException('Unable to generate a unique student ID');
    }

    private function generateDefaultPassword(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
        $password = '';
        for ($index = 0; $index < 10; $index++) {
            $password .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $password;
    }

    private function setLinkedAccountState(string $userId, bool $disabled): void
    {
        DB::table('users')->where('id', $userId)->update([
            'disabled_at' => $disabled ? now() : null,
            'refreshTokenHash' => null,
            'previousRefreshTokenHash' => null,
            'refreshCounter' => DB::raw('COALESCE(refreshCounter, 0) + 1'),
            'updatedAt' => now(),
        ]);
        if ($disabled) {
            DB::table('licenses')
                ->where('userId', $userId)
                ->where('status', 'active')
                ->update(['status' => 'revoked']);
        }

    }

    private function deleteFolderAccessRecords(string $studentId, ?string $userId): void
    {
        if (!Schema::hasTable('folder_access_plans')) {
            return;
        }
        $plans = DB::table('folder_access_plans')
            ->where(function ($query) use ($studentId, $userId): void {
                $query->where('student_id', $studentId);
                if ($userId !== null) {
                    $query->orWhere('user_id', $userId);
                }
            })
            ->pluck('id')
            ->all();
        if ($plans === []) {
            return;
        }

        $installments = Schema::hasTable('folder_access_installments')
            ? DB::table('folder_access_installments')->whereIn('plan_id', $plans)->pluck('id')->all()
            : [];
        if ($installments !== [] && Schema::hasTable('folder_access_payments')) {
            DB::table('folder_access_payments')->whereIn('installment_id', $installments)->delete();
        }
        if ($installments !== []) {
            DB::table('folder_access_installments')->whereIn('id', $installments)->delete();
        }
        DB::table('folder_access_plans')->whereIn('id', $plans)->delete();
    }

    private function deleteStudentFinanceRecords(string $studentId): void
    {
        $studentFeeIds = $this->idsFor('student_fees', 'student_id', $studentId);
        $installmentIds = $studentFeeIds !== []
            ? $this->idsForIn('installments', 'student_fee_id', $studentFeeIds)
            : [];
        $paymentIds = $this->idsFor('payments', 'student_id', $studentId);

        if (Schema::hasTable('payment_allocations')) {
            $allocations = DB::table('payment_allocations');
            if ($paymentIds !== [] && $installmentIds !== []) {
                $allocations->where(function ($query) use ($paymentIds, $installmentIds): void {
                    $query->whereIn('payment_id', $paymentIds)
                        ->orWhereIn('installment_id', $installmentIds);
                });
            } elseif ($paymentIds !== []) {
                $allocations->whereIn('payment_id', $paymentIds);
            } elseif ($installmentIds !== []) {
                $allocations->whereIn('installment_id', $installmentIds);
            }
            if ($paymentIds !== [] || $installmentIds !== []) {
                $allocations->delete();
            }
        }
        if ($paymentIds !== [] && Schema::hasTable('receipts')) {
            DB::table('receipts')->whereIn('payment_id', $paymentIds)->delete();
        }
        if (Schema::hasTable('payments')) {
            DB::table('payments')->where('student_id', $studentId)->delete();
        }
        if ($installmentIds !== []) {
            DB::table('installments')->whereIn('id', $installmentIds)->delete();
        }
        if (Schema::hasTable('student_fees')) {
            DB::table('student_fees')->where('student_id', $studentId)->delete();
        }
        if (Schema::hasTable('discounts')) {
            DB::table('discounts')->where('student_id', $studentId)->delete();
        }
    }

    private function deleteStudentAttendanceAndCohortRecords(string $studentId): void
    {
        if (Schema::hasTable('attendance_entries')) {
            DB::table('attendance_entries')->where('student_id', $studentId)->delete();
        }
        if (Schema::hasTable('student_cohort_memberships')) {
            DB::table('student_cohort_memberships')->where('student_id', $studentId)->delete();
        }
    }

    private function deleteLinkedUserRecords(string $userId): void
    {
        if (Schema::hasTable('student_queries')) {
            DB::table('student_queries')->where('userId', $userId)->delete();
            DB::table('student_queries')->where('respondedById', $userId)->update(['respondedById' => null]);
        }
        if (Schema::hasTable('ai_notebook_user_prompts')) {
            DB::table('ai_notebook_user_prompts')->where('user_id', $userId)->delete();
        }
        if (Schema::hasTable('ai_notebook_chat_sessions')) {
            DB::table('ai_notebook_chat_sessions')->where('user_id', $userId)->delete();
        }
        if (Schema::hasTable('study_plans')) {
            DB::table('study_plans')->where('user_id', $userId)->delete();
        }
        if (Schema::hasTable('ai_notebook_sources')) {
            DB::table('ai_notebook_sources')->where('uploaded_by_user_id', $userId)->update(['uploaded_by_user_id' => null]);
        }
        if (Schema::hasTable('receipts')) {
            DB::table('receipts')->where('uploaded_by_user_id', $userId)->update(['uploaded_by_user_id' => null]);
        }
    }

    private function idsFor(string $table, string $column, string $value): array
    {
        return Schema::hasTable($table)
            ? DB::table($table)->where($column, $value)->pluck('id')->all()
            : [];
    }

    private function idsForIn(string $table, string $column, array $values): array
    {
        return Schema::hasTable($table)
            ? DB::table($table)->whereIn($column, $values)->pluck('id')->all()
            : [];
    }

    private function redactStudentPatch(array $patch): array
    {
        foreach (['guardianName', 'guardianPhone', 'phone', 'address', 'dob'] as $sensitiveField) {
            if (array_key_exists($sensitiveField, $patch)) {
                $patch[$sensitiveField] = '***REDACTED***';
            }
        }

        return $patch;
    }

    private function studentResponse(object $student, bool $withRelations, bool $withDevices): array
    {
        $response = [
            'id' => $student->id,
            'enrollmentNo' => $student->enrollment_no,
            'fullName' => $student->full_name,
            'guardianName' => $student->guardian_name,
            'guardianPhone' => $student->guardian_phone,
            'phone' => $student->phone,
            'dob' => $student->dob,
            'address' => $student->address,
            'joinedAt' => $student->joined_at,
            'status' => $student->status,
            'userId' => $student->user_id,
            'currentClassId' => $student->current_class_id,
            'currentSectionId' => $student->current_section_id,
            'createdAt' => $this->isoDate($student->created_at),
            'updatedAt' => $this->isoDate($student->updated_at),
        ];
        if (!$withRelations) {
            return $response;
        }

        $class = $student->current_class_id
            ? DB::table('classes')->where('id', $student->current_class_id)->first()
            : null;
        $section = $student->current_section_id
            ? DB::table('sections')->where('id', $student->current_section_id)->first()
            : null;
        $user = $student->user_id
            ? DB::table('users')->where('id', $student->user_id)->first()
            : null;
        $response['currentClass'] = $class ? $this->classResponse($class) : null;
        $response['currentSection'] = $section ? $this->sectionResponse($section) : null;
        $response['user'] = $user ? $this->userResponse($user, $withDevices) : null;

        return $response;
    }

    private function classResponse(object $class): array
    {
        return [
            'id' => $class->id,
            'schoolId' => $class->school_id,
            'numericLevel' => $class->numeric_level === null ? null : (int) $class->numeric_level,
            'displayName' => $class->display_name,
            'createdAt' => $this->isoDate($class->created_at),
            'updatedAt' => $this->isoDate($class->updated_at),
        ];
    }

    private function sectionResponse(object $section): array
    {
        return [
            'id' => $section->id,
            'classId' => $section->class_id,
            'name' => $section->name,
            'capacity' => $section->capacity === null ? null : (int) $section->capacity,
            'createdAt' => $this->isoDate($section->created_at),
            'updatedAt' => $this->isoDate($section->updated_at),
        ];
    }

    private function userResponse(object $user, bool $withDevices): array
    {
        $response = [
            'id' => $user->id,
            'email' => $user->email,
            'role' => $user->role,
            'studentId' => $user->studentId,
            'fullName' => $user->fullName,
            'phone' => $user->phone,
            'mustResetPassword' => (bool) $user->must_reset_password,
            'createdAt' => $this->isoDate($user->createdAt),
            'updatedAt' => $this->isoDate($user->updatedAt),
        ];
        if ($withDevices) {
            $response['devices'] = DB::table('devices')
                ->where('userId', $user->id)
                ->where('isActive', true)
                ->get()
                ->map(fn (object $device): array => [
                    'id' => $device->id,
                    'name' => $device->name,
                    'publicKey' => $device->publicKey,
                    'deviceFingerprint' => $device->deviceFingerprint,
                    'isActive' => (bool) $device->isActive,
                    'lastHeartbeatAt' => $device->lastHeartbeatAt
                        ? $this->isoDate($device->lastHeartbeatAt)
                        : null,
                    'createdAt' => $this->isoDate($device->createdAt),
                    'updatedAt' => $this->isoDate($device->updatedAt),
                ])
                ->all();
        }

        return $response;
    }

    private function audit(string $event, string $details, array $metadata): void
    {
        DB::table('audit_logs')->insert([
            'id' => (string) Str::uuid(),
            'event' => $event,
            'details' => $details,
            'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
            'createdAt' => now(),
        ]);
    }

    private function isoDate(mixed $value): string
    {
        return Carbon::parse($value)->utc()->format('Y-m-d\TH:i:s.v\Z');
    }
}
