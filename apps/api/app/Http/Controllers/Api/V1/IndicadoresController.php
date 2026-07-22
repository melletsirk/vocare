<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Indicador;
use App\Models\TablaEvaluacion;
use App\Models\Variable;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class IndicadoresController extends Controller
{
    /**
     * POST /api/v1/variables/{variable}/indicadores
     */
    public function store(Request $request, Variable $variable): JsonResponse
    {
        $this->authorize('tablas_evaluacion.gestionar');
        $this->rechazarSiBloqueada($variable->rubro->tablaEvaluacion);

        $data = $request->validate([
            'nombre'              => ['required', 'string'],
            'puntaje'             => ['required', 'numeric', 'min:0'],
            'orden'               => ['required', 'integer', 'min:1'],
            'tabla_equivalencia'  => ['nullable', 'array'],
            'tabla_equivalencia.*.min'      => ['required_with:tabla_equivalencia', 'numeric'],
            'tabla_equivalencia.*.max'      => ['required_with:tabla_equivalencia', 'numeric'],
            'tabla_equivalencia.*.puntaje'  => ['required_with:tabla_equivalencia', 'numeric'],
        ]);

        $indicador = $variable->indicadores()->create($data);

        AuditService::log('indicador.creado', $indicador, [], $indicador->toArray());

        return response()->json($indicador, 201);
    }

    /**
     * PATCH /api/v1/indicadores/{indicador}
     */
    public function update(Request $request, Indicador $indicador): JsonResponse
    {
        $this->authorize('tablas_evaluacion.gestionar');
        $this->rechazarSiBloqueada($indicador->variable->rubro->tablaEvaluacion);

        $data = $request->validate([
            'nombre'              => ['sometimes', 'string'],
            'puntaje'             => ['sometimes', 'numeric', 'min:0'],
            'orden'               => ['sometimes', 'integer', 'min:1'],
            'tabla_equivalencia'  => ['sometimes', 'nullable', 'array'],
        ]);

        $old = $indicador->toArray();
        $indicador->update($data);

        AuditService::log('indicador.actualizado', $indicador, $old, $indicador->fresh()->toArray());

        return response()->json($indicador->fresh());
    }

    /**
     * DELETE /api/v1/indicadores/{indicador}
     */
    public function destroy(Indicador $indicador): JsonResponse
    {
        $this->authorize('tablas_evaluacion.gestionar');
        $this->rechazarSiBloqueada($indicador->variable->rubro->tablaEvaluacion);

        $old = $indicador->toArray();
        $indicador->delete();

        AuditService::log('indicador.eliminado', $indicador, $old, []);

        return response()->json(null, 204);
    }

    private function rechazarSiBloqueada(TablaEvaluacion $tabla): void
    {
        if ($tabla->estaBloqueada()) {
            abort(422, 'Esta versión ya está activa/archivada y no se puede editar. Crea una nueva versión.');
        }
    }
}
