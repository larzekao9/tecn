<?php

namespace App\Services;

use App\Models\Usuario;
use App\Support\Bd;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Avisos a los usuarios (antes se enviaban solo por correo: Notificador / NAviso / EnvioAvisos).
 * Ahora cada aviso queda en la bandeja de notificaciones de la web y, si NOTIFICACIONES_CORREO=true,
 * tambien se envia por correo (SMTP configurado en .env).
 * Un aviso que falla nunca convierte en error la operacion que lo origino.
 */
class NotificacionService
{
    /** Ids de los usuarios activos con alguno de los roles, sin el autor del cambio. */
    public function usuariosDeRoles(?int $excluir, string ...$roles): array
    {
        if ($roles === []) {
            return [];
        }
        $marcas = implode(',', array_fill(0, count($roles), '?'));
        $filas = Bd::filas("SELECT u.id_usuario FROM usuario u JOIN rol r ON r.id_rol = u.id_rol WHERE u.activo AND r.nombre IN ({$marcas}) ORDER BY u.id_usuario", $roles);
        return array_values(array_filter(array_map(fn ($f) => (int) $f['id_usuario'], $filas), fn ($id) => $id !== $excluir));
    }

    /** Id del usuario si esta activo; null si no existe o esta de baja. */
    public function usuarioActivo(int $id): ?int
    {
        $v = Bd::valor('SELECT id_usuario FROM usuario WHERE id_usuario = ? AND activo', [$id]);
        return $v === null ? null : (int) $v;
    }

    /**
     * Envia el aviso a cada usuario. Devuelve los ids a los que se entrego.
     * $mensaje admite HTML sencillo (<b>, listas); los datos variables deben ir escapados.
     */
    public function enviar(array $idsUsuario, string $titulo, string $mensaje): array
    {
        $entregados = [];
        foreach (array_unique($idsUsuario) as $id) {
            try {
                Bd::ejecutar(
                    'INSERT INTO notificacion (id_usuario, titulo, mensaje) VALUES (?, ?, ?)',
                    [$id, mb_substr($titulo, 0, 200), $mensaje]
                );
                $entregados[] = $id;
            } catch (Throwable $e) {
                Log::warning("[Notificacion] No se pudo registrar '{$titulo}' para {$id}: " . $e->getMessage());
            }
        }
        if ($entregados !== [] && config('canal11.notificaciones_correo')) {
            $this->enviarCorreo($entregados, $titulo, $mensaje);
        }
        return $entregados;
    }

    private function enviarCorreo(array $ids, string $titulo, string $mensaje): void
    {
        $marcas = implode(',', array_fill(0, count($ids), '?'));
        foreach (Bd::filas("SELECT email FROM usuario WHERE id_usuario IN ({$marcas})", $ids) as $f) {
            try {
                Mail::html(
                    "<div style='font-family:Arial,sans-serif'><h2 style='color:#0d47a1'>Canal 11</h2>{$mensaje}</div>",
                    fn ($m) => $m->to($f['email'])->subject("Canal 11 | {$titulo}")
                );
            } catch (Throwable $e) {
                Log::warning("[Notificacion] No se pudo enviar el correo a {$f['email']}: " . $e->getMessage());
            }
        }
    }

    // ------------------------------------------------------------------ bandeja del usuario

    public function listar(Usuario $u, bool $soloNoLeidas = false): array
    {
        $sql = "SELECT id_notificacion AS id, titulo, mensaje, leida, to_char(fecha, 'YYYY-MM-DD HH24:MI') AS fecha"
            . ' FROM notificacion WHERE id_usuario = ?' . ($soloNoLeidas ? ' AND NOT leida' : '')
            . ' ORDER BY fecha DESC, id_notificacion DESC LIMIT 200';
        return Bd::filas($sql, [$u->id_usuario]);
    }

    public function noLeidas(Usuario $u): int
    {
        return (int) Bd::valor('SELECT count(*) FROM notificacion WHERE id_usuario = ? AND NOT leida', [$u->id_usuario]);
    }

    public function marcarLeida(Usuario $u, int $id): void
    {
        Bd::ejecutar('UPDATE notificacion SET leida = true WHERE id_notificacion = ? AND id_usuario = ?', [$id, $u->id_usuario]);
    }

    public function marcarTodas(Usuario $u): int
    {
        return Bd::ejecutar('UPDATE notificacion SET leida = true WHERE id_usuario = ? AND NOT leida', [$u->id_usuario]);
    }

    // ------------------------------------------------------------------ avisos ya enviados (no repetir)

    /** Un aviso automatico ya se envio si existe una notificacion con ese mismo titulo (cada titulo nombra lo que avisa). */
    public function yaEnviado(string $titulo): bool
    {
        return Bd::existe('SELECT 1 FROM notificacion WHERE titulo = ?', [mb_substr($titulo, 0, 200)]);
    }

    /** Escapa texto para el cuerpo HTML del aviso. */
    public static function e(?string $s): string
    {
        return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
    }

    /** Tabla HTML sencilla para el cuerpo del aviso (las celdas ya deben venir escapadas). */
    public static function tabla(array $cabecera, array $filas): string
    {
        $h = "<table class='tabla-aviso'><thead><tr>";
        foreach ($cabecera as $c) {
            $h .= '<th>' . self::e($c) . '</th>';
        }
        $h .= '</tr></thead><tbody>';
        foreach ($filas as $f) {
            $h .= '<tr>' . implode('', array_map(fn ($c) => "<td>{$c}</td>", $f)) . '</tr>';
        }
        return $h . '</tbody></table>';
    }

    public static function lista(array $items): string
    {
        return '<ul>' . implode('', array_map(fn ($i) => '<li>' . self::e($i) . '</li>', $items)) . '</ul>';
    }
}
