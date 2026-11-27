<?php

namespace App\Services;

use App\Models\Usuario;
use App\Support\Bd;
use App\Support\NegocioException;
use App\Support\Validador;
use Illuminate\Support\Facades\Hash;

/**
 * CU1 - Gestion de Usuarios. Reglas de negocio (iguales al sistema por correo):
 *  - Solo el Administrador gestiona usuarios (lo controla el middleware de permisos).
 *  - Un usuario se referencia por su CI, su email o su ID.
 *  - CI y email son unicos; nombre y apellido solo letras. Rol: ADMINISTRADOR, ENCARGADO, PROGRAMADOR o ANUNCIANTE.
 *  - eliUsu es baja logica (activo = false) y cierra sus sesiones; actUsu lo reactiva.
 *  - No se puede eliminar a uno mismo, y el sistema nunca se queda sin un Administrador activo.
 *  - Web: cada usuario tiene contrasena (bcrypt). Si el Administrador no la indica, la inicial es el CI
 *    y se pide cambiarla en el primer ingreso.
 *  - Cada alta, modificacion y baja se registra en la bitacora.
 */
class UsuarioService
{
    private const SELECT = "SELECT u.id_usuario AS id, u.ci, u.nombre, u.apellido, u.email, coalesce(u.telefono, '') AS telefono, r.nombre AS rol, u.activo"
        . ' FROM usuario u JOIN rol r ON r.id_rol = u.id_rol';

    public function __construct(private BitacoraService $bitacora)
    {
    }

    /** lisUsu: "*" = todos; un rol = por rol; otro texto = busca por nombre, apellido o email. */
    public function listar(Usuario $quien, array $p): array
    {
        $v = new Validador();
        $f = $v->texto($p[0] ?? '*', 'filtro', 60);
        $v->verificar();
        if ($f === '*') {
            $datos = Bd::filas(self::SELECT . ' ORDER BY u.id_usuario');
        } elseif (in_array(strtoupper($f), Usuario::ROLES, true)) {
            $datos = Bd::filas(self::SELECT . ' WHERE r.nombre = ? ORDER BY u.id_usuario', [strtoupper($f)]);
        } else {
            $like = "%{$f}%";
            $datos = Bd::filas(self::SELECT . ' WHERE u.nombre ILIKE ? OR u.apellido ILIKE ? OR u.email ILIKE ? OR u.ci ILIKE ? ORDER BY u.id_usuario',
                [$like, $like, $like, $like]);
        }
        return ['titulo' => 'Usuarios', 'datos' => $datos];
    }

    public function mostrar(Usuario $quien, array $p): array
    {
        return ['titulo' => 'Usuario', 'datos' => $this->obtener($this->resolver($p[0] ?? null))];
    }

    /** insUsu["ci","nombre","apellido","email","telefono","rol","contrasena"]. */
    public function insertar(Usuario $quien, array $p): array
    {
        $v = new Validador();
        $ci = $v->ci($p[0] ?? null);
        $nombre = $v->nombre($p[1] ?? null, 'nombre');
        $apellido = $v->nombre($p[2] ?? null, 'apellido');
        $email = $v->email($p[3] ?? null);
        $telefono = $v->telefono($p[4] ?? null);
        $rol = $v->opcion($p[5] ?? null, 'rol', Usuario::ROLES);
        $password = $v->password($p[6] ?? null);
        $v->verificar();

        $this->validarUnicos($v, $ci, $email, 0);
        $v->verificar();

        $id = Bd::insertar(
            'INSERT INTO usuario (id_rol, ci, nombre, apellido, email, telefono, contrasena) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [Usuario::idRol($rol), $ci, $nombre, $apellido, $email, $telefono, Hash::make($password)],
            'id_usuario'
        );
        $this->bitacora->accion($quien, BitacoraService::INSERTAR, 'usuario');
        $mensaje = "Usuario <b>" . Validador::esc("{$nombre} {$apellido}") . '</b> registrado como <b>' . Usuario::NOMBRES_ROL[$rol] . '</b>.';
        return ['titulo' => 'Usuario registrado', 'mensaje' => $mensaje, 'datos' => $this->obtener($id)];
    }

