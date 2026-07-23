<script setup lang="ts">
import { ref, reactive, computed, onMounted, onBeforeUnmount, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '@/services/api'
import Icon from '@/components/ui/Icon.vue'

const route  = useRoute()
const router = useRouter()
const id     = route.params.id

const evaluacion       = ref<any>(null)
const postulacionEtapas = ref<any[]>([])
const loading           = ref(true)
const error             = ref('')
const cerrando          = ref(false)
const mostrarResumenCierre = ref(false)

// ── Construcción de "pasos" (una variable a la vez, nunca tabla plana) ──────
const pasos = computed(() => {
  const snapshot = evaluacion.value?.postulacion?.convocatoria?.tabla_snapshot
  if (!snapshot) return []

  const pivotes = evaluacion.value.postulacion.postulacion_evidencias ?? []
  const puntajes = evaluacion.value.puntajes ?? []
  const lista: any[] = []

  snapshot.rubros.forEach((rubro: any, rubroIdx: number) => {
    rubro.variables.forEach((variable: any) => {
      lista.push({
        rubroNombre: rubro.nombre,
        rubroIndex: rubroIdx + 1,
        rubroTotal: snapshot.rubros.length,
        variable,
        evidencias: pivotes.filter((p: any) => p.evidencia?.variable_id === variable.id),
        puntajeRow: puntajes.find((p: any) => p.variable_id === variable.id) ?? null,
        postulacionEtapa: variable.fuente === 'etapa'
          ? postulacionEtapas.value.find((pe: any) => pe.etapa_id === variable.etapa_id) ?? null
          : null,
      })
    })
  })

  return lista
})

const pasoActualIndex = ref(0)
const pasoActual = computed(() => pasos.value[pasoActualIndex.value] ?? null)

// ── Riel lateral — árbol rubro › variable, siempre visible. Reemplaza el
// navegador desplegable: orienta sin competir con el foco de la variable
// actual (design.md — "step indicators", periférico, no parte de la decisión).
const rubrosRail = computed(() => {
  const grupos: { nombre: string; items: { paso: any; index: number }[] }[] = []
  pasos.value.forEach((paso, index) => {
    let grupo = grupos.find((g) => g.nombre === paso.rubroNombre)
    if (!grupo) {
      grupo = { nombre: paso.rubroNombre, items: [] }
      grupos.push(grupo)
    }
    grupo.items.push({ paso, index })
  })
  return grupos
})

onMounted(cargar)

async function cargar() {
  loading.value = true
  error.value   = ''
  try {
    const { data } = await api.get(`/evaluaciones/${id}`)
    evaluacion.value = data

    const { data: etapas } = await api.get(`/postulaciones/${data.postulacion_id}/etapas`)
    postulacionEtapas.value = etapas
  } catch (e: any) {
    error.value = e.response?.data?.message || 'Error al cargar la evaluación.'
  } finally {
    loading.value = false
  }
}

async function recalcular() {
  try {
    const { data } = await api.post(`/evaluaciones/${id}/calcular`)
    evaluacion.value.puntaje_total = data.puntaje_total
    evaluacion.value.puntajes      = data.evaluacion.puntajes
  } catch {
    // El puntaje se recalculará en el próximo recalculo exitoso — no
    // interrumpimos el flujo del evaluador por un fallo de recálculo.
  }
}

async function recargarEtapas() {
  const { data } = await api.get(`/postulaciones/${evaluacion.value.postulacion_id}/etapas`)
  postulacionEtapas.value = data
}

function irA(index: number) {
  if (index >= 0 && index < pasos.value.length) pasoActualIndex.value = index
}

// "Cerrar evaluación" abre una revisión final del checklist completo antes
// de confirmar — es una acción irreversible, nunca se dispara desde un solo
// botón suelto sin repaso previo (mismo principio que activar una tabla de
// evaluación).
async function confirmarCierre() {
  cerrando.value = true
  try {
    await api.post(`/evaluaciones/${id}/cerrar`)
    mostrarResumenCierre.value = false
    await cargar()
  } catch (e: any) {
    error.value = e.response?.data?.message || 'Error al cerrar.'
  } finally {
    cerrando.value = false
  }
}

// ── Visor de documento embebido — nunca forzar descarga ─────────────────────
const documentoUrl  = ref<string | null>(null)
const documentoTipo = ref<string | null>(null)
const documentoIdActual = ref<number | null>(null)

async function verDocumento(evidencia: any) {
  if (documentoIdActual.value === evidencia.id && documentoUrl.value) return

  limpiarDocumento()
  documentoIdActual.value = evidencia.id
  const { data } = await api.get(`/evidencias/${evidencia.id}/archivo`, { responseType: 'blob' })
  documentoUrl.value  = URL.createObjectURL(data)
  documentoTipo.value = evidencia.mime_type
}

function limpiarDocumento() {
  if (documentoUrl.value) URL.revokeObjectURL(documentoUrl.value)
  documentoUrl.value  = null
  documentoTipo.value = null
  documentoIdActual.value = null
}

watch(pasoActualIndex, limpiarDocumento)
onBeforeUnmount(limpiarDocumento)

// ── Validar evidencia (SUMA_CON_TOPE / DATO_INSTITUCIONAL / MAYOR_VALOR) ────
const formEvidencia = reactive<Record<number, { indicador_id: string; puntaje_indicador: string; comentario: string }>>({})
const guardandoEvidencia = ref<number | null>(null)

function formDe(pivote: any) {
  if (!formEvidencia[pivote.id]) {
    formEvidencia[pivote.id] = {
      indicador_id: pivote.evidencia?.indicador_id ?? '',
      puntaje_indicador: pivote.evidencia?.puntaje_indicador ?? '',
      comentario: pivote.comentario_postulacion ?? '',
    }
  }
  return formEvidencia[pivote.id]
}

function alElegirIndicador(pivote: any, indicadorId: string) {
  const form = formDe(pivote)
  form.indicador_id = indicadorId
  const indicador = pasoActual.value?.variable.indicadores.find((i: any) => String(i.id) === String(indicadorId))
  if (indicador) form.puntaje_indicador = indicador.puntaje
}

async function decidirEvidencia(pivote: any, estado: 'aprobada' | 'observada' | 'rechazada') {
  const form = formDe(pivote)

  if ((estado === 'observada' || estado === 'rechazada') && !form.comentario) {
    alert('El comentario es obligatorio para observar o rechazar.')
    return
  }

  guardandoEvidencia.value = pivote.id
  try {
    await api.patch(`/evidencias/${pivote.evidencia_id}/validacion`, {
      postulacion_id: evaluacion.value.postulacion_id,
      estado,
      comentario_postulacion: form.comentario || null,
      indicador_id: form.indicador_id || null,
      puntaje_indicador: form.puntaje_indicador || null,
    })
    await cargar()
    await recalcular()
  } catch (e: any) {
    alert(e.response?.data?.message || 'Error al guardar la validación.')
  } finally {
    guardandoEvidencia.value = null
  }
}

// Indicador de qué evidencia "gana" en MAYOR_VALOR — solo lectura, calculado
// en el cliente a partir de las ya aprobadas. El evaluador nunca elige un
// "ganador" directamente (ver design.md) — solo valida genuinidad por
// evidencia; el motor decide cuál cuenta.
function evidenciaGanadora(paso: any) {
  const aprobadas = paso.evidencias.filter((p: any) => p.estado_en_postulacion === 'aprobada')
  if (aprobadas.length === 0) return null
  return aprobadas.reduce((mejor: any, actual: any) =>
    (Number(actual.evidencia?.puntaje_indicador) || 0) > (Number(mejor.evidencia?.puntaje_indicador) || 0) ? actual : mejor
  )
}

function sumaAprobadas(paso: any) {
  return paso.evidencias
    .filter((p: any) => p.estado_en_postulacion === 'aprobada')
    .reduce((acc: number, p: any) => acc + (Number(p.evidencia?.puntaje_indicador) || 0), 0)
}

// ── TABLA_EQUIVALENCIA ───────────────────────────────────────────────────────
const valorEntrada = ref<string>('')

watch(pasoActual, (paso) => {
  valorEntrada.value = paso?.puntajeRow?.valor_entrada ?? ''
})

function tablaDelIndicador(paso: any) {
  return paso?.variable.indicadores?.[0]?.tabla_equivalencia ?? []
}

const puntajeMapeadoEnVivo = computed(() => {
  const valor = parseFloat(valorEntrada.value)
  if (isNaN(valor)) return null
  const tabla = tablaDelIndicador(pasoActual.value)
  const rango = tabla.find((r: any) => valor >= Number(r.min) && valor <= Number(r.max))
  return rango ? Number(rango.puntaje) : 0
})

const guardandoTablaEquivalencia = ref(false)

async function guardarTablaEquivalencia() {
  const paso = pasoActual.value
  guardandoTablaEquivalencia.value = true
  try {
    const indicador = paso.variable.indicadores?.[0]
    await api.post(`/evaluaciones/${id}/puntajes`, {
      variable_id: paso.variable.id,
      valor_entrada: valorEntrada.value,
      indicador_id: indicador?.id ?? null,
      tabla_equivalencia: tablaDelIndicador(paso),
    })

    // Si hay una evidencia (ej. constancia) asociada, aprobarla también.
    if (paso.evidencias[0]) {
      await api.patch(`/evidencias/${paso.evidencias[0].evidencia_id}/validacion`, {
        postulacion_id: evaluacion.value.postulacion_id,
        estado: 'aprobada',
      })
    }

    await cargar()
    await recalcular()
  } catch (e: any) {
    alert(e.response?.data?.message || 'Error al guardar el puntaje.')
  } finally {
    guardandoTablaEquivalencia.value = false
  }
}

// ── fuente='etapa' (Clase Magistral, etc.) ──────────────────────────────────
const formEtapa = reactive({ fecha_realizada: '', puntaje_bruto_evento: '', jurado_texto: '', comentario: '' })
const guardandoEtapa = ref(false)

watch(pasoActual, (paso) => {
  const pe = paso?.postulacionEtapa
  formEtapa.fecha_realizada = pe?.fecha_realizada ?? ''
  formEtapa.puntaje_bruto_evento = pe?.puntaje_bruto_evento ?? ''
  formEtapa.jurado_texto = pe?.jurado_texto ?? ''
  formEtapa.comentario = pe?.comentario ?? ''
})

async function registrarResultadoEtapa() {
  await guardarEtapa('aprobada')
}

async function marcarNoPresentado() {
  if (!confirm('¿Marcar como no presentado? Esto aporta 0 puntos en esta variable.')) return
  await guardarEtapa('no_presentado')
}

async function guardarEtapa(estado: 'aprobada' | 'no_presentado') {
  const paso = pasoActual.value
  if (!paso.postulacionEtapa) return

  guardandoEtapa.value = true
  try {
    await api.patch(`/postulacion-etapas/${paso.postulacionEtapa.id}`, {
      estado,
      fecha_realizada: formEtapa.fecha_realizada || null,
      puntaje_bruto_evento: estado === 'aprobada' ? formEtapa.puntaje_bruto_evento : null,
      jurado_texto: formEtapa.jurado_texto || null,
      comentario: formEtapa.comentario || null,
    })
    await recargarEtapas()
    await recalcular()
  } catch (e: any) {
    alert(e.response?.data?.message || 'Error al registrar el resultado.')
  } finally {
    guardandoEtapa.value = false
  }
}

const evidEstadoBadge: Record<string, string> = {
  pendiente: 'badge-yellow', aprobada: 'badge-green',
  observada: 'badge-indigo', rechazada: 'badge-red',
}
const vigenciaBadge = (ev: any) => {
  if (ev.vigente === true)  return 'badge-green'
  if (ev.vigente === false) return 'badge-red'
  return 'badge-gray'
}
const vigenciaLabel = (ev: any) => {
  if (ev.vigente === true)  return 'Vigente'
  if (ev.vigente === false) return `Vencida (${ev.fecha_vencimiento ?? ''})`
  return 'Sin fecha'
}
</script>

<template>
  <div v-if="loading" class="loading-center"><span class="spinner"></span></div>
  <div v-else-if="error" class="alert alert-error" style="margin:2rem">{{ error }}</div>

  <div v-else-if="evaluacion">
    <!-- ══ HEADER FIJO — nunca se repite por tarjeta ══ -->
    <div class="page-header">
      <div>
        <button class="btn btn-ghost btn-sm mb-1" @click="router.back()">← Volver a bandeja</button>
        <h1>{{ evaluacion.postulacion?.postulante?.name }}</h1>
        <p>
          {{ evaluacion.postulacion?.plaza?.asignatura }} ·
          {{ evaluacion.postulacion?.convocatoria?.codigo }} ·
          {{ evaluacion.postulacion?.convocatoria?.tabla_snapshot?.nombre }}
        </p>
      </div>
      <div class="flex items-center gap-3">
        <div class="text-right">
          <div class="text-xs text-muted">Puntaje total</div>
          <div class="font-semibold text-lg" style="color:var(--clr-primary-700)">
            {{ evaluacion.puntaje_total ?? '—' }}
          </div>
        </div>
        <button
          v-if="evaluacion.estado !== 'cerrada' && evaluacion.puntaje_total !== null"
          class="btn btn-secondary btn-sm"
          @click="mostrarResumenCierre = true"
        >
          <Icon name="lock" :size="14" />
          Cerrar evaluación
        </button>
        <span v-else-if="evaluacion.estado === 'cerrada'" class="badge badge-green">Cerrada</span>
      </div>
    </div>

    <div v-if="pasos.length === 0" class="card">
      <div class="empty-state">
        <h3>Sin variables configuradas</h3>
        <p>Esta convocatoria no tiene tabla de evaluación disponible.</p>
      </div>
    </div>

    <template v-else>
      <!-- Progreso -->
      <div class="mb-3">
        <span class="text-sm text-muted">
          Variable {{ pasoActualIndex + 1 }} de {{ pasos.length }} — {{ pasoActual.rubroNombre }} › {{ pasoActual.variable.nombre }}
        </span>
        <div class="progress-bar mt-1">
          <div class="progress-fill" :style="{ width: ((pasoActualIndex + 1) / pasos.length * 100) + '%' }"></div>
        </div>
      </div>

      <div class="evaluacion-layout">
        <!-- ══ RIEL — árbol rubro › variable, siempre visible, periférico ══ -->
        <aside class="evaluacion-rail">
          <div v-for="grupo in rubrosRail" :key="grupo.nombre" class="evaluacion-rail-group">
            <div class="evaluacion-rail-group-title">{{ grupo.nombre }}</div>
            <button
              v-for="item in grupo.items"
              :key="item.index"
              type="button"
              class="evaluacion-rail-item"
              :class="{ 'is-current': item.index === pasoActualIndex }"
              @click="irA(item.index)"
            >
              <Icon v-if="item.paso.puntajeRow" name="check-circle" :size="14" class="evaluacion-rail-icon" />
              <span v-else class="evaluacion-rail-dot"></span>
              <span class="truncate">{{ item.paso.variable.nombre }}</span>
            </button>
          </div>
        </aside>

        <div class="evaluacion-main">
      <!-- ══ FOCO DE VARIABLE — lo único que cambia por paso ══ -->
      <div class="card mb-4">
        <div class="card-header">
          <div>
            <h3 class="card-title">{{ pasoActual.variable.nombre }}</h3>
            <p class="text-xs text-muted">
              {{ pasoActual.variable.tipo_calculo }} · tope {{ pasoActual.variable.puntaje_max }} pts
              <span v-if="pasoActual.variable.periodo_validez_anios">
                · vigencia {{ pasoActual.variable.periodo_validez_anios }} años
              </span>
            </p>
          </div>
          <span v-if="pasoActual.puntajeRow" class="badge badge-blue">
            Aplicado: {{ pasoActual.puntajeRow.puntaje_variable }} / {{ pasoActual.variable.puntaje_max }}
          </span>
        </div>

        <div
          v-if="pasoActual.variable.tipo_calculo === 'DATO_INSTITUCIONAL'"
          class="alert alert-info mb-3"
          style="margin:0 1.25rem 1rem"
        >
          <Icon name="briefcase" :size="16" />
          Fuente institucional: {{ pasoActual.variable.fuente_verificacion || 'no especificada' }}
        </div>

        <div style="padding:0 1.25rem 1.25rem">
          <!-- ═══ PATRÓN 4: fuente='etapa' — evento en vivo ═══ -->
          <template v-if="pasoActual.variable.fuente === 'etapa'">
            <div v-if="!pasoActual.postulacionEtapa" class="alert alert-error">
              No se encontró el registro de etapa para esta variable.
            </div>
            <div v-else>
              <div v-if="!pasoActual.postulacionEtapa.fecha_realizada && pasoActual.postulacionEtapa.estado === 'pendiente'" class="alert alert-warning">
                <Icon name="clock" :size="16" />
                <span>
                  {{ pasoActual.postulacionEtapa.fecha_programada
                    ? `Programada para ${pasoActual.postulacionEtapa.fecha_programada}, aún no ha ocurrido.`
                    : 'Sin programar todavía.' }}
                  No hay nada que registrar hasta que el evento ocurra.
                </span>
              </div>

              <div v-else-if="pasoActual.postulacionEtapa.estado === 'aprobada'" class="alert alert-info">
                <Icon name="check-circle" :size="16" />
                <span>
                  Resultado registrado: {{ pasoActual.postulacionEtapa.puntaje_bruto_evento }} pts
                  el {{ pasoActual.postulacionEtapa.fecha_realizada }}.
                </span>
              </div>

              <div v-else-if="pasoActual.postulacionEtapa.estado === 'no_presentado'" class="alert alert-error">
                <Icon name="x-circle" :size="16" />
                <span>Marcado como no presentado — aporta 0 puntos.</span>
              </div>

              <template v-if="pasoActual.postulacionEtapa.fecha_realizada || pasoActual.postulacionEtapa.estado !== 'pendiente'">
                <div class="grid-2 mb-3">
                  <div class="form-group">
                    <label class="form-label">Fecha realizada</label>
                    <input v-model="formEtapa.fecha_realizada" type="date" class="form-control" />
                  </div>
                  <div class="form-group">
                    <label class="form-label">Puntaje obtenido (tope {{ pasoActual.variable.puntaje_max }})</label>
                    <input v-model="formEtapa.puntaje_bruto_evento" type="number" step="0.01" class="form-control" />
                  </div>
                </div>
                <div class="form-group mb-3">
                  <label class="form-label">Jurado presente</label>
                  <input v-model="formEtapa.jurado_texto" class="form-control" placeholder="Dr. Pérez (decano), Prof. García..." />
                </div>
                <div class="form-group mb-3">
                  <label class="form-label">Comentario</label>
                  <textarea v-model="formEtapa.comentario" class="form-control" rows="2"></textarea>
                </div>
              </template>

              <div v-if="!pasoActual.postulacionEtapa.fecha_realizada && pasoActual.postulacionEtapa.estado === 'pendiente'" class="form-group mb-3">
                <label class="form-label">Fecha en que ocurrió el evento</label>
                <input v-model="formEtapa.fecha_realizada" type="date" class="form-control" />
              </div>

              <div class="flex gap-2">
                <button class="btn btn-primary" :disabled="guardandoEtapa || !formEtapa.fecha_realizada" @click="registrarResultadoEtapa">
                  {{ guardandoEtapa ? 'Guardando...' : 'Registrar resultado' }}
                </button>
                <button class="btn btn-ghost" :disabled="guardandoEtapa" @click="marcarNoPresentado">
                  Marcar no presentado
                </button>
              </div>
            </div>
          </template>

          <!-- ═══ PATRÓN 3: TABLA_EQUIVALENCIA ═══ -->
          <template v-else-if="pasoActual.variable.tipo_calculo === 'TABLA_EQUIVALENCIA'">
            <div v-if="pasoActual.evidencias[0]" class="mb-3">
              <button class="btn btn-secondary btn-sm mb-2" @click="verDocumento(pasoActual.evidencias[0].evidencia)">
                <Icon name="file-text" :size="14" /> Ver documento
              </button>
              <div v-if="documentoUrl && documentoIdActual === pasoActual.evidencias[0].evidencia.id">
                <iframe v-if="documentoTipo === 'application/pdf'" :src="documentoUrl" style="width:100%;height:400px;border:1px solid var(--surface-border);border-radius:8px"></iframe>
                <img v-else :src="documentoUrl" style="max-width:100%;border-radius:8px" />
              </div>
            </div>

            <div class="grid-2 mb-3">
              <div class="form-group">
                <label class="form-label">Valor bruto</label>
                <input v-model="valorEntrada" type="number" step="0.01" class="form-control" />
              </div>
              <div class="form-group">
                <label class="form-label">Puntaje mapeado</label>
                <div class="font-semibold text-lg" style="color:var(--clr-primary-700);padding-top:0.5rem">
                  {{ puntajeMapeadoEnVivo ?? '—' }} pts
                </div>
              </div>
            </div>

            <div class="table-wrap mb-3">
              <table>
                <thead><tr><th>Rango</th><th>Puntaje</th></tr></thead>
                <tbody>
                  <tr v-for="(r, i) in tablaDelIndicador(pasoActual)" :key="i"
                    :style="valorEntrada && Number(valorEntrada) >= r.min && Number(valorEntrada) <= r.max ? 'background:var(--clr-primary-50);font-weight:600' : ''">
                    <td>{{ r.min }} – {{ r.max }}</td>
                    <td>{{ r.puntaje }}</td>
                  </tr>
                </tbody>
              </table>
            </div>

            <div class="flex gap-2">
              <button class="btn btn-primary" :disabled="guardandoTablaEquivalencia || !valorEntrada" @click="guardarTablaEquivalencia">
                {{ guardandoTablaEquivalencia ? 'Guardando...' : 'Aprobar y asignar puntaje' }}
              </button>
              <button
                v-if="pasoActual.evidencias[0]"
                class="btn btn-ghost"
                @click="decidirEvidencia(pasoActual.evidencias[0], 'observada')"
              >Observar</button>
              <button
                v-if="pasoActual.evidencias[0]"
                class="btn btn-ghost"
                style="color:var(--clr-danger-600)"
                @click="decidirEvidencia(pasoActual.evidencias[0], 'rechazada')"
              >Rechazar definitivamente</button>
            </div>
          </template>

          <!-- ═══ SIN EVIDENCIAS (SUMA_CON_TOPE/MAYOR_VALOR/DATO_INSTITUCIONAL) ═══ -->
          <div v-else-if="pasoActual.evidencias.length === 0" class="empty-state">
            <p>El postulante no presentó evidencias para esta variable.</p>
          </div>

          <!-- ═══ PATRÓN 2: MAYOR_VALOR — lado a lado, comparación ═══ -->
          <template v-else-if="pasoActual.variable.tipo_calculo === 'MAYOR_VALOR'">
            <p class="text-sm text-muted mb-3">
              Valida cada documento por su validez, no por si es el de mayor puntaje —
              el sistema toma automáticamente el de mayor valor entre los aprobados.
            </p>
            <div class="flex gap-3 mb-3" style="overflow-x:auto">
              <div
                v-for="piv in pasoActual.evidencias" :key="piv.id"
                class="card"
                style="min-width:260px;flex:1"
                :style="evidenciaGanadora(pasoActual)?.id === piv.id ? 'border:2px solid var(--clr-primary-600)' : ''"
              >
                <div class="flex justify-between items-center mb-2">
                  <span class="badge" :class="evidEstadoBadge[piv.estado_en_postulacion]">{{ piv.estado_en_postulacion }}</span>
                  <span v-if="evidenciaGanadora(pasoActual)?.id === piv.id" class="badge badge-blue">
                    <Icon name="check-circle" :size="12" /> Aplica
                  </span>
                </div>
                <p class="font-medium text-sm mb-1">{{ piv.evidencia?.nombre_original }}</p>
                <span class="badge mb-2" :class="vigenciaBadge(piv)">{{ vigenciaLabel(piv) }}</span>

                <button class="btn btn-ghost btn-sm mb-2" @click="verDocumento(piv.evidencia)"><Icon name="file-text" :size="14" /> Ver documento</button>
                <div v-if="documentoUrl && documentoIdActual === piv.evidencia.id" class="mb-2">
                  <iframe v-if="documentoTipo === 'application/pdf'" :src="documentoUrl" style="width:100%;height:250px;border:1px solid var(--surface-border);border-radius:6px"></iframe>
                  <img v-else :src="documentoUrl" style="max-width:100%;border-radius:6px" />
                </div>

                <div class="form-group mb-2">
                  <label class="form-label text-xs">Puntaje del indicador</label>
                  <input v-model="formDe(piv).puntaje_indicador" type="number" step="0.01" class="form-control" />
                </div>
                <div v-if="piv.estado_en_postulacion === 'observada' || piv.estado_en_postulacion === 'rechazada'" class="form-group mb-2">
                  <textarea v-model="formDe(piv).comentario" class="form-control" rows="2" placeholder="Motivo..."></textarea>
                </div>

                <div class="flex gap-1">
                  <button class="btn btn-primary btn-sm" :disabled="guardandoEvidencia === piv.id" @click="decidirEvidencia(piv, 'aprobada')">Aprobar</button>
                  <button class="btn btn-ghost btn-sm" :disabled="guardandoEvidencia === piv.id" @click="decidirEvidencia(piv, 'observada')">Observar</button>
                  <button class="btn btn-ghost btn-sm" style="color:var(--clr-danger-600)" :disabled="guardandoEvidencia === piv.id" @click="decidirEvidencia(piv, 'rechazada')">Rechazar</button>
                </div>
              </div>
            </div>
          </template>

          <!-- ═══ PATRÓN 1: SUMA_CON_TOPE / DATO_INSTITUCIONAL ═══ -->
          <template v-else>
            <p class="text-sm text-muted mb-2">
              Suma actual: {{ sumaAprobadas(pasoActual) }} pts →
              aplicado: {{ Math.min(sumaAprobadas(pasoActual), pasoActual.variable.puntaje_max) }} pts
              (tope {{ pasoActual.variable.puntaje_max }})
            </p>

            <div v-for="(piv, i) in pasoActual.evidencias" :key="piv.id" class="card mb-3">
              <div class="flex justify-between items-center mb-2">
                <span class="text-sm text-muted">Evidencia {{ Number(i) + 1 }} de {{ pasoActual.evidencias.length }}</span>
                <div class="flex gap-2">
                  <span class="badge" :class="evidEstadoBadge[piv.estado_en_postulacion]">{{ piv.estado_en_postulacion }}</span>
                  <span class="badge" :class="vigenciaBadge(piv)">{{ vigenciaLabel(piv) }}</span>
                </div>
              </div>
              <p class="font-medium text-sm mb-2">{{ piv.evidencia?.nombre_original }}</p>

              <button class="btn btn-ghost btn-sm mb-2" @click="verDocumento(piv.evidencia)"><Icon name="file-text" :size="14" /> Ver documento</button>
              <div v-if="documentoUrl && documentoIdActual === piv.evidencia.id" class="mb-3">
                <iframe v-if="documentoTipo === 'application/pdf'" :src="documentoUrl" style="width:100%;height:400px;border:1px solid var(--surface-border);border-radius:8px"></iframe>
                <img v-else :src="documentoUrl" style="max-width:100%;border-radius:8px" />
              </div>

              <div class="grid-2 mb-2">
                <div class="form-group">
                  <label class="form-label text-xs">Indicador</label>
                  <select
                    v-if="pasoActual.variable.indicadores?.length"
                    class="form-control"
                    :value="formDe(piv).indicador_id"
                    @change="alElegirIndicador(piv, ($event.target as HTMLSelectElement).value)"
                  >
                    <option value="">Seleccionar...</option>
                    <option v-for="ind in pasoActual.variable.indicadores" :key="ind.id" :value="ind.id">
                      {{ ind.nombre }} ({{ ind.puntaje }} pts)
                    </option>
                  </select>
                  <span v-else class="text-xs text-muted">Sin indicadores configurados — ingresa el puntaje directamente.</span>
                </div>
                <div class="form-group">
                  <label class="form-label text-xs">Puntaje del indicador</label>
                  <input v-model="formDe(piv).puntaje_indicador" type="number" step="0.01" class="form-control" />
                </div>
              </div>

              <div v-if="piv.estado_en_postulacion === 'observada' || piv.estado_en_postulacion === 'rechazada' || formDe(piv).comentario" class="form-group mb-2">
                <label class="form-label text-xs">Comentario</label>
                <textarea v-model="formDe(piv).comentario" class="form-control" rows="2"></textarea>
              </div>

              <div class="flex gap-2">
                <button class="btn btn-primary btn-sm" :disabled="guardandoEvidencia === piv.id" @click="decidirEvidencia(piv, 'aprobada')">
                  Aprobar y asignar puntaje
                </button>
                <button class="btn btn-ghost btn-sm" :disabled="guardandoEvidencia === piv.id" @click="decidirEvidencia(piv, 'observada')">
                  Observar (pedir corrección)
                </button>
                <button class="btn btn-ghost btn-sm" style="color:var(--clr-danger-600)" :disabled="guardandoEvidencia === piv.id" @click="decidirEvidencia(piv, 'rechazada')">
                  Rechazar definitivamente
                </button>
              </div>
            </div>
          </template>
        </div>
      </div>

      <!-- ══ NAVEGACIÓN — sticky, no gestos swipe ══ -->
      <div class="flex justify-between items-center" style="position:sticky;bottom:0;background:var(--surface-bg,#fff);padding:0.75rem 0">
        <button class="btn btn-secondary" :disabled="pasoActualIndex === 0" @click="irA(pasoActualIndex - 1)">
          ← Variable anterior
        </button>
        <button class="btn btn-secondary" :disabled="pasoActualIndex === pasos.length - 1" @click="irA(pasoActualIndex + 1)">
          Siguiente variable →
        </button>
      </div>
        </div>
      </div>
    </template>

    <!-- ══ Revisión final antes de cerrar — irreversible, nunca sin repaso ══ -->
    <div v-if="mostrarResumenCierre" class="modal-overlay" @click.self="mostrarResumenCierre = false">
      <div class="modal" style="max-width:640px">
        <div class="modal-header">
          <h2>Revisar antes de cerrar</h2>
          <button class="btn btn-ghost btn-icon" @click="mostrarResumenCierre = false"><Icon name="x" :size="18" /></button>
        </div>
        <div class="modal-body">
          <p class="text-sm text-muted mb-3">
            Cerrar la evaluación es irreversible — ya no podrás modificar puntajes.
            Puntaje total: <strong style="color:var(--clr-gray-900)">{{ evaluacion.puntaje_total }}</strong>
          </p>
          <div class="cierre-resumen-list">
            <div v-for="(p, idx) in pasos" :key="idx" class="cierre-resumen-item">
              <span class="truncate">{{ p.rubroNombre }} › {{ p.variable.nombre }}</span>
              <span class="font-medium" :class="{ 'text-muted': !p.puntajeRow }">
                {{ p.puntajeRow ? `${p.puntajeRow.puntaje_variable} pts` : 'Sin calificar' }}
              </span>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" @click="mostrarResumenCierre = false">Cancelar</button>
          <button class="btn btn-primary" :disabled="cerrando" @click="confirmarCierre">
            <span v-if="cerrando" class="spinner"></span>
            {{ cerrando ? 'Cerrando...' : 'Confirmar y cerrar' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
