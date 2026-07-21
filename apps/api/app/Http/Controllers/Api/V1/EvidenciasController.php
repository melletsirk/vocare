<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Evidencia;
use App\Models\Expediente;
use App\Models\Postulacion;
use App\Models\PostulacionEvidencia;
use App\Models\Variable;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EvidenciasController extends Controller
{
    // ──────────────────────────────────────────────────────────────────────────
    // GET /api/v1/postulaciones/{postulacion}/evidencias
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Lista las evidencias asociadas a una postulación, con su estado y
     * vigencia calculados en el contexto de esa postulación.
     *
     * La respuesta incluye tanto los datos del archivo (evidencia maestra)
     * como el estado específico de esta postulación (postulacion_evidencia).
     */
    public function index(Request $request, Postulacion $postulacion): JsonResponse
    {
        $user = $request->user();

        if ($user->hasRole('postulante') && $postulacion->user_id !== $user->id) {
            abort(403);
        }

        $registros = $postulacion
            ->postulacionEvidencias()
            ->with(['evidencia.variable.rubro', 'evidencia.evaluador', 'evaluadorPostulacion'])
            ->get();

        return response()->json($registros);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // POST /api/v1/postulaciones/{postulacion}/evidencias
    // Sube un archivo NUEVO y lo asocia a la postulación.
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Carga un archivo PDF/JPG/PNG (máx 10 MB) como evidencia nueva.
     *
     * Flujo:
     *   1. Valida el archivo y los metadatos.
     *   2. Crea la evidencia maestra (user_id = postulante autenticado).
     *   3. Crea el pivote postulacion_evidencia con vigencia calculada.
     *   4. Incrementa total_bytes del expediente (solo archivos nuevos).
     */
    public function store(Request $request, Postulacion $postulacion): JsonResponse
    {
        $this->authorize('evidencias.subir');

        if ($postulacion->user_id !== $request->user()->id) {
            abort(403);
        }

        $request->validate([
            'archivo'           => ['required', 'file', 'max:10240', 'mimes:pdf,jpg,jpeg,png'],
            'variable_id'       => ['required', 'exists:variables,id'],
            'indicador_id'      => ['nullable', 'exists:indicadores,id'],
            'puntaje_indicador' => ['nullable', 'numeric', 'min:0'],
            'fecha_emision'     => ['nullable', 'date', 'before_or_equal:today'],
        ]);

        $archivo    = $request->file('archivo');
        $expediente = $postulacion->expediente;

        // Validar MIME real (no solo extensión)
        $mimeReal = $archivo->getMimeType();
        if (!in_array($mimeReal, Evidencia::MIMES_PERMITIDOS)) {
            return response()->json([
                'message' => 'El tipo de archivo no está permitido. Solo PDF, JPG o PNG.',
                'code'    => 'MIME_NO_PERMITIDO',
            ], 422);
        }

        // Validar cuota de la postulación (200 MB — solo archivos nuevos)
        if (!$expediente->tieneEspacioDisponible($archivo->getSize())) {
            return response()->json([
                'message' => 'El expediente ha alcanzado el límite de 200 MB.',
                'code'    => 'CUOTA_EXPEDIENTE_EXCEDIDA',
            ], 422);
        }

        // Hash SHA-256 del contenido real
        $hash = hash_file('sha256', $archivo->getRealPath());

        // Ruta: storage/expedientes/{user_id}/{uuid}.{ext}
        // Organizado por usuario (no por convocatoria) porque la evidencia
        // pertenece al postulante, no a una postulación específica.
        $uuid        = Str::uuid();
        $extension   = $archivo->getClientOriginalExtension();
        $rutaRelativa = "expedientes/{$postulacion->user_id}/{$uuid}.{$extension}";

        Storage::disk('local')->put($rutaRelativa, file_get_contents($archivo->getRealPath()));

        // ── Crear evidencia maestra ────────────────────────────────────────
        $evidencia = Evidencia::create([
            'user_id'           => $postulacion->user_id,
            'variable_id'       => $request->variable_id,
            'indicador_id'      => $request->indicador_id,
            'puntaje_indicador' => $request->puntaje_indicador,
            'nombre_original'   => $archivo->getClientOriginalName(),
            'ruta_archivo'      => $rutaRelativa,
            'mime_type'         => $mimeReal,
            'tamano_bytes'      => $archivo->getSize(),
            'hash_archivo'      => $hash,
            'fecha_emision'     => $request->fecha_emision,
            'estado'            => Evidencia::ESTADO_PENDIENTE,
        ]);

        // ── Calcular vigencia y crear pivote ──────────────────────────────
        $pivote = $this->crearPivote($postulacion, $evidencia);

        // ── Actualizar cuota del expediente ───────────────────────────────
        $expediente->increment('total_bytes', $archivo->getSize());

        AuditService::log('evidencia.subida', $evidencia, [], [
            'variable_id'    => $evidencia->variable_id,
            'postulacion_id' => $postulacion->id,
            'hash'           => $hash,
            'mime'           => $mimeReal,
            'bytes'          => $archivo->getSize(),
        ]);

        return response()->json([
            'evidencia'            => $evidencia->load('variable'),
            'postulacion_evidencia' => $pivote,
        ], 201);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // POST /api/v1/postulaciones/{postulacion}/evidencias/reutilizar
    // Asocia una evidencia existente (del mismo postulante) a la postulación.
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Reutiliza una evidencia maestra ya existente en una nueva postulación.
     *
     * Reglas:
     *   - La evidencia debe pertenecer al mismo postulante.
     *   - No puede estar en estado "rechazada" (excluida del selector).
     *   - No puede estar ya asociada a esta postulación.
     *   - No ocupa nueva cuota en el expediente (el archivo físico ya existe).
     */
    public function reutilizar(Request $request, Postulacion $postulacion): JsonResponse
    {
        $this->authorize('evidencias.subir');

        if ($postulacion->user_id !== $request->user()->id) {
            abort(403);
        }

        $request->validate([
            'evidencia_id' => ['required', 'exists:evidencias,id'],
        ]);

        $evidencia = Evidencia::findOrFail($request->evidencia_id);

        // Solo evidencias del propio postulante
        if ($evidencia->user_id !== $postulacion->user_id) {
            return response()->json([
                'message' => 'La evidencia no pertenece a este postulante.',
                'code'    => 'EVIDENCIA_AJENA',
            ], 403);
        }

        // Evidencias rechazadas excluidas del selector
        if ($evidencia->estado === Evidencia::ESTADO_RECHAZADA) {
            return response()->json([
                'message' => 'Las evidencias rechazadas no pueden reutilizarse.',
                'code'    => 'EVIDENCIA_RECHAZADA',
            ], 422);
        }

        // No puede asociarse dos veces a la misma postulación
        $yaAsociada = PostulacionEvidencia::where('postulacion_id', $postulacion->id)
            ->where('evidencia_id', $evidencia->id)
            ->exists();

        if ($yaAsociada) {
            return response()->json([
                'message' => 'Esta evidencia ya está asociada a la postulación.',
                'code'    => 'YA_ASOCIADA',
            ], 422);
        }

        $pivote = $this->crearPivote($postulacion, $evidencia);

        AuditService::log('evidencia.reutilizada', $evidencia, [], [
            'postulacion_id'  => $postulacion->id,
            'evidencia_id'    => $evidencia->id,
            'vigente'         => $pivote->vigente,
            'fecha_vencimiento' => $pivote->fecha_vencimiento?->toDateString(),
        ]);

        return response()->json([
            'evidencia'            => $evidencia->load('variable'),
            'postulacion_evidencia' => $pivote,
        ], 201);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // GET /api/v1/evidencias/{evidencia}/archivo
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Descarga el archivo original. Solo el postulante dueño o usuarios con
     * permiso de ver todas las evidencias.
     */
    public function descargar(Request $request, Evidencia $evidencia): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $user = $request->user();

        if ($user->id !== $evidencia->user_id && !$user->hasPermissionTo('evidencias.ver_todas')) {
            abort(403);
        }

        if (!Storage::disk('local')->exists($evidencia->ruta_archivo)) {
            abort(404, 'Archivo no encontrado en el servidor.');
        }

        return Storage::disk('local')->download(
            $evidencia->ruta_archivo,
            $evidencia->nombre_original
        );
    }

    // ──────────────────────────────────────────────────────────────────────────
    // PATCH /api/v1/evidencias/{evidencia}/validacion
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * El evaluador valida (aprueba / observa / rechaza) una evidencia en el
     * contexto de una postulación específica.
     *
     * Se requiere postulacion_id en el body para identificar el pivote.
     * La validación actualiza estado_en_postulacion en el pivote y, si es
     * la primera validación global del archivo, también evidencias.estado.
     *
     * Efecto colateral: si el estado es "observada", la postulación se marca
     * como observada.
     */
    public function validar(Request $request, Evidencia $evidencia): JsonResponse
    {
        $this->authorize('evidencias.validar');

        $data = $request->validate([
            'postulacion_id'         => ['required', 'exists:postulaciones,id'],
            'estado'                  => ['required', \Illuminate\Validation\Rule::in([
                PostulacionEvidencia::ESTADO_APROBADA,
                PostulacionEvidencia::ESTADO_OBSERVADA,
                PostulacionEvidencia::ESTADO_RECHAZADA,
            ])],
            'comentario_postulacion'  => [
                'required_if:estado,' . PostulacionEvidencia::ESTADO_OBSERVADA,
                'required_if:estado,' . PostulacionEvidencia::ESTADO_RECHAZADA,
                'nullable',
                'string',
            ],
            // El evaluador puede corregir el indicador al validar
            'indicador_id'      => ['nullable', 'exists:indicadores,id'],
            'puntaje_indicador' => ['nullable', 'numeric', 'min:0'],
        ]);

        $pivote = PostulacionEvidencia::where('postulacion_id', $data['postulacion_id'])
            ->where('evidencia_id', $evidencia->id)
            ->firstOrFail();

        $oldPivote    = $pivote->toArray();
        $oldEvidencia = $evidencia->toArray();

        // Actualizar estado en el contexto de esta postulación
        $pivote->update([
            'estado_en_postulacion'      => $data['estado'],
            'comentario_postulacion'     => $data['comentario_postulacion'] ?? null,
            'evaluador_postulacion_id'   => $request->user()->id,
            'fecha_revision_postulacion' => now(),
        ]);

        // Actualizar indicador/puntaje en la evidencia maestra si se proporcionan
        if ($request->filled('indicador_id') || $request->filled('puntaje_indicador')) {
            $evidencia->update(array_filter([
                'indicador_id'      => $data['indicador_id'] ?? null,
                'puntaje_indicador' => $data['puntaje_indicador'] ?? null,
            ], fn($v) => $v !== null));
        }

        // Si el archivo aún no ha sido validado globalmente, actualizar estado maestro.
        // Una vez aprobado globalmente, no se regresa (el archivo sigue siendo genuino).
        if ($evidencia->estado === Evidencia::ESTADO_PENDIENTE) {
            $evidencia->update([
                'estado'                => $data['estado'],
                'comentario_observacion' => $data['comentario_postulacion'] ?? null,
                'evaluador_id'          => $request->user()->id,
                'fecha_validacion'      => now(),
            ]);
        }

        // Si hay observación, marcar la postulación como observada
        if ($data['estado'] === PostulacionEvidencia::ESTADO_OBSERVADA) {
            $pivote->postulacion->update(['estado' => Postulacion::ESTADO_OBSERVADA]);
        }

        AuditService::log('evidencia.validada', $evidencia, $oldEvidencia, $evidencia->fresh()->toArray());
        AuditService::log('postulacion_evidencia.validada', $pivote, $oldPivote, $pivote->fresh()->toArray());

        return response()->json([
            'evidencia'            => $evidencia->fresh()->load('evaluador'),
            'postulacion_evidencia' => $pivote->fresh()->load('evaluadorPostulacion'),
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // GET /api/v1/users/me/evidencias
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Lista todas las evidencias maestras del postulante autenticado
     * disponibles para reutilizar (excluye rechazadas).
     *
     * Útil para el selector "reutilizar evidencia existente".
     */
    public function misEvidencias(Request $request): JsonResponse
    {
        $user = $request->user();

        $evidencias = Evidencia::reutilizables()
            ->where('user_id', $user->id)
            ->with('variable.rubro')
            ->orderByDesc('created_at')
            ->get();

        return response()->json($evidencias);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helper interno
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Crea el registro en postulacion_evidencia con la vigencia calculada.
     *
     * La vigencia se calcula contra convocatorias.fecha_inicio de la
     * postulación (no contra la fecha actual), tal como exige la sección 10
     * del spec.
     */
    private function crearPivote(Postulacion $postulacion, Evidencia $evidencia): PostulacionEvidencia
    {
        $variable         = Variable::find($evidencia->variable_id);
        $fechaConvocatoria = $postulacion->convocatoria->fecha_inicio;
        $aniosValidez     = $variable?->periodo_validez_anios;
        $fechaEmision     = $evidencia->fecha_emision;

        if ($aniosValidez === null) {
            $fechaVencimiento = null;
            $vigente          = true;
        } elseif ($fechaEmision === null) {
            $fechaVencimiento = null;
            $vigente          = null;
        } else {
            $fechaVencimiento = $fechaEmision->copy()->addYears($aniosValidez);
            $vigente          = $fechaVencimiento->gte($fechaConvocatoria);
        }

        return PostulacionEvidencia::create([
            'postulacion_id'         => $postulacion->id,
            'evidencia_id'           => $evidencia->id,
            'fecha_convocatoria'     => $fechaConvocatoria,
            'anios_validez'          => $aniosValidez,
            'fecha_vencimiento'      => $fechaVencimiento,
            'vigente'                => $vigente,
            'estado_en_postulacion'  => PostulacionEvidencia::ESTADO_PENDIENTE,
        ]);
    }
}
