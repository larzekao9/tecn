<?php

namespace App\Services;

use App\Models\Usuario;
use App\Support\Bd;
use App\Support\Reloj;

/**
 * Tablero de inicio y estadisticas generales (motor de estadisticas de la capa de negocio).
 * Los datos se agregan en SQL; cada rol ve los indicadores de su area.
 */
class EstadisticaService
{
    /** verEst["dias"]. */
    public function generalesCmd(Usuario $u, array $p): array
    {
        $dias = (int) ($p[0] ?? 30);
        return ['titulo' => 'Estadísticas', 'datos' => $this->generales($u, $dias > 0 ? $dias : 30)];
    }

    /** Estadisticas generales para los graficos (rango de dias hacia atras). */
    public function generales(Usuario $u, int $dias = 30): array
    {
        $dias = max(7, min($dias, 366));
        $desde = Reloj::ahora()->subDays($dias - 1)->format('Y-m-d');
        $hasta = Reloj::hoy();
        return [
            'desde' => $desde,
            'hasta' => $hasta,
            'emision_por_estado' => Bd::filas("SELECT coalesce(c.estado, 'SIN REGISTRO') AS estado, count(*) AS cantidad FROM bloque b"
                . ' JOIN parrilla pr ON pr.id_parrilla = b.id_parrilla LEFT JOIN conciliacion c ON c.id_bloque = b.id_bloque'
                . " WHERE pr.estado = 'PUBLICADA' AND pr.fecha BETWEEN ? AND ? GROUP BY 1 ORDER BY 1", [$desde, $hasta]),
            'materiales_por_tipo' => Bd::filas('SELECT tipo, count(*) AS cantidad FROM material GROUP BY tipo ORDER BY tipo'),
            'visitas_por_pagina' => Bd::filas('SELECT pagina, sum(contador) AS visitas FROM visita GROUP BY pagina ORDER BY 2 DESC LIMIT 15'),
            // actividad por dia: peticiones a la API registradas en la bitacora
            'visitas_por_dia' => Bd::filas("SELECT to_char(d::date, 'YYYY-MM-DD') AS fecha, count(b.id_bitacora) AS visitas"
                . " FROM generate_series(?::date, ?::date, interval '1 day') d LEFT JOIN bitacora b ON b.fecha::date = d::date AND b.accion = 'ACCESO'"
                . ' GROUP BY d ORDER BY d', [$desde, $hasta]),
        ];
    }

    // ------------------------------------------------------------------ contador de visitas

    /** usuario realiza visita: suma 1 al contador del usuario en esa pagina y devuelve el total de la pagina. */
    public function visitar(string $pagina, ?int $idUsuario): int
    {
        $pagina = mb_substr(preg_replace('/[^A-Za-z0-9_\-\/]/', '', $pagina) ?: '/', 0, 80);
        Bd::ejecutar('INSERT INTO visita (pagina, id_usuario) VALUES (?, ?) ON CONFLICT ON CONSTRAINT uq_visita'
            . ' DO UPDATE SET contador = visita.contador + 1', [$pagina, $idUsuario]);
        return (int) Bd::valor('SELECT coalesce(sum(contador), 0) FROM visita WHERE pagina = ?', [$pagina]);
    }
}
