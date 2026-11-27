<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ComandoController;
use App\Http\Controllers\Api\GeneralController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API RESTful - Canal 11
|--------------------------------------------------------------------------
| Cada ruta de negocio corresponde a una funcion del Catalogo (los comandos del sistema por correo).
| El middleware "permiso" aplica la matriz de control de acceso (tabla permiso: rol, recurso y
| sus acciones ver, crear, editar y eliminar) y el servicio aplica
| las validaciones y reglas de negocio. Los campos se envian en JSON en el orden declarado.
*/

/** Ruta de negocio: METODO uri -> comando del catalogo, con sus campos en orden. */
if (!function_exists('cmd')) {
    function cmd(string $metodo, string $uri, string $comando, array $campos = []): void
    {
        Route::match([$metodo], $uri, ComandoController::class)
            ->defaults('comando', $comando)
            ->defaults('campos', $campos)
            ->middleware('permiso');
    }
}

// --- Publicas ---
Route::post('auth/login', [AuthController::class, 'login'])->middleware('throttle:login');
Route::post('visitas', [GeneralController::class, 'visita'])->middleware('throttle:120,1');
Route::get('salud', [GeneralController::class, 'salud'])->middleware('throttle:30,1');
// Video de un material: sin token (lo pide la etiqueta <video>), protegido por firma temporal emitida en mosMat
Route::get('videos/{id}', [GeneralController::class, 'verVideo'])->whereNumber('id')->middleware('signed:relative')->name('material.video');

