<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Esquema completo de Canal 11 (sql/esquema.sql: el mismo modelo del diseño conceptual de la base de datos)
 * y la tabla de tokens de la API (Laravel Sanctum).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('sql/esquema.sql')));

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
        DB::unprepared('DROP TABLE IF EXISTS factura, pago, spot, pauta_espacio, pauta, espacio, conciliacion, escaleta, bloque, parrilla,'
            . ' material, programa, bitacora, visita, notificacion, preferencia_usuario, usuario, permiso, recurso, rol CASCADE');
        DB::unprepared('DROP SEQUENCE IF EXISTS seq_numero_factura');
        DB::unprepared('DROP FUNCTION IF EXISTS fn_preferencia_inicial, fn_bitacora_inmutable, fn_desvio_inicio, fn_estado_bloque,'
            . ' fn_pauta_cerrada, fn_validar_spot, fn_validar_publicacion, fn_revalidar_bloque, fn_revalidar_contenido CASCADE');
    }
};
