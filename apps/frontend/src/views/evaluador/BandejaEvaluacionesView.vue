<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/services/api'
import Icon from '@/components/ui/Icon.vue'
import ProgressCard from '@/components/ui/ProgressCard.vue'

const router       = useRouter()
const evaluaciones = ref<any[]>([])
const pendientes   = ref<any[]>([]) // asignaciones sin evaluación creada todavía
const loading       = ref(true)
const error         = ref('')
const iniciando      = ref<number | null>(null) // postulacion_id en curso
const mostrarCerradas = ref(false)

onMounted(cargar)

async function cargar() {
  loading.value = true
  try {
    // GET /evaluaciones devuelve las evaluaciones ya iniciadas (asignadas al
    // usuario) con postulacion.plaza, postulacion.convocatoria y
    // postulacion.postulante eager-loaded.
    const { data } = await api.get('/evaluaciones')
    evaluaciones.value = data.data ?? data

    // GET /asignaciones devuelve (para el rol evaluador) solo las propias.
    // Las que todavía no tienen evaluación creada se muestran aparte, con
    // acción para iniciarla.
    const { data: asigData } = await api.get('/asignaciones')
    const asignaciones = asigData.data ?? asigData
    pendientes.value = asignaciones.filter((a: any) => !a.postulacion?.evaluacion)
  } catch (e: any) {
    error.value = e.response?.data?.message || 'Error al cargar evaluaciones.'
  } finally {
    loading.value = false
  }
}

async function iniciarEvaluacion(postulacionId: number) {
  iniciando.value = postulacionId
  try {
    const { data } = await api.post(`/postulaciones/${postulacionId}/evaluacion`)
    router.push(`/evaluaciones/${data.id}`)
  } catch (e: any) {
    alert(e.response?.data?.message || 'No se pudo iniciar la evaluación')
  } finally {
    iniciando.value = null
  }
}

// Ordenar por cercanía al cierre de la convocatoria — no por orden de
// inserción en BD, que no comunica ninguna prioridad real.
function diasParaCierre(fechaFin?: string | null): number | null {
  if (!fechaFin) return null
  return Math.ceil((new Date(fechaFin).getTime() - Date.now()) / 86400000)
}

function tonoPorFecha(fechaFin?: string | null): 'urgent' | 'default' {
  const dias = diasParaCierre(fechaFin)
  return dias !== null && dias <= 5 ? 'urgent' : 'default'
}

function metaAsignacion(a: any): string {
  const base = `${a.postulacion?.plaza?.asignatura ?? '—'} · ${a.convocatoria?.codigo ?? '—'}`
  const dias = diasParaCierre(a.convocatoria?.fecha_fin)
  if (dias !== null && dias >= 0 && dias <= 5) return `${base} · convocatoria cierra en ${dias} día${dias === 1 ? '' : 's'}`
  return base
}

function metaEvaluacion(ev: any): string {
  const base = `${ev.postulacion?.plaza?.asignatura ?? '—'} · ${ev.postulacion?.convocatoria?.codigo ?? '—'}`
  if (ev.estado === 'completada' && ev.puntaje_total !== null) return `${base} · calculado: ${ev.puntaje_total} pts — falta cerrar`
  return `${base} · calificación en curso`
}

const pendientesOrdenadas = computed(() =>
  [...pendientes.value].sort((a, b) =>
    (diasParaCierre(a.convocatoria?.fecha_fin) ?? 999) - (diasParaCierre(b.convocatoria?.fecha_fin) ?? 999)
  )
)
const enProgreso = computed(() =>
  evaluaciones.value
    .filter((e) => e.estado !== 'cerrada')
    .sort((a, b) =>
      (diasParaCierre(a.postulacion?.convocatoria?.fecha_fin) ?? 999) - (diasParaCierre(b.postulacion?.convocatoria?.fecha_fin) ?? 999)
    )
)
const cerradas = computed(() => evaluaciones.value.filter((e) => e.estado === 'cerrada'))
</script>

<template>
  <div>
    <div class="page-header">
      <div>
        <h1>Bandeja de evaluaciones</h1>
        <p>Expedientes asignados para calificar</p>
      </div>
    </div>

    <div v-if="loading" class="loading-center"><span class="spinner"></span></div>

    <div v-else-if="error" class="alert alert-error">{{ error }}</div>

    <div v-else-if="evaluaciones.length === 0 && pendientes.length === 0" class="card">
      <div class="empty-state">
        <h3>Sin evaluaciones asignadas</h3>
        <p>No tienes expedientes pendientes de calificar en este momento.</p>
      </div>
    </div>

    <template v-else>
      <!-- Asignadas pero aún sin iniciar -->
      <div v-if="pendientesOrdenadas.length > 0" class="mb-4">
        <h3 class="bandeja-section-title">Sin iniciar ({{ pendientesOrdenadas.length }})</h3>
        <ProgressCard
          v-for="a in pendientesOrdenadas"
          :key="a.id"
          :title="a.postulacion?.postulante?.name ?? '—'"
          :meta="metaAsignacion(a)"
          :tone="tonoPorFecha(a.convocatoria?.fecha_fin)"
        >
          <template #action>
            <button
              class="btn btn-primary btn-sm"
              :disabled="iniciando === a.postulacion_id"
              @click="iniciarEvaluacion(a.postulacion_id)"
            >
              <span v-if="iniciando === a.postulacion_id" class="spinner"></span>
              Iniciar evaluación →
            </button>
          </template>
        </ProgressCard>
      </div>

      <!-- Ya iniciadas, aún no cerradas -->
      <div v-if="enProgreso.length > 0" class="mb-4">
        <h3 class="bandeja-section-title">En progreso ({{ enProgreso.length }})</h3>
        <ProgressCard
          v-for="ev in enProgreso"
          :key="ev.id"
          :title="ev.postulacion?.postulante?.name ?? '—'"
          :meta="metaEvaluacion(ev)"
          :tone="tonoPorFecha(ev.postulacion?.convocatoria?.fecha_fin)"
        >
          <template #action>
            <RouterLink :to="`/evaluaciones/${ev.id}`" class="btn btn-secondary btn-sm">Continuar →</RouterLink>
          </template>
        </ProgressCard>
      </div>

      <!-- Cerradas — colapsadas, ya no requieren acción -->
      <div v-if="cerradas.length > 0">
        <button type="button" class="bandeja-toggle" @click="mostrarCerradas = !mostrarCerradas">
          <Icon :name="mostrarCerradas ? 'chevron-up' : 'chevron-down'" :size="14" />
          Cerradas ({{ cerradas.length }})
        </button>
        <div v-if="mostrarCerradas" class="mt-2">
          <ProgressCard
            v-for="ev in cerradas"
            :key="ev.id"
            :title="ev.postulacion?.postulante?.name ?? '—'"
            :meta="`${ev.postulacion?.plaza?.asignatura ?? '—'} · ${ev.postulacion?.convocatoria?.codigo ?? '—'} · ${ev.puntaje_total ?? '—'} pts`"
            tone="success"
          >
            <template #action>
              <RouterLink :to="`/evaluaciones/${ev.id}`" class="btn btn-ghost btn-sm">Ver →</RouterLink>
            </template>
          </ProgressCard>
        </div>
      </div>
    </template>
  </div>
</template>
