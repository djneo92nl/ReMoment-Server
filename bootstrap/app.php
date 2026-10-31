<?php

use App\Http\Middleware\AuthenticateAdminToken;
use App\Http\Middleware\EnforceProfile;
use App\Http\Middleware\NowHumming;
use App\Http\Middleware\RedirectToSetupWizard;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // The profile pages (not-in-profile notice, profile switch); kept apart from routes/web.php.
            \Illuminate\Support\Facades\Route::middleware('web')->group(__DIR__.'/../routes/profile.php');
        },
    )
    // Listeners are registered explicitly in AppServiceProvider; discovery
    // would register each one a second time.
    ->withEvents(discover: false)
    ->withMiddleware(function (Middleware $middleware): void {
        // EnforceProfile is prepended: it must answer before route model binding turns a gated URL into a 404.
        $middleware->web(prepend: [EnforceProfile::class], append: [RedirectToSetupWizard::class]);
        $middleware->api(prepend: [EnforceProfile::class], append: [NowHumming::class]);
        $middleware->alias(['admin.token' => AuthenticateAdminToken::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->renderable(function (\Exception $e, $request) {
            // Validation (422), missing models/routes (404) and other HTTP
            // exceptions keep Laravel's own JSON responses; only unexpected
            // errors are collapsed into a server_error.
            if ($e instanceof ValidationException
                || $e instanceof HttpExceptionInterface
                || $e instanceof ModelNotFoundException) {
                return null;
            }

            if ($request->is('api/*')) {
                return response()->json([
                    'error' => 'server_error',
                    'message' => $e->getMessage(),
                ], 500);
            }
        });
    })->create();
