<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Configuración del negocio
    |--------------------------------------------------------------------------
    | Parámetros de negocio del salón. La meta mensual de facturación se usa
    | en el widget de progreso del Dashboard Gerencial.
    */
    'meta_mensual' => env('SALON_META_MENSUAL', 150000),

    /*
    |--------------------------------------------------------------------------
    | Deducciones legales de nómina (INSS / IR - Nicaragua)
    |--------------------------------------------------------------------------
    | Estas tasas y umbrales deben ser confirmados con el contador del negocio.
    | Son un punto de partida configurable, no asesoría fiscal definitiva.
    */
    'nomina' => [
        // INSS aporte del empleado (porcentaje decimal)
        'inss_empleado' => env('SALON_INSS_EMPLEADO', 0.07),
        // INSS aporte patronal (no se deduce al empleado, solo informativo)
        'inss_empleador' => env('SALON_INSS_EMPLEADOR', 0.185),
        // IR: monto mensual exento
        'ir_exento' => env('SALON_IR_EXENTO', 100000),
        // IR: tasa sobre el excedente del exento
        'ir_tasa' => env('SALON_IR_TASA', 0.15),
    ],
];