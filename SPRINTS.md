# Roadmap y Sprints de Desarrollo - Vocare

Este documento rastrea el progreso del desarrollo paso a paso, diseñado para
mantener el contexto claro entre sesiones de trabajo y dividir la carga de
manera eficiente.

## Estado General

- **Estado Actual:** 🟢 Sprint 5 completo. Sprint 6 en curso — cierre de evaluación (ranking/desierta/empate), reportes internos con desglose, vista de resultado del postulante, y cobertura de auditoría completados 2026-07-21
- **Base de datos:** PostgreSQL exclusivamente (migraciones y seeders aplicados 2026-07-20; seeder de roles/permisos re-corrido 2026-07-21 para `asignaciones.*`)
- **Tests:** PostgreSQL exclusivo (sin SQLite) — corren contra `vocare_test`, aislada de `vocare`. `docker compose exec api php artisan test` normal, sin flags extra (fix aplicado 2026-07-21, ver CONTEXTO.md). Suite en 0 fallas desde 2026-07-21.
- **Git:** ver `CLAUDE.MD` en la raíz — sin atribución de IA en commits, y el asistente propone comandos de git para que el usuario los ejecute (no commitea de forma autónoma).
- **CRUD admin de tablas de evaluación + Etapa — Fase 2 IMPLEMENTADA
  (2026-07-21).** Backend funcional: `ReglamentoVersion` → `TablaEvaluacion`
  → `Rubro` → `Variable` → `Indicador` con fork por anexo individual, ciclo
  de vida borrador/activo/archivado, índice único parcial a nivel de BD,
  linaje vía `version_anterior_id`; `Etapa` como plantilla de
  `TablaEvaluacion` + `postulacion_etapa` operativo; `CalculadorService`
  soporta `fuente='etapa'` (Clase Magistral); `ResultadosService` lee
  mínimos (total + sub-rubro) desde `tabla_snapshot`. 86/86 tests passing.
  **Falta el frontend admin** — todo esto es solo API por ahora.
- **Bloqueado (solo del lado del cliente):** números 55/52/60 + sub-mínimos
  de "Aptitud Docente" (confirmado como rollup en Anexo 1/2; Anexo 3 resuelto
  como rubro único), vigencia del requisito de sub-rubro en TUO V10,
  discrepancia Anexo 4.1 (8 vs 9). El motor ya soporta estos mínimos — solo
  falta que un admin los configure una vez confirmados (sin código
  adicional). E2E se mantiene en espera a propósito.
- **Próxima Acción:** frontend admin para el CRUD de tablas de evaluación, o
  cargar los mínimos reales en cuanto el cliente confirme.

---

## ✅ Sprint 1: Base Técnica e Infraestructura — COMPLETADO

**Objetivo:** Tener el entorno de desarrollo local funcional con todos los
servicios orquestados por Docker.

- [x] Crear estructura de directorios (`apps/frontend`, `apps/api`,
      `infra/nginx`, `storage/expedientes`).
- [x] Inicializar proyecto Laravel 13 en `apps/api`.
- [x] Inicializar proyecto Vue 3 + TS + Vite en `apps/frontend`.
- [x] Crear `docker-compose.yml` para los servicios: `postgres`, `redis`, `api`,
      `worker`, `scheduler`, `frontend` y `nginx`.
- [x] Configurar configuración básica de Nginx para el proxy inverso.
- [x] Crear `.env` base de Laravel apuntando a postgres y redis del compose.
- [x] Crear `.gitignore` raíz (ignora `.md` excepto `README.md`).
- [ ] Validar que todos los contenedores levanten y se comuniquen correctamente
      (`docker compose up --build`).

## ✅ Sprint 2: Identidad, Roles y Permisos — COMPLETADO

**Objetivo:** Implementar la autenticación y la estructura de seguridad de la
API.

- [x] Instalar `laravel/sanctum` y `spatie/laravel-permission`.
- [x] Agregar campos `dni` e `is_active` a `users`; crear tabla `audit_logs`.
- [x] Actualizar modelo `User` con `HasApiTokens` y `HasRoles`.
- [x] Crear `AuditLog` model y `AuditService`.
- [x] Implementar autenticación local: `POST /api/v1/auth/login`,
      `POST /api/v1/auth/logout`, `GET /api/v1/me`.
