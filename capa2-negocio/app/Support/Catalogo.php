<?php

namespace App\Support;

use App\Services\BitacoraService;
use App\Services\ContratoService;
use App\Services\EmisionService;
use App\Services\EscaletaService;
use App\Services\EspacioService;
use App\Services\EstadisticaService;
use App\Services\FacturaService;
use App\Services\MaterialService;
use App\Services\ParrillaService;
use App\Services\PautaService;
use App\Services\ProgramaService;
use App\Services\ReporteService;
use App\Services\RolService;
use App\Services\SolicitudService;
use App\Services\UsuarioService;
use App\Services\ValidacionService;

/**
 * Catalogo de funciones del sistema y MATRIZ DE CONTROL DE ACCESO (RBAC). Cada funcion es un comando (3 letras de la
 * accion + 3 de la entidad) con sus parametros y los roles que la pueden usar por defecto.
 * La matriz vigente esta en la BD: un permiso por rol y recurso (pantalla) con cuatro acciones: ver, crear, editar y
 * eliminar. Cada comando pertenece a un recurso (su pantalla) y necesita una de esas acciones (accionDe): por ejemplo
 * insPar = crear en /parrillas. Se edita en "Matriz de acceso". La usan el middleware "permiso", la ayuda y el menu.
 *   ADMINISTRADOR: todo.   ENCARGADO: CU4 y CU5.   PROGRAMADOR: CU3 y CU7.   ANUNCIANTE: sus pautas (CU6).
 */
final class Catalogo
{
    private const TODOS = ['ADMINISTRADOR', 'ENCARGADO', 'PROGRAMADOR'];
    private const ADM = ['ADMINISTRADOR'];
    private const ADM_ENC = ['ADMINISTRADOR', 'ENCARGADO'];
    private const ADM_PROG = ['ADMINISTRADOR', 'PROGRAMADOR'];
    private const ANU = ['ANUNCIANTE'];

    private static ?array $comandos = null;

    public static function casosUso(): array
    {
        return [
            1 => ['Gestión de Usuarios', 'Registrar y administrar a los usuarios del sistema, su rol y su acceso.'],
            2 => ['Gestión de Catálogo de Programas', 'Mantener el catálogo de programas que se emiten en el canal.'],
            3 => ['Gestión de Confeccionar y Controlar la Parrilla de Programación', 'Armar la parrilla de cada día por bloques horarios y la escaleta del noticiero, y publicarla.'],
            4 => ['Gestionar Materiales de Video', 'Registrar los videos (programas y spots publicitarios) sin duplicados.'],
            5 => ['Gestión de Validar Estado de Emisión', 'Revisar cada bloque de la parrilla y marcarlo LISTO u OBSERVADO antes de publicarla.'],
            6 => ['Gestión de Pauta Publicitaria e Insertar Spots Comerciales', 'Solicitar, aprobar y pagar las pautas; sus spots se programan solos en las pausas comerciales.'],
            7 => ['Gestión de Conciliar Emisión Real, Registrar Auditoría', 'Conciliar lo que realmente salió al aire con lo programado; cada cambio queda en la bitácora.'],
            8 => ['Reportes y Estadísticas', 'Reportes de programación, contenidos, pauta y cumplimiento filtrados por rango de fechas.'],
        ];
    }

