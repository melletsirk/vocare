<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Convocatoria extends Model
{
    use SoftDeletes;

    const ESTADO_BORRADOR    = 'borrador';
    const ESTADO_PUBLICADA   = 'publicada';
    const ESTADO_EN_PROCESO  = 'en_proceso';
    const ESTADO_CERRADA     = 'cerrada';
    const ESTADO_DESIERTA    = 'desierta';

    protected $fillable = [
        'codigo',
        'nombre',
        'reglamento_version_id',
        'tabla_evaluacion_id',
        'tabla_snapshot',
        'tipo_proceso',
        'modalidad',
        'fecha_inicio',
        'fecha_fin',
        'estado',
        'descripcion',
        'creado_por',
    ];

    protected function casts(): array
    {
        return [
            'tabla_snapshot' => 'array',
            'fecha_inicio'   => 'date',
            'fecha_fin'      => 'date',
        ];
    }

    public function reglamentoVersion(): BelongsTo
    {
        return $this->belongsTo(ReglamentoVersion::class);
    }

    public function tablaEvaluacion(): BelongsTo
    {
        return $this->belongsTo(TablaEvaluacion::class);
    }

    public function creadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'creado_por');
    }

    public function plazas(): HasMany
    {
        return $this->hasMany(Plaza::class);
    }

    public function postulaciones(): HasMany
    {
        return $this->hasMany(Postulacion::class);
    }

    public function etapas(): HasMany
    {
        return $this->hasMany(Etapa::class)->orderBy('orden');
    }

    public function asignaciones(): HasMany
    {
        return $this->hasMany(AsignacionEvaluador::class);
    }

    public function scopePublicadas($query)
    {
        return $query->where('estado', self::ESTADO_PUBLICADA);
    }

    public function scopeEnProceso($query)
    {
        return $query->where('estado', self::ESTADO_EN_PROCESO);
    }

    /**
     * Genera el snapshot inmutable de la tabla de evaluación al publicar la convocatoria.
     */
    public function generarSnapshot(): void
    {
        $tabla = $this->tablaEvaluacion->load('rubros.variables.indicadores');

        $this->tabla_snapshot = [
            'tabla_evaluacion_id' => $tabla->id,
            'codigo_anexo'        => $tabla->codigo_anexo,
            'nombre'              => $tabla->nombre,
            'puntaje_total_max'   => $tabla->puntaje_total_max,
            'rubros'              => $tabla->rubros->map(fn($rubro) => [
                'id'                  => $rubro->id,
                'nombre'              => $rubro->nombre,
                'orden'               => $rubro->orden,
                'puntaje_max_subrubro' => $rubro->puntaje_max_subrubro,
                'variables'           => $rubro->variables->map(fn($variable) => [
                    'id'                   => $variable->id,
                    'nombre'               => $variable->nombre,
                    'orden'                => $variable->orden,
                    'puntaje_max'          => $variable->puntaje_max,
                    'tipo_calculo'         => $variable->tipo_calculo,
                    'periodo_validez_anios' => $variable->periodo_validez_anios,
                    'fuente_verificacion'  => $variable->fuente_verificacion,
                    'indicadores'          => $variable->indicadores->map(fn($ind) => [
                        'id'                => $ind->id,
                        'nombre'            => $ind->nombre,
                        'puntaje'           => $ind->puntaje,
                        'orden'             => $ind->orden,
                        'tabla_equivalencia' => $ind->tabla_equivalencia,
                    ])->toArray(),
                ])->toArray(),
            ])->toArray(),
        ];

        $this->save();
    }
}
