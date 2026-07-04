<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Convocatoria;
use App\Models\Plaza;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlazasController extends Controller
{
    /**
     * GET /api/v1/convocatorias/{convocatoria}/plazas
     */
    public function index(Convocatoria $convocatoria): JsonResponse
    {
        return response()->json(
            $convocatoria->plazas()->orderBy('facultad')->get()
        );
    }

    /**
     * POST /api/v1/convocatorias/{convocatoria}/plazas
     */
    public function store(Request $request, Convocatoria $convocatoria): JsonResponse
    {
        $this->authorize('plazas.crear');

        $data = $request->validate([
            'facultad'               => ['required', 'string', 'max:150'],
            'departamento'           => ['required', 'string', 'max:150'],
            'asignatura'             => ['required', 'string', 'max:200'],
            'area_conocimiento'      => ['nullable', 'string', 'max:150'],
            'modalidad'              => ['nullable', 'string', 'max:50'],
            'categoria_requerida'    => ['nullable', 'string', 'max:50'],
            'horas_semana'           => ['nullable', 'string', 'max:20'],
            'requisitos_adicionales' => ['nullable', 'string'],
        ]);

        $plaza = $convocatoria->plazas()->create($data);

        return response()->json($plaza, 201);
    }

    /**
     * GET /api/v1/plazas/{plaza}
     */
    public function show(Plaza $plaza): JsonResponse
    {
        return response()->json($plaza->load('convocatoria'));
    }

    /**
     * PATCH /api/v1/plazas/{plaza}
     */
    public function update(Request $request, Plaza $plaza): JsonResponse
    {
        $this->authorize('plazas.editar');

        $data = $request->validate([
            'facultad'               => ['sometimes', 'string', 'max:150'],
            'departamento'           => ['sometimes', 'string', 'max:150'],
            'asignatura'             => ['sometimes', 'string', 'max:200'],
            'area_conocimiento'      => ['sometimes', 'nullable', 'string', 'max:150'],
            'modalidad'              => ['sometimes', 'nullable', 'string', 'max:50'],
            'categoria_requerida'    => ['sometimes', 'nullable', 'string', 'max:50'],
            'horas_semana'           => ['sometimes', 'nullable', 'string', 'max:20'],
            'requisitos_adicionales' => ['sometimes', 'nullable', 'string'],
            'estado'                 => ['sometimes', 'in:activa,cubierta,desierta'],
        ]);

        $plaza->update($data);

        return response()->json($plaza->fresh());
    }

    /**
     * GET /api/v1/convocatorias/{convocatoria}/tabla-evaluacion
     * Retorna la tabla de evaluación (snapshot si existe, live si está en borrador)
     */
    public function tablaEvaluacion(Convocatoria $convocatoria): JsonResponse
    {
        // Si ya fue publicada, devuelve el snapshot inmutable
        if ($convocatoria->tabla_snapshot) {
            return response()->json([
                'source'  => 'snapshot',
                'tabla'   => $convocatoria->tabla_snapshot,
            ]);
        }

        // Si es borrador, devuelve la tabla live
        return response()->json([
            'source' => 'live',
            'tabla'  => $convocatoria->tablaEvaluacion->load('rubros.variables'),
        ]);
    }
}
