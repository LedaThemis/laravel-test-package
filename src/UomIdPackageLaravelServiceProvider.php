<?php

namespace UoMosul\UomIdPackageLaravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Ory\Kratos\Client\Api\FrontendApi;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class UomIdPackageLaravelServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('uom-id-package-laravel')
            ->hasConfigFile();
        // ->hasViews()
        // ->hasMigration('create_uom-id-package-laravel_table')
        // ->hasCommand(UomIdPackageLaravelCommand::class);
    }

    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(FrontendApi::class, function (Application $app) {
            $config = new \Ory\Kratos\Client\Configuration;
            $config->setHost(config('uom-id.auth.uom.routes.host'));

            $frontendApi = new FrontendApi(null, $config);

            return $frontendApi;
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Define custom guard for Ory Kratos instance (https://laravel.com/docs/10.x/authentication#adding-custom-guards, https://laravel.com/docs/10.x/authentication#closure-request-guards)
        Auth::viaRequest('uom', function (Request $request) {
            // TODO: Deduplicate this with the singleton in register method
            $config = new \Ory\Kratos\Client\Configuration;
            $config->setHost(config('uom-id.auth.uom.routes.host'));

            $frontendApi = new FrontendApi(null, $config);

            try {
                // Get current user session
                $session = $frontendApi->toSession(null, $request->header('Cookie'));
                $identity = $session->getIdentity()->getTraits();

                $user = ['id' => $session->getIdentity()->getId(), 'name' => $identity->name, 'email' => $identity->email];

                return (object) $user;
            } catch (\Ory\Kratos\Client\ApiException $err) {
                // Not authenticated
                return null;
            }
        });

        // Register routes
        $this->defineRoutes();
    }

    /**
     * Define the Sanctum routes.
     *
     * @return void
     */
    protected function defineRoutes()
    {
        if (app()->routesAreCached()) {
            return;
        }

        Route::group(['prefix' => 'auth'], function () {
            // ALL ROUTES UNDER THE GROUP ARE PREFIXED WITH "/auth"

            Route::get('@me', function (Request $request) {
                return $request->user() ?? (object) [];
            });

            Route::get('login', function (Request $request, FrontendApi $frontendApi) {
                try {
                    $frontendApi->createBrowserLoginFlow(null, null, route('home'), $request->header('Cookie'));

                    $loginUrl = $request::create(config('uom-id.auth.uom.routes.login'))->fullUrlWithQuery([
                        'return_to' => route('home'),
                    ]);

                    return redirect($loginUrl);
                } catch (\Ory\Kratos\Client\ApiException $err) {
                    $errorId = json_decode($err->getResponseBody())->error->id;
                    // TODO: Complete handling all errorId cases
                    switch ($errorId) {
                        case 'session_already_available':
                            return redirect(route('home'));
                    }
                }
            })->name('login');

            Route::get('logout', function (Request $request, FrontendApi $frontendApi) {
                try {
                    $response = $frontendApi->createBrowserLogoutFlow($request->header('Cookie'));
                    $logoutUrl = $request::create($response['logoutUrl'])->fullUrlWithQuery([
                        'return_to' => route('home'),
                    ]);

                    return redirect($logoutUrl);
                } catch (\Ory\Kratos\Client\ApiException $err) {
                    $errorId = json_decode($err->getResponseBody())->error->id;
                    // TODO: Use errorId as you wish
                    return redirect(route('home'));
                }
            })->name('logout');
        });
    }
}
