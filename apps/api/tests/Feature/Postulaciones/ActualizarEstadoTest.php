<?php

namespace Tests\Feature\Postulaciones;

use App\Models\Convocatoria;
use App\Models\Plaza;
use App\Models\Postulacion;
use App\Models\ReglamentoVersion;
use App\Models\TablaEvaluacion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PATCH /postulaciones/{id}/estado no tenía ningún chequeo de autorización:
 * cualquier usuario autenticado (incluido el propio postulante) podía marcar
 * su postulación (o la de otro) como rechazada/ganadora/etc. Este test cubre
 * la corrección: solo evaluador/admin (permiso postulaciones.ver_todas).
 */
class ActualizarEstadoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    private function crearPostulacion(): Postulacion
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

        $admin = User::factory()->create(['is_active' => true]);

        $convocatoria = Convocatoria::create([
            'codigo'                => 'CONV-TEST-003',
            'nombre'                => 'Convocatoria Test',
            'reglamento_version_id' => $reglamento->id,
            'tabla_evaluacion_id'   => $tabla->id,
            'tipo_proceso'          => 'contratacion',
            'fecha_inicio'          => '2026-01-01',
            'fecha_fin'             => '2026-12-31',
            'estado'                => Convocatoria::ESTADO_EN_PROCESO,
            'creado_por'            => $admin->id,
        ]);

        $plaza = Plaza::create([
            'convocatoria_id' => $convocatoria->id,
            'facultad'        => 'Facultad Test',
            'departamento'    => 'Depto Test',
            'asignatura'      => 'Asignatura Test',
        ]);

        $postulante = User::factory()->create(['is_active' => true]);
        $postulante->assignRole('postulante');

        return Postulacion::create([
            'user_id'         => $postulante->id,
            'convocatoria_id' => $convocatoria->id,
            'plaza_id'        => $plaza->id,
            'estado'          => Postulacion::ESTADO_EN_PROCESO,
        ]);
    }

    public function test_postulante_no_puede_cambiar_el_estado_de_su_propia_postulacion(): void
    {
        $postulacion = $this->crearPostulacion();
        $postulante  = $postulacion->postulante;

        $this->actingAs($postulante, 'sanctum')
            ->patchJson("/api/v1/postulaciones/{$postulacion->id}/estado", [
                'estado' => Postulacion::ESTADO_GANADORA,
            ])
            ->assertStatus(403);

        $this->assertDatabaseHas('postulaciones', [
            'id'     => $postulacion->id,
            'estado' => Postulacion::ESTADO_EN_PROCESO,
        ]);
    }

    public function test_postulante_no_puede_cambiar_el_estado_de_otra_postulacion(): void
    {
        $postulacion = $this->crearPostulacion();
        $otroPostulante = User::factory()->create(['is_active' => true]);
        $otroPostulante->assignRole('postulante');

        $this->actingAs($otroPostulante, 'sanctum')
            ->patchJson("/api/v1/postulaciones/{$postulacion->id}/estado", [
                'estado' => Postulacion::ESTADO_RECHAZADA,
                'motivo_rechazo' => 'porque sí',
            ])
            ->assertStatus(403);
    }

    public function test_evaluador_puede_cambiar_el_estado_de_una_postulacion(): void
    {
        $postulacion = $this->crearPostulacion();
        $evaluador   = User::factory()->create(['is_active' => true]);
        $evaluador->assignRole('evaluador');

        $this->actingAs($evaluador, 'sanctum')
            ->patchJson("/api/v1/postulaciones/{$postulacion->id}/estado", [
                'estado' => Postulacion::ESTADO_OBSERVADA,
            ])
            ->assertOk()
            ->assertJsonPath('estado', Postulacion::ESTADO_OBSERVADA);
    }
}
