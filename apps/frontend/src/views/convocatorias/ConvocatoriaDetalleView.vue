<script setup lang="ts">
import { ref, reactive, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import Icon from '@/components/ui/Icon.vue'
import Stepper from '@/components/ui/Stepper.vue'
import AccordionSection from '@/components/ui/AccordionSection.vue'
import type { StepperStep } from '@/components/ui/Stepper.vue'

const route  = useRoute()
const router = useRouter()
const auth   = useAuthStore()
const id     = route.params.id

const conv    = ref<any>(null)
const plazas  = ref<any[]>([])
const loading = ref(true)
const tab     = ref<'plazas'|'tabla'|'postulantes'|'evaluaciones'|'asignaciones'>('plazas')
const showPlazaModal = ref(false)
const savingPlaza    = ref(false)
const publicando     = ref(false)
const plazaForm = ref({ facultad:'', departamento:'', asignatura:'', modalidad:'', horas_semana:'' })

// Postulantes de esta convocatoria (todas, no solo enviadas)
const todasPostulaciones = ref<any[]>([])

// Evaluaciones de esta convocatoria
const evaluacionesConv = ref<any[]>([])

// Asignación de evaluadores
const postulaciones = ref<any[]>([])
const asignaciones  = ref<any[]>([])
const evaluadores   = ref<any[]>([])
const asignacionesGlobales = ref<any[]>([]) // todas las del sistema — para carga de trabajo real
const evaluadorElegido = reactive<Record<number, string>>({}) // postulacion_id -> evaluador_id
const asignando = reactive<Record<number, boolean>>({})
const quitando   = reactive<Record<number, boolean>>({})

const postulacionEstadoBadge: Record<string, string> = {
  en_proceso: 'badge-yellow', observada: 'badge-indigo',
  rechazada: 'badge-red', aprobada_etapa: 'badge-green', ganadora: 'badge-blue',
}
const evaluacionEstadoBadge: Record<string, string> = {
  en_proceso: 'badge-yellow', completada: 'badge-blue', cerrada: 'badge-green',
}

const plazaEstadoBadge: Record<string, string> = {
  activa:'badge-blue', cubierta:'badge-green', desierta:'badge-red',
}

const ESTADOS_CONV: StepperStep[] = [
  { key: 'borrador',    label: 'Borrador' },
  { key: 'publicada',   label: 'Publicada' },
  { key: 'en_proceso',  label: 'En proceso' },
  { key: 'cerrada',     label: 'Cerrada' },
]
const pasosConvocatoria = computed<StepperStep[]>(() => {
  if (conv.value?.estado === 'desierta') {
    return ESTADOS_CONV.map((p) => (p.key === 'cerrada' ? { ...p, label: 'Desierta', state: 'error' as const } : p))
  }
  return ESTADOS_CONV
})

onMounted(async () => {
  await cargar()
})

async function cargar() {
  loading.value = true
  try {
    const [cRes, pRes] = await Promise.all([
      api.get(`/convocatorias/${id}`),
      api.get(`/convocatorias/${id}/plazas`),
    ])
    conv.value   = cRes.data
    plazas.value = pRes.data

    if (canManage) {
      await cargarAsignaciones()
      const [postRes, evalRes, todasAsigRes] = await Promise.all([
        api.get('/postulaciones', { params: { convocatoria_id: id } }),
        api.get('/evaluaciones', { params: { convocatoria_id: id } }),
        api.get('/asignaciones'),
      ])
      todasPostulaciones.value  = postRes.data.data ?? postRes.data
      evaluacionesConv.value    = evalRes.data.data ?? evalRes.data
      asignacionesGlobales.value = todasAsigRes.data.data ?? todasAsigRes.data
    }
  } finally {
    loading.value = false
  }
}

async function cargarAsignaciones() {
  const [postRes, asigRes, evalRes] = await Promise.all([
    api.get('/postulaciones', { params: { convocatoria_id: id } }),
    api.get('/asignaciones', { params: { convocatoria_id: id } }),
    api.get('/users', { params: { rol: 'evaluador' } }),
  ])
  postulaciones.value = (postRes.data.data ?? postRes.data).filter((p: any) => p.fecha_envio)
  asignaciones.value  = asigRes.data.data ?? asigRes.data
  evaluadores.value   = evalRes.data.data ?? evalRes.data
}

function asignacionesDe(postulacionId: number) {
  return asignaciones.value.filter((a) => a.postulacion_id === postulacionId)
}

// Carga de trabajo real del evaluador: asignaciones cuya evaluación todavía
// no está cerrada (o ni siquiera existe) — en cualquier convocatoria, no
// solo esta. Sin esto, asignar es una apuesta a ciegas sobre quién ya está
// sobrecargado.
function cargaDe(evaluadorId: number): number {
  return asignacionesGlobales.value.filter((a) =>
    a.evaluador_id === evaluadorId && a.postulacion?.evaluacion?.estado !== 'cerrada'
  ).length
}
const evaluadoresPorCarga = computed(() =>
  [...evaluadores.value].sort((a, b) => cargaDe(a.id) - cargaDe(b.id))
)
const sinAsignar = computed(() => postulaciones.value.filter((p) => asignacionesDe(p.id).length === 0))

async function asignarA(postulacionId: number, evaluadorId: string) {
  if (!evaluadorId) return
  asignando[postulacionId] = true
  try {
    await api.post(`/convocatorias/${id}/asignaciones`, {
      postulacion_id: postulacionId,
      evaluador_id: evaluadorId,
    })
    evaluadorElegido[postulacionId] = ''
    await cargar()
  } catch (e: any) {
    alert(e.response?.data?.message || 'No se pudo asignar el evaluador')
  } finally {
    asignando[postulacionId] = false
  }
}

async function quitarAsignacion(asignacionId: number) {
  if (!confirm('¿Quitar esta asignación?')) return
  quitando[asignacionId] = true
  try {
    await api.delete(`/asignaciones/${asignacionId}`)
    await cargar()
  } catch (e: any) {
    alert(e.response?.data?.message || 'No se pudo quitar la asignación')
  } finally {
    quitando[asignacionId] = false
  }
}

async function publicar() {
  if (!confirm('¿Publicar esta convocatoria? Los postulantes podrán verla y postular.')) return
  publicando.value = true
  try {
    await api.patch(`/convocatorias/${id}`, { estado: 'publicada' })
    await cargar()
  } catch (e: any) {
    alert(e.response?.data?.message || 'No se pudo publicar la convocatoria')
  } finally {
    publicando.value = false
  }
}

async function guardarPlaza() {
  savingPlaza.value = true
  try {
    await api.post(`/convocatorias/${id}/plazas`, plazaForm.value)
    showPlazaModal.value = false
    plazaForm.value = { facultad:'', departamento:'', asignatura:'', modalidad:'', horas_semana:'' }
    await cargar()
  } finally {
    savingPlaza.value = false
  }
}

// Cobertura de evaluador por plaza — cuántas postulaciones enviadas de esta
// plaza no tienen ningún evaluador asignado todavía.
function postulantesDe(plazaId: number): any[] {
  return todasPostulaciones.value.filter((p) => p.plaza_id === plazaId)
}
function sinAsignarDe(plazaId: number): number {
  return todasPostulaciones.value.filter((p) =>
    p.plaza_id === plazaId && p.fecha_envio && asignacionesDe(p.id).length === 0
  ).length
}

const canManage = auth.isAdmin || auth.rol === 'admin_convocatoria'
</script>

<template>
  <div v-if="loading" class="loading-center"><span class="spinner"></span> Cargando...</div>

  <div v-else-if="conv">
    <!-- Header -->
    <div class="page-header">
      <div>
        <button class="btn btn-ghost btn-sm mb-1" @click="router.back()">← Volver</button>
        <h1>{{ conv.nombre }}</h1>
        <p>{{ conv.codigo }} · {{ conv.tipo_proceso }}</p>
      </div>
      <div class="flex gap-2">
        <button
          v-if="canManage && conv.estado === 'borrador'"
          class="btn btn-primary btn-sm"
          :disabled="publicando"
          @click="publicar"
        >
          <span v-if="publicando" class="spinner"></span>
          {{ publicando ? 'Publicando...' : 'Publicar convocatoria' }}
        </button>
        <RouterLink :to="`/convocatorias/${id}/resultados`" class="btn btn-secondary btn-sm">
          Ver resultados
        </RouterLink>
      </div>
    </div>

    <!-- Estado + fechas -->
    <div class="card mb-4">
      <Stepper :steps="pasosConvocatoria" :current-key="conv.estado === 'desierta' ? 'cerrada' : conv.estado" />
      <div class="flex gap-4 mt-3 text-sm text-muted">
        <span>{{ new Date(conv.fecha_inicio).toLocaleDateString('es-PE') }} → {{ new Date(conv.fecha_fin).toLocaleDateString('es-PE') }}</span>
        <span>{{ plazas.length }} plaza{{ plazas.length === 1 ? '' : 's' }}</span>
        <span>{{ plazas.filter((p: any) => p.estado === 'cubierta').length }} cubierta{{ plazas.filter((p: any) => p.estado === 'cubierta').length === 1 ? '' : 's' }}</span>
      </div>
    </div>

    <!-- Tabs -->
    <div class="tabs">
      <button
        v-for="t in (canManage
          ? [['plazas','Plazas'],['tabla','Tabla Evaluación'],['postulantes','Postulantes'],['evaluaciones','Evaluaciones'],['asignaciones','Asignaciones']]
          : [['plazas','Plazas'],['tabla','Tabla Evaluación']])"
        :key="t[0]"
        class="tab"
        :class="{ active: tab === t[0] }"
        @click="tab = t[0] as any"
      >
        {{ t[1] }}
      </button>
    </div>

    <!-- Plazas — cards, no tabla -->
    <div v-if="tab === 'plazas'">
      <div class="flex justify-between items-center mb-3">
        <h3 class="font-semibold">Plazas disponibles</h3>
        <button v-if="canManage" class="btn btn-primary btn-sm" @click="showPlazaModal = true">+ Agregar plaza</button>
      </div>

      <div v-if="plazas.length === 0" class="card">
        <div class="empty-state">
          <h3>Sin plazas registradas</h3>
          <p>Agrega las plazas docentes de esta convocatoria.</p>
        </div>
      </div>

      <div v-else class="plazas-grid">
        <div v-for="p in plazas" :key="p.id" class="card plaza-card">
          <div class="flex justify-between items-start mb-2">
            <h3 class="font-semibold">{{ p.asignatura }}</h3>
            <span class="badge" :class="plazaEstadoBadge[p.estado] ?? 'badge-gray'">{{ p.estado }}</span>
          </div>
          <p class="text-xs text-muted mb-3">{{ p.facultad }} · {{ p.departamento }}</p>
          <p class="text-xs text-muted mb-1">{{ p.modalidad || '—' }} · {{ p.horas_semana || '—' }} h/sem</p>
          <div v-if="canManage" class="plaza-card-foot">
            <span class="text-xs">{{ postulantesDe(p.id).length }} postulante{{ postulantesDe(p.id).length === 1 ? '' : 's' }}</span>
            <span v-if="sinAsignarDe(p.id) > 0" class="badge badge-yellow">{{ sinAsignarDe(p.id) }} sin evaluador</span>
            <span v-else-if="postulantesDe(p.id).length > 0" class="badge badge-green">Evaluador OK</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Tabla evaluación — árbol de acordeón, no tablas anidadas -->
    <div v-else-if="tab === 'tabla'">
      <div v-if="conv.tabla_snapshot">
        <div class="alert alert-info mb-4">
          Esta convocatoria tiene un snapshot inmutable de la tabla de evaluación.
        </div>
        <AccordionSection
          v-for="rubro in conv.tabla_snapshot.rubros"
          :key="rubro.nombre"
          :title="rubro.nombre"
          :meta="`tope ${rubro.puntaje_max_subrubro} pts`"
          :open="false"
        >
          <div v-for="v in rubro.variables" :key="v.id" class="requisito-row">
            <div class="flex justify-between items-center">
              <span class="font-medium text-sm">{{ v.nombre }}</span>
              <div class="flex items-center gap-2">
                <span class="badge badge-gray">{{ v.tipo_calculo }}</span>
                <span class="text-sm font-medium">{{ v.puntaje_max }} pts</span>
              </div>
            </div>
          </div>
        </AccordionSection>
      </div>
      <div v-else class="card">
        <div class="empty-state">
          <h3>Sin tabla de evaluación</h3>
          <p>Publica la convocatoria para generar el snapshot inmutable.</p>
        </div>
      </div>
    </div>

    <!-- Postulantes -->
    <div v-else-if="tab === 'postulantes'">
      <div v-if="todasPostulaciones.length === 0" class="card">
        <div class="empty-state">
          <h3>Sin postulantes todavía</h3>
        </div>
      </div>
      <div v-else>
        <div v-for="p in todasPostulaciones" :key="p.id" class="list-row">
          <div class="min-w-0">
            <p class="font-medium text-sm truncate">{{ p.postulante?.name ?? p.user_id }}</p>
            <p class="text-xs text-muted">{{ p.plaza?.asignatura ?? '—' }}</p>
          </div>
          <div class="flex items-center gap-2" style="flex-shrink:0">
            <span v-if="p.fecha_envio" class="text-xs text-muted">{{ new Date(p.fecha_envio).toLocaleDateString('es-PE') }}</span>
            <span v-else class="badge badge-gray" style="font-size:0.7rem">Borrador</span>
            <span class="badge" :class="postulacionEstadoBadge[p.estado] ?? 'badge-gray'">{{ p.estado }}</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Evaluaciones -->
    <div v-else-if="tab === 'evaluaciones'">
      <div v-if="evaluacionesConv.length === 0" class="card">
        <div class="empty-state">
          <h3>Sin evaluaciones todavía</h3>
        </div>
      </div>
      <div v-else>
        <RouterLink v-for="e in evaluacionesConv" :key="e.id" :to="`/evaluaciones/${e.id}`" class="list-row" style="text-decoration:none;color:inherit">
          <div class="min-w-0">
            <p class="font-medium text-sm truncate">{{ e.postulacion?.postulante?.name ?? '—' }}</p>
            <p class="text-xs text-muted">Evaluador: {{ e.evaluador?.name ?? '—' }}</p>
          </div>
          <div class="flex items-center gap-2" style="flex-shrink:0">
            <span v-if="e.puntaje_total" class="font-semibold text-sm" style="color:var(--clr-primary-700)">{{ e.puntaje_total }} pts</span>
            <span class="badge" :class="evaluacionEstadoBadge[e.estado] ?? 'badge-gray'">{{ e.estado }}</span>
          </div>
        </RouterLink>
      </div>
    </div>

    <!-- Asignación de evaluadores — split view con carga de trabajo -->
    <div v-else-if="tab === 'asignaciones'">
      <div v-if="postulaciones.length === 0" class="card">
        <div class="empty-state">
          <h3>Sin postulaciones enviadas</h3>
          <p>Solo se pueden asignar evaluadores a postulaciones ya enviadas formalmente.</p>
        </div>
      </div>

      <div v-else class="asignaciones-layout">
        <div class="asignaciones-lista">
          <h3 class="font-semibold mb-3" v-if="sinAsignar.length > 0">Sin asignar ({{ sinAsignar.length }})</h3>
          <div v-for="p in sinAsignar" :key="p.id" class="card mb-3">
            <p class="font-medium text-sm mb-2">{{ p.postulante?.name ?? p.user_id }} · {{ p.plaza?.asignatura ?? '—' }}</p>
            <div class="flex gap-2">
              <select v-model="evaluadorElegido[p.id]" class="form-control">
                <option value="">Elegir evaluador...</option>
                <option v-for="e in evaluadoresPorCarga" :key="e.id" :value="e.id">
                  {{ e.name }} — {{ cargaDe(e.id) }} activa{{ cargaDe(e.id) === 1 ? '' : 's' }}
                </option>
              </select>
              <button
                class="btn btn-primary btn-sm"
                :disabled="!evaluadorElegido[p.id] || asignando[p.id]"
                @click="asignarA(p.id, evaluadorElegido[p.id])"
              >
                <span v-if="asignando[p.id]" class="spinner"></span>
                Asignar
              </button>
            </div>
          </div>

          <h3 class="font-semibold mb-3 mt-4" v-if="sinAsignar.length < postulaciones.length">Ya asignadas</h3>
          <div v-for="p in postulaciones.filter((p: any) => asignacionesDe(p.id).length > 0)" :key="p.id" class="card mb-3">
            <p class="font-medium text-sm mb-2">{{ p.postulante?.name ?? p.user_id }} · {{ p.plaza?.asignatura ?? '—' }}</p>
            <div class="flex items-center gap-2" style="flex-wrap:wrap">
              <div v-for="a in asignacionesDe(p.id)" :key="a.id" class="flex items-center gap-2">
                <span class="badge badge-blue">{{ a.evaluador?.name ?? a.evaluador_id }}</span>
                <button
                  class="btn btn-ghost btn-icon btn-sm"
                  :disabled="quitando[a.id]"
                  title="Quitar asignación"
                  @click="quitarAsignacion(a.id)"
                ><Icon name="x" :size="14" /></button>
              </div>
            </div>
          </div>
        </div>

        <aside class="asignaciones-carga">
          <h3 class="font-semibold mb-3">Evaluadores — carga actual</h3>
          <div v-for="e in evaluadoresPorCarga" :key="e.id" class="carga-row">
            <span class="text-sm">{{ e.name }}</span>
            <span class="text-xs" :class="cargaDe(e.id) === 0 ? 'text-muted' : ''">
              {{ cargaDe(e.id) === 0 ? 'libre' : `${cargaDe(e.id)} activa${cargaDe(e.id) === 1 ? '' : 's'}` }}
            </span>
          </div>
        </aside>
      </div>
    </div>

    <!-- Modal plaza -->
    <div v-if="showPlazaModal" class="modal-overlay" @click.self="showPlazaModal = false">
      <div class="modal">
        <div class="modal-header">
          <h2>Agregar plaza</h2>
          <button class="btn btn-ghost btn-icon" @click="showPlazaModal = false"><Icon name="x" :size="18" /></button>
        </div>
        <div class="modal-body">
          <div class="form-group mb-4">
            <label class="form-label">Facultad <span class="required">*</span></label>
            <input v-model="plazaForm.facultad" class="form-control" placeholder="Facultad de Ingeniería" />
          </div>
          <div class="grid-2">
            <div class="form-group">
              <label class="form-label">Departamento <span class="required">*</span></label>
              <input v-model="plazaForm.departamento" class="form-control" />
            </div>
            <div class="form-group">
              <label class="form-label">Asignatura <span class="required">*</span></label>
              <input v-model="plazaForm.asignatura" class="form-control" />
            </div>
          </div>
          <div class="grid-2 mt-4">
            <div class="form-group">
              <label class="form-label">Modalidad</label>
              <select v-model="plazaForm.modalidad" class="form-control">
                <option value="">—</option>
                <option>Tiempo completo</option>
                <option>Tiempo parcial</option>
                <option>Por horas</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Horas / semana</label>
              <input v-model="plazaForm.horas_semana" class="form-control" placeholder="20" />
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" @click="showPlazaModal = false">Cancelar</button>
          <button class="btn btn-primary" :disabled="savingPlaza" @click="guardarPlaza">
            <span v-if="savingPlaza" class="spinner"></span>
            Guardar plaza
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
