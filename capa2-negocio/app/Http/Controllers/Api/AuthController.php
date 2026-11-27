<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use App\Services\BitacoraService;
use App\Services\NotificacionService;
use App\Services\RolService;
use App\Support\Bd;
use App\Support\Catalogo;
use App\Support\NegocioException;
use App\Support\Reloj;
use App\Support\Validador;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Inicio y cierre de sesion y preferencias de tema/accesibilidad.
 * Seguridad: contrasenas bcrypt, tokens Sanctum con vencimiento, limite de peticiones por IP
 * y registro en la bitacora (LOGIN, LOGIN_FALLIDO, LOGOUT).
 */
class AuthController extends Controller
{
    public function __construct(private BitacoraService $bitacora, private RolService $roles, private NotificacionService $avisos)
    {
    }

    public function login(Request $request): JsonResponse
    {
        $v = new Validador();
        $email = $v->email($request->input('email'));
        $password = (string) $request->input('password', '');
        if ($password === '') {
            $v->error('La <b>contraseña</b> es obligatoria.');
        }
        $v->verificar();

        $u = Usuario::whereRaw('lower(email) = lower(?)', [$email])->first();

        if ($u === null) {
            $this->bitacora->registrar('LOGIN_FALLIDO', $request, null, 'Autenticación', "Login fallido: correo no registrado ({$email})");
            throw new NegocioException('Correo o contraseña incorrectos.', 401);
        }
        if (!$u->contrasena || !Hash::check($password, $u->contrasena)) {
            $this->bitacora->registrar('LOGIN_FALLIDO', $request, $u->id_usuario, 'Autenticación', 'Login fallido: contraseña incorrecta');
            throw new NegocioException('Correo o contraseña incorrectos.', 401);
        }
        if (!$u->activo) {
            $this->bitacora->registrar('LOGIN_FALLIDO', $request, $u->id_usuario, 'Autenticación', 'Login fallido: usuario eliminado');
            throw new NegocioException('Su usuario está eliminado. Comuníquese con el Administrador.', 403);
        }

        $token = $u->createToken('web', ['*'], now()->addHours((int) config('canal11.login.token_horas')))->plainTextToken;
        $this->bitacora->registrar('LOGIN', $request, $u->id_usuario, 'Autenticación', 'Login');
        return response()->json(['ok' => true, 'token' => $token, 'usuario' => $this->perfil($u->fresh())]);
    }

    public function logout(Request $request): JsonResponse
    {
        $u = $request->user();
        $u->currentAccessToken()?->delete();
        $this->bitacora->registrar('LOGOUT', $request, $u->id_usuario, 'Autenticación', 'Logout');
        return response()->json(['ok' => true, 'mensaje' => 'Sesión cerrada.']);
    }

    public function yo(Request $request): JsonResponse
    {
        return response()->json(['ok' => true, 'usuario' => $this->perfil($request->user())]);
    }

    public function preferencias(Request $request): JsonResponse
    {
        $u = $request->user();
        $actual = $u->preferencias();
        $v = new Validador();
        $tema = $v->opcion($request->input('tema', $actual['tema']), 'tema', ['NINOS', 'JOVENES', 'ADULTOS']);
        $modo = $v->opcion($request->input('modo', $actual['modo']), 'modo', ['DIA', 'NOCHE', 'AUTO']);
        $escala = $v->entero((string) $request->input('escala_fuente', $actual['escala_fuente']), 'tamaño de letra', 80, 160);
        $contraste = filter_var($request->input('alto_contraste', $actual['alto_contraste']), FILTER_VALIDATE_BOOL);
        $v->verificar();
        // usuario configura preferencia_usuario (1 a 1)
        Bd::ejecutar('INSERT INTO preferencia_usuario (id_usuario, tema, modo, escala_fuente, alto_contraste) VALUES (?, ?, ?, ?, ?)'
            . ' ON CONFLICT (id_usuario) DO UPDATE SET tema = EXCLUDED.tema, modo = EXCLUDED.modo, escala_fuente = EXCLUDED.escala_fuente,'
            . ' alto_contraste = EXCLUDED.alto_contraste', [$u->id_usuario, $tema, $modo, $escala, $contraste]);
        return response()->json(['ok' => true, 'usuario' => $this->perfil($u->fresh())]);
    }

    private function perfil(Usuario $u): array
    {
        return [
            'id' => $u->id_usuario,
            'ci' => $u->ci,
            'nombre' => $u->nombre,
            'apellido' => $u->apellido,
            'nombre_completo' => $u->nombreCompleto(),
            'email' => $u->email,
            'rol' => $u->rol,
            'rol_nombre' => $u->nombreRol(),
            'preferencias' => $u->preferencias(),
            'permisos' => $this->roles->permisosDe($u->rol),
            'menu' => $this->roles->menu($u),
            'notificaciones_sin_leer' => $this->avisos->noLeidas($u),
            'zona' => Reloj::zona(),
            'casos_uso' => Catalogo::casosUso(),
        ];
    }
}
