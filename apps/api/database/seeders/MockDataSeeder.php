<?php

namespace Database\Seeders;

use App\Models\AsignacionEvaluador;
use App\Models\Convocatoria;
use App\Models\Evaluacion;
use App\Models\Evidencia;
use App\Models\Expediente;
use App\Models\Indicador;
use App\Models\Plaza;
use App\Models\Postulacion;
use App\Models\PostulacionEtapa;
use App\Models\PostulacionEvidencia;
use App\Models\Puntaje;
use App\Models\TablaEvaluacion;
use App\Models\User;
use App\Models\Variable;
use App\Services\AuditService;
use App\Services\CalculadorService;
use App\Services\ResultadosService;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Datos de prueba para desarrollo/QA — usuarios, convocatorias, plazas,
 * postulaciones, evidencias (con archivos reales en storage), evaluaciones
 * y resultados en distintos estados, para poder navegar la app sin tener
 * que armar cada escenario a mano.
 *
 * NO corre con `db:seed` por defecto (no está en DatabaseSeeder::run()).
 * Invocar explícitamente:
 *   php artisan db:seed --class=MockDataSeeder
 *
 * Reutiliza los servicios y modelos reales (Convocatoria::generarSnapshot(),
 * CalculadorService, ResultadosService, AuditService) en vez de reimplementar
 * las reglas de negocio, para que los datos resultantes sean consistentes
 * con lo que el sistema realmente calcularía si un usuario hiciera lo mismo
 * a mano por la UI.
 *
 * Idempotente: si ya corrió antes (existe CONV-2026-101), no hace nada.
 */
class MockDataSeeder extends Seeder
{
    private CalculadorService $calculador;
    private ResultadosService $resultadosService;

