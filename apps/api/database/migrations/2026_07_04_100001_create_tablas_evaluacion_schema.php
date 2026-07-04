<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Versiones del Reglamento (ej: TUO-GTH-001/V10, Junio 2025)
        Schema::create('reglamento_versiones', function (Blueprint $table) {
            $table->id();
            $table->string('numero_version', 30);          // "V10"
            $table->string('nombre');                       // "TUO Reglamento Personal Docente"
            $table->date('fecha_vigencia');
            $table->string('documento_fuente')->nullable(); // "Resolución 9245-CU-2025"
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        // Tablas de evaluación (Fichas/Anexos) asociadas a una versión
        Schema::create('tablas_evaluacion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reglamento_version_id')->constrained('reglamento_versiones');
            $table->string('codigo_anexo', 20);             // "ANEXO_1", "ANEXO_3", etc.
            $table->string('nombre');
            $table->string('tipo_proceso');                 // contratacion, ascenso, ingreso_ordinaria
            $table->string('modalidad')->nullable();        // presencial, semipresencial_distancia
            $table->decimal('puntaje_total_max', 6, 2);
            $table->timestamps();

            $table->unique(['reglamento_version_id', 'codigo_anexo']);
        });

        // Sub Rubros (ej: "Formación Académica", "Investigación y Producción")
        Schema::create('rubros', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tabla_evaluacion_id')->constrained('tablas_evaluacion');
            $table->string('nombre');
            $table->unsignedSmallInteger('orden');
            $table->decimal('puntaje_max_subrubro', 6, 2); // Tope nivel Sub Rubro
            $table->timestamps();
        });

        // Variables dentro de cada Sub Rubro
        Schema::create('variables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rubro_id')->constrained('rubros');
            $table->string('nombre');
            $table->unsignedSmallInteger('orden');
            $table->decimal('puntaje_max', 6, 2);          // Tope nivel Variable
            $table->string('tipo_calculo', 30);             // SUMA_CON_TOPE|MAYOR_VALOR|TABLA_EQUIVALENCIA|DATO_INSTITUCIONAL
            $table->unsignedSmallInteger('periodo_validez_anios')->nullable(); // null = sin vencimiento
            $table->string('fuente_verificacion')->nullable();
            $table->timestamps();
        });

        // Indicadores individuales dentro de cada Variable
        Schema::create('indicadores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('variable_id')->constrained('variables');
            $table->string('nombre');
            $table->decimal('puntaje', 6, 2);
            $table->unsignedSmallInteger('orden');
            $table->json('tabla_equivalencia')->nullable(); // Para tipo TABLA_EQUIVALENCIA: [{min,max,puntaje}]
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('indicadores');
        Schema::dropIfExists('variables');
        Schema::dropIfExists('rubros');
        Schema::dropIfExists('tablas_evaluacion');
        Schema::dropIfExists('reglamento_versiones');
    }
};
