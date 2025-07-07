<?php

namespace MohammedHassan\CrudPackage;

use Illuminate\Support\ServiceProvider;
use MohammedHassan\CrudPackage\Console\Commands\MakeCrudPackage;

class CrudPackageServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                MakeCrudPackage::class,
            ]);
        }
    }
}
