<?php

namespace App\Providers;

use App\Support\SystemHealth;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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
        $this->configureDefaults();
        $this->configureRateLimiting();

        // Stamped on every worker poll loop so the dashboard can tell users
        // when no queue worker is running instead of leaving emails "queued".
        Queue::looping(fn () => app(SystemHealth::class)->recordWorkerHeartbeat());
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    private function configureRateLimiting(): void
    {
        RateLimiter::for('larasend-api-auth', function (Request $request): Limit {
            return Limit::perMinute(config('larasend.api_auth_rate_limit_per_minute'))
                ->by("larasend-api-auth:{$request->ip()}");
        });

        RateLimiter::for('larasend-api', function (Request $request): Limit {
            $token = $request->bearerToken();
            $key = filled($token) ? hash('sha256', $token) : $request->ip();

            return Limit::perMinute(config('larasend.api_rate_limit_per_minute'))
                ->by("larasend-api:{$key}");
        });
    }
}
