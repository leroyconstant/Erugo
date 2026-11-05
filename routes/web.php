<?php

use Illuminate\Support\Facades\Route;
use App\Models\Setting;
use App\Models\User;
use App\Mail\shareDownloadedMail;
use App\Jobs\sendEmail;
use App\Models\Share;
use App\Models\Theme;
use App\Http\Controllers\ExternalAuthController;
use App\Services\SettingsService;

Route::get('/', function () {
    $indexedSettings = app(SettingsService::class)->getFrontendSettings();

    //grab the app url from env
    $appURL = env('APP_URL');
    $indexedSettings['api_url'] = $appURL;

    $theme = Theme::where('active', true)->first();


    return view('app', ['settings' => $indexedSettings, 'theme' => $theme]);
});

Route::get('/reset-password/{token}', function ($token) {
    $indexedSettings = app(SettingsService::class)->getFrontendSettings();

    $indexedSettings['token'] = $token;

    $theme = Theme::where('active', true)->first();


    return view('app', ['settings' => $indexedSettings, 'theme' => $theme]);
});

Route::get('/shares/{share}', function ($shareId) {
    // Detect CLI tools (curl, wget, etc.) and serve file directly
    $userAgent = request()->userAgent() ?? '';
    $cliPatterns = [
        '/^curl\//i',
        '/^wget\//i',
        '/^libcurl/i',
        '/^Wget/i',
        '/^HTTPie\//i',
    ];
    
    $isCli = false;
    foreach ($cliPatterns as $pattern) {
        if (preg_match($pattern, $userAgent)) {
            $isCli = true;
            break;
        }
    }
    
    if ($isCli) {
        // Serve file directly (curl uses Content-Disposition with -OJ flag)
        $controller = app(\App\Http\Controllers\SharesController::class);
        return $controller->download($shareId);
    }

    $indexedSettings = app(SettingsService::class)->getFrontendSettings();

    $theme = Theme::where('active', true)->first();

    return view('app', ['settings' => $indexedSettings, 'theme' => $theme]);
});

// Direct download route with filename in URL (for wget compatibility - wget uses URL path for filename)
Route::get('/shares/{share}/{filename}', function ($shareId, $filename) {
    $controller = app(\App\Http\Controllers\SharesController::class);
    return $controller->download($shareId);
})->where('filename', '[^/]+'); // Only match single filename, not paths

// Download specific file from a multi-file share (supports nested paths)
Route::get('/shares/{share}/file/{filepath}', function ($shareId, $filepath) {
    $controller = app(\App\Http\Controllers\SharesController::class);
    return $controller->downloadFile($shareId, $filepath);
})->where('filepath', '.*');


//auth provider login
Route::get('/auth/provider/{provider}/login', [ExternalAuthController::class, 'providerLogin'])
    ->name('social.provider.login');

//auth provider callback
Route::get('/auth/provider/{provider}/callback', [ExternalAuthController::class, 'providerCallback'])
    ->name('social.provider.callback');

//auth provider link account
Route::get('/auth/provider/{provider}/link', [ExternalAuthController::class, 'linkAccount'])
    ->name('social.provider.link');
