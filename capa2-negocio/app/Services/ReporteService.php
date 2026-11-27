<?php

namespace App\Services;

use App\Models\Usuario;
use App\Support\Bd;
use App\Support\Validador;
use DateTimeImmutable;

/**
 * CU8 - Reportes y Estadisticas. Todos reciben desde y hasta (inclusive), desde <= hasta y maximo 366 dias.
 * Las agrupaciones se hacen en SQL (GROUP BY); aqui se valida, se formatea y se agrega la fila TOTAL.
 * Los reportes son solo de lectura: no van a la bitacora como ACCION.
 */
class ReporteService
{
    public const MAXIMO_DIAS = 366;

    /** repPro: horas programadas por tipo de programa. */
    public function horasPorPrograma(Usuario $quien, array $p): array
    {
        [$desde, $hasta] = $this->rango($p);
        $filas = Bd::filas("SELECT coalesce(p.tipo, 'SIN PROGRAMA') AS tipo, count(*) AS bloques,"
            . ' CAST(coalesce(sum(extract(epoch FROM (b.hora_fin - b.hora_inicio))), 0) AS bigint) AS segundos'
            . ' FROM bloque b JOIN parrilla pr ON pr.id_parrilla = b.id_parrilla LEFT JOIN programa p ON p.id_programa = b.id_programa'
            . " WHERE b.tipo = 'PROGRAMA' AND pr.fecha BETWEEN ? AND ? GROUP BY coalesce(p.tipo, 'SIN PROGRAMA') ORDER BY 3 DESC, 1", [$desde, $hasta]);
        $tb = 0;
        $ts = 0;
        foreach ($filas as &$f) {
            $tb += (int) $f['bloques'];
            $ts += (int) $f['segundos'];
            $f['horas'] = self::horas((int) $f['segundos']);
            $f['horas_decimal'] = round($f['segundos'] / 3600, 2);
        }
        $total = $filas ? ['tipo' => 'TOTAL', 'bloques' => $tb, 'segundos' => $ts, 'horas' => self::horas($ts), 'horas_decimal' => round($ts / 3600, 2)] : null;
        return $this->respuesta('Horas programadas por tipo de programa', $desde, $hasta, $filas, $total);
    }

    /** repMat: materiales por tipo que salen en las parrillas del rango (como programa, en una escaleta o como spot). */
    public function materiales(Usuario $quien, array $p): array
    {
        [$desde, $hasta] = $this->rango($p);
        $filas = Bd::filas('SELECT m.tipo, count(DISTINCT m.id_material) AS cantidad FROM material m'
            . ' WHERE m.id_material IN (SELECT b.id_material FROM bloque b JOIN parrilla pr ON pr.id_parrilla = b.id_parrilla WHERE pr.fecha BETWEEN ? AND ?'
            . '   UNION SELECT e.id_material FROM escaleta e JOIN bloque b ON b.id_bloque = e.id_bloque JOIN parrilla pr ON pr.id_parrilla = b.id_parrilla WHERE pr.fecha BETWEEN ? AND ?'
            . '   UNION SELECT pa.id_material FROM spot s JOIN pauta pa ON pa.id_pauta = s.id_pauta JOIN bloque b ON b.id_bloque = s.id_bloque'
            . '     JOIN parrilla pr ON pr.id_parrilla = b.id_parrilla WHERE pr.fecha BETWEEN ? AND ?)'
            . ' GROUP BY m.tipo ORDER BY m.tipo', [$desde, $hasta, $desde, $hasta, $desde, $hasta]);
        $total = $filas ? ['tipo' => 'TOTAL', 'cantidad' => array_sum(array_column($filas, 'cantidad'))] : null;
        return $this->respuesta('Materiales por tipo', $desde, $hasta, $filas, $total);
    }

