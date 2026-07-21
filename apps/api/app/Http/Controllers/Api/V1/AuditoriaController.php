<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Convocatoria;
use App\Models\Resultado;
use App\Services\CalculadorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditoriaController extends Controller
{
    public function __construct(private readonly CalculadorService $calculador) {}

    /**
     * GET /api/v1/auditoria
     * Log de auditoría paginado con filtros.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('auditoria.ver');

        $query = AuditLog::with('user')
            ->orderByDesc('created_at');

        if ($request->filled('evento')) {
            $query->where('event', $request->evento);
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('desde')) {
            $query->whereDate('created_at', '>=', $request->desde);
        }

        if ($request->filled('hasta')) {
            $query->whereDate('created_at', '<=', $request->hasta);
        }

        return response()->json($query->paginate(50));
    }

    /**
     * GET /api/v1/reportes/convocatoria/{convocatoria}
     * Reporte consolidado de una convocatoria (para exportar o mostrar en UI).
     */
    public function reporteConvocatoria(Request $request, Convocatoria $convocatoria): JsonResponse
    {
        $this->authorize('reportes.ver');

        $resultados = Resultado::where('convocatoria_id', $convocatoria->id)
            ->with(['plaza', 'postulacion.postulante', 'evaluacion.puntajes', 'evaluacion.postulacion.convocatoria'])
            ->orderBy('plaza_id')
            ->orderBy('posicion')
            ->get();

        $totalPlazas     = $convocatoria->plazas()->count();
        $plazasCubiertas = $convocatoria->plazas()->where('estado', 'cubierta')->count();
        $plazasDesierta  = $convocatoria->plazas()->where('estado', 'desierta')->count();

        // Ganadores con desglose completo por sub-rubro/variable — para uso
        // interno/administrativo (evaluador, comisión), nunca expuesto al
        // postulante (ver requisitos-sistema.md §10).
        $ganadores = $resultados->where('estado', Resultado::ESTADO_GANADOR)->map(function ($r) {
            return [
                'plaza'          => $r->plaza->only(['facultad', 'departamento', 'asignatura']),
                'ganador'        => [
                    'nombre'  => $r->postulacion?->postulante?->name,
                    'email'   => $r->postulacion?->postulante?->email,
                    'dni'     => $r->postulacion?->postulante?->dni,
                    'puntaje' => $r->puntaje_total,
                ],
                'empatada'       => $r->empatada,
                'orden_manual'   => $r->orden_manual,
                'decidido_por'   => $r->decidido_por,
                'decidido_en'    => $r->decidido_en,
                'publicado_en'   => $r->publicado_en,
                'desglose'       => $r->evaluacion ? $this->calculador->desglosar($r->evaluacion) : null,
            ];
        });

        return response()->json([
            'convocatoria'    => $convocatoria->only(['id', 'codigo', 'nombre', 'tipo_proceso', 'estado']),
            'resumen'         => [
                'total_plazas'     => $totalPlazas,
                'plazas_cubiertas' => $plazasCubiertas,
                'plazas_desiertas' => $plazasDesierta,
                'total_postulantes' => $resultados->whereNotNull('postulacion_id')->count(),
            ],
            'ganadores'       => $ganadores->values(),
            'resultados'      => $resultados,
        ]);
    }
}
