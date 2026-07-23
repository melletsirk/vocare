<?php

namespace App\Services;

use App\Models\Evaluacion;
use App\Models\PostulacionEtapa;
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
        $postulacion = $evaluacion->postulacion->load(
            'convocatoria',
            'postulacionEvidencias.evidencia',
            'postulacionEtapas'
        );
        $snapshot    = $postulacion->convocatoria->tabla_snapshot;

        if (!$snapshot) {
            throw new \RuntimeException('La convocatoria no tiene snapshot de tabla de evaluación.');
        }

        // TABLA_EQUIVALENCIA no se deriva de evidencias: depende de un
        // valor_entrada que el evaluador guardó antes por separado
        // (POST .../puntajes, ver guardarPuntaje). Hay que preservarlo antes
        // de borrar — si no, el delete() de abajo lo destruye en la misma
        // llamada, antes de que el propio calcular() llegue a leerlo, y la
        // variable queda en 0 en cada recálculo sin importar lo guardado.
        $entradasTablaEquivalencia = $evaluacion->puntajes()
            ->where('tipo_calculo', Variable::TIPO_TABLA_EQUIVALENCIA)
            ->whereNotNull('valor_entrada')
            ->get()
            ->keyBy('variable_id');

        // Borrar puntajes anteriores si se recalcula
        $evaluacion->puntajes()->delete();

        $puntajeTotal = 0.0;

        foreach ($snapshot['rubros'] as $rubroData) {
            $puntajeRubroAcumulado = 0.0;

            foreach ($rubroData['variables'] as $varData) {
                $variable = (object) $varData;
                $fuente   = $variable->fuente ?? Variable::FUENTE_EVIDENCIA;

                // fuente='etapa': el puntaje viene de un evento en vivo
                // (Clase Magistral, Sesión de Prácticas) vía postulacion_etapa,
                // no de evidencia documental. TABLA_EQUIVALENCIA se excluye
                // aquí porque ya tiene su propio camino de entrada manual
                // (guardarPuntaje → tablaEquivalencia(), sin cambios).
                if ($fuente === Variable::FUENTE_ETAPA && $variable->tipo_calculo !== Variable::TIPO_TABLA_EQUIVALENCIA) {
                    $postulacionEtapa = $postulacion->postulacionEtapas
                        ->firstWhere('etapa_id', $variable->etapa_id);

                    [$puntajeBruto, $detalle, $indicadorId, $valorEntrada] = $this->etapaScore($postulacionEtapa);
                } elseif ($variable->tipo_calculo === Variable::TIPO_TABLA_EQUIVALENCIA) {
                    [$puntajeBruto, $detalle, $indicadorId, $valorEntrada] = $this->tablaEquivalencia(
                        $variable,
                        $entradasTablaEquivalencia->get($variable->id)
                    );
                } else {
                    // Obtener evidencias aprobadas de esta variable
                    $evidencias = $this->evidenciasAprobadasDeVariable(
                        $evaluacion,
                        $variable->id
                    );

                    [$puntajeBruto, $detalle, $indicadorId, $valorEntrada] = $this->calcularVariable(
                        $variable,
                        $evidencias
                    );
                }

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
        Collection $evidencias
    ): array {
        return match ($variable->tipo_calculo) {
            Variable::TIPO_SUMA_CON_TOPE,
            Variable::TIPO_DATO_INSTITUCIONAL => $this->sumaConTope($variable, $evidencias),
            Variable::TIPO_MAYOR_VALOR         => $this->mayorValor($variable, $evidencias),
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
     *
     * $puntajeExistente se recibe ya resuelto por calcular() (ver
     * $entradasTablaEquivalencia) — no se puede volver a consultar aquí
     * porque calcular() ya borró los puntajes de la evaluación antes de
     * llegar a este punto.
     *
     * La tabla de rangos se lee del snapshot (Indicador.tabla_equivalencia
     * vía $variable->indicadores), no de $puntajeExistente->detalle: ese
     * campo lo reescribe el propio calcular() en cada corrida con forma de
     * "detalle de contribución" (para el desglose), no con la tabla de
     * rangos original — usarlo como fuente de lectura hacía que la tabla se
     * perdiera desde el segundo recálculo en adelante.
     */
    private function tablaEquivalencia(object $variable, ?Puntaje $puntajeExistente): array
    {
        // El evaluador debe haber guardado valor_entrada previamente
        if (!$puntajeExistente || $puntajeExistente->valor_entrada === null) {
            return [0.0, [], null, null];
        }

        $valorEntrada = (float) $puntajeExistente->valor_entrada;
        $indicadorId  = $puntajeExistente->indicador_id;

        $indicadores = collect($variable->indicadores ?? []);
        $indicador   = $indicadorId
            ? $indicadores->firstWhere('id', $indicadorId)
            : $indicadores->first();
        $tabla = (array) ($indicador['tabla_equivalencia'] ?? []);

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

    /**
     * fuente='etapa' — puntaje de un evento en vivo (Clase Magistral, etc.)
     * registrado en postulacion_etapa después del hecho. Solo cuenta si el
     * registro está 'aprobada' — igual disciplina que evidenciasAprobadasDeVariable():
     * no basta con que haya un número, tiene que estar aprobado en el
     * contexto de esta postulación. Sin registro, pendiente, observado,
     * rechazado o no_presentado → aporta 0, igual que evidencia faltante —
     * la postulación puede calcularse completa antes de que el evento ocurra.
     */
    private function etapaScore(?PostulacionEtapa $postulacionEtapa): array
    {
        if (!$postulacionEtapa
            || $postulacionEtapa->estado !== PostulacionEtapa::ESTADO_APROBADA
            || $postulacionEtapa->puntaje_bruto_evento === null) {
            return [0.0, [], null, null];
        }

        $puntaje = (float) $postulacionEtapa->puntaje_bruto_evento;

        $detalle = [[
            'postulacion_etapa_id' => $postulacionEtapa->id,
            'etapa_id'             => $postulacionEtapa->etapa_id,
            'fecha_realizada'      => $postulacionEtapa->fecha_realizada?->toDateString(),
            'jurado'               => $postulacionEtapa->jurado_texto,
            'aporte'               => $puntaje,
        ]];

        return [$puntaje, $detalle, null, null];
    }

    /**
     * Desglose completo por sub-rubro y variable de una evaluación ya
     * calculada — para uso interno/administrativo (evaluador, reportes de
     * comisión). El postulante nunca debe recibir el resultado de este
     * método (ver requisitos-sistema.md §10: solo ve el puntaje total).
     */
    public function desglosar(Evaluacion $evaluacion): array
    {
        $snapshot    = $evaluacion->postulacion->convocatoria->tabla_snapshot;
        $puntajesMap = $evaluacion->puntajes->keyBy('variable_id');

        $desglose = collect($snapshot['rubros'])->map(function ($rubro) use ($puntajesMap) {
            $puntajeRubroAcumulado = 0.0;
            $variables = collect($rubro['variables'])->map(function ($varData) use ($puntajesMap, &$puntajeRubroAcumulado) {
                $puntaje    = $puntajesMap->get($varData['id']);
                $puntajeVar = $puntaje ? (float) $puntaje->puntaje_variable : 0.0;
                $puntajeRubroAcumulado += $puntajeVar;

                return [
                    'variable_id'      => $varData['id'],
                    'nombre'           => $varData['nombre'],
                    'tipo_calculo'     => $varData['tipo_calculo'],
                    'puntaje_max'      => $varData['puntaje_max'],
                    'puntaje_bruto'    => $puntaje?->puntaje_bruto ?? 0,
                    'puntaje_aplicado' => $puntajeVar,
                    'detalle'          => $puntaje?->detalle ?? [],
                ];
            });

            $puntajeRubroFinal = min($puntajeRubroAcumulado, (float) $rubro['puntaje_max_subrubro']);

            return [
                'nombre'            => $rubro['nombre'],
                'puntaje_max'       => $rubro['puntaje_max_subrubro'],
                'puntaje_acumulado' => round($puntajeRubroAcumulado, 2),
                'puntaje_final'     => round($puntajeRubroFinal, 2),
                'tope_aplicado'     => $puntajeRubroAcumulado > $rubro['puntaje_max_subrubro'],
                'variables'         => $variables,
            ];
        });

        return [
            'puntaje_total' => $evaluacion->puntaje_total,
            'tabla_nombre'  => $snapshot['nombre'],
            'rubros'        => $desglose,
        ];
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
