# 🧠 CONTEXTO MAESTRO — Vocare

> **Instrucción para el agente IA:** Lee este archivo PRIMERO antes de cualquier
> cosa. Aquí está el estado completo del proyecto. Actualiza la sección "Última
> sesión" cada vez que termines de trabajar.

---

## 📌 ¿Qué es Vocare?

Sistema web para gestionar **convocatorias docentes** en una universidad
(contexto UCSM - Perú). Permite a postulantes subir evidencias, a evaluadores
calificar expedientes y a la comisión publicar resultados. El cálculo de
puntajes sigue reglas específicas del reglamento universitario (Anexos 1, 2, 3,
4.1, 6 y 7).

---

## 🏗️ Stack Técnico

| Capa          | Tecnología                                           |
| ------------- | ---------------------------------------------------- |
| Frontend      | Vue 3 + TypeScript + Vite                            |
| Backend API   | Laravel 13 (PHP 8.3+)                                |
| Base de datos | PostgreSQL 16 (SQLite en dev local, ojo ver bug)     |
| Cache/Colas   | Redis                                                |
| Auth          | Laravel Sanctum (tokens) + Spatie Laravel-Permission |
| Proxy         | Nginx                                                |
| Orquestación  | Docker Compose                                       |
| Archivos      | Disco local (`storage/expedientes/`) — NO S3         |

---

## 📁 Estructura de Directorios

```
vocare/
  apps/
    frontend/        Vue 3 + TS + Vite  (puerto 5173)
    api/             Laravel 13 API     (puerto 8000 vía Nginx)
  infra/
    nginx/default.conf
  storage/
    expedientes/     Archivos locales persistentes
  docker-compose.yml
  CONTEXTO.md        ← ESTE ARCHIVO
  SPRINTS.md         ← Roadmap de sprints
  plan.md            ← Plan técnico detallado
  d.md               ← Guía de arranque local
```

---

## 🔐 Credenciales de Desarrollo

| Servicio        | Detalle                                                                                                |
| --------------- | ------------------------------------------------------------------------------------------------------ |
| Admin API       | `admin@vocare.local` / `Admin1234!`                                                                    |
| PostgreSQL      | host `postgres`, puerto `5432` interno / `5433` host, db `vocare`, user `vocare`, pass `vocare_secret` |
| Redis           | `redis:6379` sin contraseña                                                                            |
| Frontend        | `http://localhost:5173`                                                                                |
| API (vía nginx) | `http://localhost:8000/api/v1/`                                                                        |

---

## 🎯 Estado Actual del Proyecto

### Sprints completados

- ✅ **Sprint 1** — Infraestructura Docker Compose, estructura, Nginx, `.env` base
- ✅ **Sprint 2** — Auth local (Sanctum), Roles/Permisos (Spatie), AuditLog, Seeders
- ✅ **Sprint 3** — Convocatorias + Motor de Tablas (backend completo, Fase 2-B lista)

### Sprint en curso

- 📂 **Sprint 4** — Portal del Postulante y Expedientes
  - Backend ✅ completo
  - Frontend postulante ✅ completo
  - Frontend evaluador ✅ corregido y funcional (2026-07-20)
- ✅ **Sprint 5** — Evaluación y Cálculo (completado 2026-07-21)
  - `periodo_validez_anios` integrado en `CalculadorService` ✅
  - Asignación de evaluadores (backend + frontend) ✅ — modelo
    `AsignacionEvaluador`, `AsignacionesController`, gate en
    `EvaluacionesController::crear`, pestaña "Asignaciones" en
    `ConvocatoriaDetalleView.vue`
  - `BandejaEvaluacionesView` muestra asignadas-sin-iniciar (con botón
    "Iniciar evaluación") además de las ya iniciadas ✅
  - Verificación completa contra requisitos-sistema.md y
    tablas-evaluacion-convocatorias.md ✅ (2026-07-21) — ver hallazgos abajo
  - Empate resuelto por decisión manual de comisión (no auto/sorteo) ✅
