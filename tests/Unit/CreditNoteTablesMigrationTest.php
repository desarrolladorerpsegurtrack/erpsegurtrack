<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CreditNoteTablesMigrationTest extends TestCase
{
    public function test_credit_note_migration_creates_tables_used_by_the_finance_module(): void
    {
        $migration = require base_path('database/migrations/2026_10_21_130000_create_credit_note_tables.php');
        $migration->up();

        $this->assertTrue(Schema::hasColumns('nota_credito', [
            'idnotacredito',
            'cliente_idcliente',
            'moneda_idmoneda',
            'docReferencia',
            'monto',
            'descripcion',
            'fechaEmision',
            'estado',
            'cuentasPorCobrar_idcuentasPorCobrar',
            'fechaAplicacion',
        ]));
        $this->assertTrue(Schema::hasColumns('nota_credito_aplicacion', [
            'id',
            'nota_credito_idnotacredito',
            'cuentasPorCobrar_idcuentasPorCobrar',
            'monto',
            'fechaAplicacion',
            'estado',
        ]));
    }
}
