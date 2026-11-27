<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cliente minimo de la API de Stripe (https://api.stripe.com/v1) con la clave secreta de STRIPE_SECRET (.env).
 * Devuelve el JSON de Stripe; si Stripe responde con error, devuelve ['error' => ['message' => ...]] para que
 * el servicio decida (un pago rechazado no es una excepcion, es un resultado).
 */
final class Stripe
{
    private const API = 'https://api.stripe.com/v1/';

    public static function post(string $ruta, array $datos = []): array
    {
        return self::llamar('post', $ruta, $datos);
    }

    public static function get(string $ruta, array $consulta = []): array
    {
        return self::llamar('get', $ruta, $consulta);
    }

    private static function llamar(string $metodo, string $ruta, array $datos): array
    {
        $clave = config('canal11.stripe.secreta');
        if (!$clave) {
            throw new NegocioException('Los pagos con Stripe no están configurados: falta STRIPE_SECRET en el .env.');
        }
        try {
            $r = Http::withBasicAuth($clave, '')->asForm()->timeout(30)->{$metodo}(self::API . $ruta, $datos);
        } catch (Throwable $e) {
            Log::error('[Stripe] ' . $e->getMessage());
            throw new NegocioException('No se pudo conectar con la pasarela de pagos. Intente de nuevo en unos minutos.', 503);
        }
        $json = $r->json() ?? [];
        if ($r->failed() && !isset($json['error'])) {
            $json['error'] = ['message' => 'La pasarela de pagos respondió con un error (' . $r->status() . ').'];
        }
        return $json;
    }
}