- 📊 **Sprint 6** — Resultados, Reportes y Cierre MVP (en curso, 2026-07-21)
  - Frontend de cierre de evaluación en `ResultadosView.vue` ✅ (generar
    ranking, declarar desierta, resolver empates — el backend ya existía sin
    ninguna forma de invocarlo desde la UI)
  - Reporte interno con desglose completo por sub-rubro/variable ✅
    (`AuditoriaController::reporteConvocatoria` + `CalculadorService::desglosar()`)
  - Vista de resultado propio del postulante (solo total/posición) ✅
    en `PostulacionDetalleView.vue`
  - Cobertura de auditoría completada (plazas, puntaje manual) ✅
  - Pendiente: hardening final y E2E (Playwright) — deuda técnica explícita,
    no abordado

### 🔎 Hallazgos de la verificación contra spec (2026-07-21)

- **Bug de seguridad corregido:** `PATCH /postulaciones/{id}/estado` no tenía
  `authorize()` — cualquier postulante podía marcar cualquier postulación
  como ganadora/rechazada. Corregido (`postulaciones.ver_todas`).
- **Bug de datos corregido:** `puntaje_total_max` de Anexo 1 estaba en 107.0
  (suma sin tope) en vez de 100.0 (con tope de sub-rubro, verificado en
  requisitos-sistema.md §7). Corregido en `AnexosSeeder`.
- **Empate:** el código decía "sorteo" pero no había aleatoriedad real (solo
  orden de inserción en BD). Rediseñado: los empates en posición
  ganador/reserva quedan `empate_pendiente` hasta que la comisión registra el
  orden manualmente vía `resolverEmpate()` / `POST .../resultados/desempatar`,
  con auditoría de quién y cuándo. `publicar()` bloquea si quedan empates
  pendientes.
- **Anexo 4.1 — discrepancia sin resolver:** el PDF fuente detallado (p.63)
  topa "Dictado de Clases y Responsabilidad Docente" en 8.0, pero la Ficha
  4.1 resumen (la que cuadra el total 100.0, y la transcrita en
  tablas-evaluacion-convocatorias.md) la topa en 9.0. Se mantiene 9.0
  (comentado en `AnexosSeeder`) — pendiente confirmación escrita del cliente.
- **`PUNTAJE_MINIMO_APROBATORIO=50` / `MAX_RESERVAS=3`:** confirmados
  verbalmente por el cliente, no están en los .md — comentado en
  `ResultadosService` para trazabilidad.
- **Cross-validación agregada:** `POST /convocatorias` ahora rechaza
  tipo_proceso/modalidad inconsistentes con la tabla de evaluación elegida.
- **Bug de infraestructura de tests — RESUELTO 2026-07-21.** `docker-compose.yml`
  fijaba `DB_CONNECTION=pgsql`/`DB_DATABASE=vocare` como variables de entorno
  reales del contenedor `api` (duplicando `apps/api/.env`), lo que
  **sobreescribía en silencio** cualquier config de test — con `sqlite`
  corrían (y con `RefreshDatabase`, reseteaban) la base de datos real de
  desarrollo. Decisión: el proyecto usa **PostgreSQL exclusivamente, sin
  SQLite en ningún punto** (ni siquiera para tests). Solución aplicada:
  - Se quitó el bloque `DB_*` redundante de `environment:` del servicio `api`
    en `docker-compose.yml` (ahora depende solo de `.env`, sin duplicarlo).
  - Se agregó una base de datos Postgres separada `vocare_test` en el mismo
    contenedor `postgres` (`infra/postgres/init-test-db.sql`, montado en
    `docker-entrypoint-initdb.d` — se ejecuta solo, en instalaciones nuevas;
    en el volumen ya existente se creó manualmente una vez).
  - `phpunit.xml` ahora apunta a `pgsql` / `vocare_test` en vez de sqlite.
  - `php artisan test` ya corre normal, sin flags extra, aislado de `vocare`.
  De paso se corrigió una migración (`2026_07_20_...rediseñar_evidencias...`)
  que tenía un bug latente (drop de columna con FK combinado con drop de
  índice en el mismo `Schema::table()` — inofensivo en Postgres pero
  revelado al validar contra Postgres real en la BD de test).
- **Pendiente de decisión, no bloqueante:** `Indicador` (tabla vacía —
  TABLA_EQUIVALENCIA sin rangos canónicos seedeados).

### 📐 Diseño Fase 1 — `Etapa` (2026-07-21, sin código todavía)