    /** @return array<string, array> clave = nombre del comando en minusculas */
    public static function comandos(): array
    {
        if (self::$comandos !== null) {
            return self::$comandos;
        }
        $c = [];
        $add = function (int $cu, string $nombre, string $desc, array $params, string $ejemplo, array $roles, string $clase, string $metodo, string $pantalla) use (&$c) {
            $c[strtolower($nombre)] = compact('cu', 'nombre', 'params', 'ejemplo', 'roles', 'clase', 'metodo', 'pantalla') + ['descripcion' => $desc];
        };

        // CU1
        $add(1, 'lisUsu', 'Listar usuarios', ['filtro'], 'lisUsu["*"]', self::ADM, UsuarioService::class, 'listar', '/usuarios');
        $add(1, 'mosUsu', 'Ver un usuario', ['usuario (CI o email)'], 'mosUsu["7654321"]', self::ADM, UsuarioService::class, 'mostrar', '/usuarios');
        $add(1, 'insUsu', 'Registrar usuario', ['ci', 'nombre', 'apellido', 'email', 'telefono', 'rol', 'contrasena'],
            'insUsu["7654321","Ana","Rojas","ana.rojas@gmail.com","71234567","ENCARGADO","Clave2026"]', self::ADM, UsuarioService::class, 'insertar', '/usuarios');
        $add(1, 'modUsu', 'Modificar usuario', ['usuario (CI o email)', 'ci', 'nombre', 'apellido', 'email', 'telefono', 'rol'],
            'modUsu["7654321","7654321","Ana","Rojas","ana.rojas@gmail.com","71234567","ENCARGADO"]', self::ADM, UsuarioService::class, 'modificar', '/usuarios');
        $add(1, 'eliUsu', 'Eliminar usuario', ['usuario (CI o email)'], 'eliUsu["7654321"]', self::ADM, UsuarioService::class, 'eliminar', '/usuarios');
        $add(1, 'lisBit', 'Ver la bitácora (accesos y acciones de los usuarios)', ['accion', 'desde', 'hasta', 'texto'],
            'lisBit["LOGIN_FALLIDO","","",""]', self::ADM, BitacoraService::class, 'listar', '/bitacora');

        // CU2
        $add(2, 'lisPro', 'Listar programas', ['filtro'], 'lisPro["*"]', self::TODOS, ProgramaService::class, 'listar', '/programas');
        $add(2, 'mosPro', 'Ver un programa', ['programa (nombre)'], 'mosPro["Informe Once"]', self::TODOS, ProgramaService::class, 'mostrar', '/programas');
        $add(2, 'insPro', 'Registrar programa', ['nombre', 'tipo'], 'insPro["Informe Once","NOTICIERO"]', self::ADM, ProgramaService::class, 'insertar', '/programas');
        $add(2, 'modPro', 'Modificar programa', ['programa (nombre)', 'nombre', 'tipo'],
            'modPro["Informe Once","Informe Once","NOTICIERO"]', self::ADM, ProgramaService::class, 'modificar', '/programas');
        $add(2, 'eliPro', 'Eliminar un programa', ['programa (nombre)'], 'eliPro["Informe Once"]', self::ADM, ProgramaService::class, 'eliminar', '/programas');

        // CU3
        $add(3, 'lisPar', 'Listar parrillas', ['filtro'], 'lisPar["*"]', self::TODOS, ParrillaService::class, 'listar', '/parrillas');
        $add(3, 'mosPar', 'Ver una parrilla', ['fecha'], 'mosPar["2026-11-20"]', self::TODOS, ParrillaService::class, 'mostrar', '/parrillas');
        $add(3, 'insPar', 'Crear parrilla', ['fecha'], 'insPar["2026-11-20"]', self::ADM_PROG, ParrillaService::class, 'insertar', '/parrillas');
        $add(3, 'eliPar', 'Eliminar parrilla', ['fecha'], 'eliPar["2026-11-20"]', self::ADM_PROG, ParrillaService::class, 'eliminar', '/parrillas');
        $add(3, 'insBlo', 'Agregar bloque a la parrilla', ['fecha', 'hora_inicio', 'hora_fin', 'tipo', 'programa (nombre)', 'material (id)'],
            'insBlo["2026-11-20","20:00","21:00","PROGRAMA","Informe Once","3"]', self::ADM_PROG, ParrillaService::class, 'insertarBloque', '/parrillas');
        $add(3, 'eliBlo', 'Quitar bloque de la parrilla', ['fecha', 'hora_inicio'], 'eliBlo["2026-11-20","20:00"]', self::ADM_PROG, ParrillaService::class, 'eliminarBloque', '/parrillas');
        $add(3, 'camBlo', 'Cambiar el material de un bloque', ['fecha', 'hora_inicio', 'material (id)'], 'camBlo["2026-11-20","20:00","4"]', self::ADM_PROG, ParrillaService::class, 'cambiarMaterial', '/parrillas');
        $add(3, 'lisLea', 'Ver la escaleta', ['fecha', 'hora_bloque'], 'lisLea["2026-11-20","20:00"]', self::TODOS, EscaletaService::class, 'listar', '/parrillas');
        $add(3, 'insLea', 'Agregar nota a la escaleta', ['fecha', 'hora_bloque', 'orden', 'material (id)'],
            'insLea["2026-11-20","20:00","1","3"]', self::ADM_PROG, EscaletaService::class, 'insertar', '/parrillas');
        $add(3, 'modLea', 'Reordenar notas de la escaleta', ['fecha', 'hora_bloque', 'material (id)', 'nuevo_orden'],
            'modLea["2026-11-20","20:00","3","2"]', self::ADM_PROG, EscaletaService::class, 'mover', '/parrillas');
        $add(3, 'eliLea', 'Quitar nota de la escaleta', ['fecha', 'hora_bloque', 'material (id)'], 'eliLea["2026-11-20","20:00","3"]', self::ADM_PROG, EscaletaService::class, 'eliminar', '/parrillas');
        $add(3, 'pubPar', 'Publicar parrilla', ['fecha'], 'pubPar["2026-11-20"]', self::ADM_PROG, ParrillaService::class, 'publicar', '/parrillas');

        // CU4
        $add(4, 'lisMat', 'Listar materiales', ['filtro'], 'lisMat["SPOT"]', self::TODOS, MaterialService::class, 'listar', '/materiales');
        $add(4, 'mosMat', 'Ver un material', ['material (id)'], 'mosMat["3"]', self::TODOS, MaterialService::class, 'mostrar', '/materiales');
        $add(4, 'insMat', 'Registrar material', ['titulo', 'tipo', 'programa (nombre)', 'duracion_seg', 'formato', 'ruta'],
            'insMat["Informe Once 20 de noviembre","PROGRAMA","Informe Once","3600","MP4","videos/informe.mp4"]', self::ADM_ENC, MaterialService::class, 'insertar', '/materiales');
        $add(4, 'modMat', 'Modificar material', ['material (id)', 'titulo', 'tipo', 'programa (nombre)', 'duracion_seg', 'formato', 'ruta'],
            'modMat["3","Informe Once 20 de noviembre","PROGRAMA","Informe Once","3580","MP4","videos/informe.mp4"]', self::ADM_ENC, MaterialService::class, 'modificar', '/materiales');
        $add(4, 'eliMat', 'Eliminar un material', ['material (id)'], 'eliMat["3"]', self::ADM_ENC, MaterialService::class, 'eliminar', '/materiales');

        // CU5 - Validar Estado de Emision (antes de publicar)
        $add(5, 'lisVal', 'Ver el estado de emisión de la parrilla', ['fecha'], 'lisVal["2026-11-20"]', self::TODOS, ValidacionService::class, 'listar', '/validacion');
        $add(5, 'insVal', 'Validar un bloque (LISTO u OBSERVADO)', ['fecha', 'hora_bloque', 'estado', 'observacion'],
            'insVal["2026-11-20","20:00","LISTO",""]', self::ADM_ENC, ValidacionService::class, 'insertar', '/validacion');
        $add(5, 'modVal', 'Modificar la validación de un bloque', ['fecha', 'hora_bloque', 'estado', 'observacion'],
            'modVal["2026-11-20","20:00","OBSERVADO","Falta el audio de la nota 2"]', self::ADM_ENC, ValidacionService::class, 'modificar', '/validacion');
        $add(5, 'eliVal', 'Anular la validación de un bloque', ['fecha', 'hora_bloque'], 'eliVal["2026-11-20","20:00"]', self::ADM_ENC, ValidacionService::class, 'eliminar', '/validacion');
        $add(5, 'canBlo', 'Cancelar un bloque de la parrilla publicada (p. ej. breaking news): su video pasa a ser el de reemplazo', ['fecha', 'hora_bloque', 'reemplazo (id de material, opcional)'],
            'canBlo["2026-11-20","21:05","4"]', self::ADM_ENC, ValidacionService::class, 'cancelar', '/validacion');
        $add(5, 'reaBlo', 'Reanudar un bloque cancelado', ['fecha', 'hora_bloque'], 'reaBlo["2026-11-20","21:05"]', self::ADM_ENC, ValidacionService::class, 'reanudar', '/validacion');

        // CU6 - espacios publicitarios (horarios fijos del canal con su cupo y precio)
        $add(6, 'lisEsp', 'Listar espacios publicitarios', [], 'lisEsp', self::ADM, EspacioService::class, 'listar', '/espacios');
        $add(6, 'insEsp', 'Registrar espacio publicitario', ['nombre', 'hora_inicio', 'hora_fin', 'segundos_por_dia', 'precio_segundo'],
            'insEsp["Noche","20:00","23:00","300","10"]', self::ADM, EspacioService::class, 'insertar', '/espacios');
        $add(6, 'modEsp', 'Modificar espacio publicitario', ['espacio (nombre)', 'nombre', 'hora_inicio', 'hora_fin', 'segundos_por_dia', 'precio_segundo'],
            'modEsp["Noche","Noche","20:00","23:00","360","12"]', self::ADM, EspacioService::class, 'modificar', '/espacios');
        $add(6, 'eliEsp', 'Eliminar espacio publicitario', ['espacio (nombre)'], 'eliEsp["Mediodía"]', self::ADM, EspacioService::class, 'eliminar', '/espacios');
        $add(6, 'disEsp', 'Ver los segundos libres de cada espacio en unas fechas', ['fecha_inicio', 'fecha_fin'],
            'disEsp["2026-12-01","2026-12-31"]', ['ADMINISTRADOR', 'ENCARGADO', 'PROGRAMADOR', 'ANUNCIANTE'], EspacioService::class, 'disponibilidad', '/mis-pautas');

        // CU6 - la solicitud del anunciante ES la pauta (estado EN REVISION -> APROBADA o RECHAZADA); aprobada, queda cerrada
        $add(6, 'lisPau', 'Listar pautas', ['filtro'], 'lisPau["*"]', self::TODOS, PautaService::class, 'listar', '/pautas');
        $add(6, 'mosPau', 'Ver una pauta', ['pauta (nombre)'], 'mosPau["Navidad Banco Sol"]', self::TODOS, PautaService::class, 'mostrar', '/pautas');
        $add(6, 'insPau', 'Registrar pauta aprobada para un anunciante', ['nombre', 'anunciante (CI o email)', 'fecha_inicio', 'fecha_fin', 'espacios (espacio:salidas por día, ...)', 'duracion_spot', 'frecuencia', 'cuotas', 'spot (id de material SPOT)'],
            'insPau["Navidad Banco Sol","1000011","2026-12-01","2026-12-31","Noche:3,Mañana:2","30","MENSUAL","2","9"]', self::ADM, PautaService::class, 'insertar', '/pautas');
        $add(6, 'eliPau', 'Cancelar una pauta', ['pauta (nombre)'], 'eliPau["Navidad Banco Sol"]', self::ADM, PautaService::class, 'eliminar', '/pautas');
        $add(6, 'lisSol', 'Ver solicitudes de pauta', [], 'lisSol', ['ADMINISTRADOR', 'ANUNCIANTE'], SolicitudService::class, 'listar', '/pautas');
        $add(6, 'insSol', 'Solicitar una pauta con su video (anunciante)', ['nombre (campaña)', 'fecha_inicio', 'fecha_fin', 'espacios (espacio:salidas por día, ...)', 'frecuencia', 'cuotas', 'titulo_spot', 'ruta', 'formato', 'duracion_seg'],
            'insSol["Navidad 2026","2026-12-01","2026-12-31","Noche:3,Mañana:2","MENSUAL","2","Spot navideño","videos/...mp4","MP4","30"]', self::ANU, SolicitudService::class, 'insertar', '/mis-pautas');
        $add(6, 'canSol', 'Cancelar mi solicitud de pauta', ['pauta (id)'], 'canSol["1"]', self::ANU, SolicitudService::class, 'cancelar', '/mis-pautas');
        $add(6, 'apbSol', 'Aprobar una solicitud de pauta', ['pauta (id)'], 'apbSol["1"]', self::ADM, SolicitudService::class, 'aprobar', '/pautas');
        $add(6, 'recSol', 'Rechazar una solicitud de pauta', ['pauta (id)'], 'recSol["1"]', self::ADM, SolicitudService::class, 'rechazar', '/pautas');
        $add(6, 'lisMpa', 'Ver mis pautas (anunciante)', [], 'lisMpa', self::ANU, PautaService::class, 'misPautas', '/mis-pautas');
        $add(6, 'pagStr', 'Pagar cuotas de mi pauta con tarjeta o QR (Stripe), con los datos de la factura', ['pauta (nombre)', 'cuotas (cuántas)', 'metodo (TARJETA / QR)', 'nit', 'razon_social'],
            'pagStr["Navidad Banco Sol","1","QR","1020304","Banco Sol S.A."]', self::ANU, ContratoService::class, 'pagarStripe', '/mis-pautas');
        $add(6, 'lisFac', 'Ver mis facturas (anunciante)', ['filtro'], 'lisFac["*"]', self::ANU, FacturaService::class, 'listar', '/facturas');
        $add(6, 'mosFac', 'Ver una de mis facturas', ['numero de factura'], 'mosFac["1"]', self::ANU, FacturaService::class, 'mostrar', '/facturas');
        $add(6, 'mosCob', 'Confirmar mi pago con Stripe', ['sesion de Stripe'], 'mosCob["cs_test_..."]', self::ANU, ContratoService::class, 'estadoCobro', '/mis-pautas');

        // CU7 - Conciliar Emision Real (despues de salir al aire); cada cambio queda en la bitacora
        $add(7, 'conPar', 'Ver la conciliación del día', ['fecha'], 'conPar["2026-11-20"]', self::TODOS, EmisionService::class, 'conciliar', '/emision');
        $add(7, 'insEmi', 'Conciliar un bloque (lo que salió al aire)', ['fecha', 'hora_inicio', 'inicio_real', 'fin_real', 'estado', 'observacion'],
            'insEmi["2026-11-20","20:00","20:00:05","21:00:00","EMITIDO",""]', self::ADM_PROG, EmisionService::class, 'insertar', '/emision');
        $add(7, 'modEmi', 'Corregir la conciliación de un bloque', ['fecha', 'hora_inicio', 'inicio_real', 'fin_real', 'estado', 'observacion'],
            'modEmi["2026-11-20","20:00","20:00:10","21:00:00","PARCIAL","Se cortó la señal"]', self::ADM_PROG, EmisionService::class, 'modificar', '/emision');
        $add(7, 'eliEmi', 'Eliminar la conciliación de un bloque', ['fecha', 'hora_inicio'], 'eliEmi["2026-11-20","20:00"]', self::ADM_PROG, EmisionService::class, 'eliminar', '/emision');
        $add(7, 'modSpo', 'Registrar si un spot salió al aire', ['fecha', 'hora_spot', 'estado'],
            'modSpo["2026-11-20","21:00:00","NO_EMITIDO"]', self::ADM_PROG, EmisionService::class, 'spot', '/emision');

        // CU8
        $add(8, 'repPro', 'Reporte de horas por programa', ['desde', 'hasta'], 'repPro["2026-11-01","2026-11-30"]', self::ADM, ReporteService::class, 'horasPorPrograma', '/reportes');
        $add(8, 'repMat', 'Reporte de materiales', ['desde', 'hasta'], 'repMat["2026-11-01","2026-11-30"]', self::ADM, ReporteService::class, 'materiales', '/reportes');
        $add(8, 'repPau', 'Reporte de spots por anunciante', ['desde', 'hasta'], 'repPau["2026-11-01","2026-11-30"]', self::ADM, ReporteService::class, 'spotsPorAnunciante', '/reportes');
        $add(8, 'repCum', 'Reporte de cumplimiento', ['desde', 'hasta'], 'repCum["2026-11-01","2026-11-30"]', self::ADM, ReporteService::class, 'cumplimiento', '/reportes');
        $add(8, 'verEst', 'Ver estadísticas', ['dias'], 'verEst["30"]', self::ADM, EstadisticaService::class, 'generalesCmd', '/estadisticas');

        // CU1 - Seguridad: matriz de acceso
        $add(1, 'lisRol', 'Ver la matriz de acceso', [], 'lisRol', self::ADM, RolService::class, 'listar', '/roles');
        $add(1, 'modRol', 'Conceder o quitar una acción de un rol sobre un recurso', ['rol', 'recurso (ruta)', 'accion (VER/CREAR/EDITAR/ELIMINAR)', 'concedido (SI/NO)'],
            'modRol["PROGRAMADOR","/reportes","VER","SI"]', self::ADM, RolService::class, 'asignar', '/roles');

        return self::$comandos = $c;
    }

