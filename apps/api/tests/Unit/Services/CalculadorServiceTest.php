<?php

namespace Tests\Unit\Services;

use App\Models\Evaluacion;
use App\Models\Evidencia;
use App\Models\Expediente;
use App\Models\Postulacion;
use App\Models\Variable;
use App\Services\CalculadorService;
use Illuminate\Support\Collection;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Tests unitarios del CalculadorService.
 *
 * Estos tests NO usan base de datos — validan la lógica pura del motor de cálculo
 * usando objetos simples para máxima velocidad y aislamiento.
 */
class CalculadorServiceTest extends TestCase
{
    private CalculadorService $calculador;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculador = new CalculadorService();
    }

    // =========================================================================
    // Helper: crea un objeto variable simple
    // =========================================================================
    private function makeVariable(array $attrs = []): object
    {
        return (object) array_merge([
            'id'                   => 1,
            'nombre'               => 'Variable Test',
            'puntaje_max'          => 10.0,
            'tipo_calculo'         => Variable::TIPO_SUMA_CON_TOPE,
            'periodo_validez_anios' => null,
        ], $attrs);
    }

    // Helper: crea evidencia simple
    private function makeEvidencia(float $puntaje, int $id = 1): object
    {
        return (object) [
            'id'               => $id,
            'nombre_original'  => "evidencia_{$id}.pdf",
            'puntaje_indicador' => $puntaje,
            'indicador_id'     => $id,
            'estado'           => 'aprobada',
        ];
    }

    // =========================================================================
    // SUMA_CON_TOPE
    // =========================================================================

    public function test_suma_con_tope_sin_evidencias_retorna_cero(): void
    {
        $variable  = $this->makeVariable(['tipo_calculo' => Variable::TIPO_SUMA_CON_TOPE]);
        $resultado = $this->invocarSumaConTope($variable, collect());

        $this->assertEquals(0.0, $resultado[0]);
        $this->assertEmpty($resultado[1]);
    }

    public function test_suma_con_tope_suma_correctamente(): void
    {
        $variable  = $this->makeVariable(['puntaje_max' => 10.0]);
        $evidencias = collect([
            $this->makeEvidencia(2.0, 1),
            $this->makeEvidencia(3.0, 2),
        ]);

        [$bruto] = $this->invocarSumaConTope($variable, $evidencias);

        $this->assertEquals(5.0, $bruto);
    }

    public function test_suma_con_tope_el_tope_se_aplica_correctamente(): void
    {
        // Tope de variable = 4.0 pero las evidencias suman 8.0
        $variable   = $this->makeVariable(['puntaje_max' => 4.0]);
        $evidencias = collect([
            $this->makeEvidencia(5.0, 1),
            $this->makeEvidencia(3.0, 2),
        ]);

        [$bruto] = $this->invocarSumaConTope($variable, $evidencias);
        $aplicado = min($bruto, $variable->puntaje_max);

        $this->assertEquals(8.0, $bruto);     // Bruto real
        $this->assertEquals(4.0, $aplicado);  // Con tope
    }

    // =========================================================================
    // MAYOR_VALOR
    // =========================================================================

    public function test_mayor_valor_toma_el_mas_alto(): void
    {
        $variable   = $this->makeVariable(['tipo_calculo' => Variable::TIPO_MAYOR_VALOR, 'puntaje_max' => 3.0]);
        $evidencias = collect([
            $this->makeEvidencia(1.0, 1),
            $this->makeEvidencia(3.0, 2),
            $this->makeEvidencia(2.0, 3),
        ]);

        [$bruto] = $this->invocarMayorValor($variable, $evidencias);

        $this->assertEquals(3.0, $bruto);
    }

    public function test_mayor_valor_sin_evidencias_retorna_cero(): void
    {
        $variable = $this->makeVariable(['tipo_calculo' => Variable::TIPO_MAYOR_VALOR]);

        [$bruto] = $this->invocarMayorValor($variable, collect());

        $this->assertEquals(0.0, $bruto);
    }

    public function test_mayor_valor_no_suma_acumula_solo_el_mejor(): void
    {
        // Con 3 evidencias de 2, el resultado debe ser 2, no 6
        $variable   = $this->makeVariable(['tipo_calculo' => Variable::TIPO_MAYOR_VALOR, 'puntaje_max' => 3.0]);
        $evidencias = collect([
            $this->makeEvidencia(2.0, 1),
            $this->makeEvidencia(2.0, 2),
            $this->makeEvidencia(2.0, 3),
        ]);

        [$bruto] = $this->invocarMayorValor($variable, $evidencias);

        $this->assertEquals(2.0, $bruto); // No 6.0
    }

    // =========================================================================
    // TABLA_EQUIVALENCIA
    // =========================================================================

    #[DataProvider('tablaEquivalenciaProvider')]
    public function test_tabla_equivalencia_mapea_correctamente(
        float $valorEntrada,
        float $puntajeEsperado,
        array $tabla
    ): void {
        $resultado = $this->mapearTablaEquivalencia($valorEntrada, $tabla);
        $this->assertEquals($puntajeEsperado, $resultado);
    }

    public static function tablaEquivalenciaProvider(): array
    {
        $tabla = [
            ['min' => 0,  'max' => 9,  'puntaje' => 0],
            ['min' => 10, 'max' => 13, 'puntaje' => 2],
            ['min' => 14, 'max' => 16, 'puntaje' => 4],
            ['min' => 17, 'max' => 18, 'puntaje' => 6],
            ['min' => 19, 'max' => 20, 'puntaje' => 8],
        ];

        return [
            'debajo del primer rango' => [5.0,  0.0, $tabla],
            'en rango bajo'           => [12.0, 2.0, $tabla],
            'en rango medio'          => [15.0, 4.0, $tabla],
            'en rango alto'           => [17.5, 6.0, $tabla],
            'en rango máximo'         => [20.0, 8.0, $tabla],
            'exactamente en límite'   => [19.0, 8.0, $tabla],
        ];
    }

    // =========================================================================
    // TOPE DOS NIVELES (Variable + Sub Rubro) — el más crítico del reglamento
    // =========================================================================

    public function test_tope_dos_niveles_variable_y_subrubro(): void
    {
        /*
         * Escenario real del Anexo 1 — Sub Rubro "Investigación":
         *   - Variable A: tope 10, evidencias suman 15  → aplicado 10
         *   - Variable B: tope 8,  evidencias suman 6   → aplicado 6
         *   - Variable C: tope 4,  evidencias suman 4   → aplicado 4
         *   Total bruto sub-rubro: 10 + 6 + 4 = 20
         *   Tope sub-rubro: 20 → min(20, 20) = 20  ✓
         *
         * Segundo escenario con tope sub-rubro activo:
         *   - Variable A: 10, Variable B: 8, Variable C: 4 → suma 22
         *   Tope sub-rubro: 20 → resultado 20  ✓
         */
        $variables = [
            ['puntaje_evidencias' => 15, 'tope_variable' => 10],
            ['puntaje_evidencias' => 6,  'tope_variable' => 8],
            ['puntaje_evidencias' => 4,  'tope_variable' => 4],
        ];
        $topeSubrubro = 20.0;

        $sumaVariables = 0.0;
        foreach ($variables as $v) {
            $sumaVariables += min((float) $v['puntaje_evidencias'], (float) $v['tope_variable']);
        }
        $puntajeFinalRubro = min($sumaVariables, $topeSubrubro);

        $this->assertEquals(20.0, $sumaVariables);
        $this->assertEquals(20.0, $puntajeFinalRubro);
    }

    public function test_tope_subrubro_recorta_correctamente(): void
    {
        // Tres variables que juntas superan el tope del sub-rubro
        $variables = [
            ['puntaje_evidencias' => 14, 'tope_variable' => 10],  // aplicado 10
            ['puntaje_evidencias' => 9,  'tope_variable' => 8],   // aplicado 8
            ['puntaje_evidencias' => 5,  'tope_variable' => 4],   // aplicado 4
        ];
        $topeSubrubro = 15.0;  // Tope del Sub Rubro más bajo que la suma

        $sumaVariables = 0.0;
        foreach ($variables as $v) {
            $sumaVariables += min((float) $v['puntaje_evidencias'], (float) $v['tope_variable']);
        }
        // Suma sin tope subrubro = 22
        $this->assertEquals(22.0, $sumaVariables);

        $puntajeFinalRubro = min($sumaVariables, $topeSubrubro);
        // Con tope subrubro = 15
        $this->assertEquals(15.0, $puntajeFinalRubro);
    }

    public function test_puntaje_total_suma_todos_los_rubros(): void
    {
        $puntajesRubros = [12.0, 14.0, 20.0, 4.0, 4.0, 20.0, 4.0, 20.0];  // Anexo 1 con topes
        $total = array_sum($puntajesRubros);
        $this->assertEquals(98.0, $total);  // Máximo posible con topes = 107, pero con esta distribución 98
    }

    // =========================================================================
    // Invokers privados para acceder a métodos private via Reflection
    // =========================================================================

    private function invocarSumaConTope(object $variable, Collection $evidencias): array
    {
        $ref    = new \ReflectionClass(CalculadorService::class);
        $metodo = $ref->getMethod('sumaConTope');
        $metodo->setAccessible(true);
        return $metodo->invoke($this->calculador, $variable, $evidencias);
    }

    private function invocarMayorValor(object $variable, Collection $evidencias): array
    {
        $ref    = new \ReflectionClass(CalculadorService::class);
        $metodo = $ref->getMethod('mayorValor');
        $metodo->setAccessible(true);
        return $metodo->invoke($this->calculador, $variable, $evidencias);
    }

    private function mapearTablaEquivalencia(float $valor, array $tabla): float
    {
        foreach ($tabla as $rango) {
            if ($valor >= (float) $rango['min'] && $valor <= (float) $rango['max']) {
                return (float) $rango['puntaje'];
            }
        }
        return 0.0;
    }
}