Contexto real aportado por el cliente: Clase Magistral es un evento presencial
(no un documento) — hay un gap temporal real entre Validación/CV y Clase
Magistral, el jurado puede ser gente distinta a quien revisó documentos, y el
resultado se transcribe al sistema después del hecho, no en vivo por quien lo
juzgó necesariamente.

**Gap técnico real encontrado durante el diseño:** hoy `CalculadorService`
solo permite entrada manual persistente para variables `TABLA_EQUIVALENCIA`
(vía `guardarPuntaje` → `tablaEquivalencia()`). Las variables `SUMA_CON_TOPE`
de "Demostración Magistral"/"Sesión de Prácticas" (Anexo 1/2) **no tienen
ningún camino de entrada hoy** — dependen de `Evidencia`, que una clase en
vivo no genera. Esto no es un problema nuevo del diseño de Etapa, es un hueco
ya existente que el diseño expone.

**Diseño propuesto (Fase 2, no implementado):**
- `Variable` gana un campo `fuente` (`evidencia` | `etapa`), ortogonal a
  `tipo_calculo` (que sigue gobernando cómo se topa/interpreta el número, no
  de dónde sale).
- Nuevo pivote `postulacion_etapa` (mismo patrón que `postulacion_evidencia`):
  `fecha_programada`/`fecha_realizada`, `estado` (pendiente|aprobada|
  observada|rechazada|no_presentado), `puntaje_bruto_evento`, `jurado_texto`
  (texto libre — confirmado con cliente: **un solo puntaje por
  rubro/etapa, sin desglose por jurado en el sistema** — no requiere cambio
  de esquema adicional), `comentario`, `registrado_por`.
- `AsignacionEvaluador` gana `etapa_id` nullable (constraint única pasa a
  `postulacion_id + evaluador_id + etapa_id`); `null` = asignado a toda la
  postulación (compatible con el comportamiento actual), un valor específico
  = jurado de esa etapa únicamente (ej. Clase Magistral con jurado distinto a
  quien validó documentos).
- **`Etapa` NO lleva un flag `es_eliminatoria` booleano.** Decisión del
  cliente: el reglamento (RES 9245-CU-2025) no usa el concepto de
  "eliminatoria" en ningún punto — no presentarse o sacar 0 en Clase
  Magistral no es un auto-rechazo hardcodeado, es una variable más que aporta
  0 si no tiene puntaje. La exclusión se maneja con los mínimos existentes
  (ver abajo), no con una bandera nueva.

**Hallazgo del cliente sobre mínimos (pendiente de confirmar, ver abajo):**
`ResultadosService::PUNTAJE_MINIMO_APROBATORIO = 50` es un único constante
global, pero el reglamento fuente define, por anexo, un **mínimo total +
un mínimo de sub-rubro separado**:
- Anexo 1: mínimo total 55, mínimo 20 en "Aptitud Docente" (rollup que suma
  "Elaboración del Sílabo" + "Demostración Magistral" — **no** son dos
  sub-rubros independientes con su propio mínimo; `tablas-evaluacion-
  convocatorias.md` los lista como sub-rubros separados sin ninguna mención
  de "Aptitud Docente", así que este rollup no está en ningún `.md` del
  proyecto).
- Anexo 2: mínimo total 52, sub-mínimo 18 en Aptitud Docente.
- Anexo 3: mínimo total 60, sub-mínimo 18 en "Concurso de Oposición"
  (incluye Clase Magistral).
- Ninguno de estos tres números coincide con el 50 actual, y hoy no existe
  ningún chequeo de mínimo de sub-rubro en el motor.
- **Diseño (cuando se confirme):** los mínimos dejan de ser una constante de
  código y pasan a vivir en `tabla_snapshot`, junto a `puntaje_max`/
  `puntaje_max_subrubro` (son config del anexo, no una constante global). El
  mínimo de sub-rubro necesita poder referenciar un **grupo de rubro_ids**
  (por el caso "Aptitud Docente"), no solo un rubro individual.

**Bloqueado hasta que el cliente confirme (no tocar código/seed mientras tanto):**
1. Si 55/52/60 + los sub-mínimos son los valores vigentes — el sistema corrió
   con 50 sin reclamos, así que el cliente debe confirmar si 50 fue elegido a
   propósito para algún anexo aún no revisado, o si de verdad hay que
   moverse a mínimos por anexo.
