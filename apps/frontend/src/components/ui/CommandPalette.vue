<script setup lang="ts">
import { ref, computed, watch, nextTick, onMounted, onBeforeUnmount } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import api from '@/services/api'
import Icon from '@/components/ui/Icon.vue'

const router = useRouter()
const auth   = useAuthStore()

const visible  = ref(false)
const query    = ref('')
const selected = ref(0)
const inputRef = ref<HTMLInputElement | null>(null)

interface Resultado { key: string; label: string; sublabel?: string; icon: string; to: string }

// ── Destinos estáticos — mismo criterio por rol que la navegación lateral ──
const destinosEstaticos = computed<Resultado[]>(() => {
  const rol = auth.rol
  const items: Resultado[] = []
  if (rol === 'admin') items.push({ key: 'nav-dashboard', label: 'Dashboard', icon: 'grid', to: '/dashboard' })
  items.push({ key: 'nav-convocatorias', label: 'Convocatorias', icon: 'clipboard', to: '/convocatorias' })
  if (rol === 'postulante') items.push({ key: 'nav-postulaciones', label: 'Mis postulaciones', icon: 'file-text', to: '/mis-postulaciones' })
  if (rol === 'evaluador' || rol === 'admin') items.push({ key: 'nav-evaluaciones', label: 'Evaluaciones', icon: 'check-square', to: '/evaluaciones' })
  if (rol === 'admin') {
    items.push({ key: 'nav-tablas', label: 'Tablas de evaluación', icon: 'layers', to: '/admin/tablas-evaluacion' })
    items.push({ key: 'nav-auditoria', label: 'Auditoría', icon: 'activity', to: '/auditoria' })
    items.push({ key: 'nav-usuarios', label: 'Usuarios', icon: 'users', to: '/admin/usuarios' })
  }
  return items
})

// ── Convocatorias — se cargan una vez, al abrir el palette por primera vez ──
const convocatorias = ref<Resultado[]>([])
const cargandoConvocatorias = ref(false)
let convocatoriasCargadas = false

async function cargarConvocatorias() {
  if (convocatoriasCargadas) return
  cargandoConvocatorias.value = true
  try {
    const { data } = await api.get('/convocatorias', { params: { per_page: 100 } })
    const lista = data.data ?? data
    convocatorias.value = lista.map((c: any) => ({
      key: `conv-${c.id}`,
      label: c.nombre,
      sublabel: c.codigo,
      icon: 'clipboard',
      to: `/convocatorias/${c.id}`,
    }))
    convocatoriasCargadas = true
  } catch {
    // Búsqueda degradada a solo destinos estáticos — no bloquea el palette.
  } finally {
    cargandoConvocatorias.value = false
  }
}

const resultados = computed<Resultado[]>(() => {
  const q = query.value.trim().toLowerCase()
  const todos = [...destinosEstaticos.value, ...convocatorias.value]
  if (!q) return destinosEstaticos.value
  return todos.filter((r) =>
    r.label.toLowerCase().includes(q) || r.sublabel?.toLowerCase().includes(q)
  ).slice(0, 20)
})

watch(resultados, () => { selected.value = 0 })

function abrir() {
  visible.value = true
  query.value   = ''
  selected.value = 0
  cargarConvocatorias()
  nextTick(() => inputRef.value?.focus())
}
function cerrar() {
  visible.value = false
}
function ir(r: Resultado) {
  cerrar()
  router.push(r.to)
}
function mover(delta: number) {
  if (resultados.value.length === 0) return
  selected.value = (selected.value + delta + resultados.value.length) % resultados.value.length
}
function activarSeleccionado() {
  const r = resultados.value[selected.value]
  if (r) ir(r)
}

function onKeydown(e: KeyboardEvent) {
  const esAtajo = (e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k'
  if (esAtajo) {
    e.preventDefault()
    visible.value ? cerrar() : abrir()
    return
  }
  if (!visible.value) return
  if (e.key === 'Escape') { e.preventDefault(); cerrar(); return }
  if (e.key === 'ArrowDown') { e.preventDefault(); mover(1); return }
  if (e.key === 'ArrowUp') { e.preventDefault(); mover(-1); return }
  if (e.key === 'Enter') { e.preventDefault(); activarSeleccionado(); return }
}

onMounted(() => window.addEventListener('keydown', onKeydown))
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown))
</script>

<template>
  <button type="button" class="command-trigger" @click="abrir" title="Buscar (⌘K)">
    <Icon name="search" :size="14" />
    <span>Buscar</span>
    <kbd>⌘K</kbd>
  </button>

  <div v-if="visible" class="modal-overlay command-overlay" @click.self="cerrar">
    <div class="command-palette">
      <div class="command-input-row">
        <Icon name="search" :size="16" class="command-input-icon" />
        <input
          ref="inputRef"
          v-model="query"
          type="text"
          class="command-input"
          placeholder="Ir a una sección o convocatoria..."
        />
        <kbd>esc</kbd>
      </div>

      <div class="command-results">
        <div v-if="resultados.length === 0 && !cargandoConvocatorias" class="command-empty">
          Sin resultados para "{{ query }}"
        </div>
        <button
          v-for="(r, i) in resultados"
          :key="r.key"
          type="button"
          class="command-result"
          :class="{ 'is-selected': i === selected }"
          @mouseenter="selected = i"
          @click="ir(r)"
        >
          <Icon :name="r.icon" :size="15" />
          <span class="command-result-label">{{ r.label }}</span>
          <span v-if="r.sublabel" class="command-result-sublabel">{{ r.sublabel }}</span>
        </button>
        <div v-if="cargandoConvocatorias" class="command-empty text-xs">Cargando convocatorias...</div>
      </div>
    </div>
  </div>
</template>