// --- Autenticadas ---
Route::middleware(['auth:sanctum'])->group(function () {
    Route::post('auth/logout', [AuthController::class, 'logout']);
    Route::get('auth/yo', [AuthController::class, 'yo']);
    Route::put('auth/preferencias', [AuthController::class, 'preferencias']);

    Route::get('notificaciones', [GeneralController::class, 'notificaciones']);
    Route::get('notificaciones/contador', [GeneralController::class, 'contador']);
    Route::post('notificaciones/leer-todas', [GeneralController::class, 'leerTodas']);
    Route::post('notificaciones/{id}/leer', [GeneralController::class, 'leer'])->whereNumber('id');
    Route::get('buscar', [GeneralController::class, 'buscar']);
    Route::get('opciones', [GeneralController::class, 'opciones']);

    // CU1 - Usuarios
    cmd('GET', 'usuarios', 'lisUsu', ['filtro']);
    cmd('GET', 'usuarios/{ref}', 'mosUsu', ['ref']);
    cmd('POST', 'usuarios', 'insUsu', ['ci', 'nombre', 'apellido', 'email', 'telefono', 'rol', 'contrasena']);
    cmd('PUT', 'usuarios/{ref}', 'modUsu', ['ref', 'ci', 'nombre', 'apellido', 'email', 'telefono', 'rol']);
    cmd('DELETE', 'usuarios/{ref}', 'eliUsu', ['ref']);

    // CU2 - Programas
    cmd('GET', 'programas', 'lisPro', ['filtro']);
    cmd('GET', 'programas/{ref}', 'mosPro', ['ref']);
    cmd('POST', 'programas', 'insPro', ['nombre', 'tipo']);
    cmd('PUT', 'programas/{ref}', 'modPro', ['ref', 'nombre', 'tipo']);
    cmd('DELETE', 'programas/{ref}', 'eliPro', ['ref']);

    // CU3 - Parrilla, bloques y escaleta / CU5 estado / CU7 emision
    cmd('GET', 'parrillas', 'lisPar', ['filtro']);
    cmd('GET', 'parrillas/{fecha}', 'mosPar', ['fecha']);
    cmd('POST', 'parrillas', 'insPar', ['fecha']);
    cmd('DELETE', 'parrillas/{fecha}', 'eliPar', ['fecha']);
    cmd('POST', 'parrillas/{fecha}/publicar', 'pubPar', ['fecha']);
    cmd('GET', 'parrillas/{fecha}/conciliacion', 'conPar', ['fecha']);
    cmd('POST', 'parrillas/{fecha}/bloques', 'insBlo', ['fecha', 'hora_inicio', 'hora_fin', 'tipo', 'programa', 'material']);
    cmd('DELETE', 'parrillas/{fecha}/bloques/{hora}', 'eliBlo', ['fecha', 'hora']);
    cmd('PUT', 'parrillas/{fecha}/bloques/{hora}/material', 'camBlo', ['fecha', 'hora', 'material']);

    // CU5 - Validar Estado de Emision
    cmd('GET', 'parrillas/{fecha}/validacion', 'lisVal', ['fecha']);
    cmd('POST', 'parrillas/{fecha}/bloques/{hora}/validacion', 'insVal', ['fecha', 'hora', 'estado', 'observacion']);
    cmd('PUT', 'parrillas/{fecha}/bloques/{hora}/validacion', 'modVal', ['fecha', 'hora', 'estado', 'observacion']);
    cmd('DELETE', 'parrillas/{fecha}/bloques/{hora}/validacion', 'eliVal', ['fecha', 'hora']);
    cmd('POST', 'parrillas/{fecha}/bloques/{hora}/cancelacion', 'canBlo', ['fecha', 'hora', 'reemplazo']);
    cmd('DELETE', 'parrillas/{fecha}/bloques/{hora}/cancelacion', 'reaBlo', ['fecha', 'hora']);

    // CU7 - Conciliar Emision Real
    cmd('POST', 'parrillas/{fecha}/bloques/{hora}/emision', 'insEmi', ['fecha', 'hora', 'inicio_real', 'fin_real', 'estado', 'observacion']);
    cmd('PUT', 'parrillas/{fecha}/bloques/{hora}/emision', 'modEmi', ['fecha', 'hora', 'inicio_real', 'fin_real', 'estado', 'observacion']);
    cmd('DELETE', 'parrillas/{fecha}/bloques/{hora}/emision', 'eliEmi', ['fecha', 'hora']);
    cmd('PUT', 'parrillas/{fecha}/spots/{hora}/emision', 'modSpo', ['fecha', 'hora', 'estado']);

    cmd('GET', 'parrillas/{fecha}/bloques/{hora}/escaleta', 'lisLea', ['fecha', 'hora']);
    cmd('POST', 'parrillas/{fecha}/bloques/{hora}/escaleta', 'insLea', ['fecha', 'hora', 'orden', 'material']);
    cmd('PUT', 'parrillas/{fecha}/bloques/{hora}/escaleta/{material}', 'modLea', ['fecha', 'hora', 'material', 'nuevo_orden']);
    cmd('DELETE', 'parrillas/{fecha}/bloques/{hora}/escaleta/{material}', 'eliLea', ['fecha', 'hora', 'material']);

    // CU4 - Materiales
    cmd('GET', 'materiales', 'lisMat', ['filtro']);
    cmd('GET', 'materiales/{ref}', 'mosMat', ['ref']);
    cmd('POST', 'materiales', 'insMat', ['titulo', 'tipo', 'programa', 'duracion_seg', 'formato', 'ruta']);
    cmd('PUT', 'materiales/{ref}', 'modMat', ['ref', 'titulo', 'tipo', 'programa', 'duracion_seg', 'formato', 'ruta']);
    cmd('DELETE', 'materiales/{ref}', 'eliMat', ['ref']);
    Route::post('materiales/video', [GeneralController::class, 'subirVideo'])->defaults('comando', 'insMat')->middleware('permiso');

    // CU6 - Espacios publicitarios
    cmd('GET', 'espacios', 'lisEsp');
    cmd('GET', 'espacios/disponibles', 'disEsp', ['fecha_inicio', 'fecha_fin']);
    cmd('POST', 'espacios', 'insEsp', ['nombre', 'hora_inicio', 'hora_fin', 'segundos_por_dia', 'precio_segundo']);
    cmd('PUT', 'espacios/{ref}', 'modEsp', ['ref', 'nombre', 'hora_inicio', 'hora_fin', 'segundos_por_dia', 'precio_segundo']);
    cmd('DELETE', 'espacios/{ref}', 'eliEsp', ['ref']);

    // CU6 - Pautas y spots (una pauta aprobada queda cerrada: no se modifica)
    cmd('GET', 'pautas', 'lisPau', ['filtro']);
    cmd('GET', 'pautas/{ref}', 'mosPau', ['ref']);
    cmd('POST', 'pautas', 'insPau', ['nombre', 'anunciante', 'fecha_inicio', 'fecha_fin', 'espacios', 'duracion_spot', 'frecuencia', 'cuotas', 'spot']);
    cmd('DELETE', 'pautas/{ref}', 'eliPau', ['ref']);
    cmd('GET', 'mis-pautas', 'lisMpa');
    cmd('POST', 'mis-pautas/{ref}/stripe', 'pagStr', ['ref', 'cuotas', 'metodo', 'nit', 'razon_social']);
    cmd('GET', 'mis-cobros/{sesion}', 'mosCob', ['sesion']);
    cmd('GET', 'facturas', 'lisFac', ['filtro']);
    cmd('GET', 'facturas/{numero}', 'mosFac', ['numero']);
    cmd('GET', 'solicitudes', 'lisSol');
    cmd('POST', 'solicitudes', 'insSol', ['nombre', 'fecha_inicio', 'fecha_fin', 'espacios', 'frecuencia', 'cuotas', 'titulo_spot', 'ruta', 'formato', 'duracion_seg']);
    Route::post('solicitudes/video', [GeneralController::class, 'subirVideo'])->defaults('comando', 'insSol')->middleware('permiso');
    cmd('POST', 'solicitudes/{id}/cancelar', 'canSol', ['id']);
    cmd('POST', 'solicitudes/{id}/aprobar', 'apbSol', ['id']);
    cmd('POST', 'solicitudes/{id}/rechazar', 'recSol', ['id']);

    // CU1 - Bitacora de seguridad (accesos de los usuarios)
    cmd('GET', 'bitacora', 'lisBit', ['accion', 'desde', 'hasta', 'texto']);

    // CU8 - Reportes y estadisticas
    cmd('GET', 'reportes/programas', 'repPro', ['desde', 'hasta']);
    cmd('GET', 'reportes/materiales', 'repMat', ['desde', 'hasta']);
    cmd('GET', 'reportes/pautas', 'repPau', ['desde', 'hasta']);
    cmd('GET', 'reportes/cumplimiento', 'repCum', ['desde', 'hasta']);
    cmd('GET', 'estadisticas', 'verEst', ['dias']);

    // CU1 - Matriz de control de acceso
    cmd('GET', 'roles', 'lisRol');
    cmd('PUT', 'roles/{rol}/permisos', 'modRol', ['rol', 'recurso', 'accion', 'concedido']);
});
