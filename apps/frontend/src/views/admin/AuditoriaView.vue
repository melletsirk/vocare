<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import api from '@/services/api'

const logs    = ref<any[]>([])
const loading = ref(true)
const filtroEvento = ref('')
const filtroDesde  = ref('')

onMounted(cargar)

async function cargar() {
  loading.value = true
  const params: any = {}
  if (filtroEvento.value) params.evento = filtroEvento.value
  if (filtroDesde.value)  params.desde  = filtroDesde.value
  const { data } = await api.get('/auditoria', { params })
  logs.value = data.data ?? data
  loading.value = false
}

// "convocatoria.creada" → "convocatoria — creada" — sin mantener un mapa de
// traducción para cada evento posible que exista hoy o se agregue después.
function humanizar(event: string): string {
  return event.split('.').map((p) => p.replace(/_/g, ' ')).join(' — ')
}

// Un punto de color discreto, no una píldora saturada — basta para escanear
// qué tipo de evento fue sin competir visualmente con el texto.
function categoria(event: string): 'success' | 'danger' | 'warn' | 'neutral' {
  if (/rechazad|desactivad|eliminad/.test(event)) return 'danger'
  if (/observad|empate/.test(event)) return 'warn'
  if (/cerrad|activad|public|aprobad|resuelto/.test(event)) return 'success'
  return 'neutral'
}

const gruposPorDia = computed(() => {
  const mapa = new Map<string, any[]>()
  for (const log of logs.value) {
    const key = new Date(log.created_at).toLocaleDateString('es-PE', { day: 'numeric', month: 'long', year: 'numeric' })
    if (!mapa.has(key)) mapa.set(key, [])
    mapa.get(key)!.push(log)
  }
  return [...mapa.entries()]
})
</script>

<template>
  <div>
    <div class="page-header">
      <h1>Auditoría</h1>
    </div>

    <div class="card mb-4" style="padding:1rem">
      <div class="flex gap-3 items-center" style="flex-wrap:wrap">
        <input v-model="filtroEvento" class="form-control" style="max-width:220px" placeholder="Filtrar por evento..." @input="cargar" />
        <input v-model="filtroDesde" type="date" class="form-control" style="max-width:160px" @change="cargar" />
      </div>
    </div>

    <div v-if="loading" class="loading-center"><span class="spinner"></span></div>

    <div v-else-if="logs.length === 0" class="card">
      <div class="empty-state">
        <h3>Sin eventos</h3>
        <p>No hay actividad registrada que coincida con los filtros.</p>
      </div>
    </div>

    <div v-else>
      <div v-for="[dia, items] in gruposPorDia" :key="dia" class="audit-day-group">
        <div class="audit-day-label">{{ dia }}</div>
        <div v-for="log in items" :key="log.id" class="audit-row">
          <span class="audit-time">{{ new Date(log.created_at).toLocaleTimeString('es-PE', { hour: '2-digit', minute: '2-digit' }) }}</span>
          <span class="audit-dot" :class="`is-${categoria(log.event)}`"></span>
          <span class="audit-actor">{{ log.user?.name ?? 'Sistema' }}</span>
          <span class="audit-event">{{ humanizar(log.event) }}</span>
          <span class="audit-ip">{{ log.ip_address ?? '' }}</span>
        </div>
      </div>
    </div>
  </div>
</template>
