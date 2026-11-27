<?php

/*
 * Punto de entrada de la API en el servidor del curso (hosting con Apache, subido por FTP).
 * La carpeta publicada tiene la app React compilada (index.html, assets/) y este archivo; el resto de Laravel
 * vive en ./laravel, que su .htaccess deja inaccesible desde la web.
 */

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$laravel = __DIR__.'/laravel';

if (file_exists($maintenance = $laravel.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $laravel.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once $laravel.'/bootstrap/app.php';
$app->usePublicPath(__DIR__);

$app->handleRequest(Request::capture());
