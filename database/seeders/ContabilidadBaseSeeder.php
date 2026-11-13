<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;


class ContabilidadBaseSeeder extends Seeder
{
    public function run()
    {
        // 1. Crear Monedas
        $nioId = $this->moneda([
            'codigo' => 'NIO',
            'nombre' => 'Córdoba Nicaragüense',
            'es_base' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $usdId = $this->moneda([
            'codigo' => 'USD',
            'nombre' => 'Dólar Estadounidense',
            'es_base' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Los períodos se crean por el servicio contable según la operación.

        // ====================================================================
        // 3. CREAR EL CATÁLOGO DE CUENTAS 
        // ====================================================================

        // --- CLASE 1: ACTIVO  ---
        $activo = $this->cuenta(['codigo' => '1', 'nombre' => 'ACTIVO', 'tipo' => 'activo', 'naturaleza' => 'deudora', 'nivel' => 1, 'permite_movimiento' => false, 'created_at' => now()]);
        
        $activoCirculante = $this->cuenta(['codigo' => '1.1', 'nombre' => 'Activo Corriente', 'tipo' => 'activo', 'naturaleza' => 'deudora', 'cuenta_padre_id' => $activo, 'nivel' => 2, 'permite_movimiento' => false, 'created_at' => now()]);
        $bancos = $this->cuenta(['codigo' => '1.1.2', 'nombre' => 'Bancos', 'tipo' => 'activo', 'naturaleza' => 'deudora', 'cuenta_padre_id' => $activoCirculante, 'nivel' => 3, 'permite_movimiento' => false, 'created_at' => now()]);
        
        $this->cuentas([
            ['codigo' => '1.1.1', 'nombre' => 'Caja General / Caja Chica', 'tipo' => 'activo', 'naturaleza' => 'deudora', 'cuenta_padre_id' => $activoCirculante, 'nivel' => 3, 'permite_movimiento' => true, 'created_at' => now()],
            ['codigo' => '1.1.2.1', 'nombre' => 'Banco Lafise', 'tipo' => 'activo', 'naturaleza' => 'deudora', 'cuenta_padre_id' => $bancos, 'nivel' => 4, 'permite_movimiento' => true, 'created_at' => now()],
            ['codigo' => '1.1.2.2', 'nombre' => 'Banco BAC', 'tipo' => 'activo', 'naturaleza' => 'deudora', 'cuenta_padre_id' => $bancos, 'nivel' => 4, 'permite_movimiento' => true, 'created_at' => now()],
            ['codigo' => '1.1.3', 'nombre' => 'Cuentas por Cobrar', 'tipo' => 'activo', 'naturaleza' => 'deudora', 'cuenta_padre_id' => $activoCirculante, 'nivel' => 3, 'permite_movimiento' => true, 'created_at' => now()],
            ['codigo' => '1.1.4', 'nombre' => 'Inventario de Productos', 'tipo' => 'activo', 'naturaleza' => 'deudora', 'cuenta_padre_id' => $activoCirculante, 'nivel' => 3, 'permite_movimiento' => true, 'created_at' => now()],
            ['codigo' => '1.1.5', 'nombre' => 'Inventario de Insumos', 'tipo' => 'activo', 'naturaleza' => 'deudora', 'cuenta_padre_id' => $activoCirculante, 'nivel' => 3, 'permite_movimiento' => true, 'created_at' => now()],
            ['codigo' => '1.1.6', 'nombre' => 'Adelantos de Salario', 'tipo' => 'activo', 'naturaleza' => 'deudora', 'cuenta_padre_id' => $activoCirculante, 'nivel' => 3, 'permite_movimiento' => true, 'created_at' => now()],
        ]);

        $activoNoCirculante = $this->cuenta(['codigo' => '1.2', 'nombre' => 'Activo No Corriente', 'tipo' => 'activo', 'naturaleza' => 'deudora', 'cuenta_padre_id' => $activo, 'nivel' => 2, 'permite_movimiento' => false, 'created_at' => now()]);
        $this->cuentas([
            ['codigo' => '1.2.1', 'nombre' => 'Mobiliario y Enseres', 'tipo' => 'activo', 'naturaleza' => 'deudora', 'cuenta_padre_id' => $activoNoCirculante, 'nivel' => 3, 'permite_movimiento' => true, 'created_at' => now()],
            ['codigo' => '1.2.2', 'nombre' => 'Equipo de Belleza', 'tipo' => 'activo', 'naturaleza' => 'deudora', 'cuenta_padre_id' => $activoNoCirculante, 'nivel' => 3, 'permite_movimiento' => true, 'created_at' => now()],
            ['codigo' => '1.2.3', 'nombre' => 'Equipos de Cómputo', 'tipo' => 'activo', 'naturaleza' => 'deudora', 'cuenta_padre_id' => $activoNoCirculante, 'nivel' => 3, 'permite_movimiento' => true, 'created_at' => now()],
        ]);

        // --- CLASE 2: PASIVO (Deudas y obligaciones) ---
        $pasivo = $this->cuenta(['codigo' => '2', 'nombre' => 'PASIVO', 'tipo' => 'pasivo', 'naturaleza' => 'acreedora', 'nivel' => 1, 'permite_movimiento' => false, 'created_at' => now()]);
        
        $pasivoCorto = $this->cuenta(['codigo' => '2.1', 'nombre' => 'Pasivo Corriente', 'tipo' => 'pasivo', 'naturaleza' => 'acreedora', 'cuenta_padre_id' => $pasivo, 'nivel' => 2, 'permite_movimiento' => false, 'created_at' => now()]);
        $this->cuentas([
            ['codigo' => '2.1.1', 'nombre' => 'Cuentas por Pagar Proveedores', 'tipo' => 'pasivo', 'naturaleza' => 'acreedora', 'cuenta_padre_id' => $pasivoCorto, 'nivel' => 3, 'permite_movimiento' => true, 'created_at' => now()],
            ['codigo' => '2.1.2', 'nombre' => 'Préstamos Bancarios a Corto Plazo', 'tipo' => 'pasivo', 'naturaleza' => 'acreedora', 'cuenta_padre_id' => $pasivoCorto, 'nivel' => 3, 'permite_movimiento' => true, 'created_at' => now()],
            ['codigo' => '2.1.3', 'nombre' => 'Impuestos por Pagar', 'tipo' => 'pasivo', 'naturaleza' => 'acreedora', 'cuenta_padre_id' => $pasivoCorto, 'nivel' => 3, 'permite_movimiento' => true, 'created_at' => now()],
            ['codigo' => '2.1.4', 'nombre' => 'Sueldos y Salarios por Pagar', 'tipo' => 'pasivo', 'naturaleza' => 'acreedora', 'cuenta_padre_id' => $pasivoCorto, 'nivel' => 3, 'permite_movimiento' => true, 'created_at' => now()],
        ]);

        $pasivoLargo = $this->cuenta(['codigo' => '2.2', 'nombre' => 'Pasivo No Corriente', 'tipo' => 'pasivo', 'naturaleza' => 'acreedora', 'cuenta_padre_id' => $pasivo, 'nivel' => 2, 'permite_movimiento' => false, 'created_at' => now()]);
        $this->cuentas([
            ['codigo' => '2.2.1', 'nombre' => 'Préstamos Bancarios a Largo Plazo', 'tipo' => 'pasivo', 'naturaleza' => 'acreedora', 'cuenta_padre_id' => $pasivoLargo, 'nivel' => 3, 'permite_movimiento' => true, 'created_at' => now()],
        ]);

        // --- CLASE 3: CAPITAL ---
        $capital = $this->cuenta(['codigo' => '3', 'nombre' => 'CAPITAL', 'tipo' => 'patrimonio', 'naturaleza' => 'acreedora', 'nivel' => 1, 'permite_movimiento' => false, 'created_at' => now()]);
        $this->cuentas([
            ['codigo' => '3.1', 'nombre' => 'Capital Social', 'tipo' => 'patrimonio', 'naturaleza' => 'acreedora', 'cuenta_padre_id' => $capital, 'nivel' => 2, 'permite_movimiento' => true, 'created_at' => now()],
            ['codigo' => '3.2', 'nombre' => 'Utilidades Retenidas', 'tipo' => 'patrimonio', 'naturaleza' => 'acreedora', 'cuenta_padre_id' => $capital, 'nivel' => 2, 'permite_movimiento' => true, 'created_at' => now()],
        ]);

        // --- CLASE 4: INGRESOS ---
        $ingreso = $this->cuenta(['codigo' => '4', 'nombre' => 'INGRESOS', 'tipo' => 'ingreso', 'naturaleza' => 'acreedora', 'nivel' => 1, 'permite_movimiento' => false, 'created_at' => now()]);
        $this->cuentas([
            ['codigo' => '4.1', 'nombre' => 'Ingresos por Servicios de Peluquería', 'tipo' => 'ingreso', 'naturaleza' => 'acreedora', 'cuenta_padre_id' => $ingreso, 'nivel' => 2, 'permite_movimiento' => true, 'created_at' => now()],
            ['codigo' => '4.2', 'nombre' => 'Ingresos por Servicios de Estética', 'tipo' => 'ingreso', 'naturaleza' => 'acreedora', 'cuenta_padre_id' => $ingreso, 'nivel' => 2, 'permite_movimiento' => true, 'created_at' => now()],
            ['codigo' => '4.3', 'nombre' => 'Ingresos por Tratamientos', 'tipo' => 'ingreso', 'naturaleza' => 'acreedora', 'cuenta_padre_id' => $ingreso, 'nivel' => 2, 'permite_movimiento' => true, 'created_at' => now()],
            ['codigo' => '4.4', 'nombre' => 'Venta de Productos', 'tipo' => 'ingreso', 'naturaleza' => 'acreedora', 'cuenta_padre_id' => $ingreso, 'nivel' => 2, 'permite_movimiento' => true, 'created_at' => now()],
            // Cuenta de naturaleza contraria para registrar los descuentos
            ['codigo' => '4.5', 'nombre' => 'Descuentos sobre Ventas', 'tipo' => 'ingreso', 'naturaleza' => 'deudora', 'cuenta_padre_id' => $ingreso, 'nivel' => 2, 'permite_movimiento' => true, 'created_at' => now()], 
        ]);

        // --- CLASE 5: COSTOS ---
        $costo = $this->cuenta(['codigo' => '5', 'nombre' => 'COSTOS', 'tipo' => 'costo', 'naturaleza' => 'deudora', 'nivel' => 1, 'permite_movimiento' => false, 'created_at' => now()]);
        $this->cuentas([
            ['codigo' => '5.1', 'nombre' => 'Costo de Productos Vendidos', 'tipo' => 'costo', 'naturaleza' => 'deudora', 'cuenta_padre_id' => $costo, 'nivel' => 2, 'permite_movimiento' => true, 'created_at' => now()],
            ['codigo' => '5.2', 'nombre' => 'Insumos Consumidos', 'tipo' => 'costo', 'naturaleza' => 'deudora', 'cuenta_padre_id' => $costo, 'nivel' => 2, 'permite_movimiento' => true, 'created_at' => now()],
            ['codigo' => '5.3', 'nombre' => 'Comisiones de Estilistas', 'tipo' => 'costo', 'naturaleza' => 'deudora', 'cuenta_padre_id' => $costo, 'nivel' => 2, 'permite_movimiento' => true, 'created_at' => now()],
            ['codigo' => '5.4', 'nombre' => 'Pago a Modelos', 'tipo' => 'costo', 'naturaleza' => 'deudora', 'cuenta_padre_id' => $costo, 'nivel' => 2, 'permite_movimiento' => true, 'created_at' => now()],
        ]);

        // --- CLASE 6: GASTOS OPERATIVOS ---
        $gasto = $this->cuenta(['codigo' => '6', 'nombre' => 'GASTOS OPERATIVOS', 'tipo' => 'gasto', 'naturaleza' => 'deudora', 'nivel' => 1, 'permite_movimiento' => false, 'created_at' => now()]);
        $this->cuentas([
            ['codigo' => '6.1', 'nombre' => 'Gastos de Local (Alquiler)', 'tipo' => 'gasto', 'naturaleza' => 'deudora', 'cuenta_padre_id' => $gasto, 'nivel' => 2, 'permite_movimiento' => true, 'created_at' => now()],
            ['codigo' => '6.2', 'nombre' => 'Servicios Básicos', 'tipo' => 'gasto', 'naturaleza' => 'deudora', 'cuenta_padre_id' => $gasto, 'nivel' => 2, 'permite_movimiento' => true, 'created_at' => now()],
            ['codigo' => '6.3', 'nombre' => 'Publicidad y Marketing', 'tipo' => 'gasto', 'naturaleza' => 'deudora', 'cuenta_padre_id' => $gasto, 'nivel' => 2, 'permite_movimiento' => true, 'created_at' => now()],
            ['codigo' => '6.4', 'nombre' => 'Mantenimiento y Reparación', 'tipo' => 'gasto', 'naturaleza' => 'deudora', 'cuenta_padre_id' => $gasto, 'nivel' => 2, 'permite_movimiento' => true, 'created_at' => now()],
            ['codigo' => '6.5', 'nombre' => 'Productos de Limpieza y Papelería', 'tipo' => 'gasto', 'naturaleza' => 'deudora', 'cuenta_padre_id' => $gasto, 'nivel' => 2, 'permite_movimiento' => true, 'created_at' => now()],
            ['codigo' => '6.6', 'nombre' => 'Licencias, Permisos y Seguros', 'tipo' => 'gasto', 'naturaleza' => 'deudora', 'cuenta_padre_id' => $gasto, 'nivel' => 2, 'permite_movimiento' => true, 'created_at' => now()],
            ['codigo' => '6.7', 'nombre' => 'Sueldos y Salarios', 'tipo' => 'gasto', 'naturaleza' => 'deudora', 'cuenta_padre_id' => $gasto, 'nivel' => 2, 'permite_movimiento' => true, 'created_at' => now()],
        ]);

        // --- CLASE 7: OTROS GASTOS Y EGRESOS (Adaptado del Libro Diario) ---
        $otrosGastos = $this->cuenta(['codigo' => '7', 'nombre' => 'OTROS EGRESOS', 'tipo' => 'gasto', 'naturaleza' => 'deudora', 'nivel' => 1, 'permite_movimiento' => false, 'created_at' => now()]);
        $this->cuentas([
            ['codigo' => '7.1', 'nombre' => 'Reembolso a Clientes', 'tipo' => 'gasto', 'naturaleza' => 'deudora', 'cuenta_padre_id' => $otrosGastos, 'nivel' => 2, 'permite_movimiento' => true, 'created_at' => now()],
            ['codigo' => '7.2', 'nombre' => 'Gastos Personales / Retiros del Dueño', 'tipo' => 'gasto', 'naturaleza' => 'deudora', 'cuenta_padre_id' => $otrosGastos, 'nivel' => 2, 'permite_movimiento' => true, 'created_at' => now()],
            ['codigo' => '7.3', 'nombre' => 'Otros Gastos Varios', 'tipo' => 'gasto', 'naturaleza' => 'deudora', 'cuenta_padre_id' => $otrosGastos, 'nivel' => 2, 'permite_movimiento' => true, 'created_at' => now()],
            ['codigo' => '7.4', 'nombre' => 'Pérdidas por Robo o Extravío', 'tipo' => 'gasto', 'naturaleza' => 'deudora', 'cuenta_padre_id' => $otrosGastos, 'nivel' => 2, 'permite_movimiento' => true, 'created_at' => now()],
        ]);
        $this->cuenta(['codigo' => '3.3', 'nombre' => 'Retiros del Propietario', 'tipo' => 'patrimonio', 'naturaleza' => 'deudora', 'cuenta_padre_id' => $capital, 'nivel' => 2, 'permite_movimiento' => true]);
        DB::table('cuentas_contables')->where('codigo', '7.2')->update(['activa' => false, 'permite_movimiento' => false]);
    }

    private function moneda(array $data): int
    {
        $existing = DB::table('monedas')->where('codigo', $data['codigo'])->first();
        return $existing ? (int) $existing->id : DB::table('monedas')->insertGetId($data);
    }

    private function cuenta(array $data): int
    {
        $existing = DB::table('cuentas_contables')->where('codigo', $data['codigo'])->first();
        if ($existing) {
            if ($existing->tipo !== $data['tipo'] || $existing->naturaleza !== $data['naturaleza']) {
                throw new \RuntimeException('Una cuenta base tiene clasificación incompatible; requiere revisión explícita.');
            }
            DB::table('cuentas_contables')->where('id', $existing->id)->update(['is_system_account' => true]);
            return (int) $existing->id;
        }
        return DB::table('cuentas_contables')->insertGetId($data + ['is_system_account' => true, 'activa' => true, 'created_at' => now()]);
    }

    private function cuentas(array $rows): void
    {
        foreach ($rows as $row) {
            $this->cuenta($row);
        }
    }
}
