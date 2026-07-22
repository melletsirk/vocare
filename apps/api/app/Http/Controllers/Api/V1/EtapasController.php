<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Etapa;
use App\Models\TablaEvaluacion;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EtapasController extends Controller
{
    /**
     * POST /api/v1/tablas-evaluacion/{tablaEvaluacion}/etapas
     * Plantilla de etapa para este anexo — se congela en tabla_snapshot al
     * crear cada Convocatoria que use este anexo.
     */
    public function store(Request $request, TablaEvaluacion $tablaEvaluacion): JsonResponse
    {
        $this->authorize('tablas_evaluacion.gestionar');
        $this->rechazarSiBloqueada($tablaEvaluacion);

        $data = $request->validate([
            'nombre' => ['required', 'string'],
            'tipo'   => ['required', Rule::in([
                Etapa::TIPO_VALIDACION_REQUISITOS,
                Etapa::TIPO_EVALUACION_CV,
                Etapa::TIPO_CLASE_MAGISTRAL,
                Etapa::TIPO_CONCURSO_OPOSICION,
                Etapa::TIPO_SESION_PRACTICAS,
                Etapa::TIPO_ELABORACION_SILABO,
            ])],
            'orden'  => ['required', 'integer', 'min:1'],
        ]);

        $etapa = $tablaEvaluacion->etapas()->create($data);

        AuditService::log('etapa.creada', $etapa, [], $etapa->toArray());

        return response()->json($etapa, 201);
    }

    /**
     * PATCH /api/v1/etapas/{etapa}
     */
    public function update(Request $request, Etapa $etapa): JsonResponse
    {
        $this->authorize('tablas_evaluacion.gestionar');
        $this->rechazarSiBloqueada($etapa->tablaEvaluacion);

        $data = $request->validate([
            'nombre' => ['sometimes', 'string'],
            'tipo'   => ['sometimes', Rule::in([
                Etapa::TIPO_VALIDACION_REQUISITOS,
                Etapa::TIPO_EVALUACION_CV,
                Etapa::TIPO_CLASE_MAGISTRAL,
                Etapa::TIPO_CONCURSO_OPOSICION,
                Etapa::TIPO_SESION_PRACTICAS,
                Etapa::TIPO_ELABORACION_SILABO,
            ])],
            'orden'  => ['sometimes', 'integer', 'min:1'],
        ]);

        $old = $etapa->toArray();
        $etapa->update($data);

        AuditService::log('etapa.actualizada', $etapa, $old, $etapa->fresh()->toArray());

        return response()->json($etapa->fresh());
    }

    /**
     * DELETE /api/v1/etapas/{etapa}
     */
    public function destroy(Etapa $etapa): JsonResponse
    {
        $this->authorize('tablas_evaluacion.gestionar');
        $this->rechazarSiBloqueada($etapa->tablaEvaluacion);

        $old = $etapa->toArray();
        $etapa->delete();

        AuditService::log('etapa.eliminada', $etapa, $old, []);

        return response()->json(null, 204);
    }

    private function rechazarSiBloqueada(TablaEvaluacion $tabla): void
    {
        if ($tabla->estaBloqueada()) {
            abort(422, 'Esta versión ya está activa/archivada y no se puede editar. Crea una nueva versión.');
        }
    }
}
