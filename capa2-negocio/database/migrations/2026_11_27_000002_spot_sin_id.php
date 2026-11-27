<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * spot es la tabla intermedia pauta-bloque: su llave es el bloque y la hora de la salida (id_bloque, hora), sin id
 * propio. Las bases creadas antes con id_spot se pasan a esa llave sin perder ningun spot y se recarga fn_validar_spot
 * (que ya no compara por id_spot) desde esquema.sql; en una base nueva no hace nada.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('spot', 'id_spot')) {
            return;
        }
        DB::unprepared('ALTER TABLE spot DROP CONSTRAINT IF EXISTS spot_pkey;
            ALTER TABLE spot DROP COLUMN id_spot;
            ALTER TABLE spot DROP CONSTRAINT IF EXISTS spot_id_bloque_hora_key;
            ALTER TABLE spot ADD PRIMARY KEY (id_bloque, hora);');

        $esquema = file_get_contents(database_path('sql/esquema.sql'));
        if (!preg_match('/CREATE OR REPLACE FUNCTION fn_validar_spot\(\).*?\$\$ LANGUAGE plpgsql;/s', $esquema, $funcion)) {
            throw new RuntimeException('fn_validar_spot no esta en esquema.sql');
        }
        DB::unprepared($funcion[0]);
    }

    public function down(): void
    {
        // no se vuelve a un id que nada usa
    }
};
