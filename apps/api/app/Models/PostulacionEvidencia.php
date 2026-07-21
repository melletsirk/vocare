<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Pivote entre Postulacion y Evidencia.
 *
 * Registra cada uso de una evidencia maestra en una postulación concreta,
 * almacenando la vigencia recalculada en el momento de la asociación.
 *
 * Columnas clave:
 *   - estado_en_postulacion : estado de la evidencia en el contexto de ESTA
 *                             postulación, independiente del estado global del
 *                             archivo (evidencias.estado).
 *   - fecha_convocatoria    : desnormalizado desde convocatorias.fecha_inicio
 *                             para preservar el cálculo histórico.
 *   - anios_validez         : desnormalizado desde variables.periodo_validez_anios
 *   - fecha_vencimiento     : calculada: fecha_emision + anios_validez años
 *   - vigente               : resultado del cálculo de vigencia
 */
class PostulacionEvidencia extends Model
{
    protected $table = 'postulacion_evidencia';

    const ESTADO_PENDIENTE  = 'pendiente';
    const ESTADO_APROBADA   = 'aprobada';
    const ESTADO_OBSERVADA  = 'observada';
    const ESTADO_RECHAZADA  = 'rechazada';

    protected $fillable = [
        'postulacion_id',
        'evidencia_id',
        'fecha_convocatoria',
        'anios_validez',
        'fecha_vencimiento',
        'vigente',
        'estado_en_postulacion',
        'comentario_postulacion',
        'evaluador_postulacion_id',
        'fecha_revision_postulacion',
    ];

    protected function casts(): array
    {
        return [
            'fecha_convocatoria'         => 'date',
            'fecha_vencimiento'          => 'date',
            'vigente'                    => 'boolean',
            'fecha_revision_postulacion' => 'datetime',
        ];
    }

    public function postulacion(): BelongsTo
    {
        return $this->belongsTo(Postulacion::class);
    }

    public function evidencia(): BelongsTo
    {
        return $this->belongsTo(Evidencia::class);
    }

    public function evaluadorPostulacion(): BelongsTo
    {
        return $this->belongsTo(User::class, 'evaluador_postulacion_id');
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Calcula y persiste vigencia para este registro usando la fecha de emisión
     * del archivo y los anios_validez ya almacenados.
     *
     * Llamar después de crear el registro cuando se dispone de fecha_emision.
     */
    public function recalcularVigencia(): void
    {
        $fechaEmision = $this->evidencia->fecha_emision;

        if ($this->anios_validez === null) {
            // Sin vencimiento (título, grado académico)
            $this->fecha_vencimiento = null;
            $this->vigente           = true;
        } elseif ($fechaEmision === null) {
            // No se puede calcular sin fecha de emisión
            $this->fecha_vencimiento = null;
            $this->vigente           = null;
        } else {
            $vencimiento             = $fechaEmision->copy()->addYears($this->anios_validez);
            $this->fecha_vencimiento = $vencimiento;
            $this->vigente           = $vencimiento->gte($this->fecha_convocatoria);
        }

        $this->save();
    }
}
