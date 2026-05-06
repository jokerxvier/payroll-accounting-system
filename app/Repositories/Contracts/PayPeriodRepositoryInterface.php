<?php

declare(strict_types=1);

namespace App\Repositories\Contracts;

use App\Models\Pas\PayPeriod;

/**
 * Read-side repository for `pas_pay_periods` consumed by the dashboard.
 */
interface PayPeriodRepositoryInterface
{
    /**
     * The currently-open pay period (status = 'open') with the latest
     * start date. Returns null when no period is open.
     */
    public function latestOpen(): ?PayPeriod;
}
