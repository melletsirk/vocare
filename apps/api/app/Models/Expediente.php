<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function postulacion(): BelongsTo
    {
        return $this->belongsTo(Postulacion::class);
    }

    public function evidencias(): HasMany
    {
        return $this->hasMany(Evidencia::class);
    }

    public function evidenciasAprobadas(): HasMany
    {
        return $this->hasMany(Evidencia::class)->where('estado', 'aprobada');
    }

    /** ¿Hay espacio disponible para subir un archivo del tamaño indicado? */
    public function tieneEspacioDisponible(int $bytes): bool
    {
        return ($this->total_bytes + $bytes) <= self::MAX_BYTES_EXPEDIENTE;
    }
}