    public function run(): void
    {
        if (Convocatoria::withTrashed()->where('codigo', 'CONV-2026-101')->exists()) {
            $this->command->warn('MockDataSeeder ya corrió antes (CONV-2026-101 existe) — omitiendo.');
            return;
        }

        $this->calculador       = app(CalculadorService::class);
        $this->resultadosService = app(ResultadosService::class);

        $admin = User::where('email', 'admin@vocare.local')->firstOrFail();
        Auth::loginUsingId($admin->id);

        // ── Usuarios ─────────────────────────────────────────────────────────
        $marta = $this->usuario('Marta Salas Delgado', 'marta.salas@vocare.local', '20000001', 'evaluador', 'Evaluador123!');
        $renzo = $this->usuario('Renzo Huamán Cáceres', 'renzo.huaman@vocare.local', '20000002', 'evaluador', 'Evaluador123!');
        $juan  = $this->usuario('Juan Pérez Quispe',    'juan.perez@example.com',    '10000001', 'postulante', 'Postulante123!');
        $ana   = $this->usuario('Ana Ramos Flores',     'ana.ramos@example.com',     '10000002', 'postulante', 'Postulante123!');
        $luis  = $this->usuario('Luis Cárdenas Vega',   'luis.cardenas@example.com', '10000003', 'postulante', 'Postulante123!');
        $rita  = $this->usuario('Rita Gómez Salinas',   'rita.gomez@example.com',    '10000004', 'postulante', 'Postulante123!');

        $anexo1 = TablaEvaluacion::where('codigo_anexo', 'ANEXO_1')->where('estado', 'activo')->firstOrFail();
        $anexo2 = TablaEvaluacion::where('codigo_anexo', 'ANEXO_2')->where('estado', 'activo')->firstOrFail();
        $anexo3 = TablaEvaluacion::where('codigo_anexo', 'ANEXO_3')->where('estado', 'activo')->firstOrFail();
        $anexo4 = TablaEvaluacion::where('codigo_anexo', 'ANEXO_4')->where('estado', 'activo')->firstOrFail();

        // El Anexo 3 tiene 2 variables TABLA_EQUIVALENCIA (Renacyt, Dictado de
        // Clases) sin ningún Indicador seedeado en todo el sistema — sin esto
        // el patrón de UI de "tabla de equivalencia" queda inerte, no hay
        // rangos que mostrar. Rangos ilustrativos, no el reglamento oficial
        // (ver CONTEXTO.md: números reales pendientes de confirmación).
        $this->asegurarRangosMock($anexo3, 'Investigación y Producción', 'Renacyt');
        $this->asegurarRangosMock($anexo3, 'Práctica Docente', 'Dictado de Clases y Responsabilidad Docente');

        // ════════════════════════════════════════════════════════════════════
        // CONV-2026-101 — Contratación de Docentes (Anexo 1) — publicada,
        // abierta a postulaciones. Un postulante sin enviar (expediente
        // incompleto a propósito) y otro enviado con una evidencia observada.
        // ════════════════════════════════════════════════════════════════════
        $conv101 = $this->convocatoria(
            'CONV-2026-101', 'Contratación de Docentes — Facultad de Ingeniería',
            $anexo1, 'publicada', now()->subDays(20), now()->addDays(25)
        );
        $plazaCalculo = $this->plaza($conv101, 'Facultad de Ingeniería', 'Ciencias Básicas', 'Cálculo I');
        $this->plaza($conv101, 'Facultad de Ingeniería', 'Ciencias Básicas', 'Física I');

        $postJuan101 = $this->postulacion($juan, $conv101, $plazaCalculo);
        $this->evidencia($postJuan101, $anexo1, 'Formación Académica y Profesional Universitaria', 'Grados Académicos', 8.0);
        $this->evidencia($postJuan101, $anexo1, 'Formación Académica y Profesional Universitaria', 'Títulos Profesionales', 4.0);
        $this->evidencia($postJuan101, $anexo1, 'Superación Profesional', 'Idioma Extranjero o Nativo', 3.0, extension: 'png');
        $this->evidencia($postJuan101, $anexo1, 'Elaboración del Sílabo', 'Sílabo', 4.0);
        // Sin enviar — expediente incompleto a propósito (faltan Récord,
        // Investigación, Demostración Magistral). Prueba el estado "borrador".

        $plazaFisica1 = Plaza::where('convocatoria_id', $conv101->id)->where('asignatura', 'Física I')->firstOrFail();
        $postAna101 = $this->postulacion($ana, $conv101, $plazaFisica1);
        $this->evidencia($postAna101, $anexo1, 'Formación Académica y Profesional Universitaria', 'Grados Académicos', 8.0, estado: PostulacionEvidencia::ESTADO_APROBADA, evaluador: $marta);
        $this->evidencia($postAna101, $anexo1, 'Formación Académica y Profesional Universitaria', 'Títulos Profesionales', 4.0, estado: PostulacionEvidencia::ESTADO_APROBADA, evaluador: $marta);
        $this->evidencia($postAna101, $anexo1, 'Superación Profesional', 'Idioma Extranjero o Nativo', 3.0, estado: PostulacionEvidencia::ESTADO_APROBADA, evaluador: $marta, extension: 'png');
        $this->evidencia($postAna101, $anexo1, 'Superación Profesional', 'Diplomado y Especialización', 2.0, estado: PostulacionEvidencia::ESTADO_OBSERVADA, evaluador: $marta, comentario: 'La fecha de emisión no es legible — sube una copia más clara.');
        $this->evidencia($postAna101, $anexo1, 'Elaboración del Sílabo', 'Sílabo', 4.0, estado: PostulacionEvidencia::ESTADO_APROBADA, evaluador: $marta);
        $this->enviar($postAna101);
        $postAna101->update(['estado' => Postulacion::ESTADO_OBSERVADA]);

        // ════════════════════════════════════════════════════════════════════
        // CONV-2026-102 — Contratación de Jefes de Práctica (Anexo 2) —
        // en_proceso. Una evaluación a medio calificar (bandeja "en progreso")
        // y una postulación asignada pero sin iniciar (bandeja "sin iniciar").
        // ════════════════════════════════════════════════════════════════════
        $conv102 = $this->convocatoria(
            'CONV-2026-102', 'Contratación de Jefes de Práctica — Facultad de Ciencias',
            $anexo2, 'en_proceso', now()->subDays(15), now()->addDays(10)
        );
        $plazaQuimica = $this->plaza($conv102, 'Facultad de Ciencias', 'Química', 'Química Orgánica');

        $postLuis102 = $this->postulacion($luis, $conv102, $plazaQuimica);
        $this->evidencia($postLuis102, $anexo2, 'Formación Académica y Profesional Universitaria', 'Grados Académicos', 6.0, estado: PostulacionEvidencia::ESTADO_APROBADA, evaluador: $marta);
        $this->evidencia($postLuis102, $anexo2, 'Superación Profesional', 'Idioma Extranjero o Nativo', 3.0, estado: PostulacionEvidencia::ESTADO_APROBADA, evaluador: $marta, extension: 'png');
        $this->evidencia($postLuis102, $anexo2, 'Superación Profesional', 'Idioma Extranjero o Nativo', 2.0, estado: PostulacionEvidencia::ESTADO_APROBADA, evaluador: $marta, extension: 'png');
        $this->evidencia($postLuis102, $anexo2, 'Récord Profesional y Docente', 'Experiencia Laboral Profesional', 8.0, estado: PostulacionEvidencia::ESTADO_APROBADA, evaluador: $marta);
        $this->evidencia($postLuis102, $anexo2, 'Investigación y Producción', 'Publicaciones Científicas', 8.0);
        $this->evidencia($postLuis102, $anexo2, 'Elaboración de Guía de Prácticas', 'Guía de Prácticas', 4.0, estado: PostulacionEvidencia::ESTADO_APROBADA, evaluador: $marta);
        $this->enviar($postLuis102);
        $this->asignar($conv102, $postLuis102, $marta);
        // Evaluación creada e iniciada, pero sin calcular todavía — "en progreso" real.
        Evaluacion::create(['postulacion_id' => $postLuis102->id, 'evaluador_id' => $marta->id, 'estado' => Evaluacion::ESTADO_EN_PROCESO]);

        $postRita102 = $this->postulacion($rita, $conv102, $plazaQuimica);
        $this->evidencia($postRita102, $anexo2, 'Formación Académica y Profesional Universitaria', 'Grados Académicos', 6.0);
        $this->evidencia($postRita102, $anexo2, 'Récord Profesional y Docente', 'Experiencia Laboral Profesional', 6.0);
        $this->enviar($postRita102);
        $this->asignar($conv102, $postRita102, $renzo);
        // Sin Evaluacion todavía — aparece en "Asignadas, sin iniciar".

        // ════════════════════════════════════════════════════════════════════
        // CONV-2026-103 — Ingreso a Docencia Ordinaria (Anexo 3) — cerrada,
        // ranking generado con un EMPATE PENDIENTE de resolver (Juan y Ana
        // quedan exactos en el mismo puntaje) y Rita como reserva. No se
        // publica — queda a propósito para probar la resolución de empate.
        // ════════════════════════════════════════════════════════════════════
        $conv103 = $this->convocatoria(
            'CONV-2026-103', 'Ingreso a Docencia Ordinaria — Facultad de Ciencias (Matemática)',
            $anexo3, 'publicada', now()->subDays(60), now()->subDays(20)
        );
        $plazaMatematica = $this->plaza($conv103, 'Facultad de Ciencias', 'Matemática', 'Matemática I');

        // Juan y Ana reciben exactamente las mismas evidencias/puntajes —
        // empate real y determinístico, no "casualidad" de datos aleatorios.
        $evaluacionJuan103 = $this->postulacionCompletaAnexo3($juan, $conv103, $plazaMatematica, $marta, 6.0, 4.0, 10.0, 10.0, 18, 17, 2.0, 6.0, 6.0);
        $evaluacionAna103  = $this->postulacionCompletaAnexo3($ana,  $conv103, $plazaMatematica, $marta, 6.0, 4.0, 10.0, 10.0, 18, 17, 2.0, 6.0, 6.0);
        $evaluacionRita103 = $this->postulacionCompletaAnexo3($rita, $conv103, $plazaMatematica, $marta, 6.0, 4.0, 10.0, 10.0, 15, 15, 2.0, 6.0, 6.0);

        AuditService::log('evaluacion.cerrada', $evaluacionJuan103);
        AuditService::log('evaluacion.cerrada', $evaluacionAna103);
        AuditService::log('evaluacion.cerrada', $evaluacionRita103);

        $conv103->update(['estado' => Convocatoria::ESTADO_CERRADA]);
        $resultados103 = $this->resultadosService->generarRankingPlaza($conv103, $plazaMatematica);
        AuditService::log('resultados.ranking_generado', $conv103, [], ['plaza_id' => $plazaMatematica->id, 'total' => $resultados103->count()]);
        // Empate pendiente entre Juan y Ana — se deja SIN resolver a propósito.

        // ════════════════════════════════════════════════════════════════════
        // CONV-2026-104 — Ascenso de Categoría (Anexo 4) — cerrada y
        // PUBLICADA. Luis gana, Rita queda de reserva — para probar la
        // vista de resultado del postulante en ambos casos.
        // ════════════════════════════════════════════════════════════════════
        $conv104 = $this->convocatoria(
            'CONV-2026-104', 'Ascenso de Categoría — Facultad de Ingeniería (Física)',
            $anexo4, 'publicada', now()->subDays(90), now()->subDays(40)
        );
        $plazaAscenso = $this->plaza($conv104, 'Facultad de Ingeniería', 'Física', 'Docente Asociado — Física');

        // Puntajes elegidos para que ambos superen el mínimo de aprobación
        // (fallback 50 pts — ver ResultadosService::PUNTAJE_MINIMO_APROBATORIO_FALLBACK,
        // este anexo todavía no tiene puntaje_minimo_aprobatorio propio
        // confirmado por el cliente): Luis 65, Rita 52 — ambos elegibles,
        // Luis gana, Rita queda de reserva.
        $postLuis104 = $this->postulacion($luis, $conv104, $plazaAscenso);
        $this->evidencia($postLuis104, $anexo4, 'Formación Académica y Profesional Universitaria', 'Grados Académicos', 8.0, estado: PostulacionEvidencia::ESTADO_APROBADA, evaluador: $renzo);
        $this->evidencia($postLuis104, $anexo4, 'Formación Académica y Profesional Universitaria', 'Títulos Profesionales', 4.0, estado: PostulacionEvidencia::ESTADO_APROBADA, evaluador: $renzo);
        $this->evidencia($postLuis104, $anexo4, 'Superación Docente durante la permanencia en la Categoría', 'Ponencia, Participación y/o Asistencia', 10.0, estado: PostulacionEvidencia::ESTADO_APROBADA, evaluador: $renzo);
        $this->evidencia($postLuis104, $anexo4, 'Superación Docente durante la permanencia en la Categoría', 'Eventos de Posgrado', 6.0, estado: PostulacionEvidencia::ESTADO_APROBADA, evaluador: $renzo);
        $this->evidencia($postLuis104, $anexo4, 'Récord Docente', 'Experiencia Docente en la UCSM', 5.0, estado: PostulacionEvidencia::ESTADO_APROBADA, evaluador: $renzo);
        $this->evidencia($postLuis104, $anexo4, 'Distinciones y Participaciones', 'Distinciones Organismos Externos', 2.0, estado: PostulacionEvidencia::ESTADO_APROBADA, evaluador: $renzo);
        $this->evidencia($postLuis104, $anexo4, 'Investigación y Producción', 'Investigación Científica', 14.0, estado: PostulacionEvidencia::ESTADO_APROBADA, evaluador: $renzo);
        $this->evidencia($postLuis104, $anexo4, 'Investigación y Producción', 'Producción Intelectual', 6.0, estado: PostulacionEvidencia::ESTADO_APROBADA, evaluador: $renzo);
        $this->evidencia($postLuis104, $anexo4, 'Producción para el Desarrollo Universitario', 'Proyectos Curriculares Normativos y de Producción', 4.0, estado: PostulacionEvidencia::ESTADO_APROBADA, evaluador: $renzo);
        $this->evidencia($postLuis104, $anexo4, 'Funciones de Gobierno', 'Cargos Asumidos', 6.0, estado: PostulacionEvidencia::ESTADO_APROBADA, evaluador: $renzo);
        $this->enviar($postLuis104);
        $this->asignar($conv104, $postLuis104, $renzo);
        $evLuis104 = Evaluacion::create(['postulacion_id' => $postLuis104->id, 'evaluador_id' => $renzo->id, 'estado' => Evaluacion::ESTADO_EN_PROCESO]);
        $this->calculador->calcular($evLuis104->fresh());
        $evLuis104->fresh()->update(['estado' => Evaluacion::ESTADO_CERRADA, 'cerrada_en' => now()]);

        $postRita104 = $this->postulacion($rita, $conv104, $plazaAscenso);
        $this->evidencia($postRita104, $anexo4, 'Formación Académica y Profesional Universitaria', 'Grados Académicos', 8.0, estado: PostulacionEvidencia::ESTADO_APROBADA, evaluador: $renzo);
        $this->evidencia($postRita104, $anexo4, 'Formación Académica y Profesional Universitaria', 'Títulos Profesionales', 4.0, estado: PostulacionEvidencia::ESTADO_APROBADA, evaluador: $renzo);
        $this->evidencia($postRita104, $anexo4, 'Superación Docente durante la permanencia en la Categoría', 'Ponencia, Participación y/o Asistencia', 8.0, estado: PostulacionEvidencia::ESTADO_APROBADA, evaluador: $renzo);
        $this->evidencia($postRita104, $anexo4, 'Superación Docente durante la permanencia en la Categoría', 'Eventos de Posgrado', 4.0, estado: PostulacionEvidencia::ESTADO_APROBADA, evaluador: $renzo);
        $this->evidencia($postRita104, $anexo4, 'Récord Docente', 'Experiencia Docente en la UCSM', 5.0, estado: PostulacionEvidencia::ESTADO_APROBADA, evaluador: $renzo);
        $this->evidencia($postRita104, $anexo4, 'Investigación y Producción', 'Investigación Científica', 10.0, estado: PostulacionEvidencia::ESTADO_APROBADA, evaluador: $renzo);
        $this->evidencia($postRita104, $anexo4, 'Investigación y Producción', 'Producción Intelectual', 4.0, estado: PostulacionEvidencia::ESTADO_APROBADA, evaluador: $renzo);
        $this->evidencia($postRita104, $anexo4, 'Producción para el Desarrollo Universitario', 'Proyectos Curriculares Normativos y de Producción', 3.0, estado: PostulacionEvidencia::ESTADO_APROBADA, evaluador: $renzo);
        $this->evidencia($postRita104, $anexo4, 'Funciones de Gobierno', 'Cargos Asumidos', 6.0, estado: PostulacionEvidencia::ESTADO_APROBADA, evaluador: $renzo);
        $this->enviar($postRita104);
        $this->asignar($conv104, $postRita104, $renzo);
        $evRita104 = Evaluacion::create(['postulacion_id' => $postRita104->id, 'evaluador_id' => $renzo->id, 'estado' => Evaluacion::ESTADO_EN_PROCESO]);
        $this->calculador->calcular($evRita104->fresh());
        $evRita104->fresh()->update(['estado' => Evaluacion::ESTADO_CERRADA, 'cerrada_en' => now()]);

        $conv104->update(['estado' => Convocatoria::ESTADO_CERRADA]);
        $this->resultadosService->generarRankingPlaza($conv104, $plazaAscenso);
        $this->resultadosService->publicarResultados($conv104, $admin->id);
        AuditService::log('resultados.publicados', $conv104, [], ['convocatoria_id' => $conv104->id]);

        $this->command->info('✅ MockDataSeeder: 6 usuarios, 4 convocatorias, 7 postulaciones, evidencias con archivos reales, 2 evaluaciones cerradas con empate pendiente (CONV-2026-103) y resultados publicados (CONV-2026-104).');
        $this->command->info('   Postulantes: juan.perez@example.com / ana.ramos@example.com / luis.cardenas@example.com / rita.gomez@example.com — Postulante123!');
        $this->command->info('   Evaluadores: marta.salas@vocare.local / renzo.huaman@vocare.local — Evaluador123!');
    }

