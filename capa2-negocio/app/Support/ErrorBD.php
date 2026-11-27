<?php

namespace App\Support;

use Illuminate\Database\QueryException;
use PDOException;
use Throwable;

/**
 * Traduce los errores de PostgreSQL (SQLSTATE) a mensajes claros en espanol.
 * Ultima red de seguridad: los servicios detectan estos casos antes, pero si algo llega a la BD
 * (dos usuarios al mismo tiempo, un trigger) el usuario igual recibe un mensaje entendible.
 */
final class ErrorBD
{
    public static function traducir(Throwable $e): string
    {
        $estado = '';
        if ($e instanceof QueryException || $e instanceof PDOException) {
            $estado = (string) ($e->errorInfo[0] ?? $e->getCode());
        }
        $msg = $e->getMessage();

        switch ($estado) {
            case '23505':
                if (str_contains($msg, 'ux_usuario_email_lower')) {
                    return 'Ya existe un usuario con ese correo (sin importar mayúsculas o minúsculas).';
                }
                if (preg_match('/\(([^)]+)\)=\(([^)]+)\)/', $msg, $m)) {
                    return "Ya existe un registro con {$m[1]} = '{$m[2]}'.";
                }
                return 'Ya existe un registro con esos datos.';
            case '23503':
                return str_contains($msg, 'still referenced') || str_contains($msg, 'todavía es referida')
                    ? 'No se puede eliminar porque otros registros dependen de él.'
                    : 'Uno de los datos hace referencia a un registro que no existe.';
            case '23502':
                return preg_match('/column "([^"]+)"/', $msg, $m)
                    ? "El campo '{$m[1]}' no puede estar vacío." : 'Falta un campo obligatorio.';
            case '23514':
                if (str_contains($msg, 'ck_bloque_tipo')) {
                    return 'Un bloque PROGRAMA debe tener programa; una PAUSA_COMERCIAL no puede tener programa ni material.';
                }
                return 'Uno de los valores no está permitido. Revise los valores válidos en la ayuda.';
            case '23P01':
                return 'El horario se solapa con otro bloque de la parrilla.';
            case 'P0001':
                // RAISE EXCEPTION de los triggers: el mensaje ya viene en espanol
                if (preg_match('/ERROR:\s+(.*?)(?:\s+CONTEXT:|\s+\(Connection:|\n|$)/s', $msg, $m)) {
                    return trim($m[1]);
                }
                return $msg;
            case '22001':
                return 'Uno de los textos es demasiado largo.';
            case '22P02':
            case '22007':
            case '22008':
                return 'Uno de los datos tiene un formato incorrecto (números, fechas AAAA-MM-DD u horas HH:MM).';
        }
        if (str_starts_with($estado, '08') || str_contains($msg, 'could not connect') || str_contains($msg, 'Connection refused')) {
            return 'No hay conexión con la base de datos. Intente nuevamente en unos minutos.';
        }
        return 'No se pudo completar la operación en la base de datos.';
    }
}
