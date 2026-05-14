<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureErpAction;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class EnsureErpActionMiddlewareTest extends TestCase
{
    public function test_allows_access_with_exact_granular_permission(): void
    {
        $response = $this->runMiddleware(
            routeName: 'modules.clientes.grupos.index',
            method: 'GET',
            authData: [
                'roles' => ['operador'],
                'modules' => ['clientes'],
                'permissions' => [
                    'clientes' => ['ver'],
                    'clientes.grupo_cliente' => ['ver'],
                ],
            ],
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_denies_access_when_only_sibling_granular_permission_exists(): void
    {
        $this->assertForbidden(function (): void {
            $this->runMiddleware(
                routeName: 'modules.clientes.index',
                method: 'GET',
                authData: [
                    'roles' => ['operador'],
                    'modules' => ['clientes'],
                    'permissions' => [
                        'clientes' => ['ver'],
                        'clientes.grupo_cliente' => ['ver'],
                    ],
                ],
            );
        });
    }

    public function test_allows_admin_bypass_even_without_explicit_permissions(): void
    {
        $response = $this->runMiddleware(
            routeName: 'modules.configuracion.ubigeos.destroy',
            method: 'DELETE',
            authData: [
                'roles' => ['admin'],
                'modules' => [],
                'permissions' => [],
            ],
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_denies_unresolvable_modules_route_name(): void
    {
        $this->assertForbidden(function (): void {
            $this->runMiddleware(
                routeName: 'modules.inventario.index',
                method: 'GET',
                authData: [
                    'roles' => ['operador'],
                    'modules' => ['inventario'],
                    'permissions' => [
                        'inventario' => ['ver'],
                    ],
                ],
            );
        });
    }

    public function test_allows_parent_permission_fallback_when_no_granular_keys_exist(): void
    {
        $response = $this->runMiddleware(
            routeName: 'modules.clientes.create',
            method: 'GET',
            authData: [
                'roles' => ['operador'],
                'modules' => ['clientes'],
                'permissions' => [
                    'clientes' => ['ver', 'crear'],
                ],
            ],
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_allows_import_preview_route_for_lineas_chips_detallesimcard_viewers(): void
    {
        $response = $this->runMiddleware(
            routeName: 'modules.lineas-chips.detallesimcard.import.preview',
            method: 'POST',
            authData: [
                'roles' => ['operador'],
                'modules' => ['lineas_chips'],
                'permissions' => [
                    'lineas_chips.detallesimcard' => ['ver'],
                ],
            ],
        );

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_does_not_use_parent_fallback_when_granular_keys_exist(): void
    {
        $this->assertForbidden(function (): void {
            $this->runMiddleware(
                routeName: 'modules.clientes.create',
                method: 'GET',
                authData: [
                    'roles' => ['operador'],
                    'modules' => ['clientes'],
                    'permissions' => [
                        'clientes' => ['ver', 'crear'],
                        'clientes.grupo_cliente' => ['ver', 'crear'],
                    ],
                ],
            );
        });
    }

    private function runMiddleware(
        string $routeName,
        string $method,
        array $authData,
        ?string $module = null,
        ?string $action = null
    ): HttpResponse {
        $middleware = new EnsureErpAction();
        $request = $this->makeRequest($routeName, $method, $authData);

        return $middleware->handle(
            $request,
            static fn () => new HttpResponse('ok', 200),
            $module,
            $action
        );
    }

    private function makeRequest(string $routeName, string $method, array $authData): Request
    {
        $request = Request::create('/test/' . $routeName, $method);

        $session = new Store('test', new ArraySessionHandler(120));
        $session->start();
        $session->put('erp_auth', $authData);
        $request->setLaravelSession($session);

        $route = new Route([$method], '/test/' . $routeName, [
            'as' => $routeName,
            'uses' => static fn () => null,
        ]);
        $route->name($routeName);

        $request->setRouteResolver(static fn () => $route);

        return $request;
    }

    private function assertForbidden(callable $callback): void
    {
        try {
            $callback();
            $this->fail('Se esperaba una excepcion HttpException con codigo 403.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
    }
}
