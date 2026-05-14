<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class SecurityHardeningTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_applies_rate_limiting_after_failed_attempts(): void
    {
        $this->seedUsuario('seguridad.user', 'ClaveCorrecta1!');

        $throttleKey = 'seguridad.user|127.0.0.1';
        RateLimiter::clear($throttleKey);

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('login.attempt'), [
                'usuario' => 'seguridad.user',
                'password' => 'ClaveIncorrecta1!',
            ])->assertSessionHasErrors('usuario');
        }

        $this->post(route('login.attempt'), [
            'usuario' => 'seguridad.user',
            'password' => 'ClaveIncorrecta1!',
        ])->assertSessionHasErrors([
            'usuario' => 'Demasiados intentos. Espera un minuto e intenta nuevamente.',
        ]);
    }

    public function test_login_rejects_short_passwords(): void
    {
        $response = $this->post(route('login.attempt'), [
            'usuario' => 'seguridad.user',
            'password' => '1234',
        ]);

        $response->assertSessionHasErrors('password');
    }

    public function test_usuario_store_rejects_invalid_username_characters(): void
    {
        $this->withAdminSession();
        $this->seedPersonal('77889900');

        $response = $this->post(route('modules.usuarios.store'), [
            'usuario' => 'usuario;invalido',
            'personal_dniPersonal' => '77889900',
            'clave' => 'ClaveSegura1!',
            'estado' => '1',
        ]);

        $response->assertSessionHasErrors('usuario');
        $this->assertDatabaseMissing('usuario', ['usuario' => 'usuario;invalido']);
    }

    public function test_usuario_store_allows_hyphenated_username(): void
    {
        $this->withAdminSession();
        $this->seedPersonal('88990011');

        $response = $this->post(route('modules.usuarios.store'), [
            'usuario' => 'juan-perez',
            'personal_dniPersonal' => '88990011',
            'clave' => 'ClaveSegura1!',
            'estado' => '1',
        ]);

        $response->assertSessionDoesntHaveErrors();
        $response->assertRedirect(route('modules.usuarios'));

        $this->assertDatabaseHas('usuario', [
            'usuario' => 'juan-perez',
            'personal_dniPersonal' => '88990011',
            'estado' => '1',
        ]);

        $record = DB::table('usuario')->where('usuario', 'juan-perez')->first();
        $this->assertNotNull($record);
        $this->assertTrue(Hash::check('ClaveSegura1!', (string) $record->clave));
    }

    private function withAdminSession(): void
    {
        $this->withSession([
            'erp_auth' => [
                'usuario' => 'admin',
                'personal_dni' => '00000000',
                'roles' => ['admin'],
                'modules' => ['usuarios', 'roles', 'personal', 'clientes'],
            ],
        ]);
    }

    private function seedUsuario(string $usuario, string $plainPassword): void
    {
        $dni = '11223344';
        $this->seedPersonal($dni);

        DB::table('usuario')->insert([
            'usuario' => $usuario,
            'personal_dniPersonal' => $dni,
            'clave' => Hash::make($plainPassword),
            'estado' => '1',
        ]);
    }

    private function seedPersonal(string $dni): void
    {
        DB::table('cargopersonal')->insertOrIgnore([
            'idcargoPersonal' => 1,
            'descripcion' => 'Cargo Base',
        ]);

        DB::table('personal')->insertOrIgnore([
            'dniPersonal' => $dni,
            'apellido' => 'QA',
            'nombre' => 'Security',
            'cargoPersonal_idcargoPersonal' => 1,
            'foto' => 'foto.png',
            'firma' => 'firma.png',
            'correo' => 'security@example.com',
            'estado' => '1',
        ]);
    }
}
