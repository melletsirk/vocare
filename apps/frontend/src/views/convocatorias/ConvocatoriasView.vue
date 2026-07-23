<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import api from '@/services/api'

const auth  = useAuthStore()
const route = useRoute()

const convocatorias = ref<any[]>([])
const loading       = ref(true)
// Permite llegar aquí con un filtro ya aplicado vía URL (ej. compartir un
// enlace a "solo cerradas").
const filtroEstado  = ref(typeof route.query.estado === 'string' ? route.query.estado : '')

const ORDEN_ESTADOS = ['borrador', 'publicada', 'en_proceso', 'cerrada', 'desierta']
const estadoLabel: Record<string, string> = {
  borrador: 'Borrador', publicada: 'Publicada',
  en_proceso: 'En proceso', cerrada: 'Cerrada', desierta: 'Desierta',
}
const ctaLabel: Record<string, string> = {
  borrador: 'Continuar configurando →', publicada: 'Ver convocatoria →',
  en_proceso: 'Ver convocatoria →', cerrada: 'Ver resultados →', desierta: 'Ver convocatoria →',
}

onMounted(cargar)

async function cargar() {
  loading.value = true
  try {
    const params: any = { per_page: 100 }
    const { data } = await api.get('/convocatorias', { params })
    convocatorias.value = data.data ?? data
  } finally {
    loading.value = false
  }
}

function ctaTo(conv: any): string {
  return conv.estado === 'cerrada' ? `/convocatorias/${conv.id}/resultados` : `/convocatorias/${conv.id}`
}

// Agrupadas por estado — con un filtro activo, solo se muestra ese grupo;
// sin filtro, todas en el orden natural del proceso.
const grupos = computed(() => {
  const estados = filtroEstado.value ? [filtroEstado.value] : ORDEN_ESTADOS
  return estados
    .map((estado) => ({ estado, items: convocatorias.value.filter((c) => c.estado === estado) }))
    .filter((g) => g.items.length > 0)
})
</script>

<template>
  <div>
    <div class="page-header">
      <div>
        <h1>Convocatorias</h1>
        <p>Procesos de selección y contratación docente</p>
      </div>
      <RouterLink
        v-if="auth.isAdmin || auth.rol === 'admin_convocatoria'"
        to="/convocatorias/nueva"
        class="btn btn-primary"
      >
        + Nueva convocatoria
      </RouterLink>
    </div>

    <!-- Filtro por estado — chips, no dropdown -->
    <div class="filter-chips mb-4">
      <button type="button" class="filter-chip" :class="{ active: !filtroEstado }" @click="filtroEstado = ''">
        Todos
      </button>
      <button
        v-for="estado in ORDEN_ESTADOS"
        :key="estado"
        type="button"
        class="filter-chip"
        :class="{ active: filtroEstado === estado }"
        @click="filtroEstado = filtroEstado === estado ? '' : estado"
      >
        {{ estadoLabel[estado] }}
      </button>
    </div>

    <div v-if="loading" class="loading-center">
      <span class="spinner"></span> Cargando convocatorias...
    </div>

    <div v-else-if="grupos.length === 0" class="card">
      <div class="empty-state">
        <h3>Sin convocatorias</h3>
        <p>No hay convocatorias que coincidan con los filtros seleccionados.</p>
      </div>
    </div>

    <div v-else>
      <div v-for="grupo in grupos" :key="grupo.estado" class="mb-4">
        <h3 class="bandeja-section-title">{{ estadoLabel[grupo.estado] }} ({{ grupo.items.length }})</h3>
        <div class="plazas-grid">
          <RouterLink
            v-for="conv in grupo.items"
            :key="conv.id"
            :to="ctaTo(conv)"
            class="card plaza-card"
            style="text-decoration:none;color:inherit;display:flex;flex-direction:column"
          >
            <h3 class="font-semibold mb-1">{{ conv.nombre }}</h3>
            <p class="text-xs text-muted mb-3">{{ conv.codigo }} · {{ conv.tipo_proceso }}</p>
            <p class="text-xs text-muted" style="margin-top:auto">
              {{ conv.plazas_count ?? 0 }} plaza{{ (conv.plazas_count ?? 0) === 1 ? '' : 's' }} ·
              {{ new Date(conv.fecha_inicio).toLocaleDateString('es-PE') }} → {{ new Date(conv.fecha_fin).toLocaleDateString('es-PE') }}
            </p>
            <div class="plaza-card-foot">
              <span class="text-xs font-medium" style="color:var(--clr-primary-700)">{{ ctaLabel[conv.estado] }}</span>
            </div>
          </RouterLink>
        </div>
      </div>
    </div>
  </div>
</template>
