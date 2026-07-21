<?php

namespace App\Services;

use App\Models\Evaluacion;
use App\Models\PostulacionEvidencia;
use App\Models\Puntaje;
use App\Models\Variable;
use Illuminate\Support\Collection;

/**
 * CalculadorService — Motor de cálculo de puntajes de Vocare
 *
 * Implementa los cuatro tipos de cálculo del reglamento con tope de dos niveles:
 *   1. Tope por Variable  (puntaje_max de la variable)
 *   2. Tope por Sub Rubro (puntaje_max_subrubro del rubro)
 *
 * Tipos de cálculo soportados:
 *   - SUMA_CON_TOPE       : suma evidencias aprobadas, aplica tope variable
 *   - MAYOR_VALOR         : toma el puntaje más alto entre indicadores válidos
 *   - TABLA_EQUIVALENCIA  : mapea un valor externo (nota/nivel) a puntaje fijo
 *   - DATO_INSTITUCIONAL  : igual que SUMA_CON_TOPE pero marcado como fuente institucional
 */
class CalculadorService
{
    /**
     * Calcula y persiste todos los puntajes de una evaluación.
     * Retorna el puntaje total de la evaluación.
     */
    public function calcular(Evaluacion $evaluacion): float
    {
        $postulacion = $evaluacion->postulacion->load('convocatoria', 'postulacionEvidencias.evidencia');
        $snapshot    = $postulacion->convocatoria->tabla_snapshot;

        if (!$snapshot) {
            throw new \RuntimeException('La convocatoria no tiene snapshot de tabla de evaluación.');
        }

        // Borrar puntajes anteriores si se recalcula
        $evaluacion->puntajes()->delete();

        $puntajeTotal = 0.0;

        foreach ($snapshot['rubros'] as $rubroData) {
            $puntajeRubroAcumulado = 0.0;

            foreach ($rubroData['variables'] as $varData) {
                $variable = (object) $varData;

                // Obtener evidencias aprobadas de esta variable
                $evidencias = $this->evidenciasAprobadasDeVariable(
                    $evaluacion,
                    $variable->id
                );

                // Calcular puntaje bruto y aplicar tope de variable
                [$puntajeBruto, $detalle, $indicadorId, $valorEntrada] = $this->calcularVariable(
                    $variable,
                    $evidencias,
                    $evaluacion
                );

                $puntajeVariableConTope = min($puntajeBruto, (float) $variable->puntaje_max);
                $puntajeRubroAcumulado += $puntajeVariableConTope;

                // Persistir puntaje de la variable
                Puntaje::create([
                    'evaluacion_id'  => $evaluacion->id,
                    'variable_id'    => $variable->id,
                    'nombre_variable' => $variable->nombre,
                    'puntaje_bruto'  => $puntajeBruto,
                    'puntaje_variable' => $puntajeVariableConTope,
                    'tipo_calculo'   => $variable->tipo_calculo,
                    'indicador_id'   => $indicadorId,
                    'valor_entrada'  => $valorEntrada,
                    'detalle'        => $detalle,
                ]);
            }

            // Aplicar tope de Sub Rubro
            $puntajeRubroFinal = min($puntajeRubroAcumulado, (float) $rubroData['puntaje_max_subrubro']);
            $puntajeTotal += $puntajeRubroFinal;

            // Actualizar puntaje_subrubro en los puntajes de este rubro
            $idsVariablesRubro = collect($rubroData['variables'])->pluck('id');
            $evaluacion->puntajes()
                ->whereIn('variable_id', $idsVariablesRubro)
                ->update(['puntaje_subrubro' => $puntajeRubroFinal]);
        }

        $puntajeTotal = round($puntajeTotal, 2);

        $evaluacion->update([
            'puntaje_total' => $puntajeTotal,
            'estado'        => Evaluacion::ESTADO_COMPLETADA,
        ]);

        return $puntajeTotal;
    }

    // -------------------------------------------------------------------------
    // Lógica por tipo de cálculo
    // -------------------------------------------------------------------------

    private function calcularVariable(
        object $variable,
        Collection $evidencias,
        Evaluacion $evaluacion
    ): array {
        return match ($variable->tipo_calculo) {
            Variable::TIPO_SUMA_CON_TOPE,
            Variable::TIPO_DATO_INSTITUCIONAL => $this->sumaConTope($variable, $evidencias),
            Variable::TIPO_MAYOR_VALOR         => $this->mayorValor($variable, $evidencias),
            Variable::TIPO_TABLA_EQUIVALENCIA  => $this->tablaEquivalencia($variable, $evaluacion),
            default                            => [0.0, [], null, null],
        };
    }

