<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TablaEvaluacion;
use Illuminate\Http\JsonResponse;

class TablasEvaluacionController extends Controller
{
    /**
     * GET /api/v1/tablas-evaluacion
     * Lista todas las tablas disponibles con sus rubros y variables.
     */
    public function index(): JsonResponse
    {
        $tablas = TablaEvaluacion::with('reglamentoVersion')
            ->orderBy('tipo_proceso')
            ->orderBy('codigo_anexo')
            ->get();

        return response()->json($tablas);
    }

    /**
     * GET /api/v1/tablas-evaluacion/{id}
     * Detalle completo: rubros → variables (sin indicadores individuales por ahora).
     */
    public function show(TablaEvaluacion $tablaEvaluacion): JsonResponse
    {
        return response()->json(
            $tablaEvaluacion->load(['reglamentoVersion', 'rubros.variables'])
        );
    }
}
