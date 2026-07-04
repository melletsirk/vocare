<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Evaluacion extends Model
{
    const ESTADO_EN_PROCESO  = 'en_proceso';
    const ESTADO_COMPLETADA  = 'completada';
    const ESTADO_CERRADA     = 'cerrada';

    protected $fillable = [
        'postulacion_id',
        'evaluador_id',
        'estado',
        'puntaje_total',
        'observaciones',
        'cerrada_en',
    ];

    protected function casts(): array
    {
        return [
            'puntaje_total' => 'decimal:2',
            'cerrada_en'    => 'datetime',
        ];
    }

    public function postulacion(): BelongsTo
    {
        return $this->belongsTo(Postulacion::class);
    }

    public function evaluador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluador_id');
    }

    public function puntajes(): HasMany
    {
        return $this->hasMany(Puntaje::class);
    }
}
