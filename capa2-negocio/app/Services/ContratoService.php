<?php

namespace App\Services;

use App\Models\Usuario;
use App\Support\Bd;
use App\Support\NegocioException;
use App\Support\Reloj;
use App\Support\Stripe;
use App\Support\Validador;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * CU6 - Contrato y pagos de la pauta (pauta se paga en pago: cada pago es una cuota; pago genera factura). Reglas:
 *  - Contrato: el costo de la pauta = suma de (salidas por dia x dias x duracion x precio por segundo) de sus espacios.
 *    Sin intereses. Aprobada la pauta, el costo y el plan no cambian (pauta cerrada).
 *  - Plan: CONTADO (1 cuota) o SEMANAL / QUINCENAL / MENSUAL / TRIMESTRAL con 2..MAX_CUOTAS cuotas. Las cuotas se crean
 *    al aprobar la pauta; la 1a vence ese dia.
 *  - Estado de cada cuota: PENDIENTE, VENCIDO (paso su fecha sin pagarse) o PAGADO.
 *  - Estado de pago de la pauta (se calcula, no se guarda): PENDIENTE (falta la 1a cuota), ACTIVA, SUSPENDIDA (hay una
 *    cuota VENCIDA), PAGADA (todas pagadas), FINALIZADA (pagada y con sus salidas usadas o la vigencia terminada).
 *    Solo con ACTIVA o PAGADA el sistema programa sus spots.
 *  - Solo paga el anunciante, en Stripe (pagStr), con tarjeta o por QR (Cash App): elige cuantas cuotas paga (las
 *    siguientes en orden) y da su NIT y razon social. El pago se registra recien cuando Stripe lo confirma (mosCob o la
 *    tarea programada) y cada cuota pagada genera su factura.
 */
class ContratoService
{
    public const FRECUENCIAS = ['CONTADO', 'SEMANAL', 'QUINCENAL', 'MENSUAL', 'TRIMESTRAL'];
    /** TARJETA: tarjeta de debito/credito en la moneda del canal. QR: Cash App Pay (el anunciante escanea el QR), en dolares. */
    public const METODOS = ['TARJETA', 'QR'];
    public const NOMBRES_METODO = ['TARJETA' => 'tarjeta', 'QR' => 'QR (Cash App)'];
    public const CON_SPOTS = ['ACTIVA', 'PAGADA'];

    public function __construct(private BitacoraService $bitacora, private NotificacionService $avisos)
    {
    }

    // ------------------------------------------------------------------ validaciones comunes

    /** [frecuencia, numero de cuotas]: CONTADO = 1 cuota; las demas, de 2 a MAX_CUOTAS. */
    public static function leerPlan(Validador $v, mixed $frecuencia, mixed $cuotas): array
    {
        $f = $v->opcion($frecuencia, 'frecuencia', self::FRECUENCIAS);
        if ($f === 'CONTADO') {
            return [$f, 1];
        }
        $max = max(2, (int) config('canal11.pautas.max_cuotas'));
        return [$f, $v->entero($cuotas, 'cuotas', 2, $max)];
    }

    public static function vencimiento(string $desde, string $frecuencia, int $i): string
    {
        $d = CarbonImmutable::parse($desde, Reloj::zona());
        return (match ($frecuencia) {
            'SEMANAL' => $d->addDays(7 * $i),
            'QUINCENAL' => $d->addDays(15 * $i),
            'TRIMESTRAL' => $d->addMonthsNoOverflow(3 * $i),
            default => $d->addMonthsNoOverflow($i),
        })->format('Y-m-d');
    }

    // ------------------------------------------------------------------ cuotas del plan (las crea la aprobacion)

    /** Crea las cuotas (pagos PENDIENTES) de la pauta: la ultima absorbe los centavos; la 1a vence hoy. */
    public function crearCuotas(int $idPauta): void
    {
        $p = Bd::fila('SELECT costo_total::float AS costo, frecuencia_pago, numero_cuotas FROM pauta WHERE id_pauta = ?', [$idPauta]);
        $n = $p['frecuencia_pago'] === 'CONTADO' ? 1 : (int) $p['numero_cuotas'];
        $centavos = (int) round($p['costo'] * 100);
        $base = intdiv($centavos, $n);
        for ($i = 0; $i < $n; $i++) {
            $monto = $base + ($i === $n - 1 ? $centavos - $base * $n : 0);
            Bd::ejecutar('INSERT INTO pago (id_pauta, numero_cuota, monto, fecha_vencimiento) VALUES (?, ?, ?, ?)',
                [$idPauta, $i + 1, $monto / 100, self::vencimiento(Reloj::hoy(), $p['frecuencia_pago'], $i)]);
        }
        $this->cuenta($idPauta);
    }