    /** modUsu["usuario (CI o email)","ci","nombre","apellido","email","telefono","rol"]. */
    public function modificar(Usuario $quien, array $p): array
    {
        $id = $this->resolver($p[0] ?? null);
        $v = new Validador();
        $ci = $v->ci($p[1] ?? null);
        $nombre = $v->nombre($p[2] ?? null, 'nombre');
        $apellido = $v->nombre($p[3] ?? null, 'apellido');
        $email = $v->email($p[4] ?? null);
        $telefono = $v->telefono($p[5] ?? null);
        $rol = $v->opcion($p[6] ?? null, 'rol', Usuario::ROLES);
        $v->verificar();

        $actual = $this->obtener($id);
        if ($actual['rol'] === 'ADMINISTRADOR' && $actual['activo'] && $rol !== 'ADMINISTRADOR' && $this->adminsActivos() <= 1) {
            $v->error('No se puede cambiar el rol del único Administrador activo; primero registre otro Administrador.');
        }
        $this->validarUnicos($v, $ci, $email, $id);
        $v->verificar();

        Bd::ejecutar('UPDATE usuario SET ci = ?, nombre = ?, apellido = ?, email = ?, telefono = ?, id_rol = ? WHERE id_usuario = ?',
            [$ci, $nombre, $apellido, $email, $telefono, Usuario::idRol($rol), $id]);
        $this->bitacora->accion($quien, BitacoraService::MODIFICAR, 'usuario');
        return ['titulo' => 'Usuario modificado', 'mensaje' => 'Los datos del usuario fueron actualizados.', 'datos' => $this->obtener($id)];
    }

    /** eliUsu["usuario"]: baja logica; cierra sus sesiones abiertas. */
    public function eliminar(Usuario $quien, array $p): array
    {
        $id = $this->resolver($p[0] ?? null);
        $actual = $this->obtener($id);
        $nombre = Validador::esc("{$actual['nombre']} {$actual['apellido']}");
        if (!$actual['activo']) {
            throw new NegocioException("El usuario <b>{$nombre}</b> ya está eliminado.");
        }
        if ($id === (int) $quien->id_usuario) {
            throw new NegocioException('No puede darse de baja a sí mismo.');
        }
        if ($actual['rol'] === 'ADMINISTRADOR' && $this->adminsActivos() <= 1) {
            throw new NegocioException('No se puede eliminar al único Administrador activo.');
        }
        Bd::ejecutar('UPDATE usuario SET activo = false WHERE id_usuario = ?', [$id]);
        Bd::ejecutar("DELETE FROM personal_access_tokens WHERE tokenable_type = ? AND tokenable_id = ?", [Usuario::class, $id]);
        $this->bitacora->accion($quien, BitacoraService::ELIMINAR, 'usuario');
        return ['titulo' => 'Usuario eliminado', 'mensaje' => "El usuario <b>{$nombre}</b> fue eliminado y ya no puede ingresar.",
            'datos' => $this->obtener($id)];
    }

    // ------------------------------------------------------------------ auxiliares

    public function obtener(int $id): array
    {
        $f = Bd::fila(self::SELECT . ' WHERE u.id_usuario = ?', [$id]);
        if ($f === null) {
            throw new NegocioException('El usuario no existe.', 404);
        }
        return $f;
    }

    /** Convierte la referencia (email, CI o ID) en el id del usuario. */
    public function resolver(mixed $referencia): int
    {
        $v = new Validador();
        $ref = $v->texto($referencia, 'usuario', 120);
        $v->verificar();
        $id = Bd::valor('SELECT id_usuario FROM usuario WHERE lower(email) = lower(?) OR upper(ci) = upper(?)', [$ref, $ref]);
        if ($id === null && preg_match('/^\d{1,9}$/', $ref)) {
            $id = Bd::valor('SELECT id_usuario FROM usuario WHERE id_usuario = ?', [(int) $ref]);
        }
        if ($id === null) {
            throw new NegocioException('No existe un usuario con CI, email o ID <b>' . Validador::esc($ref) . '</b>. Consulte la lista de usuarios.', 404);
        }
        return (int) $id;
    }

    private function adminsActivos(): int
    {
        return (int) Bd::valor("SELECT count(*) FROM usuario u JOIN rol r ON r.id_rol = u.id_rol WHERE r.nombre = 'ADMINISTRADOR' AND u.activo");
    }

    private function validarUnicos(Validador $v, ?string $ci, ?string $email, int $excluir): void
    {
        if (Bd::existe('SELECT 1 FROM usuario WHERE lower(ci) = lower(?) AND id_usuario <> ?', [$ci, $excluir])) {
            $v->error('Ya existe otro usuario con el CI <b>' . Validador::esc($ci) . '</b>.');
        }
        if (Bd::existe('SELECT 1 FROM usuario WHERE lower(email) = lower(?) AND id_usuario <> ?', [$email, $excluir])) {
            $v->error('Ya existe otro usuario con el email <b>' . Validador::esc($email) . '</b>.');
        }
    }
}
