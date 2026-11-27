<?php

namespace App\Services;

use App\Models\Usuario;
use App\Support\Bd;
use App\Support\NegocioException;
use App\Support\Reloj;
use App\Support\Validador;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * CU6 - Pauta Publicitaria e Insertar Spots Comerciales.
 * Pauta: la solicita un anunciante (usuario) con su video (material SPOT) o la registra el Administrador ya aprobada.
 *   nombre unico; fecha_fin >= fecha_inicio; duracion del spot 1 a 300 s; plan de pago (ContratoService).
 *   La pauta elige uno o mas ESPACIOS publicitarios (pauta_espacio) con sus salidas por dia; el precio por segundo es
 *   el del espacio al pedirla. Salidas contratadas = salidas por dia x dias de vigencia (se calculan, no se guardan).
 *   costo = suma de (salidas por dia x dias x duracion x precio) de cada espacio. Cada espacio debe tener cupo libre.
 *   Estado: EN REVISION -> APROBADA (se crean sus cuotas) | RECHAZADA | CANCELADA. Aprobada, queda cerrada: no se modifica.
 * Spots: NADIE los inserta a mano. programar() pone el video de la pauta en la parrilla, solo: cada dia de su vigencia,
 *   en cada espacio elegido, sus salidas por dia en las pausas comerciales que caen dentro del horario del espacio
 *   (una salida por pausa), mientras la pauta este APROBADA y al dia con su pago. Una salida que no se emitio (spot
 *   NO_EMITIDO) se repone otro dia en el mismo espacio, si queda cupo. Si la pauta queda suspendida o se cancela, sus
 *   salidas futuras se retiran. Se ejecuta al registrar un pago, al agregar una pausa y cada minuto.
 */
class PautaService
{
    public const EN_REVISION = 'EN REVISION';
    public const APROBADA = 'APROBADA';

    private const USADOS = "(SELECT count(*) FROM spot s WHERE s.id_pauta = pa.id_pauta AND s.estado <> 'NO_EMITIDO')";
    /** Salidas contratadas: salidas por dia de todos sus espacios x dias de vigencia. */
    public const SALIDAS = '((SELECT coalesce(sum(pe.salidas_por_dia), 0) FROM pauta_espacio pe WHERE pe.id_pauta = pa.id_pauta)'
        . ' * (pa.fecha_fin - pa.fecha_inicio + 1))';

    public const SELECT = "SELECT pa.id_pauta AS id, pa.nombre, pa.id_usuario, u.nombre || ' ' || u.apellido AS anunciante, u.ci AS anunciante_ci,"
        . " u.email AS anunciante_email, to_char(pa.fecha_inicio, 'YYYY-MM-DD') AS fecha_inicio, to_char(pa.fecha_fin, 'YYYY-MM-DD') AS fecha_fin,"
        . ' ' . self::SALIDAS . ' AS salidas, ' . self::USADOS . ' AS salidas_usadas, ' . self::SALIDAS . ' - ' . self::USADOS . ' AS salidas_pendientes,'
        . ' pa.duracion_spot, pa.frecuencia_pago AS frecuencia, pa.numero_cuotas, pa.costo_total::float AS costo, pa.estado,'
        . ' pa.id_material, m.titulo AS titulo_spot'
        . ' FROM pauta pa JOIN usuario u ON u.id_usuario = pa.id_usuario JOIN material m ON m.id_material = pa.id_material';

    private const SELECT_SPOT = "SELECT s.id_bloque || '-' || to_char(s.hora, 'HH24:MI:SS') AS id, to_char(pr.fecha, 'YYYY-MM-DD') AS fecha, to_char(s.hora, 'HH24:MI:SS') AS hora,"
        . ' pa.nombre AS pauta, m.id_material AS material, m.titulo, m.duracion_seg, s.estado,'
        . " coalesce((SELECT e.nombre FROM espacio e WHERE s.hora >= e.hora_inicio AND s.hora < e.hora_fin), '') AS espacio,"
        . " to_char(b.hora_inicio, 'HH24:MI:SS') || '-' || to_char(b.hora_fin, 'HH24:MI:SS') AS pausa"
        . ' FROM spot s JOIN pauta pa ON pa.id_pauta = s.id_pauta JOIN material m ON m.id_material = pa.id_material'
        . ' JOIN bloque b ON b.id_bloque = s.id_bloque JOIN parrilla pr ON pr.id_parrilla = b.id_parrilla';

