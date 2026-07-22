<?php

use App\Http\Controllers\Api\V1\AsignacionesController;
use App\Http\Controllers\Api\V1\AuditoriaController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ConvocatoriasController;
use App\Http\Controllers\Api\V1\EtapasController;
use App\Http\Controllers\Api\V1\EvaluacionesController;
use App\Http\Controllers\Api\V1\EvidenciasController;
use App\Http\Controllers\Api\V1\IndicadoresController;
use App\Http\Controllers\Api\V1\PlazasController;
use App\Http\Controllers\Api\V1\PostulacionEtapasController;
use App\Http\Controllers\Api\V1\PostulacionesController;
use App\Http\Controllers\Api\V1\ResultadosController;
use App\Http\Controllers\Api\V1\RubrosController;
use App\Http\Controllers\Api\V1\TablasEvaluacionController;
use App\Http\Controllers\Api\V1\UsersController;
use App\Http\Controllers\Api\V1\VariablesController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes - Vocare v1
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {

    // -------------------------------------------------------------------------
    // Autenticación (pública)
    // -------------------------------------------------------------------------
    Route::prefix('auth')->group(function () {
        Route::post('login',    [AuthController::class, 'login'])->name('auth.login');
        Route::post('register', [AuthController::class, 'register'])->name('auth.register');
    });

    // -------------------------------------------------------------------------
    // Rutas protegidas con Sanctum
    // -------------------------------------------------------------------------
    Route::middleware('auth:sanctum')->group(function () {

        // Auth
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('me', [AuthController::class, 'me'])->name('auth.me');
        // Evidencias propias disponibles para reutilizar (excluye rechazadas)
        Route::get('me/evidencias', [EvidenciasController::class, 'misEvidencias'])->name('me.evidencias');

        // -------------------------------------------------------------------------
        // Usuarios y Roles (Sprint 4)
        // -------------------------------------------------------------------------
        Route::prefix('users')->group(function () {
            Route::get('/', [UsersController::class, 'index'])->name('users.index');
            Route::post('/', [UsersController::class, 'store'])->name('users.store');
            Route::get('{user}', [UsersController::class, 'show'])->name('users.show');
            Route::patch('{user}', [UsersController::class, 'update'])->name('users.update');
            Route::patch('{user}/desactivar', [UsersController::class, 'desactivar'])->name('users.desactivar');
        });
        Route::get('roles', [UsersController::class, 'roles'])->name('roles.index');

        // -------------------------------------------------------------------------
        // Tablas de Evaluación (Anexos) — CRUD admin con fork por versión
        // -------------------------------------------------------------------------
        Route::prefix('tablas-evaluacion')->group(function () {
            Route::get('/', [TablasEvaluacionController::class, 'index'])->name('tablas.index');
            Route::post('/', [TablasEvaluacionController::class, 'store'])->name('tablas.store');
            Route::get('{tablaEvaluacion}', [TablasEvaluacionController::class, 'show'])->name('tablas.show');
            Route::patch('{tablaEvaluacion}', [TablasEvaluacionController::class, 'update'])->name('tablas.update');
            Route::delete('{tablaEvaluacion}', [TablasEvaluacionController::class, 'destroy'])->name('tablas.destroy');
            Route::post('{tablaEvaluacion}/activar', [TablasEvaluacionController::class, 'activar'])->name('tablas.activar');

            // Rubros y Etapas (plantilla) anidados
            Route::post('{tablaEvaluacion}/rubros', [RubrosController::class, 'store'])->name('rubros.store');
            Route::post('{tablaEvaluacion}/etapas', [EtapasController::class, 'store'])->name('etapas.store');
        });

        Route::prefix('rubros')->group(function () {
            Route::patch('{rubro}', [RubrosController::class, 'update'])->name('rubros.update');
            Route::delete('{rubro}', [RubrosController::class, 'destroy'])->name('rubros.destroy');
            Route::post('{rubro}/variables', [VariablesController::class, 'store'])->name('variables.store');
        });

        Route::prefix('variables')->group(function () {
            Route::patch('{variable}', [VariablesController::class, 'update'])->name('variables.update');
            Route::delete('{variable}', [VariablesController::class, 'destroy'])->name('variables.destroy');
            Route::post('{variable}/indicadores', [IndicadoresController::class, 'store'])->name('indicadores.store');
        });

        Route::prefix('indicadores')->group(function () {
            Route::patch('{indicador}', [IndicadoresController::class, 'update'])->name('indicadores.update');
            Route::delete('{indicador}', [IndicadoresController::class, 'destroy'])->name('indicadores.destroy');
        });

        Route::prefix('etapas')->group(function () {
            Route::patch('{etapa}', [EtapasController::class, 'update'])->name('etapas.update');
            Route::delete('{etapa}', [EtapasController::class, 'destroy'])->name('etapas.destroy');
        });

        // -------------------------------------------------------------------------
        // Convocatorias
        // -------------------------------------------------------------------------
        Route::prefix('convocatorias')->group(function () {
            Route::get('/', [ConvocatoriasController::class, 'index'])->name('convocatorias.index');
            Route::post('/', [ConvocatoriasController::class, 'store'])->name('convocatorias.store');
            Route::get('{convocatoria}', [ConvocatoriasController::class, 'show'])->name('convocatorias.show');
            Route::patch('{convocatoria}', [ConvocatoriasController::class, 'update'])->name('convocatorias.update');
            Route::post('{convocatoria}/cerrar', [ConvocatoriasController::class, 'cerrar'])->name('convocatorias.cerrar');

            // Tabla de evaluación (snapshot o live)
            Route::get('{convocatoria}/tabla-evaluacion', [PlazasController::class, 'tablaEvaluacion'])->name('convocatorias.tabla');

            // Plazas anidadas
            Route::get('{convocatoria}/plazas', [PlazasController::class, 'index'])->name('plazas.index');
            Route::post('{convocatoria}/plazas', [PlazasController::class, 'store'])->name('plazas.store');

            // Asignación de evaluadores a postulaciones de esta convocatoria
            Route::post('{convocatoria}/asignaciones', [AsignacionesController::class, 'store'])->name('asignaciones.store');

            // Resultados (Sprint 6)
            Route::get('{convocatoria}/resultados', [ResultadosController::class, 'index'])->name('resultados.index');
            Route::post('{convocatoria}/resultados/publicar', [ResultadosController::class, 'publicar'])->name('resultados.publicar');
            Route::post('{convocatoria}/plazas/{plaza}/ranking', [ResultadosController::class, 'generarRanking'])->name('resultados.ranking');
            Route::post('{convocatoria}/plazas/{plaza}/desierta', [ResultadosController::class, 'declararDesierta'])->name('resultados.desierta');
            Route::post('{convocatoria}/plazas/{plaza}/resultados/desempatar', [ResultadosController::class, 'resolverEmpate'])->name('resultados.desempatar');

            // Reporte consolidado
            Route::get('{convocatoria}/reporte', [AuditoriaController::class, 'reporteConvocatoria'])->name('reportes.convocatoria');
        });

        // Plazas individuales
        Route::prefix('plazas')->group(function () {
            Route::get('{plaza}', [PlazasController::class, 'show'])->name('plazas.show');
            Route::patch('{plaza}', [PlazasController::class, 'update'])->name('plazas.update');
        });

        // -------------------------------------------------------------------------
        // Postulaciones
        // -------------------------------------------------------------------------
        Route::prefix('postulaciones')->group(function () {
            Route::get('/', [PostulacionesController::class, 'index'])->name('postulaciones.index');
            Route::post('/', [PostulacionesController::class, 'store'])->name('postulaciones.store');
            Route::get('{postulacion}', [PostulacionesController::class, 'show'])->name('postulaciones.show');
            Route::post('{postulacion}/enviar', [PostulacionesController::class, 'enviar'])->name('postulaciones.enviar');
            Route::patch('{postulacion}/estado', [PostulacionesController::class, 'actualizarEstado'])->name('postulaciones.estado');

            // Evidencias
            Route::get('{postulacion}/evidencias', [EvidenciasController::class, 'index'])->name('evidencias.index');
            Route::post('{postulacion}/evidencias', [EvidenciasController::class, 'store'])->name('evidencias.store');
            // Reutilizar evidencia existente en una nueva postulación (sin re-validación del archivo)
            Route::post('{postulacion}/evidencias/reutilizar', [EvidenciasController::class, 'reutilizar'])->name('evidencias.reutilizar');

            // Evaluación
            Route::post('{postulacion}/evaluacion', [EvaluacionesController::class, 'crear'])->name('evaluaciones.crear');

            // Etapas instanciadas para esta postulación (Clase Magistral, etc.)
            Route::get('{postulacion}/etapas', [PostulacionEtapasController::class, 'index'])->name('postulacion_etapas.index');

            // Resultado propio (postulante)
            Route::get('{postulacion}/resultado', [ResultadosController::class, 'miResultado'])->name('resultados.propio');
        });

        Route::patch('postulacion-etapas/{postulacionEtapa}', [PostulacionEtapasController::class, 'update'])->name('postulacion_etapas.update');

        // -------------------------------------------------------------------------
        // Evidencias individuales
        // -------------------------------------------------------------------------
        Route::prefix('evidencias')->group(function () {
            Route::get('{evidencia}/archivo', [EvidenciasController::class, 'descargar'])->name('evidencias.descargar');
            Route::patch('{evidencia}/validacion', [EvidenciasController::class, 'validar'])->name('evidencias.validar');
        });

        // -------------------------------------------------------------------------
        // Asignaciones de evaluador (Sprint 5)
        // -------------------------------------------------------------------------
        Route::prefix('asignaciones')->group(function () {
            Route::get('/', [AsignacionesController::class, 'index'])->name('asignaciones.index');
            Route::delete('{asignacion}', [AsignacionesController::class, 'destroy'])->name('asignaciones.destroy');
        });

        // -------------------------------------------------------------------------
        // Evaluaciones
        // -------------------------------------------------------------------------
        Route::prefix('evaluaciones')->group(function () {
            Route::get('/', [EvaluacionesController::class, 'index'])->name('evaluaciones.index');
            Route::get('{evaluacion}', [EvaluacionesController::class, 'show'])->name('evaluaciones.show');
            Route::get('{evaluacion}/desglose', [EvaluacionesController::class, 'desglose'])->name('evaluaciones.desglose');
            Route::post('{evaluacion}/puntajes', [EvaluacionesController::class, 'guardarPuntaje'])->name('evaluaciones.puntajes');
            Route::post('{evaluacion}/calcular', [EvaluacionesController::class, 'calcular'])->name('evaluaciones.calcular');
            Route::post('{evaluacion}/cerrar', [EvaluacionesController::class, 'cerrar'])->name('evaluaciones.cerrar');
        });

        // -------------------------------------------------------------------------
        // Auditoría y Reportes (Sprint 6)
        // -------------------------------------------------------------------------
        Route::get('auditoria', [AuditoriaController::class, 'index'])->name('auditoria.index');

    });
});