    public static function comando(string $nombre): ?array
    {
        return self::comandos()[strtolower($nombre)] ?? null;
    }

    // ------------------------------------------------------------------ matriz: rol -> recurso -> ver/crear/editar/eliminar

    public const ACCIONES = ['ver', 'crear', 'editar', 'eliminar'];

    /** Recursos (pantallas) que no dependen de ningun comando: todos los usuarios los ven. */
    public const RECURSOS_LIBRES = ['/', '/notificaciones'];

    /** Pantallas propias del anunciante: el Administrador no las tiene. */
    public const SOLO_ANUNCIANTE = ['/mis-pautas', '/facturas'];

    /** Accion que necesita el comando: consultar = ver; ins = crear; eli o cancelar mi solicitud = eliminar; el resto = editar. */
    public static function accionDe(string $nombre): string
    {
        $n = strtolower($nombre);
        if ($n === 'cansol') {
            return 'eliminar';
        }
        return match (substr($n, 0, 3)) {
            'lis', 'mos', 'con', 'rep', 'ver', 'dis' => 'ver',
            'ins' => 'crear',
            'eli' => 'eliminar',
            default => 'editar',
        };
    }

    /**
     * Accion que necesita el comando en ese recurso. Las solicitudes en Pautas son para revisarlas (aprobar o
     * rechazar): ahi se necesita "editar"; en Mis pautas el anunciante solo las ve.
     */
    public static function accionEn(string $nombre, string $ruta): string
    {
        return strtolower($nombre) === 'lissol' && $ruta === '/pautas' ? 'editar' : self::accionDe($nombre);
    }

