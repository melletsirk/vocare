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
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('convocatorias.crear');

        $data = $request->validate([
            'codigo'                => ['required', 'string', 'max:30', 'unique:convocatorias,codigo'],
            'nombre'                => ['required', 'string', 'max:255'],
            'tabla_evaluacion_id'   => ['required', 'exists:tablas_evaluacion,id'],
            'tipo_proceso'          => ['required', 'string'],
            'modalidad'             => ['nullable', 'string'],
            'fecha_inicio'          => ['required', 'date'],
            'fecha_fin'             => ['required', 'date', 'after:fecha_inicio'],
            'descripcion'           => ['nullable', 'string'],
        ]);

        $tabla = TablaEvaluacion::findOrFail($data['tabla_evaluacion_id']);

        $convocatoria = Convocatoria::create([
            ...$data,
            'reglamento_version_id' => $tabla->reglamento_version_id,
            'estado'                => Convocatoria::ESTADO_BORRADOR,
            'creado_por'            => $request->user()->id,
        ]);

        AuditService::log('convocatoria.creada', $convocatoria, [], $convocatoria->toArray());

        return response()->json($convocatoria->load('tablaEvaluacion'), 201);
    }

    /**
     * GET /api/v1/convocatorias/{id}
     */
    public function show(Convocatoria $convocatoria): JsonResponse
    {
        return response()->json(
            $convocatoria->load(['reglamentoVersion', 'tablaEvaluacion.rubros.variables', 'plazas', 'etapas'])
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

        // Al publicar: generar snapshot inmutable de la tabla de evaluación
        if (isset($data['estado']) && $data['estado'] === Convocatoria::ESTADO_PUBLICADA
            && $convocatoria->estado === Convocatoria::ESTADO_BORRADOR) {
            $convocatoria->update($data);
            $convocatoria->generarSnapshot();
        } else {
            $convocatoria->update($data);
        }

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
