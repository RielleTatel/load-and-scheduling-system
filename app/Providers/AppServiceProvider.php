<?php

namespace App\Providers;

use App\Services\Scheduling\ScheduleGenerationEngine;
use App\Services\Scheduling\TimetableGenerator;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(TimetableGenerator::class, ScheduleGenerationEngine::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
