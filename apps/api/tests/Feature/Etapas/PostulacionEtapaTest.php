<?php

namespace Tests\Feature\Etapas;

use App\Models\AsignacionEvaluador;
use App\Models\Convocatoria;
use App\Models\Etapa;
use App\Models\Evaluacion;
use App\Models\Plaza;
use App\Models\Postulacion;
use App\Models\PostulacionEtapa;
use App\Models\ReglamentoVersion;
use App\Models\Rubro;
use App\Models\TablaEvaluacion;
use App\Models\User;
use App\Models\Variable;
use App\Services\CalculadorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cubre el flujo completo de Clase Magistral: postulacion_etapa se
 * auto-crea al postular, el evento puede registrarse después (brecha de
 * tiempo real), y CalculadorService lee su puntaje para variables con
 * fuente='etapa' — el gap que SUMA_CON_TOPE tenía (sin evidencia, sin forma
 * de puntuar un evento en vivo).
 */
class PostulacionEtapaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    private function crearEscenario(): array
    {
        $reglamento = ReglamentoVersion::create([
            'numero_version' => 'V1', 'nombre' => 'Reglamento Test', 'fecha_vigencia' => '2020-01-01',
        ]);

        $tabla = TablaEvaluacion::create([
            'reglamento_version_id' => $reglamento->id, 'codigo_anexo' => 'ANEXO_CM',
            'nombre' => 'Anexo Test', 'tipo_proceso' => 'contratacion', 'puntaje_total_max' => 20,
            'estado' => TablaEvaluacion::ESTADO_ACTIVO,
        ]);

        $etapaClaseMagistral = Etapa::create([
            'tabla_evaluacion_id' => $tabla->id, 'nombre' => 'Clase Magistral',
            'tipo' => Etapa::TIPO_CLASE_MAGISTRAL, 'orden' => 1,
        ]);

        $rubro = Rubro::create(['tabla_evaluacion_id' => $tabla->id, 'nombre' => 'Demostración', 'orden' => 1, 'puntaje_max_subrubro' => 20]);
        $variable = Variable::create([
            'rubro_id' => $rubro->id, 'nombre' => 'Desempeño Docente', 'orden' => 1, 'puntaje_max' => 20,
            'tipo_calculo' => Variable::TIPO_SUMA_CON_TOPE,
            'fuente' => Variable::FUENTE_ETAPA, 'etapa_id' => $etapaClaseMagistral->id,
        ]);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole('admin');

        $convocatoria = Convocatoria::create([
            'codigo' => 'CONV-CM-' . uniqid(), 'nombre' => 'Convocatoria Test',
            'reglamento_version_id' => $reglamento->id, 'tabla_evaluacion_id' => $tabla->id,
            'tipo_proceso' => 'contratacion', 'fecha_inicio' => '2026-01-01', 'fecha_fin' => '2026-12-31',
            'estado' => Convocatoria::ESTADO_PUBLICADA, 'creado_por' => $admin->id,
        ]);
        $convocatoria->generarSnapshot();

        $plaza = Plaza::create([
            'convocatoria_id' => $convocatoria->id, 'facultad' => 'F', 'departamento' => 'D', 'asignatura' => 'A',
        ]);

        $postulante = User::factory()->create(['is_active' => true]);
        $postulante->assignRole('postulante');

        $evaluador = User::factory()->create(['is_active' => true]);
        $evaluador->assignRole('evaluador');

        return compact('tabla', 'etapaClaseMagistral', 'variable', 'convocatoria', 'plaza', 'postulante', 'admin', 'evaluador');
    }

    public function test_postular_crea_postulacion_etapa_pendiente_automaticamente(): void
    {
        ['convocatoria' => $convocatoria, 'plaza' => $plaza, 'postulante' => $postulante, 'etapaClaseMagistral' => $etapa] = $this->crearEscenario();

        $response = $this->actingAs($postulante, 'sanctum')
            ->postJson('/api/v1/postulaciones', [
                'convocatoria_id' => $convocatoria->id,
                'plaza_id'        => $plaza->id,
            ])
            ->assertStatus(201);

        $postulacionId = $response->json('id');

        $this->assertDatabaseHas('postulacion_etapa', [
            'postulacion_id' => $postulacionId,
            'etapa_id'       => $etapa->id,
            'estado'         => 'pendiente',
        ]);
    }

    public function test_evaluacion_calcula_cero_si_clase_magistral_no_ha_ocurrido_aun(): void
    {
        ['convocatoria' => $convocatoria, 'plaza' => $plaza, 'postulante' => $postulante, 'evaluador' => $evaluador] = $this->crearEscenario();

        $postulacion = Postulacion::create([
            'user_id' => $postulante->id, 'convocatoria_id' => $convocatoria->id,
            'plaza_id' => $plaza->id, 'estado' => Postulacion::ESTADO_EN_PROCESO,
        ]);
        // Sin postulacion_etapa creada (simulando: la brecha de tiempo real
        // antes de que exista siquiera el registro pendiente) — el motor no
        // debe romperse, solo aportar 0.

        $evaluacion = Evaluacion::create([
            'postulacion_id' => $postulacion->id, 'evaluador_id' => $evaluador->id,
            'estado' => Evaluacion::ESTADO_EN_PROCESO,
        ]);

        $puntaje = (new CalculadorService())->calcular($evaluacion);

        $this->assertEquals(0.0, $puntaje);
    }

    public function test_evaluacion_toma_el_puntaje_del_evento_una_vez_aprobado(): void
    {
        ['convocatoria' => $convocatoria, 'plaza' => $plaza, 'postulante' => $postulante, 'evaluador' => $evaluador, 'etapaClaseMagistral' => $etapa] = $this->crearEscenario();

        $postulacion = Postulacion::create([
            'user_id' => $postulante->id, 'convocatoria_id' => $convocatoria->id,
            'plaza_id' => $plaza->id, 'estado' => Postulacion::ESTADO_EN_PROCESO,
        ]);

        PostulacionEtapa::create([
            'postulacion_id' => $postulacion->id, 'etapa_id' => $etapa->id,
            'fecha_programada' => '2026-06-01', 'fecha_realizada' => '2026-06-01',
            'estado' => PostulacionEtapa::ESTADO_APROBADA,
            'puntaje_bruto_evento' => 18.0,
            'jurado_texto' => 'Dr. Pérez (decano, sin cuenta en el sistema), Prof. García',
            'registrado_por' => $evaluador->id,
        ]);

        $evaluacion = Evaluacion::create([
            'postulacion_id' => $postulacion->id, 'evaluador_id' => $evaluador->id,
            'estado' => Evaluacion::ESTADO_EN_PROCESO,
        ]);

        $puntaje = (new CalculadorService())->calcular($evaluacion);

        $this->assertEquals(18.0, $puntaje);
    }

    public function test_evento_no_aprobado_todavia_no_suma_aunque_tenga_puntaje_bruto(): void
    {
        ['convocatoria' => $convocatoria, 'plaza' => $plaza, 'postulante' => $postulante, 'evaluador' => $evaluador, 'etapaClaseMagistral' => $etapa] = $this->crearEscenario();

        $postulacion = Postulacion::create([
            'user_id' => $postulante->id, 'convocatoria_id' => $convocatoria->id,
            'plaza_id' => $plaza->id, 'estado' => Postulacion::ESTADO_EN_PROCESO,
        ]);

        PostulacionEtapa::create([
            'postulacion_id' => $postulacion->id, 'etapa_id' => $etapa->id,
            'estado' => PostulacionEtapa::ESTADO_PENDIENTE, // aún no revisado/aprobado
            'puntaje_bruto_evento' => 18.0,
        ]);

        $evaluacion = Evaluacion::create([
            'postulacion_id' => $postulacion->id, 'evaluador_id' => $evaluador->id,
            'estado' => Evaluacion::ESTADO_EN_PROCESO,
        ]);

        $puntaje = (new CalculadorService())->calcular($evaluacion);

        $this->assertEquals(0.0, $puntaje);
    }

    public function test_evaluador_asignado_a_la_etapa_puede_registrar_el_resultado(): void
    {
        ['convocatoria' => $convocatoria, 'plaza' => $plaza, 'postulante' => $postulante, 'evaluador' => $evaluador, 'etapaClaseMagistral' => $etapa] = $this->crearEscenario();

        $postulacion = Postulacion::create([
            'user_id' => $postulante->id, 'convocatoria_id' => $convocatoria->id,
            'plaza_id' => $plaza->id, 'estado' => Postulacion::ESTADO_EN_PROCESO,
        ]);
        $postulacionEtapa = PostulacionEtapa::create([
            'postulacion_id' => $postulacion->id, 'etapa_id' => $etapa->id,
        ]);

        AsignacionEvaluador::create([
            'convocatoria_id' => $convocatoria->id, 'postulacion_id' => $postulacion->id,
            'evaluador_id' => $evaluador->id, 'etapa_id' => $etapa->id,
        ]);

        $this->actingAs($evaluador, 'sanctum')
            ->patchJson("/api/v1/postulacion-etapas/{$postulacionEtapa->id}", [
                'estado' => 'aprobada',
                'fecha_realizada' => '2026-06-01',
                'puntaje_bruto_evento' => 17.5,
                'jurado_texto' => 'Prof. García',
            ])
            ->assertOk()
            ->assertJsonPath('estado', 'aprobada')
            ->assertJsonPath('puntaje_bruto_evento', '17.50');
    }

    public function test_evaluador_no_asignado_a_esa_etapa_no_puede_registrar(): void
    {
        ['convocatoria' => $convocatoria, 'plaza' => $plaza, 'postulante' => $postulante, 'evaluador' => $evaluador, 'etapaClaseMagistral' => $etapa] = $this->crearEscenario();

        $postulacion = Postulacion::create([
            'user_id' => $postulante->id, 'convocatoria_id' => $convocatoria->id,
            'plaza_id' => $plaza->id, 'estado' => Postulacion::ESTADO_EN_PROCESO,
        ]);
        $postulacionEtapa = PostulacionEtapa::create([
            'postulacion_id' => $postulacion->id, 'etapa_id' => $etapa->id,
        ]);

        // Sin ninguna asignación para este evaluador en esta postulación/etapa.
        $this->actingAs($evaluador, 'sanctum')
            ->patchJson("/api/v1/postulacion-etapas/{$postulacionEtapa->id}", [
                'estado' => 'aprobada',
                'puntaje_bruto_evento' => 17.5,
            ])
            ->assertStatus(403)
            ->assertJson(['code' => 'EVALUADOR_NO_ASIGNADO']);
    }
}
