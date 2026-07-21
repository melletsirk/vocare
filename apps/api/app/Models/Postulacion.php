<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Postulacion extends Model
{
    use SoftDeletes;

    protected $table = 'postulaciones';

    const ESTADO_EN_PROCESO    = 'en_proceso';
    const ESTADO_OBSERVADA     = 'observada';
    const ESTADO_RECHAZADA     = 'rechazada';
    const ESTADO_APROBADA      = 'aprobada_etapa';
    const ESTADO_GANADORA      = 'ganadora';

    protected $fillable = [
        'user_id',
        'convocatoria_id',
        'plaza_id',
        'estado',
        'categoria_actual',
        'fecha_envio',
        'fecha_cierre',
        'motivo_rechazo',
    ];

    protected function casts(): array
    {
        return [
            'fecha_envio'  => 'datetime',
            'fecha_cierre' => 'datetime',
        ];
    }

    public function postulante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function convocatoria(): BelongsTo
    {
        return $this->belongsTo(Convocatoria::class);
    }

    public function plaza(): BelongsTo
    {
        return $this->belongsTo(Plaza::class);
    }

    public function cvSnapshot(): HasOne
    {
        return $this->hasOne(CvSnapshot::class);
    }

    public function expediente(): HasOne
    {
        return $this->hasOne(Expediente::class);
    }

    public function evaluacion(): HasOne
    {
        return $this->hasOne(Evaluacion::class);
    }

    /**
     * Evidencias asociadas a esta postulación con su estado y vigencia
     * calculados en el contexto de esta postulación.
     */
    public function postulacionEvidencias(): HasMany
    {
        return $this->hasMany(PostulacionEvidencia::class);
    }

    /** ¿Ya fue enviada formalmente? */
    public function estaEnviada(): bool
    {
        return $this->fecha_envio !== null;
    }
}
