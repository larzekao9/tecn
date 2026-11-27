<?php

namespace Database\Seeders;

use App\Models\Usuario;
use App\Support\Bd;
use App\Support\Catalogo;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Se ejecuta en cada arranque (es idempotente):
 *  1) carga los recursos (pantallas del menu dinamico);
 *  2) crea el permiso de cada rol en cada recurso (ver, crear, editar, eliminar) con los valores por defecto del Catalogo;
 *  3) crea las cuentas definidas en el .env (ADMINISTRADORES, CUENTAS_ENCARGADO, CUENTAS_ANUNCIANTE) si no existen;
 *  4) si SEMBRAR_DEMO no es false y no existen, crea los datos de demostracion (DemoSeeder).
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->recursos();
        $this->permisos();

        foreach (['ADMINISTRADOR' => config('canal11.administradores'), 'ENCARGADO' => config('canal11.cuentas_encargado'),
                     'ANUNCIANTE' => config('canal11.cuentas_anunciante')] as $rol => $cuentas) {
            foreach ($cuentas as $a) {
                if (!Bd::existe('SELECT 1 FROM usuario WHERE lower(email) = lower(?) OR ci = ?', [$a['email'], $a['ci']])) {
                    Bd::ejecutar('INSERT INTO usuario (id_rol, ci, nombre, apellido, email, contrasena) VALUES (?, ?, ?, ?, ?, ?)',
                        [Usuario::idRol($rol), $a['ci'], $a['nombre'], $a['apellido'], $a['email'], Hash::make($a['password'])]);
                }
            }
        }

        if (filter_var(env('SEMBRAR_DEMO', true), FILTER_VALIDATE_BOOL)) {
            $this->call(DemoSeeder::class);
        }
    }

    /**
     * Un permiso por rol y recurso con sus cuatro acciones. Los permisos que ya existen no se tocan (se editan en la
     * Matriz de acceso), salvo los del Administrador, que siempre tiene todo (menos Mis pautas y Facturas, que son del anunciante).
     */
    private function permisos(): void
    {
        $defecto = Catalogo::permisosPorDefecto();
        $acciones = Catalogo::accionesPorRecurso();
        foreach (Bd::filas('SELECT id_rol, nombre FROM rol') as $rol) {
            foreach (Bd::filas('SELECT id_recurso, ruta FROM recurso') as $re) {
                $esAdmin = $rol['nombre'] === 'ADMINISTRADOR';
                $f = [];
                foreach (Catalogo::ACCIONES as $a) {
                    $f[$a] = $esAdmin ? (!in_array($re['ruta'], Catalogo::SOLO_ANUNCIANTE, true) && in_array($a, $acciones[$re['ruta']] ?? ['ver'], true))
                        : ($defecto[$rol['nombre']][$re['ruta']][$a] ?? false);
                }
                Bd::ejecutar('INSERT INTO permiso (id_rol, id_recurso, ver, crear, editar, eliminar) VALUES (?, ?, ?, ?, ?, ?)'
                    . ' ON CONFLICT (id_rol, id_recurso) DO ' . ($esAdmin
                        ? 'UPDATE SET ver = EXCLUDED.ver, crear = EXCLUDED.crear, editar = EXCLUDED.editar, eliminar = EXCLUDED.eliminar'
                        : 'NOTHING'),
                    [$rol['id_rol'], $re['id_recurso'], $f['ver'], $f['crear'], $f['editar'], $f['eliminar']]);
            }
        }
        Catalogo::olvidarMatriz();
    }

    private function recursos(): void
    {
        $items = [
            // nombre, ruta; el menu sale en este orden
            ['Inicio', '/'],
            ['Notificaciones', '/notificaciones'],
            ['Mis pautas', '/mis-pautas'],
            ['Facturas', '/facturas'],
            ['Parrillas', '/parrillas'],
            ['Programas', '/programas'],
            ['Materiales', '/materiales'],
            ['Validar emisión', '/validacion'],
            ['Conciliación', '/emision'],
            ['Pautas', '/pautas'],
            ['Espacios publicitarios', '/espacios'],
            ['Estadísticas', '/estadisticas'],
            ['Reportes', '/reportes'],
            ['Usuarios', '/usuarios'],
            ['Matriz de acceso', '/roles'],
            ['Bitácora', '/bitacora'],
        ];
        foreach ($items as [$nombre, $ruta]) {
            Bd::ejecutar('INSERT INTO recurso (nombre, ruta) VALUES (?, ?) ON CONFLICT (ruta) DO UPDATE SET nombre = EXCLUDED.nombre',
                [$nombre, $ruta]);
        }
        Bd::ejecutar('DELETE FROM recurso WHERE ruta <> ALL(?)', ['{' . implode(',', array_column($items, 1)) . '}']);
    }
}
