<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Asignación previa de un evaluador (o miembro de comisión) a una postulación
 * concreta dentro de una convocatoria.
 *
 * Requisito de negocio (sección 10 del spec): "Los expedientes se asignan
 * previamente a un evaluador o comisión específica." Un evaluador solo puede
 * crear su Evaluacion (POST /postulaciones/{id}/evaluacion) sobre una
 * postulación para la que exista una asignación a su nombre.
 *
 * etapa_id NULL = asignado a toda la postulación (compatible con el
 * comportamiento original). Un valor específico = jurado de esa etapa
 * únicamente — necesario para Clase Magistral, donde el jurado presencial
 * puede ser gente distinta de quien revisó documentos.
 */
class AsignacionEvaluador extends Model
{
    protected $table = 'asignaciones_evaluador';

    const TIPO_EVALUADOR = 'evaluador';
    const TIPO_COMISION   = 'comision';

    protected $fillable = [
        'convocatoria_id',
        'postulacion_id',
        'evaluador_id',
        'etapa_id',
        'tipo',
    ];

    public function convocatoria(): BelongsTo
    {
        return $this->belongsTo(Convocatoria::class);
    }

    public function postulacion(): BelongsTo
    {
        return $this->belongsTo(Postulacion::class);
    }

    public function evaluador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluador_id');
    }

    public function etapa(): BelongsTo
    {
        return $this->belongsTo(Etapa::class);
    }
}
