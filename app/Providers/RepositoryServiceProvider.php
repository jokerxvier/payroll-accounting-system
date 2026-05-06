<?php

declare(strict_types=1);

namespace App\Providers;

use App\Repositories\Contracts\AuditLogRepositoryInterface;
use App\Repositories\Contracts\EmployeeRepositoryInterface;
use App\Repositories\Contracts\PayPeriodRepositoryInterface;
use App\Repositories\Contracts\PayrollRunRepositoryInterface;
use App\Repositories\Eloquent\EloquentAuditLogRepository;
use App\Repositories\Eloquent\EloquentEmployeeRepository;
use App\Repositories\Eloquent\EloquentPayPeriodRepository;
use App\Repositories\Eloquent\EloquentPayrollRunRepository;
use Illuminate\Support\ServiceProvider;

final class RepositoryServiceProvider extends ServiceProvider
{
    /**
     * Bind every repository contract to its Eloquent implementation here.
     */
    public function register(): void
    {
        $this->app->bind(
            EmployeeRepositoryInterface::class,
            EloquentEmployeeRepository::class,
        );

        $this->app->bind(
            PayrollRunRepositoryInterface::class,
            EloquentPayrollRunRepository::class,
        );

        $this->app->bind(
            PayPeriodRepositoryInterface::class,
            EloquentPayPeriodRepository::class,
        );

        $this->app->bind(
            AuditLogRepositoryInterface::class,
            EloquentAuditLogRepository::class,
        );
    }
}
