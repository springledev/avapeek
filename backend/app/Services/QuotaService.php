<?php

namespace App\Services;

use App\Models\Child;

class QuotaService
{
    /**
     * Get the storage limit in bytes.
     *
     * Returns a dynamic limit based on priority:
     * 1. STORAGE_LIMIT_BYTES env var → fixed limit
     * 2. STORAGE_VOLUME_PATH env var → disk_free_space() * 0.9
     * 3. Fallback → 5 GB
     */
    protected function getStorageLimit(): int
    {
        $limit = env('STORAGE_LIMIT_BYTES');
        if ($limit !== null && $limit !== '') {
            return (int) $limit;
        }

        $volumePath = env('STORAGE_VOLUME_PATH');
        if ($volumePath !== null && $volumePath !== '' && is_dir($volumePath)) {
            $free = disk_free_space($volumePath);
            if ($free !== false && $free > 0) {
                return (int) ($free * 0.9);
            }
        }

        return 5 * 1024 * 1024 * 1024; // 5 GB
    }

    /**
     * Check if the child can store additionalBytes without exceeding the limit.
     */
    public function checkQuota(Child $child, int $additionalBytes): bool
    {
        $used = $child->storageUsage?->used_bytes ?? 0;
        $limit = $this->getStorageLimit();

        return ($used + $additionalBytes) <= $limit;
    }

    /**
     * Increment the child's storage usage by the given bytes.
     */
    public function addUsage(Child $child, int $bytes): void
    {
        $usage = $child->storageUsage;
        if ($usage) {
            $usage->increment('used_bytes', $bytes);
        }
    }

    /**
     * Decrement the child's storage usage by the given bytes.
     */
    public function removeUsage(Child $child, int $bytes): void
    {
        $usage = $child->storageUsage;
        if ($usage) {
            $usage->decrement('used_bytes', max(0, $bytes));
            if ($usage->fresh()->used_bytes < 0) {
                $usage->update(['used_bytes' => 0]);
            }
        }
    }

    /**
     * Get the child's storage usage information.
     *
     * @return array{used_bytes: int, limit_bytes: int, percentage: float}
     */
    public function getUsage(Child $child): array
    {
        $used = $child->storageUsage?->used_bytes ?? 0;
        $limit = $this->getStorageLimit();
        $percentage = $limit > 0 ? round(($used / $limit) * 100, 1) : 0;

        return [
            'used_bytes' => (int) $used,
            'limit_bytes' => $limit,
            'percentage' => $percentage,
        ];
    }

    /**
     * Get disk information for the storage volume.
     *
     * @return array{total_bytes: int, used_bytes: int, free_bytes: int}
     */
    public function getDiskInfo(): array
    {
        $volumePath = env('STORAGE_VOLUME_PATH', '/');

        $total = disk_total_space($volumePath) ?: 0;
        $free = disk_free_space($volumePath) ?: 0;
        $used = $total - $free;

        return [
            'total_bytes' => $total,
            'used_bytes' => $used,
            'free_bytes' => $free,
        ];
    }
}