    // -------------------------------------------------------------------------
    // Helpers de dominio — reutilizan modelos/servicios reales
    // -------------------------------------------------------------------------

    private function usuario(string $name, string $email, string $dni, string $rol, string $password): User
    {
        $user = User::firstOrCreate(
            ['email' => $email],
            ['name' => $name, 'dni' => $dni, 'password' => Hash::make($password), 'is_active' => true]
        );
        $user->syncRoles([$rol]);
        return $user;
    }

    private function convocatoria(string $codigo, string $nombre, TablaEvaluacion $tabla, string $estadoFinal, Carbon $inicio, Carbon $fin): Convocatoria
    {
        $conv = Convocatoria::create([
            'codigo'                => $codigo,
            'nombre'                => $nombre,
            'reglamento_version_id' => $tabla->reglamento_version_id,
            'tabla_evaluacion_id'   => $tabla->id,
            'tipo_proceso'          => $tabla->tipo_proceso,
            'modalidad'             => $tabla->modalidad,
            'fecha_inicio'          => $inicio,
            'fecha_fin'             => $fin,
            'estado'                => Convocatoria::ESTADO_BORRADOR,
            'creado_por'            => Auth::id(),
        ]);
        $conv->load('tablaEvaluacion.rubros.variables.indicadores', 'tablaEvaluacion.etapas');
        $conv->generarSnapshot();
        AuditService::log('convocatoria.creada', $conv, [], $conv->toArray());
        $conv->update(['estado' => $estadoFinal]);
        return $conv->fresh();
    }