    public function __construct(
        private BitacoraService $bitacora,
        private MaterialService $materiales,
        private ParrillaService $parrillas,
        private ContratoService $contratos,
        private EspacioService $espacios,
    ) {
    }

    /** lisPau: "*" = todas las aprobadas o canceladas; otro texto = busca por anunciante o nombre. Con estado de pago y saldo. */
    public function listar(Usuario $quien, array $p): array
    {
        $v = new Validador();
        $f = $v->texto($p[0] ?? '*', 'filtro', 150);
        $v->verificar();
        $donde = " WHERE pa.estado IN ('APROBADA', 'CANCELADA')";
        $datos = $f === '*'
            ? Bd::filas(self::SELECT . $donde . ' ORDER BY pa.fecha_inicio DESC, pa.nombre')
            : Bd::filas(self::SELECT . $donde . " AND (u.nombre || ' ' || u.apellido ILIKE ? OR u.ci ILIKE ? OR pa.nombre ILIKE ?) ORDER BY pa.fecha_inicio DESC, pa.nombre",
                ["%{$f}%", "%{$f}%", "%{$f}%"]);
        return ['titulo' => 'Pautas publicitarias', 'datos' => array_map([$this, 'conPago'], $datos)];
    }

    /** Agrega sus espacios, el estado de pago y el saldo a una fila de pauta. */
    private function conPago(array $f): array
    {
        $c = $this->contratos->cuenta((int) $f['id']);
        $f['espacios'] = $this->espaciosDe((int) $f['id']);
        $f['estado_pago'] = $c['estado'];
        $f['saldo'] = $c['saldo'];
        $f['exigible'] = $c['exigible'];
        return $f;
    }

    /** lisMpa: las pautas aprobadas (o canceladas) del anunciante, con sus spots y su estado de cuenta. */
    public function misPautas(Usuario $quien, array $p): array
    {
        $pautas = Bd::filas(self::SELECT . " WHERE pa.id_usuario = ? AND pa.estado IN ('APROBADA', 'CANCELADA') ORDER BY pa.fecha_inicio DESC",
            [$quien->id_usuario]);
        foreach ($pautas as &$f) {
            $f = $this->completar($f);
        }
        unset($f);
        return ['titulo' => 'Pautas de ' . $quien->nombreCompleto(), 'datos' => ['anunciante' => $quien->nombreCompleto(), 'pautas' => $pautas]];
    }

    /** El video de la pauta (material SPOT) con el enlace firmado para verlo. */
    public function spotAsignado(int $idPauta): ?array
    {
        $m = Bd::fila('SELECT m.id_material AS id, m.titulo, m.duracion_seg, m.formato, m.ruta'
            . ' FROM pauta pa JOIN material m ON m.id_material = pa.id_material WHERE pa.id_pauta = ?', [$idPauta]);
        if ($m === null) {
            return null;
        }
        $m['video_url'] = MaterialService::urlVideo((int) $m['id'], $m['ruta']);
        unset($m['ruta']);
        return $m;
    }

    /** Los espacios que eligio la pauta: horario, salidas por dia, precio pactado, subtotal y salidas ya usadas. */
    public function espaciosDe(int $idPauta): array
    {
        return Bd::filas("SELECT e.id_espacio AS id, e.nombre, to_char(e.hora_inicio, 'HH24:MI') AS hora_inicio, to_char(e.hora_fin, 'HH24:MI') AS hora_fin,"
            . ' pe.salidas_por_dia, pe.precio_segundo::float AS precio_segundo, pe.salidas_por_dia * (pa.fecha_fin - pa.fecha_inicio + 1) AS salidas,'
            . ' round(pe.salidas_por_dia * (pa.fecha_fin - pa.fecha_inicio + 1) * pa.duracion_spot * pe.precio_segundo, 2)::float AS subtotal,'
            . " (SELECT count(*) FROM spot s WHERE s.id_pauta = pa.id_pauta AND s.estado <> 'NO_EMITIDO' AND s.hora >= e.hora_inicio AND s.hora < e.hora_fin) AS usadas"
            . ' FROM pauta_espacio pe JOIN espacio e ON e.id_espacio = pe.id_espacio JOIN pauta pa ON pa.id_pauta = pe.id_pauta'
            . ' WHERE pe.id_pauta = ? ORDER BY e.hora_inicio', [$idPauta]);
    }

