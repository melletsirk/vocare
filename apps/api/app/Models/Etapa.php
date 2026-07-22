<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Plantilla de etapa — pertenece al Anexo (TablaEvaluacion), no a una
 * Convocatoria. El spec dice que las etapas "varían según tipo de
 * convocatoria" — el conjunto y orden es propiedad del anexo, se clona y se
 * bloquea junto con el resto de su TablaEvaluacion al forkear.
 *
 * Sin es_eliminatoria: la lógica de aprobar/rechazar vive en los mínimos de
 * TablaEvaluacion, no en una bandera separada aquí. Sin fecha_inicio/
 * fecha_fin: no tienen sentido en algo reutilizable entre convocatorias — lo
 * operativo (fecha_programada, resultado, jurado) vive en PostulacionEtapa.
 */
class Etapa extends Model
{
    const TIPO_VALIDACION_REQUISITOS = 'validacion_requisitos';
    const TIPO_EVALUACION_CV         = 'evaluacion_cv';
    const TIPO_CLASE_MAGISTRAL       = 'clase_magistral';
    const TIPO_CONCURSO_OPOSICION    = 'concurso_oposicion';
    const TIPO_SESION_PRACTICAS      = 'sesion_practicas';
    const TIPO_ELABORACION_SILABO    = 'elaboracion_silabo';

    protected $fillable = [
        'tabla_evaluacion_id',
        'nombre',
        'tipo',
        'orden',
    ];

    public function tablaEvaluacion(): BelongsTo
    {
        return $this->belongsTo(TablaEvaluacion::class);
    }

    public function postulacionEtapas(): HasMany
    {
        return $this->hasMany(PostulacionEtapa::class);
    }
}
