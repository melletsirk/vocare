<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reemplaza el campo `empate_resuelto_por_sorteo` (nunca implementado — no
 * había ningún sorteo/aleatoriedad real, solo orden de inserción en BD) por
 * el flujo real requerido: un empate entre los postulantes que definen
 * ganador/reserva se resuelve por DECISIÓN MANUAL de la comisión, no
 * automáticamente. Ver requisitos-sistema.md §10 "Empate y plaza desierta".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('resultados', function (Blueprint $table) {
            $table->dropColumn('empate_resuelto_por_sorteo');

            // Marca si esta posición formó parte de un grupo empatado
            // (informativo, independiente de si ya fue resuelto).
            $table->boolean('empatada')->default(false)->after('estado');

            // true si la posición final fue asignada por decisión manual de
            // la comisión (desempate) en vez de por orden automático del
            // motor de ranking.
            $table->boolean('orden_manual')->default(false)->after('empatada');

            $table->foreignId('decidido_por')->nullable()->after('orden_manual')->constrained('users');
            $table->timestamp('decidido_en')->nullable()->after('decidido_por');
        });

        // `posicion` ya no puede tener default fijo: en un grupo empatado
        // pendiente, todas las filas comparten la posición de INICIO del
        // grupo hasta que la comisión decide el orden final.
        Schema::table('resultados', function (Blueprint $table) {
            $table->unsignedSmallInteger('posicion')->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('resultados', function (Blueprint $table) {
            $table->dropForeign(['decidido_por']);
            $table->dropColumn(['empatada', 'orden_manual', 'decidido_por', 'decidido_en']);
            $table->boolean('empate_resuelto_por_sorteo')->default(false);
            $table->unsignedSmallInteger('posicion')->default(1)->change();
        });
    }
};
