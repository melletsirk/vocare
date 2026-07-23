<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import api from '@/services/api'
import Icon from '@/components/ui/Icon.vue'

const router  = useRouter()
const tablas  = ref<any[]>([])
const loading = ref(true)
const forkeando = ref<number | null>(null)

const estadoBadge: Record<string, string> = { activo: 'badge-green', borrador: 'badge-indigo', archivado: 'badge-gray' }
const estadoLabel: Record<string, string> = { activo: 'Activo', borrador: 'Borrador', archivado: 'Archivado' }

onMounted(cargar)

async function cargar() {
  loading.value = true
  try {
    const [activasRes, borradoresRes] = await Promise.all([
      api.get('/tablas-evaluacion'),
      api.get('/tablas-evaluacion', { params: { estado: 'borrador' } }),
    ])
    const activas    = activasRes.data.data ?? activasRes.data
    const borradores = borradoresRes.data.data ?? borradoresRes.data
    tablas.value = [...activas, ...borradores]
  } finally {
    loading.value = false
  }
}

// Agrupado por anexo — el mismo codigo_anexo se reutiliza en cada fork.
const grupos = computed(() => {
  const mapa = new Map<string, any[]>()
  for (const t of tablas.value) {
    if (!mapa.has(t.codigo_anexo)) mapa.set(t.codigo_anexo, [])
    mapa.get(t.codigo_anexo)!.push(t)
  }
  return [...mapa.entries()]
    .map(([codigo, versiones]) => {
      const ordenadas = [...versiones].sort((a, b) => b.id - a.id)
      const activa = versiones.find((v) => v.estado === 'activo')
      const referencia = activa ?? ordenadas[0]
      return { codigo, referencia, versiones: ordenadas, activa }
    })
    .sort((a, b) => a.codigo.localeCompare(b.codigo))
})

async function nuevaVersionDesde(tablaId: number) {
  forkeando.value = tablaId
  try {
    const { data } = await api.post('/tablas-evaluacion', { clonar_de_id: tablaId })
    router.push(`/admin/tablas-evaluacion/${data.id}`)
  } catch (e: any) {
    alert(e.response?.data?.message || 'No se pudo crear la nueva versión')
  } finally {
    forkeando.value = null
  }
}
</script>

<template>
  <div>
    <div class="page-header">
      <div>
        <h1>Tablas de evaluación</h1>
        <p>Reglamento por anexo — cuando cambie una Resolución, se versiona aquí, no en código</p>
      </div>
    </div>

    <div v-if="loading" class="loading-center"><span class="spinner"></span></div>

    <div v-else-if="grupos.length === 0" class="card">
      <div class="empty-state">
        <h3>Sin tablas de evaluación</h3>
      </div>
    </div>

    <div v-else>
      <div v-for="grupo in grupos" :key="grupo.codigo" class="card mb-3">
        <div class="flex justify-between items-start">
          <div>
            <h3 class="font-semibold">{{ grupo.referencia.nombre }}</h3>
            <p class="text-xs text-muted">
              {{ grupo.codigo }} · {{ grupo.referencia.tipo_proceso }}
              <span v-if="grupo.referencia.modalidad"> · {{ grupo.referencia.modalidad }}</span>
            </p>
          </div>
          <button
            v-if="grupo.activa"
            class="btn btn-secondary btn-sm"
            :disabled="forkeando === grupo.activa.id"
            @click="nuevaVersionDesde(grupo.activa.id)"
          >
            <span v-if="forkeando === grupo.activa.id" class="spinner"></span>
            Nueva versión desde esta →
          </button>
        </div>

        <div class="tabla-eval-versiones">
          <RouterLink
            v-for="v in grupo.versiones"
            :key="v.id"
            :to="`/admin/tablas-evaluacion/${v.id}`"
            class="tabla-eval-version-row"
          >
            <span class="badge" :class="estadoBadge[v.estado] ?? 'badge-gray'">{{ estadoLabel[v.estado] ?? v.estado }}</span>
            <span class="text-xs text-muted">{{ v.reglamento_version?.numero_version ?? '—' }}</span>
            <span v-if="v.estado === 'borrador'" class="tabla-eval-version-cta">Continuar editando →</span>
            <Icon v-else name="chevron-down" :size="14" class="tabla-eval-version-chevron" />
          </RouterLink>
        </div>
      </div>
    </div>
  </div>
</template>
