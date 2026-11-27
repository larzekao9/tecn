<?php

namespace App\Support;

use DateTimeImmutable;

/**
 * Valida TODOS los parametros antes de tocar la base de datos y junta TODOS los errores (mensajes en espanol).
 * Port directo de canal11.negocio.Validador.
 *
 *   $v = new Validador();
 *   $ci = $v->ci($p[0]);
 *   ...
 *   $v->verificar();   // si hubo errores lanza NegocioException con la lista completa
 *
 * Los valores se limpian (sin espacios al inicio/fin ni repetidos). "" o solo espacios cuenta como vacio.
 * Fechas: 'Y-m-d'. Horas: 'H:i:s'.
 */
class Validador
{
    public const FIN_DEL_DIA = '23:59:59';

    private const LETRAS = "/^[a-zA-ZáéíóúÁÉÍÓÚñÑüÜ' ]+$/u";
    private const EMAIL = "/^[A-Za-z0-9!#$%&'*+\/=?^_`{|}~-]+(?:\\.[A-Za-z0-9!#$%&'*+\/=?^_`{|}~-]+)*@(?:[A-Za-z0-9](?:[A-Za-z0-9-]*[A-Za-z0-9])?\\.)+[A-Za-z]{2,}$/";
    private const CI = '/^\d{5,12}(-[0-9A-Za-z]{1,3})?$/';
    private const TELEFONO = '/^\+?\d{7,15}$/';
    private const CODIGO = '/^[A-Za-z0-9\-_]{2,20}$/';

    /** @var string[] */
    private array $errores = [];