2. Si el requisito de mínimo de sub-rubro sigue vigente en esta versión del
   TUO (V10, Junio 2025).
3. Si Anexo 2 y Anexo 3 también son rollups sobre sub-rubros existentes o
   mínimos de un solo rubro (solo se confirmó el caso de Anexo 1).

### Sprints pendientes

- ⚖️ Sprint 5 — Evaluación y Cálculo
- 📊 Sprint 6 — Resultados, Reportes y Cierre MVP

---

## ✅ Lo que YA está implementado (código real, no solo planificado)

### Backend (Laravel API)

**Modelos creados** (18 modelos):

- `User`, `AuditLog`, `Convocatoria`, `Plaza`, `Etapa`, `ReglamentoVersion`
- `TablaEvaluacion`, `Rubro`, `Variable`, `Indicador`
- `Postulacion`, `Expediente`, `CvSnapshot`, `Evidencia`, `PostulacionEvidencia`
- `Evaluacion`, `Puntaje`, `Resultado`

**Controllers API (v1)** — todos bajo `/api/v1/`:

- `AuthController` — login, register, logout, me
- `UsersController` — CRUD usuarios + desactivar + roles
- `ConvocatoriasController` — CRUD + cerrar + snapshot en store() (Fase 2-B)
- `PlazasController` — CRUD plazas anidadas en convocatoria
- `TablasEvaluacionController` — index + show (solo lectura)
- `PostulacionesController` — CRUD + enviar + actualizarEstado
- `EvidenciasController` — subir archivo + reutilizar + descargar + validar +
  misEvidencias
- `EvaluacionesController` — **index** + crear + show + guardarPuntaje +
  calcular + cerrar + desglose
- `ResultadosController` — generarRanking + index + miResultado + publicar +
  declararDesierta
- `AuditoriaController` — index + reporteConvocatoria

**Servicios**:

- `AuditService` — registra cambios críticos con usuario/fecha/entidad/old/new
- `CalculadorService` — motor de cálculo: SUMA_CON_TOPE, MAYOR_VALOR,
  TABLA_EQUIVALENCIA, DATO_INSTITUCIONAL (tope a 2 niveles: variable y
  sub-rubro)
- `ResultadosService` — genera ranking, resuelve empates (random), puntaje
  mínimo 50pts, max 3 reservas

**Migrations** (13 archivos):

- Users, cache, jobs (base Laravel)
- Campos DNI + is_active a users, audit_logs
- Spatie permission tables, personal_access_tokens
- tablas_evaluacion schema (reglamento_versiones, tablas_evaluacion, rubros,
  variables, indicadores)
- convocatorias, plazas, etapas
- postulaciones, expedientes, evidencias
- evaluaciones, puntajes
- resultados
- add_indicador_to_evidencias
- **rediseñar_evidencias_reutilizables** ← crea `postulacion_evidencia`, mueve
  `user_id` a evidencias, elimina `expediente_id` y `reutilizada`

**Seeders**:

- `RolesAndPermissionsSeeder` — 3 roles (postulante, evaluador, admin), 29
  permisos
- `AdminUserSeeder` — usuario `admin@vocare.local`
- `AnexosSeeder` — datos de los Anexos 6 y 7 del reglamento (35KB de seed)

**Rutas API** — 33+ endpoints definidos en `routes/api.php`

### Frontend (Vue 3 + TS)

**Vistas creadas**:

- `LoginView.vue`, `RegisterView.vue`
- `DashboardView.vue` — dashboard general por rol
- `admin/UsuariosView.vue` — gestión de usuarios (CRUD con roles)
- `admin/AuditoriaView.vue` — vista de logs de auditoría
- `convocatorias/ConvocatoriasView.vue` — lista con filtro por estado
- `convocatorias/ConvocatoriaFormView.vue` — formulario nueva convocatoria
- `convocatorias/ConvocatoriaDetalleView.vue` — detalle con plazas + tabla
  evaluación
- `convocatorias/ResultadosView.vue` — resultados por convocatoria
- `evaluador/BandejaEvaluacionesView.vue` — usa `GET /evaluaciones` (corregido
  2026-07-20)
