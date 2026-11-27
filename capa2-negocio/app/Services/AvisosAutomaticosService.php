<?php

namespace App\Services;

use App\Support\Bd;
use App\Support\Reloj;
use App\Support\Validador;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Avisos automaticos (antes: hilo "avisos automaticos" del sistema por correo; ahora: tarea programada de Laravel cada minuto).
 *  - PARRILLA EN RIESGO (CU5): alerta.parrilla_horas antes del dia, si no existe, sigue en BORRADOR o tiene bloques
 *    que no estan validados como LISTO. A alerta.parrilla_urgente_horas vuelve a avisar como URGENTE.
 *    Al Administrador (+ Encargado de Contenidos, que valida los bloques, si faltan validaciones).
 *  - BLOQUE SIN CONCILIAR (CU7): bloque PUBLICADO que termino hace mas de alerta.emision_minutos sin conciliacion -> Programador.
 * Cada alerta se envia una sola vez: no se repite si ya existe una notificacion con su mismo titulo.
 */
class AvisosAutomaticosService
{
    public function __construct(
        private NotificacionService $avisos,
        private ValidacionService $validacion,
    ) {
    }

    /** Revisa todo. Un error en una revision no impide las demas. Devuelve un resumen para la consola. */
    public function revisar(): array
    {
        $resumen = [];
        foreach ([
            // los bloques LISTOS que ya empezaron salieron al aire: pasan a TRANSMITIDO
            'bloques_transmitidos' => fn () => app(ParrillaService::class)->marcarTransmitidos(),
            'parrillas_en_riesgo' => fn () => $this->parrillasEnRiesgo(),
            'emisiones_sin_registrar' => fn () => $this->emisionesSinRegistrar(),
            // pagos con Stripe que se confirmaron aunque el anunciante cerró la página (antes de programar los spots)
            'pagos_stripe' => fn () => config('canal11.stripe.secreta') ? app(ContratoService::class)->revisarCobros() : 0,
            // los spots de las pautas se programan solos (y se retiran si la pauta queda suspendida)
            'spots_programados' => fn () => app(PautaService::class)->programarTodas(),
        ] as $nombre => $tarea) {
            try {
                $resumen[$nombre] = $tarea();
            } catch (Throwable $e) {
                Log::error("[Avisos] {$nombre}: " . $e->getMessage());
                $resumen[$nombre] = 'error: ' . $e->getMessage();
            }
        }
        return $resumen;
    }

    // ------------------------------------------------------------------ parrilla en riesgo

    private function parrillasEnRiesgo(): int
    {
        $horas = (int) config('canal11.alerta.parrilla_horas');
        if ($horas <= 0) {
            return 0;
        }
        $urgente = (int) config('canal11.alerta.parrilla_urgente_horas');
        $ahora = Reloj::ahora();
        $enviadas = 0;
        for ($i = 0; $i <= intdiv($horas, 24) + 1; $i++) {
            $dia = $ahora->startOfDay()->addDays($i);
            $fecha = $dia->format('Y-m-d');
            if ($ahora->greaterThanOrEqualTo($dia->addDay())) {
                continue;
            }
            $nivel = 0;
            if ($urgente > 0 && $ahora->greaterThanOrEqualTo($dia->subHours($urgente))) {
                $nivel = 2;
            } elseif ($ahora->greaterThanOrEqualTo($dia->subHours($horas))) {
                $nivel = 1;
            }
            if ($nivel === 0) {
                continue;
            }
            $titulo = ($nivel === 2 ? 'URGENTE: parrilla del ' : 'Parrilla en riesgo: ') . $fecha;
            if ($this->avisos->yaEnviado($titulo)) {
                continue;
            }
            [$problemas, $conPendientes] = $this->evaluar($fecha);
            if ($problemas === []) {
                continue;
            }
            $roles = $conPendientes ? ['ADMINISTRADOR', 'ENCARGADO'] : ['ADMINISTRADOR'];
            $cuerpo = "La parrilla del <b>{$fecha}</b> " . ($nivel === 2 ? '<b>sigue sin estar lista</b> y el día está por empezar.'
                    : 'todavía no está lista para salir al aire.') . ' Pendientes:' . NotificacionService::lista($problemas);
            if ($this->avisos->enviar($this->avisos->usuariosDeRoles(null, ...$roles), $titulo, $cuerpo) !== []) {
                $enviadas++;
            }
        }
        return $enviadas;
    }