    // ------------------------------------------------------------------ estado de cuenta

    /**
     * Costo, pagado, saldo, cuotas con su estado, lo exigible, el estado de pago (calculado) y los pagos hechos con su
     * factura. De paso deja al dia las cuotas VENCIDAS.
     */
    public function cuenta(int $idPauta): array
    {
        $p = Bd::fila('SELECT pa.duracion_spot, pa.frecuencia_pago, pa.numero_cuotas, pa.estado, pa.costo_total::float AS costo,'
            . " to_char(pa.fecha_fin, 'YYYY-MM-DD') AS fecha_fin, " . PautaService::SALIDAS . ' AS salidas,'
            . " (SELECT count(*) FROM spot s WHERE s.id_pauta = pa.id_pauta AND s.estado <> 'NO_EMITIDO') AS usados"
            . ' FROM pauta pa WHERE pa.id_pauta = ?', [$idPauta]);
        $hoy = Reloj::hoy();
        Bd::ejecutar("UPDATE pago SET estado = CASE WHEN fecha_vencimiento < ? THEN 'VENCIDO' ELSE 'PENDIENTE' END"
            . " WHERE id_pauta = ? AND estado <> 'PAGADO' AND estado <> CASE WHEN fecha_vencimiento < ? THEN 'VENCIDO' ELSE 'PENDIENTE' END",
            [$hoy, $idPauta, $hoy]);
        $cuotas = array_map(function ($c) {
            $c['pagado'] = $c['estado'] === 'PAGADO' ? $c['monto'] : 0.0;
            $c['restante'] = $c['estado'] === 'PAGADO' ? 0.0 : $c['monto'];
            $c['en_proceso'] = $c['estado'] !== 'PAGADO' && str_starts_with((string) $c['referencia_stripe'], 'cs_');
            unset($c['referencia_stripe']);
            return $c;
        }, Bd::filas("SELECT numero_cuota AS numero, to_char(fecha_vencimiento, 'YYYY-MM-DD') AS vence, monto::float AS monto, estado,"
            . " coalesce(referencia_stripe, '') AS referencia_stripe FROM pago WHERE id_pauta = ? ORDER BY numero_cuota", [$idPauta]));
        $pagado = round(array_sum(array_column($cuotas, 'pagado')), 2);
        $saldo = round($p['costo'] - $pagado, 2);
        $vencidas = array_values(array_filter($cuotas, fn ($c) => $c['estado'] === 'VENCIDO'));
        $proxima = array_values(array_filter($cuotas, fn ($c) => $c['estado'] === 'PENDIENTE'))[0] ?? null;
        $exigible = round(array_sum(array_column($vencidas, 'restante')) + ($proxima['restante'] ?? 0), 2);
        $terminada = (int) $p['usados'] >= (int) $p['salidas'] || $p['fecha_fin'] < $hoy;
        $estado = $cuotas === [] ? 'PENDIENTE'
            : ($saldo <= 0.004 ? ($terminada ? 'FINALIZADA' : 'PAGADA')
                : ($vencidas ? 'SUSPENDIDA' : ($cuotas[0]['estado'] !== 'PAGADO' ? 'PENDIENTE' : 'ACTIVA')));
        return [
            'estado' => $estado,
            'puede_spots' => $p['estado'] === 'APROBADA' && in_array($estado, self::CON_SPOTS, true),
            'costo' => $p['costo'], 'pagado' => $pagado, 'saldo' => $saldo, 'exigible' => $exigible,
            'duracion_spot' => (int) $p['duracion_spot'], 'frecuencia' => $p['frecuencia_pago'],
            'numero_cuotas' => (int) $p['numero_cuotas'], 'salidas' => (int) $p['salidas'], 'salidas_usadas' => (int) $p['usados'],
            'cuotas' => $cuotas,
            'pagos' => Bd::filas("SELECT g.numero_cuota, to_char(g.fecha_pago, 'YYYY-MM-DD HH24:MI') AS fecha, g.monto::float AS monto, g.metodo_pago AS metodo,"
                . " coalesce(g.referencia_stripe, '') AS comprobante, u.nombre || ' ' || u.apellido AS registrado_por,"
                . " f.numero_factura, to_char(f.fecha_emision, 'YYYY-MM-DD HH24:MI') AS fecha_factura, f.nit, f.razon_social"
                . ' FROM pago g JOIN pauta pa ON pa.id_pauta = g.id_pauta JOIN usuario u ON u.id_usuario = pa.id_usuario'
                . ' LEFT JOIN factura f ON f.id_pago = g.id_pago'
                . " WHERE g.id_pauta = ? AND g.estado = 'PAGADO' ORDER BY g.fecha_pago DESC, g.numero_cuota DESC", [$idPauta]),
        ];
    }