    /** mosPau["pauta"]: ficha + espacios + contrato y estado de cuenta + spots. */
    public function mostrar(Usuario $quien, array $p): array
    {
        $f = $this->completar($this->obtener($this->resolver($p[0] ?? null)));
        return ['titulo' => 'Pauta ' . $f['nombre'], 'datos' => $f];
    }

    /** Ficha completa: espacios, spots, estado de cuenta, video y salidas. */
    private function completar(array $f): array
    {
        $id = (int) $f['id'];
        $f['espacios'] = $this->espaciosDe($id);
        $f['spots'] = Bd::filas(self::SELECT_SPOT . ' WHERE s.id_pauta = ? ORDER BY pr.fecha, s.hora', [$id]);
        $f['cuenta'] = $this->contratos->cuenta($id);
        $f['estado_pago'] = $f['cuenta']['estado'];
        $f['spot'] = $this->spotAsignado($id);
        $f['salidas_detalle'] = $f['estado'] === self::APROBADA ? $this->salidas($id) : [];
        return $f;
    }

    /**
     * insPau["nombre","anunciante","fecha_inicio","fecha_fin","espacios","duracion_spot","frecuencia","cuotas","spot"]:
     * el Administrador registra una pauta ya APROBADA para un anunciante (CI o email) con un material SPOT ya registrado.
     * espacios: "espacio:salidas por dia" separados por coma, p. ej. "Noche:3,Mañana:2" (nombre o id del espacio).
     */
    public function insertar(Usuario $quien, array $p): array
    {
        $v = new Validador();
        $nombre = $v->texto($p[0] ?? null, 'nombre', 150);
        $refAnunciante = $v->texto($p[1] ?? null, 'anunciante', 150);
        $c = $this->leerPauta($v, $p[2] ?? null, $p[3] ?? null, $p[6] ?? null, $p[7] ?? null);
        $duracion = $v->entero($p[5] ?? null, 'duracion_spot', 1, 300);
        $refSpot = $v->texto($p[8] ?? null, 'spot', 160);
        $v->verificar();
        $elegidos = $this->elegirEspacios($v, $p[4] ?? null, $duracion, $c['inicio'], $c['fin'], null);
        $idAnunciante = Bd::valor("SELECT u.id_usuario FROM usuario u JOIN rol r ON r.id_rol = u.id_rol WHERE r.nombre = 'ANUNCIANTE' AND u.activo"
            . ' AND (u.ci = ? OR lower(u.email) = lower(?))', [$refAnunciante, $refAnunciante]);
        if ($idAnunciante === null) {
            $v->error('No existe un anunciante activo con CI o correo <b>' . Validador::esc($refAnunciante) . '</b>.');
        }
        $idSpot = $this->materiales->buscarId($refSpot);
        if ($idSpot === null) {
            $v->error('No existe el material <b>' . Validador::esc($refSpot) . '</b>. Registre primero el video como material de tipo SPOT.');
        } else {
            $m = $this->materiales->obtener($idSpot);
            $tit = Validador::esc($m['titulo']);
            if ($m['tipo'] !== 'SPOT') {
                $v->error("El material <b>{$tit}</b> no es un spot.");
            } elseif ((int) $m['duracion_seg'] > $duracion) {
                $v->error("El spot <b>{$tit}</b> dura {$m['duracion_seg']} s y se contratan spots de {$duracion} s.");
            }
        }
        $this->verificarNombre($v, $nombre);
        $v->verificar();
        $id = DB::transaction(function () use ($nombre, $idAnunciante, $idSpot, $c, $duracion, $elegidos) {
            $id = $this->crear((int) $idAnunciante, $idSpot, $nombre, $c, $duracion, $elegidos, self::APROBADA);
            $this->contratos->crearCuotas($id);
            return $id;
        });
        $f = $this->obtener($id);
        $plan = self::plan_texto($c['frecuencia'], $c['cuotas']);
        $this->bitacora->accion($quien, BitacoraService::INSERTAR, 'pauta', $nombre);
        return ['titulo' => 'Pauta registrada',
            'mensaje' => 'La pauta <b>' . Validador::esc($nombre) . "</b> fue registrada: {$f['salidas']} salidas de {$duracion} s en "
                . self::textoEspacios($elegidos) . ' = <b>' . $this->contratos->dinero($f['costo']) . "</b>, {$plan}. Queda <b>PENDIENTE</b> hasta que el anunciante pague la primera cuota.",
            'datos' => $f];
    }

