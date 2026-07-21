<?php

namespace Tests\Feature\Auditoria;

use App\Models\Convocatoria;
use App\Models\Evaluacion;
use App\Models\Plaza;
use App\Models\Postulacion;
use App\Models\Puntaje;
use App\Models\ReglamentoVersion;
use App\Models\Resultado;
use App\Models\Rubro;
use App\Models\TablaEvaluacion;
use App\Models\User;
use App\Models\Variable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El reporte interno de convocatoria (uso administrativo, nunca expuesto al
 * postulante) debe incluir el desglose completo por sub-rubro/variable del
 * ganador de cada plaza, y no referenciar el campo empate_resuelto_por_sorteo
 * (eliminado — ver ResultadosService).
 */
class ReporteConvocatoriaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    public function test_reporte_incluye_desglose_del_ganador_sin_campo_eliminado(): void
    {
        $reglamento = ReglamentoVersion::create([
            'numero_version' => 'V1', 'nombre' => 'Reglamento Test',
            'fecha_vigencia' => '2020-01-01', 'activo' => true,
        ]);

        $tabla = TablaEvaluacion::create([
            'reglamento_version_id' => $reglamento->id,
            'codigo_anexo' => 'ANEXO_REP', 'nombre' => 'Anexo Test',
            'tipo_proceso' => 'contratacion', 'puntaje_total_max' => 100,
        ]);

        $rubro = Rubro::create([
            'tabla_evaluacion_id' => $tabla->id, 'nombre' => 'Rubro Test',
            'orden' => 1, 'puntaje_max_subrubro' => 20,
        ]);

        $variable = Variable::create([
            'rubro_id' => $rubro->id, 'nombre' => 'Variable Test',
            'orden' => 1, 'puntaje_max' => 10, 'tipo_calculo' => Variable::TIPO_SUMA_CON_TOPE,
        ]);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $convocatoria = Convocatoria::create([
            'codigo' => 'CONV-REP-001', 'nombre' => 'Convocatoria Test',
            'reglamento_version_id' => $reglamento->id, 'tabla_evaluacion_id' => $tabla->id,
            'tipo_proceso' => 'contratacion', 'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-12-31',
            'estado' => Convocatoria::ESTADO_EN_PROCESO, 'creado_por' => $admin->id,
        ]);
        $convocatoria->generarSnapshot();

        $plaza = Plaza::create([
            'convocatoria_id' => $convocatoria->id, 'facultad' => 'Facultad Test',
            'departamento' => 'Depto Test', 'asignatura' => 'Asignatura Test',
        ]);

        $postulante = User::factory()->create(['is_active' => true]);
        $postulante->assignRole('postulante');

        $postulacion = Postulacion::create([
            'user_id' => $postulante->id, 'convocatoria_id' => $convocatoria->id,
            'plaza_id' => $plaza->id, 'estado' => Postulacion::ESTADO_GANADORA,
        ]);

        $evaluador = User::factory()->create(['is_active' => true]);
        $evaluador->assignRole('evaluador');

        $evaluacion = Evaluacion::create([
            'postulacion_id' => $postulacion->id, 'evaluador_id' => $evaluador->id,
            'estado' => Evaluacion::ESTADO_CERRADA, 'puntaje_total' => 8.0,
        ]);

        Puntaje::create([
            'evaluacion_id' => $evaluacion->id, 'variable_id' => $variable->id,
            'nombre_variable' => $variable->nombre, 'puntaje_bruto' => 8.0,
            'puntaje_variable' => 8.0, 'tipo_calculo' => 'SUMA_CON_TOPE',
        ]);

        Resultado::create([
            'convocatoria_id' => $convocatoria->id, 'plaza_id' => $plaza->id,
            'postulacion_id' => $postulacion->id, 'evaluacion_id' => $evaluacion->id,
            'puntaje_total' => 8.0, 'posicion' => 1, 'estado' => Resultado::ESTADO_GANADOR,
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->getJson("/api/v1/convocatorias/{$convocatoria->id}/reporte")
            ->assertOk();

        $response->assertJsonMissingPath('ganadores.0.empate_sorteo');
        $response->assertJsonPath('ganadores.0.desglose.puntaje_total', '8.00');
        $response->assertJsonPath('ganadores.0.desglose.rubros.0.variables.0.puntaje_aplicado', 8);
    }
}
