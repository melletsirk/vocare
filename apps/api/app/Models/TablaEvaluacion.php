<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TablaEvaluacion extends Model
{
    protected $table = 'tablas_evaluacion';

    const ESTADO_BORRADOR  = 'borrador';
    const ESTADO_ACTIVO    = 'activo';
    const ESTADO_ARCHIVADO = 'archivado';

    protected $fillable = [
        'reglamento_version_id',
        'codigo_anexo',
        'nombre',
        'tipo_proceso',
        'modalidad',
        'puntaje_total_max',
        'puntaje_minimo_aprobatorio',
        'minimos_subrubro',
        'estado',
        'version_anterior_id',
    ];

    protected function casts(): array
    {
        return [
            'puntaje_total_max'          => 'decimal:2',
            'puntaje_minimo_aprobatorio' => 'decimal:2',
            'minimos_subrubro'           => 'array',
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

    public function etapas(): HasMany
    {
        return $this->hasMany(Etapa::class)->orderBy('orden');
    }

    /** Versión anterior que este fork reemplazó (null si es la original). */
    public function versionAnterior(): BelongsTo
    {
        return $this->belongsTo(self::class, 'version_anterior_id');
    }

    /** Forks posteriores hechos a partir de esta versión. */
    public function versionesSiguientes(): HasMany
    {
        return $this->hasMany(self::class, 'version_anterior_id');
    }

    /**
     * Bloqueada = ya no editable ni eliminable. Se bloquea en el momento en
     * que se activa (no cuando la usa la primera convocatoria) — activar ES
     * el punto de publicación.
     */
    public function estaBloqueada(): bool
    {
        return $this->estado !== self::ESTADO_BORRADOR;
    }
}
