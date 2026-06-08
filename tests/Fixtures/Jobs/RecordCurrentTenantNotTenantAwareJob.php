<?php

declare(strict_types=1);

namespace Tests\Fixtures\Jobs;

use App\Models\Pas\School;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Spatie\Multitenancy\Jobs\NotTenantAware;

/**
 * Phase C.2 fixture — opts out of tenant awareness via Spatie's
 * NotTenantAware marker interface. Spatie's MakeQueueTenantAwareAction
 * detects the interface and calls forgetCurrent() before handle(), so
 * School::current() must be null at the moment this job runs.
 */
final class RecordCurrentTenantNotTenantAwareJob implements NotTenantAware, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly string $cacheKey) {}

    public function handle(): void
    {
        $current = School::current();

        Cache::put($this->cacheKey, [
            'id' => $current?->getKey(),
            'slug' => $current?->slug,
        ]);
    }
}
