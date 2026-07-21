<?php

namespace Database\Seeders;

use App\Models\Indicador;
use App\Models\ReglamentoVersion;
use App\Models\Rubro;
use App\Models\TablaEvaluacion;
use App\Models\Variable;
use Illuminate\Database\Seeder;

class AnexosSeeder extends Seeder
{
    private ReglamentoVersion $version;

    public function run(): void
    {
        $this->version = ReglamentoVersion::firstOrCreate(
            ['numero_version' => 'V10'],
            [
                'nombre'           => 'TUO Reglamento del Personal Docente Universitario',
                'fecha_vigencia'   => '2025-06-01',
                'documento_fuente' => 'Resolución 9245-CU-2025 / TUO-GTH-001',
                'activo'           => true,
            ]
        );

        $this->seedAnexo1();
        $this->seedAnexo2();
        $this->seedAnexo3();
        $this->seedAnexo4();
        $this->seedAnexo6();
        $this->seedAnexo7();

        $this->command->info('✅ Seeders de Anexos 1, 2, 3, 4.1, 6 y 7 completados.');
    }

    // -------------------------------------------------------------------------
    // ANEXO 1 — Contratación de Docentes (total 107 puntos ficha; topes aplican)
    // -------------------------------------------------------------------------
    private function seedAnexo1(): void
    {
        $tabla = TablaEvaluacion::firstOrCreate(
            ['reglamento_version_id' => $this->version->id, 'codigo_anexo' => 'ANEXO_1'],
            [
                'nombre'           => 'Contratación de Docentes',
                'tipo_proceso'     => 'contratacion',
                'modalidad'        => null,
                // 107.0 es la suma de los puntaje_max de las 5 variables de
                // "Investigación y Producción" sin aplicar su tope de sub-rubro
                // (20.0). Con el tope de dos niveles (ver requisitos-sistema.md
                // §7) el total real y verificado de la Ficha es 100.0 — igual
                // a la suma de los puntaje_max_subrubro declarados abajo.
                'puntaje_total_max' => 100.00,
            ]
        );

        $rubros = [
            ['nombre' => 'Formación Académica y Profesional Universitaria', 'orden' => 1, 'max' => 12.00, 'variables' => [
                ['nombre' => 'Grados Académicos',     'orden' => 1, 'max' => 8.0, 'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Diploma / registro SUNEDU'],
                ['nombre' => 'Títulos Profesionales', 'orden' => 2, 'max' => 4.0, 'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Diploma / registro SUNEDU'],
            ]],
            ['nombre' => 'Superación Profesional', 'orden' => 2, 'max' => 14.00, 'variables' => [
                ['nombre' => 'Ponencia, Participación y/o Asistencia', 'orden' => 1, 'max' => 4.0,  'tipo' => 'SUMA_CON_TOPE', 'validez' => 7,    'fuente' => 'Constancia/certificado'],
                ['nombre' => 'Eventos de Posgrado',                   'orden' => 2, 'max' => 4.0,  'tipo' => 'SUMA_CON_TOPE', 'validez' => 7,    'fuente' => 'Constancia/certificado'],
                ['nombre' => 'Idioma Extranjero o Nativo',            'orden' => 3, 'max' => 3.0,  'tipo' => 'MAYOR_VALOR',   'validez' => null, 'fuente' => 'Certificado de competencia'],
                ['nombre' => 'Informática',                           'orden' => 4, 'max' => 1.0,  'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Certificado'],
                ['nombre' => 'Diplomado y Especialización',           'orden' => 5, 'max' => 2.0,  'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Diploma'],
            ]],
            ['nombre' => 'Récord Profesional y Docente', 'orden' => 3, 'max' => 22.00, 'variables' => [
                ['nombre' => 'Experiencia Laboral Profesional',                    'orden' => 1, 'max' => 8.0, 'tipo' => 'MAYOR_VALOR',   'validez' => null, 'fuente' => 'Certificado de trabajo o RUC'],
                ['nombre' => 'Campo Ocupacional',                                  'orden' => 2, 'max' => 2.0, 'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Documento probatorio'],
                ['nombre' => 'Experiencia Laboral en el Sistema Universitario',   'orden' => 3, 'max' => 8.0, 'tipo' => 'MAYOR_VALOR',   'validez' => null, 'fuente' => 'Constancia de trabajo'],
            ]],
            ['nombre' => 'Distinciones', 'orden' => 4, 'max' => 4.00, 'variables' => [
                ['nombre' => 'Reconocimiento y Felicitación', 'orden' => 1, 'max' => 4.0, 'tipo' => 'SUMA_CON_TOPE', 'validez' => 7, 'fuente' => 'Diploma/Resolución'],
            ]],
            ['nombre' => 'Responsabilidad Social', 'orden' => 5, 'max' => 4.00, 'variables' => [
                ['nombre' => 'Actividades de Proyección Social',       'orden' => 1, 'max' => 2.0, 'tipo' => 'SUMA_CON_TOPE', 'validez' => 7, 'fuente' => 'Certificación/Constancia'],
                ['nombre' => 'Actividades de Extensión Universitaria', 'orden' => 2, 'max' => 2.0, 'tipo' => 'SUMA_CON_TOPE', 'validez' => 7, 'fuente' => 'Certificación/Constancia'],
            ]],
            // Tope sub-rubro: 20.0; suma de variables: 27.0 — diseño de rúbrica intencional
            ['nombre' => 'Investigación y Producción', 'orden' => 6, 'max' => 20.00, 'variables' => [
                ['nombre' => 'Publicaciones Científicas', 'orden' => 1, 'max' => 10.0, 'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Publicación correspondiente'],
                ['nombre' => 'Proyectos de Investigación', 'orden' => 2, 'max' => 8.0,  'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Resolución de aprobación'],
                ['nombre' => 'Patente',                   'orden' => 3, 'max' => 4.0,  'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Resolución de aprobación'],
                ['nombre' => 'Renacyt',                   'orden' => 4, 'max' => 1.0,  'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Constancia'],
                ['nombre' => 'Producción Intelectual',    'orden' => 5, 'max' => 4.0,  'tipo' => 'SUMA_CON_TOPE', 'validez' => 7,    'fuente' => 'Registro Biblioteca Nacional / ISBN'],
            ]],
            ['nombre' => 'Elaboración del Sílabo', 'orden' => 7, 'max' => 4.00, 'variables' => [
                ['nombre' => 'Sílabo', 'orden' => 1, 'max' => 4.0, 'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Documento presentado'],
            ]],
            ['nombre' => 'Demostración Magistral', 'orden' => 8, 'max' => 20.00, 'variables' => [
                ['nombre' => 'Desempeño Docente y Desarrollo del Tema', 'orden' => 1, 'max' => 20.0, 'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Evaluación en vivo (20-40 min)'],
            ]],
        ];

        $this->insertRubros($tabla, $rubros);
        $this->command->info('  → Anexo 1 insertado');
    }

