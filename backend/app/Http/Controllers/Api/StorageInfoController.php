<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\QuotaService;
use Illuminate\Http\JsonResponse;

class StorageInfoController extends Controller
{
    public function __construct(
        protected QuotaService $quotaService,
    ) {}

    /**
     * Get disk information for the storage volume.
     */
    public function diskInfo(): JsonResponse
    {
        $info = $this->quotaService->getDiskInfo();

        return response()->json(['data' => $info]);
    }
}
