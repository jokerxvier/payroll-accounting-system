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

/**
 * Phase C.2 fixture — records whichever School is current at handle()
 * time into the cache under a caller-supplied key. Used by
 * QueueTenantAwarenessTest to assert that the dispatcher's tenant
 * context survives the dispatch → handle round-trip.
 *
 * Cache (not env / file / globals) because it isolates between tests
 * automatically (CACHE_STORE=array in phpunit.xml flushes per process)
 * and avoids serializer surprises around closures or mutable refs.
 */
final class RecordCurrentTenantJob implements ShouldQueue
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