    private function plaza(Convocatoria $conv, string $facultad, string $departamento, string $asignatura): Plaza
    {
        return Plaza::create([
            'convocatoria_id' => $conv->id,
            'facultad'        => $facultad,
            'departamento'    => $departamento,
            'asignatura'      => $asignatura,
            'modalidad'       => 'Tiempo completo',
            'horas_semana'    => 20,
            'estado'          => 'activa',
        ]);
    }

    private function postulacion(User $user, Convocatoria $conv, Plaza $plaza): Postulacion
    {
        $post = Postulacion::create([
            'user_id'         => $user->id,
            'convocatoria_id' => $conv->id,
            'plaza_id'        => $plaza->id,
            'estado'          => Postulacion::ESTADO_EN_PROCESO,
        ]);
        Expediente::create(['postulacion_id' => $post->id, 'estado' => 'en_preparacion', 'total_bytes' => 0]);
        foreach ($conv->tablaEvaluacion->etapas as $etapa) {
            PostulacionEtapa::create([
                'postulacion_id' => $post->id, 'etapa_id' => $etapa->id,
                'estado' => PostulacionEtapa::ESTADO_PENDIENTE,
            ]);
        }
        AuditService::log('postulacion.creada', $post, [], $post->toArray());
        return $post->fresh();
    }

