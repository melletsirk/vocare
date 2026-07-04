<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Rubro extends Model
{
    protected $fillable = [
        'tabla_evaluacion_id',
        'nombre',
        'orden',
        'puntaje_max_subrubro',
    ];

    protected function casts(): array
    {
        return [
            'puntaje_max_subrubro' => 'decimal:2',
        ];
    }

    public function tablaEvaluacion(): BelongsTo
    {
        return $this->belongsTo(TablaEvaluacion::class);
    }

    public function variables(): HasMany
    {
        return $this->hasMany(Variable::class)->orderBy('orden');
    }
}
