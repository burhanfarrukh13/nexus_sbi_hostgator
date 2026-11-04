<?php

namespace App\Http\Controllers;

use App\Services\LeaderboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeaderboardController
{
    public function __construct(private readonly LeaderboardService $leaderboard)
    {
    }

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->leaderboard->forUser(
            $request->attributes->get('apiIdentity')['userId']
        ));
    }
}
