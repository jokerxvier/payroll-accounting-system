<?php

declare(strict_types=1);

namespace App\Providers;

use App\Repositories\Contracts\EmployeeRepositoryInterface;
use App\Repositories\Eloquent\EloquentEmployeeRepository;
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
    }
}