    public function tieneVencidas(int $idPauta): bool
    {
        return Bd::existe("SELECT 1 FROM pago WHERE id_pauta = ? AND estado <> 'PAGADO' AND fecha_vencimiento < ?", [$idPauta, Reloj::hoy()]);
    }

    public function dinero(float $monto): string
    {
        return config('canal11.pautas.simbolo') . ' ' . number_format($monto, 2, ',', '.');
    }

    // ------------------------------------------------------------------ comandos del Anunciante: pago con Stripe

    /**
     * pagStr["pauta","cuotas","metodo","nit","razon_social"]: el anunciante paga las siguientes N cuotas que le faltan
     * (las vencidas primero) en la pagina segura de Stripe Checkout y vuelve a "Mis pautas". El NIT (4 a 20 digitos) y la
     * razon social van a la factura de cada cuota pagada. metodo:
     *  TARJETA -> tarjeta, cobrado en la moneda del canal (STRIPE_MONEDA).
     *  QR      -> Cash App Pay: Stripe muestra un QR para escanear con la app. Stripe solo lo ofrece en dolares
     *             (STRIPE_QR_MONEDA): el monto se convierte con TIPO_CAMBIO. La cuota se registra con su monto en Bs.
     */
    public function pagarStripe(Usuario $quien, array $p): array
    {
        $id = $this->miPautaId($quien, $p[0] ?? null);
        $c = $this->cuenta($id);
        $faltan = array_values(array_filter($c['cuotas'], fn ($x) => $x['estado'] !== 'PAGADO'));
        if ($faltan === []) {
            throw new NegocioException('La pauta ya está pagada por completo.');
        }
        $v = new Validador();
        $n = $v->entero($p[1] ?? '1', 'cuotas', 1, count($faltan));
        $metodo = $v->opcion($p[2] ?? 'TARJETA', 'metodo', self::METODOS);
        $nit = $v->texto($p[3] ?? null, 'NIT', 20);
        if ($nit !== null && !preg_match('/^\d{4,20}$/', $nit)) {
            $v->error('El <b>NIT</b> debe tener solo números, de 4 a 20 dígitos (puede usar su CI).');
        }
        $razon = $v->texto($p[4] ?? null, 'razón social', 150);
        $v->verificar();
        $elegidas = array_slice($faltan, 0, $n);
        $monto = round(array_sum(array_column($elegidas, 'monto')), 2);
        $minimo = (float) config('canal11.stripe.minimo');
        if ($monto + 0.004 < $minimo) {
            throw new NegocioException('El pago mínimo es ' . $this->dinero($minimo) . '.');
        }
        // lo que cobra Stripe: en Bs con tarjeta; en dolares por QR (Cash App)
        if ($metodo === 'QR') {
            $cambio = (float) config('canal11.stripe.tipo_cambio');
            if ($cambio <= 0) {
                throw new NegocioException('Falta configurar el tipo de cambio para pagar por QR (TIPO_CAMBIO en el .env).');
            }
            $moneda = (string) config('canal11.stripe.qr_moneda');
            $cobro = round($monto / $cambio, 2);
            $tipos = [(string) config('canal11.stripe.qr_metodo')];
        } else {
            $moneda = (string) config('canal11.stripe.moneda');
            $cobro = $monto;
            $tipos = ['card'];
        }
        $pauta = Bd::fila('SELECT nombre FROM pauta WHERE id_pauta = ?', [$id]);
        $numeros = array_column($elegidas, 'numero');
        $front = rtrim((string) config('canal11.stripe.frontend_url'), '/');
        $vence = time() + 60 * max(30, (int) config('canal11.stripe.minutos')); // Stripe exige al menos 30 minutos
        $r = Stripe::post('checkout/sessions', [
            'mode' => 'payment', 'expires_at' => $vence, 'allowed_payment_method_types' => $tipos,
            'line_items' => [['quantity' => 1, 'price_data' => [
                'currency' => $moneda, 'unit_amount' => (int) round($cobro * 100),
                'product_data' => ['name' => "Pauta {$pauta['nombre']} - Canal 11 (cuota " . implode(', ', $numeros) . ')'
                    . ($metodo === 'QR' ? ' · ' . $this->dinero($monto) . ' al cambio de ' . config('canal11.stripe.tipo_cambio') : '')]]]],
            'metadata' => ['id_pauta' => $id, 'cuotas' => implode(',', $numeros), 'metodo' => $metodo, 'nit' => $nit, 'razon_social' => $razon],
            'success_url' => "{$front}/mis-pautas?sesion={CHECKOUT_SESSION_ID}",
            'cancel_url' => "{$front}/mis-pautas?cancelado=1",
        ]);
        if (isset($r['error'])) {
            throw new NegocioException('Stripe no pudo iniciar el pago: ' . Validador::esc($r['error']['message']));
        }
        Bd::ejecutar("UPDATE pago SET referencia_stripe = ? WHERE id_pauta = ? AND estado <> 'PAGADO' AND numero_cuota = ANY(?)",
            [$r['id'], $id, '{' . implode(',', $numeros) . '}']);
        $this->bitacora->accion($quien, BitacoraService::INSERTAR, 'pago');
        return ['titulo' => 'Pago con Stripe',
            'datos' => ['sesion' => $r['id'], 'url' => $r['url'], 'monto' => $monto, 'metodo' => $metodo, 'cobro' => $cobro, 'moneda' => strtoupper($moneda), 'cuotas' => $numeros,
                'expira' => CarbonImmutable::createFromTimestamp($r['expires_at'] ?? $vence, Reloj::zona())->format('H:i')]];
    }