    private function enviar(Postulacion $post): void
    {
        $post->cvSnapshot()->create(['datos' => ['nota' => 'CV de prueba (mock)'], 'tomado_en' => now()]);
        $post->update(['fecha_envio' => now()->subDays(random_int(1, 10))]);
        $post->expediente->update(['estado' => 'enviado']);
        AuditService::log('postulacion.enviada', $post);
    }

    private function asignar(Convocatoria $conv, Postulacion $post, User $evaluador): AsignacionEvaluador
    {
        $asignacion = AsignacionEvaluador::create([
            'convocatoria_id' => $conv->id, 'postulacion_id' => $post->id,
            'evaluador_id'    => $evaluador->id, 'etapa_id' => null, 'tipo' => AsignacionEvaluador::TIPO_EVALUADOR,
        ]);
        AuditService::log('asignacion.creada', $asignacion);
        return $asignacion;
    }

    /**
     * Sube una evidencia real (archivo en storage) para una variable, y crea
     * el pivote postulacion_evidencia con la vigencia calculada — mismo
     * cálculo que EvidenciasController::crearPivote().
     */
    private function evidencia(
        Postulacion $post,
        TablaEvaluacion $tabla,
        string $rubroNombre,
        string $variableNombre,
        float $puntajeIndicador,
        string $estado = PostulacionEvidencia::ESTADO_PENDIENTE,
        ?User $evaluador = null,
        ?string $comentario = null,
        string $extension = 'pdf',
    ): PostulacionEvidencia {
        $variable = $this->variable($tabla, $rubroNombre, $variableNombre);

        if ($extension === 'png') {
            $bytes = $this->pngBytes();
            $mime  = 'image/png';
        } else {
            $bytes = $this->pdfBytes($variableNombre);
            $mime  = 'application/pdf';
        }

        $uuid = (string) Str::uuid();
        $ruta = "expedientes/{$post->user_id}/{$uuid}.{$extension}";
        Storage::disk('local')->put($ruta, $bytes);

        $fechaEmision = now()->subMonths(random_int(2, 18))->toDateString();

        $evidencia = Evidencia::create([
            'user_id'           => $post->user_id,
            'variable_id'       => $variable->id,
            'indicador_id'      => null,
            'puntaje_indicador' => $puntajeIndicador,
            'nombre_original'   => Str::slug($variableNombre) . '.' . $extension,
            'ruta_archivo'      => $ruta,
            'mime_type'         => $mime,
            'tamano_bytes'      => strlen($bytes),
            'hash_archivo'      => hash('sha256', $bytes),
            'fecha_emision'     => $fechaEmision,
            'estado'            => $estado === PostulacionEvidencia::ESTADO_PENDIENTE ? Evidencia::ESTADO_PENDIENTE : $estado,
            'comentario_observacion' => $comentario,
            'evaluador_id'      => $evaluador?->id,
            'fecha_validacion'  => $evaluador ? now() : null,
        ]);

        $aniosValidez = $variable->periodo_validez_anios;
        if ($aniosValidez === null) {
            $fechaVencimiento = null;
            $vigente = true;
        } else {
            $fechaVencimiento = Carbon::parse($fechaEmision)->addYears($aniosValidez);
            $vigente = $fechaVencimiento->gte($post->convocatoria->fecha_inicio);
        }

        $pivote = PostulacionEvidencia::create([
            'postulacion_id'            => $post->id,
            'evidencia_id'              => $evidencia->id,
            'fecha_convocatoria'        => $post->convocatoria->fecha_inicio,
            'anios_validez'             => $aniosValidez,
            'fecha_vencimiento'         => $fechaVencimiento,
            'vigente'                   => $vigente,
            'estado_en_postulacion'     => $estado,
            'comentario_postulacion'    => $comentario,
            'evaluador_postulacion_id'  => $evaluador?->id,
            'fecha_revision_postulacion' => $evaluador ? now() : null,
        ]);

        $post->expediente->increment('total_bytes', strlen($bytes));

        return $pivote;
    }