    /** Recursos (rutas) donde se usa el comando: su pantalla; las solicitudes se ven en Pautas y en Mis pautas. */
    public static function recursosDe(string $nombre): array
    {
        $c = self::comando($nombre);
        if ($c === null) {
            return [];
        }
        // las solicitudes se ven en Pautas y en Mis pautas; los espacios libres se consultan al pedir (Mis pautas) y al
        // registrar una pauta (Pautas)
        return in_array(strtolower($nombre), ['lissol', 'disesp'], true) ? ['/pautas', '/mis-pautas'] : [$c['pantalla']];
    }

    /** @var array<string, array<string, array<string, bool>>>|null rol -> ruta -> accion -> concedida (de la tabla permiso) */
    private static ?array $matrizBd = null;

    /** Roles que pueden usar el comando segun la BD (la matriz se edita en "Matriz de acceso"). */
    public static function rolesDe(string $nombre): array
    {
        return array_values(array_filter(array_keys(self::matrizBd()), fn ($rol) => self::permite($nombre, $rol)));
    }

    public static function permite(string $nombre, ?string $rol): bool
    {
        if ($rol === null || self::comando($nombre) === null) {
            return false;
        }
        foreach (self::recursosDe($nombre) as $ruta) {
            if (self::matrizBd()[$rol][$ruta][self::accionEn($nombre, $ruta)] ?? false) {
                return true;
            }
        }
        return false;
    }

