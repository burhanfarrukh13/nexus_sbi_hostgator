<?php

namespace Tests\Feature;

use App\Services\JwtTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class StudentsCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_filters_and_get_return_source_relations_and_active_devices(): void
    {
        $admin = $this->createUser('admin@example.test', ['admin']);
        [$schoolId, $classId, $sectionId] = $this->createClassScope();
        $studentUser = $this->createUser('student@example.test', [], 'student');
        $studentId = $this->createStudent('CRN-10', $studentUser, $classId, $sectionId);
        $otherClassId = $this->createClassScope()[1];
        $otherStudent = $this->createStudent('CRN-20', null, $otherClassId, null, 'left');

        $this->createDevice($studentUser, true);
        $this->createDevice($studentUser, false);
        $token = $this->token($admin);

        $response = $this->withToken($token)
            ->getJson('/api/students/list?classId='.$classId.'&sectionId='.$sectionId.'&status=active')
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.id', $studentId)
            ->assertJsonPath('0.currentClass.id', $classId)
            ->assertJsonPath('0.currentClass.schoolId', $schoolId)
            ->assertJsonPath('0.currentSection.id', $sectionId)
            ->assertJsonPath('0.user.id', $studentUser)
            ->assertJsonCount(1, '0.user.devices');

        self::assertSame('CRN-10', $response->json('0.enrollmentNo'));
        self::assertArrayNotHasKey('devices', $this->withToken($token)->getJson('/api/students/'.$studentId)->assertOk()->json('user'));
        $this->withToken($token)
            ->getJson('/api/students/'.$otherStudent)
            ->assertOk()
            ->assertJsonPath('status', 'left');
        $this->withToken($token)->getJson('/api/students/'.(string) Str::uuid())->assertOk()->assertContent('null');
    }

    public function test_school_year_schema_allows_only_one_current_year_per_school(): void
    {
        [$schoolId] = $this->createClassScope();
        $firstYear = (string) Str::uuid();
        DB::table('school_years')->insert([
            'id' => $firstYear,
            'school_id' => $schoolId,
            'label' => '2025-2026',
            'start_date' => '2025-09-01',
            'end_date' => '2026-06-30',
            'is_current' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertDatabaseHas('school_years', ['id' => $firstYear, 'is_current' => true]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('school_years')->insert([
            'id' => (string) Str::uuid(),
            'school_id' => $schoolId,
            'label' => '2026-2027',
            'start_date' => '2026-09-01',
            'end_date' => '2027-06-30',
            'is_current' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_create_generates_one_time_login_and_audits_without_logging_password(): void
    {
        $manager = $this->createUser('manager@example.test', ['user_manager']);
        $response = $this->withToken($this->token($manager))
            ->postJson('/api/students', [
                'enrollmentNo' => '  CRN-104  ',
                'fullName' => 'Example Student',
                'guardianName' => 'Guardian',
                'guardianPhone' => '+1 555 900 0000',
                'phone' => '+1 555 123 4567',
                'dob' => '2008-05-04',
                'address' => 'Example address',
                'email' => 'Student@Example.com',
            ])
            ->assertCreated()
            ->assertJsonPath('enrollmentNo', 'CRN-104')
            ->assertJsonPath('email', 'student@example.com')
            ->assertJsonPath('status', 'active');

        $studentId = $response->json('id');
        $generatedPassword = $response->json('generatedPassword');
        self::assertMatchesRegularExpression('/^[A-HJ-NP-Za-hj-np-z2-9]{10}$/', $generatedPassword);
        $student = DB::table('students')->where('id', $studentId)->first();
        $user = DB::table('users')->where('id', $student->user_id)->first();
        self::assertSame('student', $user->role);
        self::assertTrue((bool) $user->must_reset_password);
        self::assertTrue(Hash::check($generatedPassword, $user->password));
        self::assertSame('STU-', substr($user->studentId, 0, 4));
        self::assertSame('2008-05-04', $student->dob);

        $audit = DB::table('audit_logs')->where('event', 'student_created')->first();
        self::assertSame('CRN-104', $audit->details);
        self::assertStringNotContainsString($generatedPassword, $audit->metadata);
        self::assertSame([
            'actorId' => $manager,
            'studentId' => $studentId,
            'fullName' => 'Example Student',
            'loginCreated' => true,
        ], json_decode($audit->metadata, true));

        $this->withToken($this->token($manager))
            ->postJson('/api/students', [
                'enrollmentNo' => 'CRN-104',
                'fullName' => 'Duplicate',
                'phone' => '1234567',
                'email' => 'second@example.test',
            ])
            ->assertBadRequest()
            ->assertJsonPath('message', 'A student with CRN "CRN-104" already exists.');
    }

    public function test_create_can_link_only_a_matching_student_account_and_validates_dto(): void
    {
        $manager = $this->createUser('manager@example.test', ['user_manager']);
        $linkedUser = $this->createUser('linked@example.test', [], 'student');
        $this->withToken($this->token($manager))
            ->postJson('/api/students', [
                'enrollmentNo' => 'LINK-INVALID',
                'fullName' => 'Linked Student',
                'phone' => '1234567',
                'email' => ' linked@example.test ',
                'userId' => $linkedUser,
            ])
            ->assertBadRequest();
        $this->withToken($this->token($manager))
            ->postJson('/api/students', [
                'enrollmentNo' => 'LINK-1',
                'fullName' => 'Linked Student',
                'phone' => '1234567',
                'email' => 'linked@example.test',
                'userId' => $linkedUser,
            ])
            ->assertCreated()
            ->assertJsonPath('userId', $linkedUser)
            ->assertJsonMissingPath('generatedPassword');

        $adminUser = $this->createUser('team@example.test', [], 'admin');
        $this->withToken($this->token($manager))
            ->postJson('/api/students', [
                'enrollmentNo' => 'LINK-2',
                'fullName' => 'Mismatched Student',
                'phone' => '1234567',
                'email' => 'team@example.test',
                'userId' => $adminUser,
            ])
            ->assertBadRequest()
            ->assertJsonPath('message', 'The selected login account must be a student account with the supplied email.');

        $this->withToken($this->token($manager))
            ->postJson('/api/students', [
                'enrollmentNo' => '',
                'fullName' => 'A',
                'phone' => '123',
                'email' => 'bad',
            ])
            ->assertBadRequest();
    }

    public function test_update_status_invalidates_account_and_redacts_sensitive_audit_fields(): void
    {
        $manager = $this->createUser('manager@example.test', ['user_manager']);
        $studentUser = $this->createUser('student@example.test', [], 'student');
        [, $classId, $sectionId] = $this->createClassScope();
        $studentId = $this->createStudent('CRN-104', $studentUser, $classId, $sectionId);
        $deviceId = $this->createDevice($studentUser, true);
        $licenseId = (string) Str::uuid();
        DB::table('licenses')->insert([
            'id' => $licenseId,
            'userId' => $studentUser,
            'deviceId' => $deviceId,
            'status' => 'active',
            'createdAt' => now(),
            'updatedAt' => now(),
        ]);
        DB::table('users')->where('id', $studentUser)->update([
            'refreshTokenHash' => str_repeat('a', 64),
            'previousRefreshTokenHash' => str_repeat('b', 64),
        ]);
        $studentAccessToken = $this->token($studentUser);

        $this->withToken($this->token($manager))
            ->putJson('/api/students/'.$studentId, [
                'status' => 'suspended',
                'phone' => '123456789',
                'address' => 'Sensitive address',
                'currentClassId' => $classId,
            ])
            ->assertOk()
            ->assertJsonPath('status', 'suspended')
            ->assertJsonPath('user.id', $studentUser);

        $user = DB::table('users')->where('id', $studentUser)->first();
        self::assertNotNull($user->disabled_at);
        self::assertNull($user->refreshTokenHash);
        self::assertNull($user->previousRefreshTokenHash);
        self::assertSame(1, (int) $user->refreshCounter);
        $this->assertDatabaseHas('licenses', ['id' => $licenseId, 'status' => 'revoked']);
        $this->withToken($studentAccessToken)->getJson('/api/auth/me')->assertUnauthorized();
        $audit = DB::table('audit_logs')->where('event', 'student_updated')->first();
        self::assertSame([
            'actorId' => $manager,
            'patch' => [
                'status' => 'suspended',
                'phone' => '***REDACTED***',
                'address' => '***REDACTED***',
                'currentClassId' => $classId,
            ],
        ], json_decode($audit->metadata, true));

        $this->withToken($this->token($manager))
            ->putJson('/api/students/'.$studentId, ['status' => 'active'])
            ->assertOk()
            ->assertJsonPath('status', 'active');
        $user = DB::table('users')->where('id', $studentUser)->first();
        self::assertNull($user->disabled_at);
        self::assertSame(2, (int) $user->refreshCounter);
        $this->assertDatabaseHas('licenses', ['id' => $licenseId, 'status' => 'revoked']);
    }

    public function test_assignment_checks_section_ownership_and_returns_updated_relations(): void
    {
        $manager = $this->createUser('manager@example.test', ['user_manager']);
        [, $classId, $sectionId] = $this->createClassScope();
        [, $otherClassId] = $this->createClassScope();
        $studentId = $this->createStudent('CRN-ASSIGN', null);

        $this->withToken($this->token($manager))
            ->postJson('/api/students/'.$studentId.'/assign', [
                'classId' => $classId,
                'sectionId' => $sectionId,
            ])
            ->assertCreated()
            ->assertJsonPath('currentClass.id', $classId)
            ->assertJsonPath('currentSection.classId', $classId);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'student_assigned',
            'details' => $studentId,
        ]);

        $this->withToken($this->token($manager))
            ->postJson('/api/students/'.$studentId.'/assign', [
                'classId' => $otherClassId,
                'sectionId' => $sectionId,
            ])
            ->assertStatus(500);
        $this->withToken($this->token($manager))
            ->postJson('/api/students/'.(string) Str::uuid().'/assign', [
                'classId' => $classId,
                'sectionId' => $sectionId,
            ])
            ->assertCreated()
            ->assertContent('null');
    }

    public function test_archive_suspends_student_and_revokes_linked_account_session(): void
    {
        $manager = $this->createUser('manager@example.test', ['user_manager']);
        $studentUser = $this->createUser('student@example.test', [], 'student');
        $studentId = $this->createStudent('CRN-ARCHIVE', $studentUser);
        $this->withToken($this->token($manager))
            ->deleteJson('/api/students/'.$studentId)
            ->assertOk()
            ->assertContent('');

        $this->assertDatabaseHas('students', ['id' => $studentId, 'status' => 'suspended']);
        $user = DB::table('users')->where('id', $studentUser)->first();
        self::assertNotNull($user->disabled_at);
        self::assertSame(1, (int) $user->refreshCounter);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'student_archived',
            'details' => $studentId,
        ]);
    }

    public function test_permanent_delete_cleans_linked_grants_billing_rows_and_cohort_history(): void
    {
        $admin = $this->createUser('admin@example.test', ['admin']);
        $studentUser = $this->createUser('student@example.test', [], 'student');
        $studentId = $this->createStudent('CRN-DELETE', $studentUser);
        $folderId = $this->createFolder();
        $permissionId = (string) Str::uuid();
        DB::table('folder_permissions')->insert([
            'id' => $permissionId,
            'userId' => $studentUser,
            'folderId' => $folderId,
            'canView' => true,
            'billing_suspended' => false,
            'createdAt' => now(),
        ]);
        $planId = (string) Str::uuid();
        DB::table('folder_access_plans')->insert([
            'id' => $planId,
            'folder_id' => $folderId,
            'user_id' => $studentUser,
            'student_id' => $studentId,
        ]);
        $installmentId = (string) Str::uuid();
        DB::table('folder_access_installments')->insert([
            'id' => $installmentId,
            'plan_id' => $planId,
            'sequence_no' => 1,
            'kind' => 'one_time',
            'due_date' => '2026-11-03',
            'amount' => '10.00',
            'paid_total' => '10.00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('folder_access_payments')->insert([
            'id' => (string) Str::uuid(),
            'installment_id' => $installmentId,
            'entry_type' => 'payment',
            'amount' => '10.00',
            'method' => 'cash',
            'received_at' => now(),
            'created_at' => now(),
        ]);
        DB::table('student_cohort_memberships')->insert([
            'id' => (string) Str::uuid(),
            'student_id' => $studentId,
            'class_id' => null,
        ]);

        $this->withToken($this->token($admin))
            ->deleteJson('/api/students/'.$studentId.'/permanent')
            ->assertOk()
            ->assertExactJson(['deleted' => true]);

        $this->assertDatabaseMissing('students', ['id' => $studentId]);
        $this->assertDatabaseMissing('users', ['id' => $studentUser]);
        $this->assertDatabaseMissing('folder_permissions', ['id' => $permissionId]);
        $this->assertDatabaseMissing('folder_access_plans', ['id' => $planId]);
        $this->assertDatabaseMissing('folder_access_installments', ['id' => $installmentId]);
        $this->assertDatabaseCount('folder_access_payments', 0);
        $this->assertDatabaseCount('student_cohort_memberships', 0);
        $audit = DB::table('audit_logs')->where('event', 'student_permanently_deleted')->first();
        self::assertSame('CRN-DELETE', $audit->details);
        self::assertSame([
            'actorId' => $admin,
            'studentId' => $studentId,
            'fullName' => 'Example Student',
            'loginDeleted' => true,
        ], json_decode($audit->metadata, true));
    }

    public function test_student_route_permissions_validation_and_missing_records(): void
    {
        $admin = $this->createUser('admin@example.test', ['admin']);
        $teacher = $this->createUser('teacher@example.test', ['teacher_assistant']);
        $passwordManager = $this->createUser('password@example.test', ['password_manager']);
        $accountantExpenses = $this->createUser('expenses@example.test', ['accountant_expenses']);
        $studentId = $this->createStudent('CRN-PERMS', null);

        $this->withToken($this->token($teacher))->getJson('/api/students/list')->assertOk();
        $this->withToken($this->token($passwordManager))->getJson('/api/students/list')->assertOk();
        $this->withToken($this->token($teacher))->getJson('/api/students/'.$studentId)->assertForbidden();
        $this->withToken($this->token($accountantExpenses))->getJson('/api/students/list')->assertForbidden();
        $this->withToken($this->token($admin))->getJson('/api/students/not-a-uuid')->assertBadRequest()
            ->assertJsonPath('message', 'Invalid identifier');
        $this->withToken($this->token($admin))->putJson('/api/students/'.(string) Str::uuid(), ['fullName' => 'Unknown'])
            ->assertNotFound()
            ->assertJsonPath('message', 'Student not found');
        $this->withToken($this->token($admin))->deleteJson('/api/students/'.(string) Str::uuid())
            ->assertNotFound();
        $this->withToken($this->token($teacher))->postJson('/api/students', [])->assertForbidden();
        $this->withToken($this->token($teacher))->deleteJson('/api/students/'.$studentId.'/permanent')->assertForbidden();
    }

    private function createClassScope(): array
    {
        $schoolId = (string) Str::uuid();
        DB::table('schools')->insert([
            'id' => $schoolId,
            'name' => 'Nexus School',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $classId = (string) Str::uuid();
        DB::table('classes')->insert([
            'id' => $classId,
            'school_id' => $schoolId,
            'numeric_level' => 1,
            'display_name' => 'Grade 1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $sectionId = (string) Str::uuid();
        DB::table('sections')->insert([
            'id' => $sectionId,
            'class_id' => $classId,
            'name' => 'A',
            'capacity' => 40,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$schoolId, $classId, $sectionId];
    }

    private function createStudent(
        string $enrollmentNo,
        ?string $userId,
        ?string $classId = null,
        ?string $sectionId = null,
        string $status = 'active'
    ): string {
        $id = (string) Str::uuid();
        DB::table('students')->insert([
            'id' => $id,
            'enrollment_no' => $enrollmentNo,
            'full_name' => 'Example Student',
            'guardian_name' => null,
            'guardian_phone' => null,
            'phone' => '123456789',
            'dob' => null,
            'address' => null,
            'joined_at' => now()->toDateString(),
            'status' => $status,
            'user_id' => $userId,
            'current_class_id' => $classId,
            'current_section_id' => $sectionId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function createUser(string $email, array $permissions, string $role = 'admin'): string
    {
        $id = (string) Str::uuid();
        DB::table('users')->insert([
            'id' => $id,
            'email' => $email,
            'password' => Hash::make('current-account-password'),
            'role' => $role,
            'studentId' => $role === 'student' ? 'STU-'.strtoupper(str_replace('-', '', $id)) : null,
            'refreshCounter' => 0,
            'must_reset_password' => false,
            'createdAt' => now(),
            'updatedAt' => now(),
        ]);
        foreach ($permissions as $permission) {
            DB::table('user_role_assignments')->insert([
                'userId' => $id,
                'granteeId' => $id,
                'permission' => $permission,
                'grantedAt' => now(),
            ]);
        }

        return $id;
    }

    private function createDevice(string $userId, bool $isActive): string
    {
        $id = (string) Str::uuid();
        DB::table('devices')->insert([
            'id' => $id,
            'userId' => $userId,
            'deviceFingerprint' => 'fingerprint-'.$id,
            'name' => 'Student device',
            'publicKey' => 'public-key',
            'isActive' => $isActive,
            'createdAt' => now(),
            'updatedAt' => now(),
        ]);

        return $id;
    }

    private function createFolder(): string
    {
        $id = (string) Str::uuid();
        DB::table('folders')->insert([
            'id' => $id,
            'name' => 'Student folder',
            'description' => null,
            'default_price' => '0.00',
            'coverImageUrl' => null,
            'subject_id' => null,
            'createdAt' => now(),
            'updatedAt' => now(),
        ]);

        return $id;
    }

    private function token(string $userId): string
    {
        $user = DB::table('users')->where('id', $userId)->first();
        $permissions = DB::table('user_role_assignments')
            ->where('userId', $userId)
            ->orderBy('permission')
            ->pluck('permission')
            ->all();

        return app(JwtTokenService::class)->issue([
            'sub' => $userId,
            'email' => $user->email,
            'role' => $user->role,
            'adminPermissions' => $permissions,
            'mustResetPassword' => false,
            'sessionVersion' => (int) $user->refreshCounter,
            'jti' => (string) Str::uuid(),
        ], (string) config('auth.jwt.access_secret'), '60m');
    }
}
