<?php

namespace App\Services;

use App\Models\Usuario;
use App\Support\Bd;
use App\Support\Catalogo;
use App\Support\Validador;

/** Buscador global: busca en lo que el rol del usuario puede consultar. */
class BusquedaService
{
    public function buscar(Usuario $u, string $q): array
    {
        $q = Validador::limpiar($q);
        if (mb_strlen($q) < 2) {
            return [];
        }
        $like = '%' . $q . '%';
        $r = [];
        if (Catalogo::permite('lisPro', $u->rol)) {
            foreach (Bd::filas('SELECT nombre, tipo FROM programa WHERE nombre ILIKE ? OR tipo ILIKE ? ORDER BY nombre LIMIT 8', [$like, $like]) as $f) {
                $r[] = ['tipo' => 'Programa', 'titulo' => $f['nombre'], 'detalle' => $f['tipo'], 'enlace' => '/programas?filtro=' . rawurlencode($f['nombre'])];
            }
        }
        if (Catalogo::permite('lisMat', $u->rol)) {
            foreach (Bd::filas('SELECT id_material AS id, titulo, tipo FROM material WHERE titulo ILIKE ? OR id_material::text = ? ORDER BY titulo LIMIT 8', [$like, $q]) as $f) {
                $r[] = ['tipo' => 'Material', 'titulo' => "#{$f['id']} · {$f['titulo']}", 'detalle' => $f['tipo'], 'enlace' => "/materiales/{$f['id']}"];
            }
        }
        if (Catalogo::permite('lisPar', $u->rol)) {
            foreach (Bd::filas("SELECT to_char(fecha, 'YYYY-MM-DD') AS fecha, estado FROM parrilla WHERE to_char(fecha, 'YYYY-MM-DD') LIKE ? ORDER BY fecha DESC LIMIT 5", [$like]) as $f) {
                $r[] = ['tipo' => 'Parrilla', 'titulo' => "Parrilla del {$f['fecha']}", 'detalle' => $f['estado'], 'enlace' => "/parrillas/{$f['fecha']}"];
            }
        }
        if (Catalogo::permite('lisPau', $u->rol)) {
            foreach (Bd::filas("SELECT pa.nombre, u.nombre || ' ' || u.apellido AS anunciante FROM pauta pa JOIN usuario u ON u.id_usuario = pa.id_usuario"
                . " WHERE pa.estado IN ('APROBADA', 'CANCELADA') AND (pa.nombre ILIKE ? OR u.nombre || ' ' || u.apellido ILIKE ?) ORDER BY pa.nombre LIMIT 8", [$like, $like]) as $f) {
                $r[] = ['tipo' => 'Pauta', 'titulo' => $f['nombre'], 'detalle' => $f['anunciante'], 'enlace' => '/pautas/' . rawurlencode($f['nombre'])];
            }
        }
        if (Catalogo::permite('lisUsu', $u->rol)) {
            foreach (Bd::filas("SELECT u.nombre || ' ' || u.apellido AS nombre, u.email, r.nombre AS rol FROM usuario u JOIN rol r ON r.id_rol = u.id_rol"
                . ' WHERE u.nombre ILIKE ? OR u.apellido ILIKE ? OR u.email ILIKE ? OR u.ci ILIKE ? ORDER BY u.nombre LIMIT 8',
                [$like, $like, $like, $like]) as $f) {
                $r[] = ['tipo' => 'Usuario', 'titulo' => $f['nombre'], 'detalle' => "{$f['email']} · {$f['rol']}", 'enlace' => '/usuarios?filtro=' . rawurlencode($f['email'])];
            }
        }
        return $r;
    }
}
