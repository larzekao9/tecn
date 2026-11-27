<?php

namespace App\Services;

use App\Models\Usuario;
use App\Support\Bd;
use App\Support\NegocioException;
use App\Support\Validador;

/**
 * CU6 - Facturas: cada cuota pagada (pago) genera su factura con el NIT y la razon social que dio el anunciante al pagar.
 *  - lisFac: el anunciante ve solo las suyas; el Administrador, todas. Filtro opcional por texto (pauta, NIT, razon social).
 *  - mosFac: una factura con su detalle (pauta, cuota, metodo de pago, anunciante). El anunciante solo ve las suyas.
 * Las facturas no se crean ni se modifican a mano: las genera el pago confirmado por Stripe.
 */
class FacturaService
{
    private const SELECT = "SELECT f.id_factura AS id, f.numero_factura AS numero, to_char(f.fecha_emision, 'YYYY-MM-DD HH24:MI') AS fecha,"
        . ' f.nit, f.razon_social, f.total::float AS total, g.numero_cuota AS cuota, pa.numero_cuotas, g.metodo_pago AS metodo,'
        . " coalesce(g.referencia_stripe, '') AS comprobante, pa.nombre AS pauta, pa.id_usuario,"
        . " u.nombre || ' ' || u.apellido AS anunciante, u.email AS anunciante_email,"
        . " to_char(pa.fecha_inicio, 'YYYY-MM-DD') AS fecha_inicio, to_char(pa.fecha_fin, 'YYYY-MM-DD') AS fecha_fin, pa.duracion_spot"
        . ' FROM factura f JOIN pago g ON g.id_pago = f.id_pago JOIN pauta pa ON pa.id_pauta = g.id_pauta'
        . ' JOIN usuario u ON u.id_usuario = pa.id_usuario';

    public function __construct(private PautaService $pautas)
    {
    }

    /** lisFac["filtro"]: "*" = todas (las del anunciante si quien es anunciante). */
    public function listar(Usuario $quien, array $p): array
    {
        $v = new Validador();
        $filtro = Validador::limpiar($p[0] ?? '*') ?: '*';
        $v->verificar();
        $sql = self::SELECT . ' WHERE true';
        $params = [];
        if ($quien->rol === 'ANUNCIANTE') {
            $sql .= ' AND pa.id_usuario = ?';
            $params[] = $quien->id_usuario;
        }
        if ($filtro !== '*') {
            $sql .= " AND (pa.nombre ILIKE ? OR f.nit ILIKE ? OR f.razon_social ILIKE ? OR u.nombre || ' ' || u.apellido ILIKE ?)";
            array_push($params, "%{$filtro}%", "%{$filtro}%", "%{$filtro}%", "%{$filtro}%");
        }
        $filas = Bd::filas($sql . ' ORDER BY f.numero_factura DESC', $params);
        return ['titulo' => 'Facturas', 'datos' => ['facturas' => $filas, 'total' => round(array_sum(array_column($filas, 'total')), 2)]];
    }

    /** mosFac["numero"]: la factura con el detalle de lo que se pago (espacios contratados de la pauta). */
    public function mostrar(Usuario $quien, array $p): array
    {
        $v = new Validador();
        $numero = $v->entero($p[0] ?? null, 'número de factura', 1, PHP_INT_MAX);
        $v->verificar();
        $f = Bd::fila(self::SELECT . ' WHERE f.numero_factura = ?', [$numero]);
        if ($f === null || ($quien->rol === 'ANUNCIANTE' && (int) $f['id_usuario'] !== (int) $quien->id_usuario)) {
            throw new NegocioException("No existe la factura N° {$numero}.", 404);
        }
        $f['espacios'] = $this->pautas->espaciosDe((int) Bd::valor('SELECT id_pauta FROM pauta WHERE nombre = ?', [$f['pauta']]));
        return ['titulo' => "Factura N° {$numero}", 'datos' => $f];
    }
}
