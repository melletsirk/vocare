<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Evidencia;
use App\Models\Expediente;
use App\Models\Variable;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class EvidenciasController extends Controller
{
    /**
     * GET /api/v1/postulaciones/{postulacion}/evidencias
     */
    public function index(Request $request, \App\Models\Postulacion $postulacion): JsonResponse
    {
        $user = $request->user();

        // Postulante solo ve sus evidencias
        if ($user->hasRole('postulante') && $postulacion->user_id !== $user->id) {
            abort(403);
        }

        return response()->json(
            $postulacion->expediente->evidencias()->with('variable.rubro')->get()
        );
    }

    /**
     * POST /api/v1/postulaciones/{postulacion}/evidencias
     * Carga un archivo PDF/JPG/PNG (máx 10MB) y lo registra en el expediente.
     */
    public function store(Request $request, \App\Models\Postulacion $postulacion): JsonResponse
    {
        $this->authorize('evidencias.subir');

        // Solo el propio postulante puede subir evidencias
        if ($postulacion->user_id !== $request->user()->id) {
            abort(403);
        }

        $request->validate([
            'archivo'       => [
                'required',
                'file',
                'max:10240',   // 10 MB en KB
                'mimes:pdf,jpg,jpeg,png',
            ],
            'variable_id'   => ['required', 'exists:variables,id'],
            'fecha_emision' => ['nullable', 'date', 'before_or_equal:today'],
        ]);

        $archivo  = $request->file('archivo');
        $expediente = $postulacion->expediente;

        // Validar MIME real (no solo extensión)
        $mimeReal = $archivo->getMimeType();
        if (!in_array($mimeReal, Evidencia::MIMES_PERMITIDOS)) {
            return response()->json([
                'message' => 'El tipo de archivo no está permitido. Solo PDF, JPG o PNG.',
                'code'    => 'MIME_NO_PERMITIDO',
            ], 422);
        }

        // Validar cuota del expediente (200MB)
        if (!$expediente->tieneEspacioDisponible($archivo->getSize())) {
            return response()->json([
                'message' => 'El expediente ha alcanzado el límite de 200 MB.',
                'code'    => 'CUOTA_EXPEDIENTE_EXCEDIDA',
            ], 422);
        }

        // Calcular hash SHA-256 del contenido real
        $hash = hash_file('sha256', $archivo->getRealPath());

        // Ruta: storage/expedientes/{convocatoria_id}/{postulacion_id}/{uuid}.{ext}
        $convocatoriaId = $postulacion->convocatoria_id;
        $uuid           = Str::uuid();
        $extension      = $archivo->getClientOriginalExtension();
        $rutaRelativa   = "expedientes/{$convocatoriaId}/{$postulacion->id}/{$uuid}.{$extension}";

        // Guardar en disco local (volumen compartido)
        Storage::disk('local')->put($rutaRelativa, file_get_contents($archivo->getRealPath()));

        // Registrar en BD
        $evidencia = $expediente->evidencias()->create([
            'variable_id'    => $request->variable_id,
            'nombre_original' => $archivo->getClientOriginalName(),
            'ruta_archivo'   => $rutaRelativa,
            'mime_type'      => $mimeReal,
            'tamano_bytes'   => $archivo->getSize(),
            'hash_archivo'   => $hash,
            'fecha_emision'  => $request->fecha_emision,
            'estado'         => Evidencia::ESTADO_PENDIENTE,
        ]);

        // Actualizar total de bytes del expediente
        $expediente->increment('total_bytes', $archivo->getSize());

        AuditService::log('evidencia.subida', $evidencia, [], [
            'variable_id' => $evidencia->variable_id,
            'hash'        => $hash,
            'mime'        => $mimeReal,
            'bytes'       => $archivo->getSize(),
        ]);

        return response()->json($evidencia->load('variable'), 201);
    }

    /**
     * GET /api/v1/evidencias/{evidencia}/archivo
     * Descarga el archivo original. Solo usuarios autorizados.
     */
    public function descargar(Request $request, Evidencia $evidencia): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $user = $request->user();
        $postulante = $evidencia->expediente->postulacion->user_id;

        // Solo el postulante dueño o usuarios con permiso de ver todas
        if ($user->id !== $postulante && !$user->hasPermissionTo('evidencias.ver_todas')) {
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

    /**
     * PATCH /api/v1/evidencias/{evidencia}/validacion
     * Evaluador aprueba, observa o rechaza una evidencia.
     */
    public function validar(Request $request, Evidencia $evidencia): JsonResponse
    {
        $this->authorize('evidencias.validar');

        $data = $request->validate([
            'estado'                  => ['required', \Illuminate\Validation\Rule::in([
                Evidencia::ESTADO_APROBADA,
                Evidencia::ESTADO_OBSERVADA,
                Evidencia::ESTADO_RECHAZADA,
            ])],
            'comentario_observacion'  => [
                'required_if:estado,' . Evidencia::ESTADO_OBSERVADA,
                'required_if:estado,' . Evidencia::ESTADO_RECHAZADA,
                'nullable',
                'string',
            ],
        ]);

        $old = $evidencia->toArray();

        $evidencia->update([
            ...$data,
            'evaluador_id'     => $request->user()->id,
            'fecha_validacion' => now(),
        ]);

        // Si hay observación en evidencia, poner la postulación también como observada
        if ($data['estado'] === Evidencia::ESTADO_OBSERVADA) {
            $evidencia->expediente->postulacion->update(['estado' => Postulacion::ESTADO_OBSERVADA ?? 'observada']);
        }

        AuditService::log('evidencia.validada', $evidencia, $old, $evidencia->fresh()->toArray());

        return response()->json($evidencia->fresh()->load('evaluador'));
    }
}
