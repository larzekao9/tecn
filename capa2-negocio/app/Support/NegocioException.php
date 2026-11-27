<?php

namespace App\Support;

use RuntimeException;

/**
 * Error de regla de negocio o de validacion. Junta TODOS los errores para responder de una sola vez
 * (igual que canal11.negocio.NegocioException del sistema por correo). La API lo devuelve como HTTP 422.
 */
class NegocioException extends RuntimeException
{
    /** @var string[] */
    private array $errores;

    public function __construct(array|string $errores, private int $estado = 422)
    {
        $this->errores = is_array($errores) ? array_values($errores) : [$errores];
        parent::__construct(implode(' ; ', $this->errores));
    }

    /** @return string[] */
    public function getErrores(): array
    {
        return $this->errores;
    }

    public function getEstado(): int
    {
        return $this->estado;
    }
}
