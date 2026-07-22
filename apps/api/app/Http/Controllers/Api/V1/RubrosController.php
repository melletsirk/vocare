<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Rubro;
use App\Models\TablaEvaluacion;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RubrosController extends Controller
{
    /**
     * POST /api/v1/tablas-evaluacion/{tablaEvaluacion}/rubros
     */
    public function store(Request $request, TablaEvaluacion $tablaEvaluacion): JsonResponse
    {
        $this->authorize('tablas_evaluacion.gestionar');
        $this->rechazarSiBloqueada($tablaEvaluacion);

        $data = $request->validate([
            'nombre'               => ['required', 'string'],
            'orden'                => ['required', 'integer', 'min:1'],
            'puntaje_max_subrubro' => ['required', 'numeric', 'min:0'],
        ]);

        $rubro = $tablaEvaluacion->rubros()->create($data);

        AuditService::log('rubro.creado', $rubro, [], $rubro->toArray());

        return response()->json($rubro, 201);
    }

    /**
     * PATCH /api/v1/rubros/{rubro}
     */
    public function update(Request $request, Rubro $rubro): JsonResponse
    {
        $this->authorize('tablas_evaluacion.gestionar');
        $this->rechazarSiBloqueada($rubro->tablaEvaluacion);

        $data = $request->validate([
            'nombre'               => ['sometimes', 'string'],
            'orden'                => ['sometimes', 'integer', 'min:1'],
            'puntaje_max_subrubro' => ['sometimes', 'numeric', 'min:0'],
        ]);

        $old = $rubro->toArray();
        $rubro->update($data);

        AuditService::log('rubro.actualizado', $rubro, $old, $rubro->fresh()->toArray());

        return response()->json($rubro->fresh());
    }

    /**
     * DELETE /api/v1/rubros/{rubro}
     */
    public function destroy(Rubro $rubro): JsonResponse
    {
        $this->authorize('tablas_evaluacion.gestionar');
        $this->rechazarSiBloqueada($rubro->tablaEvaluacion);

        $old = $rubro->toArray();
        $rubro->delete();

        AuditService::log('rubro.eliminado', $rubro, $old, []);

        return response()->json(null, 204);
    }

    private function rechazarSiBloqueada(TablaEvaluacion $tabla): void
    {
        if ($tabla->estaBloqueada()) {
            abort(422, 'Esta versión ya está activa/archivada y no se puede editar. Crea una nueva versión.');
        }
    }
}
