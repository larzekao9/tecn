<?php

namespace App\Http\Middleware;

use App\Services\BitacoraService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Registra cada peticion a la API en la bitacora (ACCESO): quien, a que recurso, resultado, cuanto tardo, IP y navegador. */
class RegistrarTrafico
{
    /** Rutas de sondeo frecuente que no aportan a la bitacora. */
    private const IGNORAR = ['api/notificaciones/contador', 'api/visitas'];

    public function __construct(private BitacoraService $bitacora)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $inicio = microtime(true);
        $response = $next($request);
        if ($request->isMethod('OPTIONS') || in_array($request->path(), self::IGNORAR, true) || $request->is('api/auth/login')) {
            return $response;
        }
        $u = $request->user();
        $this->bitacora->acceso($request, $u?->id_usuario, $response->getStatusCode(), (int) round((microtime(true) - $inicio) * 1000));
        return $response;
    }
}
