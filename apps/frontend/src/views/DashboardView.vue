<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import api from '@/services/api'
import ProgressCard from '@/components/ui/ProgressCard.vue'

const auth   = useAuthStore()
const router = useRouter()

interface Alerta {
  key: string
  titulo: string
  meta: string
  to: string
  ctaLabel: string
  tone: 'urgent' | 'default'
}

const loading  = ref(true)
const alertas  = ref<Alerta[]>([])
const totalConvocatorias = ref(0)
const convocatoriasActivas = ref(0)

const DIAS_URGENTE  = 5   // convocatoria cierra pronto, sin evaluador
const DIAS_BORRADOR = 5   // lleva demasiado en borrador sin publicar

onMounted(async () => {
  // El dashboard genérico solo tiene sentido para admin — evaluador y
  // postulante aterrizan directo en su cola de trabajo (design.md: no
  // interponer una pantalla intermedia sin decisión propia).
  if (auth.rol === 'postulante') { router.replace('/mis-postulaciones'); return }
  if (auth.rol === 'evaluador')  { router.replace('/evaluaciones'); return }

  await cargarFeed()
})

async function cargarFeed() {
  loading.value = true
  try {
    const { data } = await api.get('/convocatorias', { params: { per_page: 100 } })
    const convs = data.data ?? data
    totalConvocatorias.value   = data.total ?? convs.length
    convocatoriasActivas.value = convs.filter((c: any) => c.estado === 'publicada' || c.estado === 'en_proceso').length

    const resultado: Alerta[] = []

    // 1) Borradores estancados — nadie los va a publicar solos.
    const ahora = Date.now()
    for (const c of convs) {
      if (c.estado !== 'borrador') continue
      const dias = Math.floor((ahora - new Date(c.created_at).getTime()) / 86400000)
      if (dias >= DIAS_BORRADOR) {
        resultado.push({
          key: `borrador-${c.id}`,
          titulo: `${c.nombre} sigue en borrador`,
          meta: `${c.codigo} · creada hace ${dias} días`,
          to: `/convocatorias/${c.id}`,
          ctaLabel: 'Revisar y publicar →',
          tone: 'default',
        })
      }
    }

    // 2) Convocatorias por cerrar con postulaciones sin evaluador asignado.
    const porCerrarPronto = convs.filter((c: any) => {
      if (!['publicada', 'en_proceso'].includes(c.estado)) return false
      const dias = Math.ceil((new Date(c.fecha_fin).getTime() - ahora) / 86400000)
      return dias >= 0 && dias <= DIAS_URGENTE
    })
    for (const c of porCerrarPronto) {
      const [postRes, asigRes] = await Promise.all([
        api.get('/postulaciones', { params: { convocatoria_id: c.id } }),
        api.get('/asignaciones',  { params: { convocatoria_id: c.id } }),
      ])
      const enviadas = (postRes.data.data ?? postRes.data).filter((p: any) => p.fecha_envio)
      const asignaciones = asigRes.data.data ?? asigRes.data
      const sinAsignar = enviadas.filter((p: any) => !asignaciones.some((a: any) => a.postulacion_id === p.id))
      if (sinAsignar.length > 0) {
        const dias = Math.ceil((new Date(c.fecha_fin).getTime() - ahora) / 86400000)
        resultado.push({
          key: `sin-asignar-${c.id}`,
          titulo: `${c.nombre} cierra en ${dias} día${dias === 1 ? '' : 's'}`,
          meta: `${c.codigo} · ${sinAsignar.length} postulación${sinAsignar.length === 1 ? '' : 'es'} sin evaluador asignado`,
          to: `/convocatorias/${c.id}`,
          ctaLabel: 'Asignar evaluadores →',
          tone: 'urgent',
        })
      }
    }

    // 3) Empates pendientes de resolver en convocatorias ya cerradas.
    const cerradas = convs.filter((c: any) => c.estado === 'cerrada')
    for (const c of cerradas) {
      const { data: resultados } = await api.get(`/convocatorias/${c.id}/resultados`)
      const pendientes = resultados.filter((r: any) => r.estado === 'empate_pendiente')
      if (pendientes.length > 0) {
        const plazas = new Set(pendientes.map((r: any) => r.plaza_id)).size
        resultado.push({
          key: `empate-${c.id}`,
          titulo: `${c.nombre} tiene un empate sin resolver`,
          meta: `${c.codigo} · ${plazas} plaza${plazas === 1 ? '' : 's'} con posiciones empatadas`,
          to: `/convocatorias/${c.id}/resultados`,
          ctaLabel: 'Resolver empate →',
          tone: 'urgent',
        })
      }
    }

    // Urgentes primero.
    alertas.value = resultado.sort((a, b) => (a.tone === b.tone ? 0 : a.tone === 'urgent' ? -1 : 1))
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div v-if="loading" class="loading-center"><span class="spinner"></span> Cargando...</div>

  <div v-else>
    <div class="page-header">
      <div>
        <h1>Hola, {{ auth.user?.name?.split(' ')[0] }}</h1>
        <p>Esto es lo que necesita tu atención hoy</p>
      </div>
    </div>

    <template v-if="alertas.length > 0">
      <h3 class="font-semibold mb-3">Requiere tu atención</h3>
      <ProgressCard
        v-for="a in alertas"
        :key="a.key"
        :title="a.titulo"
        :meta="a.meta"
        :tone="a.tone"
      >
        <template #action>
          <RouterLink :to="a.to" class="btn btn-secondary btn-sm">{{ a.ctaLabel }}</RouterLink>
        </template>
      </ProgressCard>
    </template>

    <div v-else class="card empty-state">
      <h3>Todo al día</h3>
      <p>Sin alertas pendientes en este momento.</p>
    </div>

    <p class="feed-footer">
      {{ convocatoriasActivas }} convocatoria{{ convocatoriasActivas === 1 ? '' : 's' }} activa{{ convocatoriasActivas === 1 ? '' : 's' }}
      · {{ totalConvocatorias }} en total ·
      <RouterLink to="/convocatorias">ver todas →</RouterLink>
    </p>
  </div>
</template>
