<?php

namespace Tests\Feature\Evaluaciones;

use App\Models\AsignacionEvaluador;
use App\Models\Convocatoria;
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
 * Cubre el requisito de negocio (sección 10 del spec): "Los expedientes se
 * asignan previamente a un evaluador o comisión específica" — un evaluador
 * ya no puede auto-asignarse una postulación arbitraria.
 */
class AsignacionEvaluadorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    private function crearPostulacion(): array
    {
        $reglamento = ReglamentoVersion::create([
            'numero_version' => 'V1',
            'nombre'         => 'Reglamento Test',
            'fecha_vigencia' => '2020-01-01',
            'activo'         => true,
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

        Variable::create([
            'rubro_id'     => $rubro->id,
            'nombre'       => 'Variable Test',
            'orden'        => 1,
            'puntaje_max'  => 10,
            'tipo_calculo' => Variable::TIPO_SUMA_CON_TOPE,
        ]);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $convocatoria = Convocatoria::create([
            'codigo'                => 'CONV-TEST-002',
            'nombre'                => 'Convocatoria Test',
            'reglamento_version_id' => $reglamento->id,
            'tabla_evaluacion_id'   => $tabla->id,
            'tipo_proceso'          => 'contratacion',
            'fecha_inicio'          => '2026-01-01',
            'fecha_fin'             => '2026-12-31',
            'estado'                => Convocatoria::ESTADO_EN_PROCESO,
            'creado_por'            => $admin->id,
        ]);
        $convocatoria->generarSnapshot();

        $plaza = Plaza::create([
            'convocatoria_id' => $convocatoria->id,
            'facultad'        => 'Facultad Test',
            'departamento'    => 'Depto Test',
            'asignatura'      => 'Asignatura Test',
        ]);

        $postulante = User::factory()->create(['is_active' => true]);
        $postulante->assignRole('postulante');

        $postulacion = Postulacion::create([
            'user_id'         => $postulante->id,
            'convocatoria_id' => $convocatoria->id,
            'plaza_id'        => $plaza->id,
            'estado'          => Postulacion::ESTADO_EN_PROCESO,
        ]);

        $evaluador = User::factory()->create(['is_active' => true]);
        $evaluador->assignRole('evaluador');

        return compact('admin', 'convocatoria', 'postulacion', 'evaluador');
    }

    public function test_evaluador_sin_asignacion_no_puede_crear_evaluacion(): void
    {
        ['postulacion' => $postulacion, 'evaluador' => $evaluador] = $this->crearPostulacion();

        $this->actingAs($evaluador, 'sanctum')
            ->postJson("/api/v1/postulaciones/{$postulacion->id}/evaluacion")
            ->assertStatus(403)
            ->assertJson(['code' => 'EVALUADOR_NO_ASIGNADO']);
    }

    public function test_evaluador_con_asignacion_puede_crear_evaluacion(): void
    {
        ['postulacion' => $postulacion, 'evaluador' => $evaluador, 'convocatoria' => $convocatoria] = $this->crearPostulacion();

        AsignacionEvaluador::create([
            'convocatoria_id' => $convocatoria->id,
            'postulacion_id'  => $postulacion->id,
            'evaluador_id'    => $evaluador->id,
            'tipo'            => AsignacionEvaluador::TIPO_EVALUADOR,
        ]);

        $this->actingAs($evaluador, 'sanctum')
            ->postJson("/api/v1/postulaciones/{$postulacion->id}/evaluacion")
            ->assertStatus(201)
            ->assertJsonPath('evaluador_id', $evaluador->id);
    }

    public function test_admin_puede_asignar_evaluador_a_postulacion(): void
    {
        ['postulacion' => $postulacion, 'evaluador' => $evaluador, 'convocatoria' => $convocatoria, 'admin' => $admin] = $this->crearPostulacion();

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/convocatorias/{$convocatoria->id}/asignaciones", [
                'postulacion_id' => $postulacion->id,
                'evaluador_id'   => $evaluador->id,
            ])
            ->assertStatus(201)
            ->assertJsonPath('evaluador_id', $evaluador->id)
            ->assertJsonPath('postulacion_id', $postulacion->id);

        $this->assertDatabaseHas('asignaciones_evaluador', [
            'postulacion_id' => $postulacion->id,
            'evaluador_id'   => $evaluador->id,
        ]);
    }

    public function test_no_se_puede_asignar_el_mismo_evaluador_dos_veces(): void
    {
        ['postulacion' => $postulacion, 'evaluador' => $evaluador, 'convocatoria' => $convocatoria, 'admin' => $admin] = $this->crearPostulacion();

        AsignacionEvaluador::create([
            'convocatoria_id' => $convocatoria->id,
            'postulacion_id'  => $postulacion->id,
            'evaluador_id'    => $evaluador->id,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/convocatorias/{$convocatoria->id}/asignaciones", [
                'postulacion_id' => $postulacion->id,
                'evaluador_id'   => $evaluador->id,
            ])
            ->assertStatus(422)
            ->assertJson(['code' => 'YA_ASIGNADO']);
    }

    public function test_evaluador_no_puede_gestionar_asignaciones(): void
    {
        ['postulacion' => $postulacion, 'evaluador' => $evaluador, 'convocatoria' => $convocatoria] = $this->crearPostulacion();

        $this->actingAs($evaluador, 'sanctum')
            ->postJson("/api/v1/convocatorias/{$convocatoria->id}/asignaciones", [
                'postulacion_id' => $postulacion->id,
                'evaluador_id'   => $evaluador->id,
            ])
            ->assertStatus(403);
    }

    public function test_evaluador_solo_ve_sus_propias_asignaciones(): void
    {
        ['postulacion' => $postulacion, 'evaluador' => $evaluador, 'convocatoria' => $convocatoria] = $this->crearPostulacion();
        $otroEvaluador = User::factory()->create(['is_active' => true]);
        $otroEvaluador->assignRole('evaluador');

        AsignacionEvaluador::create([
            'convocatoria_id' => $convocatoria->id,
            'postulacion_id'  => $postulacion->id,
            'evaluador_id'    => $evaluador->id,
        ]);
        AsignacionEvaluador::create([
            'convocatoria_id' => $convocatoria->id,
            'postulacion_id'  => $postulacion->id,
            'evaluador_id'    => $otroEvaluador->id,
        ]);

        $response = $this->actingAs($evaluador, 'sanctum')
            ->getJson('/api/v1/asignaciones')
            ->assertOk();

        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals($evaluador->id, $data[0]['evaluador_id']);
    }

    public function test_asignacion_incluye_evaluacion_de_la_postulacion_null_hasta_que_se_inicia(): void
    {
        ['postulacion' => $postulacion, 'evaluador' => $evaluador, 'convocatoria' => $convocatoria] = $this->crearPostulacion();

        AsignacionEvaluador::create([
            'convocatoria_id' => $convocatoria->id,
            'postulacion_id'  => $postulacion->id,
            'evaluador_id'    => $evaluador->id,
        ]);

        // Antes de iniciar: la bandeja del evaluador debe poder distinguir
        // "asignada, sin evaluación creada" de una ya iniciada.
        $antes = $this->actingAs($evaluador, 'sanctum')
            ->getJson('/api/v1/asignaciones')
            ->assertOk()
            ->json('data');
        $this->assertNull($antes[0]['postulacion']['evaluacion']);

        $this->actingAs($evaluador, 'sanctum')
            ->postJson("/api/v1/postulaciones/{$postulacion->id}/evaluacion")
            ->assertStatus(201);

        $despues = $this->actingAs($evaluador, 'sanctum')
            ->getJson('/api/v1/asignaciones')
            ->assertOk()
            ->json('data');
        $this->assertNotNull($despues[0]['postulacion']['evaluacion']);
        $this->assertEquals($evaluador->id, $despues[0]['postulacion']['evaluacion']['evaluador_id']);
    }

    public function test_admin_puede_eliminar_una_asignacion(): void
    {
        ['postulacion' => $postulacion, 'evaluador' => $evaluador, 'convocatoria' => $convocatoria, 'admin' => $admin] = $this->crearPostulacion();

        $asignacion = AsignacionEvaluador::create([
            'convocatoria_id' => $convocatoria->id,
            'postulacion_id'  => $postulacion->id,
            'evaluador_id'    => $evaluador->id,
        ]);

        $this->actingAs($admin, 'sanctum')
            ->deleteJson("/api/v1/asignaciones/{$asignacion->id}")
            ->assertStatus(204);

        $this->assertDatabaseMissing('asignaciones_evaluador', ['id' => $asignacion->id]);
    }
}
