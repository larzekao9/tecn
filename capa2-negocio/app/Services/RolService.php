<?php

namespace App\Services;

use App\Models\Usuario;
use App\Support\Bd;
use App\Support\Catalogo;
use App\Support\NegocioException;
use App\Support\Validador;

/**
 * Matriz de control de acceso (RBAC) guardada en la BD: rol agrupa permiso y permiso autoriza recurso.
 * Cada permiso es lo que un rol puede hacer en una pantalla (recurso): ver, crear, editar y eliminar.
 *  - El ADMINISTRADOR siempre tiene todos los permisos (no editable): el sistema nunca queda sin quien lo administre.
 *  - Los demas roles se editan marcando o quitando acciones; el cambio rige desde la siguiente peticion.
 *  - El menu de cada usuario son los recursos donde su rol tiene "ver".
 */
class RolService
{
    public function __construct(private BitacoraService $bitacora)
    {
    }

    /** lisRol: roles, recursos (con las acciones que existen en cada uno) y la matriz rol -> recurso -> acciones. */
    public function listar(Usuario $quien, array $p): array
    {
        $roles = array_map(fn ($r) => [
            'codigo' => $r['codigo'], 'nombre' => Usuario::NOMBRES_ROL[$r['codigo']] ?? $r['codigo'],
            'editable' => (bool) $r['editable'], 'usuarios' => (int) $r['usuarios'],
        ], Bd::filas("SELECT r.nombre AS codigo, r.nombre <> 'ADMINISTRADOR' AS editable,"
            . ' (SELECT count(*) FROM usuario u WHERE u.id_rol = r.id_rol AND u.activo) AS usuarios'
            . " FROM rol r ORDER BY r.nombre <> 'ADMINISTRADOR', r.id_rol"));
        $acciones = Catalogo::accionesPorRecurso();
        $recursos = array_map(fn ($r) => $r + ['acciones' => $acciones[$r['ruta']] ?? ['ver'], 'paquete' => $this->paqueteDe($r['ruta'])],
            Bd::filas('SELECT re.id_recurso AS id, re.nombre, re.ruta FROM recurso re ORDER BY re.id_recurso'));
        $matriz = Catalogo::matrizBd();
        return ['titulo' => 'Matriz de acceso', 'datos' => ['roles' => $roles, 'recursos' => $recursos, 'matriz' => $matriz]];
    }

    /** modRol["rol","recurso (ruta)","accion","SI|NO"]. */
    public function asignar(Usuario $quien, array $p): array
    {
        $v = new Validador();
        $rol = $v->opcion($p[0] ?? null, 'rol', Usuario::ROLES);
        $ruta = $v->texto($p[1] ?? null, 'recurso', 120);
        $accion = $v->opcion(strtoupper((string) ($p[2] ?? '')), 'accion', ['VER', 'CREAR', 'EDITAR', 'ELIMINAR']);
        $conceder = $v->opcion($p[3] ?? null, 'concedido', ['SI', 'NO']);
        $v->verificar();
        if ($rol === 'ADMINISTRADOR') {
            throw new NegocioException("El rol <b>{$rol}</b> no es editable: el Administrador siempre tiene todos los permisos.");
        }
        $ruta = '/' . ltrim($ruta, '/');
        $recurso = Bd::fila('SELECT id_recurso, nombre FROM recurso WHERE ruta = ?', [$ruta]);
        if ($recurso === null) {
            throw new NegocioException('No existe el recurso <b>' . Validador::esc($ruta) . '</b>.');
        }
        $columna = strtolower($accion); // ver, crear, editar o eliminar (valor validado contra la lista)
        if (!in_array($columna, Catalogo::accionesPorRecurso()[$ruta] ?? ['ver'], true)) {
            throw new NegocioException("En <b>{$recurso['nombre']}</b> no existe la acción <b>{$columna}</b>.");
        }
        $idRol = Usuario::idRol($rol);
        $valor = $conceder === 'SI';
        Bd::ejecutar("INSERT INTO permiso (id_rol, id_recurso, {$columna}) VALUES (?, ?, ?)"
            . " ON CONFLICT (id_rol, id_recurso) DO UPDATE SET {$columna} = EXCLUDED.{$columna}", [$idRol, $recurso['id_recurso'], $valor]);
        // sin "ver" no se puede hacer nada mas en la pantalla: al quitar ver se quitan las demas acciones
        if ($columna === 'ver' && !$valor) {
            Bd::ejecutar('UPDATE permiso SET crear = false, editar = false, eliminar = false WHERE id_rol = ? AND id_recurso = ?',
                [$idRol, $recurso['id_recurso']]);
        }
        if ($columna !== 'ver' && $valor) {
            Bd::ejecutar('UPDATE permiso SET ver = true WHERE id_rol = ? AND id_recurso = ?', [$idRol, $recurso['id_recurso']]);
        }
        Catalogo::olvidarMatriz();
        $this->bitacora->accion($quien, BitacoraService::MODIFICAR, 'permiso');
        return ['titulo' => 'Permiso actualizado',
            'mensaje' => ($valor ? 'Se concedió' : 'Se quitó') . " <b>{$columna}</b> en <b>{$recurso['nombre']}</b> " . ($valor ? 'al' : 'del') . " rol <b>{$rol}</b>.",
            'datos' => $this->listar($quien, [])['datos']];
    }

    /**
     * Menu dinamico del usuario: los recursos donde su rol tiene "ver".
     * Cada opcion lleva su paquete (Gestion Operativa, Gestion Comercial, Reportes y Estadisticas), que sale del caso de
     * uso de los comandos de esa pantalla (Catalogo) y de config canal11.paquetes. Inicio y Notificaciones no tienen paquete.
     */
    public function menu(Usuario $u): array
    {
        $filas = Bd::filas('SELECT re.nombre AS titulo, re.ruta FROM recurso re JOIN permiso p ON p.id_recurso = re.id_recurso'
            . ' WHERE p.id_rol = ? AND p.ver ORDER BY re.id_recurso', [$u->id_rol]);
        return array_map(fn (array $f) => $f + ['paquete' => $this->paqueteDe($f['ruta'])], $filas);
    }

    /** Comandos que el rol puede usar, para que el frontend muestre u oculte botones. */
    public function permisosDe(string $rol): array
    {
        $r = [];
        foreach (Catalogo::comandos() as $c) {
            if (Catalogo::permite($c['nombre'], $rol)) {
                $r[] = $c['nombre'];
            }
        }
        sort($r);
        return $r;
    }

    private function paqueteDe(string $ruta): ?string
    {
        $cu = null;
        foreach (Catalogo::comandos() as $c) {
            if (in_array($ruta, Catalogo::recursosDe($c['nombre']), true) && $c['nombre'] !== 'lisSol') {
                $cu = $c['cu'];
                break;
            }
        }
        if ($cu === null) {
            return null;
        }
        foreach (config('canal11.paquetes', []) as $paquete => $cus) {
            if (in_array($cu, array_map('intval', $cus), true)) {
                return $paquete;
            }
        }
        return null;
    }
}
