<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsGradingFixtures;
use Tests\TestCase;

class GradingStatusEndpointTest extends TestCase
{
    use BuildsGradingFixtures;
    use RefreshDatabase;

    public function test_student_status_is_small_cacheable_and_returns_not_modified_when_unchanged(): void
    {
        [$subjectId, $testId] = $this->createTest();
        $studentId = $this->createUser();
        $this->grantSubject($studentId, $subjectId);
        $attemptId = $this->insertAttempt($testId, [
            'status' => 'FAILED',
            'gradingError' => 'Provider detail that must stay private.',
        ]);
        DB::table('test_attempts')->where('id', $attemptId)->update(['studentId' => $studentId]);
        $token = $this->token($studentId);

        $response = $this->withToken($token)->getJson("/api/tests/{$testId}/me/status");
        $response->assertOk()
            ->assertJsonPath('status', 'SUBMITTED')
            ->assertJsonPath('gradingComplete', false)
            ->assertJsonPath('gradingError', true)
            ->assertJsonMissingPath('queuePosition')
            ->assertJsonMissingPath('queueDepth')
            ->assertJsonMissingPath('providerError');
        self::assertLessThanOrEqual(5, count($response->json()));
        $etag = $response->headers->get('ETag');
        self::assertNotEmpty($etag);

        $this->withToken($token)
            ->withHeader('If-None-Match', $etag)
            ->get("/api/tests/{$testId}/me/status")
            ->assertStatus(304)
            ->assertHeader('ETag', $etag);
    }
}