    /**
     * Anexo 3 completo para una postulación: evidencias en 7 variables +
     * las 2 TABLA_EQUIVALENCIA, evaluación calculada y cerrada. Los mismos
     * argumentos numéricos producen el mismo puntaje_total — así es como
     * Juan y Ana quedan exactamente empatados en CONV-2026-103.
     */
    private function postulacionCompletaAnexo3(
        User $user, Convocatoria $conv, Plaza $plaza, User $evaluador,
        float $grados, float $titulos, float $ponencia, float $investigacion,
        float $valorRenacyt, float $valorDictado, float $silabo, float $comportamiento, float $desarrollo,
    ): Evaluacion {
        $tabla = $conv->tablaEvaluacion;
        $post  = $this->postulacion($user, $conv, $plaza);

        $this->evidencia($post, $tabla, 'Formación Académica y Profesional Universitaria', 'Grados Académicos', $grados, PostulacionEvidencia::ESTADO_APROBADA, $evaluador);
        $this->evidencia($post, $tabla, 'Formación Académica y Profesional Universitaria', 'Títulos Profesionales', $titulos, PostulacionEvidencia::ESTADO_APROBADA, $evaluador);
        $this->evidencia($post, $tabla, 'Superación Profesional', 'Ponencia, Participación y/o Asistencia', $ponencia, PostulacionEvidencia::ESTADO_APROBADA, $evaluador);
        $this->evidencia($post, $tabla, 'Investigación y Producción', 'Investigación Científica', $investigacion, PostulacionEvidencia::ESTADO_APROBADA, $evaluador);
        $this->evidencia($post, $tabla, 'Elaboración y Fundamentación del Sílabo', 'Documento Sílabo presentado', $silabo, PostulacionEvidencia::ESTADO_APROBADA, $evaluador);
        $this->evidencia($post, $tabla, 'Clase Magistral / Concurso de Oposición', 'Comportamiento Docente', $comportamiento, PostulacionEvidencia::ESTADO_APROBADA, $evaluador);
        $this->evidencia($post, $tabla, 'Clase Magistral / Concurso de Oposición', 'Desarrollo del Contenido', $desarrollo, PostulacionEvidencia::ESTADO_APROBADA, $evaluador);
        $this->enviar($post);
        $this->asignar($conv, $post, $evaluador);

        $evaluacion = Evaluacion::create(['postulacion_id' => $post->id, 'evaluador_id' => $evaluador->id, 'estado' => Evaluacion::ESTADO_EN_PROCESO]);

        // TABLA_EQUIVALENCIA — mismo camino que guardarPuntaje(): guarda
        // valor_entrada, y calcular() mapea contra el Indicador del snapshot.
        $this->guardarValorTablaEquivalencia($evaluacion, $tabla, 'Investigación y Producción', 'Renacyt', $valorRenacyt);
        $this->guardarValorTablaEquivalencia($evaluacion, $tabla, 'Práctica Docente', 'Dictado de Clases y Responsabilidad Docente', $valorDictado);

        $this->calculador->calcular($evaluacion->fresh());
        $evaluacion->fresh()->update(['estado' => Evaluacion::ESTADO_CERRADA, 'cerrada_en' => now()]);

        return $evaluacion->fresh();
    }

