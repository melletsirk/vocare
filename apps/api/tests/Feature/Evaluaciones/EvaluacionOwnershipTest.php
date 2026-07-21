<?php

namespace Tests\Feature\Evaluaciones;

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
 * show()/calcular()/cerrar() solo verificaban el permiso general
 * (evaluaciones.ver / evaluaciones.calificar / evaluaciones.cerrar) — mismo
 * bug que guardarPuntaje: cualquier evaluador podía ver, recalcular o cerrar
 * la evaluación de otro. Cubre la corrección para los tres endpoints.
 */
class EvaluacionOwnershipTest extends TestCase
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
            'reglamento_version_id' => $reglamento->id, 'codigo_anexo' => 'ANEXO_OWN',
            'nombre' => 'Anexo Test', 'tipo_proceso' => 'contratacion', 'puntaje_total_max' => 100,
        ]);
        $rubro = Rubro::create([
            'tabla_evaluacion_id' => $tabla->id, 'nombre' => 'Rubro Test', 'orden' => 1, 'puntaje_max_subrubro' => 20,
        ]);
        Variable::create([
            'rubro_id' => $rubro->id, 'nombre' => 'Variable Test', 'orden' => 1,
            'puntaje_max' => 10, 'tipo_calculo' => Variable::TIPO_SUMA_CON_TOPE,
        ]);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $convocatoria = Convocatoria::create([
            'codigo' => 'CONV-OWN-' . uniqid(), 'nombre' => 'Convocatoria Test',
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

        $otroEvaluador = User::factory()->create(['is_active' => true]);
        $otroEvaluador->assignRole('evaluador');

        return compact('evaluacion', 'evaluador', 'otroEvaluador', 'admin');
    }

    // -------------------------------------------------------------------------
    // show()
    // -------------------------------------------------------------------------

    public function test_evaluador_dueno_puede_ver_su_evaluacion(): void
    {
        ['evaluacion' => $evaluacion, 'evaluador' => $evaluador] = $this->crearEvaluacion();

        $this->actingAs($evaluador, 'sanctum')
            ->getJson("/api/v1/evaluaciones/{$evaluacion->id}")
            ->assertOk();
    }

    public function test_evaluador_ajeno_no_puede_ver_evaluacion_de_otro(): void
    {
        ['evaluacion' => $evaluacion, 'otroEvaluador' => $otroEvaluador] = $this->crearEvaluacion();

        $this->actingAs($otroEvaluador, 'sanctum')
            ->getJson("/api/v1/evaluaciones/{$evaluacion->id}")
            ->assertStatus(403);
    }

    public function test_admin_puede_ver_cualquier_evaluacion(): void
    {
        ['evaluacion' => $evaluacion, 'admin' => $admin] = $this->crearEvaluacion();

        $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/evaluaciones/{$evaluacion->id}")
            ->assertOk();
    }

    // -------------------------------------------------------------------------
    // calcular()
    // -------------------------------------------------------------------------

    public function test_evaluador_ajeno_no_puede_recalcular(): void
    {
        ['evaluacion' => $evaluacion, 'otroEvaluador' => $otroEvaluador] = $this->crearEvaluacion();

        $this->actingAs($otroEvaluador, 'sanctum')
            ->postJson("/api/v1/evaluaciones/{$evaluacion->id}/calcular")
            ->assertStatus(403)
            ->assertJson(['code' => 'EVALUADOR_NO_ASIGNADO']);
    }

    public function test_evaluador_dueno_puede_recalcular(): void
    {
        ['evaluacion' => $evaluacion, 'evaluador' => $evaluador] = $this->crearEvaluacion();

        $this->actingAs($evaluador, 'sanctum')
            ->postJson("/api/v1/evaluaciones/{$evaluacion->id}/calcular")
            ->assertOk();
    }

    // -------------------------------------------------------------------------
    // cerrar()
    // -------------------------------------------------------------------------

    public function test_evaluador_ajeno_no_puede_cerrar(): void
    {
        ['evaluacion' => $evaluacion, 'otroEvaluador' => $otroEvaluador] = $this->crearEvaluacion();
        $evaluacion->update(['puntaje_total' => 10]);

        $this->actingAs($otroEvaluador, 'sanctum')
            ->postJson("/api/v1/evaluaciones/{$evaluacion->id}/cerrar")
            ->assertStatus(403)
            ->assertJson(['code' => 'EVALUADOR_NO_ASIGNADO']);

        $this->assertDatabaseHas('evaluaciones', ['id' => $evaluacion->id, 'estado' => Evaluacion::ESTADO_EN_PROCESO]);
    }

    public function test_evaluador_dueno_puede_cerrar(): void
    {
        ['evaluacion' => $evaluacion, 'evaluador' => $evaluador] = $this->crearEvaluacion();
        $evaluacion->update(['puntaje_total' => 10]);

        $this->actingAs($evaluador, 'sanctum')
            ->postJson("/api/v1/evaluaciones/{$evaluacion->id}/cerrar")
            ->assertOk();
    }
}
