<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import api from '@/services/api'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const auth  = useAuthStore()
const id    = route.params.id

const resultados   = ref<any[]>([])
const plazas       = ref<any[]>([])
const convocatoria = ref<any>(null)
const loading      = ref(true)
const publishing   = ref(false)
const published    = ref(false)
const generando    = reactive<Record<number, boolean>>({})   // por plaza_id
const declarando   = reactive<Record<number, boolean>>({})   // por plaza_id
const resolviendo  = reactive<Record<string, boolean>>({})   // por `${plazaId}-${posicionInicio}`
const error        = ref('')

const estadoRes: Record<string, string> = {
  ganador: 'badge-green', reserva: 'badge-blue',
  no_ganador: 'badge-gray', desierta: 'badge-red',
  empate_pendiente: 'badge-yellow',
}

const canManage = auth.isAdmin || auth.isEvaluador

onMounted(cargar)

async function cargar() {
  loading.value = true
  try {
    const [cRes, pRes, rRes] = await Promise.all([
      api.get(`/convocatorias/${id}`),
      api.get(`/convocatorias/${id}/plazas`),
      api.get(`/convocatorias/${id}/resultados`),
    ])
    convocatoria.value = cRes.data
    plazas.value       = pRes.data
    resultados.value   = rRes.data
    published.value    = resultados.value.some((r: any) => r.publicado_en)
  } finally {
    loading.value = false
  }
}

function resultadosDePlaza(plazaId: number) {
  return resultados.value.filter((r) => r.plaza_id === plazaId)
}

// Grupos de empate pendiente dentro de una plaza, agrupados por la
// posición de inicio que comparten (ver ResultadosService::resolverEmpate).
function gruposEmpatePendiente(plazaId: number) {
  const pendientes = resultadosDePlaza(plazaId).filter((r) => r.estado === 'empate_pendiente')
  const grupos = new Map<number, any[]>()
  for (const r of pendientes) {
    if (!grupos.has(r.posicion)) grupos.set(r.posicion, [])
    grupos.get(r.posicion)!.push(r)
  }
  return grupos
}

async function generarRanking(plazaId: number) {
  error.value = ''
  generando[plazaId] = true
  try {
    await api.post(`/convocatorias/${id}/plazas/${plazaId}/ranking`)
    await cargar()
  } catch (e: any) {
    error.value = e.response?.data?.message || 'No se pudo generar el ranking'
  } finally {
    generando[plazaId] = false
  }
}

async function declararDesierta(plazaId: number) {
  if (!confirm('¿Declarar esta plaza desierta? Se perderá el ranking generado.')) return
  error.value = ''
  declarando[plazaId] = true
  try {
    await api.post(`/convocatorias/${id}/plazas/${plazaId}/desierta`)
    await cargar()
  } catch (e: any) {
    error.value = e.response?.data?.message || 'No se pudo declarar la plaza desierta'
  } finally {
    declarando[plazaId] = false
  }
}

// Orden local editable por grupo de empate, antes de confirmar.
const ordenLocal = reactive<Record<string, any[]>>({})

function claveGrupo(plazaId: number, posicionInicio: number) {
  return `${plazaId}-${posicionInicio}`
}

function ordenDe(plazaId: number, posicionInicio: number, items: any[]) {
  const key = claveGrupo(plazaId, posicionInicio)
  if (!ordenLocal[key]) ordenLocal[key] = [...items]
  return ordenLocal[key]
}

function mover(plazaId: number, posicionInicio: number, index: number, delta: number) {
  const key   = claveGrupo(plazaId, posicionInicio)
  const lista = ordenLocal[key]
  const destino = index + delta
  if (destino < 0 || destino >= lista.length) return
  const [item] = lista.splice(index, 1)
  lista.splice(destino, 0, item)
}

async function confirmarDesempate(plazaId: number, posicionInicio: number) {
  const key   = claveGrupo(plazaId, posicionInicio)
  const orden = ordenLocal[key]
  if (!orden?.length) return

  if (!confirm('¿Confirmar este orden como decisión de la comisión? Quedará registrado con tu usuario y la hora actual.')) return

  error.value = ''
  resolviendo[key] = true
  try {
    await api.post(`/convocatorias/${id}/plazas/${plazaId}/resultados/desempatar`, {
      posicion_inicio: posicionInicio,
      orden: orden.map((r) => r.postulacion_id),
    })
    delete ordenLocal[key]
    await cargar()
  } catch (e: any) {
    error.value = e.response?.data?.message || 'No se pudo registrar el desempate'
  } finally {
    resolviendo[key] = false
  }
}

