<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Rubro;
use App\Models\TablaEvaluacion;
use App\Models\Variable;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class VariablesController extends Controller
{
    /**
     * POST /api/v1/rubros/{rubro}/variables
     */
    public function store(Request $request, Rubro $rubro): JsonResponse
    {
        $this->authorize('tablas_evaluacion.gestionar');
        $this->rechazarSiBloqueada($rubro->tablaEvaluacion);

        $data = $this->validarDatos($request, $rubro->tabla_evaluacion_id);

        $variable = $rubro->variables()->create($data);

        AuditService::log('variable.creada', $variable, [], $variable->toArray());

        return response()->json($variable, 201);
    }

    /**
     * PATCH /api/v1/variables/{variable}
     */
    public function update(Request $request, Variable $variable): JsonResponse
    {
        $this->authorize('tablas_evaluacion.gestionar');
        $tabla = $variable->rubro->tablaEvaluacion;
        $this->rechazarSiBloqueada($tabla);

        $data = $this->validarDatos($request, $tabla->id, sometimes: true);

        $old = $variable->toArray();
        $variable->update($data);

        AuditService::log('variable.actualizada', $variable, $old, $variable->fresh()->toArray());

        return response()->json($variable->fresh());
    }

    /**
     * DELETE /api/v1/variables/{variable}
     */
    public function destroy(Variable $variable): JsonResponse
    {
        $this->authorize('tablas_evaluacion.gestionar');
        $this->rechazarSiBloqueada($variable->rubro->tablaEvaluacion);

        $old = $variable->toArray();
        $variable->delete();

        AuditService::log('variable.eliminada', $variable, $old, []);

        return response()->json(null, 204);
    }

    private function validarDatos(Request $request, int $tablaEvaluacionId, bool $sometimes = false): array
    {
        $regla = fn (array $reglas) => $sometimes ? array_merge(['sometimes'], $reglas) : $reglas;

        return $request->validate([
            'nombre'                => $regla(['required', 'string']),
            'orden'                 => $regla(['required', 'integer', 'min:1']),
            'puntaje_max'           => $regla(['required', 'numeric', 'min:0']),
            'tipo_calculo'          => $regla(['required', Rule::in([
                Variable::TIPO_SUMA_CON_TOPE,
                Variable::TIPO_MAYOR_VALOR,
                Variable::TIPO_TABLA_EQUIVALENCIA,
                Variable::TIPO_DATO_INSTITUCIONAL,
            ])]),
            'periodo_validez_anios' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'fuente_verificacion'   => ['sometimes', 'nullable', 'string'],
            'fuente'                => ['sometimes', Rule::in([Variable::FUENTE_EVIDENCIA, Variable::FUENTE_ETAPA])],
            'etapa_id'              => [
                'sometimes', 'nullable',
                Rule::exists('etapas', 'id')->where('tabla_evaluacion_id', $tablaEvaluacionId),
            ],
        ]);
    }

    private function rechazarSiBloqueada(TablaEvaluacion $tabla): void
    {
        if ($tabla->estaBloqueada()) {
            abort(422, 'Esta versión ya está activa/archivada y no se puede editar. Crea una nueva versión.');
        }
    }
}
