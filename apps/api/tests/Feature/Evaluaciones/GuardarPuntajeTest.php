<?php

namespace Tests\Feature\Evaluaciones;

use App\Models\AsignacionEvaluador;
use App\Models\Convocatoria;
use App\Models\Evaluacion;
use App\Models\Plaza;
use App\Models\Postulacion;
use App\Models\ReglamentoVersion;
use App\Models\Rubro;
use App\Models\TablaEvaluacion;
use App\Models\User;
use App\Models\Variable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * POST /evaluaciones/{id}/puntajes no verificaba pertenencia — cualquier
 * evaluador con el permiso general evaluaciones.calificar podía guardar
 * puntajes en la evaluación de otro. Cubre la corrección.
 */
class GuardarPuntajeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    private function crearEvaluacion(): array
    {
        $reglamento = ReglamentoVersion::create([
            'numero_version' => 'V1', 'nombre' => 'Reglamento Test',
            'fecha_vigencia' => '2020-01-01', 'activo' => true,
        ]);
        $tabla = TablaEvaluacion::create([
            'reglamento_version_id' => $reglamento->id, 'codigo_anexo' => 'ANEXO_GP',
            'nombre' => 'Anexo Test', 'tipo_proceso' => 'contratacion', 'puntaje_total_max' => 100,
        ]);
        $rubro = Rubro::create([
            'tabla_evaluacion_id' => $tabla->id, 'nombre' => 'Rubro Test', 'orden' => 1, 'puntaje_max_subrubro' => 20,
        ]);
        $variable = Variable::create([
            'rubro_id' => $rubro->id, 'nombre' => 'Variable Test', 'orden' => 1,
            'puntaje_max' => 10, 'tipo_calculo' => Variable::TIPO_TABLA_EQUIVALENCIA,
        ]);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $convocatoria = Convocatoria::create([
            'codigo' => 'CONV-GP-' . uniqid(), 'nombre' => 'Convocatoria Test',
            'reglamento_version_id' => $reglamento->id, 'tabla_evaluacion_id' => $tabla->id,
            'tipo_proceso' => 'contratacion', 'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-12-31',
            'estado' => Convocatoria::ESTADO_EN_PROCESO, 'creado_por' => $admin->id,
        ]);
        $convocatoria->generarSnapshot();

        $plaza = Plaza::create([
            'convocatoria_id' => $convocatoria->id, 'facultad' => 'F', 'departamento' => 'D', 'asignatura' => 'A',
        ]);
        $postulante = User::factory()->create(['is_active' => true]);
        $postulante->assignRole('postulante');
        $postulacion = Postulacion::create([
            'user_id' => $postulante->id, 'convocatoria_id' => $convocatoria->id,
            'plaza_id' => $plaza->id, 'estado' => Postulacion::ESTADO_EN_PROCESO,
        ]);

        $evaluador = User::factory()->create(['is_active' => true]);
        $evaluador->assignRole('evaluador');
        $evaluacion = Evaluacion::create([
            'postulacion_id' => $postulacion->id, 'evaluador_id' => $evaluador->id,
            'estado' => Evaluacion::ESTADO_EN_PROCESO,
        ]);

        return compact('evaluacion', 'evaluador', 'variable', 'admin');
    }

    public function test_evaluador_dueno_puede_guardar_puntaje(): void
    {
        ['evaluacion' => $evaluacion, 'evaluador' => $evaluador, 'variable' => $variable] = $this->crearEvaluacion();

        $this->actingAs($evaluador, 'sanctum')
            ->postJson("/api/v1/evaluaciones/{$evaluacion->id}/puntajes", [
                'variable_id' => $variable->id, 'valor_entrada' => 15,
            ])
            ->assertStatus(201);
    }

    public function test_evaluador_ajeno_no_puede_guardar_puntaje(): void
    {
        ['evaluacion' => $evaluacion, 'variable' => $variable] = $this->crearEvaluacion();

        $otroEvaluador = User::factory()->create(['is_active' => true]);
        $otroEvaluador->assignRole('evaluador');

        $this->actingAs($otroEvaluador, 'sanctum')
            ->postJson("/api/v1/evaluaciones/{$evaluacion->id}/puntajes", [
                'variable_id' => $variable->id, 'valor_entrada' => 20,
            ])
            ->assertStatus(403)
            ->assertJson(['code' => 'EVALUADOR_NO_ASIGNADO']);

        $this->assertDatabaseMissing('puntajes', ['evaluacion_id' => $evaluacion->id]);
    }

    public function test_admin_puede_guardar_puntaje_en_cualquier_evaluacion(): void
    {
        ['evaluacion' => $evaluacion, 'variable' => $variable, 'admin' => $admin] = $this->crearEvaluacion();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/evaluaciones/{$evaluacion->id}/puntajes", [
                'variable_id' => $variable->id, 'valor_entrada' => 12,
            ])
            ->assertStatus(201);
    }
}