    /**
     * SUMA_CON_TOPE / DATO_INSTITUCIONAL
     * Suma los puntajes de todas las evidencias aprobadas.
     * El tope se aplica fuera de este método (nivel variable y nivel sub-rubro).
     */
    private function sumaConTope(object $variable, Collection $evidencias): array
    {
        $puntajeBruto = 0.0;
        $detalle      = [];

        foreach ($evidencias as $evidencia) {
            // Cada evidencia aporta el puntaje del indicador al que está vinculada
            // Si no tiene indicador, aportamos 0 (el evaluador debió vincularla)
            $aporte = (float) ($evidencia->puntaje_indicador ?? 0);
            $puntajeBruto += $aporte;

            $detalle[] = [
                'evidencia_id'   => $evidencia->id,
                'nombre_archivo' => $evidencia->nombre_original,
                'aporte'         => $aporte,
            ];
        }

        return [$puntajeBruto, $detalle, null, null];
    }

    /**
     * MAYOR_VALOR
     * Toma el puntaje más alto entre las evidencias aprobadas.
     * No se suman — se elige el mejor indicador válido.
     */
    private function mayorValor(object $variable, Collection $evidencias): array
    {
        if ($evidencias->isEmpty()) {
            return [0.0, [], null, null];
        }

        $mejor   = $evidencias->sortByDesc('puntaje_indicador')->first();
        $puntaje = (float) ($mejor->puntaje_indicador ?? 0);

        $detalle = [
            [
                'evidencia_id'   => $mejor->id,
                'nombre_archivo' => $mejor->nombre_original,
                'aporte'         => $puntaje,
                'criterio'       => 'MAYOR_VALOR — se tomó el indicador más alto',
            ],
        ];

        return [$puntaje, $detalle, $mejor->indicador_id ?? null, null];
    }

    /**
     * TABLA_EQUIVALENCIA
     * Lee el valor de entrada almacenado en el puntaje previo (lo ingresa el evaluador)
     * y lo mapea contra la tabla de equivalencia del indicador.
     *
     * Ejemplo: nota de clase magistral 17.5 → busca en [{min:16,max:17,pts:6},{min:18,max:20,pts:8}...] → 6 pts
     */
    private function tablaEquivalencia(object $variable, Evaluacion $evaluacion): array
    {
        // El evaluador debe haber guardado valor_entrada y el indicador previamente
        $puntajeExistente = $evaluacion->puntajes()
            ->where('variable_id', $variable->id)
            ->first();

        if (!$puntajeExistente || $puntajeExistente->valor_entrada === null) {
            return [0.0, [], null, null];
        }

        $valorEntrada = (float) $puntajeExistente->valor_entrada;
        $indicadorId  = $puntajeExistente->indicador_id;
        $tabla        = $puntajeExistente->detalle['tabla_equivalencia'] ?? [];

        $puntajeMapeado = 0.0;
        foreach ($tabla as $rango) {
            if ($valorEntrada >= (float) $rango['min'] && $valorEntrada <= (float) $rango['max']) {
                $puntajeMapeado = (float) $rango['puntaje'];
                break;
            }
        }

        $detalle = [[
            'valor_entrada'  => $valorEntrada,
            'puntaje_mapeado' => $puntajeMapeado,
            'tabla'          => $tabla,
        ]];

        return [$puntajeMapeado, $detalle, $indicadorId, $valorEntrada];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Obtiene las evidencias aprobadas y vigentes de una variable para una evaluación.
     *
     * Una evidencia cuenta para el cálculo solo si, en el contexto de ESTA
     * postulación (postulacion_evidencia), fue aprobada por el evaluador Y
     * sigue vigente (fecha_emision + periodo_validez_anios >= fecha de la
     * convocatoria). El estado global de la evidencia (Evidencia::estado) no
     * es suficiente: una evidencia aprobada en otra postulación, o vencida
     * para esta convocatoria, no debe sumar puntaje aquí.
     */
    private function evidenciasAprobadasDeVariable(Evaluacion $evaluacion, int $variableId): Collection
    {
        return $evaluacion->postulacion
            ->postulacionEvidencias
            ->filter(fn (PostulacionEvidencia $pe) => $pe->estado_en_postulacion === PostulacionEvidencia::ESTADO_APROBADA)
            ->filter(fn (PostulacionEvidencia $pe) => $pe->vigente === true)
            ->map(fn (PostulacionEvidencia $pe) => $pe->evidencia)
            ->filter(fn ($evidencia) => $evidencia && $evidencia->variable_id === $variableId)
            ->values();
    }
}