    /** Escapa los valores que se repiten en los mensajes (los mensajes se muestran como HTML). */
    public static function esc(?string $s): string
    {
        return $s === null ? '' : str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $s);
    }

    /** Quita espacios de mas. null -> "". */
    public static function limpiar(mixed $valor): string
    {
        if ($valor === null || is_array($valor)) {
            return '';
        }
        if (is_bool($valor)) {
            return $valor ? 'true' : 'false';
        }
        return trim(preg_replace('/\s+/u', ' ', (string) $valor));
    }

    public function error(string $mensaje): void
    {
        $this->errores[] = $mensaje;
    }

    public function verificar(): void
    {
        if ($this->errores !== []) {
            throw new NegocioException($this->errores);
        }
    }

    /** Monto con hasta 2 decimales (acepta coma o punto). */
    public function monto(mixed $valor, string $campo, float $minimo, float $maximo): ?float
    {
        $v = $this->texto($valor, $campo, 15);
        if ($v === null) {
            return null;
        }
        $v = str_replace(',', '.', $v);
        if (!preg_match('/^\d{1,10}(\.\d{1,2})?$/', $v)) {
            $this->errores[] = "El campo <b>{$campo}</b> debe ser un monto válido con hasta 2 decimales (recibido: '" . self::esc($v) . "').";
            return null;
        }
        $n = round((float) $v, 2);
        if ($n < $minimo || $n > $maximo) {
            $this->errores[] = "El campo <b>{$campo}</b> debe estar entre {$minimo} y {$maximo}.";
            return null;
        }
        return $n;
    }

    // ---------------- texto ----------------

    public function texto(mixed $valor, string $campo, int $maximo): ?string
    {
        $v = self::limpiar($valor);
        if ($v === '') {
            $this->errores[] = "El campo <b>{$campo}</b> es obligatorio y llegó vacío.";
            return null;
        }
        $largo = mb_strlen($v);
        if ($largo > $maximo) {
            $this->errores[] = "El campo <b>{$campo}</b> admite máximo {$maximo} caracteres (tiene {$largo}).";
            return null;
        }
        return $v;
    }

    public function textoOpcional(mixed $valor, string $campo, int $maximo): ?string
    {
        $v = self::limpiar($valor);
        return $v === '' ? null : $this->texto($v, $campo, $maximo);
    }

    public function nombre(mixed $valor, string $campo): ?string
    {
        $v = $this->texto($valor, $campo, 80);
        if ($v !== null && !preg_match(self::LETRAS, $v)) {
            $this->errores[] = "El campo <b>{$campo}</b> solo admite letras y espacios (recibido: '" . self::esc($v) . "').";
            return null;
        }
        return $v;
    }

    public function codigo(mixed $valor, string $campo): ?string
    {
        $v = $this->texto($valor, $campo, 20);
        if ($v !== null && !preg_match(self::CODIGO, $v)) {
            $this->errores[] = "El campo <b>{$campo}</b> solo admite letras, números y guiones, sin espacios (recibido: '" . self::esc($v) . "').";
            return null;
        }
        return $v === null ? null : strtoupper($v);
    }

    // ---------------- datos de personas ----------------

    public function ci(mixed $valor): ?string
    {
        $v = $this->texto($valor, 'ci', 20);
        if ($v !== null && !preg_match(self::CI, $v)) {
            $this->errores[] = "El <b>ci</b> '" . self::esc($v) . "' no es válido: 5 a 12 dígitos y complemento opcional (ej. 7654321 o 7654321-1A).";
            return null;
        }
        return $v === null ? null : strtoupper($v);
    }

    public function email(mixed $valor): ?string
    {
        $v = $this->texto($valor, 'email', 120);
        if ($v !== null && !preg_match(self::EMAIL, $v)) {
            $this->errores[] = "El <b>email</b> '" . self::esc($v) . "' no es válido (ej. nombre@gmail.com).";
            return null;
        }
        return $v === null ? null : strtolower($v);
    }

    public function telefono(mixed $valor): ?string
    {
        $v = str_replace(' ', '', self::limpiar($valor));
        if ($v === '') {
            return null;
        }
        if (!preg_match(self::TELEFONO, $v)) {
            $this->errores[] = "El <b>telefono</b> '" . self::esc($v) . "' no es válido: solo números, de 7 a 15 dígitos.";
            return null;
        }
        return $v;
    }

    /** Contrasena de acceso web: 8 a 72 caracteres, con letras y numeros. */
    public function password(mixed $valor, string $campo = 'contraseña'): ?string
    {
        $v = is_string($valor) ? $valor : '';
        if ($v === '') {
            $this->errores[] = "La <b>{$campo}</b> es obligatoria.";
            return null;
        }
        if (strlen($v) < 8 || strlen($v) > 72) {
            $this->errores[] = "La <b>{$campo}</b> debe tener entre 8 y 72 caracteres.";
            return null;
        }
        if (!preg_match('/[A-Za-z]/', $v) || !preg_match('/\d/', $v)) {
            $this->errores[] = "La <b>{$campo}</b> debe combinar letras y números.";
            return null;
        }
        return $v;
    }

    // ---------------- numeros, opciones, fechas ----------------

    public function entero(mixed $valor, string $campo, int $minimo, int $maximo): ?int
    {
        $v = $this->texto($valor, $campo, 10);
        if ($v === null) {
            return null;
        }
        if (!preg_match('/^-?\d{1,9}$/', $v)) {
            $this->errores[] = "El campo <b>{$campo}</b> debe ser un número entero (recibido: '" . self::esc($v) . "').";
            return null;
        }
        $n = (int) $v;
        if ($n < $minimo || $n > $maximo) {
            $this->errores[] = "El campo <b>{$campo}</b> debe estar entre {$minimo} y {$maximo} (recibido: {$n}).";
            return null;
        }
        return $n;
    }

    /** Una de las opciones permitidas (no distingue mayusculas). Devuelve la opcion en mayusculas. */
    /** Valor de una lista (sin distinguir mayusculas); devuelve el valor tal como esta en la lista. */
    public function deLista(mixed $valor, string $campo, array $lista): ?string
    {
        $v = $this->texto($valor, $campo, 150);
        if ($v === null) {
            return null;
        }
        foreach ($lista as $ok) {
            if (strcasecmp($ok, $v) === 0) {
                return $ok;
            }
        }
        $this->errores[] = "El campo <b>{$campo}</b> debe ser uno de: " . implode(', ', $lista) . '.';
        return null;
    }

    public function opcion(mixed $valor, string $campo, array $opciones): ?string
    {
        $v = $this->texto($valor, $campo, 40);
        if ($v === null) {
            return null;
        }
        $mayus = str_replace(' ', '_', mb_strtoupper($v));
        if (in_array($mayus, $opciones, true)) {
            return $mayus;
        }
        $this->errores[] = "El campo <b>{$campo}</b> debe ser uno de: " . implode(', ', $opciones) . " (recibido: '" . self::esc($v) . "').";
        return null;
    }

    /** Fecha AAAA-MM-DD. */
    public function fecha(mixed $valor, string $campo): ?string
    {
        $v = $this->texto($valor, $campo, 10);
        if ($v === null) {
            return null;
        }
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        if (!$d || $d->format('Y-m-d') !== $v) {
            $this->errores[] = "El campo <b>{$campo}</b> debe ser una fecha válida AAAA-MM-DD (recibido: '" . self::esc($v) . "').";
            return null;
        }
        return $v;
    }

    /** Hora HH:MM o HH:MM:SS (acepta 8:30). Devuelve HH:MM:SS. */
    public function hora(mixed $valor, string $campo): ?string
    {
        $v = $this->texto($valor, $campo, 8);
        if ($v === null) {
            return null;
        }
        if (preg_match('/^\d:\d{2}(:\d{2})?$/', $v)) {
            $v = '0' . $v;
        }
        if (!preg_match('/^([01]\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', $v, $m)) {
            $this->errores[] = "El campo <b>{$campo}</b> debe ser una hora válida HH:MM (recibido: '" . self::esc($v) . "').";
            return null;
        }
        return sprintf('%s:%s:%s', $m[1], $m[2], $m[3] ?? '00');
    }

    /** Como hora(), pero acepta 24:00 (fin del dia), que se guarda como 23:59:59. */
    public function horaFin(mixed $valor, string $campo): ?string
    {
        if (preg_match('/^24:00(:00)?$/', self::limpiar($valor))) {
            return self::FIN_DEL_DIA;
        }
        return $this->hora($valor, $campo);
    }

    // ---------------- utilidades de hora ----------------

    /** "20:30:15" -> segundos desde medianoche. */
    public static function segundos(string $hora): int
    {
        [$h, $m, $s] = array_map('intval', explode(':', $hora) + [0, 0, 0]);
        return $h * 3600 + $m * 60 + $s;
    }

    /** segundos desde medianoche -> "HH:MM:SS" (se recorta al dia). */
    public static function aHora(int $segundos): string
    {
        $segundos = max(0, min($segundos, 86399));
        return sprintf('%02d:%02d:%02d', intdiv($segundos, 3600), intdiv($segundos % 3600, 60), $segundos % 60);
    }

    /** "20:00:00" -> "20:00"; "23:59:59" -> "24:00"; "20:00:30" se deja igual. */
    public static function corta(string $hora): string
    {
        if ($hora === self::FIN_DEL_DIA) {
            return '24:00';
        }
        return strlen($hora) === 8 && str_ends_with($hora, ':00') ? substr($hora, 0, 5) : $hora;
    }
}
