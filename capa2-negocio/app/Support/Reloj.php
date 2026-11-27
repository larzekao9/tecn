<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Hora del canal (America/La_Paz). Para demostraciones se puede fijar con CANAL11_AHORA=2026-11-20T10:00
 * (igual que app.ahora del sistema por correo).
 */
final class Reloj
{
    public static function zona(): string
    {
        return config('canal11.zona', 'America/La_Paz');
    }

    public static function ahora(): CarbonImmutable
    {
        $fija = config('canal11.ahora');
        return $fija ? CarbonImmutable::parse($fija, self::zona()) : CarbonImmutable::now(self::zona());
    }

    /** Fecha de hoy AAAA-MM-DD. */
    public static function hoy(): string
    {
        return self::ahora()->format('Y-m-d');
    }

    /** "AAAA-MM-DD" + "HH:MM:SS" -> instante en la zona del canal. */
    public static function instante(string $fecha, string $hora): CarbonImmutable
    {
        return CarbonImmutable::parse("{$fecha} {$hora}", self::zona());
    }
}
