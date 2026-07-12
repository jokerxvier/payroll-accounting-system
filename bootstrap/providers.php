<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\HorizonServiceProvider;
use App\Providers\RepositoryServiceProvider;
use App\Providers\TestingMigrationsServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    HorizonServiceProvider::class,
    RepositoryServiceProvider::class,
    TestingMigrationsServiceProvider::class,
];