    /** eliPau["pauta"]: cancela una pauta aprobada; sus salidas futuras se retiran (lo emitido y lo pagado quedan como historial). */
    public function eliminar(Usuario $quien, array $p): array
    {
        $id = $this->resolver($p[0] ?? null);
        $actual = $this->obtener($id);
        $nombre = Validador::esc($actual['nombre']);
        if ($actual['estado'] !== self::APROBADA) {
            throw new NegocioException("La pauta <b>{$nombre}</b> está <b>{$actual['estado']}</b>: solo se cancelan pautas aprobadas.");
        }
        $retirados = DB::transaction(function () use ($id) {
            Bd::ejecutar("UPDATE pauta SET estado = 'CANCELADA' WHERE id_pauta = ?", [$id]);
            return $this->retirarFuturos($id);
        });
        $this->bitacora->accion($quien, BitacoraService::ANULAR, 'pauta', $actual['nombre']);
        return ['titulo' => 'Pauta cancelada',
            'mensaje' => "La pauta <b>{$nombre}</b> fue cancelada." . ($retirados ? " Se retiraron sus {$retirados} salida(s) que faltaban de la parrilla." : ''),
            'datos' => $this->obtener($id)];
    }

    // ------------------------------------------------------------------ alta comun (insPau y la solicitud del anunciante)

    /** Vigencia y plan de pago: lo que comparten insPau e insSol. */
    public function leerPauta(Validador $v, mixed $inicio, mixed $fin, mixed $frecuencia, mixed $cuotas): array
    {
        $c = ['inicio' => $v->fecha($inicio, 'fecha_inicio'), 'fin' => $v->fecha($fin, 'fecha_fin')];
        [$c['frecuencia'], $c['cuotas']] = ContratoService::leerPlan($v, $frecuencia, $cuotas);
        if ($c['inicio'] !== null && $c['fin'] !== null && $c['fin'] < $c['inicio']) {
            $v->error("La <b>fecha_fin</b> ({$c['fin']}) no puede ser anterior a la <b>fecha_inicio</b> ({$c['inicio']}).");
        }
        return $c;
    }

    /**
     * Los espacios elegidos ("espacio:salidas por dia,..."), validados y con cupo: cada uno con su precio actual y su
     * subtotal (salidas por dia x dias x duracion x precio). $excluir: la pauta que se revisa (no cuenta contra si misma).
     */
    public function elegirEspacios(Validador $v, mixed $texto, int $duracion, string $inicio, string $fin, ?int $excluir): array
    {
        $crudo = Validador::limpiar($texto);
        if ($crudo === '') {
            $v->error('Elija al menos un <b>espacio publicitario</b> con sus salidas por día.');
            $v->verificar();
        }
        $dias = (int) CarbonImmutable::parse($inicio)->diffInDays(CarbonImmutable::parse($fin)) + 1;
        $libres = array_column($this->espacios->disponibles($inicio, $fin, $excluir), null, 'id');
        $elegidos = [];
        $malos = 0;
        foreach (preg_split('/[,;]/', $crudo) as $parte) {
            if (trim($parte) === '') {
                continue;
            }
            $malos++;
            [$ref, $n] = array_map('trim', explode(':', $parte, 2) + [1 => '']);
            $id = $this->espacios->buscarId($ref);
            if ($id === null) {
                $v->error('No existe el espacio <b>' . Validador::esc($ref) . '</b>.');
                continue;
            }
            $e = $libres[$id];
            $salidas = $v->entero($n, 'salidas por día de ' . $e['nombre'], 1, 20);
            if ($salidas === null) {
                continue;
            }
            if (isset($elegidos[$id])) {
                $v->error('El espacio <b>' . Validador::esc($e['nombre']) . '</b> está repetido.');
                continue;
            }
            $pide = $salidas * $duracion;
            if ($pide > (int) $e['libre']) {
                $v->error('El espacio <b>' . Validador::esc($e['nombre']) . "</b> ({$e['hora_inicio']} a {$e['hora_fin']}) solo tiene <b>{$e['libre']} s libres</b> por día en esas fechas"
                    . " y se piden {$pide} s ({$salidas} × {$duracion} s): máximo " . intdiv((int) $e['libre'], $duracion) . ' salida(s) por día.');
                continue;
            }
            $malos--;
            $elegidos[$id] = ['id' => $id, 'nombre' => $e['nombre'], 'salidas_por_dia' => $salidas, 'precio_segundo' => (float) $e['precio_segundo'],
                'subtotal' => round($salidas * $dias * $duracion * (float) $e['precio_segundo'], 2)];
        }
        if ($elegidos === [] && $malos === 0) {
            $v->error('Elija al menos un <b>espacio publicitario</b> con sus salidas por día.');
        }
        $v->verificar();
        return array_values($elegidos);
    }