async function publicar() {
  if (!confirm('¿Publicar los resultados? Los postulantes podrán ver su posición y estado.')) return
  error.value = ''
  publishing.value = true
  try {
    await api.post(`/convocatorias/${id}/resultados/publicar`)
    published.value = true
  } catch (e: any) {
    error.value = e.response?.data?.message || 'No se pudo publicar'
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
        v-if="canManage && !published"
        class="btn btn-primary"
        :disabled="publishing || resultados.length === 0"
        @click="publicar"
      >
        <span v-if="publishing" class="spinner"></span>
        📣 Publicar resultados
      </button>
      <span v-else-if="published" class="badge badge-green" style="font-size:0.875rem;padding:0.5rem 1rem">
        ✅ Publicados
      </span>
    </div>

    <div v-if="error" class="alert alert-error mb-4">{{ error }}</div>

    <div v-if="plazas.length === 0" class="card">
      <div class="empty-state">
        <h3>Sin plazas registradas</h3>
      </div>
    </div>

    <template v-else>
      <div v-for="plaza in plazas" :key="plaza.id" class="card mb-4">
        <div class="card-header">
          <div>
            <h3 class="card-title">{{ plaza.asignatura }}</h3>
            <p class="text-sm text-muted">{{ plaza.facultad }} — {{ plaza.departamento }}</p>
          </div>
          <div class="flex items-center gap-2">
            <span class="badge" :class="plaza.estado === 'desierta' ? 'badge-red' : (plaza.estado === 'cubierta' ? 'badge-green' : 'badge-gray')">
              {{ plaza.estado }}
            </span>
            <template v-if="canManage && !published">
              <button
                class="btn btn-secondary btn-sm"
                :disabled="generando[plaza.id]"
                @click="generarRanking(plaza.id)"
              >
                <span v-if="generando[plaza.id]" class="spinner"></span>
                {{ resultadosDePlaza(plaza.id).length ? 'Recalcular ranking' : 'Generar ranking' }}
              </button>
              <button
                v-if="plaza.estado !== 'desierta'"
                class="btn btn-ghost btn-sm"
                :disabled="declarando[plaza.id]"
                @click="declararDesierta(plaza.id)"
              >
                Declarar desierta
              </button>
            </template>
          </div>
        </div>

        <div v-if="resultadosDePlaza(plaza.id).length === 0" class="empty-state">
          <p>Sin ranking generado todavía.</p>
        </div>

        <template v-else>
          <div class="table-wrap">
            <table>
              <thead>
                <tr>
                  <th>#</th>
                  <th>Postulante</th>
                  <th>Puntaje</th>
                  <th>Resultado</th>
                  <th>Decisión de empate</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="r in resultadosDePlaza(plaza.id).slice().sort((a, b) => a.posicion - b.posicion)"
                  :key="r.id"
                  :style="r.estado === 'ganador' ? 'background:var(--clr-success-50)' : ''"
                >
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
                    <span class="badge" :class="estadoRes[r.estado] ?? 'badge-gray'">{{ r.estado }}</span>
                  </td>
                  <td>
                    <span v-if="r.orden_manual" class="badge badge-blue" title="Decidido manualmente por la comisión">
                      Manual — {{ r.decidido_en ? new Date(r.decidido_en).toLocaleString('es-PE') : '' }}
                    </span>
                    <span v-else-if="r.empatada" class="text-muted text-sm">Empatada, no afectó el orden final</span>
                    <span v-else class="text-muted text-sm">—</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <!-- Grupos empatados pendientes de decisión manual de la comisión -->
          <div
            v-for="[posicionInicio, items] in gruposEmpatePendiente(plaza.id)"
            :key="`${plaza.id}-${posicionInicio}`"
            class="alert alert-warning mt-3"
          >
            <p class="font-semibold mb-2">
              ⚠️ Empate en la posición {{ posicionInicio }} — la comisión debe decidir el orden
            </p>
            <p class="text-sm mb-3">
              Estos postulantes tienen el mismo puntaje. Ordénalos según la decisión de la
              comisión (arriba = mejor posición) y confirma — quedará registrado con tu usuario
              y la hora actual.
            </p>

            <ol class="mb-3" style="list-style:none;padding:0">
              <li
                v-for="(r, idx) in ordenDe(plaza.id, posicionInicio, items)"
                :key="r.id"
                class="flex items-center gap-3 mb-2"
                style="padding:0.5rem;border:1px solid var(--surface-border);border-radius:6px"
              >
                <span class="font-semibold">{{ posicionInicio + idx }}</span>
                <div class="flex-1">
                  <div class="font-medium">{{ r.postulacion?.postulante?.name ?? '—' }}</div>
                  <div class="text-xs text-muted">Puntaje: {{ r.puntaje_total }}</div>
                </div>
                <button class="btn btn-ghost btn-sm" :disabled="idx === 0" @click="mover(plaza.id, posicionInicio, idx, -1)">↑</button>
                <button
                  class="btn btn-ghost btn-sm"
                  :disabled="idx === ordenDe(plaza.id, posicionInicio, items).length - 1"
                  @click="mover(plaza.id, posicionInicio, idx, 1)"
                >↓</button>
              </li>
            </ol>

            <button
              v-if="canManage"
              class="btn btn-primary btn-sm"
              :disabled="resolviendo[claveGrupo(plaza.id, posicionInicio)]"
              @click="confirmarDesempate(plaza.id, posicionInicio)"
            >
              <span v-if="resolviendo[claveGrupo(plaza.id, posicionInicio)]" class="spinner"></span>
              Confirmar orden decidido
            </button>
          </div>
        </template>
      </div>
    </template>
  </div>
</template>
