<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_guest_is_redirected_to_login_from_root(): void
    {
        $response = $this->get('/');

        $location = $response->headers->get('Location') ?? '';
        $this->assertTrue(
            preg_match('#/(home|login)$#', $location) === 1,
            "Se esperaba redirección a /home o /login, se recibió: {$location}"
        );
    }
}
