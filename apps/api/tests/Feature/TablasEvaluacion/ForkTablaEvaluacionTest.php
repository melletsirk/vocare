<?php

namespace Tests\Feature\TablasEvaluacion;

use App\Models\Etapa;
use App\Models\Indicador;
use App\Models\ReglamentoVersion;
use App\Models\Rubro;
use App\Models\TablaEvaluacion;
use App\Models\User;
use App\Models\Variable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Editar" un anexo bloqueado siempre crea una versión nueva (fork),
 * clonando rubros/variables/indicadores/etapas — nunca muta el original.
 */
class ForkTablaEvaluacionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    public function test_clonar_una_tabla_activa_copia_su_estructura_completa(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $reglamento = ReglamentoVersion::create([
            'numero_version' => 'V1', 'nombre' => 'Original', 'fecha_vigencia' => '2020-01-01',
        ]);

        $original = TablaEvaluacion::create([
            'reglamento_version_id' => $reglamento->id, 'codigo_anexo' => 'ANEXO_ORIG',
            'nombre' => 'Original', 'tipo_proceso' => 'contratacion', 'modalidad' => null,
            'puntaje_total_max' => 10, 'estado' => TablaEvaluacion::ESTADO_ACTIVO,
        ]);

        $etapa = Etapa::create(['tabla_evaluacion_id' => $original->id, 'nombre' => 'Clase Magistral', 'tipo' => 'clase_magistral', 'orden' => 1]);

        $rubro = Rubro::create(['tabla_evaluacion_id' => $original->id, 'nombre' => 'Rubro 1', 'orden' => 1, 'puntaje_max_subrubro' => 10]);
        $variable = Variable::create([
            'rubro_id' => $rubro->id, 'nombre' => 'Variable 1', 'orden' => 1, 'puntaje_max' => 10,
            'tipo_calculo' => Variable::TIPO_SUMA_CON_TOPE, 'fuente' => Variable::FUENTE_ETAPA, 'etapa_id' => $etapa->id,
        ]);
        Indicador::create(['variable_id' => $variable->id, 'nombre' => 'Ind 1', 'puntaje' => 10, 'orden' => 1]);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/tablas-evaluacion', ['clonar_de_id' => $original->id])
            ->assertStatus(201);

        $nuevaId = $response->json('id');

        $this->assertNotEquals($original->id, $nuevaId);
        $response->assertJsonPath('estado', 'borrador');
        $response->assertJsonPath('version_anterior_id', $original->id);
        $response->assertJsonPath('codigo_anexo', 'ANEXO_ORIG');
        $response->assertJsonCount(1, 'rubros');
        $response->assertJsonCount(1, 'rubros.0.variables');
        $response->assertJsonCount(1, 'rubros.0.variables.0.indicadores');
        $response->assertJsonCount(1, 'etapas');

        // El id de la nueva variable NO es el mismo que el original, pero su
        // etapa_id apunta a la ETAPA CLONADA (no a la del original).
        $nuevaEtapaId = $response->json('etapas.0.id');
        $this->assertNotEquals($etapa->id, $nuevaEtapaId);
        $this->assertEquals($nuevaEtapaId, $response->json('rubros.0.variables.0.etapa_id'));

        // El original sigue intacto y bloqueado.
        $this->assertDatabaseHas('tablas_evaluacion', ['id' => $original->id, 'estado' => 'activo']);
        $this->assertDatabaseCount('rubros', 2); // original + clon
    }
}
