<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Etapa;
use App\Models\Indicador;
use App\Models\ReglamentoVersion;
use App\Models\Rubro;
use App\Models\TablaEvaluacion;
use App\Models\Variable;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TablasEvaluacionController extends Controller
{
    /**
     * GET /api/v1/tablas-evaluacion
     * Por default solo activo|archivado (lo seleccionable al crear una
     * convocatoria). ?estado=borrador requiere permiso de gestión — son los
     * anexos en edición, no listos para usarse.
     */
    public function index(Request $request): JsonResponse
    {
        $query = TablaEvaluacion::with('reglamentoVersion')
            ->orderBy('tipo_proceso')
            ->orderBy('codigo_anexo');

        if ($request->filled('estado')) {
            $this->authorize('tablas_evaluacion.gestionar');
            $query->where('estado', $request->estado);
        } else {
            $query->whereIn('estado', [TablaEvaluacion::ESTADO_ACTIVO, TablaEvaluacion::ESTADO_ARCHIVADO]);
        }

        return response()->json($query->get());
    }

    /**
     * GET /api/v1/tablas-evaluacion/{id}
     */
    public function show(TablaEvaluacion $tablaEvaluacion): JsonResponse
    {
        return response()->json(
            $tablaEvaluacion->load(['reglamentoVersion', 'rubros.variables.indicadores', 'etapas', 'versionAnterior'])
        );
    }

    /**
     * POST /api/v1/tablas-evaluacion
     * Crea un anexo nuevo en borrador — vacío, o clonado de uno existente
     * (clonar_de_id) con version_anterior_id apuntando al original. Editar
     * un anexo bloqueado (activo|archivado) siempre pasa por aquí, nunca por
     * mutarlo — el original nunca se toca.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('tablas_evaluacion.gestionar');

        $data = $request->validate([
            'clonar_de_id'           => ['nullable', 'exists:tablas_evaluacion,id'],
            'reglamento_version_id'  => ['nullable', 'exists:reglamento_versiones,id'],
            'reglamento_version'     => ['nullable', 'array'],
            'reglamento_version.numero_version'   => ['required_with:reglamento_version', 'string', 'max:30'],
            'reglamento_version.nombre'           => ['required_with:reglamento_version', 'string'],
            'reglamento_version.fecha_vigencia'    => ['required_with:reglamento_version', 'date'],
            'reglamento_version.documento_fuente'  => ['nullable', 'string'],
            'codigo_anexo'    => ['required_without:clonar_de_id', 'nullable', 'string', 'max:20'],
            'nombre'          => ['required_without:clonar_de_id', 'nullable', 'string'],
            'tipo_proceso'    => ['required_without:clonar_de_id', 'nullable', 'string'],
            'modalidad'       => ['nullable', 'string'],
        ]);

        // Al clonar, si no se indica una versión de reglamento explícita se
        // reutiliza la del original (un fork no siempre corresponde a una
        // Resolución nueva).
        if (empty($data['reglamento_version_id']) && empty($data['reglamento_version']) && empty($data['clonar_de_id'])) {
            return response()->json([
                'message' => 'Debes indicar una versión de reglamento existente o los datos para crear una nueva.',
                'code'    => 'RELAMENTO_VERSION_REQUERIDA',
            ], 422);
        }

        $nueva = DB::transaction(function () use ($data, $request) {
            if (!empty($data['clonar_de_id'])) {
                $original = TablaEvaluacion::with('rubros.variables.indicadores', 'etapas')
                    ->findOrFail($data['clonar_de_id']);

                $reglamentoVersionId = $data['reglamento_version_id']
                    ?? (isset($data['reglamento_version']) ? ReglamentoVersion::create($data['reglamento_version'])->id : $original->reglamento_version_id);

                $nueva = TablaEvaluacion::create([
                    'reglamento_version_id' => $reglamentoVersionId,
                    'codigo_anexo'          => $data['codigo_anexo'] ?? $original->codigo_anexo,
                    'nombre'                => $data['nombre'] ?? $original->nombre,
                    'tipo_proceso'          => $data['tipo_proceso'] ?? $original->tipo_proceso,
                    'modalidad'             => $data['modalidad'] ?? $original->modalidad,
                    'puntaje_total_max'     => $original->puntaje_total_max,
                    'puntaje_minimo_aprobatorio' => $original->puntaje_minimo_aprobatorio,
                    'minimos_subrubro'      => $original->minimos_subrubro,
                    'estado'                => TablaEvaluacion::ESTADO_BORRADOR,
                    'version_anterior_id'   => $original->id,
                ]);

                $mapaEtapas = [];
                foreach ($original->etapas as $etapa) {
                    $nuevaEtapa = Etapa::create([
                        'tabla_evaluacion_id' => $nueva->id,
                        'nombre'              => $etapa->nombre,
                        'tipo'                => $etapa->tipo,
                        'orden'               => $etapa->orden,
                    ]);
                    $mapaEtapas[$etapa->id] = $nuevaEtapa->id;
                }

                foreach ($original->rubros as $rubro) {
                    $nuevoRubro = Rubro::create([
                        'tabla_evaluacion_id'  => $nueva->id,
                        'nombre'               => $rubro->nombre,
                        'orden'                => $rubro->orden,
                        'puntaje_max_subrubro' => $rubro->puntaje_max_subrubro,
                    ]);

                    foreach ($rubro->variables as $variable) {
                        $nuevaVariable = Variable::create([
                            'rubro_id'              => $nuevoRubro->id,
                            'nombre'                => $variable->nombre,
                            'orden'                 => $variable->orden,
                            'puntaje_max'           => $variable->puntaje_max,
                            'tipo_calculo'          => $variable->tipo_calculo,
                            'periodo_validez_anios' => $variable->periodo_validez_anios,
                            'fuente_verificacion'   => $variable->fuente_verificacion,
                            'fuente'                => $variable->fuente,
                            'etapa_id'              => $variable->etapa_id ? ($mapaEtapas[$variable->etapa_id] ?? null) : null,
                        ]);

                        foreach ($variable->indicadores as $indicador) {
                            Indicador::create([
                                'variable_id'        => $nuevaVariable->id,
                                'nombre'              => $indicador->nombre,
                                'puntaje'             => $indicador->puntaje,
                                'orden'               => $indicador->orden,
                                'tabla_equivalencia'  => $indicador->tabla_equivalencia,
                            ]);
                        }
                    }
                }

                return $nueva;
            }

            $reglamentoVersionId = $data['reglamento_version_id']
                ?? ReglamentoVersion::create($data['reglamento_version'])->id;

            return TablaEvaluacion::create([
                'reglamento_version_id' => $reglamentoVersionId,
                'codigo_anexo'          => $data['codigo_anexo'],
                'nombre'                => $data['nombre'],
                'tipo_proceso'          => $data['tipo_proceso'],
                'modalidad'             => $data['modalidad'] ?? null,
                'puntaje_total_max'     => 0,
                'estado'                => TablaEvaluacion::ESTADO_BORRADOR,
            ]);
        });

        AuditService::log('tabla_evaluacion.creada', $nueva, [], $nueva->toArray());

        return response()->json(
            $nueva->load(['reglamentoVersion', 'rubros.variables.indicadores', 'etapas']),
            201
        );
    }

    /**
     * PATCH /api/v1/tablas-evaluacion/{id}
     * Solo mientras estado=borrador.
     */
    public function update(Request $request, TablaEvaluacion $tablaEvaluacion): JsonResponse
    {
        $this->authorize('tablas_evaluacion.gestionar');

        if ($tablaEvaluacion->estaBloqueada()) {
            return response()->json([
                'message' => 'Esta versión ya está activa/archivada y no se puede editar. Crea una nueva versión.',
                'code'    => 'TABLA_BLOQUEADA',
            ], 422);
        }

        $data = $request->validate([
            'nombre'                     => ['sometimes', 'string'],
            'tipo_proceso'               => ['sometimes', 'string'],
            'modalidad'                  => ['sometimes', 'nullable', 'string'],
            'puntaje_minimo_aprobatorio' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'minimos_subrubro'           => ['sometimes', 'nullable', 'array'],
            'minimos_subrubro.*.nombre'     => ['required_with:minimos_subrubro', 'string'],
            'minimos_subrubro.*.rubro_ids'  => ['required_with:minimos_subrubro', 'array', 'min:1'],
            'minimos_subrubro.*.rubro_ids.*' => ['integer', Rule::exists('rubros', 'id')->where('tabla_evaluacion_id', $tablaEvaluacion->id)],
            'minimos_subrubro.*.minimo'     => ['required_with:minimos_subrubro', 'numeric', 'min:0'],
        ]);

        $old = $tablaEvaluacion->toArray();
        $tablaEvaluacion->update($data);

        AuditService::log('tabla_evaluacion.actualizada', $tablaEvaluacion, $old, $tablaEvaluacion->fresh()->toArray());

        return response()->json($tablaEvaluacion->fresh()->load(['rubros.variables.indicadores', 'etapas']));
    }

    /**
     * POST /api/v1/tablas-evaluacion/{id}/activar
     * Valida y publica. Archiva la versión activa anterior del mismo
     * (tipo_proceso, modalidad) si existe.
     */
    public function activar(Request $request, TablaEvaluacion $tablaEvaluacion): JsonResponse
    {
        $this->authorize('tablas_evaluacion.gestionar');

        if ($tablaEvaluacion->estado !== TablaEvaluacion::ESTADO_BORRADOR) {
            return response()->json([
                'message' => 'Solo se puede activar una versión en borrador.',
                'code'    => 'ESTADO_INVALIDO',
            ], 422);
        }

        $tablaEvaluacion->load('rubros.variables.indicadores');
        $errores = $this->validarParaActivar($tablaEvaluacion);

        if (!empty($errores)) {
            return response()->json([
                'message' => 'No se puede activar: hay errores de validación.',
                'code'    => 'VALIDACION_FALLIDA',
                'errores' => $errores,
            ], 422);
        }

        // puntaje_total_max se deriva — nunca se confía en un valor manual.
        $puntajeTotalMax = $tablaEvaluacion->rubros->sum(fn ($r) => (float) $r->puntaje_max_subrubro);

        DB::transaction(function () use ($tablaEvaluacion, $puntajeTotalMax) {
            $anterior = TablaEvaluacion::where('tipo_proceso', $tablaEvaluacion->tipo_proceso)
                ->where('modalidad', $tablaEvaluacion->modalidad)
                ->where('estado', TablaEvaluacion::ESTADO_ACTIVO)
                ->where('id', '!=', $tablaEvaluacion->id)
                ->first();

            if ($anterior) {
                $anterior->update(['estado' => TablaEvaluacion::ESTADO_ARCHIVADO]);
                AuditService::log('tabla_evaluacion.archivada', $anterior, [], ['reemplazada_por' => $tablaEvaluacion->id]);
            }

            $tablaEvaluacion->update([
                'estado'            => TablaEvaluacion::ESTADO_ACTIVO,
                'puntaje_total_max' => $puntajeTotalMax,
            ]);
        });

        AuditService::log('tabla_evaluacion.activada', $tablaEvaluacion, [], $tablaEvaluacion->fresh()->toArray());

        return response()->json($tablaEvaluacion->fresh()->load(['rubros.variables.indicadores', 'etapas']));
    }

    /**
     * DELETE /api/v1/tablas-evaluacion/{id}
     * Solo borradores nunca activados.
     */
    public function destroy(TablaEvaluacion $tablaEvaluacion): JsonResponse
    {
        $this->authorize('tablas_evaluacion.gestionar');

        if ($tablaEvaluacion->estado !== TablaEvaluacion::ESTADO_BORRADOR) {
            return response()->json([
                'message' => 'Solo se puede eliminar un borrador.',
                'code'    => 'ESTADO_INVALIDO',
            ], 422);
        }

        $old = $tablaEvaluacion->toArray();
        $tablaEvaluacion->delete();

        AuditService::log('tabla_evaluacion.eliminada', $tablaEvaluacion, $old, []);

        return response()->json(null, 204);
    }

    /**
     * Validación previa a activar — ver CONTEXTO.md "Validación obligatoria
     * antes de activar". Retorna un array de mensajes de error (vacío = ok).
     */
    private function validarParaActivar(TablaEvaluacion $tabla): array
    {
        $errores = [];

        if ($tabla->rubros->isEmpty()) {
            $errores[] = 'El anexo no tiene rubros.';
        }

        foreach ($tabla->rubros as $rubro) {
            if ($rubro->variables->isEmpty()) {
                $errores[] = "El rubro \"{$rubro->nombre}\" no tiene variables.";
                continue;
            }

            foreach ($rubro->variables as $variable) {
                $prefijo = "Variable \"{$variable->nombre}\" ({$rubro->nombre})";

                match ($variable->tipo_calculo) {
                    Variable::TIPO_TABLA_EQUIVALENCIA => $this->exigirIndicadorConTabla($variable, $prefijo, $errores),
                    Variable::TIPO_MAYOR_VALOR        => $this->exigirIndicador($variable, $prefijo, $errores),
                    Variable::TIPO_DATO_INSTITUCIONAL => $this->exigirFuenteVerificacion($variable, $prefijo, $errores),
                    default => null,
                };

                if ($variable->fuente === Variable::FUENTE_ETAPA && !$variable->etapa_id) {
                    $errores[] = "{$prefijo}: fuente='etapa' requiere etapa_id.";
                }
            }
        }

        foreach ($tabla->minimos_subrubro ?? [] as $grupo) {
            $idsValidos = $tabla->rubros->pluck('id')->all();
            foreach ($grupo['rubro_ids'] ?? [] as $rubroId) {
                if (!in_array($rubroId, $idsValidos, true)) {
                    $errores[] = "minimos_subrubro \"{$grupo['nombre']}\": rubro_id {$rubroId} no pertenece a este anexo.";
                }
            }
        }

        return $errores;
    }

    private function exigirIndicadorConTabla(Variable $variable, string $prefijo, array &$errores): void
    {
        if ($variable->indicadores->isEmpty() || $variable->indicadores->every(fn ($i) => empty($i->tabla_equivalencia))) {
            $errores[] = "{$prefijo}: TABLA_EQUIVALENCIA requiere al menos un indicador con tabla_equivalencia poblada.";
        }
    }

    private function exigirIndicador(Variable $variable, string $prefijo, array &$errores): void
    {
        if ($variable->indicadores->isEmpty()) {
            $errores[] = "{$prefijo}: MAYOR_VALOR requiere al menos un indicador definido.";
        }
    }

    private function exigirFuenteVerificacion(Variable $variable, string $prefijo, array &$errores): void
    {
        if (empty($variable->fuente_verificacion)) {
            $errores[] = "{$prefijo}: DATO_INSTITUCIONAL requiere fuente_verificacion.";
        }
    }
}