- [x] Registrar middleware de roles/permisos de Spatie en `bootstrap/app.php`.
- [x] Seeder con **3 roles MVP** (`postulante`, `evaluador`, `admin`) y 29
      permisos.
  > **Decisión MVP:** `comision`, `admin_convocatoria`, `admin_sistema` y
  > `auditor` se unifican en el rol `admin`. Router y guards ya reflejan esto.
- [x] Seeder de usuario admin inicial (`admin@vocare.local`).
- [x] Feature tests: `AuthTest` y `RolesPermissionsTest`.

## ✅ Sprint 3: Convocatorias y Motor de Tablas — COMPLETADO

**Objetivo:** Gestionar convocatorias, plazas y la configuración inmutable de
las tablas de evaluación (Anexos).

- [x] CRUD de `convocatorias`, `plazas` y `etapas` (backend).
- [x] Modelo versionado de `reglamento_versiones`, `tablas_evaluacion`,
      `rubros`, `variables`, `indicadores`.
- [x] Seeders iniciales para los Anexos 1, 2, 3, 4.1, 6 y 7 (con sus reglas
      de cálculo y topes).
- [x] **Gate de salida del sprint:** modelo de evidencias rediseñado para
      soportar reutilización — `postulacion_evidencia` + vigencia recalculada
      por convocatoria. **COMPLETADO 2026-07-20.**
- [x] `generarSnapshot()` movido a `store()` de `ConvocatoriasController`
      (Fase 2-B — **COMPLETADO 2026-07-20**). El snapshot se genera al crear.

## 📂 Sprint 4: Portal del Postulante y Expedientes — EN CURSO

**Objetivo:** Permitir a los docentes registrarse, postularse y cargar
evidencias. **Depende de:** gate de evidencias de Sprint 3 → ✅ RESUELTO.

### Backend (completo)

- [x] Entidades de `postulaciones`, `expedientes`, `cv_snapshots` y
      `evidencias` — modelos, migrations, controllers.
- [x] API para carga local de archivos (`multipart/form-data`) con validación
      (MIME, tamaño 10MB, guardado de hash y ruta).
- [x] API para reutilización de evidencias entre postulaciones —
      `POST /postulaciones/{id}/evidencias/reutilizar` + `GET /me/evidencias`.
- [x] `GET /api/v1/evaluaciones` (index) — **nuevo 2026-07-20**. Evaluador ve
      sus evaluaciones; admin ve todas. Con filtros por `convocatoria_id` y
      `estado`.

### Frontend postulante (completo)

- [x] `MisPostulacionesView` — lista con badge Borrador/enviada, modal inline
      "Nueva postulación" (selector convocatoria → plaza → categoría).
      **Implementado 2026-07-20.**
- [x] `PostulacionDetalleView` — detalle de postulación con botón "Enviar
      formalmente" y acceso al expediente. **Nuevo — 2026-07-20.**
- [x] `ExpedienteView` — adaptado al nuevo modelo `postulacion_evidencia`:
      vigencia por fila (Vigente/Vencida/Sin fecha), `estado_en_postulacion`,
      modal "Reutilizar documento existente". **Implementado 2026-07-20.**

### Frontend evaluador (completo)

- [x] `BandejaEvaluacionesView` — corregida 2026-07-20.
      **Bug original:** llamaba `GET /postulaciones` (sin `evaluacion` en eager
      load) → el spinner giraba infinitamente sin error visible.
      **Fix:** usa `GET /evaluaciones`, captura errores con bloque try/catch,
      muestra mensaje descriptivo si falla.
- [x] `EvaluacionDetalleView` — corregida 2026-07-20.
      **Bug original:** usaba `expediente.evidencias` (cadena rota tras
      rediseño del modelo) → excepción silenciosa bloqueaba `finally`, spinner
      permanente.
      **Fix:** usa `postulacion_evidencias` (nuevo modelo), vigencia por fila,
      modal de validación de evidencias integrado, `try/catch` en todas las
      llamadas async.

### Base de datos — Fix crítico PostgreSQL (2026-07-20)

- [x] Corregida pluralización incorrecta de modelos en español.
      Laravel pluraliza en inglés: `Evaluacion` → `evaluacions` (incorrecto).
      **Modelos corregidos con `protected $table`:**
      - `Evaluacion` → `evaluaciones`
      - `Postulacion` → `postulaciones`
      - `Indicador` → `indicadores`
- [x] Migraciones y seeders ejecutados en PostgreSQL (`vocare` DB).
      Roles, permisos, usuario admin y Anexos 1–7 disponibles.

## ⚖️ Sprint 5: Evaluación y Cálculo

