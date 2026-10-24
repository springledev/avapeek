<?php

namespace App\Services;

use App\Models\StorageUsage;

class QuotaService
{
    /**
     * The storage limit in bytes, or null when it is unknown (external S3
     * provider with no STORAGE_LIMIT_BYTES configured).
     */
    protected function getStorageLimit(): ?int
    {
        $limit = config('avapeek.storage.limit_bytes');
        if ($limit !== null) {
            return (int) $limit;
        }

        $volumePath = config('avapeek.storage.volume_path');
        if ($volumePath !== null && is_dir($volumePath)) {
            $free = disk_free_space($volumePath);
            if ($free !== false && $free > 0) {
                return (int) ($free * 0.9);
            }
        }

        return null;
    }

    public function getTotalUsage(): int
    {
        return (int) StorageUsage::global()->used_bytes;
    }

    public function checkQuota(int $additionalBytes): bool
    {
        $limit = $this->getStorageLimit();

        return $limit === null || ($this->getTotalUsage() + $additionalBytes) <= $limit;
    }

    public function addUsage(int $bytes): void
    {
        StorageUsage::global()->increment('used_bytes', $bytes);
    }

    public function removeUsage(int $bytes): void
    {
        StorageUsage::global()->decrement('used_bytes', max(0, $bytes));
        $current = StorageUsage::global()->fresh()->used_bytes;
        if ($current < 0) {
            StorageUsage::global()->update(['used_bytes' => 0]);
        }
    }

    /**
     * @return array{used_bytes: int, limit_bytes: int|null, percentage: float|int|null}
     */
    public function getUsage(): array
    {
        $used = $this->getTotalUsage();
        $limit = $this->getStorageLimit();
        $percentage = $limit === null ? null : ($limit > 0 ? round(($used / $limit) * 100, 1) : 0);

        return [
            'used_bytes' => $used,
            'limit_bytes' => $limit,
            'percentage' => $percentage,
        ];
    }

    public function getDiskInfo(): array
    {
        $volumePath = config('avapeek.storage.volume_path');

        if ($volumePath === null || ! is_dir($volumePath)) {
            return [
                'total_bytes' => null,
                'used_bytes' => null,
                'free_bytes' => null,
            ];
        }

        try {
            $total = disk_total_space($volumePath) ?: 0;
            $free = disk_free_space($volumePath) ?: 0;
        } catch (\Throwable) {
            return [
                'total_bytes' => null,
                'used_bytes' => null,
                'free_bytes' => null,
            ];
        }

        $used = $total - $free;

        return [
            'total_bytes' => $total,
            'used_bytes' => $used,
            'free_bytes' => $free,
        ];
    }
}
