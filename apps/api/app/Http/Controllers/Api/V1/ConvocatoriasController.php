<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Convocatoria;
use App\Models\TablaEvaluacion;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ConvocatoriasController extends Controller
{
    /**
     * GET /api/v1/convocatorias
     * Lista paginada. Postulantes solo ven publicadas/en_proceso.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Convocatoria::with(['reglamentoVersion', 'tablaEvaluacion', 'plazas'])
            ->withCount('plazas');

        // Postulante solo ve convocatorias activas
        if ($request->user()->hasRole('postulante')) {
            $query->whereIn('estado', [
                Convocatoria::ESTADO_PUBLICADA,
                Convocatoria::ESTADO_EN_PROCESO,
            ]);
        }

        // Filtros opcionales
        if ($request->filled('estado')) {
            $query->where('estado', $request->estado);
        }
        if ($request->filled('tipo_proceso')) {
            $query->where('tipo_proceso', $request->tipo_proceso);
        }

        return response()->json(
            $query->orderByDesc('created_at')->paginate(15)
        );
    }

    /**
     * POST /api/v1/convocatorias
     *
     * Genera el snapshot inmutable de la tabla de evaluación
     * al momento de crear la convocatoria (no al publicarla).
     * Esto garantiza trazabilidad completa desde el origen, independientemente
     * de si la tabla de evaluación se modifica antes de publicar.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('convocatorias.crear');

        $data = $request->validate([
            'codigo'              => ['required', 'string', 'max:30', 'unique:convocatorias,codigo'],
            'nombre'              => ['required', 'string', 'max:255'],
            'tabla_evaluacion_id' => ['required', 'exists:tablas_evaluacion,id'],
            'tipo_proceso'        => ['required', 'string'],
            'modalidad'           => ['nullable', 'string'],
            'fecha_inicio'        => ['required', 'date'],
            'fecha_fin'           => ['required', 'date', 'after:fecha_inicio'],
            'descripcion'         => ['nullable', 'string'],
        ]);

        $tabla = TablaEvaluacion::with('rubros.variables.indicadores')->findOrFail($data['tabla_evaluacion_id']);

        // La tabla de evaluación ya está construida para un tipo_proceso y
        // modalidad específicos (ver requisitos-sistema.md §8) — evita que
        // se cree una convocatoria con datos inconsistentes con la tabla
        // seleccionada (ej. tipo_proceso="ascenso" usando el Anexo 1 de
        // contratación).
        if ($tabla->tipo_proceso !== $data['tipo_proceso']) {
            return response()->json([
                'message' => 'El tipo de proceso no coincide con la tabla de evaluación seleccionada.',
                'code'    => 'TIPO_PROCESO_INCONSISTENTE',
            ], 422);
        }

        if ($tabla->modalidad !== null && ($data['modalidad'] ?? null) !== $tabla->modalidad) {
            return response()->json([
                'message' => 'La modalidad no coincide con la tabla de evaluación seleccionada.',
                'code'    => 'MODALIDAD_INCONSISTENTE',
            ], 422);
        }

        $convocatoria = Convocatoria::create([
            ...$data,
            'reglamento_version_id' => $tabla->reglamento_version_id,
            'estado'                => Convocatoria::ESTADO_BORRADOR,
            'creado_por'            => $request->user()->id,
        ]);

        // Snapshot inmutable de la tabla de evaluación (Fase 2-B).
        // Se toma al crear para que apelaciones futuras siempre apunten a la
        // tabla vigente en el momento de abrir el proceso.
        $convocatoria->load('tablaEvaluacion.rubros.variables.indicadores');
        $convocatoria->generarSnapshot();

        AuditService::log('convocatoria.creada', $convocatoria, [], $convocatoria->toArray());

        return response()->json($convocatoria->load('tablaEvaluacion'), 201);
    }

    /**
     * GET /api/v1/convocatorias/{id}
     */
    public function show(Convocatoria $convocatoria): JsonResponse
    {
        return response()->json(
            $convocatoria->load(['reglamentoVersion', 'tablaEvaluacion.rubros.variables', 'plazas'])
        );
    }

    /**
     * PATCH /api/v1/convocatorias/{id}
     */
    public function update(Request $request, Convocatoria $convocatoria): JsonResponse
    {
        $this->authorize('convocatorias.editar');

        // No se puede editar una convocatoria cerrada o desierta
        if (in_array($convocatoria->estado, [Convocatoria::ESTADO_CERRADA, Convocatoria::ESTADO_DESIERTA])) {
            return response()->json([
                'message' => 'No se puede editar una convocatoria cerrada o desierta.',
                'code'    => 'CONVOCATORIA_CERRADA',
            ], 422);
        }

        $data = $request->validate([
            'nombre'      => ['sometimes', 'string', 'max:255'],
            'modalidad'   => ['sometimes', 'nullable', 'string'],
            'fecha_inicio' => ['sometimes', 'date'],
            'fecha_fin'   => ['sometimes', 'date', 'after:fecha_inicio'],
            'descripcion' => ['sometimes', 'nullable', 'string'],
            'estado'      => ['sometimes', Rule::in([
                Convocatoria::ESTADO_BORRADOR,
                Convocatoria::ESTADO_PUBLICADA,
                Convocatoria::ESTADO_EN_PROCESO,
                Convocatoria::ESTADO_CERRADA,
                Convocatoria::ESTADO_DESIERTA,
            ])],
        ]);

        $old = $convocatoria->toArray();

        // El snapshot de la tabla de evaluación ya se generó en store().
        // update() solo actualiza datos del proceso; el snapshot no se regenera.
        $convocatoria->update($data);

        AuditService::log('convocatoria.actualizada', $convocatoria, $old, $convocatoria->fresh()->toArray());

        return response()->json($convocatoria->fresh());
    }

    /**
     * POST /api/v1/convocatorias/{id}/cerrar
     */
    public function cerrar(Request $request, Convocatoria $convocatoria): JsonResponse
    {
        $this->authorize('convocatorias.cerrar');

        $data = $request->validate([
            'estado' => ['required', Rule::in([Convocatoria::ESTADO_CERRADA, Convocatoria::ESTADO_DESIERTA])],
        ]);

        $old = $convocatoria->toArray();
        $convocatoria->update(['estado' => $data['estado']]);

        AuditService::log('convocatoria.cerrada', $convocatoria, $old, $convocatoria->fresh()->toArray());

        return response()->json($convocatoria->fresh());
    }
}
