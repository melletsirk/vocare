<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Evidencia maestra — pertenece al postulante (user), no a una postulación.
 *
 * Una evidencia validada puede reutilizarse en múltiples postulaciones sin
 * nueva validación humana del archivo. La vigencia se recalcula en cada
 * postulación a través de PostulacionEvidencia.
 *
 * Estados del archivo (estado global):
 *   pendiente  → aún no ha sido revisada por ningún evaluador
 *   aprobada   → validada por un evaluador; el archivo es genuino
 *   observada  → el evaluador solicitó corrección/aclaración
 *   rechazada  → excluida del selector de reutilización en nuevas postulaciones
 *
 * El estado en el contexto de una postulación específica vive en
 * PostulacionEvidencia.estado_en_postulacion.
 */
class Evidencia extends Model
{
    use SoftDeletes;

    const ESTADO_PENDIENTE  = 'pendiente';
    const ESTADO_APROBADA   = 'aprobada';
    const ESTADO_OBSERVADA  = 'observada';
    const ESTADO_RECHAZADA  = 'rechazada';

    const MAX_BYTES_ARCHIVO      = 10_485_760;   // 10 MB en bytes
    const MIMES_PERMITIDOS       = ['application/pdf', 'image/jpeg', 'image/png'];
    const EXTENSIONES_PERMITIDAS = ['pdf', 'jpg', 'jpeg', 'png'];

    protected $fillable = [
        'user_id',
        'variable_id',
        'indicador_id',
        'puntaje_indicador',
        'nombre_original',
        'ruta_archivo',
        'mime_type',
        'tamano_bytes',
        'hash_archivo',
        'fecha_emision',
        'estado',
        'comentario_observacion',
        'evaluador_id',
        'fecha_validacion',
    ];

    protected function casts(): array
    {
        return [
            'fecha_emision'     => 'date',
            'fecha_validacion'  => 'datetime',
            'tamano_bytes'      => 'integer',
            'puntaje_indicador' => 'decimal:2',
        ];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Relaciones
    // ──────────────────────────────────────────────────────────────────────────

    /** Postulante dueño del archivo. */
    public function postulante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Variable del reglamento a la que corresponde esta evidencia. */
    public function variable(): BelongsTo
    {
        return $this->belongsTo(Variable::class);
    }

    /** Evaluador que realizó la validación global del archivo. */
    public function evaluador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluador_id');
    }

    /** Usos de esta evidencia en postulaciones (vía pivote). */
    public function postulacionEvidencias(): HasMany
    {
        return $this->hasMany(PostulacionEvidencia::class);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Scopes
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Evidencias disponibles para reutilizar: solo las no rechazadas.
     * Las rechazadas se excluyen del selector según la decisión de diseño.
     */
    public function scopeReutilizables($query)
    {
        return $query->where('estado', '!=', self::ESTADO_RECHAZADA);
    }
}
