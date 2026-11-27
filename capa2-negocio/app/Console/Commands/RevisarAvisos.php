<?php

namespace App\Console\Commands;

use App\Services\AvisosAutomaticosService;
use Illuminate\Console\Command;

/** Tarea programada (cada minuto): alertas automaticas. */
class RevisarAvisos extends Command
{
    protected $signature = 'canal11:avisos';

    protected $description = 'Revisa parrillas en riesgo (sin validar) y emisiones sin registrar';

    public function handle(AvisosAutomaticosService $avisos): int
    {
        foreach ($avisos->revisar() as $tarea => $resultado) {
            $this->line(str_pad($tarea, 26) . ": {$resultado}");
        }
        return self::SUCCESS;
    }
}
