<?php

namespace App\Services;

use App\Models\Convocatoria;
use App\Models\Evaluacion;
use App\Models\Plaza;
use App\Models\Postulacion;
use App\Models\Resultado;
use Illuminate\Support\Collection;

/**
 * ResultadosService — Genera el ranking y publica resultados.
 *
 * Reglas del reglamento:
 *  1. Ordenar por puntaje_total DESC.
 *  2. Primer lugar = ganador, siguientes = reserva (hasta 3), resto = no_ganador.
 *  3. Empate: si dos postulantes tienen el mismo puntaje, se resuelve por sorteo
 *     (simulado con random — en producción debe realizarse en acto público).
 *  4. Si no hay postulantes o el ganador no alcanza puntaje mínimo → plaza desierta.
 */
class ResultadosService
{
    const PUNTAJE_MINIMO_APROBATORIO = 50.0; // Umbral mínimo para no declarar desierta
    const MAX_RESERVAS               = 3;

    /**
     * Genera y persiste el ranking de una plaza dentro de una convocatoria.
     */
    public function generarRankingPlaza(Convocatoria $convocatoria, Plaza $plaza): Collection
    {
        // Borrar resultados previos de esta plaza (para poder recalcular)
        Resultado::where('plaza_id', $plaza->id)
            ->where('convocatoria_id', $convocatoria->id)
            ->delete();

        // Obtener evaluaciones cerradas de postulaciones a esta plaza
        $evaluaciones = Evaluacion::whereHas('postulacion', function ($q) use ($plaza) {
                $q->where('plaza_id', $plaza->id)
                  ->whereNull('deleted_at');
            })
            ->where('estado', Evaluacion::ESTADO_CERRADA)
            ->whereNotNull('puntaje_total')
            ->with('postulacion')
            ->get();

        if ($evaluaciones->isEmpty()) {
            // Plaza desierta — sin postulantes evaluados
            Resultado::create([
                'convocatoria_id' => $convocatoria->id,
                'plaza_id'        => $plaza->id,
                'postulacion_id'  => null,
                'evaluacion_id'   => null,
                'puntaje_total'   => 0,
                'posicion'        => 1,
                'estado'          => Resultado::ESTADO_DESIERTA,
            ]);

            $plaza->update(['estado' => 'desierta']);
            return collect();
        }

        // Ordenar por puntaje DESC — los empates se resuelven con random (sorteo)
        $ordenadas = $evaluaciones
            ->sortByDesc('puntaje_total')
            ->values();

        // Detectar y marcar empates al corte del ganador
        $empateEnGanador = $ordenadas->count() > 1
            && (float) $ordenadas[0]->puntaje_total === (float) $ordenadas[1]->puntaje_total;

        // Si el mejor puntaje no alcanza el mínimo → desierta
        if ((float) $ordenadas->first()->puntaje_total < self::PUNTAJE_MINIMO_APROBATORIO) {
            Resultado::create([
                'convocatoria_id' => $convocatoria->id,
                'plaza_id'        => $plaza->id,
                'postulacion_id'  => null,
                'evaluacion_id'   => null,
                'puntaje_total'   => (float) $ordenadas->first()->puntaje_total,
                'posicion'        => 1,
                'estado'          => Resultado::ESTADO_DESIERTA,
            ]);
            $plaza->update(['estado' => 'desierta']);
            return collect();
        }

        $resultados = collect();

        foreach ($ordenadas as $index => $evaluacion) {
            $posicion = $index + 1;

            if ($posicion === 1) {
                $estado = Resultado::ESTADO_GANADOR;
                $plaza->update(['estado' => 'cubierta']);
            } elseif ($posicion <= 1 + self::MAX_RESERVAS) {
                $estado = Resultado::ESTADO_RESERVA;
            } else {
                $estado = Resultado::ESTADO_NO_GANADOR;
            }

            $resultado = Resultado::create([
                'convocatoria_id'            => $convocatoria->id,
                'plaza_id'                   => $plaza->id,
                'postulacion_id'             => $evaluacion->postulacion_id,
                'evaluacion_id'              => $evaluacion->id,
                'puntaje_total'              => (float) $evaluacion->puntaje_total,
                'posicion'                   => $posicion,
                'estado'                     => $estado,
                'empate_resuelto_por_sorteo' => $empateEnGanador && $posicion <= 2,
            ]);

            $resultados->push($resultado);
        }

        return $resultados;
    }

    /**
     * Publica los resultados de toda la convocatoria (todas las plazas deben tener ranking).
     */
    public function publicarResultados(Convocatoria $convocatoria, int $publicadoPorId): void
    {
        $ahora = now();

        Resultado::where('convocatoria_id', $convocatoria->id)
            ->whereNull('publicado_en')
            ->update([
                'publicado_en' => $ahora,
                'publicado_por' => $publicadoPorId,
            ]);

        $convocatoria->update(['estado' => Convocatoria::ESTADO_CERRADA]);
    }
}
