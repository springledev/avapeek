<?php

namespace App\Services;

use App\Models\StorageUsage;
use App\Models\UploadIntent;
use Closure;
use Illuminate\Support\Facades\DB;

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

    /**
     * Atomically reserve upload capacity. Under a lock on the global usage row,
     * checks used + live reservations + requested against the limit and, when it
     * fits, runs $create (which must persist the reservation) and returns its
     * result. Returns null when over quota. No cap when the limit is unknown.
     *
     * @template T
     *
     * @param  Closure(): T  $create
     * @return T|null
     */
    public function reserve(int $bytes, Closure $create): mixed
    {
        return DB::transaction(function () use ($bytes, $create) {
            $usage = StorageUsage::global();
            $locked = StorageUsage::query()->whereKey($usage->getKey())->lockForUpdate()->first();

            $limit = $this->getStorageLimit();
            if ($limit !== null) {
                $reserved = (int) UploadIntent::unexpired()->sum('reserved_bytes');
                if (((int) $locked->used_bytes + $reserved + $bytes) > $limit) {
                    return null;
                }
            }

            return $create();
        });
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
