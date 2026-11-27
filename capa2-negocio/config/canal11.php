<?php

/*
 * Configuracion del sistema Canal 11 (equivale a config.properties del sistema por correo).
 */
return [
    'zona' => env('APP_TIMEZONE', 'America/La_Paz'),

    // Solo pruebas/demostraciones: hora fija del sistema (formato 2026-11-20T10:00). Vacio = hora real.
    'ahora' => env('CANAL11_AHORA') ?: null,

    // Administradores que se crean al iniciar (si no existen). Formato: CI,Nombre,Apellido,correo,contrasena ; separados por punto y coma.
    'administradores' => array_values(array_filter(array_map(function ($a) {
        $c = array_map('trim', explode(',', $a));
        return count($c) === 5 ? array_combine(['ci', 'nombre', 'apellido', 'email', 'password'], $c) : null;
    }, explode(';', (string) env('ADMINISTRADORES', ''))))),

    // Encargados de Contenidos que se crean al iniciar (si no existen). Mismo formato que ADMINISTRADORES.
    'cuentas_encargado' => array_values(array_filter(array_map(function ($a) {
        $c = array_map('trim', explode(',', $a));
        return count($c) === 5 ? array_combine(['ci', 'nombre', 'apellido', 'email', 'password'], $c) : null;
    }, explode(';', (string) env('CUENTAS_ENCARGADO', ''))))),

    // Cuentas de anunciante que se crean al iniciar (si no existen). Mismo formato que ADMINISTRADORES
    // (si trae un sexto dato, la empresa de antes, se ignora: el anunciante es el usuario).
    'cuentas_anunciante' => array_values(array_filter(array_map(function ($a) {
        $c = array_map('trim', explode(',', $a));
        return count($c) >= 5 ? array_combine(['ci', 'nombre', 'apellido', 'email', 'password'], array_slice($c, 0, 5)) : null;
    }, explode(';', (string) env('CUENTAS_ANUNCIANTE', ''))))),

    // Contrasena de las cuentas de demostracion (DemoSeeder).
    'demo_password' => env('DEMO_PASSWORD'),

    // Minutos que vale el enlace firmado para reproducir el video de un material.
    'video_enlace_minutos' => (int) env('VIDEO_ENLACE_MINUTOS', 240),

    // --- CU6 Contrato y pagos de la pauta ---
    'pautas' => [
        'simbolo' => env('MONEDA_SIMBOLO', 'Bs'),
        'max_cuotas' => (int) env('MAX_CUOTAS', 1),
    ],

    // --- CU6 Pago del anunciante en Stripe: con tarjeta (en la moneda del canal) o por QR con Cash App Pay
    //     (Stripe solo lo ofrece en dolares: se cobra al tipo de cambio TIPO_CAMBIO = Bs por 1 USD) ---
    'stripe' => [
        'secreta' => env('STRIPE_SECRET'),
        'moneda' => env('STRIPE_MONEDA', 'bob'),
        'qr_metodo' => env('STRIPE_QR_METODO', 'cashapp'),
        'qr_moneda' => env('STRIPE_QR_MONEDA', 'usd'),
        'tipo_cambio' => (float) env('TIPO_CAMBIO', 0),
        'minimo' => (float) env('PAGO_MINIMO', 0),
        'minutos' => (int) env('STRIPE_MINUTOS', 31),
        'frontend_url' => env('FRONTEND_URL'),
    ],

    // --- Alertas automaticas ---
    'alerta' => [
        'parrilla_horas' => (int) env('ALERTA_PARRILLA_HORAS', 24),
        'parrilla_urgente_horas' => (int) env('ALERTA_PARRILLA_URGENTE_HORAS', 6),
        'emision_minutos' => (int) env('ALERTA_EMISION_MINUTOS', 30),
        'emision_ventana_horas' => (int) env('ALERTA_EMISION_VENTANA_HORAS', 48),
    ],

    // Los avisos siempre quedan en la bandeja de notificaciones de la web; ademas se pueden enviar por correo.
    'notificaciones_correo' => filter_var(env('NOTIFICACIONES_CORREO', false), FILTER_VALIDATE_BOOL),

    // --- Seguridad del login ---
    'login' => [
        'token_horas' => (int) env('LOGIN_TOKEN_HORAS', 12),
    ],

    // --- Paquetes del sistema (diagramas de paquetes del informe): agrupan el menu lateral por caso de uso ---
    'paquetes' => [
        'Gestión Operativa' => [1, 3, 4, 5],
        'Gestión Comercial' => [2, 6, 7],
        'Reportes y Estadísticas' => [8],
    ],

];