    private function guardarValorTablaEquivalencia(Evaluacion $evaluacion, TablaEvaluacion $tabla, string $rubroNombre, string $variableNombre, float $valorEntrada): void
    {
        $variable  = $this->variable($tabla, $rubroNombre, $variableNombre);
        $indicador = $variable->indicadores()->first();

        Puntaje::create([
            'evaluacion_id'  => $evaluacion->id,
            'variable_id'    => $variable->id,
            'nombre_variable' => $variable->nombre,
            'tipo_calculo'   => Variable::TIPO_TABLA_EQUIVALENCIA,
            'valor_entrada'  => $valorEntrada,
            'indicador_id'   => $indicador?->id,
            'puntaje_bruto'  => 0,
            'puntaje_variable' => 0,
            'detalle'        => ['tabla_equivalencia' => $indicador?->tabla_equivalencia ?? []],
        ]);
    }

    /**
     * Rangos ilustrativos para una variable TABLA_EQUIVALENCIA que todavía no
     * tiene ningún Indicador — sin esto no hay nada que mapear y la pantalla
     * de este patrón queda vacía. No es el reglamento oficial (ver
     * CONTEXTO.md: pendiente de confirmación del cliente). No se toca si ya
     * existe un Indicador real.
     */
    private function asegurarRangosMock(TablaEvaluacion $tabla, string $rubroNombre, string $variableNombre): void
    {
        $variable = $this->variable($tabla, $rubroNombre, $variableNombre);
        if ($variable->indicadores()->exists()) {
            return;
        }

        $max = (float) $variable->puntaje_max;
        Indicador::create([
            'variable_id' => $variable->id,
            'nombre'      => 'Nota / nivel (0-20)',
            'puntaje'     => 0,
            'orden'       => 1,
            'tabla_equivalencia' => [
                ['min' => 0,  'max' => 13, 'puntaje' => 0],
                ['min' => 14, 'max' => 16, 'puntaje' => round($max * 0.5, 2)],
                ['min' => 17, 'max' => 20, 'puntaje' => $max],
            ],
        ]);
    }

