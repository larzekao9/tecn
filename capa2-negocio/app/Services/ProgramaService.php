<?php

namespace App\Services;

use App\Models\Usuario;
use App\Support\Bd;
use App\Support\NegocioException;
use App\Support\Validador;

/**
 * CU2 - Catalogo de Programas. Reglas de negocio:
 *  - El nombre es unico (sin distinguir mayusculas). Un programa se referencia por su nombre (o ID).
 *  - Tipo: NOTICIERO, PELICULA, SERIE u OTRO.
 *  - eliPro borra el programa y NO se permite si tiene materiales o bloques en alguna parrilla.
 */
class ProgramaService
{
    public const TIPOS = ['NOTICIERO', 'PELICULA', 'SERIE', 'OTRO'];

    private const SELECT = 'SELECT id_programa AS id, nombre, tipo FROM programa';

    public function __construct(private BitacoraService $bitacora)
    {
    }

    /** lisPro: "*" = todos; un tipo; otro texto = busca en el nombre. */
    public function listar(Usuario $quien, array $p): array
    {
        $v = new Validador();
        $f = $v->texto($p[0] ?? '*', 'filtro', 60);
        $v->verificar();
        if ($f === '*') {
            $datos = Bd::filas(self::SELECT . ' ORDER BY nombre');
        } elseif (in_array(strtoupper($f), self::TIPOS, true)) {
            $datos = Bd::filas(self::SELECT . ' WHERE tipo = ? ORDER BY nombre', [strtoupper($f)]);
        } else {
            $like = "%{$f}%";
            $datos = Bd::filas(self::SELECT . ' WHERE nombre ILIKE ? ORDER BY nombre', [$like]);
        }
        return ['titulo' => 'Programas', 'datos' => $datos];
    }

    public function mostrar(Usuario $quien, array $p): array
    {
        return ['titulo' => 'Programa', 'datos' => $this->obtener($this->resolver($p[0] ?? null))];
    }

    /** insPro["nombre","tipo"]. */
    public function insertar(Usuario $quien, array $p): array
    {
        $v = new Validador();
        $nombre = $v->texto($p[0] ?? null, 'nombre', 120);
        $tipo = $v->opcion($p[1] ?? null, 'tipo', self::TIPOS);
        $v->verificar();

        $this->validarNombreUnico($v, $nombre, 0);
        $v->verificar();

        $id = Bd::insertar('INSERT INTO programa (nombre, tipo) VALUES (?, ?)', [$nombre, $tipo], 'id_programa');
        $this->bitacora->accion($quien, BitacoraService::INSERTAR, 'programa');
        return ['titulo' => 'Programa registrado', 'mensaje' => 'El programa <b>' . Validador::esc($nombre) . '</b> fue registrado.',
            'datos' => $this->obtener($id)];
    }

    /** modPro["programa","nombre","tipo"]. */
    public function modificar(Usuario $quien, array $p): array
    {
        $id = $this->resolver($p[0] ?? null);
        $v = new Validador();
        $nombre = $v->texto($p[1] ?? null, 'nombre', 120);
        $tipo = $v->opcion($p[2] ?? null, 'tipo', self::TIPOS);
        $v->verificar();

        $this->validarNombreUnico($v, $nombre, $id);
        $v->verificar();

        $actual = $this->obtener($id);
        Bd::ejecutar('UPDATE programa SET nombre = ?, tipo = ? WHERE id_programa = ?', [$nombre, $tipo, $id]);
        $this->bitacora->accion($quien, BitacoraService::MODIFICAR, 'programa');
        return ['titulo' => 'Programa modificado', 'mensaje' => 'El programa fue actualizado.', 'datos' => $this->obtener($id)];
    }

    /** eliPro["programa"]. */
    public function eliminar(Usuario $quien, array $p): array
    {
        $id = $this->resolver($p[0] ?? null);
        $actual = $this->obtener($id);
        $nombre = Validador::esc($actual['nombre']);
        $v = new Validador();
        $materiales = (int) Bd::valor('SELECT count(*) FROM material WHERE id_programa = ?', [$id]);
        if ($materiales > 0) {
            $v->error("El programa <b>{$nombre}</b> tiene {$materiales} material(es) registrado(s). Elimínelos o cámbielos de programa primero.");
        }
        $bloques = (int) Bd::valor('SELECT count(*) FROM bloque WHERE id_programa = ?', [$id]);
        if ($bloques > 0) {
            $v->error("El programa <b>{$nombre}</b> está en {$bloques} bloque(s) de parrilla. Quítelo primero de la parrilla.");
        }
        $v->verificar();

        Bd::ejecutar('DELETE FROM programa WHERE id_programa = ?', [$id]);
        $this->bitacora->accion($quien, BitacoraService::ELIMINAR, 'programa');
        return ['titulo' => 'Programa eliminado', 'mensaje' => "El programa <b>{$nombre}</b> fue eliminado.", 'datos' => $actual];
    }

    // ------------------------------------------------------------------ auxiliares

    public function obtener(int $id): array
    {
        $f = Bd::fila(self::SELECT . ' WHERE id_programa = ?', [$id]);
        if ($f === null) {
            throw new NegocioException('El programa no existe.', 404);
        }
        return $f;
    }

    public function resolver(mixed $referencia): int
    {
        $v = new Validador();
        $ref = $v->texto($referencia, 'programa', 120);
        $v->verificar();
        $id = $this->buscarId($ref);
        if ($id === null) {
            throw new NegocioException('No existe un programa con el nombre <b>' . Validador::esc($ref) . '</b>. Consulte el catálogo de programas.', 404);
        }
        return $id;
    }

    public function buscarId(string $ref): ?int
    {
        $id = Bd::valor('SELECT id_programa FROM programa WHERE lower(nombre) = lower(?)', [$ref]);
        if ($id === null && preg_match('/^\d{1,9}$/', $ref)) {
            $id = Bd::valor('SELECT id_programa FROM programa WHERE id_programa = ?', [(int) $ref]);
        }
        return $id === null ? null : (int) $id;
    }

    private function validarNombreUnico(Validador $v, ?string $nombre, int $excluir): void
    {
        if (Bd::existe('SELECT 1 FROM programa WHERE lower(nombre) = lower(?) AND id_programa <> ?', [$nombre, $excluir])) {
            $v->error('Ya existe otro programa con el nombre <b>' . Validador::esc($nombre) . '</b>.');
        }
    }
}
