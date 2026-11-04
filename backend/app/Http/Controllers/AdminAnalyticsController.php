<?php

namespace App\Http\Controllers;

use App\Services\AnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class AdminAnalyticsController
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
    private const ALLOWED_TIERS = ['TIER_1', 'TIER_2', 'TIER_3', 'PAST_PAPER'];

    public function __construct(private readonly AnalyticsService $analytics)
    {
    }

    public function overview(Request $request): JsonResponse
    {
        return response()->json($this->analytics->overview($this->buildFilters($request)));
    }

    public function filters(): JsonResponse
    {
        return response()->json($this->analytics->filterOptions());
    }

    public function students(Request $request): JsonResponse
    {
        return response()->json($this->analytics->students($this->buildFilters($request)));
    }

    public function student(Request $request, string $studentId): JsonResponse
    {
        $filters = $this->buildFilters($request);
        if (!preg_match(self::UUID, $studentId)) {
            throw new BadRequestHttpException('studentId must be a UUID');
        }
        $filters['studentId'] = $studentId;

        return response()->json($this->analytics->studentDetail($studentId, $filters));
    }

    public function questions(Request $request): JsonResponse
    {
        return response()->json($this->analytics->questions($this->buildFilters($request)));
    }

    public function topics(Request $request): JsonResponse
    {
        return response()->json($this->analytics->topics($this->buildFilters($request)));
    }

    public function quizzes(Request $request): JsonResponse
    {
        return response()->json($this->analytics->quizzes($this->buildFilters($request)));
    }

    public function activity(Request $request): JsonResponse
    {
        return response()->json($this->analytics->activity($this->buildFilters($request)));
    }

    private function buildFilters(Request $request): array
    {
        $filters = [];
        foreach (['batchId', 'schoolYearId', 'classId', 'sectionId', 'courseId', 'gridId', 'topicId', 'studentId'] as $key) {
            $value = $request->query($key);
            if ($value !== null && $value !== '') {
                if (!preg_match(self::UUID, (string) $value)) {
                    throw new BadRequestHttpException("{$key} must be a UUID");
                }
                $filters[$key] = (string) $value;
            }
        }
        foreach (['tierType', 'difficulty', 'questionType'] as $key) {
            $value = $request->query($key);
            if ($value !== null && $value !== '') {
                $filters[$key] = mb_strtoupper(trim((string) $value));
            }
        }
        if (!empty($filters['tierType']) && !in_array($filters['tierType'], self::ALLOWED_TIERS, true)) {
            throw new BadRequestHttpException('Invalid tierType');
        }
        $search = $request->query('search');
        if ($search !== null && trim((string) $search) !== '') {
            $filters['search'] = mb_substr(trim((string) $search), 0, 120);
        }
        foreach (['from', 'to'] as $key) {
            $value = $request->query($key);
            if ($value) {
                try {
                    $date = new \DateTimeImmutable((string) $value);
                } catch (\Throwable) {
                    throw new BadRequestHttpException("{$key} must be a valid date");
                }
                $filters[$key] = $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
            }
        }
        $page = $request->query('page', 1);
        $pageSize = $request->query('pageSize', 25);
        if (!is_numeric($page) || !is_numeric($pageSize) ||
            (float) $page !== (float) (int) $page || (float) $pageSize !== (float) (int) $pageSize ||
            (int) $page < 1 || (int) $pageSize < 1 || (int) $pageSize > 100) {
            throw new BadRequestHttpException('page must be positive and pageSize must be between 1 and 100');
        }
        $filters['page'] = (int) $page;
        $filters['pageSize'] = (int) $pageSize;
        $filters['sort'] = (string) $request->query('sort', 'activity');

        return $filters;
    }
}