    /** mosCob["sesion"]: confirma con Stripe un pago propio; si sigue abierto, dice que esta pendiente. */
    public function estadoCobro(Usuario $quien, array $p): array
    {
        $sesion = trim((string) ($p[0] ?? ''));
        if (!preg_match('/^cs_[A-Za-z0-9_]{1,250}$/', $sesion)) {
            throw new NegocioException('El pago no existe o ya no está abierto.', 404);
        }
        $suyos = fn (string $ref) => Bd::fila('SELECT g.id_pauta, sum(g.monto)::float AS monto FROM pago g JOIN pauta pa ON pa.id_pauta = g.id_pauta'
            . ' WHERE g.referencia_stripe = ? AND pa.id_usuario = ? GROUP BY g.id_pauta', [$ref, $quien->id_usuario]);
        $g = $suyos($sesion);
        if ($g !== null) {
            $estado = $this->revisarSesion($sesion); // sigue abierta: se pregunta a Stripe
        } else {
            // ya confirmada (por la tarea programada): sus cuotas quedaron con la referencia del pago (pi_...)
            $pi = (string) (Stripe::get('checkout/sessions/' . $sesion)['payment_intent'] ?? '');
            $g = $pi !== '' ? $suyos($pi) : null;
            if ($g === null) {
                throw new NegocioException('El pago no existe o ya no está abierto.', 404);
            }
            $estado = 'APROBADO';
        }
        return ['titulo' => 'Pago con Stripe',
            'mensaje' => $estado === 'APROBADO' ? 'Stripe confirmó su pago de <b>' . $this->dinero($g['monto']) . '</b>.' : null,
            'datos' => ['estado' => $estado, 'monto' => $g['monto'], 'cuenta' => $this->cuenta((int) $g['id_pauta'])]];
    }

