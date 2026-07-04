<?php

use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\ConvocatoriasController;
use App\Http\Controllers\Api\V1\EvidenciasController;
use App\Http\Controllers\Api\V1\PlazasController;
use App\Http\Controllers\Api\V1\PostulacionesController;
use App\Http\Controllers\Api\V1\TablasEvaluacionController;
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
        Route::post('login', [AuthController::class, 'login'])->name('auth.login');
    });

    // -------------------------------------------------------------------------
    // Rutas protegidas con Sanctum
    // -------------------------------------------------------------------------
    Route::middleware('auth:sanctum')->group(function () {

        // Auth
        Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::get('me', [AuthController::class, 'me'])->name('auth.me');

        // -------------------------------------------------------------------------
        // Tablas de Evaluación (Anexos) — lectura para todos los roles
        // -------------------------------------------------------------------------
        Route::prefix('tablas-evaluacion')->group(function () {
            Route::get('/', [TablasEvaluacionController::class, 'index'])->name('tablas.index');
            Route::get('{tablaEvaluacion}', [TablasEvaluacionController::class, 'show'])->name('tablas.show');
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

            // Plazas anidadas bajo convocatoria
            Route::get('{convocatoria}/plazas', [PlazasController::class, 'index'])->name('plazas.index');
            Route::post('{convocatoria}/plazas', [PlazasController::class, 'store'])->name('plazas.store');
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

            // Evidencias anidadas
            Route::get('{postulacion}/evidencias', [EvidenciasController::class, 'index'])->name('evidencias.index');
            Route::post('{postulacion}/evidencias', [EvidenciasController::class, 'store'])->name('evidencias.store');
        });

        // -------------------------------------------------------------------------
        // Evidencias individuales
        // -------------------------------------------------------------------------
        Route::prefix('evidencias')->group(function () {
            Route::get('{evidencia}/archivo', [EvidenciasController::class, 'descargar'])->name('evidencias.descargar');
            Route::patch('{evidencia}/validacion', [EvidenciasController::class, 'validar'])->name('evidencias.validar');
        });

    });
});
