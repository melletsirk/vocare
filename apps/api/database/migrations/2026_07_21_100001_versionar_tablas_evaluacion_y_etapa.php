<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 2 del CRUD admin de tablas de evaluación (ver CONTEXTO.md §"Diseño
 * Fase 1 — CRUD de tablas de evaluación + Etapa").
 *
 * Cambios:
 *   1. `reglamento_versiones` pierde `activo` — el ciclo de vida
 *      (borrador|activo|archivado) pasa a `tablas_evaluacion`, porque ahora
 *      se forkea por anexo individual, no por reglamento completo.
 *   2. `tablas_evaluacion` gana el ciclo de vida, linaje (`version_anterior_id`)
 *      y los mínimos (total + sub-rubro). El unique(reglamento_version_id,
 *      codigo_anexo) se elimina — el mismo codigo_anexo se reutiliza en cada
 *      fork; la unicidad real es "una sola activa por (tipo_proceso,
 *      modalidad)", garantizada con un índice único parcial a nivel de BD
 *      (no solo en código — mismo criterio que los gaps de autorización ya
 *      corregidos).
 *   3. `variables` gana `fuente` (evidencia|etapa) y `etapa_id` — permite que
 *      una variable puntúe desde un evento en vivo (postulacion_etapa) en vez
 *      de evidencia documental.
 *   4. `etapas` deja de pertenecer a `convocatoria_id` y pasa a ser una
 *      plantilla de `tabla_evaluacion_id` — se clona/bloquea junto con el
 *      resto del anexo. Pierde `es_eliminatoria` (la lógica de
 *      aprobar/rechazar vive en los mínimos, no en una bandera separada) y
 *      `fecha_inicio`/`fecha_fin` (no tienen sentido en una plantilla
 *      reutilizable entre convocatorias — lo operativo vive en
 *      postulacion_etapa). La tabla estaba vacía (sin controller ni lógica
 *      que la usara), así que no hay datos que migrar.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── 1. reglamento_versiones: quitar activo ────────────────────────
        Schema::table('reglamento_versiones', function (Blueprint $table) {
            $table->dropColumn('activo');
        });

        // ── 2. tablas_evaluacion: ciclo de vida, linaje, mínimos ──────────
        Schema::table('tablas_evaluacion', function (Blueprint $table) {
            $table->dropUnique(['reglamento_version_id', 'codigo_anexo']);

            $table->decimal('puntaje_minimo_aprobatorio', 6, 2)->nullable()->after('puntaje_total_max');
            $table->json('minimos_subrubro')->nullable()->after('puntaje_minimo_aprobatorio');
            $table->string('estado', 20)->default('borrador')->after('minimos_subrubro');
            $table->foreignId('version_anterior_id')->nullable()->after('estado')
                  ->constrained('tablas_evaluacion');

            $table->index('estado');
        });

        // Los anexos ya seedeados están en uso — quedan como "activo", no
        // "borrador" (el default), para no bloquear convocatorias nuevas.
        DB::table('tablas_evaluacion')->update(['estado' => 'activo']);

        // Único a nivel de BD: una sola TablaEvaluacion "activo" por
        // (tipo_proceso, modalidad). COALESCE normaliza NULL en modalidad
        // (Anexo 1 y 2 no tienen modalidad) — sin esto, Postgres trataría
        // cada NULL como distinto y dos filas "activo" con modalidad NULL
        // no violarían un índice único plano.
        DB::statement("
            CREATE UNIQUE INDEX tablas_evaluacion_activa_unica
            ON tablas_evaluacion (tipo_proceso, COALESCE(modalidad, ''))
            WHERE estado = 'activo'
        ");

        // ── 3. variables: fuente + etapa_id ───────────────────────────────
        Schema::table('variables', function (Blueprint $table) {
            $table->string('fuente', 20)->default('evidencia')->after('tipo_calculo');
            $table->foreignId('etapa_id')->nullable()->after('fuente')->constrained('etapas');
        });

        // ── 4. etapas: de convocatoria_id a tabla_evaluacion_id ───────────
        Schema::table('etapas', function (Blueprint $table) {
            $table->dropForeign(['convocatoria_id']);
            $table->dropColumn(['convocatoria_id', 'es_eliminatoria', 'fecha_inicio', 'fecha_fin']);
        });

        Schema::table('etapas', function (Blueprint $table) {
            $table->foreignId('tabla_evaluacion_id')->after('id')->constrained('tablas_evaluacion');
            $table->index(['tabla_evaluacion_id', 'orden']);
        });
    }

    public function down(): void
    {
        Schema::table('etapas', function (Blueprint $table) {
            $table->dropForeign(['tabla_evaluacion_id']);
            $table->dropColumn('tabla_evaluacion_id');
        });

        Schema::table('etapas', function (Blueprint $table) {
            $table->foreignId('convocatoria_id')->nullable()->constrained('convocatorias');
            $table->boolean('es_eliminatoria')->default(false);
            $table->date('fecha_inicio')->nullable();
            $table->date('fecha_fin')->nullable();
        });

        Schema::table('variables', function (Blueprint $table) {
            $table->dropForeign(['etapa_id']);
            $table->dropColumn(['fuente', 'etapa_id']);
        });

        DB::statement('DROP INDEX IF EXISTS tablas_evaluacion_activa_unica');

        Schema::table('tablas_evaluacion', function (Blueprint $table) {
            $table->dropForeign(['version_anterior_id']);
            $table->dropColumn(['puntaje_minimo_aprobatorio', 'minimos_subrubro', 'estado', 'version_anterior_id']);
            $table->unique(['reglamento_version_id', 'codigo_anexo']);
        });

        Schema::table('reglamento_versiones', function (Blueprint $table) {
            $table->boolean('activo')->default(true);
        });
    }
};
