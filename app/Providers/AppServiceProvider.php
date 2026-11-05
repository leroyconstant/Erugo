<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\SettingsService;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\Schema;

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
        \Illuminate\Support\Facades\RateLimiter::for('login', function ($request) {
            return [
                \Illuminate\Cache\RateLimiting\Limit::perMinute(30)->by('login-ip:' . $request->ip()),
                \Illuminate\Cache\RateLimiting\Limit::perMinute(5)->by('login-account:' . hash('sha256', strtolower((string) $request->input('email')))),
            ];
        });
        \Illuminate\Support\Facades\RateLimiter::for('auth-sensitive', function ($request) {
            return [
                \Illuminate\Cache\RateLimiting\Limit::perMinute(20)->by($request->route()->getName() . ':' . $request->ip()),
                \Illuminate\Cache\RateLimiting\Limit::perMinute(5)->by($request->route()->getName() . ':' . hash('sha256', strtolower((string) $request->input('email')))),
            ];
        });
        // Use a view composer for email templates so settings are loaded fresh each time.
        // This is important for queue workers which are long-running processes -
        // View::share() would capture stale values from when the worker started.
        View::composer('emails.*', function ($view) {
            try {
                $settingsService = app(SettingsService::class);
                $settings = $settingsService->getGlobalViewData();
                $view->with('settings', $settings);
            } catch (\Exception $e) {
                // If settings can't be loaded, provide empty defaults
                $view->with('settings', []);
            }
        });

        View::prependLocation(storage_path('templates'));
    }
}
