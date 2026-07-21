<?php

namespace Tests\Feature\Resultados;

use App\Models\Convocatoria;
use App\Models\Evaluacion;
use App\Models\Plaza;
use App\Models\Postulacion;
use App\Models\ReglamentoVersion;
use App\Models\Resultado;
use App\Models\TablaEvaluacion;
use App\Models\User;
use App\Services\ResultadosService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Cubre requisitos-sistema.md §10 "Empate y plaza desierta": un empate en una
 * posición que define ganador/reserva no se resuelve automáticamente (no hay
 * sorteo real) — queda `empate_pendiente` hasta que la comisión registra el
 * orden decidido manualmente, con trazabilidad de quién y cuándo.
 */
class EmpateResultadosTest extends TestCase
{
    use RefreshDatabase;

    private ResultadosService $service;
    private Convocatoria $convocatoria;
    private Plaza $plaza;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->service = new ResultadosService();

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

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole('admin');

        $this->convocatoria = Convocatoria::create([
            'codigo'                => 'CONV-EMPATE-001',
            'nombre'                => 'Convocatoria Test',
            'reglamento_version_id' => $reglamento->id,
            'tabla_evaluacion_id'   => $tabla->id,
            'tipo_proceso'          => 'contratacion',
            'fecha_inicio'          => '2026-01-01',
            'fecha_fin'             => '2026-12-31',
            'estado'                => Convocatoria::ESTADO_EN_PROCESO,
            'creado_por'            => $this->admin->id,
        ]);