- `evaluador/EvaluacionDetalleView.vue` — usa `postulacion_evidencias`, vigencia
  por fila, modal validación (corregido 2026-07-20)
- `postulante/MisPostulacionesView.vue` — lista + modal Nueva postulación
- `postulante/PostulacionDetalleView.vue` — detalle + botón Enviar
- `postulante/ExpedienteView.vue` — evidencias con vigencia + modal Reutilizar

**Stores (Pinia)**:

- `auth.ts` — token, usuario, roles, login/logout/fetchMe con localStorage

**Router** (`/src/router/index.ts`):

- Guard global: redirige a login si no autenticado
- Guard por rol: `meta.roles` con redirección a dashboard si sin acceso
- Rutas lazy-loaded

**Servicios frontend**:

- `api.ts` — instancia Axios con baseURL y Authorization header

---

## 🚨 Brechas contra Spec (bloqueantes — no avanzar de sprint sin resolver)

1. ~~**Evidencias no son reutilizables entre postulaciones (viola sección 10 de
   requisitos).** RESUELTO en Sprint 3 (2026-07-20).~~
   - Nuevo esquema: `evidencias` → FK a `user_id`; tabla pivote
     `postulacion_evidencia` con vigencia recalculada contra
     `convocatorias.fecha_inicio`.
   - `reutilizada` (boolean decorativo) eliminado.
   - Tres endpoints nuevos: `misEvidencias()`, `reutilizar()`, y `validar()`
     actualizado.

2. ~~**`generarSnapshot()` se dispara en publicar(), no en store().**~~
   **RESUELTO (Fase 2-B) en 2026-07-20.**
   - El snapshot se genera en `store()` al crear la convocatoria.

3. ~~**`periodo_validez_anios` no se usa en CalculadorService.**~~
   **RESUELTO 2026-07-21.**
   - `evidenciasAprobadasDeVariable()` ahora consulta `postulacion_evidencia`
     y exige `estado_en_postulacion=aprobada` **y** `vigente=true` (el pivote
     ya calculaba vigencia al asociar la evidencia, pero el calculador la
     ignoraba y leía `Evidencia::estado` global en su lugar). Tests en
     `tests/Feature/Services/CalculadorServiceVigenciaTest.php`.

## 🧹 Deuda técnica cosmética (no bloqueante)

4. `APP_KEY` vacío en `.env` — documentar en `d.md`.
5. Empate se resuelve sin `random()` real — aceptable para MVP.

> **Nota importante — pluralización PostgreSQL:**
> Laravel pluraliza modelos en inglés. Los modelos con nombre en español
> que NO terminan igual en inglés deben declarar `protected $table` explícito.
> Ya corregidos: `Evaluacion` → `evaluaciones`, `Postulacion` → `postulaciones`,
> `Indicador` → `indicadores`. Si se crea un nuevo modelo con nombre en
> español, agregar `$table` inmediatamente.

## 📋 Próximos Pasos (Sprint 5)

- [ ] Lógica de asignación de expedientes a evaluadores (actualmente el
      evaluador se autoasigna vía `POST /postulaciones/{id}/evaluacion`)
- [ ] Integrar `periodo_validez_anios` en `CalculadorService`
- [ ] Frontend admin: CRUD de convocatorias (vistas base ya existen, revisar
      flujo completo)
- [ ] Unit tests del motor de cálculo
- [ ] Resolver inconsistencia de roles (3 vs 6)
- [ ] Agregar volumen para `storage/expedientes` en `docker-compose.yml`
- [ ] Verificar existencia de vistas de convocatorias en frontend

---

## 🗺️ Mapa de Endpoints API

