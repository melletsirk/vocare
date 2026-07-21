<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Expediente extends Model
{
    protected $table = 'expedientes';

    const MAX_BYTES_EXPEDIENTE = 209_715_200; // 200 MB en bytes

    protected $fillable = [
        'postulacion_id',
        'estado',
        'total_bytes',
    ];

    protected function casts(): array
    {
        return [
            'total_bytes' => 'integer',
        ];
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Relaciones
    // ──────────────────────────────────────────────────────────────────────────

    public function postulacion(): BelongsTo
    {
        return $this->belongsTo(Postulacion::class);
    }

    /**
     * Registros del pivote postulacion_evidencia para esta postulación.
     * Incluye el estado de cada evidencia en el contexto de esta postulación
     * y los datos de vigencia calculados.
     */
    public function postulacionEvidencias(): HasMany
    {
        return $this->hasMany(PostulacionEvidencia::class, 'postulacion_id', 'postulacion_id');
    }

    /**
     * Evidencias maestras asociadas a esta postulación (vía pivote).
     * Para obtener el listado de archivos junto con su estado en postulación,
     * usar postulacionEvidencias()->with('evidencia') en su lugar.
     */
    public function evidencias(): HasManyThrough
    {
        return $this->hasManyThrough(
            Evidencia::class,
            PostulacionEvidencia::class,
            'postulacion_id', // FK en postulacion_evidencia → postulacion_id (= expedientes.postulacion_id)
            'id',             // FK en evidencias → id
            'postulacion_id', // Llave local en expedientes
            'evidencia_id'    // FK en postulacion_evidencia → evidencia_id
        );
    }

    /**
     * Evidencias aprobadas en el contexto de esta postulación.
     */
    public function evidenciasAprobadas(): HasMany
    {
        return $this->postulacionEvidencias()
                    ->where('estado_en_postulacion', PostulacionEvidencia::ESTADO_APROBADA);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * ¿Hay espacio disponible para subir un archivo del tamaño indicado?
     * total_bytes representa el peso acumulado de archivos NUEVOS subidos
     * en esta postulación. Los archivos reutilizados no cuentan — el
     * archivo físico ya existe y no ocupa nuevo espacio en disco.
     */
    public function tieneEspacioDisponible(int $bytes): bool
    {
        return ($this->total_bytes + $bytes) <= self::MAX_BYTES_EXPEDIENTE;
    }
}
