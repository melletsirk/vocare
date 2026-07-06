<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Evidencia extends Model
{
    use SoftDeletes;

    const ESTADO_PENDIENTE  = 'pendiente';
    const ESTADO_APROBADA   = 'aprobada';
    const ESTADO_OBSERVADA  = 'observada';
    const ESTADO_RECHAZADA  = 'rechazada';

    const MAX_BYTES_ARCHIVO  = 10_485_760;   // 10 MB en bytes
    const MIMES_PERMITIDOS   = ['application/pdf', 'image/jpeg', 'image/png'];
    const EXTENSIONES_PERMITIDAS = ['pdf', 'jpg', 'jpeg', 'png'];

    protected $fillable = [
        'expediente_id',
        'variable_id',
        'indicador_id',       // ID del indicador del snapshot al que se vincula la evidencia
        'puntaje_indicador',  // Puntaje que aporta esta evidencia (tomado del indicador)
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
        'reutilizada',
    ];

    protected function casts(): array
    {
        return [
            'fecha_emision'     => 'date',
            'fecha_validacion'  => 'datetime',
            'reutilizada'       => 'boolean',
            'tamano_bytes'      => 'integer',
            'puntaje_indicador' => 'decimal:2',
        ];
    }

    public function expediente(): BelongsTo
    {
        return $this->belongsTo(Expediente::class);
    }

    public function variable(): BelongsTo
    {
        return $this->belongsTo(Variable::class);
    }

    public function evaluador(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluador_id');
    }
}