    public function verificarNombre(Validador $v, ?string $nombre): void
    {
        if ($nombre !== null && Bd::existe("SELECT 1 FROM pauta WHERE lower(nombre) = lower(?) AND estado IN ('EN REVISION', 'APROBADA')", [$nombre])) {
            $v->error('Ya existe una pauta con el nombre <b>' . Validador::esc($nombre) . '</b>: elija otro nombre.');
        } elseif ($nombre !== null && Bd::existe('SELECT 1 FROM pauta WHERE lower(nombre) = lower(?)', [$nombre])) {
            $v->error('El nombre <b>' . Validador::esc($nombre) . '</b> ya se usó en una pauta anterior: elija otro nombre.');
        }
    }

    /** Inserta la pauta con sus espacios (costo = suma de los subtotales). Se crea EN REVISION y, si corresponde, se aprueba. */
    public function crear(int $idUsuario, int $idMaterial, string $nombre, array $c, int $duracion, array $elegidos, string $estado): int
    {
        $id = Bd::insertar('INSERT INTO pauta (id_usuario, id_material, nombre, fecha_inicio, fecha_fin, duracion_spot, costo_total,'
            . ' frecuencia_pago, numero_cuotas) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$idUsuario, $idMaterial, $nombre, $c['inicio'], $c['fin'], $duracion, round(array_sum(array_column($elegidos, 'subtotal')), 2),
                $c['frecuencia'], $c['cuotas']], 'id_pauta');
        foreach ($elegidos as $e) {
            Bd::ejecutar('INSERT INTO pauta_espacio (id_pauta, id_espacio, salidas_por_dia, precio_segundo) VALUES (?, ?, ?, ?)',
                [$id, $e['id'], $e['salidas_por_dia'], $e['precio_segundo']]);
        }
        if ($estado !== self::EN_REVISION) {
            Bd::ejecutar('UPDATE pauta SET estado = ? WHERE id_pauta = ?', [$estado, $id]);
        }
        return $id;
    }

    /** "Noche (3 por día) y Mañana (2 por día)". */
    public static function textoEspacios(array $espacios): string
    {
        $t = array_map(fn ($e) => Validador::esc($e['nombre']) . " ({$e['salidas_por_dia']} por día)", $espacios);
        return count($t) > 1 ? implode(', ', array_slice($t, 0, -1)) . ' y ' . end($t) : ($t[0] ?? '');
    }

    public static function plan_texto(string $frecuencia, int $cuotas): string
    {
        return $frecuencia === 'CONTADO' ? 'un solo pago' : "{$cuotas} cuotas " . strtolower($frecuencia) . 'es';
    }

    // ------------------------------------------------------------------ programacion automatica de los spots

    /**
     * Pone el video de la pauta en la parrilla, solo (ver plan()). Solo con la pauta APROBADA y al dia con su pago; si no,
     * sus salidas futuras se retiran. Devuelve cuantos spots inserto.
     */
    public function programar(int $idPauta, ?Usuario $quien = null): int
    {
        $pa = Bd::fila('SELECT nombre, estado FROM pauta WHERE id_pauta = ?', [$idPauta]);
        if ($pa['estado'] !== self::APROBADA || !in_array($this->contratos->cuenta($idPauta)['estado'], ContratoService::CON_SPOTS, true)) {
            $this->retirarFuturos($idPauta);
            return 0;
        }
        $insertados = 0;
        foreach ($this->plan($idPauta) as $s) {
            if ($s['estado'] !== 'LISTA') {
                continue;
            }
            Bd::ejecutar('INSERT INTO spot (id_pauta, id_bloque, hora) VALUES (?, ?, ?)', [$idPauta, $s['id_bloque'], $s['hora_spot']]);
            $this->bitacora->accion($quien, BitacoraService::INSERTAR, 'spot', "{$pa['nombre']} {$s['fecha']} {$s['hora_spot']}");
            $insertados++;
        }
        return $insertados;
    }

    /** Programa todas las pautas aprobadas (lo llama la tarea programada, el registro de pagos y las parrillas). */
    public function programarTodas(?Usuario $quien = null): int
    {
        $n = 0;
        foreach (Bd::filas("SELECT id_pauta FROM pauta WHERE estado = 'APROBADA' ORDER BY id_pauta") as $f) {
            $n += $this->programar((int) $f['id_pauta'], $quien);
        }
        return $n;
    }

    /**
     * Las salidas que faltan, por espacio y en orden de fecha y hora, cada una con su estado:
     *  LISTA        hay una pausa comercial del espacio con lugar (programar() la inserta).
     *  SIN PARRILLA todavia no existe la parrilla del dia.
     *  EN ESPERA    la pauta no esta al dia con su pago.
     * Cada dia se ponen sus salidas por dia; si faltan salidas de dias anteriores (no emitidas o sin lugar), se reponen
     * lo antes posible en el mismo espacio. Se saltan las pausas que ya empezaron, canceladas, conciliadas, llenas, fuera
     * del horario del espacio o donde ya esta la pauta; y no se pasa el cupo de segundos del espacio en el dia.
     */
    private function plan(int $idPauta): array
    {
        $pa = Bd::fila("SELECT to_char(fecha_inicio, 'YYYY-MM-DD') AS inicio, to_char(fecha_fin, 'YYYY-MM-DD') AS fin, estado, duracion_spot,"
            . ' (SELECT m.duracion_seg FROM material m WHERE m.id_material = pauta.id_material) AS video FROM pauta WHERE id_pauta = ?', [$idPauta]);
        if ($pa['estado'] !== self::APROBADA) {
            return [];
        }
        $desde = max($pa['inicio'], Reloj::hoy());
        if ($desde > $pa['fin']) {
            return [];
        }
        $alDia = in_array($this->contratos->cuenta($idPauta)['estado'], ContratoService::CON_SPOTS, true);
        $ahora = Reloj::ahora();
        $dur = (int) $pa['duracion_spot'];
        $dias = [];
        for ($f = CarbonImmutable::parse($desde, Reloj::zona()); $f->format('Y-m-d') <= $pa['fin']; $f = $f->addDay()) {
            $dias[] = $f->format('Y-m-d');
        }
        $total = (int) CarbonImmutable::parse($pa['inicio'])->diffInDays(CarbonImmutable::parse($pa['fin'])) + 1;
        $plan = [];
        foreach (Bd::filas("SELECT e.nombre, to_char(e.hora_inicio, 'HH24:MI:SS') AS inicio, to_char(e.hora_fin, 'HH24:MI:SS') AS fin,"
            . ' e.segundos_por_dia, pe.salidas_por_dia FROM pauta_espacio pe JOIN espacio e ON e.id_espacio = pe.id_espacio'
            . ' WHERE pe.id_pauta = ? ORDER BY e.hora_inicio', [$idPauta]) as $e) {
            $porDia = [];
            foreach (Bd::filas("SELECT to_char(pr.fecha, 'YYYY-MM-DD') AS fecha, count(*) AS n FROM spot s JOIN bloque b ON b.id_bloque = s.id_bloque"
                . " JOIN parrilla pr ON pr.id_parrilla = b.id_parrilla WHERE s.id_pauta = ? AND s.estado <> 'NO_EMITIDO'"
                . ' AND s.hora >= ?::time AND s.hora < ?::time GROUP BY pr.fecha', [$idPauta, $e['inicio'], $e['fin']]) as $r) {
                $porDia[$r['fecha']] = (int) $r['n'];
            }
            $salidas = (int) $e['salidas_por_dia'];
            $quedan = $salidas * $total - array_sum($porDia);
            foreach ($dias as $i => $fecha) {
                if ($quedan <= 0) {
                    break;
                }
                // lo normal de hoy, mas lo que no entra en los dias que siguen (reposicion de salidas perdidas)
                $despues = 0;
                foreach (array_slice($dias, $i + 1) as $g) {
                    $despues += max(0, $salidas - ($porDia[$g] ?? 0));
                }
                $quiero = min($quedan, max($salidas - ($porDia[$fecha] ?? 0), $quedan - $despues));
                if ($quiero <= 0) {
                    continue;
                }
                $parrilla = Bd::valor('SELECT id_parrilla FROM parrilla WHERE fecha = ?', [$fecha]);
                if ($parrilla === null) {
                    for ($k = 0; $k < $quiero; $k++) {
                        $plan[] = ['fecha' => $fecha, 'hora' => '', 'espacio' => $e['nombre'], 'estado' => $alDia ? 'SIN PARRILLA' : 'EN ESPERA'];
                    }
                    $quedan -= $quiero;
                    continue;
                }
                $ocupado = (int) Bd::valor('SELECT coalesce(sum(p2.duracion_spot), 0) FROM spot s JOIN pauta p2 ON p2.id_pauta = s.id_pauta'
                    . " JOIN bloque b ON b.id_bloque = s.id_bloque WHERE b.id_parrilla = ? AND s.estado <> 'NO_EMITIDO'"
                    . ' AND s.hora >= ?::time AND s.hora < ?::time', [$parrilla, $e['inicio'], $e['fin']]);
                $pausas = Bd::filas("SELECT b.id_bloque, to_char(b.hora_inicio, 'HH24:MI:SS') AS inicio, to_char(b.hora_fin, 'HH24:MI:SS') AS fin,"
                    . ' (SELECT max(s.hora + make_interval(secs => m.duracion_seg)) FROM spot s JOIN pauta p2 ON p2.id_pauta = s.id_pauta'
                    . "   JOIN material m ON m.id_material = p2.id_material WHERE s.id_bloque = b.id_bloque AND s.estado <> 'NO_EMITIDO')::time AS fin_ocupado"
                    . " FROM bloque b WHERE b.id_parrilla = ? AND b.tipo = 'PAUSA_COMERCIAL' AND b.estado <> 'CANCELADO'"
                    . ' AND b.hora_inicio >= ?::time AND b.hora_fin <= ?::time'
                    . ' AND NOT EXISTS (SELECT 1 FROM conciliacion c WHERE c.id_bloque = b.id_bloque)'
                    . " AND NOT EXISTS (SELECT 1 FROM spot s WHERE s.id_bloque = b.id_bloque AND s.id_pauta = ? AND s.estado <> 'NO_EMITIDO')"
                    . ' ORDER BY b.hora_inicio', [$parrilla, $e['inicio'], $e['fin'], $idPauta]);
                $puestas = 0;
                foreach ($pausas as $b) {
                    if ($puestas >= $quiero || $ocupado + $dur > (int) $e['segundos_por_dia']) {
                        break;
                    }
                    if (Reloj::instante($fecha, $b['inicio'])->lessThanOrEqualTo($ahora)) {
                        continue; // ya empezo
                    }
                    $horaSpot = $b['fin_ocupado'] ? substr((string) $b['fin_ocupado'], 0, 8) : $b['inicio'];
                    if (Validador::segundos($horaSpot) + (int) $pa['video'] > Validador::segundos($b['fin'])) {
                        continue; // la pausa esta llena
                    }
                    $salida = ['fecha' => $fecha, 'hora' => substr($b['inicio'], 0, 5), 'espacio' => $e['nombre']];
                    $plan[] = $salida + ($alDia ? ['estado' => 'LISTA', 'id_bloque' => (int) $b['id_bloque'], 'hora_spot' => $horaSpot] : ['estado' => 'EN ESPERA']);
                    $ocupado += $dur;
                    $puestas++;
                }
                $quedan -= $puestas;
            }
        }
        usort($plan, fn ($a, $b) => [$a['fecha'], $a['hora']] <=> [$b['fecha'], $b['hora']]);
        return $plan;
    }

    /** Todas las salidas de la pauta: las que ya estan en la parrilla (con su estado) y las que faltan (SIN PARRILLA / EN ESPERA). */
    public function salidas(int $idPauta): array
    {
        $hechas = array_map(fn ($s) => [
            'fecha' => $s['fecha'], 'hora' => substr($s['hora'], 0, 8), 'pausa' => $s['pausa'], 'espacio' => $s['espacio'], 'material' => $s['material'],
            'titulo' => $s['titulo'], 'estado' => $s['estado'],
        ], Bd::filas(self::SELECT_SPOT . ' WHERE s.id_pauta = ? ORDER BY pr.fecha, s.hora', [$idPauta]));
        $faltan = array_map(fn ($s) => ['fecha' => $s['fecha'], 'hora' => $s['hora'] === '' ? '' : "{$s['hora']}:00", 'pausa' => '', 'espacio' => $s['espacio'],
            'material' => '', 'titulo' => '', 'estado' => $s['estado'] === 'LISTA' ? 'SIN PARRILLA' : $s['estado']], $this->plan($idPauta));
        $todas = [...$hechas, ...$faltan];
        usort($todas, fn ($a, $b) => [$a['fecha'], $a['hora']] <=> [$b['fecha'], $b['hora']]);
        return $todas;
    }

    /** Quita de la parrilla los spots de la pauta que todavia no salieron (pausa sin empezar y sin conciliar). */
    private function retirarFuturos(int $idPauta): int
    {
        $n = 0;
        foreach (Bd::filas("SELECT s.id_bloque, to_char(s.hora, 'HH24:MI:SS') AS hora_spot, to_char(pr.fecha, 'YYYY-MM-DD') AS fecha, to_char(b.hora_inicio, 'HH24:MI:SS') AS inicio"
            . ' FROM spot s JOIN bloque b ON b.id_bloque = s.id_bloque JOIN parrilla pr ON pr.id_parrilla = b.id_parrilla'
            . " WHERE s.id_pauta = ? AND s.estado = 'PROGRAMADO' AND NOT EXISTS (SELECT 1 FROM conciliacion c WHERE c.id_bloque = b.id_bloque)", [$idPauta]) as $s) {
            if (Reloj::instante($s['fecha'], $s['inicio'])->greaterThan(Reloj::ahora())) {
                Bd::ejecutar('DELETE FROM spot WHERE id_bloque = ? AND hora = ?', [$s['id_bloque'], $s['hora_spot']]);
                $n++;
            }
        }
        return $n;
    }

    // ------------------------------------------------------------------ auxiliares

    public function obtener(int $id): array
    {
        $f = Bd::fila(self::SELECT . ' WHERE pa.id_pauta = ?', [$id]);
        if ($f === null) {
            throw new NegocioException('La pauta no existe.', 404);
        }
        return $f;
    }

    public function buscarId(string $ref): ?int
    {
        $id = Bd::valor('SELECT id_pauta FROM pauta WHERE lower(nombre) = lower(?)', [$ref]);
        if ($id === null && preg_match('/^\d{1,9}$/', $ref)) {
            $id = Bd::valor('SELECT id_pauta FROM pauta WHERE id_pauta = ?', [(int) $ref]);
        }
        return $id === null ? null : (int) $id;
    }

    public function resolver(mixed $referencia): int
    {
        $v = new Validador();
        $ref = $v->texto($referencia, 'pauta', 150);
        $v->verificar();
        $id = $this->buscarId($ref);
        if ($id === null) {
            throw new NegocioException('No existe una pauta con el nombre <b>' . Validador::esc($ref) . '</b>.', 404);
        }
        return $id;
    }
}
