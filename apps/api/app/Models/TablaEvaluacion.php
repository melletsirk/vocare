<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TablaEvaluacion extends Model
{
    protected $fillable = [
        'reglamento_version_id',
        'codigo_anexo',
        'nombre',
        'tipo_proceso',
        'modalidad',
        'puntaje_total_max',
    ];

    protected function casts(): array
    {
        return [
            'puntaje_total_max' => 'decimal:2',
        ];
    }

    public function reglamentoVersion(): BelongsTo
    {
        return $this->belongsTo(ReglamentoVersion::class);
    }

    public function rubros(): HasMany
    {
        return $this->hasMany(Rubro::class)->orderBy('orden');
    }
}
