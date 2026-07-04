<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Convocatorias
        Schema::create('convocatorias', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 30)->unique();          // "CONV-2025-001"
            $table->string('nombre');
            $table->foreignId('reglamento_version_id')->constrained('reglamento_versiones');
            $table->foreignId('tabla_evaluacion_id')->constrained('tablas_evaluacion');
            // Snapshot inmutable de la tabla al momento de crear la convocatoria
            $table->json('tabla_snapshot')->nullable();
            $table->string('tipo_proceso');                  // contratacion, ascenso, ingreso_ordinaria
            $table->string('modalidad')->nullable();
            $table->date('fecha_inicio');
            $table->date('fecha_fin');
            $table->string('estado', 20)->default('borrador'); // borrador|publicada|en_proceso|cerrada|desierta
            $table->text('descripcion')->nullable();
            $table->foreignId('creado_por')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index('estado');
            $table->index('tipo_proceso');
        });

        // Plazas dentro de una convocatoria
        Schema::create('plazas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('convocatoria_id')->constrained('convocatorias');
            $table->string('facultad');
            $table->string('departamento');
            $table->string('asignatura');
            $table->string('area_conocimiento')->nullable();
            $table->string('modalidad')->nullable();          // presencial|semipresencial|distancia
            $table->string('categoria_requerida')->nullable(); // auxiliar|asociado|principal
            $table->string('horas_semana')->nullable();
            $table->text('requisitos_adicionales')->nullable();
            $table->string('estado', 20)->default('activa'); // activa|cubierta|desierta
            $table->timestamps();

            $table->index('convocatoria_id');
        });

        // Etapas del proceso de evaluación
        Schema::create('etapas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('convocatoria_id')->constrained('convocatorias');
            $table->string('nombre');
            $table->string('tipo', 30);                      // validacion_requisitos|evaluacion_cv|clase_magistral|oposicion|practica
            $table->unsignedSmallInteger('orden');
            $table->date('fecha_inicio')->nullable();
            $table->date('fecha_fin')->nullable();
            $table->boolean('es_eliminatoria')->default(false);
            $table->timestamps();

            $table->index(['convocatoria_id', 'orden']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('etapas');
        Schema::dropIfExists('plazas');
        Schema::dropIfExists('convocatorias');
    }
};
