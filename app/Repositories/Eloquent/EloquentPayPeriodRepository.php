<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\Models\Pas\PayPeriod;
use App\Repositories\Contracts\PayPeriodRepositoryInterface;

final class EloquentPayPeriodRepository implements PayPeriodRepositoryInterface
{
    public function latestOpen(): ?PayPeriod
    {
        return PayPeriod::query()
            ->open()
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->first();
    }
}
