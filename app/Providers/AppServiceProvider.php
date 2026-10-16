<?php

namespace App\Providers;

use App\Http\Controllers\NotificacionController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;

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
        View::composer(['dashboard.overview-1', 'layouts.layout'], function ($view): void {
            $usuario = (string) session('erp_auth.usuario', '');
            $muted = $usuario !== '' && NotificacionController::notificationsMutedFor($usuario);

            $view->with('notificationsMuted', $muted);
        });

        if (! app()->runningInConsole() && $this->shouldUseHttps()) {
            URL::forceScheme('https');
            config(['session.secure' => true]);
        }

        if (! app()->runningInConsole() && ! $this->shouldUseHttps()) {
            config(['session.secure' => false]);
        }

        // Local WebSocket server is started separately via npm run ws-server.
    }

    private function shouldUseHttps(): bool
    {
        $request = request();
        $host = $request->getHost();
        $forwardedProto = strtolower((string) $request->headers->get('x-forwarded-proto'));

        return $forwardedProto === 'https'
            || $host === 'erp-segurtrack.com'
            || $host === 'socket.erp-segurtrack.com';
    }
}