    /** Tarea programada: confirma los pagos que Stripe cobro (aunque el anunciante cerro la pagina) y suelta los vencidos. */
    public function revisarCobros(): int
    {
        $n = 0;
        foreach (Bd::filas("SELECT DISTINCT referencia_stripe AS sesion FROM pago WHERE estado <> 'PAGADO' AND referencia_stripe LIKE 'cs\\_%'") as $f) {
            $n += $this->revisarSesion($f['sesion']) === 'APROBADO' ? 1 : 0;
        }
        return $n;
    }

    /** Pregunta a Stripe por la sesion: pagada -> registra el pago de sus cuotas; vencida -> las cuotas quedan libres. */
    private function revisarSesion(string $sesion): string
    {
        $s = Stripe::get('checkout/sessions/' . $sesion);
        if (($s['payment_status'] ?? '') === 'paid') {
            $metodo = in_array($s['metadata']['metodo'] ?? '', self::METODOS, true) ? $s['metadata']['metodo'] : 'TARJETA';
            return $this->confirmarCobro($sesion, (string) ($s['payment_intent'] ?? $sesion), $metodo,
                (string) ($s['metadata']['nit'] ?? ''), (string) ($s['metadata']['razon_social'] ?? '')) ? 'APROBADO' : 'PENDIENTE';
        }
        if (($s['status'] ?? '') === 'expired') {
            Bd::ejecutar("UPDATE pago SET referencia_stripe = NULL WHERE referencia_stripe = ? AND estado <> 'PAGADO'", [$sesion]);
            return 'EXPIRADO';
        }
        return 'PENDIENTE';
    }

    /**
     * Stripe confirmo el cobro: sus cuotas quedan PAGADAS con el metodo (TARJETA / QR) y la referencia del pago (una
     * sola vez), y cada una genera su factura con el NIT y la razon social (si faltan: CI y nombre del anunciante).
     */
    private function confirmarCobro(string $sesion, string $ref, string $metodo, string $nit, string $razon): bool
    {
        $g = DB::transaction(function () use ($sesion, $ref, $metodo, $nit, $razon) {
            $filas = Bd::filas("SELECT id_pago, id_pauta, monto::float AS monto, numero_cuota FROM pago WHERE referencia_stripe = ? AND estado <> 'PAGADO' FOR UPDATE", [$sesion]);
            if ($filas === []) {
                return null;
            }
            $idPauta = (int) $filas[0]['id_pauta'];
            $antes = $this->cuenta($idPauta)['estado'];
            Bd::ejecutar("UPDATE pago SET estado = 'PAGADO', fecha_pago = now(), metodo_pago = ?, referencia_stripe = ? WHERE referencia_stripe = ? AND estado <> 'PAGADO'",
                [$metodo, $ref, $sesion]);
            $this->facturar(array_column($filas, 'id_pago'), $nit, $razon);
            return ['id_pauta' => $idPauta, 'antes' => $antes, 'monto' => round(array_sum(array_column($filas, 'monto')), 2),
                'cuotas' => implode(', ', array_column($filas, 'numero_cuota'))];
        });
        if ($g === null) {
            return false;
        }
        $pauta = Bd::fila('SELECT nombre, id_usuario FROM pauta WHERE id_pauta = ?', [$g['id_pauta']]);
        $por = self::NOMBRES_METODO[$metodo];
        $this->despuesDelPago($g['id_pauta'], $g['monto'], "cuota(s) {$g['cuotas']} por {$por} {$ref}", $g['antes'], Usuario::find($pauta['id_usuario']), 'pagStr');
        $nueva = $this->cuenta($g['id_pauta']);
        $this->avisos->enviar($this->avisos->usuariosDeRoles(null, 'ADMINISTRADOR'), "Pago por {$por}: {$pauta['nombre']}", 'El anunciante pagó <b>' . $this->dinero($g['monto']) . "</b> por {$por} (cuota(s) {$g['cuotas']}) de la pauta <b>{$pauta['nombre']}</b>. Estado: <b>{$nueva['estado']}</b>.");
        return true;
    }

    // ------------------------------------------------------------------ datos de demostracion

