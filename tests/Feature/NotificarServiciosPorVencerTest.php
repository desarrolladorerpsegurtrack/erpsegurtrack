<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class NotificarServiciosPorVencerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-22 09:00:00');
        Http::fake();
        $this->registerSqliteFunctions();
        $this->createSchema();
        $this->createAuthorizedUser('cxc-user', true);
        $this->createAuthorizedUser('other-user', false);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_notifies_authorized_users_once_per_group_for_services_due_in_one_to_five_days(): void
    {
        $this->createService(11, '2026-10-01', '2026-10-23', 'ABC-111', 'GPS', 1);
        $this->createService(12, '2026-10-01', '2026-10-23', 'ABC-222', 'Cámara', 1);
        $this->createService(13, '2026-10-01', '2026-10-27', 'XYZ-333', 'GPS', 2);
        $this->createService(14, '2026-10-01', '2026-10-22', 'TOD-444', 'GPS', 1);
        $this->createService(15, '2026-10-01', '2026-10-28', 'LATE-555', 'GPS', 1);

        DB::table('notificaciones')->insert([
            'id' => 'stale-notification',
            'type' => 'App\\Notifications\\ServicioPorVencerNotification',
            'notifiable_type' => 'App\\Models\\User',
            'notifiable_id' => 'cxc-user',
            'data' => json_encode([
                'group_key' => 'stale',
                'servicio_ids' => ['99'],
            ]),
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);

        $this->artisan('app:notificar-servicios-por-vencer')
            ->assertExitCode(0);

        $notifications = DB::table('notificaciones')
            ->where('type', 'App\\Notifications\\ServicioPorVencerNotification')
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $notifications);
        $this->assertSame(['cxc-user', 'cxc-user'], $notifications->pluck('notifiable_id')->all());
        $this->assertFalse($notifications->contains('id', 'stale-notification'));

        $payloads = $notifications->map(fn ($notification) => json_decode($notification->data, true));
        $oneDayPayload = $payloads->firstWhere('dias_restantes', 1);
        $fiveDayPayload = $payloads->firstWhere('dias_restantes', 5);

        $this->assertSame(['11', '12'], $oneDayPayload['servicio_ids']);
        $this->assertSame('ABC-111, ABC-222', $oneDayPayload['vehiculo']);
        $this->assertStringContainsString('2 tipos de servicio', $oneDayPayload['mensaje']);
        $this->assertSame(['13'], $fiveDayPayload['servicio_ids']);

        Http::assertSentCount(2);
    }

    public function test_repeated_notification_run_updates_the_group_instead_of_creating_duplicates(): void
    {
        $this->createService(21, '2026-10-01', '2026-10-23', 'ABC-111', 'GPS', 1);

        $this->artisan('app:notificar-servicios-por-vencer')->assertExitCode(0);
        DB::table('serviciocliente')->where('idservicioCliente', 21)->update([
            'vehiculo_placa' => 'ABC-999',
        ]);
        $this->artisan('app:notificar-servicios-por-vencer')->assertExitCode(0);

        $this->assertSame(1, DB::table('notificaciones')
            ->where('type', 'App\\Notifications\\ServicioPorVencerNotification')
            ->count());
        $payload = json_decode(DB::table('notificaciones')->value('data'), true);
        $this->assertSame('ABC-999', $payload['vehiculo']);
        Http::assertSentCount(1);
    }

    public function test_does_not_notify_when_no_user_has_receivables_permission(): void
    {
        DB::table('inforol')->delete();
        $this->createService(31, '2026-10-01', '2026-10-23', 'ABC-111', 'GPS', 1);

        $this->artisan('app:notificar-servicios-por-vencer')
            ->expectsOutput('No se encontraron usuarios con permiso a Cuentas por Cobrar.')
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('notificaciones')->count());
        Http::assertNothingSent();
    }

    public function test_websocket_failure_does_not_interrupt_notification_creation(): void
    {
        Http::fake(function (): never {
            throw new \RuntimeException('WebSocket no disponible');
        });
        $this->createService(41, '2026-10-01', '2026-10-23', 'ABC-111', 'GPS', 1);

        $this->artisan('app:notificar-servicios-por-vencer')
            ->assertExitCode(0);

        $this->assertSame(1, DB::table('notificaciones')
            ->where('type', 'App\\Notifications\\ServicioPorVencerNotification')
            ->count());
    }

    private function registerSqliteFunctions(): void
    {
        $pdo = DB::connection()->getPdo();
        $pdo->sqliteCreateFunction('DATE_FORMAT', function (?string $date, string $format): ?string {
            if ($date === null) {
                return null;
            }

            $format = strtr($format, ['%d' => 'd', '%m' => 'm', '%Y' => 'Y']);

            return (new \DateTimeImmutable($date))->format($format);
        }, 2);
        $pdo->sqliteCreateFunction('CONCAT', fn (...$parts): string => implode('', $parts), -1);
        $pdo->sqliteCreateFunction('JSON_UNQUOTE', fn ($value) => $value, 1);
    }

    private function createSchema(): void
    {
        Schema::create('serviciocliente', function (Blueprint $table): void {
            $table->increments('idservicioCliente');
            $table->string('cliente_idcliente');
            $table->string('vehiculo_placa')->nullable();
            $table->unsignedInteger('almacen_idalmacen')->nullable();
            $table->string('docReferencia')->nullable();
            $table->date('fechaInicio');
            $table->date('fecheVencimiento')->nullable();
            $table->decimal('monto', 12, 2);
            $table->string('estado')->nullable();
            $table->unsignedInteger('moneda_idmoneda')->nullable();
        });

        Schema::create('cliente', function (Blueprint $table): void {
            $table->string('idcliente')->primary();
            $table->string('razonSocial')->nullable();
            $table->string('nombreComercial')->nullable();
        });
        Schema::create('vehiculo', function (Blueprint $table): void {
            $table->string('placa')->primary();
        });
        Schema::create('almacen', function (Blueprint $table): void {
            $table->increments('idalmacen');
            $table->string('detalle')->nullable();
        });
        Schema::create('cuentasporcobrar', function (Blueprint $table): void {
            $table->increments('idcuentasPorCobrar');
            $table->string('cliente_idcliente');
            $table->string('docReferencia')->nullable();
            $table->decimal('montoOriginal', 12, 2)->nullable();
            $table->text('descripcion')->nullable();
        });
        Schema::create('notificaciones', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('type');
            $table->string('notifiable_type');
            $table->string('notifiable_id');
            $table->text('data');
            $table->dateTime('read_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });
        Schema::create('usuario', function (Blueprint $table): void {
            $table->string('usuario')->primary();
            $table->string('estado');
            $table->string('personal_dniPersonal')->nullable();
        });
        Schema::create('rol', function (Blueprint $table): void {
            $table->increments('idrol');
            $table->string('nombre');
        });
        Schema::create('detallerol', function (Blueprint $table): void {
            $table->string('usuario_usuario');
            $table->unsignedInteger('rol_idrol');
        });
        Schema::create('inforol', function (Blueprint $table): void {
            $table->unsignedInteger('rol_idrol');
            $table->string('modulo')->nullable();
            $table->string('accion')->nullable();
        });
        Schema::create('vista', function (Blueprint $table): void {
            $table->increments('idvista');
        });
    }

    private function createAuthorizedUser(string $username, bool $hasCxcPermission): void
    {
        DB::table('usuario')->insert([
            'usuario' => $username,
            'estado' => '1',
        ]);
        DB::table('rol')->insert([
            'idrol' => $hasCxcPermission ? 1 : 2,
            'nombre' => $hasCxcPermission ? 'Cuentas por cobrar' : 'Sin permisos',
        ]);
        DB::table('detallerol')->insert([
            'usuario_usuario' => $username,
            'rol_idrol' => $hasCxcPermission ? 1 : 2,
        ]);

        if ($hasCxcPermission) {
            DB::table('inforol')->insert([
                'rol_idrol' => 1,
                'modulo' => 'cuentasporcobrar',
                'accion' => 'ver',
            ]);
        }
    }

    private function createService(
        int $id,
        string $start,
        string $due,
        string $plate,
        string $detail,
        int $currency
    ): void {
        DB::table('cliente')->updateOrInsert(
            ['idcliente' => 'CLIENT-1'],
            ['razonSocial' => 'Cliente de prueba']
        );
        DB::table('vehiculo')->updateOrInsert(['placa' => $plate], []);
        $warehouseId = DB::table('almacen')->insertGetId(['detalle' => $detail]);
        DB::table('serviciocliente')->insert([
            'idservicioCliente' => $id,
            'cliente_idcliente' => 'CLIENT-1',
            'vehiculo_placa' => $plate,
            'almacen_idalmacen' => $warehouseId,
            'fechaInicio' => $start,
            'fecheVencimiento' => $due,
            'monto' => 100,
            'estado' => 'Activo',
            'moneda_idmoneda' => $currency,
        ]);
    }
}
