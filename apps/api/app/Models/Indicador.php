<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Indicador extends Model
{
    protected $fillable = [
        'variable_id',
        'nombre',
        'puntaje',
        'orden',
        'tabla_equivalencia',
    ];

    protected function casts(): array
    {
        return [
            'puntaje'            => 'decimal:2',
            'tabla_equivalencia' => 'array',
        ];
    }

    public function variable(): BelongsTo
    {
        return $this->belongsTo(Variable::class);
    }
}