    private function variable(TablaEvaluacion $tabla, string $rubroNombre, string $variableNombre): Variable
    {
        return Variable::whereHas('rubro', fn ($q) => $q->where('tabla_evaluacion_id', $tabla->id)->where('nombre', $rubroNombre))
            ->where('nombre', $variableNombre)
            ->firstOrFail();
    }

    // -------------------------------------------------------------------------
    // Archivos de prueba — PDF/PNG válidos y mínimos, para que el visor de
    // documentos del evaluador tenga algo real que embeber, no un archivo roto.
    // -------------------------------------------------------------------------

    private function pdfBytes(string $titulo): string
    {
        $texto = 'Documento de prueba (mock) - ' . addcslashes($titulo, "()\\");

        $objects = [];
        $objects[1] = "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
        $objects[2] = "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";
        $objects[3] = "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 300 200] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>\nendobj\n";
        $objects[4] = "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>\nendobj\n";
        $stream = "BT /F1 14 Tf 20 150 Td ({$texto}) Tj ET";
        $objects[5] = "5 0 obj\n<< /Length " . strlen($stream) . " >>\nstream\n{$stream}\nendstream\nendobj\n";

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $num => $obj) {
            $offsets[$num] = strlen($pdf);
            $pdf .= $obj;
        }
        $xrefStart = strlen($pdf);
        $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        for ($i = 1; $i <= 5; $i++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }
        $pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n{$xrefStart}\n%%EOF";

        return $pdf;
    }

    private function pngBytes(): string
    {
        // 1x1 px, rojo — suficiente para que <img> lo renderice de verdad.
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
    }
}
