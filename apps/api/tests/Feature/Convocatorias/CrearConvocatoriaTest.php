<?php

namespace Tests\Feature\Convocatorias;

use App\Models\ReglamentoVersion;
use App\Models\TablaEvaluacion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * requisitos-sistema.md §8: la tabla de evaluación ya está construida para un
 * tipo_proceso/modalidad específicos — una convocatoria no debe poder
 * declarar un tipo_proceso/modalidad distinto al de la tabla seleccionada.
 */
class CrearConvocatoriaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    private function crearTabla(string $tipoProceso, ?string $modalidad, string $codigo): TablaEvaluacion
    {
        $reglamento = ReglamentoVersion::create([
            'numero_version' => 'V1',
            'nombre'         => 'Reglamento Test',
            'fecha_vigencia' => '2020-01-01',
            'activo'         => true,
        ]);

        return TablaEvaluacion::create([
            'reglamento_version_id' => $reglamento->id,
            'codigo_anexo'          => $codigo, // max 20 chars (varchar(20) real)
            'nombre'                => 'Anexo Test',
            'tipo_proceso'          => $tipoProceso,
            'modalidad'             => $modalidad,
            'puntaje_total_max'     => 100,
        ]);
    }

    public function test_rechaza_tipo_proceso_inconsistente_con_la_tabla(): void
    {
        $tabla = $this->crearTabla('contratacion', null, 'TEST_001');
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/convocatorias', [
                'codigo'              => 'CONV-X-001',
                'nombre'              => 'Convocatoria X',
                'tabla_evaluacion_id' => $tabla->id,
                'tipo_proceso'        => 'ascenso', // no coincide con la tabla (contratacion)
                'fecha_inicio'        => '2026-01-01',
                'fecha_fin'           => '2026-12-31',
            ])
            ->assertStatus(422)
            ->assertJson(['code' => 'TIPO_PROCESO_INCONSISTENTE']);
    }

    public function test_rechaza_modalidad_inconsistente_con_la_tabla(): void
    {
        $tabla = $this->crearTabla('ingreso_ordinaria', 'presencial', 'TEST_002');
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/convocatorias', [
                'codigo'              => 'CONV-X-002',
                'nombre'              => 'Convocatoria X',
                'tabla_evaluacion_id' => $tabla->id,
                'tipo_proceso'        => 'ingreso_ordinaria',
                'modalidad'           => 'semipresencial_distancia', // no coincide (presencial)
                'fecha_inicio'        => '2026-01-01',
                'fecha_fin'           => '2026-12-31',
            ])
            ->assertStatus(422)
            ->assertJson(['code' => 'MODALIDAD_INCONSISTENTE']);
    }

    public function test_acepta_convocatoria_consistente_con_la_tabla(): void
    {
        $tabla = $this->crearTabla('contratacion', null, 'TEST_003');
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/convocatorias', [
                'codigo'              => 'CONV-X-003',
                'nombre'              => 'Convocatoria X',
                'tabla_evaluacion_id' => $tabla->id,
                'tipo_proceso'        => 'contratacion',
                'fecha_inicio'        => '2026-01-01',
                'fecha_fin'           => '2026-12-31',
            ])
            ->assertStatus(201);
    }
}
