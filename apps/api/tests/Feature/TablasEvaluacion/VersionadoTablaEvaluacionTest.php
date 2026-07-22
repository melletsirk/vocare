<?php

namespace Tests\Feature\TablasEvaluacion;

use App\Models\ReglamentoVersion;
use App\Models\Rubro;
use App\Models\TablaEvaluacion;
use App\Models\User;
use App\Models\Variable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cubre el ciclo de vida borrador/activo/archivado, fork por anexo
 * individual, y el índice único parcial a nivel de BD (incluido el caso
 * NULL de modalidad, que un índice único plano NO habría detectado).
 */
class VersionadoTablaEvaluacionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');
        return $admin;
    }

    private function reglamento(): ReglamentoVersion
    {
        return ReglamentoVersion::create([
            'numero_version' => 'V1', 'nombre' => 'Reglamento Test', 'fecha_vigencia' => '2020-01-01',
        ]);
    }

    public function test_crear_tabla_evaluacion_queda_en_borrador(): void
    {
        $admin = $this->admin();
        $reglamento = $this->reglamento();

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/tablas-evaluacion', [
                'reglamento_version_id' => $reglamento->id,
                'codigo_anexo' => 'ANEXO_X', 'nombre' => 'Anexo X',
                'tipo_proceso' => 'contratacion', 'modalidad' => null,
            ])
            ->assertStatus(201);

        $response->assertJsonPath('estado', 'borrador');
    }

    public function test_no_se_puede_activar_sin_rubros(): void
    {
        $admin = $this->admin();
        $tabla = TablaEvaluacion::create([
            'reglamento_version_id' => $this->reglamento()->id,
            'codigo_anexo' => 'ANEXO_VACIO', 'nombre' => 'Vacío',
            'tipo_proceso' => 'contratacion', 'puntaje_total_max' => 0,
            'estado' => 'borrador',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/tablas-evaluacion/{$tabla->id}/activar")
            ->assertStatus(422)
            ->assertJson(['code' => 'VALIDACION_FALLIDA']);
    }

    public function test_activar_deriva_puntaje_total_max_y_archiva_la_version_anterior(): void
    {
        $admin = $this->admin();
        $reglamento = $this->reglamento();

        // Versión 1: activa
        $tablaV1 = TablaEvaluacion::create([
            'reglamento_version_id' => $reglamento->id, 'codigo_anexo' => 'ANEXO_Y',
            'nombre' => 'Anexo Y', 'tipo_proceso' => 'contratacion', 'modalidad' => null,
            'puntaje_total_max' => 10, 'estado' => 'activo',
        ]);
        $rubroV1 = Rubro::create(['tabla_evaluacion_id' => $tablaV1->id, 'nombre' => 'R1', 'orden' => 1, 'puntaje_max_subrubro' => 10]);
        Variable::create(['rubro_id' => $rubroV1->id, 'nombre' => 'V1', 'orden' => 1, 'puntaje_max' => 10, 'tipo_calculo' => 'SUMA_CON_TOPE']);

        // Fork: version 2, en borrador
        $tablaV2 = TablaEvaluacion::create([
            'reglamento_version_id' => $reglamento->id, 'codigo_anexo' => 'ANEXO_Y',
            'nombre' => 'Anexo Y v2', 'tipo_proceso' => 'contratacion', 'modalidad' => null,
            'puntaje_total_max' => 0, 'estado' => 'borrador', 'version_anterior_id' => $tablaV1->id,
        ]);
        $rubroV2 = Rubro::create(['tabla_evaluacion_id' => $tablaV2->id, 'nombre' => 'R1', 'orden' => 1, 'puntaje_max_subrubro' => 15]);
        Variable::create(['rubro_id' => $rubroV2->id, 'nombre' => 'V1', 'orden' => 1, 'puntaje_max' => 15, 'tipo_calculo' => 'SUMA_CON_TOPE']);

        $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/tablas-evaluacion/{$tablaV2->id}/activar")
            ->assertOk()
            ->assertJsonPath('estado', 'activo')
            ->assertJsonPath('puntaje_total_max', '15.00');

        $this->assertDatabaseHas('tablas_evaluacion', ['id' => $tablaV1->id, 'estado' => 'archivado']);
    }

    public function test_no_se_puede_editar_una_tabla_bloqueada(): void
    {
        $admin = $this->admin();
        $tabla = TablaEvaluacion::create([
            'reglamento_version_id' => $this->reglamento()->id, 'codigo_anexo' => 'ANEXO_Z',
            'nombre' => 'Z', 'tipo_proceso' => 'contratacion', 'puntaje_total_max' => 10,
            'estado' => 'activo',
        ]);

        $this->actingAs($admin, 'sanctum')
            ->patchJson("/api/v1/tablas-evaluacion/{$tabla->id}", ['nombre' => 'Intento de editar'])
            ->assertStatus(422)
            ->assertJson(['code' => 'TABLA_BLOQUEADA']);
    }

    public function test_indice_unico_impide_dos_activas_con_modalidad_null(): void
    {
        // Este es el caso que un índice único plano (sin COALESCE) NO detecta:
        // dos filas con tipo_proceso igual y modalidad NULL.
        $reglamento = $this->reglamento();

        TablaEvaluacion::create([
            'reglamento_version_id' => $reglamento->id, 'codigo_anexo' => 'ANEXO_N1',
            'nombre' => 'N1', 'tipo_proceso' => 'contratacion', 'modalidad' => null,
            'puntaje_total_max' => 10, 'estado' => 'activo',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        TablaEvaluacion::create([
            'reglamento_version_id' => $reglamento->id, 'codigo_anexo' => 'ANEXO_N2',
            'nombre' => 'N2', 'tipo_proceso' => 'contratacion', 'modalidad' => null,
            'puntaje_total_max' => 10, 'estado' => 'activo',
        ]);
    }

    public function test_indice_unico_permite_dos_borradores_con_modalidad_null(): void
    {
        // Solo "activo" está restringido — dos borradores del mismo
        // (tipo_proceso, modalidad) sí pueden coexistir mientras se decide
        // cuál activar.
        $reglamento = $this->reglamento();

        TablaEvaluacion::create([
            'reglamento_version_id' => $reglamento->id, 'codigo_anexo' => 'ANEXO_B1',
            'nombre' => 'B1', 'tipo_proceso' => 'contratacion', 'modalidad' => null,
            'puntaje_total_max' => 10, 'estado' => 'borrador',
        ]);

        $segundo = TablaEvaluacion::create([
            'reglamento_version_id' => $reglamento->id, 'codigo_anexo' => 'ANEXO_B2',
            'nombre' => 'B2', 'tipo_proceso' => 'contratacion', 'modalidad' => null,
            'puntaje_total_max' => 10, 'estado' => 'borrador',
        ]);

        $this->assertNotNull($segundo->id);
    }

    public function test_tabla_equivalencia_sin_indicador_falla_validacion(): void
    {
        $admin = $this->admin();
        $tabla = TablaEvaluacion::create([
            'reglamento_version_id' => $this->reglamento()->id, 'codigo_anexo' => 'ANEXO_TE',
            'nombre' => 'TE', 'tipo_proceso' => 'contratacion', 'puntaje_total_max' => 0,
            'estado' => 'borrador',
        ]);
        $rubro = Rubro::create(['tabla_evaluacion_id' => $tabla->id, 'nombre' => 'R', 'orden' => 1, 'puntaje_max_subrubro' => 10]);
        Variable::create([
            'rubro_id' => $rubro->id, 'nombre' => 'Nota', 'orden' => 1,
            'puntaje_max' => 10, 'tipo_calculo' => Variable::TIPO_TABLA_EQUIVALENCIA,
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson("/api/v1/tablas-evaluacion/{$tabla->id}/activar")
            ->assertStatus(422);

        $this->assertStringContainsString('TABLA_EQUIVALENCIA', $response->json('errores.0'));
    }
}
