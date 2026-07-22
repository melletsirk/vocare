<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AsignacionEvaluador;
use App\Models\Postulacion;
use App\Models\PostulacionEtapa;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PostulacionEtapasController extends Controller
{
    /**
     * GET /api/v1/postulaciones/{postulacion}/etapas
     */
    public function index(Request $request, Postulacion $postulacion): JsonResponse
    {
        $user = $request->user();
        if ($user->hasRole('postulante') && $postulacion->user_id !== $user->id) {
            abort(403);
        }

        return response()->json(
            $postulacion->postulacionEtapas()->with('etapa', 'registradoPor')->orderBy('etapa_id')->get()
        );
    }

    /**
     * PATCH /api/v1/postulacion-etapas/{postulacionEtapa}
     *
     * Registra el resultado de una etapa — para eventos en vivo (Clase
     * Magistral, etc.), esto se transcribe DESPUÉS de que el evento ocurre;
     * quien transcribe (registrado_por) no es necesariamente quien juzgó
     * (jurado_texto es un campo de texto libre aparte, para jurado externo
     * sin cuenta en el sistema).
     */
    public function update(Request $request, PostulacionEtapa $postulacionEtapa): JsonResponse
    {
        $this->authorize('evaluaciones.calificar');

        $user = $request->user();

        if ($user->hasRole('evaluador')) {
            $asignado = AsignacionEvaluador::where('postulacion_id', $postulacionEtapa->postulacion_id)
                ->where('evaluador_id', $user->id)
                ->where(function ($q) use ($postulacionEtapa) {
                    $q->whereNull('etapa_id')->orWhere('etapa_id', $postulacionEtapa->etapa_id);
                })
                ->exists();

            if (!$asignado) {
                return response()->json([
                    'message' => 'No tienes una asignación para registrar esta etapa.',
                    'code'    => 'EVALUADOR_NO_ASIGNADO',
                ], 403);
            }
        }

        $data = $request->validate([
            'fecha_programada'     => ['nullable', 'date'],
            'fecha_realizada'      => ['nullable', 'date'],
            'estado'               => ['required', Rule::in([
                PostulacionEtapa::ESTADO_PENDIENTE,
                PostulacionEtapa::ESTADO_APROBADA,
                PostulacionEtapa::ESTADO_OBSERVADA,
                PostulacionEtapa::ESTADO_RECHAZADA,
                PostulacionEtapa::ESTADO_NO_PRESENTADO,
            ])],
            'puntaje_bruto_evento' => ['nullable', 'numeric', 'min:0'],
            'jurado_texto'         => ['nullable', 'string'],
            'comentario'           => ['nullable', 'string'],
        ]);

        $old = $postulacionEtapa->toArray();

        $postulacionEtapa->update([
            ...$data,
            'registrado_por' => $user->id,
        ]);

        AuditService::log(
            'postulacion_etapa.registrada',
            $postulacionEtapa,
            $old,
            $postulacionEtapa->fresh()->toArray()
        );

        return response()->json($postulacionEtapa->fresh()->load('etapa', 'registradoPor'));
    }
}
