<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Convocatoria;
use App\Models\Expediente;
use App\Models\Postulacion;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PostulacionesController extends Controller
{
    /**
     * GET /api/v1/postulaciones
     * Postulante: solo las propias. Evaluador/admin: todas.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Postulacion::with(['convocatoria', 'plaza', 'expediente', 'postulante']);

        if ($user->hasRole('postulante')) {
            $query->where('user_id', $user->id);
        } elseif ($request->filled('convocatoria_id')) {
            $query->where('convocatoria_id', $request->convocatoria_id);
        }

        if ($request->filled('estado')) {
            $query->where('estado', $request->estado);
        }

        return response()->json($query->orderByDesc('created_at')->paginate(15));
    }

    /**
     * POST /api/v1/postulaciones
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('postulaciones.crear');

        $data = $request->validate([
            'convocatoria_id'  => ['required', 'exists:convocatorias,id'],
            'plaza_id'         => ['required', 'exists:plazas,id'],
            'categoria_actual' => ['nullable', 'string', 'max:50'],
        ]);

        $convocatoria = Convocatoria::findOrFail($data['convocatoria_id']);

        // Solo se puede postular a convocatorias publicadas o en proceso
        if (!in_array($convocatoria->estado, [Convocatoria::ESTADO_PUBLICADA, Convocatoria::ESTADO_EN_PROCESO])) {
            return response()->json([
                'message' => 'La convocatoria no está disponible para postulaciones.',
                'code'    => 'CONVOCATORIA_NO_DISPONIBLE',
            ], 422);
        }

        // Verificar que no exista una postulación previa a la misma plaza
        $existente = Postulacion::where('user_id', $request->user()->id)
            ->where('plaza_id', $data['plaza_id'])
            ->whereNull('deleted_at')
            ->first();

        if ($existente) {
            return response()->json([
                'message' => 'Ya tienes una postulación activa para esta plaza.',
                'code'    => 'POSTULACION_DUPLICADA',
            ], 422);
        }

        $postulacion = Postulacion::create([
            ...$data,
            'user_id' => $request->user()->id,
            'estado'  => Postulacion::ESTADO_EN_PROCESO,
        ]);

        // Crear el expediente vacío asociado
        Expediente::create([
            'postulacion_id' => $postulacion->id,
            'estado'         => 'en_preparacion',
            'total_bytes'    => 0,
        ]);

        AuditService::log('postulacion.creada', $postulacion, [], $postulacion->toArray());

        return response()->json($postulacion->load(['convocatoria', 'plaza', 'expediente']), 201);
    }

    /**
     * GET /api/v1/postulaciones/{id}
     */
    public function show(Request $request, Postulacion $postulacion): JsonResponse
    {
        $user = $request->user();

        // Postulante solo puede ver la suya
        if ($user->hasRole('postulante') && $postulacion->user_id !== $user->id) {
            abort(403);
        }

        return response()->json(
            $postulacion->load(['convocatoria', 'plaza', 'cvSnapshot', 'expediente', 'postulacionEvidencias.evidencia.variable'])
        );
    }

    /**
     * POST /api/v1/postulaciones/{id}/enviar
     * El postulante envía formalmente su postulación (genera snapshot del CV).
     */
    public function enviar(Request $request, Postulacion $postulacion): JsonResponse
    {
        $this->authorize('postulaciones.enviar');

        if ($postulacion->user_id !== $request->user()->id) {
            abort(403);
        }

        if ($postulacion->estaEnviada()) {
            return response()->json([
                'message' => 'La postulación ya fue enviada.',
                'code'    => 'YA_ENVIADA',
            ], 422);
        }

        $data = $request->validate([
            'cv_datos'         => ['required', 'array'],   // JSON con los datos del CV
            'categoria_actual' => ['nullable', 'string', 'max:50'],
        ]);

        // Snapshot inmutable del CV
        $postulacion->cvSnapshot()->create([
            'datos'    => $data['cv_datos'],
            'tomado_en' => now(),
        ]);

        $postulacion->update([
            'fecha_envio'      => now(),
            'categoria_actual' => $data['categoria_actual'] ?? $postulacion->categoria_actual,
        ]);

        // Cambiar estado del expediente
        $postulacion->expediente->update(['estado' => 'enviado']);

        AuditService::log('postulacion.enviada', $postulacion);

        return response()->json($postulacion->fresh()->load(['cvSnapshot', 'expediente']));
    }

    /**
     * PATCH /api/v1/postulaciones/{id}/estado
     * Evaluador/admin puede observar o rechazar.
     */
    public function actualizarEstado(Request $request, Postulacion $postulacion): JsonResponse
    {
        $this->authorize('postulaciones.ver_todas');

        $data = $request->validate([
            'estado'          => ['required', Rule::in([
                Postulacion::ESTADO_OBSERVADA,
                Postulacion::ESTADO_RECHAZADA,
                Postulacion::ESTADO_APROBADA,
                Postulacion::ESTADO_GANADORA,
            ])],
            'motivo_rechazo'  => ['required_if:estado,rechazada', 'nullable', 'string'],
        ]);

        $old = $postulacion->toArray();
        $postulacion->update($data);
        AuditService::log('postulacion.estado_cambiado', $postulacion, $old, $postulacion->fresh()->toArray());

        return response()->json($postulacion->fresh());
    }
}
