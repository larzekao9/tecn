<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/** Atajos de acceso a datos con SQL parametrizado (equivalente a las clases D* con PreparedStatement). */
final class Bd
{
    /** @return array<int, array<string, mixed>> */
    public static function filas(string $sql, array $params = []): array
    {
        return array_map(fn ($f) => (array) $f, DB::select($sql, $params));
    }

    /** @return array<string, mixed>|null */
    public static function fila(string $sql, array $params = []): ?array
    {
        $f = DB::selectOne($sql, $params);
        return $f === null ? null : (array) $f;
    }

    public static function valor(string $sql, array $params = []): mixed
    {
        $f = DB::selectOne($sql, $params);
        if ($f === null) {
            return null;
        }
        $a = (array) $f;
        return reset($a);
    }

    public static function existe(string $sql, array $params = []): bool
    {
        return DB::selectOne($sql, $params) !== null;
    }

    /** INSERT ... RETURNING id. */
    public static function insertar(string $sql, array $params, string $columnaId): int
    {
        $f = (array) DB::selectOne($sql . " RETURNING {$columnaId}", $params);
        return (int) $f[$columnaId];
    }

    public static function ejecutar(string $sql, array $params = []): int
    {
        return DB::affectingStatement($sql, $params);
    }
}
