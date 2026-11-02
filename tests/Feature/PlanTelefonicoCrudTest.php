<?php

namespace Tests\Feature;

use App\Http\Controllers\PlanTelefonicoController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use App\Support\ResourceLock;
use App\Http\Middleware\EnsureErpAuthenticated;
use Tests\TestCase;

class PlanTelefonicoCrudTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('plantelef', function (Blueprint $table): void {
            $table->increments('idplantelef');
            $table->string('nombre', 45);
            $table->decimal('precio', 10, 2);
            $table->string('moneda', 10)->nullable();
            $table->string('megas', 45);
            $table->string('sms', 45);
        });

        Schema::create('moneda', function (Blueprint $table): void {
            $table->increments('idmoneda');
            $table->string('detalle', 50)->nullable();
            $table->string('simbolo', 3)->nullable();
        });
        DB::table('moneda')->insert([
            ['detalle' => 'Sol', 'simbolo' => 'S'],
            ['detalle' => 'Dólar', 'simbolo' => '$'],
            ['detalle' => 'Euro', 'simbolo' => '€'],
            ['detalle' => 'Genérico', 'simbolo' => 'G'],
        ]);

        config(['locks.enabled' => false]);
        $this->withoutMiddleware(EnsureErpAuthenticated::class);
        $this->withSession([
            'erp_auth' => [
                'usuario' => 'operador.plan',
                'roles' => ['operador'],
                'modules' => ['lineas_chips'],
                'permissions' => [
                    'lineas_chips.plan_telefonico' => ['ver', 'crear', 'editar', 'eliminar', 'exportar'],
                ],
            ],
        ]);
    }

    public function test_plan_telefonico_supports_create_edit_and_delete(): void
    {
        $indexRoute = route('modules.lineas-chips.plan-telefonico.index');

        $this->post(route('modules.lineas-chips.plan-telefonico.store'), [
            'nombre' => 'Plan Inicial',
            'precio' => '29.90',
            'moneda' => 'S',
            'megas' => '20 GB',
            'sms' => '100 SMS',
        ])->assertRedirect($indexRoute);

        $plan = DB::table('plantelef')->first();
        $this->assertNotNull($plan);
        $this->assertSame('Plan Inicial', $plan->nombre);
        $this->assertSame(29.9, (float) $plan->precio);
        $this->assertSame('S', $plan->moneda);
        $this->assertSame('20 GB', $plan->megas);
        $this->assertSame('100 SMS', $plan->sms);

        config(['locks.enabled' => true]);
        ResourceLock::acquire('lineas_chips.plan_telefonico', (string) $plan->idplantelef, 'otro.usuario');

        $editView = app(PlanTelefonicoController::class)->edit((int) $plan->idplantelef);
        $this->assertTrue($editView->getData()['readOnly']);
        $this->assertTrue($editView->getData()['lockBlocked']);
        $this->assertSame('otro.usuario', $editView->getData()['lockOwner']);

        $view = app(PlanTelefonicoController::class)->index(Request::create(
            '/modulos/lineas-chips/plan-telefonico',
            'GET'
        ));
        $this->assertSame('S/ 29.90', $view->getData()['items']->first()->precio_display);

        $this->put(route('modules.lineas-chips.plan-telefonico.update', $plan->idplantelef), [
            'nombre' => 'No debe actualizar',
            'precio' => '35.50',
            'moneda' => '$',
            'megas' => '30 GB',
            'sms' => 'SMS Ilimitados',
        ])->assertRedirect($indexRoute)
            ->assertSessionHas('error');
        $this->assertDatabaseHas('plantelef', [
            'idplantelef' => $plan->idplantelef,
            'nombre' => 'Plan Inicial',
        ]);

        ResourceLock::release('lineas_chips.plan_telefonico', (string) $plan->idplantelef, 'otro.usuario');
        ResourceLock::acquire('lineas_chips.plan_telefonico', (string) $plan->idplantelef, 'operador.plan');

        $createView = app(PlanTelefonicoController::class)->create();
        $currencyField = collect($createView->getData()['fields'])->firstWhere('name', 'moneda');
        $this->assertSame('S', $currencyField['value']);
        $this->assertSame(['S' => 'S/', '$' => '$', '€' => '€'], $currencyField['options']);
        $this->assertTrue($currencyField['hidePlaceholder']);

        $this->put(route('modules.lineas-chips.plan-telefonico.update', $plan->idplantelef), [
            'nombre' => 'Plan Actualizado',
            'precio' => '35.50',
            'moneda' => '$',
            'megas' => '30 GB',
            'sms' => 'SMS Ilimitados',
        ])->assertRedirect($indexRoute);
        $this->assertNull(ResourceLock::status('lineas_chips.plan_telefonico', (string) $plan->idplantelef));

        $this->assertDatabaseHas('plantelef', [
            'idplantelef' => $plan->idplantelef,
            'nombre' => 'Plan Actualizado',
            'moneda' => '$',
            'megas' => '30 GB',
            'sms' => 'SMS Ilimitados',
        ]);
        $this->assertSame(
            35.5,
            (float) DB::table('plantelef')->where('idplantelef', $plan->idplantelef)->value('precio')
        );
        $this->assertSame(
            '$ 35.50',
            app(PlanTelefonicoController::class)->index(Request::create(
                '/modulos/lineas-chips/plan-telefonico',
                'GET'
            ))->getData()['items']->first()->precio_display
        );

        $this->delete(route('modules.lineas-chips.plan-telefonico.destroy', $plan->idplantelef))
            ->assertRedirect($indexRoute);

        $this->assertDatabaseMissing('plantelef', ['idplantelef' => $plan->idplantelef]);
    }

    public function test_plan_telefonico_rejects_currency_not_in_moneda_catalog(): void
    {
        $this->from(route('modules.lineas-chips.plan-telefonico.create'))
            ->post(route('modules.lineas-chips.plan-telefonico.store'), [
                'nombre' => 'Plan Inválido',
                'precio' => '29.90',
                'moneda' => '¥',
                'megas' => '20 GB',
                'sms' => '100 SMS',
            ])
            ->assertSessionHasErrors('moneda');

        $this->assertDatabaseCount('plantelef', 0);
    }

    public function test_plan_telefonico_rejects_generic_currency_symbol(): void
    {
        $this->from(route('modules.lineas-chips.plan-telefonico.create'))
            ->post(route('modules.lineas-chips.plan-telefonico.store'), [
                'nombre' => 'Plan Genérico',
                'precio' => '29.90',
                'moneda' => 'G',
                'megas' => '20 GB',
                'sms' => '100 SMS',
            ])
            ->assertSessionHasErrors('moneda');

        $this->assertDatabaseCount('plantelef', 0);
    }

    public function test_plan_telefonico_shows_s_slash_when_catalog_symbol_is_s_slash(): void
    {
        DB::table('moneda')->where('simbolo', 'S')->update(['simbolo' => 'S/']);
        DB::table('plantelef')->insert([
            'nombre' => 'Plan con soles',
            'precio' => 30,
            'moneda' => 'S/',
            'megas' => '20 GB',
            'sms' => '100 SMS',
        ]);

        $view = app(PlanTelefonicoController::class)->index(Request::create(
            '/modulos/lineas-chips/plan-telefonico',
            'GET'
        ));

        $this->assertSame('S/ 30.00', $view->getData()['items']->first()->precio_display);
    }

    public function test_plan_telefonico_filters_by_price_megas_and_sms(): void
    {
        DB::table('plantelef')->insert([
            [
                'nombre' => 'Plan Básico',
                'precio' => 30,
                'moneda' => 'S',
                'megas' => '10 GB',
                'sms' => '100 SMS',
            ],
            [
                'nombre' => 'Plan Plus',
                'precio' => 50,
                'moneda' => '$',
                'megas' => '20 GB',
                'sms' => '500 SMS',
            ],
            [
                'nombre' => 'Plan Premium',
                'precio' => 100,
                'moneda' => '€',
                'megas' => '100 GB',
                'sms' => 'Ilimitados',
            ],
        ]);

        $controller = app(PlanTelefonicoController::class);
        $cases = [
            [['precio' => '5'], [2]],
            [['megas' => '20'], [2]],
            [['sms' => 'ilimit'], [3]],
            [['precio' => '5', 'megas' => '20', 'sms' => '500'], [2]],
        ];

        foreach ($cases as [$filters, $expectedIds]) {
            $items = $controller->index(Request::create(
                '/modulos/lineas-chips/plan-telefonico',
                'GET',
                $filters
            ))->getData()['items'];

            $this->assertSame($expectedIds, $items->pluck('idplantelef')->map(fn ($id) => (int) $id)->all());
        }
    }
}
