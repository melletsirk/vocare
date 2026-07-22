<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Instancia operativa de Etapa por postulación (postulacion_etapa) y
 * asignación de evaluador por etapa (no solo por postulación completa) —
 * necesario para Clase Magistral: jurado presencial, distinto del que
 * revisó documentos, resultado transcrito después del evento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('postulacion_etapa', function (Blueprint $table) {
            $table->id();
            $table->foreignId('postulacion_id')->constrained('postulaciones');
            $table->foreignId('etapa_id')->constrained('etapas');

            // Para etapas de evento en vivo (Clase Magistral, Sesión de
            // Prácticas): hay una brecha real de tiempo entre que el resto
            // de la postulación ya se calificó y el evento ocurre. Un
            // registro "pendiente" con fecha_programada fijada refleja "en
            // espera del evento", no un estado nuevo — mismo patrón que
            // vigencia en postulacion_evidencia (estado + fecha, no un
            // estado que codifique fechas).
            $table->date('fecha_programada')->nullable();
            $table->date('fecha_realizada')->nullable();

            $table->string('estado', 20)->default('pendiente');
            // pendiente | aprobada | observada | rechazada | no_presentado

            // Puntaje que aporta este evento a la Ficha, para variables con
            // fuente='etapa' (CalculadorService lo lee en vez de sumar
            // evidencias). Null/pendiente = aporta 0, igual que evidencia
            // faltante — la postulación puede calcularse completa antes de
            // que el evento ocurra.
            $table->decimal('puntaje_bruto_evento', 6, 2)->nullable();

            // Nombres de quienes juzgaron en vivo — texto libre porque el
            // jurado puede incluir gente sin cuenta en el sistema (decano,
            // profesor invitado). Un solo puntaje por rubro/etapa, sin
            // desglose por jurado (confirmado con el cliente).
            $table->text('jurado_texto')->nullable();
            $table->text('comentario')->nullable();

            // Quién transcribió el resultado al sistema — no necesariamente
            // quien juzgó (puede ser un admin transcribiendo de un acta).
            $table->foreignId('registrado_por')->nullable()->constrained('users');

            $table->timestamps();

            $table->unique(['postulacion_id', 'etapa_id']);
            $table->index(['postulacion_id', 'estado']);
        });

        // asignaciones_evaluador: asignar por etapa específica (jurado de
        // Clase Magistral distinto de quien revisó documentos), no solo por
        // postulación completa.
        Schema::table('asignaciones_evaluador', function (Blueprint $table) {
            $table->foreignId('etapa_id')->nullable()->after('postulacion_id')->constrained('etapas');
            $table->dropUnique(['postulacion_id', 'evaluador_id']);
        });

        // COALESCE por la misma razón que en tablas_evaluacion: etapa_id
        // NULL significa "asignado a toda la postulación" (compatible con
        // el comportamiento actual) — sin normalizar, Postgres permitiría
        // asignar al mismo evaluador dos veces con etapa_id NULL.
        DB::statement("
            CREATE UNIQUE INDEX asignaciones_evaluador_unica
            ON asignaciones_evaluador (postulacion_id, evaluador_id, COALESCE(etapa_id, 0))
        ");
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS asignaciones_evaluador_unica');

        Schema::table('asignaciones_evaluador', function (Blueprint $table) {
            $table->dropForeign(['etapa_id']);
            $table->dropColumn('etapa_id');
            $table->unique(['postulacion_id', 'evaluador_id']);
        });

        Schema::dropIfExists('postulacion_etapa');
    }
};