    /** Solo para el DemoSeeder: las siguientes N cuotas pagadas con tarjeta (ref = id del pago en Stripe). */
    public function sembrarPago(string $pauta, int $cuotas, string $ref): void
    {
        $id = $this->pautaId($pauta);
        $antes = $this->cuenta($id)['estado'];
        $numeros = array_column(Bd::filas("SELECT numero_cuota FROM pago WHERE id_pauta = ? AND estado <> 'PAGADO' ORDER BY numero_cuota LIMIT ?", [$id, $cuotas]), 'numero_cuota');
        Bd::ejecutar("UPDATE pago SET estado = 'PAGADO', fecha_pago = now(), metodo_pago = 'TARJETA', referencia_stripe = ? WHERE id_pauta = ? AND numero_cuota = ANY(?)",
            [$ref, $id, '{' . implode(',', $numeros) . '}']);
        $this->facturar(array_column(Bd::filas('SELECT id_pago FROM pago WHERE id_pauta = ? AND numero_cuota = ANY(?)', [$id, '{' . implode(',', $numeros) . '}']), 'id_pago'), '', '');
        $monto = (float) Bd::valor('SELECT coalesce(sum(monto), 0) FROM pago WHERE id_pauta = ? AND numero_cuota = ANY(?)', [$id, '{' . implode(',', $numeros) . '}']);
        $this->despuesDelPago($id, $monto, "cuota(s) " . implode(', ', $numeros) . " con tarjeta {$ref}", $antes,
            Usuario::find(Bd::valor('SELECT id_usuario FROM pauta WHERE id_pauta = ?', [$id])), 'pagStr');
    }

    // ------------------------------------------------------------------ auxiliares

    /** pago genera factura: una por cada cuota pagada (sin NIT valido: el CI del anunciante; sin razon social: su nombre). */
    private function facturar(array $idsPago, string $nit, string $razon): void
    {
        foreach ($idsPago as $idPago) {
            Bd::ejecutar("INSERT INTO factura (id_pago, nit, razon_social, total) SELECT g.id_pago,"
                . " CASE WHEN ?::text ~ '^[0-9]{4,20}$' THEN ?::text WHEN u.ci ~ '^[0-9]{4,20}$' THEN u.ci ELSE '0000' END,"
                . " coalesce(nullif(btrim(?::text), ''), u.nombre || ' ' || u.apellido), g.monto"
                . ' FROM pago g JOIN pauta pa ON pa.id_pauta = g.id_pauta JOIN usuario u ON u.id_usuario = pa.id_usuario'
                . " WHERE g.id_pago = ? AND g.estado = 'PAGADO' ON CONFLICT (id_pago) DO NOTHING", [$nit, $nit, mb_substr($razon, 0, 150), $idPago]);
        }
    }

    /** Despues de un pago: los spots se programan solos (si quedo al dia) y se audita. */
    private function despuesDelPago(int $id, float $monto, string $detalle, string $antes, ?Usuario $quien, string $comando): void
    {
        app(PautaService::class)->programar($id, $quien);
        $nueva = $this->cuenta($id);
        $nombre = Bd::valor('SELECT nombre FROM pauta WHERE id_pauta = ?', [$id]);
        $this->bitacora->accion($quien, BitacoraService::PAGAR, 'pauta');
    }

    private function pautaId(mixed $ref): int
    {
        $v = new Validador();
        $nombre = $v->texto($ref, 'pauta', 150);
        $v->verificar();
        $id = Bd::valor('SELECT id_pauta FROM pauta WHERE lower(nombre) = lower(?)', [$nombre]);
        if (!$id) {
            throw new NegocioException('No existe la pauta <b>' . Validador::esc($nombre) . '</b>.', 404);
        }
        return (int) $id;
    }

    /** La pauta (por nombre) tiene que ser del anunciante y estar APROBADA. */
    private function miPautaId(Usuario $quien, mixed $ref): int
    {
        $id = $this->pautaId($ref);
        $pa = Bd::fila('SELECT id_usuario, estado FROM pauta WHERE id_pauta = ?', [$id]);
        if ((int) $pa['id_usuario'] !== (int) $quien->id_usuario) {
            throw new NegocioException('La pauta no es suya.', 403);
        }
        if ($pa['estado'] !== 'APROBADA') {
            throw new NegocioException("La pauta está <b>{$pa['estado']}</b>: solo se pagan pautas aprobadas.");
        }
        return $id;
    }
}
