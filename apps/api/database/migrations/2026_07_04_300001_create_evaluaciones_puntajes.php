<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Asignación de evaluadores a postulaciones
        Schema::create('asignaciones_evaluador', function (Blueprint $table) {
            $table->id();
            $table->foreignId('convocatoria_id')->constrained('convocatorias');
            $table->foreignId('postulacion_id')->constrained('postulaciones');
            $table->foreignId('evaluador_id')->constrained('users');
            $table->string('tipo', 20)->default('evaluador'); // evaluador | comision
            $table->timestamps();

            $table->unique(['postulacion_id', 'evaluador_id']); // Un evaluador no repite por postulación
        });

        // Evaluación: una por postulación (o por etapa si el proceso lo requiere)
        Schema::create('evaluaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('postulacion_id')->constrained('postulaciones')->unique();
            $table->foreignId('evaluador_id')->constrained('users');
            $table->string('estado', 20)->default('en_proceso'); // en_proceso | completada | cerrada
            $table->decimal('puntaje_total', 7, 2)->nullable();  // Calculado por el motor
            $table->text('observaciones')->nullable();
            $table->timestamp('cerrada_en')->nullable();
            $table->timestamps();
        });

        // Puntajes por variable (resultado del motor de cálculo)
        Schema::create('puntajes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('evaluacion_id')->constrained('evaluaciones');
            $table->unsignedBigInteger('variable_id');           // ID en la tabla_snapshot
            $table->string('nombre_variable');                   // Desnormalizado para trazabilidad
            $table->decimal('puntaje_bruto', 7, 2);              // Antes de aplicar tope de variable
            $table->decimal('puntaje_variable', 7, 2);           // Después de tope de variable
            $table->decimal('puntaje_subrubro', 7, 2)->nullable(); // Después de tope de sub-rubro (calculado al agregar)
            $table->string('tipo_calculo', 30);
            // Para MAYOR_VALOR / TABLA_EQUIVALENCIA: el indicador o valor elegido
            $table->unsignedBigInteger('indicador_id')->nullable();
            $table->decimal('valor_entrada', 7, 2)->nullable();  // Para TABLA_EQUIVALENCIA (ej: nota 17.5)
            $table->json('detalle')->nullable();                  // Evidencias que aportaron al puntaje
            $table->timestamps();

            $table->index('evaluacion_id');
            $table->index('variable_id');

            $table->foreign('variable_id')->references('id')->on('variables');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('puntajes');
        Schema::dropIfExists('evaluaciones');
        Schema::dropIfExists('asignaciones_evaluador');
    }
};
