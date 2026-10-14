<?php

namespace Database\Seeders;

use App\Models\ClienteGrupo;
use App\Models\PlantillaImpuesto;
use App\Models\ServicioCategoria;
use Illuminate\Database\Seeder;

/**
 * FASE 1 - ADMINISTRACIÓN: catálogo base idempotente para clientes (grupos),
 * servicios (categorías) y reglas fiscales (plantillas de impuestos).
 * Idempotente: se invoca desde la migración admin_catalogos_base, desde
 * db:seed y desde las pruebas.
 */
class AdminCatalogoSeeder extends Seeder
{
    public function run(): void
    {
        // ------------------------------------------------------------------
        // GRUPOS DE CLIENTES (segmentación + descuento automático en POS)
        // ------------------------------------------------------------------
        $grupos = [
            ['name' => 'Regular', 'description' => 'Cliente general del salón', 'discount_percent' => 0, 'is_default' => true],
            ['name' => 'VIP', 'description' => 'Clientes frecuentes de alto valor', 'discount_percent' => 10, 'is_default' => false],
            ['name' => 'Mayorista', 'description' => 'Compra de productos al por mayor', 'discount_percent' => 15, 'is_default' => false],
        ];

        foreach ($grupos as $grupo) {
            ClienteGrupo::updateOrCreate(['name' => $grupo['name']], $grupo);
        }

        // ------------------------------------------------------------------
        // CATEGORÍAS DE SERVICIOS (agrupación para reportes de venta)
        // ------------------------------------------------------------------
        $categorias = [
            ['name' => 'Cabello', 'color' => '#0ea5e9'],
            ['name' => 'Maquillaje', 'color' => '#ec4899'],
            ['name' => 'Uñas', 'color' => '#a855f7'],
            ['name' => 'Tratamientos', 'color' => '#10b981'],
            ['name' => 'Otros', 'color' => '#6b7280'],
        ];

        foreach ($categorias as $categoria) {
            ServicioCategoria::updateOrCreate(['name' => $categoria['name']], $categoria);
        }

        // ------------------------------------------------------------------
        // PLANTILLAS DE IMPUESTOS (reglas fiscales enlazables al servicio)
        // ------------------------------------------------------------------
        PlantillaImpuesto::updateOrCreate(['name' => 'Exento'], ['rate' => 0, 'is_default' => true]);
        PlantillaImpuesto::updateOrCreate(['name' => 'IVA 15%'], ['rate' => 15, 'is_default' => false]);
    }
}
