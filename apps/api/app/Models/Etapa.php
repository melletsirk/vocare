<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Etapa extends Model
{
    const TIPO_VALIDACION_REQUISITOS = 'validacion_requisitos';
    const TIPO_EVALUACION_CV         = 'evaluacion_cv';
    const TIPO_CLASE_MAGISTRAL       = 'clase_magistral';
    const TIPO_CONCURSO_OPOSICION    = 'concurso_oposicion';
    const TIPO_SESION_PRACTICAS      = 'sesion_practicas';
    const TIPO_ELABORACION_SILABO    = 'elaboracion_silabo';

    protected $fillable = [
        'convocatoria_id',
        'nombre',
        'tipo',
        'orden',
        'fecha_inicio',
        'fecha_fin',
        'es_eliminatoria',
    ];

    protected function casts(): array
    {
        return [
            'fecha_inicio'   => 'date',
            'fecha_fin'      => 'date',
            'es_eliminatoria' => 'boolean',
        ];
    }

    public function convocatoria(): BelongsTo
    {
        return $this->belongsTo(Convocatoria::class);
    }
}
