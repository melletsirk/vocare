<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AsignacionEvaluador;
use App\Models\Evaluacion;
use App\Models\Puntaje;
use App\Models\Postulacion;
use App\Services\AuditService;
use App\Services\CalculadorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EvaluacionesController extends Controller
{
    public function __construct(private readonly CalculadorService $calculador) {}

    /**
     * GET /api/v1/evaluaciones
     * Evaluador: sus evaluaciones asignadas.
     * Admin: todas (opcionalmente filtradas por convocatoria_id).
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('evaluaciones.ver');

        $user  = $request->user();
        $query = Evaluacion::with([
            'postulacion.plaza',
            'postulacion.convocatoria',
            'postulacion.postulante',
            'evaluador',
        ]);

        if ($user->hasRole('evaluador')) {
            $query->where('evaluador_id', $user->id);
        }

        if ($request->filled('convocatoria_id')) {
            $query->whereHas('postulacion', fn ($q) =>
                $q->where('convocatoria_id', $request->convocatoria_id)
            );
        }

        if ($request->filled('estado')) {
            $query->where('estado', $request->estado);
        }

        return response()->json(
            $query->orderByDesc('created_at')->paginate(20)
        );
    }

    /**
     * POST /api/v1/postulaciones/{postulacion}/evaluacion
     * Crea la evaluación para el evaluador autenticado.
     *
     * Requiere que un admin/comisión haya asignado previamente a este
     * evaluador a la postulación (AsignacionEvaluador) — un evaluador ya no
     * puede auto-asignarse un expediente arbitrario.
     */
    public function crear(Request $request, Postulacion $postulacion): JsonResponse
    {
        $this->authorize('evaluaciones.calificar');

        if ($postulacion->evaluacion) {
            return response()->json([
                'message' => 'Esta postulación ya tiene una evaluación asignada.',
                'code'    => 'EVALUACION_EXISTENTE',
            ], 422);
        }

        $user = $request->user();

        if ($user->hasRole('evaluador')) {
            $asignado = AsignacionEvaluador::where('postulacion_id', $postulacion->id)
                ->where('evaluador_id', $user->id)
                ->exists();

            if (!$asignado) {
                return response()->json([
                    'message' => 'No tienes una asignación para evaluar esta postulación.',
                    'code'    => 'EVALUADOR_NO_ASIGNADO',
                ], 403);
            }
        }

        $evaluacion = Evaluacion::create([
            'postulacion_id' => $postulacion->id,
            'evaluador_id'   => $user->id,
            'estado'         => Evaluacion::ESTADO_EN_PROCESO,
        ]);

        AuditService::log('evaluacion.creada', $evaluacion);

        return response()->json($evaluacion->load('postulacion', 'evaluador'), 201);
    }

    /**
     * GET /api/v1/evaluaciones/{evaluacion}
     * Detalle con todos los puntajes calculados por variable.
     */
    public function show(Evaluacion $evaluacion): JsonResponse
    {
        $this->authorize('evaluaciones.ver');

        return response()->json(
            $evaluacion->load([
                'postulacion.plaza',
                'postulacion.convocatoria',
                'postulacion.postulante',
                'postulacion.postulacionEvidencias.evidencia.variable',
                'evaluador',
                'puntajes',
            ])
        );
    }

    /**
     * POST /api/v1/evaluaciones/{evaluacion}/puntajes
     * Guarda un puntaje manual (TABLA_EQUIVALENCIA u otro tipo que requiera entrada manual).
     */
    public function guardarPuntaje(Request $request, Evaluacion $evaluacion): JsonResponse
    {
        $this->authorize('evaluaciones.calificar');

        $data = $request->validate([
            'variable_id'    => ['required', 'exists:variables,id'],
            'valor_entrada'  => ['required', 'numeric', 'min:0', 'max:20'],
            'indicador_id'   => ['nullable', 'exists:indicadores,id'],
            'tabla_equivalencia' => ['nullable', 'array'], // Tabla del indicador
        ]);

        $puntaje = Puntaje::updateOrCreate(
            [
                'evaluacion_id' => $evaluacion->id,
                'variable_id'   => $data['variable_id'],
            ],
            [
                'nombre_variable' => \App\Models\Variable::find($data['variable_id'])->nombre ?? 'Variable',
                'tipo_calculo'   => 'TABLA_EQUIVALENCIA',
                'valor_entrada'  => $data['valor_entrada'],
                'indicador_id'   => $data['indicador_id'] ?? null,
                'puntaje_bruto'  => 0,     // Se calculará al ejecutar calcular()
                'puntaje_variable' => 0,
                'detalle'        => [
                    'tabla_equivalencia' => $data['tabla_equivalencia'] ?? [],
                ],
            ]
        );

        return response()->json($puntaje, 201);
    }

    /**
     * POST /api/v1/evaluaciones/{evaluacion}/calcular
     * Ejecuta el motor de cálculo y actualiza el puntaje total.
     */
    public function calcular(Request $request, Evaluacion $evaluacion): JsonResponse
    {
        $this->authorize('evaluaciones.calificar');

        if ($evaluacion->estado === Evaluacion::ESTADO_CERRADA) {
            return response()->json([
                'message' => 'No se puede recalcular una evaluación cerrada.',
                'code'    => 'EVALUACION_CERRADA',
            ], 422);
        }

        $puntajeTotal = $this->calculador->calcular($evaluacion);

        AuditService::log('evaluacion.calculada', $evaluacion, [], [
            'puntaje_total' => $puntajeTotal,
        ]);

        return response()->json([
            'puntaje_total' => $puntajeTotal,
            'evaluacion'    => $evaluacion->fresh()->load('puntajes'),
        ]);
    }

    /**
     * POST /api/v1/evaluaciones/{evaluacion}/cerrar
     * Cierra la evaluación — ya no se puede modificar.
     * Solo comisión o admin_convocatoria pueden cerrar.
     */
    public function cerrar(Request $request, Evaluacion $evaluacion): JsonResponse
    {
        $this->authorize('evaluaciones.cerrar');

        if ($evaluacion->estado === Evaluacion::ESTADO_CERRADA) {
            return response()->json([
                'message' => 'La evaluación ya está cerrada.',
                'code'    => 'YA_CERRADA',
            ], 422);
        }

        if ($evaluacion->puntaje_total === null) {
            return response()->json([
                'message' => 'Debe calcular el puntaje antes de cerrar.',
                'code'    => 'SIN_PUNTAJE',
            ], 422);
        }

        $evaluacion->update([
            'estado'     => Evaluacion::ESTADO_CERRADA,
            'cerrada_en' => now(),
        ]);

        AuditService::log('evaluacion.cerrada', $evaluacion, [], $evaluacion->fresh()->toArray());

        return response()->json($evaluacion->fresh());
    }

    /**
     * GET /api/v1/evaluaciones/{evaluacion}/desglose
     * Desglose completo por sub-rubro y variable.
     * Postulante solo ve el total — no accede a este endpoint.
     */
    public function desglose(Evaluacion $evaluacion): JsonResponse
    {
        $this->authorize('evaluaciones.ver_desglose');

        $snapshot = $evaluacion->postulacion->convocatoria->tabla_snapshot;
        $puntajesMap = $evaluacion->puntajes->keyBy('variable_id');

        $desglose = collect($snapshot['rubros'])->map(function ($rubro) use ($puntajesMap) {
            $puntajeRubroAcumulado = 0.0;
            $variables = collect($rubro['variables'])->map(function ($varData) use ($puntajesMap, &$puntajeRubroAcumulado) {
                $puntaje = $puntajesMap->get($varData['id']);
                $puntajeVar = $puntaje ? (float) $puntaje->puntaje_variable : 0.0;
                $puntajeRubroAcumulado += $puntajeVar;

                return [
                    'variable_id'   => $varData['id'],
                    'nombre'        => $varData['nombre'],
                    'tipo_calculo'  => $varData['tipo_calculo'],
                    'puntaje_max'   => $varData['puntaje_max'],
                    'puntaje_bruto' => $puntaje?->puntaje_bruto ?? 0,
                    'puntaje_aplicado' => $puntajeVar,
                ];
            });

            $puntajeRubroFinal = min($puntajeRubroAcumulado, (float) $rubro['puntaje_max_subrubro']);

            return [
                'nombre'             => $rubro['nombre'],
                'puntaje_max'        => $rubro['puntaje_max_subrubro'],
                'puntaje_acumulado'  => round($puntajeRubroAcumulado, 2),
                'puntaje_final'      => round($puntajeRubroFinal, 2),
                'tope_aplicado'      => $puntajeRubroAcumulado > $rubro['puntaje_max_subrubro'],
                'variables'          => $variables,
            ];
        });

        return response()->json([
            'puntaje_total' => $evaluacion->puntaje_total,
            'tabla_nombre'  => $snapshot['nombre'],
            'rubros'        => $desglose,
        ]);
    }
}
