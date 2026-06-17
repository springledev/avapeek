<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\QuotaService;
use Illuminate\Http\JsonResponse;

class QuotaController extends Controller
{
    public function __construct(
        protected QuotaService $quotaService,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json([
            'data' => $this->quotaService->getUsage(),
        ]);
    }
}
