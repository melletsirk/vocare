<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Convocatoria;
use App\Models\Plaza;
use App\Models\Postulacion;
use App\Models\Resultado;
use App\Services\AuditService;
use App\Services\ResultadosService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ResultadosController extends Controller
{
    public function __construct(private readonly ResultadosService $service) {}

    /**
     * POST /api/v1/convocatorias/{convocatoria}/plazas/{plaza}/ranking
     * Genera el ranking de una plaza (comision / admin_convocatoria).
     */
    public function generarRanking(Request $request, Convocatoria $convocatoria, Plaza $plaza): JsonResponse
    {
        $this->authorize('resultados.ver_todos');

        $resultados = $this->service->generarRankingPlaza($convocatoria, $plaza);

        AuditService::log('resultados.ranking_generado', $convocatoria, [], [
            'plaza_id' => $plaza->id,
            'total'    => $resultados->count(),
        ]);

        return response()->json($resultados->load('postulacion.postulante'));
    }

    /**
     * GET /api/v1/convocatorias/{convocatoria}/resultados
     * Lista completa de resultados de la convocatoria.
     * Postulante: no accede. Evaluador+: sí.
     */
    public function index(Request $request, Convocatoria $convocatoria): JsonResponse
    {
        $this->authorize('resultados.ver_todos');

        $resultados = Resultado::where('convocatoria_id', $convocatoria->id)
            ->with(['plaza', 'postulacion.postulante', 'evaluacion'])
            ->orderBy('plaza_id')
            ->orderBy('posicion')
            ->get();

        return response()->json($resultados);
    }

    /**
     * POST /api/v1/convocatorias/{convocatoria}/plazas/{plaza}/resultados/desempatar
     * La comisión registra el orden decidido manualmente para un grupo de
     * postulaciones empatadas. Ver ResultadosService::resolverEmpate().
     */
    public function resolverEmpate(Request $request, Convocatoria $convocatoria, Plaza $plaza): JsonResponse
    {
        $this->authorize('resultados.publicar');

        $data = $request->validate([
            'posicion_inicio' => ['required', 'integer', 'min:1'],
            'orden'           => ['required', 'array', 'min:2'],
            'orden.*'         => ['required', 'integer', 'exists:postulaciones,id'],
        ]);

        try {
            $resultados = $this->service->resolverEmpate(
                $convocatoria,
                $plaza,
                $data['posicion_inicio'],
                $data['orden'],
                $request->user()->id
            );
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code'    => 'EMPATE_INVALIDO',
            ], 422);
        }

        AuditService::log('resultados.empate_resuelto', $plaza, [], [
            'plaza_id'        => $plaza->id,
            'posicion_inicio' => $data['posicion_inicio'],
            'orden_decidido'  => $data['orden'],
            'decidido_por'    => $request->user()->id,
        ]);

        return response()->json($resultados->load('postulacion.postulante'));
    }

    /**
     * GET /api/v1/postulaciones/{postulacion}/resultado
     * El postulante consulta su propio resultado (solo puntaje total y posición).
     * No ve el desglose de otros postulantes.
     */
    public function miResultado(Request $request, Postulacion $postulacion): JsonResponse
    {
        $this->authorize('resultados.ver_total_propio');

        // Solo el propio postulante
        if ($postulacion->user_id !== $request->user()->id) {
            abort(403);
        }

        $resultado = Resultado::where('postulacion_id', $postulacion->id)->first();

        if (!$resultado || !$resultado->publicado_en) {
            return response()->json([
                'message' => 'Los resultados aún no han sido publicados.',
                'code'    => 'NO_PUBLICADOS',
            ], 404);
        }

        // Solo retorna lo que el postulante puede ver
        return response()->json([
            'postulacion_id' => $postulacion->id,
            'plaza'          => $postulacion->plaza->only(['facultad', 'departamento', 'asignatura']),
            'puntaje_total'  => $resultado->puntaje_total,
            'posicion'       => $resultado->posicion,
            'estado'         => $resultado->estado,
            'publicado_en'   => $resultado->publicado_en,
        ]);
    }

    /**
     * POST /api/v1/convocatorias/{convocatoria}/resultados/publicar
     * Publica oficialmente los resultados (comision / admin_convocatoria).
     */
    public function publicar(Request $request, Convocatoria $convocatoria): JsonResponse
    {
        $this->authorize('resultados.publicar');

        // Verificar que todas las plazas tengan ranking generado
        $plazasSinResultado = $convocatoria->plazas()
            ->whereNotIn('id', Resultado::where('convocatoria_id', $convocatoria->id)->pluck('plaza_id'))
            ->exists();

        if ($plazasSinResultado) {
            return response()->json([
                'message' => 'Existen plazas sin ranking generado. Genera el ranking de todas las plazas primero.',
                'code'    => 'PLAZAS_SIN_RANKING',
            ], 422);
        }

        $empatesPendientes = Resultado::where('convocatoria_id', $convocatoria->id)
            ->where('estado', Resultado::ESTADO_EMPATE_PENDIENTE)
            ->exists();

        if ($empatesPendientes) {
            return response()->json([
                'message' => 'Existen empates sin resolver. La comisión debe decidir el orden antes de publicar.',
                'code'    => 'EMPATES_PENDIENTES',
            ], 422);
        }

        $this->service->publicarResultados($convocatoria, $request->user()->id);

        AuditService::log('resultados.publicados', $convocatoria, [], [
            'convocatoria_id' => $convocatoria->id,
            'publicado_por'   => $request->user()->id,
        ]);

        return response()->json([
            'message'      => 'Resultados publicados correctamente.',
            'convocatoria' => $convocatoria->fresh(),
        ]);
    }

    /**
     * POST /api/v1/convocatorias/{convocatoria}/plazas/{plaza}/desierta
     * Declara una plaza desierta manualmente (comision).
     */
    public function declararDesierta(Request $request, Convocatoria $convocatoria, Plaza $plaza): JsonResponse
    {
        $this->authorize('resultados.publicar');

        Resultado::where('plaza_id', $plaza->id)
            ->where('convocatoria_id', $convocatoria->id)
            ->delete();

        Resultado::create([
            'convocatoria_id' => $convocatoria->id,
            'plaza_id'        => $plaza->id,
            'postulacion_id'  => null,
            'evaluacion_id'   => null,
            'puntaje_total'   => 0,
            'posicion'        => 1,
            'estado'          => Resultado::ESTADO_DESIERTA,
        ]);

        $plaza->update(['estado' => 'desierta']);

        AuditService::log('plaza.declarada_desierta', $plaza, [], [
            'plaza_id'    => $plaza->id,
            'declarado_por' => $request->user()->id,
        ]);

        return response()->json(['message' => 'Plaza declarada desierta.', 'plaza' => $plaza->fresh()]);
    }
}
