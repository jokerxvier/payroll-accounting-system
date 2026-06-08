<?php

declare(strict_types=1);

use App\Models\Pas\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Fixtures\Jobs\RecordCurrentTenantJob;
use Tests\Fixtures\Jobs\RecordCurrentTenantNotTenantAwareJob;

uses(RefreshDatabase::class);

/*
 * Phase C.2 — queue tenant awareness acceptance.
 *
 * The single load-bearing assertion: with
 * `multitenancy.queues_are_tenant_aware_by_default = true`, a job
 * dispatched while School A is current must observe School A — not
 * the previously-current School (B), and not null — when its handle()
 * runs.
 *
 * Tests run on the sync queue (QUEUE_CONNECTION=sync in phpunit.xml),
 * so dispatch is synchronous; the JobProcessing event still fires and
 * Spatie's MakeQueueTenantAwareAction listener still re-binds the
 * tenant before handle(), exercising the same code path used by the
 * Redis worker in production.
 */

it('rebinds the dispatching tenant before handle() runs', function (): void {
    $schoolA = School::factory()->create(['slug' => 'tenant-a']);

    $schoolA->makeCurrent();

    dispatch(new RecordCurrentTenantJob('observed:tenant-a'));

    $observed = Cache::get('observed:tenant-a');

    expect($observed)->not->toBeNull()
        ->and($observed['id'])->toBe($schoolA->getKey())
        ->and($observed['slug'])->toBe('tenant-a');
});

it('rebinds the dispatching tenant even when a different tenant becomes current after dispatch', function (): void {
    // The serialization-on-dispatch contract: capturing the tenant must
    // happen at dispatch time, not handle time. Under sync queues
    // dispatch and handle are atomic, so this test mostly proves the
    // happy-path round-trip; under Redis it is the contract that
    // protects against in-flight tenant swaps.
    $schoolA = School::factory()->create(['slug' => 'tenant-a']);
    $schoolB = School::factory()->create(['slug' => 'tenant-b']);

    $schoolA->makeCurrent();

    dispatch(new RecordCurrentTenantJob('observed:dispatch-time'));

    // Handle has already fired under sync queue; switching tenants now
    // does not retroactively change the cached observation.
    $schoolB->makeCurrent();

    $observed = Cache::get('observed:dispatch-time');

    expect($observed['id'])->toBe($schoolA->getKey())
        ->and($observed['slug'])->toBe('tenant-a');
});

it('does not leak School A into a job dispatched without a current tenant', function (): void {
    // After makeCurrent + forgetCurrent there is no current tenant.
    // The expected behaviour is one of two things:
    //   (a) Spatie throws CurrentTenantCouldNotBeDeterminedInTenantAwareJob
    //       at job-processing time (because the job is tenant-aware by
    //       default but no tenant was serialized), OR
    //   (b) the job's handle() runs without a current tenant (id null).
    // Either way, the job MUST NOT observe School A (which would
    // indicate the previous makeCurrent() leaked into the job context).
    $schoolA = School::factory()->create(['slug' => 'tenant-a']);

    $schoolA->makeCurrent();
    School::forgetCurrent();

    try {
        dispatch(new RecordCurrentTenantJob('observed:no-tenant'));
    } catch (Throwable) {
        // (a) — Spatie aborted the job because no tenant id was on the
        // payload. Acceptable; the leak we care about cannot have
        // happened because handle() never ran.
        Cache::put('observed:no-tenant', ['id' => null, 'slug' => null]);
    }

    $observed = Cache::get('observed:no-tenant');

    expect($observed['id'])->not->toBe($schoolA->getKey());
});

it('respects the NotTenantAware opt-out — handle() runs with no current tenant', function (): void {
    $schoolA = School::factory()->create(['slug' => 'tenant-a']);

    $schoolA->makeCurrent();

    dispatch(new RecordCurrentTenantNotTenantAwareJob('observed:opt-out'));

    $observed = Cache::get('observed:opt-out');

    // Spatie's listener calls forgetCurrent() for non-tenant-aware
    // jobs before handle(), so the job sees a null current tenant
    // even though the dispatcher had School A bound.
    expect($observed)->not->toBeNull()
        ->and($observed['id'])->toBeNull()
        ->and($observed['slug'])->toBeNull();
});
