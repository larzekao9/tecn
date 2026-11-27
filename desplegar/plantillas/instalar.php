<?php

/*
 * Prepara la base de datos en el servidor (no hay consola): crea el esquema de DB_SCHEMA si no existe,
 * corre las migraciones y la siembra (idempotente), igual que el arranque de Docker. Luego se borra a si mismo.
 * Se abre una vez en el navegador: .../instalar.php?clave=__CLAVE__
 */

header('Content-Type: text/plain; charset=utf-8');
if (!hash_equals('__CLAVE__', (string) ($_GET['clave'] ?? ''))) {
    http_response_code(403);
    exit("No autorizado.\n");
}
@set_time_limit(900);

require __DIR__ . '/laravel/vendor/autoload.php';
$app = require_once __DIR__ . '/laravel/bootstrap/app.php';
$app->usePublicPath(__DIR__);
$consola = $app->make(Illuminate\Contracts\Console\Kernel::class);
$consola->bootstrap();

try {
    $esquema = (string) config('database.connections.pgsql.search_path');
    Illuminate\Support\Facades\DB::statement('CREATE SCHEMA IF NOT EXISTS "' . str_replace('"', '', $esquema) . '"');
    echo "Esquema {$esquema}: listo\n";
    foreach (['config:clear' => [], 'migrate' => ['--force' => true], 'db:seed' => ['--force' => true]] as $comando => $args) {
        $codigo = $consola->call($comando, $args);
        echo "== {$comando} (codigo {$codigo})\n" . $consola->output() . "\n";
        if ($codigo !== 0) {
            exit("Se detuvo: revise el error de arriba.\n");
        }
    }
} catch (Throwable $e) {
    http_response_code(500);
    exit('Error: ' . $e->getMessage() . "\n");
}

unlink(__FILE__);
echo "Base de datos lista. Este script ya se borro.\n";
