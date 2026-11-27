<?php

use App\Http\Middleware\RegistrarTrafico;
use App\Http\Middleware\VerificarPermiso;
use App\Services\BitacoraService;
use App\Support\ErrorBD;
use App\Support\NegocioException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'permiso' => VerificarPermiso::class,
        ]);
        $middleware->api(append: [RegistrarTrafico::class]);
        // Detras del proxy de Vite / Nginx: la IP real del cliente llega en X-Forwarded-For
        $middleware->trustProxies(at: env('TRUSTED_PROXIES', '*'));
        // La API no redirige a una pantalla de login: responde 401 en JSON
        $middleware->redirectGuestsTo(fn (Request $r) => $r->is('api/*') ? null : '/');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*') || $request->expectsJson());

        $json = fn (int $estado, array $errores, array $extra = []) => response()->json(
            ['ok' => false, 'message' => strip_tags($errores[0] ?? 'Error'), 'errores' => $errores] + $extra, $estado);

        // Reglas de negocio y validaciones: todos los errores juntos, en espanol
        $exceptions->render(fn (NegocioException $e) => $json($e->getEstado(), $e->getErrores()));

        $exceptions->render(fn (ValidationException $e) => $json(422, array_merge(...array_values($e->errors())), ['campos' => $e->errors()]));

        $exceptions->render(fn (AuthenticationException $e, Request $r) => $r->is('api/*')
            ? $json(401, ['Su sesión expiró o no ha iniciado sesión. Ingrese nuevamente.']) : null);

        $exceptions->render(fn (ThrottleRequestsException $e, Request $r) => $r->is('api/*')
            ? $json(429, ['Demasiadas solicitudes seguidas. Espere un momento e intente de nuevo.']) : null);

        $exceptions->render(fn (NotFoundHttpException $e, Request $r) => $r->is('api/*')
            ? $json(404, ['El recurso solicitado no existe.']) : null);

        $exceptions->render(fn (MethodNotAllowedHttpException $e, Request $r) => $r->is('api/*')
            ? $json(405, ['La operación no está permitida sobre este recurso.']) : null);

        // Errores de la base de datos (restricciones y triggers): mensaje traducido
        $exceptions->render(function (QueryException $e, Request $r) use ($json) {
            if (!$r->is('api/*')) {
                return null;
            }
            $estado = (string) ($e->errorInfo[0] ?? '');
            $conocido = str_starts_with($estado, '23') || str_starts_with($estado, '22') || $estado === 'P0001';
            if (!$conocido) {
                app(BitacoraService::class)->registrar('ERROR', $r, $r->user()?->id_usuario, 'Sistema', 'BD ' . $estado . ': ' . $e->getMessage());
            }
            return $json($conocido ? 422 : 500, [ErrorBD::traducir($e)]);
        });

        // Cualquier otro error: mensaje generico y registro en la bitacora
        $exceptions->render(function (Throwable $e, Request $r) use ($json) {
            if (!$r->is('api/*') || $e instanceof HttpExceptionInterface) {
                return null;
            }
            app(BitacoraService::class)->registrar('ERROR', $r, $r->user()?->id_usuario, 'Sistema', get_class($e) . ': ' . $e->getMessage());
            return $json(500, ['Ocurrió un error inesperado al procesar la solicitud.'], config('app.debug') ? ['detalle' => $e->getMessage()] : []);
        });
    })->create();
