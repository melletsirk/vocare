<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Variable extends Model
{
    // Tipos de cálculo disponibles
    const TIPO_SUMA_CON_TOPE       = 'SUMA_CON_TOPE';
    const TIPO_MAYOR_VALOR         = 'MAYOR_VALOR';
    const TIPO_TABLA_EQUIVALENCIA  = 'TABLA_EQUIVALENCIA';
    const TIPO_DATO_INSTITUCIONAL  = 'DATO_INSTITUCIONAL';

    // De dónde sale el puntaje bruto — ortogonal a tipo_calculo (que solo
    // gobierna cómo se topa/interpreta el número, no de dónde sale).
    const FUENTE_EVIDENCIA = 'evidencia'; // default: documento subido por el postulante
    const FUENTE_ETAPA     = 'etapa';     // evento en vivo (Clase Magistral, etc.) vía postulacion_etapa

    protected $fillable = [
        'rubro_id',
        'nombre',
        'orden',
        'puntaje_max',
        'tipo_calculo',
        'periodo_validez_anios',
        'fuente_verificacion',
        'fuente',
        'etapa_id',
    ];

    protected function casts(): array
    {
        return [
            'puntaje_max' => 'decimal:2',
        ];
    }

    public function rubro(): BelongsTo
    {
        return $this->belongsTo(Rubro::class);
    }

    /** Etapa de la que proviene el puntaje, cuando fuente = 'etapa'. */
    public function etapa(): BelongsTo
    {
        return $this->belongsTo(Etapa::class);
    }

    public function indicadores(): HasMany
    {
        return $this->hasMany(Indicador::class)->orderBy('orden');
    }
}
