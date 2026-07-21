<?php

namespace Tests\Unit;

use App\Services\TicketsService;
use Tests\TestCase;

class TicketsServiceTest extends TestCase
{
    public function test_resolve_cliente_data_uses_razon_social_and_idcliente_when_available(): void
    {
        $service = new TicketsService();

        $result = $service->resolveClienteData((object) [
            'cliente_idcliente' => 'C001',
            'razonSocial' => 'Empresa ABC',
            'nombreComercial' => 'ABC S.A.',
        ]);

        $this->assertSame('Empresa ABC', $result['nombre_cliente']);
        $this->assertSame('C001', $result['doc_cliente']);
    }

    public function test_resolve_cliente_data_falls_back_to_commercial_name_when_razon_social_is_empty(): void
    {
        $service = new TicketsService();

        $result = $service->resolveClienteData((object) [
            'cliente_idcliente' => 'C002',
            'razonSocial' => '',
            'nombreComercial' => 'Negocio XYZ',
        ]);

        $this->assertSame('Negocio XYZ', $result['nombre_cliente']);
        $this->assertSame('C002', $result['doc_cliente']);
    }
}
