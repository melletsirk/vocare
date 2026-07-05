<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resultados', function (Blueprint $table) {
            $table->id();
            $table->foreignId('convocatoria_id')->constrained('convocatorias');
            $table->foreignId('plaza_id')->constrained('plazas');
            $table->foreignId('postulacion_id')->nullable()->constrained('postulaciones');
            $table->foreignId('evaluacion_id')->nullable()->constrained('evaluaciones');
            $table->decimal('puntaje_total', 7, 2)->default(0);
            $table->unsignedSmallInteger('posicion')->default(1);      // Ranking dentro de la plaza
            $table->string('estado', 20);                              // ganador|reserva|no_ganador|desierta
            $table->boolean('empate_resuelto_por_sorteo')->default(false);
            $table->timestamp('publicado_en')->nullable();
            $table->foreignId('publicado_por')->nullable()->constrained('users');
            $table->timestamps();

            $table->index(['convocatoria_id', 'plaza_id']);
            $table->index('estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resultados');
    }
};
