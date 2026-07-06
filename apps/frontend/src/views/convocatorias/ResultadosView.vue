<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import api from '@/services/api'

const route = useRoute()
const id    = route.params.id

const resultados  = ref<any[]>([])
const convocatoria = ref<any>(null)
const loading      = ref(true)
const publishing   = ref(false)
const published    = ref(false)

const estadoRes: Record<string, string> = {
  ganador:'badge-green', reserva:'badge-blue',
  no_ganador:'badge-gray', desierta:'badge-red',
}

onMounted(async () => {
  const [cRes, rRes] = await Promise.all([
    api.get(`/convocatorias/${id}`),
    api.get(`/convocatorias/${id}/resultados`),
  ])
  convocatoria.value = cRes.data
  resultados.value   = rRes.data
  published.value    = resultados.value.some((r: any) => r.publicado_en)
  loading.value = false
})

// Agrupar por plaza
const porPlaza = () => {
  const map = new Map<number, any[]>()
  for (const r of resultados.value) {
    if (!map.has(r.plaza_id)) map.set(r.plaza_id, [])
    map.get(r.plaza_id)!.push(r)
  }
  return map
}

async function publicar() {
  if (!confirm('¿Publicar los resultados? Los postulantes podrán ver su posición y estado.')) return
  publishing.value = true
  try {
    await api.post(`/convocatorias/${id}/resultados/publicar`)
    published.value = true
  } finally {
    publishing.value = false
  }
}
</script>

<template>
  <div v-if="loading" class="loading-center"><span class="spinner"></span></div>

  <div v-else>
    <div class="page-header">
      <div>
        <h1>Resultados</h1>
        <p>{{ convocatoria?.nombre }}</p>
      </div>
      <button
        v-if="!published"
        class="btn btn-primary"
        :disabled="publishing || resultados.length === 0"
        @click="publicar"
      >
        <span v-if="publishing" class="spinner"></span>
        📣 Publicar resultados
      </button>
      <span v-else class="badge badge-green" style="font-size:0.875rem;padding:0.5rem 1rem">
        ✅ Publicados
      </span>
    </div>

    <div v-if="resultados.length === 0" class="card">
      <div class="empty-state">
        <h3>Sin resultados</h3>
        <p>Genera el ranking de cada plaza para ver los resultados aquí.</p>
      </div>
    </div>

    <template v-else>
      <div v-for="[plazaId, items] in porPlaza()" :key="plazaId" class="card mb-4">
        <div class="card-header">
          <div>
            <h3 class="card-title">{{ items[0]?.plaza?.asignatura }}</h3>
            <p class="text-sm text-muted">
              {{ items[0]?.plaza?.facultad }} — {{ items[0]?.plaza?.departamento }}
            </p>
          </div>
          <span class="badge" :class="items[0]?.estado === 'desierta' ? 'badge-red' : 'badge-green'">
            {{ items[0]?.estado === 'desierta' ? 'Desierta' : 'Cubierta' }}
          </span>
        </div>

        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>#</th>
                <th>Postulante</th>
                <th>Puntaje</th>
                <th>Resultado</th>
                <th>Empate (sorteo)</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="r in items.sort((a: any, b: any) => a.posicion - b.posicion)" :key="r.id"
                :style="r.estado === 'ganador' ? 'background:var(--clr-success-50)' : ''">
                <td class="font-semibold">{{ r.posicion }}</td>
                <td>
                  <div class="font-medium">{{ r.postulacion?.postulante?.name ?? '—' }}</div>
                  <div class="text-xs text-muted">{{ r.postulacion?.postulante?.email }}</div>
                </td>
                <td>
                  <span class="font-semibold text-lg" :style="r.estado === 'ganador' ? 'color:var(--clr-success-700)' : ''">
                    {{ r.puntaje_total }}
                  </span>
                </td>
                <td>
                  <span class="badge" :class="estadoRes[r.estado]">{{ r.estado }}</span>
                </td>
                <td>
                  <span v-if="r.empate_resuelto_por_sorteo" class="badge badge-yellow">Sí</span>
                  <span v-else class="text-muted text-sm">—</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>
  </div>
</template>
