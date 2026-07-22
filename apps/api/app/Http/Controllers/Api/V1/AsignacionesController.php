<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AsignacionEvaluador;
use App\Models\Convocatoria;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AsignacionesController extends Controller
{
    /**
     * GET /api/v1/asignaciones
     * Admin: todas las asignaciones (filtrables por convocatoria_id/postulacion_id).
     * Evaluador: solo las suyas.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('asignaciones.ver');

        $user  = $request->user();
        $query = AsignacionEvaluador::with(['postulacion.plaza', 'postulacion.postulante', 'postulacion.evaluacion', 'convocatoria', 'evaluador']);

        if ($user->hasRole('evaluador')) {
            $query->where('evaluador_id', $user->id);
        }

        if ($request->filled('convocatoria_id')) {
            $query->where('convocatoria_id', $request->convocatoria_id);
        }

        if ($request->filled('postulacion_id')) {
            $query->where('postulacion_id', $request->postulacion_id);
        }

        return response()->json(
            $query->orderByDesc('created_at')->paginate(20)
        );
    }

    /**
     * POST /api/v1/convocatorias/{convocatoria}/asignaciones
     * Asigna un evaluador (o miembro de comisión) a una postulación concreta
     * de esta convocatoria. Requisito de negocio: los expedientes se asignan
     * previamente, no se auto-asignan.
     */
    public function store(Request $request, Convocatoria $convocatoria): JsonResponse
    {
        $this->authorize('asignaciones.gestionar');

        $data = $request->validate([
            'postulacion_id' => ['required', 'exists:postulaciones,id'],
            'evaluador_id'   => ['required', 'exists:users,id'],
            // etapa_id null = asignado a toda la postulación. Un valor
            // específico = jurado de esa etapa únicamente (ej. Clase
            // Magistral con jurado distinto a quien revisó documentos).
            'etapa_id'       => ['nullable', 'exists:etapas,id'],
            'tipo'           => ['nullable', Rule::in([
                AsignacionEvaluador::TIPO_EVALUADOR,
                AsignacionEvaluador::TIPO_COMISION,
            ])],
        ]);

        $postulacion = $convocatoria->postulaciones()->find($data['postulacion_id']);

        if (!$postulacion) {
            return response()->json([
                'message' => 'La postulación no pertenece a esta convocatoria.',
                'code'    => 'POSTULACION_AJENA',
            ], 422);
        }

        $evaluadorUser = User::find($data['evaluador_id']);

        if (!$evaluadorUser->hasRole(['evaluador', 'admin'])) {
            return response()->json([
                'message' => 'El usuario indicado no tiene rol de evaluador.',
                'code'    => 'ROL_INVALIDO',
            ], 422);
        }

        $yaAsignado = AsignacionEvaluador::where('postulacion_id', $data['postulacion_id'])
            ->where('evaluador_id', $data['evaluador_id'])
            ->where('etapa_id', $data['etapa_id'] ?? null)
            ->exists();

        if ($yaAsignado) {
            return response()->json([
                'message' => 'Este evaluador ya está asignado a esta postulación (o etapa).',
                'code'    => 'YA_ASIGNADO',
            ], 422);
        }

        $asignacion = AsignacionEvaluador::create([
            'convocatoria_id' => $convocatoria->id,
            'postulacion_id'  => $data['postulacion_id'],
            'evaluador_id'    => $data['evaluador_id'],
            'etapa_id'        => $data['etapa_id'] ?? null,
            'tipo'            => $data['tipo'] ?? AsignacionEvaluador::TIPO_EVALUADOR,
        ]);

        AuditService::log('asignacion.creada', $asignacion, [], $asignacion->toArray());

        return response()->json($asignacion->load('postulacion', 'evaluador'), 201);
    }

    /**
     * DELETE /api/v1/asignaciones/{asignacion}
     */
    public function destroy(Request $request, AsignacionEvaluador $asignacion): JsonResponse
    {
        $this->authorize('asignaciones.gestionar');

        $old = $asignacion->toArray();
        $asignacion->delete();

        AuditService::log('asignacion.eliminada', $asignacion, $old, []);

        return response()->json(null, 204);
    }
}
