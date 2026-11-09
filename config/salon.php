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
    | SEGURIDAD: PIN de supervisor (doble confirmación)
    |--------------------------------------------------------------------------
    | roles_supervisor: roles cuyo PIN puede desbloquear acciones críticas
    | (anulaciones, arqueos, salidas de efectivo, APROBACIONES). El PIN (4-6
    | dígitos) lo define la administración en el perfil del usuario y se
    | guarda hasheado. Para que SOLO Álvaro autorice aprobaciones, dejar
    | solo 'alvaro' aquí.
    */
    'seguridad' => [
        'roles_supervisor' => ['alvaro'],
    ],

    /*
    |--------------------------------------------------------------------------
    | GOBERNANZA: flujo de pre-aprobación de transacciones sensibles
    |--------------------------------------------------------------------------
    | monto_venta / monto_compra (C$): umbrales a partir de los cuales la
    | transacción se RETIENE en estado 'pendiente_aprobacion' sin contabilizar
    | (ni mover inventario en compras) hasta que un supervisor la apruebe.
    | Con 0 el control por umbral está desactivado (solo aplica el flag
    | explícito del formulario). En ventas, las creadas por un rol supervisor
    | están exentas; en compras el umbral aplica a cualquier creador.
    | roles_supervisor: roles EXENTOS del umbral (sus ventas no se retienen).
    | Para que SOLO Álvaro autorice, dejar solo 'alvaro' aquí.
    */
    'aprobaciones' => [
        'monto_venta' => env('SALON_APROBACION_MONTO_VENTA', 1),
        'monto_compra' => env('SALON_APROBACION_MONTO_COMPRA', 10000),
        'roles_supervisor' => ['alvaro'],
    ],

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
        // Jornada laboral diaria estándar (para tarifa por hora y horas extras)
        'jornada_horas_diarias' => env('SALON_JORNADA_HORAS', 8),
        // Días laborales por mes usados para prorratear el salario (Nicaragua: 30)
        'dias_nomina_mes' => env('SALON_DIAS_NOMINA_MES', 30),
    ],
];