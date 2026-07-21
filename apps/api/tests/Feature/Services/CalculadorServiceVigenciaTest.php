<?php

namespace Tests\Feature\Services;

use App\Models\Convocatoria;
use App\Models\Evaluacion;
use App\Models\Evidencia;
use App\Models\Plaza;
use App\Models\Postulacion;
use App\Models\PostulacionEvidencia;
use App\Models\ReglamentoVersion;
use App\Models\Rubro;
use App\Models\TablaEvaluacion;
use App\Models\User;
use App\Models\Variable;
use App\Services\CalculadorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifica que CalculadorService solo sume evidencias aprobadas Y vigentes
 * en el contexto de LA postulación evaluada (postulacion_evidencia), no el
 * estado global de la evidencia ni evidencias asociadas a otras postulaciones.
 */
class CalculadorServiceVigenciaTest extends TestCase
{
    use RefreshDatabase;

    private function crearEstructuraBase(int $periodoValidezAnios = null): array
    {
        $reglamento = ReglamentoVersion::create([
            'numero_version'  => 'V1',
            'nombre'          => 'Reglamento Test',
            'fecha_vigencia'  => '2020-01-01',
            'activo'          => true,
        ]);

        $tabla = TablaEvaluacion::create([
            'reglamento_version_id' => $reglamento->id,
            'codigo_anexo'          => 'ANEXO_TEST',
            'nombre'                => 'Anexo Test',
            'tipo_proceso'          => 'contratacion',
            'puntaje_total_max'     => 100,
        ]);

        $rubro = Rubro::create([
            'tabla_evaluacion_id'  => $tabla->id,
            'nombre'               => 'Rubro Test',
            'orden'                => 1,
            'puntaje_max_subrubro' => 20,
        ]);

        $variable = Variable::create([
            'rubro_id'              => $rubro->id,
            'nombre'                => 'Variable Test',
            'orden'                 => 1,
            'puntaje_max'           => 10,
            'tipo_calculo'          => Variable::TIPO_SUMA_CON_TOPE,
            'periodo_validez_anios' => $periodoValidezAnios,
        ]);

        $admin = User::factory()->create();

        $convocatoria = Convocatoria::create([
            'codigo'                 => 'CONV-TEST-001',
            'nombre'                 => 'Convocatoria Test',
            'reglamento_version_id'  => $reglamento->id,
            'tabla_evaluacion_id'    => $tabla->id,
            'tipo_proceso'           => 'contratacion',
            'fecha_inicio'           => '2026-01-01',
            'fecha_fin'              => '2026-12-31',
            'estado'                 => Convocatoria::ESTADO_EN_PROCESO,
            'creado_por'             => $admin->id,
        ]);
        $convocatoria->generarSnapshot();

        $plaza = Plaza::create([
            'convocatoria_id' => $convocatoria->id,
            'facultad'        => 'Facultad Test',
            'departamento'    => 'Depto Test',
            'asignatura'      => 'Asignatura Test',
        ]);

        $postulante = User::factory()->create();

        $postulacion = Postulacion::create([
            'user_id'         => $postulante->id,
            'convocatoria_id' => $convocatoria->id,
            'plaza_id'        => $plaza->id,
            'estado'          => Postulacion::ESTADO_EN_PROCESO,
        ]);

        $evaluador = User::factory()->create();

        $evaluacion = Evaluacion::create([
            'postulacion_id' => $postulacion->id,
            'evaluador_id'   => $evaluador->id,
            'estado'         => Evaluacion::ESTADO_EN_PROCESO,
        ]);

        return compact('variable', 'convocatoria', 'postulacion', 'postulante', 'evaluacion');
    }

    private function crearEvidenciaConPivote(
        Postulacion $postulacion,
        Variable $variable,
        User $postulante,
        float $puntajeIndicador,
        string $estadoEnPostulacion,
        bool $vigente
    ): Evidencia {
        $evidencia = Evidencia::create([
            'user_id'           => $postulante->id,
            'variable_id'       => $variable->id,
            'puntaje_indicador' => $puntajeIndicador,
            'nombre_original'   => 'evidencia.pdf',
            'ruta_archivo'      => 'expedientes/test/evidencia.pdf',
            'mime_type'         => 'application/pdf',
            'tamano_bytes'      => 1000,
            'hash_archivo'      => str_repeat('a', 64),
            'fecha_emision'     => '2025-01-01',
            'estado'            => Evidencia::ESTADO_APROBADA,
        ]);

        PostulacionEvidencia::create([
            'postulacion_id'        => $postulacion->id,
            'evidencia_id'          => $evidencia->id,
            'fecha_convocatoria'    => $postulacion->convocatoria->fecha_inicio,
            'anios_validez'         => $variable->periodo_validez_anios,
            'vigente'               => $vigente,
            'estado_en_postulacion' => $estadoEnPostulacion,
        ]);

        return $evidencia;
    }

    public function test_evidencia_vencida_no_suma_puntaje_aunque_este_aprobada(): void
    {
        ['variable' => $variable, 'postulacion' => $postulacion, 'postulante' => $postulante, 'evaluacion' => $evaluacion]
            = $this->crearEstructuraBase(periodoValidezAnios: 2);

        // Vigente: aprobada y dentro del período de validez
        $this->crearEvidenciaConPivote($postulacion, $variable, $postulante, 5.0, PostulacionEvidencia::ESTADO_APROBADA, true);

        // Vencida: aprobada pero fuera del período de validez respecto a la convocatoria
        $this->crearEvidenciaConPivote($postulacion, $variable, $postulante, 8.0, PostulacionEvidencia::ESTADO_APROBADA, false);

        $puntajeTotal = (new CalculadorService())->calcular($evaluacion->fresh());

        // Solo la evidencia vigente (5.0) debe sumar, la vencida (8.0) se excluye
        $this->assertEquals(5.0, $puntajeTotal);
    }

    public function test_evidencia_no_aprobada_en_esta_postulacion_no_suma_puntaje(): void
    {
        ['variable' => $variable, 'postulacion' => $postulacion, 'postulante' => $postulante, 'evaluacion' => $evaluacion]
            = $this->crearEstructuraBase(periodoValidezAnios: null);

        $this->crearEvidenciaConPivote($postulacion, $variable, $postulante, 5.0, PostulacionEvidencia::ESTADO_APROBADA, true);

        // Pendiente de validación en ESTA postulación (aunque el archivo esté aprobado globalmente)
        $this->crearEvidenciaConPivote($postulacion, $variable, $postulante, 9.0, PostulacionEvidencia::ESTADO_PENDIENTE, true);

        $puntajeTotal = (new CalculadorService())->calcular($evaluacion->fresh());

        $this->assertEquals(5.0, $puntajeTotal);
    }

    public function test_tope_de_variable_se_aplica_sobre_evidencias_vigentes(): void
    {
        ['variable' => $variable, 'postulacion' => $postulacion, 'postulante' => $postulante, 'evaluacion' => $evaluacion]
            = $this->crearEstructuraBase(periodoValidezAnios: null);

        // puntaje_max de la variable es 10; dos evidencias vigentes suman 15
        $this->crearEvidenciaConPivote($postulacion, $variable, $postulante, 9.0, PostulacionEvidencia::ESTADO_APROBADA, true);
        $this->crearEvidenciaConPivote($postulacion, $variable, $postulante, 6.0, PostulacionEvidencia::ESTADO_APROBADA, true);

        $puntajeTotal = (new CalculadorService())->calcular($evaluacion->fresh());

        $this->assertEquals(10.0, $puntajeTotal);
    }
}
