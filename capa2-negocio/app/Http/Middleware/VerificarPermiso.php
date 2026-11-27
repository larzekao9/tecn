<?php

namespace App\Http\Middleware;

use App\Services\BitacoraService;
use App\Support\Catalogo;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Control de acceso por rol (RBAC). Uso en las rutas: ->middleware('permiso:insUsu'), o sin parametro para tomar
 * el comando del default "comando" de la ruta. Los roles salen de la matriz del Catalogo.
 */
class VerificarPermiso
{
    public function __construct(private BitacoraService $bitacora)
    {
    }

    public function handle(Request $request, Closure $next, ?string $comando = null): Response
    {
        $comando ??= $request->route()?->defaults['comando'] ?? null;
        $u = $request->user();
        if ($comando === null || $u === null) {
            return $next($request);
        }
        if (!Catalogo::permite($comando, $u->rol)) {
            $this->bitacora->registrar('ACCESO', $request, $u->id_usuario, $comando, "Acceso denegado: sin permiso para {$comando} (rol {$u->rol})");
            return response()->json([
                'ok' => false,
                'message' => "Su rol ({$u->nombreRol()}) no tiene permiso para esta acción ({$comando}).",
                'errores' => ["Su rol (<b>{$u->nombreRol()}</b>) no tiene permiso para usar <code>{$comando}</code>."],
            ], 403);
        }
        return $next($request);
    }
}
