<?php

namespace Tests\Feature\Auditoria;

use App\Models\AsignacionEvaluador;
use App\Models\AuditLog;
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
 * Cubre acciones críticas que no dejaban rastro de auditoría:
 * crear/editar plazas, y guardar un puntaje manual (TABLA_EQUIVALENCIA).
 */
class CoberturaAuditoriaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    public function test_crear_plaza_queda_registrada_en_auditoria(): void
    {
        $convocatoria = $this->crearConvocatoria();
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/convocatorias/{$convocatoria->id}/plazas", [
                'facultad' => 'Facultad Test', 'departamento' => 'Depto Test', 'asignatura' => 'Asignatura Test',
            ])->assertStatus(201);

        $this->assertDatabaseHas('audit_logs', ['event' => 'plaza.creada']);
    }

    public function test_actualizar_plaza_queda_registrada_en_auditoria(): void
    {
        $convocatoria = $this->crearConvocatoria();
        $plaza = Plaza::create([
            'convocatoria_id' => $convocatoria->id, 'facultad' => 'F', 'departamento' => 'D', 'asignatura' => 'A',
        ]);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/plazas/{$plaza->id}", ['asignatura' => 'Asignatura Nueva'])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', ['event' => 'plaza.actualizada']);
    }

    public function test_guardar_puntaje_manual_queda_registrado_en_auditoria(): void
    {
        $convocatoria = $this->crearConvocatoria();
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
        $variable = Variable::first();

        AsignacionEvaluador::create([
            'convocatoria_id' => $convocatoria->id, 'postulacion_id' => $postulacion->id, 'evaluador_id' => $evaluador->id,
        ]);

        $this->actingAs($evaluador, 'sanctum')
            ->postJson("/api/v1/evaluaciones/{$evaluacion->id}/puntajes", [
                'variable_id'   => $variable->id,
                'valor_entrada' => 17.5,
            ])->assertStatus(201);

        $this->assertDatabaseHas('audit_logs', ['event' => 'evaluacion.puntaje_manual_guardado']);
    }

    private function crearConvocatoria(): Convocatoria
    {
        $reglamento = ReglamentoVersion::create([
            'numero_version' => 'V1', 'nombre' => 'Reglamento Test',
            'fecha_vigencia' => '2020-01-01', 'activo' => true,
        ]);
        $tabla = TablaEvaluacion::create([
            'reglamento_version_id' => $reglamento->id, 'codigo_anexo' => 'ANEXO_AUD',
            'nombre' => 'Anexo Test', 'tipo_proceso' => 'contratacion', 'puntaje_total_max' => 100,
        ]);
        $rubro = Rubro::create([
            'tabla_evaluacion_id' => $tabla->id, 'nombre' => 'Rubro Test', 'orden' => 1, 'puntaje_max_subrubro' => 20,
        ]);
        Variable::create([
            'rubro_id' => $rubro->id, 'nombre' => 'Variable Test', 'orden' => 1,
            'puntaje_max' => 10, 'tipo_calculo' => Variable::TIPO_TABLA_EQUIVALENCIA,
        ]);
        $admin = User::factory()->create(['is_active' => true]);

        $convocatoria = Convocatoria::create([
            'codigo' => 'CONV-AUD-' . uniqid(), 'nombre' => 'Convocatoria Test',
            'reglamento_version_id' => $reglamento->id, 'tabla_evaluacion_id' => $tabla->id,
            'tipo_proceso' => 'contratacion', 'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-12-31',
            'estado' => Convocatoria::ESTADO_EN_PROCESO, 'creado_por' => $admin->id,
        ]);
        $convocatoria->generarSnapshot();

        return $convocatoria;
    }
}