    // -------------------------------------------------------------------------
    // ANEXO 2 — Contratación de Jefes de Práctica (total 100 puntos)
    // -------------------------------------------------------------------------
    private function seedAnexo2(): void
    {
        $tabla = TablaEvaluacion::firstOrCreate(
            ['reglamento_version_id' => $this->version->id, 'codigo_anexo' => 'ANEXO_2'],
            [
                'nombre'           => 'Contratación de Jefes de Práctica',
                'tipo_proceso'     => 'contratacion_jefe_practica',
                'modalidad'        => null,
                'puntaje_total_max' => 100.00,
            ]
        );

        $rubros = [
            ['nombre' => 'Formación Académica y Profesional Universitaria', 'orden' => 1, 'max' => 12.00, 'variables' => [
                ['nombre' => 'Grados Académicos',     'orden' => 1, 'max' => 8.0, 'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Diploma / registro SUNEDU'],
                ['nombre' => 'Títulos Profesionales', 'orden' => 2, 'max' => 4.0, 'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Diploma / registro SUNEDU'],
            ]],
            ['nombre' => 'Superación Profesional', 'orden' => 2, 'max' => 14.00, 'variables' => [
                ['nombre' => 'Ponencia, Participación y/o Asistencia', 'orden' => 1, 'max' => 4.0, 'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Constancia/certificado'],
                ['nombre' => 'Eventos de Posgrado',                   'orden' => 2, 'max' => 4.0, 'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Constancia/certificado'],
                ['nombre' => 'Idioma Extranjero o Nativo',            'orden' => 3, 'max' => 3.0, 'tipo' => 'MAYOR_VALOR',   'validez' => null, 'fuente' => 'Certificado de competencia'],
                ['nombre' => 'Informática',                           'orden' => 4, 'max' => 1.0, 'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Certificado'],
                ['nombre' => 'Diplomado y Especialización',           'orden' => 5, 'max' => 2.0, 'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Diploma'],
            ]],
            ['nombre' => 'Récord Profesional y Docente', 'orden' => 3, 'max' => 22.00, 'variables' => [
                ['nombre' => 'Experiencia Laboral Profesional',                  'orden' => 1, 'max' => 8.0, 'tipo' => 'MAYOR_VALOR',   'validez' => null, 'fuente' => 'Certificado de trabajo o RUC'],
                ['nombre' => 'Campo Ocupacional',                                'orden' => 2, 'max' => 2.0, 'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Documento probatorio'],
                ['nombre' => 'Experiencia Laboral en el Sistema Universitario', 'orden' => 3, 'max' => 8.0, 'tipo' => 'MAYOR_VALOR',   'validez' => null, 'fuente' => 'Constancia de trabajo'],
            ]],
            ['nombre' => 'Distinciones', 'orden' => 4, 'max' => 4.00, 'variables' => [
                ['nombre' => 'Reconocimiento y Felicitación', 'orden' => 1, 'max' => 4.0, 'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Diploma/Resolución'],
            ]],
            ['nombre' => 'Responsabilidad Social', 'orden' => 5, 'max' => 4.00, 'variables' => [
                ['nombre' => 'Actividades de Proyección Social',       'orden' => 1, 'max' => 2.0, 'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Certificación/Constancia'],
                ['nombre' => 'Actividades de Extensión Universitaria', 'orden' => 2, 'max' => 2.0, 'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Certificación/Constancia'],
            ]],
            ['nombre' => 'Investigación y Producción', 'orden' => 6, 'max' => 20.00, 'variables' => [
                ['nombre' => 'Publicaciones Científicas', 'orden' => 1, 'max' => 10.0, 'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Publicación correspondiente'],
                ['nombre' => 'Proyectos de Investigación', 'orden' => 2, 'max' => 8.0,  'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Constancia'],
                ['nombre' => 'Patente',                   'orden' => 3, 'max' => 4.0,  'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => '—'],
                ['nombre' => 'Renacyt',                   'orden' => 4, 'max' => 1.0,  'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Constancia'],
                ['nombre' => 'Producción Intelectual',    'orden' => 5, 'max' => 4.0,  'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Registro Biblioteca Nacional / ISBN'],
            ]],
            ['nombre' => 'Elaboración de Guía de Prácticas', 'orden' => 7, 'max' => 4.00, 'variables' => [
                ['nombre' => 'Guía de Prácticas', 'orden' => 1, 'max' => 4.0, 'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Documento presentado'],
            ]],
            ['nombre' => 'Sesión de Prácticas', 'orden' => 8, 'max' => 20.00, 'variables' => [
                ['nombre' => 'Desempeño en Jefatura de Prácticas y Desarrollo del Tema', 'orden' => 1, 'max' => 20.0, 'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Evaluación en vivo (30-45 min)'],
            ]],
        ];

        $this->insertRubros($tabla, $rubros);
        $this->command->info('  → Anexo 2 insertado');
    }

    // -------------------------------------------------------------------------
    // ANEXO 3 — Ingreso a Docencia Ordinaria Presencial (total 100 puntos)
    // -------------------------------------------------------------------------
    private function seedAnexo3(): void
    {
        $tabla = TablaEvaluacion::firstOrCreate(
            ['reglamento_version_id' => $this->version->id, 'codigo_anexo' => 'ANEXO_3'],
            [
                'nombre'           => 'Ingreso a Docencia Ordinaria (Presencial) / Concurso de Oposición',
                'tipo_proceso'     => 'ingreso_ordinaria',
                'modalidad'        => 'presencial',
                'puntaje_total_max' => 100.00,
            ]
        );

        $rubros = [
            ['nombre' => 'Formación Académica y Profesional Universitaria', 'orden' => 1, 'max' => 10.00, 'variables' => [
                ['nombre' => 'Grados Académicos',     'orden' => 1, 'max' => 6.0, 'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Diploma / registro SUNEDU'],
                ['nombre' => 'Títulos Profesionales', 'orden' => 2, 'max' => 4.0, 'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Diploma / registro SUNEDU'],
            ]],
            // Tope sub-rubro: 18.0; suma de variables: 20.0 — tope de dos niveles intencional
            ['nombre' => 'Superación Profesional', 'orden' => 2, 'max' => 18.00, 'variables' => [
                ['nombre' => 'Ponencia, Participación y/o Asistencia', 'orden' => 1, 'max' => 12.0, 'tipo' => 'SUMA_CON_TOPE', 'validez' => 10, 'fuente' => 'Constancia'],
                ['nombre' => 'Diplomado y Especialización',            'orden' => 2, 'max' => 2.0,  'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => '—'],
                ['nombre' => 'Eventos de Posgrado',                    'orden' => 3, 'max' => 6.0,  'tipo' => 'SUMA_CON_TOPE', 'validez' => 10, 'fuente' => 'Constancia'],
            ]],
            ['nombre' => 'Récord Docente', 'orden' => 3, 'max' => 5.00, 'variables' => [
                ['nombre' => 'Experiencia Docente / Jefatura de Prácticas', 'orden' => 1, 'max' => 5.0, 'tipo' => 'MAYOR_VALOR', 'validez' => null, 'fuente' => 'DATO_INSTITUCIONAL — Escalafón'],
            ]],
            ['nombre' => 'Distinciones', 'orden' => 4, 'max' => 4.00, 'variables' => [
                ['nombre' => 'Reconocimiento y Felicitación', 'orden' => 1, 'max' => 4.0, 'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Diploma/Resolución'],
            ]],
            ['nombre' => 'Responsabilidad Social', 'orden' => 5, 'max' => 2.00, 'variables' => [
                ['nombre' => 'Proyección Social o Extensión Universitaria', 'orden' => 1, 'max' => 2.0, 'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Certificación/Constancia'],
            ]],
            ['nombre' => 'Investigación y Producción', 'orden' => 6, 'max' => 25.00, 'variables' => [
                ['nombre' => 'Investigación Científica', 'orden' => 1, 'max' => 13.0, 'tipo' => 'SUMA_CON_TOPE',       'validez' => null, 'fuente' => 'Registro de aprobación / publicación'],
                ['nombre' => 'Renacyt',                  'orden' => 2, 'max' => 8.0,  'tipo' => 'TABLA_EQUIVALENCIA',  'validez' => null, 'fuente' => 'Constancia'],
                ['nombre' => 'Producción Intelectual',   'orden' => 3, 'max' => 4.0,  'tipo' => 'SUMA_CON_TOPE',       'validez' => null, 'fuente' => 'Registro Biblioteca Nacional / ISBN'],
            ]],
            ['nombre' => 'Producción para el Desarrollo Universitario', 'orden' => 7, 'max' => 7.00, 'variables' => [
                ['nombre' => 'Proyectos Curriculares Normativos y de Producción', 'orden' => 1, 'max' => 3.0, 'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Resolución/documento de aprobación'],
                ['nombre' => 'Comisiones',                                        'orden' => 2, 'max' => 4.0, 'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Resolución/Informe'],
            ]],
            ['nombre' => 'Práctica Docente', 'orden' => 8, 'max' => 10.00, 'variables' => [
                ['nombre' => 'Dictado de Clases y Responsabilidad Docente', 'orden' => 1, 'max' => 8.0, 'tipo' => 'TABLA_EQUIVALENCIA', 'validez' => null, 'fuente' => 'DATO_INSTITUCIONAL — Centro de Desarrollo Académico'],
                ['nombre' => 'Orientación y Asesoría a los Estudiantes',    'orden' => 2, 'max' => 2.0, 'tipo' => 'SUMA_CON_TOPE',      'validez' => null, 'fuente' => 'DATO_INSTITUCIONAL — Escuela Profesional'],
            ]],
            ['nombre' => 'Elaboración y Fundamentación del Sílabo', 'orden' => 9, 'max' => 5.00, 'variables' => [
                ['nombre' => 'Documento Sílabo presentado', 'orden' => 1, 'max' => 2.0, 'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Documento presentado'],
                ['nombre' => 'Fundamentación Oral del Sílabo', 'orden' => 2, 'max' => 3.0, 'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Evaluación en vivo'],
            ]],
            ['nombre' => 'Clase Magistral / Concurso de Oposición', 'orden' => 10, 'max' => 14.00, 'variables' => [
                ['nombre' => 'Comportamiento Docente',  'orden' => 1, 'max' => 7.0, 'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Evaluación en vivo'],
                ['nombre' => 'Desarrollo del Contenido', 'orden' => 2, 'max' => 7.0, 'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Evaluación en vivo (incluye desarrollo en inglés)'],
            ]],
        ];

        $this->insertRubros($tabla, $rubros);
        $this->command->info('  → Anexo 3 insertado');
    }

    // -------------------------------------------------------------------------
    // ANEXO 4.1 — Promoción/Ascenso de Categoría Presencial (total 100 puntos)
    // -------------------------------------------------------------------------
    private function seedAnexo4(): void
    {
        $tabla = TablaEvaluacion::firstOrCreate(
            ['reglamento_version_id' => $this->version->id, 'codigo_anexo' => 'ANEXO_4'],
            [
                'nombre'           => 'Promoción/Ascenso de Categoría (Presencial)',
                'tipo_proceso'     => 'ascenso',
                'modalidad'        => 'presencial',
                'puntaje_total_max' => 100.00,
            ]
        );

        $rubros = [
            ['nombre' => 'Formación Académica y Profesional Universitaria', 'orden' => 1, 'max' => 12.00, 'variables' => [
                ['nombre' => 'Grados Académicos',     'orden' => 1, 'max' => 8.0, 'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Diploma / registro SUNEDU'],
                ['nombre' => 'Títulos Profesionales', 'orden' => 2, 'max' => 4.0, 'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Diploma / registro SUNEDU'],
            ]],
            ['nombre' => 'Superación Docente durante la permanencia en la Categoría', 'orden' => 2, 'max' => 22.00, 'variables' => [
                ['nombre' => 'Ponencia, Participación y/o Asistencia', 'orden' => 1, 'max' => 10.0, 'tipo' => 'SUMA_CON_TOPE', 'validez' => 10, 'fuente' => 'Constancia'],
                ['nombre' => 'Diplomado y Especialización',            'orden' => 2, 'max' => 2.0,  'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => '—'],
                ['nombre' => 'Eventos de Posgrado',                    'orden' => 3, 'max' => 6.0,  'tipo' => 'SUMA_CON_TOPE', 'validez' => 10, 'fuente' => 'Constancia'],
                ['nombre' => 'Idioma Extranjero o Nativo',             'orden' => 4, 'max' => 4.0,  'tipo' => 'MAYOR_VALOR',   'validez' => null, 'fuente' => 'Certificado'],
            ]],
            ['nombre' => 'Récord Docente', 'orden' => 3, 'max' => 7.00, 'variables' => [
                ['nombre' => 'Experiencia Docente en la UCSM',   'orden' => 1, 'max' => 5.0, 'tipo' => 'MAYOR_VALOR',   'validez' => null, 'fuente' => 'DATO_INSTITUCIONAL — RRHH Escalafón'],
                ['nombre' => 'Mayor antigüedad en la Categoría', 'orden' => 2, 'max' => 2.0, 'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'DATO_INSTITUCIONAL — Escalafón'],
            ]],
            ['nombre' => 'Distinciones y Participaciones', 'orden' => 4, 'max' => 6.00, 'variables' => [
                ['nombre' => 'Distinciones Organismos Externos',  'orden' => 1, 'max' => 2.0, 'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Resolución/Diploma'],
                ['nombre' => 'Participación Externa y en la UCSM', 'orden' => 2, 'max' => 4.0, 'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Documentación correspondiente'],
            ]],
            ['nombre' => 'Investigación y Producción', 'orden' => 5, 'max' => 26.00, 'variables' => [
                ['nombre' => 'Investigación Científica', 'orden' => 1, 'max' => 14.0, 'tipo' => 'SUMA_CON_TOPE',      'validez' => null, 'fuente' => 'Registro de aprobación / publicación'],
                ['nombre' => 'Renacyt',                  'orden' => 2, 'max' => 6.0,  'tipo' => 'TABLA_EQUIVALENCIA', 'validez' => null, 'fuente' => 'Constancia'],
                ['nombre' => 'Producción Intelectual',   'orden' => 3, 'max' => 6.0,  'tipo' => 'SUMA_CON_TOPE',      'validez' => null, 'fuente' => 'Registro Biblioteca Nacional / ISBN'],
            ]],
            ['nombre' => 'Producción para el Desarrollo Universitario', 'orden' => 6, 'max' => 7.00, 'variables' => [
                ['nombre' => 'Proyectos Curriculares Normativos y de Producción', 'orden' => 1, 'max' => 4.0, 'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Resolución/documento de aprobación'],
                ['nombre' => 'Comisiones',                                        'orden' => 2, 'max' => 3.0, 'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Resolución/Informe'],
            ]],
            // ⚠️ DISCREPANCIA SIN RESOLVER (detectada 2026-07-21): la tabla
            // detallada de indicadores del PDF fuente (RES 9245-CU-2025, p.63)
            // topa "Dictado de Clases y Responsabilidad Docente" en 8.0, pero
            // la Ficha 4.1 resumen (la que cuadra el total de 100.0 y la que
            // está transcrita en tablas-evaluacion-convocatorias.md) la topa
            // en 9.0. Verificado con dos métodos de extracción independientes
            // — no es un artefacto de OCR. Se mantiene 9.0 porque es el valor
            // que reconcilia el total de la ficha; pendiente confirmación
            // escrita del contacto de normativa del cliente antes de darlo
            // por definitivo. NO cambiar este valor sin esa confirmación.
            ['nombre' => 'Práctica Docente', 'orden' => 7, 'max' => 12.00, 'variables' => [
                ['nombre' => 'Dictado de Clases y Responsabilidad Docente', 'orden' => 1, 'max' => 9.0, 'tipo' => 'TABLA_EQUIVALENCIA', 'validez' => null, 'fuente' => 'DATO_INSTITUCIONAL — Centro de Desarrollo Académico'],
                ['nombre' => 'Orientación y Asesoría a los Estudiantes',    'orden' => 2, 'max' => 3.0, 'tipo' => 'SUMA_CON_TOPE',      'validez' => null, 'fuente' => 'DATO_INSTITUCIONAL — Escuela Profesional'],
            ]],
            ['nombre' => 'Responsabilidad Social', 'orden' => 8, 'max' => 2.00, 'variables' => [
                ['nombre' => 'Proyección Social o Extensión Universitaria', 'orden' => 1, 'max' => 2.0, 'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Certificación/Constancia'],
            ]],
            ['nombre' => 'Funciones de Gobierno', 'orden' => 9, 'max' => 6.00, 'variables' => [
                ['nombre' => 'Cargos Asumidos', 'orden' => 1, 'max' => 6.0, 'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => '—'],
            ]],
        ];

        $this->insertRubros($tabla, $rubros);
        $this->command->info('  → Anexo 4 insertado');
    }

    // -------------------------------------------------------------------------
    // ANEXO 6 — Ingreso a Docencia Ordinaria Semipresencial/Distancia (total 90)
    // -------------------------------------------------------------------------
    private function seedAnexo6(): void
    {
        $tabla = TablaEvaluacion::firstOrCreate(
            ['reglamento_version_id' => $this->version->id, 'codigo_anexo' => 'ANEXO_6'],
            [
                'nombre'           => 'Ingreso a Docencia Ordinaria (Semipresencial y a Distancia)',
                'tipo_proceso'     => 'ingreso_ordinaria',
                'modalidad'        => 'semipresencial_distancia',
                'puntaje_total_max' => 90.00,
            ]
        );

        // Tope sub-rubro Superación Profesional: 24.0; suma de variables: 30.0
        $rubros = [
            ['nombre' => 'Formación Académica y Profesional Universitaria', 'orden' => 1, 'max' => 10.00, 'variables' => [
                ['nombre' => 'Grados Académicos',     'orden' => 1, 'max' => 6.0, 'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Diploma / registro SUNEDU'],
                ['nombre' => 'Títulos Profesionales', 'orden' => 2, 'max' => 4.0, 'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Diploma / registro SUNEDU'],
            ]],
            ['nombre' => 'Superación Profesional', 'orden' => 2, 'max' => 24.00, 'variables' => [
                ['nombre' => 'Participación y/o Asistencia',                'orden' => 1, 'max' => 10.0, 'tipo' => 'SUMA_CON_TOPE', 'validez' => 5,    'fuente' => 'Constancia'],
                ['nombre' => 'Ponencia',                                     'orden' => 2, 'max' => 6.0,  'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Constancia'],
                ['nombre' => 'Eventos de Posgrado',                          'orden' => 3, 'max' => 4.0,  'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Constancia'],
                ['nombre' => 'Diplomado y Especialización',                  'orden' => 4, 'max' => 2.0,  'tipo' => 'MAYOR_VALOR',   'validez' => null, 'fuente' => 'Certificado'],
                ['nombre' => 'Idioma Extranjero o Nativo',                   'orden' => 5, 'max' => 2.0,  'tipo' => 'MAYOR_VALOR',   'validez' => null, 'fuente' => 'Certificado'],
                ['nombre' => 'Informática',                                   'orden' => 6, 'max' => 2.0,  'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Certificado'],
                ['nombre' => 'Manejo de Entornos Virtuales para el Aprendizaje', 'orden' => 7, 'max' => 4.0, 'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Constancia'],
            ]],
            ['nombre' => 'Récord Docente', 'orden' => 3, 'max' => 5.00, 'variables' => [
                ['nombre' => 'Experiencia Docente / Jefatura de Prácticas', 'orden' => 1, 'max' => 5.0, 'tipo' => 'MAYOR_VALOR', 'validez' => null, 'fuente' => 'DATO_INSTITUCIONAL — Escalafón'],
            ]],
            ['nombre' => 'Distinciones', 'orden' => 4, 'max' => 2.00, 'variables' => [
                ['nombre' => 'Reconocimiento y Felicitación', 'orden' => 1, 'max' => 2.0, 'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Diploma/Resolución'],
            ]],
            ['nombre' => 'Responsabilidad Social', 'orden' => 5, 'max' => 4.00, 'variables' => [
                ['nombre' => 'Proyección Social o Extensión Universitaria', 'orden' => 1, 'max' => 4.0, 'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Certificación/Constancia'],
            ]],
            ['nombre' => 'Investigación y Producción', 'orden' => 6, 'max' => 20.00, 'variables' => [
                ['nombre' => 'Investigación Científica', 'orden' => 1, 'max' => 16.0, 'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Registro de aprobación / publicación'],
                ['nombre' => 'Invenciones y Patentes',   'orden' => 2, 'max' => 4.0,  'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Resolución INDECOPI'],
                ['nombre' => 'Producción Intelectual',   'orden' => 3, 'max' => 6.0,  'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Registro Biblioteca Nacional / ISBN'],
            ]],
            ['nombre' => 'Práctica Docente', 'orden' => 7, 'max' => 10.00, 'variables' => [
                ['nombre' => 'Dictado de Clases y Responsabilidad Docente', 'orden' => 1, 'max' => 10.0, 'tipo' => 'TABLA_EQUIVALENCIA', 'validez' => null, 'fuente' => 'DATO_INSTITUCIONAL — Departamento Académico + Dirección Estudios a Distancia'],
            ]],
            ['nombre' => 'Elaboración y Fundamentación del Sílabo', 'orden' => 8, 'max' => 5.00, 'variables' => [
                ['nombre' => 'Documento Sílabo / Fundamentación Oral', 'orden' => 1, 'max' => 5.0, 'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Documento + evaluación en vivo'],
            ]],
            ['nombre' => 'Clase Magistral', 'orden' => 9, 'max' => 10.00, 'variables' => [
                ['nombre' => 'Comportamiento Docente + Desarrollo del Contenido', 'orden' => 1, 'max' => 10.0, 'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Evaluación en vivo'],
            ]],
        ];

        $this->insertRubros($tabla, $rubros);
        $this->command->info('  → Anexo 6 insertado');
    }

    // -------------------------------------------------------------------------
    // ANEXO 7 — Ascenso Semipresencial/Distancia (total 101 puntos con topes)
    // -------------------------------------------------------------------------
    private function seedAnexo7(): void
    {
        $tabla = TablaEvaluacion::firstOrCreate(
            ['reglamento_version_id' => $this->version->id, 'codigo_anexo' => 'ANEXO_7'],
            [
                'nombre'           => 'Promoción/Ascenso de Categoría (Semipresencial y a Distancia)',
                'tipo_proceso'     => 'ascenso',
                'modalidad'        => 'semipresencial_distancia',
                'puntaje_total_max' => 101.00,
            ]
        );

        // Tope sub-rubro Investigación y Producción: 26.0; suma de variables puede excederlo
        $rubros = [
            ['nombre' => 'Formación Académica y Profesional Universitaria', 'orden' => 1, 'max' => 11.00, 'variables' => [
                ['nombre' => 'Grados Académicos',     'orden' => 1, 'max' => 7.0, 'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Diploma / registro SUNEDU'],
                ['nombre' => 'Títulos Profesionales', 'orden' => 2, 'max' => 4.0, 'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Diploma / registro SUNEDU'],
            ]],
            ['nombre' => 'Superación Profesional', 'orden' => 2, 'max' => 26.00, 'variables' => [
                ['nombre' => 'Participación y/o Asistencia',                'orden' => 1, 'max' => 4.0,  'tipo' => 'SUMA_CON_TOPE', 'validez' => 5,    'fuente' => 'Constancia'],
                ['nombre' => 'Ponencia',                                     'orden' => 2, 'max' => 8.0,  'tipo' => 'SUMA_CON_TOPE', 'validez' => 5,    'fuente' => 'Constancia'],
                ['nombre' => 'Eventos de Posgrado',                          'orden' => 3, 'max' => 4.0,  'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Constancia'],
                ['nombre' => 'Diplomado y Especialización',                  'orden' => 4, 'max' => 2.0,  'tipo' => 'MAYOR_VALOR',   'validez' => null, 'fuente' => 'Certificado'],
                ['nombre' => 'Idioma Extranjero o Nativo',                   'orden' => 5, 'max' => 2.0,  'tipo' => 'MAYOR_VALOR',   'validez' => null, 'fuente' => 'Certificado'],
                ['nombre' => 'Informática',                                   'orden' => 6, 'max' => 2.0,  'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Certificado'],
                ['nombre' => 'Manejo de Entornos Virtuales para el Aprendizaje', 'orden' => 7, 'max' => 4.0, 'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Constancia'],
            ]],
            ['nombre' => 'Récord Docente', 'orden' => 3, 'max' => 7.00, 'variables' => [
                ['nombre' => 'Experiencia Docente en la UCSM',   'orden' => 1, 'max' => 5.0, 'tipo' => 'MAYOR_VALOR',   'validez' => null, 'fuente' => 'DATO_INSTITUCIONAL — RRHH Escalafón'],
                ['nombre' => 'Mayor antigüedad en la Categoría', 'orden' => 2, 'max' => 2.0, 'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'DATO_INSTITUCIONAL — Escalafón'],
            ]],
            ['nombre' => 'Distinciones', 'orden' => 4, 'max' => 4.00, 'variables' => [
                ['nombre' => 'Distinciones Organismos Externos',   'orden' => 1, 'max' => 2.0, 'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Resolución/Diploma'],
                ['nombre' => 'Participación Externa y en la UCSM', 'orden' => 2, 'max' => 2.0, 'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Documentación correspondiente'],
            ]],
            // Tope sub-rubro: 26.0; suma de variables: 26.0 en este caso
            ['nombre' => 'Investigación y Producción', 'orden' => 5, 'max' => 26.00, 'variables' => [
                ['nombre' => 'Investigación Científica', 'orden' => 1, 'max' => 20.0, 'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Registro de aprobación / publicación'],
                ['nombre' => 'Producción Intelectual',   'orden' => 2, 'max' => 6.0,  'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Registro Biblioteca Nacional / ISBN'],
            ]],
            ['nombre' => 'Producción para el Desarrollo Universitario', 'orden' => 6, 'max' => 7.00, 'variables' => [
                ['nombre' => 'Proyectos Curriculares Normativos y de Producción', 'orden' => 1, 'max' => 4.0, 'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Resolución/documento de aprobación'],
                ['nombre' => 'Comisiones',                                        'orden' => 2, 'max' => 3.0, 'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Resolución/Informe'],
            ]],
            ['nombre' => 'Práctica Docente', 'orden' => 7, 'max' => 13.00, 'variables' => [
                ['nombre' => 'Dictado de Clases y Responsabilidad Docente', 'orden' => 1, 'max' => 10.0, 'tipo' => 'TABLA_EQUIVALENCIA', 'validez' => null, 'fuente' => 'DATO_INSTITUCIONAL — Departamento Académico + Dirección Estudios a Distancia'],
                ['nombre' => 'Orientación y Asesoría a los Estudiantes',    'orden' => 2, 'max' => 3.0,  'tipo' => 'SUMA_CON_TOPE',      'validez' => null, 'fuente' => 'DATO_INSTITUCIONAL — Escuela Profesional'],
            ]],
            ['nombre' => 'Responsabilidad Social', 'orden' => 8, 'max' => 2.00, 'variables' => [
                ['nombre' => 'Proyección Social o Extensión Universitaria', 'orden' => 1, 'max' => 2.0, 'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => 'Certificación/Constancia'],
            ]],
            ['nombre' => 'Funciones de Gobierno', 'orden' => 9, 'max' => 5.00, 'variables' => [
                ['nombre' => 'Cargos Asumidos', 'orden' => 1, 'max' => 5.0, 'tipo' => 'SUMA_CON_TOPE', 'validez' => null, 'fuente' => '—'],
            ]],
        ];

        $this->insertRubros($tabla, $rubros);
        $this->command->info('  → Anexo 7 insertado');
    }

    // -------------------------------------------------------------------------
    // Helper: inserta rubros, variables (sin indicadores individuales de momento)
    // -------------------------------------------------------------------------
    private function insertRubros(TablaEvaluacion $tabla, array $rubros): void
    {
        foreach ($rubros as $rubroData) {
            $rubro = Rubro::firstOrCreate(
                [
                    'tabla_evaluacion_id' => $tabla->id,
                    'nombre'              => $rubroData['nombre'],
                ],
                [
                    'orden'               => $rubroData['orden'],
                    'puntaje_max_subrubro' => $rubroData['max'],
                ]
            );

            foreach ($rubroData['variables'] as $varData) {
                Variable::firstOrCreate(
                    [
                        'rubro_id' => $rubro->id,
                        'nombre'   => $varData['nombre'],
                    ],
                    [
                        'orden'                => $varData['orden'],
                        'puntaje_max'          => $varData['max'],
                        'tipo_calculo'         => $varData['tipo'],
                        'periodo_validez_anios' => $varData['validez'],
                        'fuente_verificacion'  => $varData['fuente'],
                    ]
                );
            }
        }
    }
}
