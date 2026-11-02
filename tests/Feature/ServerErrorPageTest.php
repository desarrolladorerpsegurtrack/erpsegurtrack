<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class ServerErrorPageTest extends TestCase
{
    public function test_unexpected_errors_show_a_friendly_page_when_debug_is_disabled(): void
    {
        config(['app.debug' => false]);

        Route::get('/__test-server-error', function () {
            throw new RuntimeException('internal database details');
        });

        $response = $this->get('/__test-server-error');

        $response->assertInternalServerError();
        $response->assertSee('No pudimos completar tu solicitud');
        $response->assertDontSee('internal database details');
        $response->assertDontSee('Illuminate\\Database\\QueryException');
    }
}
