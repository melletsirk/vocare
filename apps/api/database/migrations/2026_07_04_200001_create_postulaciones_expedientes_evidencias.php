<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Postulaciones
        Schema::create('postulaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users');          // El postulante
            $table->foreignId('convocatoria_id')->constrained('convocatorias');
            $table->foreignId('plaza_id')->constrained('plazas');
            $table->string('estado', 30)->default('en_proceso');         // en_proceso|observada|rechazada|aprobada_etapa|ganadora
            $table->string('categoria_actual', 50)->nullable();          // Capturada en cada postulación, no en el perfil
            $table->timestamp('fecha_envio')->nullable();                // Null hasta que el postulante envía
            $table->timestamp('fecha_cierre')->nullable();
            $table->text('motivo_rechazo')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['user_id', 'plaza_id']);                     // Un postulante, una plaza
            $table->index(['convocatoria_id', 'estado']);
        });

        // Snapshot del CV al momento de enviar la postulación (inmutable)
        Schema::create('cv_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('postulacion_id')->constrained('postulaciones')->unique();
            $table->json('datos');                                        // JSON libre con los datos del CV
            $table->timestamp('tomado_en');
            $table->timestamps();
        });

        // Expediente digital (uno por postulación)
        Schema::create('expedientes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('postulacion_id')->constrained('postulaciones')->unique();
            $table->string('estado', 30)->default('en_preparacion');     // en_preparacion|enviado|en_revision|observado|cerrado
            $table->decimal('total_bytes', 14, 0)->default(0);           // Bytes acumulados (límite 200MB)
            $table->timestamps();
        });

        // Evidencias individuales dentro de un expediente
        Schema::create('evidencias', function (Blueprint $table) {
            $table->id();
            $table->foreignId('expediente_id')->constrained('expedientes');
            $table->foreignId('variable_id')->constrained('variables');  // A qué variable del anexo pertenece
            $table->string('nombre_original');                           // Nombre del archivo subido por el usuario
            $table->string('ruta_archivo');                              // Ruta relativa en storage/expedientes/
            $table->string('mime_type', 50);                             // application/pdf | image/jpeg | image/png
            $table->unsignedBigInteger('tamano_bytes');
            $table->string('hash_archivo', 64);                          // SHA-256 del contenido
            $table->date('fecha_emision')->nullable();                   // Fecha del documento (no de carga)
            $table->string('estado', 20)->default('pendiente');          // pendiente|aprobada|observada|rechazada
            $table->text('comentario_observacion')->nullable();          // Visible para el postulante
            $table->foreignId('evaluador_id')->nullable()->constrained('users');
            $table->timestamp('fecha_validacion')->nullable();
            $table->boolean('reutilizada')->default(false);              // Si viene de otra postulación previa
            $table->timestamps();
            $table->softDeletes();

            $table->index(['expediente_id', 'estado']);
            $table->index('variable_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('evidencias');
        Schema::dropIfExists('expedientes');
        Schema::dropIfExists('cv_snapshots');
        Schema::dropIfExists('postulaciones');
    }
};
