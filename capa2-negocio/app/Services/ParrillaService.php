<?php

namespace App\Services;

use App\Models\Usuario;
use App\Support\Bd;
use App\Support\NegocioException;
use App\Support\Reloj;
use App\Support\Validador;

/**
 * CU3 - Confeccionar y Controlar la Parrilla de Programacion (lisPar, mosPar, insPar, eliPar, insBlo, eliBlo, camBlo, pubPar).
 * Reglas de negocio (iguales al sistema por correo):
 *  - Una parrilla por dia; se crea en BORRADOR y no puede ser de una fecha pasada.
 *  - Solo en BORRADOR se agregan o quitan bloques y se elimina la parrilla (sin spots programados).
 *  - Bloque PROGRAMA lleva programa; PAUSA_COMERCIAL no lleva programa ni material. Sin solapes.
 *  - El material de un bloque PROGRAMA no es SPOT, es del mismo programa y no dura mas que el bloque.
 *  - pubPar: sin bloques, con materiales sin asignar, o con algun bloque que no este LISTO en la
 *    validacion del CU5 (la hace el Encargado de Contenidos con la parrilla en BORRADOR) no se publica (errores);
 *    los huecos son avisos.
 *  - camBlo: cambia el material de un bloque aun con la parrilla PUBLICADA mientras el bloque no haya empezado,
 *    y avisa al Programador y a Prensa. El cambio deja la validacion del bloque PENDIENTE: hay que volver a validarlo.
 */
class ParrillaService
{
    public const TIPOS_BLOQUE = ['PROGRAMA', 'PAUSA_COMERCIAL'];

    private const SELECT = "SELECT p.id_parrilla AS id, to_char(p.fecha, 'YYYY-MM-DD') AS fecha, p.estado,"
        . " u.nombre || ' ' || u.apellido AS creado_por,"
        . " coalesce(to_char(p.fecha_publicacion, 'YYYY-MM-DD HH24:MI'), '') AS fecha_publicacion,"
        . ' (SELECT count(*) FROM bloque b WHERE b.id_parrilla = p.id_parrilla) AS bloques'
        . ' FROM parrilla p JOIN usuario u ON u.id_usuario = p.id_usuario';

    private const SELECT_BLOQUE = "SELECT b.id_bloque AS id, to_char(b.hora_inicio, 'HH24:MI:SS') AS hora_inicio,"
        . " to_char(b.hora_fin, 'HH24:MI:SS') AS hora_fin, b.tipo, b.estado AS estado_bloque, coalesce(p.nombre, '') AS programa, coalesce(p.tipo, '') AS tipo_programa,"
        . " coalesce(m.id_material::text, '') AS material, coalesce(m.titulo, '') AS titulo_material,"
        . ' m.duracion_seg AS duracion_material,'
        . ' (SELECT count(*) FROM escaleta e WHERE e.id_bloque = b.id_bloque) AS notas,'
        . ' (SELECT count(*) FROM spot s WHERE s.id_bloque = b.id_bloque) AS spots,'
        . ' extract(epoch FROM (b.hora_fin - b.hora_inicio))::int AS duracion_seg,'
        // cancelado a mano (sin conciliacion): su material es el de reemplazo (o ninguno)
        . " (b.estado = 'CANCELADO' AND NOT EXISTS (SELECT 1 FROM conciliacion cc WHERE cc.id_bloque = b.id_bloque)) AS cancelado,"
        . " CASE WHEN b.estado = 'CANCELADO' THEN coalesce(m.id_material::text, '') ELSE '' END AS reemplazo,"
        . " CASE WHEN b.estado = 'CANCELADO' THEN coalesce(m.titulo, '') ELSE '' END AS titulo_reemplazo,"
        . " b.estado_validacion, coalesce(b.observacion, '') AS observacion, coalesce(uv.nombre || ' ' || uv.apellido, '') AS validado_por"
        . ' FROM bloque b LEFT JOIN programa p ON p.id_programa = b.id_programa LEFT JOIN material m ON m.id_material = b.id_material'
        . ' LEFT JOIN usuario uv ON uv.id_usuario = b.id_usuario';