    /** repPau: spots programados y emitidos por anunciante. */
    public function spotsPorAnunciante(Usuario $quien, array $p): array
    {
        [$desde, $hasta] = $this->rango($p);
        $filas = Bd::filas("SELECT u.nombre || ' ' || u.apellido AS anunciante, count(*) AS programados, count(*) FILTER (WHERE s.estado = 'EMITIDO') AS emitidos,"
            . " count(*) FILTER (WHERE s.estado = 'NO_EMITIDO') AS no_emitidos, count(*) FILTER (WHERE s.estado = 'PROGRAMADO') AS pendientes"
            . ' FROM spot s JOIN pauta pa ON pa.id_pauta = s.id_pauta JOIN usuario u ON u.id_usuario = pa.id_usuario'
            . ' JOIN bloque b ON b.id_bloque = s.id_bloque JOIN parrilla pr ON pr.id_parrilla = b.id_parrilla'
            . " WHERE pr.fecha BETWEEN ? AND ? GROUP BY u.id_usuario, u.nombre, u.apellido ORDER BY 2 DESC, 1", [$desde, $hasta]);
        $total = null;
        if ($filas) {
            $total = ['anunciante' => 'TOTAL'];
            foreach (['programados', 'emitidos', 'no_emitidos', 'pendientes'] as $c) {
                $total[$c] = array_sum(array_column($filas, $c));
            }
        }
        return $this->respuesta('Spots por anunciante', $desde, $hasta, $filas, $total);
    }

    /** repCum: cumplimiento por dia (solo parrillas PUBLICADAS). */
    public function cumplimiento(Usuario $quien, array $p): array
    {
        [$desde, $hasta] = $this->rango($p);
        $filas = Bd::filas("SELECT to_char(pr.fecha, 'YYYY-MM-DD') AS fecha, count(*) AS programados,"
            . " count(*) FILTER (WHERE c.estado = 'EMITIDO') AS emitidos, count(*) FILTER (WHERE c.estado = 'PARCIAL') AS parciales,"
            . " count(*) FILTER (WHERE c.estado = 'NO_EMITIDO') AS no_emitidos, count(*) FILTER (WHERE c.id_conciliacion IS NULL) AS sin_registro"
            . ' FROM parrilla pr JOIN bloque b ON b.id_parrilla = pr.id_parrilla LEFT JOIN conciliacion c ON c.id_bloque = b.id_bloque'
            . " WHERE pr.estado = 'PUBLICADA' AND pr.fecha BETWEEN ? AND ? GROUP BY pr.fecha ORDER BY pr.fecha", [$desde, $hasta]);
        foreach ($filas as &$f) {
            $f['cumplimiento'] = self::porcentaje((int) $f['emitidos'], (int) $f['programados']);
        }
        $total = null;
        if ($filas) {
            $total = ['fecha' => 'TOTAL'];
            foreach (['programados', 'emitidos', 'parciales', 'no_emitidos', 'sin_registro'] as $c) {
                $total[$c] = array_sum(array_column($filas, $c));
            }
            $total['cumplimiento'] = self::porcentaje($total['emitidos'], $total['programados']);
        }
        return $this->respuesta('Cumplimiento: programado vs. emitido', $desde, $hasta, $filas, $total);
    }

    // ------------------------------------------------------------------ auxiliares

    /** Valida desde / hasta y devuelve [desde, hasta]. */
    public function rango(array $p): array
    {
        $v = new Validador();
        $desde = $v->fecha($p[0] ?? null, 'desde');
        $hasta = $v->fecha($p[1] ?? null, 'hasta');
        if ($desde !== null && $hasta !== null) {
            $dias = (new DateTimeImmutable($desde))->diff(new DateTimeImmutable($hasta))->days + 1;
            if ($desde > $hasta) {
                $v->error("La fecha <b>desde</b> ({$desde}) no puede ser posterior a <b>hasta</b> ({$hasta}).");
            } elseif ($dias > self::MAXIMO_DIAS) {
                $v->error('El rango no puede superar ' . self::MAXIMO_DIAS . " días (pidió {$dias}).");
            }
        }
        $v->verificar();
        return [$desde, $hasta];
    }

    private function respuesta(string $titulo, string $desde, string $hasta, array $filas, ?array $total): array
    {
        return ['titulo' => $titulo, 'datos' => ['desde' => $desde, 'hasta' => $hasta, 'filas' => $filas, 'total' => $total]];
    }

    /** 45000 s -> "12 h 30 min". */
    public static function horas(int $segundos): string
    {
        $minutos = (int) round($segundos / 60);
        return intdiv($minutos, 60) . ' h ' . sprintf('%02d', $minutos % 60) . ' min';
    }

    public static function porcentaje(int $parte, int $total): float
    {
        return $total === 0 ? 0.0 : round($parte * 100 / $total, 1);
    }
}
