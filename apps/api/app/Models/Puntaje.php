<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Puntaje extends Model
{
    protected $fillable = [
        'evaluacion_id',
        'variable_id',
        'nombre_variable',
        'puntaje_bruto',
        'puntaje_variable',
        'puntaje_subrubro',
        'tipo_calculo',
        'indicador_id',
        'valor_entrada',
        'detalle',
    ];

    protected function casts(): array
    {
        return [
            'puntaje_bruto'    => 'decimal:2',
            'puntaje_variable' => 'decimal:2',
            'puntaje_subrubro' => 'decimal:2',
            'valor_entrada'    => 'decimal:2',
            'detalle'          => 'array',
        ];
    }

    public function evaluacion(): BelongsTo
    {
        return $this->belongsTo(Evaluacion::class);
    }

    public function variable(): BelongsTo
    {
        return $this->belongsTo(Variable::class);
    }
}