```
POST   /api/v1/auth/login
POST   /api/v1/auth/register
POST   /api/v1/auth/logout            [auth]
GET    /api/v1/me                     [auth]
GET    /api/v1/me/evidencias          [auth] ← selector de reutilización

GET    /api/v1/users                  [auth + usuarios.ver]
POST   /api/v1/users                  [auth + usuarios.crear]
GET    /api/v1/users/{id}             [auth + usuarios.ver]
PATCH  /api/v1/users/{id}             [auth + usuarios.editar]
PATCH  /api/v1/users/{id}/desactivar  [auth + usuarios.desactivar]
GET    /api/v1/roles                  [auth + usuarios.crear]

GET    /api/v1/tablas-evaluacion
GET    /api/v1/tablas-evaluacion/{id}

GET    /api/v1/convocatorias
POST   /api/v1/convocatorias          [convocatorias.crear]
GET    /api/v1/convocatorias/{id}
PATCH  /api/v1/convocatorias/{id}     [convocatorias.editar]
POST   /api/v1/convocatorias/{id}/cerrar
GET    /api/v1/convocatorias/{id}/tabla-evaluacion
GET    /api/v1/convocatorias/{id}/plazas
POST   /api/v1/convocatorias/{id}/plazas
GET    /api/v1/convocatorias/{id}/resultados
POST   /api/v1/convocatorias/{id}/resultados/publicar
POST   /api/v1/convocatorias/{id}/plazas/{plaza}/ranking
POST   /api/v1/convocatorias/{id}/plazas/{plaza}/desierta
GET    /api/v1/convocatorias/{id}/reporte

GET    /api/v1/plazas/{id}
PATCH  /api/v1/plazas/{id}

GET    /api/v1/postulaciones
POST   /api/v1/postulaciones          [postulaciones.crear]
GET    /api/v1/postulaciones/{id}
POST   /api/v1/postulaciones/{id}/enviar
PATCH  /api/v1/postulaciones/{id}/estado
GET    /api/v1/postulaciones/{id}/evidencias
POST   /api/v1/postulaciones/{id}/evidencias      [multipart, max 10MB — archivo nuevo]
POST   /api/v1/postulaciones/{id}/evidencias/reutilizar  ← evidencia existente del postulante
POST   /api/v1/postulaciones/{id}/evaluacion
GET    /api/v1/postulaciones/{id}/resultado

GET    /api/v1/evidencias/{id}/archivo
PATCH  /api/v1/evidencias/{id}/validacion         [requiere postulacion_id en body]

GET    /api/v1/evaluaciones/{id}
GET    /api/v1/evaluaciones/{id}/desglose
POST   /api/v1/evaluaciones/{id}/puntajes
POST   /api/v1/evaluaciones/{id}/calcular
POST   /api/v1/evaluaciones/{id}/cerrar

GET    /api/v1/auditoria
```

---

## 📐 Reglas de Cálculo (Importante para entender el dominio)

```
1. SUMA_CON_TOPE:
   suma de evidencias aprobadas → min(suma, puntaje_max_variable)

2. MAYOR_VALOR:
   toma el puntaje_indicador más alto entre evidencias aprobadas

3. TABLA_EQUIVALENCIA:
   valor_entrada (ej: nota 17.5) → busca rango en tabla del indicador → retorna puntaje fijo

4. DATO_INSTITUCIONAL:
   igual que SUMA_CON_TOPE pero la fuente la sube el propio postulante (no institución)

Tope de Sub Rubro (2do nivel):
   puntaje_rubro = sum(variables) → min(puntaje_rubro, puntaje_max_subrubro)

Puntaje mínimo para no declarar plaza desierta: 50 puntos
Máximo reservas: 3
```

---

## 📦 Comandos Útiles

```bash
# Levantar todo
docker compose up -d

# Migrar y seed
docker compose exec api php artisan migrate
docker compose exec api php artisan db:seed

# Solo un seeder
docker compose exec api php artisan db:seed --class=AnexosSeeder

# Tests
docker compose exec api php artisan test

# Logs API
docker compose logs api -f

# Acceder a tinker
docker compose exec api php artisan tinker
```

---

## 📝 Historial de Sesiones

> Añadir una entrada cada vez que se termina una sesión de trabajo.

