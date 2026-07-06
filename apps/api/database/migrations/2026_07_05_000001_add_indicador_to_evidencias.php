<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega indicador_id y puntaje_indicador a evidencias.
 *
 * El evaluador vincula cada evidencia a un indicador concreto del snapshot
 * (ej: "Libro publicado con ISBN → 4 pts").  El motor de cálculo usa
 * puntaje_indicador para saber cuánto aporta esa evidencia al total.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('evidencias', function (Blueprint $table) {
            // FK suave — usa unsignedBigInteger porque el indicador puede venir
            // del snapshot JSON y no de la tabla live.
            $table->unsignedBigInteger('indicador_id')->nullable()->after('variable_id');
            $table->decimal('puntaje_indicador', 6, 2)->nullable()->after('indicador_id');

            $table->index('indicador_id');
        });
    }

    public function down(): void
    {
        Schema::table('evidencias', function (Blueprint $table) {
            $table->dropIndex(['indicador_id']);
            $table->dropColumn(['indicador_id', 'puntaje_indicador']);
        });
    }
};
