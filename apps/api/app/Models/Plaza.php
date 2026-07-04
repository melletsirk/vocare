<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Plaza extends Model
{
    protected $fillable = [
        'convocatoria_id',
        'facultad',
        'departamento',
        'asignatura',
        'area_conocimiento',
        'modalidad',
        'categoria_requerida',
        'horas_semana',
        'requisitos_adicionales',
        'estado',
    ];

    public function convocatoria(): BelongsTo
    {
        return $this->belongsTo(Convocatoria::class);
    }
}
