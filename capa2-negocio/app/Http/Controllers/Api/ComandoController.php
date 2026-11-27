<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Catalogo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ejecuta una funcion del Catalogo desde una ruta REST. Cada ruta declara (con ->defaults) el comando y el orden
 * de sus campos; cada campo se toma del parametro de la ruta o del cuerpo/consulta JSON. El permiso ya lo verifico
 * el middleware "permiso" y las validaciones y reglas las hace el servicio (capa de negocio).
 */
class ComandoController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $ruta = $request->route();
        $def = Catalogo::comando($ruta->defaults['comando']);
        $p = [];
        foreach ($ruta->defaults['campos'] ?? [] as $campo) {
            $valor = $ruta->parameter($campo) ?? $request->input($campo);
            if ($campo === 'filtro' && ($valor === null || $valor === '')) {
                $valor = '*';
            }
            $p[] = is_bool($valor) ? ($valor ? 'SI' : 'NO') : $valor;
        }
        $r = app($def['clase'])->{$def['metodo']}($request->user(), $p);
        return response()->json(['ok' => true, 'comando' => $def['nombre']] + $r, $request->isMethod('post') ? 201 : 200);
    }
}
