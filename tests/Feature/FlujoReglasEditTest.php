<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureErpAuthenticated;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FlujoReglasEditTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['locks.enabled' => false]);
        $this->createSchema();
        if (DB::connection()->getDriverName() === 'sqlite') {
            DB::connection()->getPdo()->sqliteCreateFunction(
                'CONCAT',
                static fn (...$values): string => implode('', array_map(static fn ($value) => (string) $value, $values)),
                -1
            );
        }
        $this->withSession([
            'erp_auth' => [
                'usuario' => 'admin.flujos',
                'roles' => ['admin'],
                'modules' => ['sistema'],
                'permissions' => [],
            ],
        ]);
        $this->withoutMiddleware(EnsureErpAuthenticated::class);
    }

    public function test_flow_edit_reorders_existing_rules_and_adds_a_view_without_replacing_history_ids(): void
    {
        [$flowId, $vistaIds, $ruleIds] = $this->seedFlowWithRules();

        $response = $this->put(route('modules.sistema.flujos.update', $flowId), [
            'tipoOperacion_idtipoOperacion' => 1,
            'nombre' => 'Flujo de prueba',
            'descripcion' => 'Descripción de prueba',
            'reglas' => [
                [
                    'idflujoregla' => $ruleIds[1],
                    'vista_idvista' => $vistaIds[1],
                    'orden' => 1,
                ],
                [
                    'idflujoregla' => $ruleIds[0],
                    'vista_idvista' => $vistaIds[0],
                    'orden' => 2,
                ],
                [
                    'idflujoregla' => '',
                    'vista_idvista' => $vistaIds[2],
                    'orden' => 3,
                ],
            ],
        ]);

        $response->assertRedirect(route('modules.sistema.flujos.index'));
        $this->assertDatabaseHas('flujoregla', [
            'idflujoregla' => $ruleIds[1],
            'orden' => 1,
            'estado' => '0',
            'condicion' => 'ver vista 1',
        ]);
        $this->assertDatabaseHas('flujoregla', [
            'idflujoregla' => $ruleIds[0],
            'orden' => 2,
            'estado' => '1',
            'condicion' => 'ver vista 2',
        ]);
        $this->assertDatabaseHas('flujoregla', [
            'flujo_idflujo' => $flowId,
            'vista_idvista' => $vistaIds[2],
            'orden' => 3,
            'estado' => '1',
            'condicion' => 'ver vista 3',
        ]);
        $this->assertSame(3, DB::table('flujoregla')->where('flujo_idflujo', $flowId)->count());
    }

    public function test_flow_edit_screen_renders_rule_ordering_controls(): void
    {
        [$flowId, , $ruleIds] = $this->seedFlowWithRules();

        $this->get(route('modules.sistema.flujos.edit', $flowId))
            ->assertOk()
            ->assertSee('Reordena las reglas o agrega vistas.')
            ->assertSee('Agregar vista')
            ->assertDontSee('data-tipo-vista-select')
            ->assertSee('data-drag-handle')
            ->assertSee('"idflujoregla":' . $ruleIds[0], false)
            ->assertSee('"idflujoregla":' . $ruleIds[1], false);
    }

    public function test_new_flow_starts_with_a_disabled_view_placeholder(): void
    {
        DB::table('tipooperacion')->insert([
            'idtipoOperacion' => 1,
            'nomenclatura' => 'CT',
            'detalle' => 'Cotización',
        ]);
        DB::table('vista')->insert([
            'nombre' => 'Vista A',
            'detalle' => 'Vista A',
            'estado' => 'Activo',
        ]);

        $this->get(route('modules.sistema.flujos.create'))
            ->assertOk()
            ->assertSee('<option value="" selected disabled>Selecciona una vista</option>', false);
    }

    public function test_flow_edit_rejects_removing_an_existing_rule(): void
    {
        [$flowId, $vistaIds, $ruleIds] = $this->seedFlowWithRules();

        $response = $this->from(route('modules.sistema.flujos.edit', $flowId))
            ->put(route('modules.sistema.flujos.update', $flowId), [
                'tipoOperacion_idtipoOperacion' => 1,
                'nombre' => 'Flujo de prueba',
                'descripcion' => 'Descripción de prueba',
                'reglas' => [
                    [
                        'idflujoregla' => $ruleIds[0],
                        'vista_idvista' => $vistaIds[0],
                        'orden' => 1,
                    ],
                ],
            ]);

        $response->assertRedirect(route('modules.sistema.flujos.edit', $flowId));
        $response->assertSessionHasErrors('reglas');
        $this->assertSame(2, DB::table('flujoregla')->where('flujo_idflujo', $flowId)->count());
        $this->assertDatabaseHas('flujoregla', [
            'idflujoregla' => $ruleIds[0],
            'orden' => 1,
        ]);
    }

    private function createSchema(): void
    {
        Schema::create('tipooperacion', function (Blueprint $table) {
            $table->increments('idtipoOperacion');
            $table->string('nomenclatura');
            $table->string('detalle');
        });

        Schema::create('flujo', function (Blueprint $table) {
            $table->increments('idflujo');
            $table->unsignedInteger('tipoOperacion_idtipoOperacion');
            $table->string('nombre');
            $table->string('descripcion');
            $table->dateTime('fechacreacion')->nullable();
        });

        Schema::create('vista', function (Blueprint $table) {
            $table->increments('idvista');
            $table->string('nombre');
            $table->string('detalle');
            $table->string('estado');
            $table->dateTime('fechacreacion')->nullable();
        });

        Schema::create('flujoregla', function (Blueprint $table) {
            $table->increments('idflujoregla');
            $table->unsignedInteger('flujo_idflujo');
            $table->unsignedInteger('vista_idvista');
            $table->integer('orden')->nullable();
            $table->string('estado')->nullable();
            $table->string('condicion')->nullable();
        });

        Schema::create('notificaciones', function (Blueprint $table) {
            $table->increments('id');
            $table->string('notifiable_id');
            $table->string('type');
            $table->text('data')->nullable();
        });
    }

    private function seedFlowWithRules(): array
    {
        DB::table('tipooperacion')->insert([
            'idtipoOperacion' => 1,
            'nomenclatura' => 'CT',
            'detalle' => 'Cotización',
        ]);

        $flowId = DB::table('flujo')->insertGetId([
            'tipoOperacion_idtipoOperacion' => 1,
            'nombre' => 'Flujo de prueba',
            'descripcion' => 'Descripción de prueba',
        ]);

        $vistaIds = [];
        foreach (['Vista A', 'Vista B', 'Vista C'] as $name) {
            $vistaIds[] = DB::table('vista')->insertGetId([
                'nombre' => $name,
                'detalle' => $name,
                'estado' => 'Activo',
            ]);
        }

        $ruleIds = [
            DB::table('flujoregla')->insertGetId([
                'flujo_idflujo' => $flowId,
                'vista_idvista' => $vistaIds[0],
                'orden' => 1,
                'estado' => '1',
                'condicion' => 'ver vista 1',
            ]),
            DB::table('flujoregla')->insertGetId([
                'flujo_idflujo' => $flowId,
                'vista_idvista' => $vistaIds[1],
                'orden' => 2,
                'estado' => '0',
                'condicion' => 'ver vista 2',
            ]),
        ];

        return [$flowId, $vistaIds, $ruleIds];
    }
}