    public static function olvidarMatriz(): void
    {
        self::$matrizBd = null;
    }

    public static function matrizBd(): array
    {
        if (self::$matrizBd === null) {
            self::$matrizBd = [];
            foreach (Bd::filas('SELECT r.nombre AS rol, re.ruta, p.ver, p.crear, p.editar, p.eliminar FROM permiso p'
                . ' JOIN rol r ON r.id_rol = p.id_rol JOIN recurso re ON re.id_recurso = p.id_recurso') as $f) {
                foreach (self::ACCIONES as $a) {
                    self::$matrizBd[$f['rol']][$f['ruta']][$a] = (bool) $f[$a];
                }
            }
        }
        return self::$matrizBd;
    }

    /**
     * Permisos por defecto (para el seeder): rol -> ruta -> acciones, a partir de los roles de cada comando.
     * Inicio y Notificaciones: todos los ven.
     */
    public static function permisosPorDefecto(): array
    {
        $d = [];
        foreach (self::comandos() as $c) {
            foreach ($c['roles'] as $rol) {
                foreach (self::recursosDe($c['nombre']) as $ruta) {
                    if ($ruta === '/mis-pautas' && $rol !== 'ANUNCIANTE' || $ruta === '/pautas' && $rol === 'ANUNCIANTE') {
                        continue; // lisSol: el anunciante ve sus solicitudes en Mis pautas, el resto en Pautas
                    }
                    $d[$rol][$ruta][self::accionEn($c['nombre'], $ruta)] = true;
                }
            }
        }
        foreach (['ADMINISTRADOR', 'ENCARGADO', 'PROGRAMADOR', 'ANUNCIANTE'] as $rol) {
            foreach (self::RECURSOS_LIBRES as $ruta) {
                $d[$rol][$ruta]['ver'] = true;
            }
        }
        return $d;
    }

