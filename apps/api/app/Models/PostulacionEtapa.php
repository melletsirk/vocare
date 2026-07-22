<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Instancia operativa de una Etapa (plantilla) para una postulación
 * concreta. Para etapas de evento en vivo (Clase Magistral, Sesión de
 * Prácticas) hay una brecha real de tiempo: el resto de la postulación
 * puede calificarse por completo antes de que el evento ocurra —
 * fecha_programada fijada con estado 'pendiente' refleja "en espera del
 * evento", sin necesitar un estado nuevo para eso.
 *
 * puntaje_bruto_evento es lo que CalculadorService lee para variables con
 * fuente='etapa', en vez de sumar evidencias. Null/pendiente aporta 0 —
 * igual que evidencia faltante.
 */
class PostulacionEtapa extends Model
{
    protected $table = 'postulacion_etapa';

    const ESTADO_PENDIENTE    = 'pendiente';
    const ESTADO_APROBADA     = 'aprobada';
    const ESTADO_OBSERVADA    = 'observada';
    const ESTADO_RECHAZADA    = 'rechazada';
    const ESTADO_NO_PRESENTADO = 'no_presentado';

    protected $fillable = [
        'postulacion_id',
        'etapa_id',
        'fecha_programada',
        'fecha_realizada',
        'estado',
        'puntaje_bruto_evento',
        'jurado_texto',
        'comentario',
        'registrado_por',
    ];

    protected function casts(): array
    {
        return [
            'fecha_programada'      => 'date',
            'fecha_realizada'       => 'date',
            'puntaje_bruto_evento'  => 'decimal:2',
        ];
    }

    public function postulacion(): BelongsTo
    {
        return $this->belongsTo(Postulacion::class);
    }

    public function etapa(): BelongsTo
    {
        return $this->belongsTo(Etapa::class);
    }

    public function registradoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registrado_por');
    }
}
