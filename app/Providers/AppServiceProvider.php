<?php

namespace App\Providers;

use App\Services;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Auth::provider('music-session', fn($app, array $config) => new Services\SessionUserProvider($app['hash'], $config['model']));
    }
}
