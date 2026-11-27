<?php

namespace App\Models;

use App\Support\Bd;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Usuario del sistema (tabla usuario del esquema canal11). Posee un rol (tabla rol) y configura sus preferencias
 * (tabla preferencia_usuario). 4 actores:
 *   ADMINISTRADOR = direccion del canal
 *   ENCARGADO     = Prensa (Encargado de Contenidos)
 *   PROGRAMADOR   = Programacion (arma la parrilla y concilia la emision real)
 *   ANUNCIANTE    = cliente que solicita y paga sus pautas
 */
class Usuario extends Authenticatable
{
    use HasApiTokens;

    public const ROLES = ['ADMINISTRADOR', 'ENCARGADO', 'PROGRAMADOR', 'ANUNCIANTE'];

    public const NOMBRES_ROL = [
        'ADMINISTRADOR' => 'Administrador',
        'ENCARGADO' => 'Encargado de Contenidos',
        'PROGRAMADOR' => 'Programador',
        'ANUNCIANTE' => 'Anunciante',
    ];

    protected $table = 'usuario';
    protected $primaryKey = 'id_usuario';
    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['contrasena', 'remember_token'];

    private ?string $rolCache = null;

    protected function casts(): array
    {
        return [
            'activo' => 'boolean',
            'id_rol' => 'integer',
        ];
    }

    /** Nombre del rol (ADMINISTRADOR, ENCARGADO...) leido de la tabla rol. */
    public function getRolAttribute(): string
    {
        return $this->rolCache ??= (string) Bd::valor('SELECT nombre FROM rol WHERE id_rol = ?', [$this->id_rol]);
    }

    public static function idRol(string $nombre): int
    {
        return (int) Bd::valor('SELECT id_rol FROM rol WHERE nombre = ?', [$nombre]);
    }

    /** Preferencias de tema y accesibilidad (tabla preferencia_usuario). */
    public function preferencias(): array
    {
        $p = Bd::fila('SELECT tema, modo, escala_fuente, alto_contraste FROM preferencia_usuario WHERE id_usuario = ?', [$this->id_usuario]);
        return [
            'tema' => $p['tema'] ?? 'ADULTOS',
            'modo' => $p['modo'] ?? 'AUTO',
            'escala_fuente' => (int) ($p['escala_fuente'] ?? 100),
            'alto_contraste' => (bool) ($p['alto_contraste'] ?? false),
        ];
    }

    public function nombreCompleto(): string
    {
        return trim($this->nombre . ' ' . $this->apellido);
    }

    public function nombreRol(): string
    {
        return self::NOMBRES_ROL[$this->rol] ?? $this->rol;
    }

    public function getAuthPasswordName()
    {
        return 'contrasena';
    }

    public function getRememberTokenName()
    {
        return null; // la API usa tokens de Sanctum, no "remember me"
    }
}
