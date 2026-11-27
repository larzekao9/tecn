<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Login: maximo 10 intentos por minuto por IP
        RateLimiter::for('login', fn (Request $r) => Limit::perMinute(10)->by($r->ip()));
        // API general: 240 peticiones por minuto por usuario o IP
        RateLimiter::for('api', fn (Request $r) => Limit::perMinute(240)->by($r->user()?->id_usuario ?: $r->ip()));
    }
}
