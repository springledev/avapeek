<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Child;
use App\Services\QuotaService;
use Illuminate\Http\JsonResponse;

class QuotaController extends Controller
{
    public function __construct(
        protected QuotaService $quotaService,
    ) {}

    /**
     * Get storage usage and dynamic limit for a child.
     */
    public function show(Child $child): JsonResponse
    {
        $usage = $this->quotaService->getUsage($child);

        return response()->json(['data' => $usage]);
    }
}
