<?php

namespace Tests\Feature\Evaluaciones;

use App\Models\Convocatoria;
use App\Models\Evaluacion;
use App\Models\Indicador;
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
 * calcular() borraba TODOS los puntajes de la evaluación (incluido el que
 * guardarPuntaje() acababa de crear con valor_entrada) antes de que
 * tablaEquivalencia() llegara a leerlo — así que cualquier variable
 * TABLA_EQUIVALENCIA (Renacyt, Dictado de Clases... en Anexos 3/4/6/7)
 * quedaba en 0 en cuanto se recalculaba, sin importar lo que el evaluador
 * hubiera ingresado. Cubre el flujo real: guardarPuntaje → calcular.
 */
class CalcularTablaEquivalenciaTest extends TestCase
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
            'reglamento_version_id' => $reglamento->id, 'codigo_anexo' => 'ANEXO_TE',
            'nombre' => 'Anexo Test', 'tipo_proceso' => 'contratacion', 'puntaje_total_max' => 10,
        ]);
        $rubro = Rubro::create([
            'tabla_evaluacion_id' => $tabla->id, 'nombre' => 'Rubro Test', 'orden' => 1, 'puntaje_max_subrubro' => 10,
        ]);
        $variable = Variable::create([
            'rubro_id' => $rubro->id, 'nombre' => 'Renacyt', 'orden' => 1,
            'puntaje_max' => 8, 'tipo_calculo' => Variable::TIPO_TABLA_EQUIVALENCIA,
        ]);

        // La tabla de rangos vive en el Indicador — es lo que
        // Convocatoria::generarSnapshot() copia a tabla_snapshot y de donde
        // CalculadorService::tablaEquivalencia() la lee (no de Puntaje.detalle,
        // que calcular() reescribe con otra forma en cada corrida).
        Indicador::create([
            'variable_id' => $variable->id,
            'nombre'      => 'Nota',
            'puntaje'     => 0,
            'orden'       => 1,
            'tabla_equivalencia' => [
                ['min' => 0,  'max' => 13, 'puntaje' => 0],
                ['min' => 14, 'max' => 16, 'puntaje' => 4],
                ['min' => 17, 'max' => 20, 'puntaje' => 8],
            ],
        ]);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $convocatoria = Convocatoria::create([
            'codigo' => 'CONV-TE-' . uniqid(), 'nombre' => 'Convocatoria Test',
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

        return compact('evaluacion', 'evaluador', 'variable');
    }

    public function test_calcular_respeta_el_valor_entrada_guardado_antes(): void
    {
        ['evaluacion' => $evaluacion, 'evaluador' => $evaluador, 'variable' => $variable] = $this->crearEvaluacion();

        $tablaEquivalencia = [
            ['min' => 0,  'max' => 13, 'puntaje' => 0],
            ['min' => 14, 'max' => 16, 'puntaje' => 4],
            ['min' => 17, 'max' => 20, 'puntaje' => 8],
        ];

        // 1. El evaluador guarda el valor de entrada (nota 18) — igual que
        //    hace el frontend en guardarTablaEquivalencia().
        $this->actingAs($evaluador, 'sanctum')
            ->postJson("/api/v1/evaluaciones/{$evaluacion->id}/puntajes", [
                'variable_id'        => $variable->id,
                'valor_entrada'      => 18,
                'tabla_equivalencia' => $tablaEquivalencia,
            ])
            ->assertStatus(201);

        // 2. Recalcular — es lo que el frontend dispara justo después.
        $respuesta = $this->actingAs($evaluador, 'sanctum')
            ->postJson("/api/v1/evaluaciones/{$evaluacion->id}/calcular")
            ->assertStatus(200);

        // 18 cae en el rango [17,20] → 8 puntos, no 0.
        $respuesta->assertJson(['puntaje_total' => 8]);
        $this->assertDatabaseHas('puntajes', [
            'evaluacion_id'    => $evaluacion->id,
            'variable_id'      => $variable->id,
            'puntaje_variable' => 8,
            'valor_entrada'    => 18,
        ]);

        // 3. Recalcular una SEGUNDA vez (ej. el evaluador reabre la pantalla)
        //    debe seguir dando 8, no perder el valor en el siguiente ciclo.
        $segundaVez = $this->actingAs($evaluador, 'sanctum')
            ->postJson("/api/v1/evaluaciones/{$evaluacion->id}/calcular")
            ->assertStatus(200);

        $segundaVez->assertJson(['puntaje_total' => 8]);

        // 4. Y una tercera, para asegurar que no es un efecto de un solo ciclo.
        $terceraVez = $this->actingAs($evaluador, 'sanctum')
            ->postJson("/api/v1/evaluaciones/{$evaluacion->id}/calcular")
            ->assertStatus(200);

        $terceraVez->assertJson(['puntaje_total' => 8]);
    }
}
