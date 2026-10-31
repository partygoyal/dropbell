<?php

namespace App\Providers;

use App\Services\AdminTables;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Builder::macro('adminPaginate', function (string $key): LengthAwarePaginator {
            return AdminTables::paginate($this, $key);
        });
    }
}