**Objetivo:** Implementar la interfaz del evaluador y el núcleo del motor de
cálculo de Vocare.

- [x] Lógica de asignación de expedientes a evaluadores/comisión — **backend
      completo 2026-07-21.** Modelo `AsignacionEvaluador` (tabla
      `asignaciones_evaluador`, ya existía sin usar), `AsignacionesController`
      (`index`/`store`/`destroy`), permisos `asignaciones.gestionar` /
      `asignaciones.ver`. `POST /postulaciones/{id}/evaluacion` ahora exige
      asignación previa para el rol `evaluador` (ya no hay auto-asignación) —
      403 `EVALUADOR_NO_ASIGNADO` si no está asignado. Admin no requiere
      asignación previa.
      - [x] Frontend admin para gestionar asignaciones — **completo
        2026-07-21.** Nueva pestaña "Asignaciones" en
        `ConvocatoriaDetalleView.vue` (solo `canManage`): lista postulaciones
        enviadas de la convocatoria, evaluadores asignados por postulación, y
        selector para asignar/quitar.
      - [x] `BandejaEvaluacionesView` actualizada — **completo 2026-07-21.**
        Ahora muestra dos secciones: asignaciones sin evaluación creada
        todavía (con botón "Iniciar evaluación") y evaluaciones ya iniciadas
        (como antes). Requirió exponer `postulacion.evaluacion` en
        `GET /asignaciones` (antes no permitía distinguir "asignada, sin
        iniciar" de "ya iniciada").
- [x] Frontend: Bandeja del evaluador, validación de evidencias
      (rechazo/observación) — ya estaba implementado desde el fix de
      `EvaluacionDetalleView` (2026-07-20): modal de validación que opera
      sobre `postulacion_evidencia` (`estado_en_postulacion`), no sobre la
      evidencia maestra directamente. No estaba marcado como hecho.
- [x] Backend: Motor de cálculo con reglas (`SUMA_CON_TOPE`, `MAYOR_VALOR`,
      `TABLA_EQUIVALENCIA`) y topes de dos niveles (variable y sub-rubro).
- [x] Integrar `periodo_validez_anios` en `CalculadorService` —
      **COMPLETADO 2026-07-21.** `evidenciasAprobadasDeVariable()` ahora lee
      por `postulacion_evidencia` (estado_en_postulacion=aprobada **y**
      vigente=true) en lugar del estado global de `Evidencia`; antes ignoraba
      vigencia y aprobación por postulación.
- [x] Unit/feature tests críticos para el motor de cálculo — 3 tests de
      vigencia (`CalculadorServiceVigenciaTest`) + 7 tests de asignación
      (`AsignacionEvaluadorTest`), además de los 15 ya existentes.

## 📊 Sprint 6: Resultados, Reportes y Cierre MVP — EN CURSO (2026-07-21)

**Objetivo:** Finalizar el proceso de evaluación, mostrar resultados y pulir el
sistema.

- [x] Lógica de cierre de evaluación (empate, plaza desierta, selección de
      ganador). Backend ya existía (Sprint 5); agregado el frontend que
      faltaba en `ResultadosView.vue`: generar/recalcular ranking por plaza,
      declarar desierta, y resolución manual de empates (reordenar +
      confirmar) — antes no había ninguna forma de invocar estos endpoints
      desde la UI.
- [x] Reportes internos completos con desglose de puntaje para uso
      administrativo. `AuditoriaController::reporteConvocatoria` incluía solo
      totales y todavía referenciaba el campo `empate_resuelto_por_sorteo`
      (eliminado); ahora incluye el desglose completo por sub-rubro/variable
      de cada ganador (`CalculadorService::desglosar()`, extraído y
      reutilizado también por `EvaluacionesController::desglose`).
- [x] Vista del postulante restringida (visualización exclusiva del puntaje
      total, sin desglose). No existía ninguna vista que consumiera
      `GET /postulaciones/{id}/resultado` — agregada en
      `PostulacionDetalleView.vue` (solo total/posición/estado, sin acceso al
      desglose).
- [x] Auditoría: asegurar que las acciones críticas quedan registradas (quién,
      cuándo, qué cambió). Auditoría de cobertura sobre todos los
      controllers: faltaba en `PlazasController::store/update` y
      `EvaluacionesController::guardarPuntaje` (puntaje manual sin rastro) —
      agregado.
- [ ] Hardening final y pruebas de extremo a extremo (E2E). Sigue diferido
      (deuda técnica explícita, ver sección correspondiente) — no abordado en
      esta sesión.
