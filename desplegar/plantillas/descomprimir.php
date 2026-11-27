<?php

/*
 * Descomprime canal11.zip en esta carpeta del servidor y luego se borra a si mismo.
 * Se abre una sola vez en el navegador: .../descomprimir.php?clave=__CLAVE__
 * El zip no trae laravel/.env: la configuracion del servidor nunca se pisa al volver a subir.
 */

header('Content-Type: text/plain; charset=utf-8');
if (!hash_equals('__CLAVE__', (string) ($_GET['clave'] ?? ''))) {
    http_response_code(403);
    exit("No autorizado.\n");
}
@set_time_limit(900);

echo 'PHP ' . PHP_VERSION . (version_compare(PHP_VERSION, '8.3.0', '>=') ? " (ok)\n" : " -- Laravel necesita PHP 8.3 o superior\n");
foreach (['zip', 'pdo_pgsql', 'mbstring', 'openssl', 'fileinfo', 'bcmath'] as $ext) {
    echo "extension {$ext}: " . (extension_loaded($ext) ? 'si' : 'NO') . "\n";
}

$archivo = __DIR__ . '/canal11.zip';
$zip = new ZipArchive();
if (!is_file($archivo) || $zip->open($archivo) !== true) {
    exit("No se pudo abrir canal11.zip\n");
}
$total = $zip->numFiles;
// los archivos del front compilado cambian de nombre en cada version: se borran los de la anterior
if (is_dir(__DIR__ . '/assets')) {
    $viejos = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__ . '/assets', FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($viejos as $f) {
        $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    }
}
if (!$zip->extractTo(__DIR__)) {
    exit("Fallo al descomprimir\n");
}
$zip->close();
unlink($archivo);

// Lo descomprimido queda a nombre del usuario de Apache: se abre a escritura para que la cuenta FTP del grupo
// pueda reemplazarlo o borrarlo y Laravel pueda escribir en storage/ y bootstrap/cache/
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(__DIR__, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST);
foreach ($it as $f) {
    if ($f->getFilename() !== '.env') {
        @chmod($f->getPathname(), $f->isDir() ? 0777 : 0666);
    }
}

echo "Descomprimidos {$total} archivos.\n";
echo is_file(__DIR__ . '/laravel/.env') ? "laravel/.env: presente\n" : "laravel/.env: FALTA (subirlo una vez)\n";
unlink(__FILE__);
echo "Listo. Este script ya se borro.\n";