| Fecha      | Lo que se hizo                                                                                                                                                                                                                | Próximo paso                                              |
| ---------- | ----------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------- |
| 2026-07-04 | Sprint 1 completo: Docker Compose, estructura, Nginx, base Laravel+Vue                                                                                                                                                        | Sprint 2                                                  |
| 2026-07-04 | Sprint 2 completo: Auth, Roles, Permisos, Migrations, Seeders, Controllers base                                                                                                                                               | Sprint 3                                                  |
| 2026-07-05 | Audit completo del repo, creación de CONTEXTO.md                                                                                                                                                                              | Bugs críticos + Sprint 3                                  |
| 2026-07-05 | Fix bugs críticos: puntaje_indicador (migración + modelo + controller), volumen expedientes en Docker, roles inconsistentes en router y SPRINTS.md                                                                            | Sprint 3 frontend (CRUD convocatorias)                    |
| 2026-07-14 | Auditoría de código real contra spec: evidencias no reutilizables (campo `reutilizada` decorativo), snapshot se genera en publicar() no en create(). Decisión: pausar Sprint 4 hasta rediseñar modelo evidencias.             | Diseñar evidencias_maestro + pivote postulacion_evidencia |
| 2026-07-20 | Fase 1 (diseño) + Fase 2 (implementación) del rediseño de evidencias: migración, modelo PostulacionEvidencia, Evidencia/Expediente/Postulacion actualizados, EvidenciasController reescrito, rutas nuevas. Brecha #1 cerrada. | Sprint 4 — Portal del Postulante                          |
| 2026-07-21 | Sprint 5: (1) Fix CalculadorService para leer vigencia/aprobación por `postulacion_evidencia` en vez del estado global de Evidencia (brecha #3 cerrada). (2) Asignación de evaluadores: modelo AsignacionEvaluador, AsignacionesController (index/store/destroy), permisos nuevos, gate en `POST /postulaciones/{id}/evaluacion` (evaluador ya no se auto-asigna). 10 tests nuevos, seeders re-corridos en dev. | Frontend de asignación (admin) + bandeja evaluador con asignadas pendientes |
| 2026-07-21 | Sprint 6: frontend de cierre de evaluación en ResultadosView (generar ranking, declarar desierta, resolver empates — backend ya existía sin UI); reporte interno con desglose completo (`CalculadorService::desglosar()` extraído y reutilizado); vista de resultado propio del postulante; cobertura de auditoría completada (plazas, puntaje manual). Se agrega `CLAUDE.MD`: sin atribución de IA en commits, el asistente propone comandos de git en vez de commitear directamente. | Sprint 6 — hardening y E2E (Playwright), diferido |
| 2026-07-21 | Cierre de Sprint 5: pestaña "Asignaciones" en ConvocatoriaDetalleView (admin asigna/quita evaluadores por postulación enviada) y BandejaEvaluacionesView actualizada para mostrar asignadas-sin-iniciar con botón "Iniciar evaluación" — requirió exponer `postulacion.evaluacion` en `GET /asignaciones`. | Sprint 6 — hardening y E2E (Playwright), único pendiente |
| 2026-07-21 | Fix de las 6 fallas recurrentes de la suite (logout sin null-safe, APP_KEY vacío, RolesPermissionsTest probando el modelo de 6 roles abandonado) — suite en 0 fallas. Se agrega `CLAUDE.MD`: sin atribución de IA, el asistente propone comandos de git en vez de commitear. Bug de autorización: `show()`/`calcular()`/`cerrar()`/`guardarPuntaje()` de EvaluacionesController solo verificaban el permiso general, no que el evaluador fuera el dueño de la evaluación — corregido en los 4 endpoints. Diseño Fase 1 de `Etapa` discutido (ver sección dedicada) — bloqueado en confirmación del cliente sobre mínimos por anexo. | Sprint 6 — E2E, bloqueado hasta que se confirme el diseño de Etapa/mínimos |

---

## ⚠️ Deuda Técnica Explícita

- Antivirus de archivos subidos (pendiente por diseño)
- Blue-green deployment documentado para producción
- CI/CD: lint, tests backend, build frontend
- Playwright E2E tests
- Rate limiting en endpoints de auth
- Validación de antigüedad de evidencias (`periodo_validez_anios` existe en DB
  pero no se valida en el motor de cálculo — pendiente tras rediseño de
  evidencias)
- Límite global de almacenamiento por usuario (distinto al límite 200MB por
  expediente que ya existe). Ticket: evitar que un postulante acumule gigas de
  archivos a lo largo de años de postulaciones. `total_bytes` en `expedientes`
  es por postulación; un futuro `storage_bytes` en `users` sería el límite
  global.
- `generarSnapshot()` debe moverse a `store()` de ConvocatoriasController (Fase
  2-B).
- Password reset / recuperación de contraseña (ruta no implementada)
- SSO universitario (fuera del MVP)
