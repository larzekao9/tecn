<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * permiso es la tabla intermedia rol-recurso: su llave es el par (id_rol, id_recurso), sin id propio, igual que escaleta
 * y pauta_espacio. Las bases creadas antes con id_permiso se pasan a esa llave sin perder ningun permiso; en una base
 * nueva (esquema.sql ya sin id_permiso) no hace nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('permiso', 'id_permiso')) {
            return;
        }
        DB::unprepared('ALTER TABLE permiso DROP CONSTRAINT IF EXISTS permiso_pkey;
            ALTER TABLE permiso DROP COLUMN id_permiso;
            ALTER TABLE permiso DROP CONSTRAINT IF EXISTS uq_permiso_rol_recurso;
            ALTER TABLE permiso ADD PRIMARY KEY (id_rol, id_recurso);');
    }

    public function down(): void
    {
        // no se vuelve a un id que nada usa
    }
};
