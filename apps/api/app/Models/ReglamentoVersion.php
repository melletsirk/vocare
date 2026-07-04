<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReglamentoVersion extends Model
{
    protected $fillable = [
        'numero_version',
        'nombre',
        'fecha_vigencia',
        'documento_fuente',
        'activo',
    ];

    protected function casts(): array
    {
        return [
            'fecha_vigencia' => 'date',
            'activo'         => 'boolean',
        ];
    }

    public function tablasEvaluacion(): HasMany
    {
        return $this->hasMany(TablaEvaluacion::class);
    }

    public function scopeActivo($query)
    {
        return $query->where('activo', true);
    }
}