    /** [problemas, faltan validaciones del CU5 (se avisa tambien a Prensa)]. */
    public function evaluar(string $fecha): array
    {
        if (!Bd::existe('SELECT 1 FROM parrilla WHERE fecha = ?', [$fecha])) {
            return [['Todavía no existe la parrilla.'], false];
        }
        $estado = $this->validacion->estadoDeParrilla($fecha);
        $problemas = [];
        if ($estado['parrilla_estado'] !== 'PUBLICADA') {
            $problemas[] = 'Sigue en BORRADOR: falta publicarla.';
        }
        if ($estado['bloques'] === []) {
            $problemas[] = 'No tiene bloques.';
        }
        $sinValidar = false;
        foreach ($estado['bloques'] as $b) {
            if ($b['estado'] === ValidacionService::LISTO) {
                continue;
            }
            $sinValidar = true;
            $problemas[] = 'Bloque ' . Validador::corta($b['hora_inicio']) . '-' . Validador::corta($b['hora_fin']) . " ({$b['nombre']}): {$b['estado']}"
                . ($b['observacion'] !== '' ? " - {$b['observacion']}" : ($b['problemas'] !== [] ? ' - ' . implode(' ', $b['problemas']) : ''));
        }
        return [$problemas, $sinValidar];
    }

    // ------------------------------------------------------------------ emision sin registrar

    private function emisionesSinRegistrar(): int
    {
        $minutos = (int) config('canal11.alerta.emision_minutos');
        if ($minutos <= 0) {
            return 0;
        }
        $ahora = Reloj::ahora();
        $bloques = Bd::filas("SELECT b.id_bloque AS id, to_char(pr.fecha, 'YYYY-MM-DD') AS fecha, to_char(b.hora_inicio, 'HH24:MI') AS inicio,"
            . " to_char(b.hora_fin, 'HH24:MI') AS fin, b.tipo, coalesce(p.nombre, '') AS programa"
            . ' FROM bloque b JOIN parrilla pr ON pr.id_parrilla = b.id_parrilla LEFT JOIN programa p ON p.id_programa = b.id_programa'
            . " WHERE pr.estado = 'PUBLICADA' AND NOT EXISTS (SELECT 1 FROM conciliacion c WHERE c.id_bloque = b.id_bloque)"
            . ' AND (pr.fecha + b.hora_fin) >= ? AND (pr.fecha + b.hora_fin) <= ? ORDER BY pr.fecha, b.hora_inicio',
            [$ahora->subHours((int) config('canal11.alerta.emision_ventana_horas'))->format('Y-m-d H:i:s'), $ahora->subMinutes($minutos)->format('Y-m-d H:i:s')]);
        // un aviso por bloque: su titulo lo identifica y evita repetirlo
        $enviados = 0;
        foreach ($bloques as $b) {
            $titulo = "Bloque sin conciliar: {$b['fecha']} {$b['inicio']} - {$b['fin']}";
            if ($this->avisos->yaEnviado($titulo)) {
                continue;
            }
            $cuerpo = 'Este bloque ya terminó y <b>no tiene su conciliación</b>. Registre lo que realmente salió al aire:'
                . NotificacionService::tabla(['Fecha', 'Horario', 'Tipo', 'Programa'],
                    [array_map([NotificacionService::class, 'e'], [$b['fecha'], "{$b['inicio']} - {$b['fin']}", $b['tipo'], $b['programa']])]);
            if ($this->avisos->enviar($this->avisos->usuariosDeRoles(null, 'PROGRAMADOR'), $titulo, $cuerpo) !== []) {
                $enviados++;
            }
        }
        return $enviados;
    }
}