        $this->plaza = Plaza::create([
            'convocatoria_id' => $this->convocatoria->id,
            'facultad'        => 'Facultad Test',
            'departamento'    => 'Depto Test',
            'asignatura'      => 'Asignatura Test',
        ]);
    }

    /** @return Postulacion[] */
    private function crearPostulacionesConPuntajes(array $puntajes): array
    {
        $postulaciones = [];

        foreach ($puntajes as $puntaje) {
            $postulante = User::factory()->create(['is_active' => true]);
            $postulante->assignRole('postulante');

            $postulacion = Postulacion::create([
                'user_id'         => $postulante->id,
                'convocatoria_id' => $this->convocatoria->id,
                'plaza_id'        => $this->plaza->id,
                'estado'          => Postulacion::ESTADO_APROBADA,
            ]);

            $evaluador = User::factory()->create(['is_active' => true]);
            $evaluador->assignRole('evaluador');

            Evaluacion::create([
                'postulacion_id' => $postulacion->id,
                'evaluador_id'   => $evaluador->id,
                'estado'         => Evaluacion::ESTADO_CERRADA,
                'puntaje_total'  => $puntaje,
            ]);

            $postulaciones[] = $postulacion;
        }

        return $postulaciones;
    }

    public function test_sin_empates_asigna_ranking_automaticamente(): void
    {
        [$p1, $p2, $p3] = $this->crearPostulacionesConPuntajes([90, 80, 70]);

        $resultados = $this->service->generarRankingPlaza($this->convocatoria, $this->plaza);

        $this->assertEquals(Resultado::ESTADO_GANADOR, $resultados->firstWhere('postulacion_id', $p1->id)->estado);
        $this->assertEquals(Resultado::ESTADO_RESERVA, $resultados->firstWhere('postulacion_id', $p2->id)->estado);
        $this->assertEquals(Resultado::ESTADO_RESERVA, $resultados->firstWhere('postulacion_id', $p3->id)->estado);
        $this->assertFalse($resultados->firstWhere('postulacion_id', $p1->id)->empatada);
    }

    public function test_empate_en_posicion_de_ganador_queda_pendiente_de_decision(): void
    {
        [$p1, $p2, $p3] = $this->crearPostulacionesConPuntajes([90, 90, 70]);

        $resultados = $this->service->generarRankingPlaza($this->convocatoria, $this->plaza);

        $r1 = $resultados->firstWhere('postulacion_id', $p1->id);
        $r2 = $resultados->firstWhere('postulacion_id', $p2->id);
        $r3 = $resultados->firstWhere('postulacion_id', $p3->id);

        $this->assertEquals(Resultado::ESTADO_EMPATE_PENDIENTE, $r1->estado);
        $this->assertEquals(Resultado::ESTADO_EMPATE_PENDIENTE, $r2->estado);
        $this->assertTrue($r1->empatada);
        $this->assertEquals(1, $r1->posicion);
        $this->assertEquals(1, $r2->posicion);

        // El tercero, no empatado y fuera de discusión, sigue el flujo normal
        $this->assertEquals(Resultado::ESTADO_RESERVA, $r3->estado);
        $this->assertEquals(3, $r3->posicion);

        // La plaza no puede marcarse como cubierta mientras el empate no se resuelva
        $this->assertEquals('en_proceso', $this->plaza->fresh()->estado);
    }

    public function test_empate_fuera_del_rango_contactable_no_requiere_decision(): void
    {
        // MAX_RESERVAS=3 → posiciones 1-4 son contactables; un empate en las
        // posiciones 5-6 no afecta a nadie contactable, se asigna normal.
        [$p1, $p2, $p3, $p4, $p5, $p6] = $this->crearPostulacionesConPuntajes([100, 90, 80, 70, 50, 50]);

        $resultados = $this->service->generarRankingPlaza($this->convocatoria, $this->plaza);

        $r5 = $resultados->firstWhere('postulacion_id', $p5->id);
        $r6 = $resultados->firstWhere('postulacion_id', $p6->id);

        $this->assertEquals(Resultado::ESTADO_NO_GANADOR, $r5->estado);
        $this->assertEquals(Resultado::ESTADO_NO_GANADOR, $r6->estado);
    }

    public function test_comision_resuelve_empate_manualmente_con_trazabilidad(): void
    {
        [$p1, $p2, $p3] = $this->crearPostulacionesConPuntajes([90, 90, 70]);
        $this->service->generarRankingPlaza($this->convocatoria, $this->plaza);

        $resultados = $this->service->resolverEmpate(
            $this->convocatoria,
            $this->plaza,
            1,
            [$p2->id, $p1->id], // la comisión decide: p2 gana, p1 queda de reserva
            $this->admin->id
        );

        $r2 = $resultados->firstWhere('postulacion_id', $p2->id);
        $r1 = $resultados->firstWhere('postulacion_id', $p1->id);

        $this->assertEquals(1, $r2->posicion);
        $this->assertEquals(Resultado::ESTADO_GANADOR, $r2->estado);
        $this->assertTrue($r2->orden_manual);
        $this->assertEquals($this->admin->id, $r2->decidido_por);
        $this->assertNotNull($r2->decidido_en);

        $this->assertEquals(2, $r1->posicion);
        $this->assertEquals(Resultado::ESTADO_RESERVA, $r1->estado);

        $this->assertEquals('cubierta', $this->plaza->fresh()->estado);
    }

    public function test_resolver_empate_con_postulaciones_que_no_coinciden_falla(): void
    {
        [$p1, $p2, $p3] = $this->crearPostulacionesConPuntajes([90, 90, 70]);
        $this->service->generarRankingPlaza($this->convocatoria, $this->plaza);

        $this->expectException(\InvalidArgumentException::class);

        $this->service->resolverEmpate(
            $this->convocatoria,
            $this->plaza,
            1,
            [$p1->id, $p3->id], // p3 no forma parte de este grupo empatado
            $this->admin->id
        );
    }

    public function test_no_se_puede_publicar_con_empates_pendientes(): void
    {
        $this->crearPostulacionesConPuntajes([90, 90, 70]);
        $this->service->generarRankingPlaza($this->convocatoria, $this->plaza);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/convocatorias/{$this->convocatoria->id}/resultados/publicar")
            ->assertStatus(422)
            ->assertJson(['code' => 'EMPATES_PENDIENTES']);
    }

    public function test_se_puede_publicar_despues_de_resolver_el_empate(): void
    {
        [$p1, $p2] = $this->crearPostulacionesConPuntajes([90, 90]);
        $this->service->generarRankingPlaza($this->convocatoria, $this->plaza);
        $this->service->resolverEmpate($this->convocatoria, $this->plaza, 1, [$p1->id, $p2->id], $this->admin->id);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/convocatorias/{$this->convocatoria->id}/resultados/publicar")
            ->assertOk();
    }

    public function test_endpoint_de_desempate_requiere_permiso_de_resultados(): void
    {
        [$p1, $p2] = $this->crearPostulacionesConPuntajes([90, 90]);
        $this->service->generarRankingPlaza($this->convocatoria, $this->plaza);

        $postulante = User::factory()->create(['is_active' => true]);
        $postulante->assignRole('postulante');

        $this->actingAs($postulante, 'sanctum')
            ->postJson("/api/v1/convocatorias/{$this->convocatoria->id}/plazas/{$this->plaza->id}/resultados/desempatar", [
                'posicion_inicio' => 1,
                'orden'           => [$p1->id, $p2->id],
            ])
            ->assertStatus(403);
    }
}
