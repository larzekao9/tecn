<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * bitacora ya no guarda el navegador (agente_usuario): queda id_bitacora, id_usuario, accion, recurso, detalle, ip y
 * fecha, como en el diseno de la base de datos del informe. En una base nueva (esquema.sql ya sin la columna) no hace nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('bitacora', 'agente_usuario')) {
            DB::unprepared('ALTER TABLE bitacora DROP COLUMN agente_usuario;');
        }
    }

    public function down(): void
    {
        // no se vuelve a guardar el navegador
    }
};
