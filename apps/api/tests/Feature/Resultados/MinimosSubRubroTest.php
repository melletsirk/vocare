<?php

namespace Tests\Feature\Resultados;

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
use App\Services\ResultadosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cubre el mínimo de sub-rubro (ej. "Aptitud Docente" = rollup de Sílabo +
 * Demostración Magistral) — un candidato puede alcanzar el mínimo total y
 * aun así quedar excluido si no cumple un piso de sub-rubro. Los mínimos
 * viven en tabla_snapshot, no en una constante global.
 */
class MinimosSubRubroTest extends TestCase
{
    use RefreshDatabase;

    private ResultadosService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->service = new ResultadosService();
    }

    /** @return array{convocatoria: Convocatoria, plaza: Plaza, rubroA: Rubro, rubroB: Rubro} */
    private function crearConvocatoriaConMinimos(): array
    {
        $reglamento = ReglamentoVersion::create([
            'numero_version' => 'V1', 'nombre' => 'Reglamento Test', 'fecha_vigencia' => '2020-01-01',
        ]);

        // Rollup "Aptitud Docente" = Rubro A (Sílabo) + Rubro B (Demostración),
        // mínimo combinado de 15 — mismo patrón que Anexo 1 real.
        $tabla = TablaEvaluacion::create([
            'reglamento_version_id' => $reglamento->id, 'codigo_anexo' => 'ANEXO_MIN',
            'nombre' => 'Anexo Test', 'tipo_proceso' => 'contratacion', 'puntaje_total_max' => 100,
            'puntaje_minimo_aprobatorio' => 50,
            'estado' => TablaEvaluacion::ESTADO_ACTIVO,
        ]);

        $rubroA = Rubro::create(['tabla_evaluacion_id' => $tabla->id, 'nombre' => 'Sílabo', 'orden' => 1, 'puntaje_max_subrubro' => 5]);
        Variable::create(['rubro_id' => $rubroA->id, 'nombre' => 'Sílabo', 'orden' => 1, 'puntaje_max' => 5, 'tipo_calculo' => 'SUMA_CON_TOPE']);

        $rubroB = Rubro::create(['tabla_evaluacion_id' => $tabla->id, 'nombre' => 'Demostración', 'orden' => 2, 'puntaje_max_subrubro' => 20]);
        Variable::create(['rubro_id' => $rubroB->id, 'nombre' => 'Demostración', 'orden' => 1, 'puntaje_max' => 20, 'tipo_calculo' => 'SUMA_CON_TOPE']);

        // Resto del puntaje para poder alcanzar el mínimo total (50) sin
        // depender de Aptitud Docente.
        $rubroResto = Rubro::create(['tabla_evaluacion_id' => $tabla->id, 'nombre' => 'Resto', 'orden' => 3, 'puntaje_max_subrubro' => 75]);
        Variable::create(['rubro_id' => $rubroResto->id, 'nombre' => 'Otro', 'orden' => 1, 'puntaje_max' => 75, 'tipo_calculo' => 'SUMA_CON_TOPE']);

        $tabla->update([
            'minimos_subrubro' => [
                ['nombre' => 'Aptitud Docente', 'rubro_ids' => [$rubroA->id, $rubroB->id], 'minimo' => 15],
            ],
        ]);

        $admin = User::factory()->create(['is_active' => true]);
        $convocatoria = Convocatoria::create([
            'codigo' => 'CONV-MIN-' . uniqid(), 'nombre' => 'Convocatoria Test',
            'reglamento_version_id' => $reglamento->id, 'tabla_evaluacion_id' => $tabla->id,
            'tipo_proceso' => 'contratacion', 'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-12-31',
            'estado' => Convocatoria::ESTADO_EN_PROCESO, 'creado_por' => $admin->id,
        ]);
        $convocatoria->generarSnapshot();

        $plaza = Plaza::create([
            'convocatoria_id' => $convocatoria->id, 'facultad' => 'F', 'departamento' => 'D', 'asignatura' => 'A',
        ]);

        return compact('convocatoria', 'plaza', 'rubroA', 'rubroB', 'rubroResto');
    }

    private function crearEvaluacionConPuntajesPorRubro(Convocatoria $convocatoria, Plaza $plaza, array $puntajePorVariable): Evaluacion
    {
        $postulante = User::factory()->create(['is_active' => true]);
        $postulacion = Postulacion::create([
            'user_id' => $postulante->id, 'convocatoria_id' => $convocatoria->id,
            'plaza_id' => $plaza->id, 'estado' => Postulacion::ESTADO_APROBADA,
        ]);
        $evaluador = User::factory()->create(['is_active' => true]);
        $evaluacion = Evaluacion::create([
            'postulacion_id' => $postulacion->id, 'evaluador_id' => $evaluador->id,
            'estado' => Evaluacion::ESTADO_CERRADA,
            'puntaje_total' => array_sum($puntajePorVariable),
        ]);

        foreach ($puntajePorVariable as $variableId => $puntaje) {
            $variable = Variable::find($variableId);
            Puntaje::create([
                'evaluacion_id' => $evaluacion->id, 'variable_id' => $variableId,
                'nombre_variable' => $variable->nombre, 'puntaje_bruto' => $puntaje,
                'puntaje_variable' => $puntaje, 'puntaje_subrubro' => $puntaje,
                'tipo_calculo' => 'SUMA_CON_TOPE',
            ]);
        }

        return $evaluacion;
    }

    public function test_candidato_no_cumple_minimo_de_subrubro_queda_excluido_aunque_supere_el_total(): void
    {
        ['convocatoria' => $convocatoria, 'plaza' => $plaza, 'rubroA' => $rubroA, 'rubroB' => $rubroB, 'rubroResto' => $rubroResto] = $this->crearConvocatoriaConMinimos();

        $varSilabo = Variable::where('rubro_id', $rubroA->id)->first();
        $varDemo   = Variable::where('rubro_id', $rubroB->id)->first();
        $varResto  = Variable::where('rubro_id', $rubroResto->id)->first();

        // Total 60 (>= mínimo 50), pero Aptitud Docente = 2+5 = 7 (< mínimo 15).
        $this->crearEvaluacionConPuntajesPorRubro($convocatoria, $plaza, [
            $varSilabo->id => 2, $varDemo->id => 5, $varResto->id => 53,
        ]);

        $resultados = $this->service->generarRankingPlaza($convocatoria, $plaza);

        $this->assertCount(0, $resultados);
        $this->assertDatabaseHas('resultados', ['convocatoria_id' => $convocatoria->id, 'plaza_id' => $plaza->id, 'estado' => Resultado::ESTADO_DESIERTA]);
        // Se reporta el puntaje real (60), no 0 — para no perder esa información.
        $this->assertDatabaseHas('resultados', ['plaza_id' => $plaza->id, 'estado' => Resultado::ESTADO_DESIERTA, 'puntaje_total' => 60]);
    }

    public function test_candidato_que_cumple_ambos_minimos_gana_normalmente(): void
    {
        ['convocatoria' => $convocatoria, 'plaza' => $plaza, 'rubroA' => $rubroA, 'rubroB' => $rubroB, 'rubroResto' => $rubroResto] = $this->crearConvocatoriaConMinimos();

        $varSilabo = Variable::where('rubro_id', $rubroA->id)->first();
        $varDemo   = Variable::where('rubro_id', $rubroB->id)->first();
        $varResto  = Variable::where('rubro_id', $rubroResto->id)->first();

        // Total 60, Aptitud Docente = 5+15 = 20 (>= 15).
        $this->crearEvaluacionConPuntajesPorRubro($convocatoria, $plaza, [
            $varSilabo->id => 5, $varDemo->id => 15, $varResto->id => 40,
        ]);

        $resultados = $this->service->generarRankingPlaza($convocatoria, $plaza);

        $this->assertCount(1, $resultados);
        $this->assertEquals(Resultado::ESTADO_GANADOR, $resultados->first()->estado);
    }

    public function test_sin_minimos_subrubro_configurados_no_afecta_el_ranking(): void
    {
        // Anexo real sin mínimos aún confirmados por el cliente — no debe
        // bloquear nada (comportamiento actual preservado).
        $reglamento = ReglamentoVersion::create([
            'numero_version' => 'V2', 'nombre' => 'Sin mínimos', 'fecha_vigencia' => '2020-01-01',
        ]);
        $tabla = TablaEvaluacion::create([
            'reglamento_version_id' => $reglamento->id, 'codigo_anexo' => 'ANEXO_SM',
            'nombre' => 'Anexo Test', 'tipo_proceso' => 'contratacion', 'puntaje_total_max' => 100,
            'estado' => TablaEvaluacion::ESTADO_ACTIVO,
            // puntaje_minimo_aprobatorio y minimos_subrubro quedan null
        ]);
        $rubro = Rubro::create(['tabla_evaluacion_id' => $tabla->id, 'nombre' => 'R', 'orden' => 1, 'puntaje_max_subrubro' => 100]);
        Variable::create(['rubro_id' => $rubro->id, 'nombre' => 'V', 'orden' => 1, 'puntaje_max' => 100, 'tipo_calculo' => 'SUMA_CON_TOPE']);

        $admin = User::factory()->create(['is_active' => true]);
        $convocatoria = Convocatoria::create([
            'codigo' => 'CONV-SM-' . uniqid(), 'nombre' => 'Convocatoria Test',
            'reglamento_version_id' => $reglamento->id, 'tabla_evaluacion_id' => $tabla->id,
            'tipo_proceso' => 'contratacion', 'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-12-31',
            'estado' => Convocatoria::ESTADO_EN_PROCESO, 'creado_por' => $admin->id,
        ]);
        $convocatoria->generarSnapshot();
        $plaza = Plaza::create(['convocatoria_id' => $convocatoria->id, 'facultad' => 'F', 'departamento' => 'D', 'asignatura' => 'A']);

        $this->crearEvaluacionConPuntajesPorRubro($convocatoria, $plaza, [
            Variable::where('rubro_id', $rubro->id)->first()->id => 60,
        ]);

        $resultados = $this->service->generarRankingPlaza($convocatoria, $plaza);

        $this->assertCount(1, $resultados);
        $this->assertEquals(Resultado::ESTADO_GANADOR, $resultados->first()->estado);
    }
}
