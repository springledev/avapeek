<?php

use App\Services\QuotaService;

describe('QuotaService limits', function () {
    it('uses the configured limit_bytes', function () {
        config(['avapeek.storage.limit_bytes' => 1000, 'avapeek.storage.volume_path' => null]);

        $usage = app(QuotaService::class)->getUsage();

        expect($usage['limit_bytes'])->toBe(1000)
            ->and($usage['percentage'])->toEqual(0);
    });

    it('reports an unknown limit when nothing is configured', function () {
        config(['avapeek.storage.limit_bytes' => null, 'avapeek.storage.volume_path' => null]);
        $service = app(QuotaService::class);

        expect($service->getUsage())->toMatchArray(['limit_bytes' => null, 'percentage' => null])
            ->and($service->checkQuota(PHP_INT_MAX / 2))->toBeTrue()
            ->and($service->getDiskInfo())->toBe(['total_bytes' => null, 'used_bytes' => null, 'free_bytes' => null]);
    });

    it('rejects uploads over the configured limit', function () {
        config(['avapeek.storage.limit_bytes' => 100]);

        expect(app(QuotaService::class)->checkQuota(101))->toBeFalse();
    });

    it('derives the limit from the volume path free space', function () {
        config(['avapeek.storage.limit_bytes' => null, 'avapeek.storage.volume_path' => sys_get_temp_dir()]);

        expect(app(QuotaService::class)->getUsage()['limit_bytes'])->toBeInt()->toBeGreaterThan(0);
    });
});