    /** Acciones que existen en cada recurso (las que tienen algun comando): la matriz solo muestra esas casillas. */
    public static function accionesPorRecurso(): array
    {
        $r = [];
        foreach (self::RECURSOS_LIBRES as $ruta) {
            $r[$ruta] = ['ver'];
        }
        foreach (self::comandos() as $c) {
            foreach (self::recursosDe($c['nombre']) as $ruta) {
                $r[$ruta][] = self::accionEn($c['nombre'], $ruta);
            }
        }
        return array_map(fn ($a) => array_values(array_intersect(self::ACCIONES, array_unique($a))), $r);
    }

    /** Comandos que el rol puede usar (sin la clase de servicio), con los roles vigentes de la BD. */
    public static function paraRol(string $rol): array
    {
        $r = [];
        foreach (self::comandos() as $c) {
            if (self::permite($c['nombre'], $rol)) {
                $r[] = self::publico($c);
            }
        }
        return $r;
    }

    /** Matriz completa (para la ayuda): todos los comandos con sus roles vigentes. */
    public static function matriz(): array
    {
        return array_values(array_map([self::class, 'publico'], self::comandos()));
    }

    private static function publico(array $c): array
    {
        unset($c['clase'], $c['metodo']);
        $c['roles_por_defecto'] = $c['roles'];
        $c['roles'] = self::rolesDe($c['nombre']);
        return $c;
    }
}
