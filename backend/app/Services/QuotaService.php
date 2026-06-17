<?php

namespace App\Services;

use App\Models\StorageUsage;

class QuotaService
{
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

        return 5 * 1024 * 1024 * 1024;
    }

    public function getTotalUsage(): int
    {
        return (int) StorageUsage::global()->used_bytes;
    }

    public function checkQuota(int $additionalBytes): bool
    {
        return ($this->getTotalUsage() + $additionalBytes) <= $this->getStorageLimit();
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

    public function getUsage(): array
    {
        $used = $this->getTotalUsage();
        $limit = $this->getStorageLimit();
        $percentage = $limit > 0 ? round(($used / $limit) * 100, 1) : 0;

        return [
            'used_bytes' => $used,
            'limit_bytes' => $limit,
            'percentage' => $percentage,
        ];
    }

    public function getDiskInfo(): array
    {
        $volumePath = env('STORAGE_VOLUME_PATH', '/');

        if (! is_dir($volumePath)) {
            return [
                'total_bytes' => 0,
                'used_bytes' => 0,
                'free_bytes' => 0,
            ];
        }

        try {
            $total = disk_total_space($volumePath) ?: 0;
            $free = disk_free_space($volumePath) ?: 0;
        } catch (\Throwable) {
            $total = 0;
            $free = 0;
        }

        $used = $total - $free;

        return [
            'total_bytes' => $total,
            'used_bytes' => $used,
            'free_bytes' => $free,
        ];
    }
}