    public function __construct(
        private BitacoraService $bitacora,
        private NotificacionService $avisos,
        private MaterialService $materiales,
        private ProgramaService $programas,
    ) {
    }

    /** lisPar: "*" = todas; AAAA-MM = las de ese mes. */
    public function listar(Usuario $quien, array $p): array
    {
        $v = new Validador();
        $f = $v->texto($p[0] ?? '*', 'filtro', 10);
        $v->verificar();
        if ($f === '*') {
            return ['titulo' => 'Parrillas', 'datos' => Bd::filas(self::SELECT . ' ORDER BY p.fecha DESC')];
        }
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $f)) {
            throw new NegocioException('El filtro debe ser <b>*</b> (todas) o un mes con formato AAAA-MM, por ejemplo 2026-11 (recibido: \''
                . Validador::esc($f) . "').");
        }
        return ['titulo' => "Parrillas del mes {$f}",
            'datos' => Bd::filas(self::SELECT . " WHERE to_char(p.fecha, 'YYYY-MM') = ? ORDER BY p.fecha", [$f])];
    }

    /** mosPar["fecha"]: ficha + bloques en orden. */
    public function mostrar(Usuario $quien, array $p): array
    {
        $fecha = $this->fecha($p[0] ?? null);
        return ['titulo' => "Parrilla del {$fecha}", 'datos' => $this->vista($fecha)];
    }

    /** insPar["fecha"]. */
    public function insertar(Usuario $quien, array $p): array
    {
        $fecha = $this->fecha($p[0] ?? null);
        if ($fecha < Reloj::hoy()) {
            throw new NegocioException("No se puede crear la parrilla del <b>{$fecha}</b>: es una fecha pasada (hoy es " . Reloj::hoy() . ').');
        }
        if ($this->buscarId($fecha) !== null) {
            throw new NegocioException("Ya existe una parrilla para el <b>{$fecha}</b>.");
        }
        $id = Bd::insertar('INSERT INTO parrilla (fecha, id_usuario) VALUES (?, ?)', [$fecha, $quien->id_usuario], 'id_parrilla');
        $this->bitacora->accion($quien, BitacoraService::INSERTAR, 'parrilla');
        return ['titulo' => 'Parrilla creada', 'mensaje' => "La parrilla del <b>{$fecha}</b> fue creada en <b>BORRADOR</b>. Agregue sus bloques.",
            'datos' => $this->vista($fecha)];
    }

    /** eliPar["fecha"]. */
    public function eliminar(Usuario $quien, array $p): array
    {
        $fecha = $this->fecha($p[0] ?? null);
        $id = $this->resolver($fecha);
        $this->exigirBorrador($id, $fecha, 'eliminarla');
        // sus spots (programados solos) se borran con los bloques y el sistema los vuelve a programar en otra salida
        $cantidad = (int) Bd::valor('SELECT count(*) FROM bloque WHERE id_parrilla = ?', [$id]);
        Bd::ejecutar('DELETE FROM parrilla WHERE id_parrilla = ?', [$id]);
        $this->bitacora->accion($quien, BitacoraService::ELIMINAR, 'parrilla');
        return ['titulo' => 'Parrilla eliminada', 'mensaje' => "La parrilla del <b>{$fecha}</b> y sus {$cantidad} bloque(s) fueron eliminados.", 'datos' => null];
    }

    /** insBlo["fecha","hora_inicio","hora_fin","tipo","programa","material"]. */
    public function insertarBloque(Usuario $quien, array $p): array
    {
        $v = new Validador();
        $fecha = $v->fecha($p[0] ?? null, 'fecha');
        $inicio = $v->hora($p[1] ?? null, 'hora_inicio');
        $fin = $v->horaFin($p[2] ?? null, 'hora_fin');
        $tipo = $v->opcion($p[3] ?? null, 'tipo', self::TIPOS_BLOQUE);
        $programa = $v->textoOpcional($p[4] ?? null, 'programa', 120);
        $material = $v->textoOpcional($p[5] ?? null, 'material', 160); // id del material
        if ($inicio !== null && $fin !== null && $fin <= $inicio) {
            $v->error('La <b>hora_fin</b> (' . Validador::corta($fin) . ') debe ser posterior a la <b>hora_inicio</b> (' . Validador::corta($inicio) . ').');
        }
        if ($tipo === 'PROGRAMA' && $programa === null) {
            $v->error('Un bloque de tipo <b>PROGRAMA</b> necesita el nombre del programa.');
        }
        if ($tipo === 'PAUSA_COMERCIAL' && ($programa !== null || $material !== null)) {
            $v->error('Una <b>PAUSA_COMERCIAL</b> no lleva programa ni material; sus spots se insertan después desde la pauta.');
        }
        $v->verificar();

        $idParrilla = $this->resolver($fecha);
        $this->exigirBorrador($idParrilla, $fecha, 'agregarle bloques');
        $solape = Bd::valor("SELECT to_char(hora_inicio, 'HH24:MI:SS') || '-' || to_char(hora_fin, 'HH24:MI:SS') FROM bloque"
            . ' WHERE id_parrilla = ? AND hora_inicio < ? AND hora_fin > ? ORDER BY hora_inicio LIMIT 1', [$idParrilla, $fin, $inicio]);
        if ($solape !== null) {
            $v->error('El horario ' . Validador::corta($inicio) . '-' . Validador::corta($fin) . ' se solapa con el bloque existente ' . self::recortar($solape) . '.');
        }
        $idPrograma = null;
        if ($programa !== null) {
            $idPrograma = $this->programas->buscarId($programa);
            if ($idPrograma === null) {
                $v->error('No existe el programa <b>' . Validador::esc($programa) . '</b>. Consulte el catálogo de programas.');
            }
        }
        $idMaterial = null;
        if ($material !== null) {
            $idMaterial = $this->materiales->buscarId($material);
            if ($idMaterial === null) {
                $v->error('No existe el material <b>' . Validador::esc($material) . '</b>. Consulte la lista de materiales.');
            } else {
                $this->validarMaterialDelBloque($v, $this->materiales->obtener($idMaterial), $programa, $inicio, $fin);
            }
        }
        $v->verificar();

        $id = Bd::insertar('INSERT INTO bloque (id_parrilla, id_programa, id_material, hora_inicio, hora_fin, tipo) VALUES (?, ?, ?, ?, ?, ?)',
            [$idParrilla, $idPrograma, $idMaterial, $inicio, $fin, $tipo], 'id_bloque');
        $this->bitacora->accion($quien, BitacoraService::INSERTAR, 'bloque');
        // una pausa nueva: los spots de las pautas contratadas para ese dia y hora se ponen solos
        $spots = $tipo === 'PAUSA_COMERCIAL' ? app(PautaService::class)->programarTodas($quien) : 0;
        return ['titulo' => 'Bloque agregado',
            'mensaje' => "El bloque fue agregado a la parrilla del <b>{$fecha}</b>." . ($spots ? " Se programaron solos <b>{$spots}</b> spot(s) de las pautas contratadas." : ''),
            'datos' => $this->vista($fecha)];
    }

    /** eliBlo["fecha","hora_inicio"]. */
    public function eliminarBloque(Usuario $quien, array $p): array
    {
        $v = new Validador();
        $fecha = $v->fecha($p[0] ?? null, 'fecha');
        $inicio = $v->hora($p[1] ?? null, 'hora_inicio');
        $v->verificar();

        $idParrilla = $this->resolver($fecha);
        $this->exigirBorrador($idParrilla, $fecha, 'quitarle bloques');
        $idBloque = $this->buscarBloque($idParrilla, $inicio, $fecha);
        $b = $this->bloque($idBloque);
        Bd::ejecutar('DELETE FROM bloque WHERE id_bloque = ?', [$idBloque]);
        app(PautaService::class)->programarTodas($quien); // los spots que tenia esa pausa salen en otra
        $this->bitacora->accion($quien, BitacoraService::ELIMINAR, 'bloque');
        return ['titulo' => 'Bloque quitado', 'mensaje' => 'El bloque de las <b>' . Validador::corta($inicio) . '</b> fue quitado de la parrilla.',
            'datos' => $this->vista($fecha)];
    }

    /** pubPar["fecha"]: revisa (errores impiden publicar; huecos son avisos) y publica. */
    public function publicar(Usuario $quien, array $p): array
    {
        $fecha = $this->fecha($p[0] ?? null);
        if ($fecha < Reloj::hoy()) {
            throw new NegocioException("No se puede publicar la parrilla del <b>{$fecha}</b>: es una fecha pasada (hoy es " . Reloj::hoy() . ').');
        }
        $id = $this->resolver($fecha);
        $this->exigirBorrador($id, $fecha, 'publicarla de nuevo');
        $bloques = $this->bloques($id);

        $v = new Validador();
        $avisos = [];
        if ($bloques === []) {
            $v->error("La parrilla del <b>{$fecha}</b> no tiene bloques. Agregue bloques antes de publicar.");
        }
        // CU5: el Encargado de Contenidos tiene que haber dejado LISTO cada bloque
        $cursor = 0;
        $sinProgramar = 0;
        foreach ($bloques as $b) {
            $ini = Validador::segundos($b['hora_inicio']);
            $fin = Validador::segundos($b['hora_fin']);
            $horario = self::recortar($b['hora_inicio']) . '-' . self::recortar($b['hora_fin']);
            $estadoVal = $b['estado_validacion'];
            if ($estadoVal !== 'LISTO') {
                $v->error("El bloque {$horario} (" . Validador::esc($b['tipo'] === 'PAUSA_COMERCIAL' ? 'Pausa comercial' : $b['programa'])
                    . ") está <b>{$estadoVal}</b>: el Encargado de Contenidos debe validarlo como LISTO antes de publicar.");
            }
            if ($b['tipo'] === 'PROGRAMA' && (int) $b['notas'] === 0 && $b['material'] === '') {
                $v->error("El bloque {$horario} (" . Validador::esc($b['programa']) . ') no tiene material asignado.');
            }
            if ($ini > $cursor) {
                $avisos[] = 'Hueco sin programación de ' . self::etiqueta($cursor) . ' a ' . self::etiqueta($ini) . '.';
                $sinProgramar += $ini - $cursor;
            }
            $cursor = max($cursor, $fin);
        }
        $finDia = Validador::segundos(Validador::FIN_DEL_DIA);
        if ($cursor < $finDia) {
            $avisos[] = 'Hueco sin programación de ' . self::etiqueta($cursor) . ' a 24:00.';
            $sinProgramar += $finDia - $cursor + 1;
        }
        if ($sinProgramar > 0) {
            $cubierto = 86400 - $sinProgramar;
            array_unshift($avisos, 'Cobertura del día: ' . intdiv($cubierto * 100, 86400) . ' % programado; faltan '
                . intdiv($sinProgramar, 3600) . ' h ' . intdiv($sinProgramar % 3600, 60) . ' min sin programar (el canal emite 24 h).');
        }
        $v->verificar();

        Bd::ejecutar("UPDATE parrilla SET estado = 'PUBLICADA', fecha_publicacion = now() WHERE id_parrilla = ?", [$id]);
        $this->bitacora->accion($quien, BitacoraService::PUBLICAR, 'parrilla');

        // El orden de transmision llega a Produccion y Prensa (antes por correo; ya no hace falta mandarlo por WhatsApp).
        $this->avisos->enviar($this->avisos->usuariosDeRoles($quien->id_usuario, 'PROGRAMADOR', 'ENCARGADO'), "Parrilla publicada: {$fecha}", NotificacionService::e($quien->nombreCompleto()) . " publicó la parrilla del <b>{$fecha}</b>. Es la <b>única fuente</b> del orden de transmisión: "
            . 'si cambia, recibirá un nuevo aviso.' . $this->resumenCorreo($id) . ($avisos ? '<p><b>Avisos:</b></p>' . NotificacionService::lista($avisos) : ''));

        return ['titulo' => 'Parrilla publicada', 'mensaje' => "La parrilla del <b>{$fecha}</b> fue <b>PUBLICADA</b>. Es ahora la única fuente del orden de transmisión. Se avisó al Programador y a Prensa.",
            'avisos' => $avisos, 'datos' => $this->vista($fecha)];
    }

    /** camBlo["fecha","hora_inicio","material"]. */
    public function cambiarMaterial(Usuario $quien, array $p): array
    {
        $v = new Validador();
        $fecha = $v->fecha($p[0] ?? null, 'fecha');
        $inicio = $v->hora($p[1] ?? null, 'hora_inicio');
        $codigo = $v->texto($p[2] ?? null, 'material', 160); // id del material
        $v->verificar();

        $idParrilla = $this->resolver($fecha);
        $idBloque = $this->buscarBloque($idParrilla, $inicio, $fecha);
        $b = $this->bloque($idBloque);
        $hi = Validador::corta($inicio);
        if ($b['tipo'] !== 'PROGRAMA') {
            throw new NegocioException("El bloque de las <b>{$hi}</b> es una pausa comercial: no lleva material. Sus spots se cambian desde la pauta.");
        }
        if ((int) $b['notas'] > 0) {
            throw new NegocioException("El bloque de las <b>{$hi}</b> tiene una escaleta de {$b['notas']} nota(s): el orden y las notas se cambian en la escaleta.");
        }
        if (Reloj::instante($fecha, $b['hora_inicio'])->lessThanOrEqualTo(Reloj::ahora())) {
            throw new NegocioException("El bloque de las <b>{$hi}</b> del {$fecha} ya empezó: no se puede cambiar su material. Registre lo ocurrido en la emisión.");
        }
        if (Bd::existe('SELECT 1 FROM conciliacion WHERE id_bloque = ?', [$idBloque])) {
            throw new NegocioException("El bloque de las <b>{$hi}</b> ya está conciliado: no se puede cambiar.");
        }
        if ($b['cancelado']) {
            throw new NegocioException("El bloque de las <b>{$hi}</b> está CANCELADO: reanúdelo antes de cambiar su material.");
        }
        $idMaterial = $this->materiales->buscarId($codigo);
        if ($idMaterial === null) {
            throw new NegocioException('No existe el material <b>' . Validador::esc($codigo) . '</b>. Consulte la lista de materiales.');
        }
        $m = $this->materiales->obtener($idMaterial);
        $cod = Validador::esc($m['titulo']);
        if ((string) $m['id'] === $b['material']) {
            $v->error("El bloque de las {$hi} ya usa el material <b>{$cod}</b>.");
        }
        $this->validarMaterialDelBloque($v, $m, $b['programa'], $b['hora_inicio'], $b['hora_fin']);
        $v->verificar();

        Bd::ejecutar('UPDATE bloque SET id_material = ? WHERE id_bloque = ?', [$idMaterial, $idBloque]);
        $estado = Bd::valor('SELECT estado FROM parrilla WHERE id_parrilla = ?', [$idParrilla]);
        $anterior = $b['material'] === '' ? '(sin material)' : $b['titulo_material'];
        $this->bitacora->accion($quien, BitacoraService::MODIFICAR, 'bloque');

        $cambio = 'El bloque de <b>' . Validador::esc($b['programa']) . '</b> (' . Validador::corta($b['hora_inicio']) . ' - ' . Validador::corta($b['hora_fin'])
            . ') pasó del material <b>' . Validador::esc($anterior) . "</b> a <b>{$cod}</b>.";
        if ($estado === 'PUBLICADA') {
            $this->avisos->enviar($this->avisos->usuariosDeRoles($quien->id_usuario, 'PROGRAMADOR', 'ENCARGADO'), "Cambio en la parrilla del {$fecha}", NotificacionService::e($quien->nombreCompleto()) . ' (' . $quien->nombreRol() . ") hizo un cambio en la parrilla publicada del <b>{$fecha}</b>. "
                . $cambio . ' Este es el material que debe salir al aire. El bloque quedó <b>PENDIENTE</b> de validación: el Encargado de Contenidos debe volver a validarlo.'
                . $this->resumenCorreo($idParrilla));
        }
        return ['titulo' => 'Material del bloque cambiado',
            'mensaje' => $cambio . ' El bloque quedó <b>PENDIENTE</b> de validación: hay que volver a validarlo (CU5).'
                . ($estado === 'PUBLICADA' ? ' La parrilla ya está publicada: se avisó del cambio al Programador y a Prensa.' : ''),
            'datos' => $this->vista($fecha)];
    }

    // ------------------------------------------------------------------ consultas para otros servicios

    /** Ficha + bloques + spots por pausa. */
    public function vista(string $fecha): array
    {
        $id = $this->resolver($fecha);
        $ficha = Bd::fila(self::SELECT . ' WHERE p.id_parrilla = ?', [$id]);
        $ficha['bloques'] = $this->bloques($id);
        $ficha['spots'] = Bd::filas("SELECT s.id_bloque || '-' || to_char(s.hora, 'HH24:MI:SS') AS id, s.id_bloque, to_char(s.hora, 'HH24:MI:SS') AS hora, pa.nombre AS pauta,"
            . " ua.nombre || ' ' || ua.apellido AS anunciante, m.id_material AS material, m.titulo, m.duracion_seg, s.estado"
            . ' FROM spot s JOIN pauta pa ON pa.id_pauta = s.id_pauta JOIN usuario ua ON ua.id_usuario = pa.id_usuario'
            . ' JOIN material m ON m.id_material = pa.id_material JOIN bloque b ON b.id_bloque = s.id_bloque WHERE b.id_parrilla = ? ORDER BY s.hora', [$id]);
        $ficha['editable'] = $ficha['estado'] === 'BORRADOR';
        return $ficha;
    }

    /** Bloques de una parrilla ordenados por hora. */
    public function bloques(int $idParrilla): array
    {
        return Bd::filas(self::SELECT_BLOQUE . ' WHERE b.id_parrilla = ? ORDER BY b.hora_inicio', [$idParrilla]);
    }

    public function bloque(int $idBloque): array
    {
        return Bd::fila(self::SELECT_BLOQUE . ' WHERE b.id_bloque = ?', [$idBloque]);
    }

    public function buscarId(string $fecha): ?int
    {
        $id = Bd::valor('SELECT id_parrilla FROM parrilla WHERE fecha = ?', [$fecha]);
        return $id === null ? null : (int) $id;
    }

    public function resolver(string $fecha): int
    {
        $id = $this->buscarId($fecha);
        if ($id === null) {
            throw new NegocioException("No existe una parrilla para el <b>{$fecha}</b>. Créela o consulte las existentes.", 404);
        }
        $this->marcarTransmitidos($id); // quien mira la parrilla la ve con el estado de ahora
        return $id;
    }

    /**
     * Un bloque EN COLA de una parrilla PUBLICADA, validado LISTO y sin cancelar, que ya empezo, salio al aire:
     * pasa a TRANSMITIDO. (La conciliacion lo corrige despues: NO_EMITIDO -> CANCELADO; si se borra, vuelve a calcularse.)
     * Lo llaman la lectura de cada parrilla y la tarea programada. Devuelve cuantos bloques cambio.
     */
    public function marcarTransmitidos(?int $idParrilla = null): int
    {
        return Bd::ejecutar("UPDATE bloque b SET estado = 'TRANSMITIDO' FROM parrilla pr WHERE pr.id_parrilla = b.id_parrilla"
            . " AND pr.estado = 'PUBLICADA' AND b.estado = 'EN COLA' AND b.estado_validacion = 'LISTO'"
            . ' AND (pr.fecha + b.hora_inicio) <= ?::timestamp AND (?::int IS NULL OR pr.id_parrilla = ?::int)',
            [Reloj::ahora()->format('Y-m-d H:i:s'), $idParrilla, $idParrilla]);
    }

    public function buscarBloque(int $idParrilla, string $inicio, string $fecha): int
    {
        $id = Bd::valor('SELECT id_bloque FROM bloque WHERE id_parrilla = ? AND hora_inicio = ?', [$idParrilla, $inicio]);
        if ($id === null) {
            throw new NegocioException('No hay un bloque que empiece a las <b>' . Validador::corta($inicio) . "</b> en la parrilla del {$fecha}.", 404);
        }
        return (int) $id;
    }

    public function estado(int $idParrilla): string
    {
        return (string) Bd::valor('SELECT estado FROM parrilla WHERE id_parrilla = ?', [$idParrilla]);
    }

    /** Tabla HTML de los bloques para las notificaciones (orden de transmision). */
    public function resumenCorreo(int $idParrilla): string
    {
        $filas = [];
        foreach ($this->bloques($idParrilla) as $b) {
            $filas[] = [
                NotificacionService::e(Validador::corta($b['hora_inicio']) . ' - ' . Validador::corta($b['hora_fin'])),
                NotificacionService::e($b['tipo']),
                NotificacionService::e($b['programa']),
                NotificacionService::e((int) $b['notas'] > 0 ? "ESCALETA ({$b['notas']})" : $b['titulo_material']),
            ];
        }
        return NotificacionService::tabla(['Horario', 'Tipo', 'Programa', 'Material'], $filas);
    }

    // ------------------------------------------------------------------ auxiliares

    /**
     * El material de un bloque PROGRAMA debe corresponder al bloque: no SPOT, del mismo programa y que quepa.
     * (Un material mas corto que el bloque si se permite: el hueco se ve en el estado de emision y la conciliacion.)
     */
    private function validarMaterialDelBloque(Validador $v, array $m, ?string $programa, string $inicio, string $fin): void
    {
        $codigo = Validador::esc($m['titulo']);
        if ($m['tipo'] === 'SPOT') {
            $v->error("El material <b>{$codigo}</b> es un SPOT: los spots se ubican en una PAUSA_COMERCIAL desde la pauta, no en un bloque PROGRAMA.");
        }
        if ($programa !== null && $programa !== '' && $m['programa'] !== '' && strcasecmp($m['programa'], trim($programa)) !== 0) {
            $v->error("El material <b>{$codigo}</b> pertenece al programa <b>" . Validador::esc($m['programa'])
                . '</b>, pero el bloque es de <b>' . Validador::esc($programa) . '</b>.');
        }
        $duracionBloque = Validador::segundos($fin) - Validador::segundos($inicio);
        if ((int) $m['duracion_seg'] > $duracionBloque) {
            $v->error("El material <b>{$codigo}</b> dura {$m['duracion_seg']} s y el bloque solo {$duracionBloque} s ("
                . Validador::corta($inicio) . '-' . Validador::corta($fin) . '): no cabe.');
        }
    }

    private function fecha(mixed $texto): string
    {
        $v = new Validador();
        $f = $v->fecha($texto, 'fecha');
        $v->verificar();
        return $f;
    }

    private function exigirBorrador(int $id, string $fecha, string $accion): void
    {
        if ($this->estado($id) !== 'BORRADOR') {
            throw new NegocioException("La parrilla del <b>{$fecha}</b> ya está <b>PUBLICADA</b>: no se puede {$accion}.");
        }
    }

    /** 86399 (23:59:59) se muestra como 24:00. */
    private static function etiqueta(int $seg): string
    {
        return $seg >= 86399 ? '24:00' : Validador::corta(Validador::aHora($seg));
    }

    /** "20:00:00-21:00:00" -> "20:00-21:00". */
    private static function recortar(string $horario): string
    {
        return preg_replace('/:00$/', '', str_replace(':00-', '-', $horario));
    }
}
