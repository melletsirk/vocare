<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rediseño del modelo de evidencias para soportar reutilización entre
 * postulaciones (sección 10 del spec).
 *
 * ANTES:
 *   evidencias → expediente_id → expedientes (1:1) → postulacion_id
 *   evidencias.reutilizada = boolean decorativo (sin FK origen)
 *
 * DESPUÉS:
 *   evidencias → user_id          (la evidencia pertenece al postulante)
 *   postulacion_evidencia          (pivote: vigencia recalculada por convocatoria)
 *
 * Pasos:
 *   1. Crear tabla postulacion_evidencia
 *   2. Agregar user_id (nullable) a evidencias
 *   3. Poblar user_id desde la cadena: evidencia → expediente → postulacion → user
 *   4. Poblar postulacion_evidencia desde los datos existentes
 *   5. Hacer user_id NOT NULL
 *   6. Eliminar expediente_id y reutilizada de evidencias
 *   7. Agregar FK definitiva a user_id
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. Tabla pivote ────────────────────────────────────────────────────
        Schema::create('postulacion_evidencia', function (Blueprint $table) {
            $table->id();
            $table->foreignId('postulacion_id')->constrained('postulaciones');
            $table->foreignId('evidencia_id')->constrained('evidencias');

            // Vigencia calculada en el momento de la asociación (desnormalizado
            // intencionalmente para preservar el cálculo histórico si cambia el
            // reglamento después de asociar la evidencia).
            $table->date('fecha_convocatoria')->nullable();          // convocatorias.fecha_inicio al asociar
            $table->unsignedSmallInteger('anios_validez')->nullable(); // variables.periodo_validez_anios al asociar
            $table->date('fecha_vencimiento')->nullable();           // fecha_emision + anios_validez; null = sin vencimiento
            $table->boolean('vigente')->nullable();                  // resultado del cálculo; null si no se pudo calcular

            // Estado de la evidencia en el contexto de ESTA postulación.
            // Independiente del estado "maestro" (evidencias.estado).
            $table->string('estado_en_postulacion', 20)->default('pendiente');
            // pendiente | aprobada | observada | rechazada

            $table->text('comentario_postulacion')->nullable();      // Observación específica de esta postulación
            $table->foreignId('evaluador_postulacion_id')
                  ->nullable()
                  ->constrained('users');
            $table->timestamp('fecha_revision_postulacion')->nullable();

            $table->timestamps();

            // Una evidencia no puede asociarse dos veces a la misma postulación
            $table->unique(['postulacion_id', 'evidencia_id']);
            $table->index(['postulacion_id', 'estado_en_postulacion']);
            $table->index('evidencia_id');
        });

        // ── 2. Agregar user_id a evidencias (nullable primero, para poblar) ───
        Schema::table('evidencias', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->after('id');
            $table->index('user_id');
        });

        // ── 3. Poblar user_id ─────────────────────────────────────────────────
        //
        // Cadena: evidencias.expediente_id → expedientes.postulacion_id
        //         → postulaciones.user_id
        //
        // La sintaxis del UPDATE varía entre PostgreSQL y SQLite:
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('
                UPDATE evidencias e
                SET user_id = p.user_id
                FROM expedientes ex
                JOIN postulaciones p ON p.id = ex.postulacion_id
                WHERE ex.id = e.expediente_id
            ');
        } else {
            // SQLite (entorno de desarrollo / tests)
            DB::statement('
                UPDATE evidencias
                SET user_id = (
                    SELECT p.user_id
                    FROM expedientes ex
                    JOIN postulaciones p ON p.id = ex.postulacion_id
                    WHERE ex.id = evidencias.expediente_id
                )
                WHERE expediente_id IS NOT NULL
            ');
        }

        // ── 4. Poblar postulacion_evidencia desde datos existentes ────────────
        //
        // Para cada evidencia que tenía expediente_id, creamos el pivote con la
        // vigencia calculada contra la fecha de inicio de la convocatoria original.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("
                INSERT INTO postulacion_evidencia (
                    postulacion_id,
                    evidencia_id,
                    fecha_convocatoria,
                    anios_validez,
                    fecha_vencimiento,
                    vigente,
                    estado_en_postulacion,
                    created_at,
                    updated_at
                )
                SELECT
                    ex.postulacion_id,
                    e.id,
                    c.fecha_inicio,
                    v.periodo_validez_anios,
                    CASE
                        WHEN v.periodo_validez_anios IS NULL THEN NULL
                        WHEN e.fecha_emision IS NULL         THEN NULL
                        ELSE (e.fecha_emision + (v.periodo_validez_anios || ' years')::interval)::date
                    END,
                    CASE
                        WHEN v.periodo_validez_anios IS NULL THEN TRUE
                        WHEN e.fecha_emision IS NULL         THEN NULL
                        ELSE (e.fecha_emision + (v.periodo_validez_anios || ' years')::interval)::date >= c.fecha_inicio
                    END,
                    e.estado,
                    NOW(),
                    NOW()
                FROM evidencias e
                JOIN expedientes ex  ON ex.id  = e.expediente_id
                JOIN postulaciones p ON p.id   = ex.postulacion_id
                JOIN convocatorias c ON c.id   = p.convocatoria_id
                JOIN variables v     ON v.id   = e.variable_id
                WHERE e.expediente_id IS NOT NULL
            ");
        } else {
            // SQLite (sin soporte de interval, usamos date())
            DB::statement("
                INSERT INTO postulacion_evidencia (
                    postulacion_id,
                    evidencia_id,
                    fecha_convocatoria,
                    anios_validez,
                    fecha_vencimiento,
                    vigente,
                    estado_en_postulacion,
                    created_at,
                    updated_at
                )
                SELECT
                    ex.postulacion_id,
                    e.id,
                    c.fecha_inicio,
                    v.periodo_validez_anios,
                    CASE
                        WHEN v.periodo_validez_anios IS NULL THEN NULL
                        WHEN e.fecha_emision IS NULL         THEN NULL
                        ELSE date(e.fecha_emision, '+' || v.periodo_validez_anios || ' years')
                    END,
                    CASE
                        WHEN v.periodo_validez_anios IS NULL THEN 1
                        WHEN e.fecha_emision IS NULL         THEN NULL
                        ELSE (date(e.fecha_emision, '+' || v.periodo_validez_anios || ' years') >= c.fecha_inicio)
                    END,
                    e.estado,
                    CURRENT_TIMESTAMP,
                    CURRENT_TIMESTAMP
                FROM evidencias e
                JOIN expedientes ex  ON ex.id  = e.expediente_id
                JOIN postulaciones p ON p.id   = ex.postulacion_id
                JOIN convocatorias c ON c.id   = p.convocatoria_id
                JOIN variables v     ON v.id   = e.variable_id
                WHERE e.expediente_id IS NOT NULL
            ");
        }

        // ── 5. Hacer user_id NOT NULL ─────────────────────────────────────────
        Schema::table('evidencias', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });

        // ── 6. Eliminar columnas del modelo viejo ─────────────────────────────
        Schema::table('evidencias', function (Blueprint $table) {
            // Primero eliminamos el índice compuesto que incluía expediente_id
            $table->dropIndex(['expediente_id', 'estado']);

            $table->dropColumn('expediente_id');
            $table->dropColumn('reutilizada');
        });

        // Recrear el índice de estado sin expediente_id
        Schema::table('evidencias', function (Blueprint $table) {
            $table->index(['user_id', 'estado']);
        });

        // ── 7. FK definitiva a users ──────────────────────────────────────────
        Schema::table('evidencias', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users');
        });
    }

    public function down(): void
    {
        // Revertir en orden inverso

        // Quitar FK a users
        Schema::table('evidencias', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropIndex(['user_id', 'estado']);
        });

        // Restaurar columnas eliminadas
        Schema::table('evidencias', function (Blueprint $table) {
            $table->foreignId('expediente_id')->nullable()->constrained('expedientes');
            $table->boolean('reutilizada')->default(false);

            $table->index(['expediente_id', 'estado']);
        });

        // Restaurar user_id como nullable antes de eliminarla
        Schema::table('evidencias', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
            $table->dropColumn('user_id');
        });

        // Eliminar tabla pivote
        Schema::dropIfExists('postulacion_evidencia');
    }
};
