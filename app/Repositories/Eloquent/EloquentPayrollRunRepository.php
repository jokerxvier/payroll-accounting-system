<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Models\Pas\PayrollRun;
use App\Repositories\Contracts\PayrollRunRepositoryInterface;
use Illuminate\Support\Collection;

final class EloquentPayrollRunRepository implements PayrollRunRepositoryInterface
{
    public function latestNonVoided(): ?PayrollRun
    {
        return PayrollRun::query()
            ->whereNull('voided_at')
            ->with('payPeriod')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();
    }

    public function recentNonVoided(int $limit): Collection
    {
        return PayrollRun::query()
            ->whereNull('voided_at')
            ->with('payPeriod')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }
}
