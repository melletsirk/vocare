<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Resultado extends Model
{
    const ESTADO_GANADOR         = 'ganador';
    const ESTADO_RESERVA         = 'reserva';
    const ESTADO_NO_GANADOR      = 'no_ganador';
    const ESTADO_DESIERTA        = 'desierta';
    // Grupo de postulaciones empatadas en una posición que define
    // ganador/reserva; requiere que la comisión decida el orden manualmente
    // (ver ResultadosService::resolverEmpate) antes de poder publicar.
    const ESTADO_EMPATE_PENDIENTE = 'empate_pendiente';

    protected $fillable = [
        'convocatoria_id',
        'plaza_id',
        'postulacion_id',
        'evaluacion_id',
        'puntaje_total',
        'posicion',
        'estado',
        'empatada',
        'orden_manual',
        'decidido_por',
        'decidido_en',
        'publicado_en',
        'publicado_por',
    ];

    protected function casts(): array
    {
        return [
            'puntaje_total' => 'decimal:2',
            'empatada'      => 'boolean',
            'orden_manual'  => 'boolean',
            'decidido_en'   => 'datetime',
            'publicado_en'  => 'datetime',
        ];
    }

    public function decididoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decidido_por');
    }

    public function convocatoria(): BelongsTo
    {
        return $this->belongsTo(Convocatoria::class);
    }

    public function plaza(): BelongsTo
    {
        return $this->belongsTo(Plaza::class);
    }

    public function postulacion(): BelongsTo
    {
        return $this->belongsTo(Postulacion::class);
    }

    public function evaluacion(): BelongsTo
    {
        return $this->belongsTo(Evaluacion::class);
    }

    public function publicadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'publicado_por');
    }
}
