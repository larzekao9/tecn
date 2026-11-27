<?php

namespace App\Services;

use App\Models\Usuario;
use App\Support\Bd;
use App\Support\Validador;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Bitacora: unico registro de accesos y acciones del sistema (tabla bitacora, solo insercion).
 *  - accion: LOGIN, LOGIN_FALLIDO, LOGOUT (Autenticacion), ACCESO (cada peticion a un recurso de la API, tambien los
 *    accesos denegados), ACCION (cada registro, modificacion o eliminacion de datos) y ERROR (errores del sistema).
 *  - recurso: el modulo o dato afectado; detalle: que paso; ip: desde donde.
 * Si la bitacora falla NO se cancela la operacion del usuario: solo se deja constancia en el log.
 */
class BitacoraService
{
    public const ACCIONES = ['LOGIN', 'LOGIN_FALLIDO', 'LOGOUT', 'ACCESO', 'ACCION', 'ERROR'];

    // verbos de las acciones sobre los datos (ACCION)
    public const INSERTAR = 'Registrar';
    public const MODIFICAR = 'Modificar';
    public const ELIMINAR = 'Eliminar';
    public const PUBLICAR = 'Publicar';
    public const VALIDAR = 'Validar';
    public const PAGAR = 'Pagar';
    public const ANULAR = 'Anular';

    private static ?array $recursos = null;

    public function registrar(string $accion, ?Request $r, ?int $idUsuario = null, ?string $recurso = null, ?string $detalle = null): void
    {
        try {
            Bd::ejecutar('INSERT INTO bitacora (id_usuario, accion, recurso, detalle, ip) VALUES (?, ?, ?, ?, ?)', [
                $idUsuario, $accion,
                $recurso === null ? null : mb_substr($recurso, 0, 60),
                $detalle === null || $detalle === '' ? null : mb_substr($detalle, 0, 500),
                $r?->ip(),
            ]);
        } catch (Throwable $e) {
            Log::warning('[Bitacora] ' . $e->getMessage());
        }
    }

    /** Accion sobre los datos (antes "auditoria"): p. ej. accion($quien, self::INSERTAR, 'programa') -> "Registrar programa". */
    public function accion(?Usuario $quien, string $verbo, string $entidad, ?string $extra = null): void
    {
        $r = app()->runningInConsole() ? null : request();
        $this->registrar('ACCION', $r, $quien?->id_usuario, ucfirst($entidad), "{$verbo} {$entidad}" . ($extra ? ": {$extra}" : ''));
    }

    /** Acceso a un recurso de la API: el recurso es el modulo del menu al que pertenece la ruta. */
    public function acceso(Request $r, ?int $idUsuario, int $estadoHttp, int $duracionMs): void
    {
        $this->registrar('ACCESO', $r, $idUsuario, $this->recursoDe($r->path()),
            $r->method() . ' /' . $r->path() . " -> {$estadoHttp} ({$duracionMs} ms)");
    }

    /** "api/parrillas/2026-11-20/bloques" -> "Parrillas" (nombre de la tabla recurso; si no, el segmento). */
    private function recursoDe(string $path): string
    {
        $seg = explode('/', preg_replace('#^api/#', '', $path))[0] ?? '';
        if ($seg === 'auth') {
            return 'Autenticación';
        }
        if (self::$recursos === null) {
            self::$recursos = [];
            try {
                foreach (Bd::filas('SELECT nombre, ruta FROM recurso') as $f) {
                    self::$recursos[ltrim($f['ruta'], '/')] = $f['nombre'];
                }
            } catch (Throwable) {
            }
        }
        return self::$recursos[$seg] ?? ucfirst(str_replace('-', ' ', $seg));
    }

    /** lisBit[accion|*, desde?, hasta?, texto?]. */
    public function listar(Usuario $quien, array $p): array
    {
        $v = new Validador();
        $accion = strtoupper(Validador::limpiar($p[0] ?? '*')) ?: '*';
        $desde = ($p[1] ?? '') === '' ? null : $v->fecha($p[1], 'desde');
        $hasta = ($p[2] ?? '') === '' ? null : $v->fecha($p[2], 'hasta');
        $texto = Validador::limpiar($p[3] ?? '');
        if ($accion !== '*' && !in_array($accion, self::ACCIONES, true)) {
            $v->error('La acción debe ser * o una de: ' . implode(', ', self::ACCIONES) . '.');
        }
        $v->verificar();

        $sql = "SELECT b.id_bitacora AS id, to_char(b.fecha, 'YYYY-MM-DD HH24:MI:SS') AS fecha, b.accion,"
            . " coalesce(u.nombre || ' ' || u.apellido, '') AS usuario, coalesce(b.recurso, '') AS recurso,"
            . " coalesce(b.detalle, '') AS detalle, coalesce(b.ip, '') AS ip"
            . ' FROM bitacora b LEFT JOIN usuario u ON u.id_usuario = b.id_usuario WHERE true';
        $params = [];
        if ($accion !== '*') {
            $sql .= ' AND b.accion = ?';
            $params[] = $accion;
        }
        if ($desde) {
            $sql .= ' AND b.fecha >= ?::date';
            $params[] = $desde;
        }
        if ($hasta) {
            $sql .= ' AND b.fecha < ?::date + 1';
            $params[] = $hasta;
        }
        if ($texto !== '') {
            $sql .= " AND (b.detalle ILIKE ? OR b.recurso ILIKE ? OR u.nombre || ' ' || u.apellido ILIKE ?)";
            array_push($params, "%{$texto}%", "%{$texto}%", "%{$texto}%");
        }
        $sql .= ' ORDER BY b.fecha DESC, b.id_bitacora DESC LIMIT 500';

        return ['titulo' => 'Bitácora', 'datos' => ['registros' => Bd::filas($sql, $params)]];
    }
}
