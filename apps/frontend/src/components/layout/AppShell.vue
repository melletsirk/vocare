<script setup lang="ts">
import { computed, h, defineComponent, ref, watch } from 'vue'
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const auth   = useAuthStore()
const route  = useRoute()
const router = useRouter()

// Sidebar (hamburguesa) — colapsada por default en viewports angostos;
// en desktop el CSS la mantiene siempre visible sin importar este estado.
const sidebarOpen = ref(false)
watch(() => route.path, () => { sidebarOpen.value = false })

// ── Íconos SVG inline ───────────────────────────────────────────────────────
const ICONS: Record<string, string> = {
  grid:          '<rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/>',
  clipboard:     '<path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><rect x="8" y="2" width="8" height="4" rx="1"/>',
  'file-text':   '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/>',
  'check-square':'<polyline points="9 11 12 14 22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
  activity:      '<polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/>',
  users:         '<path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
  logout:        '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/>',
  menu:          '<line x1="3" y1="6" x2="21" y2="6"/><line x1="3" y1="12" x2="21" y2="12"/><line x1="3" y1="18" x2="21" y2="18"/>',
}

const NavIcon = defineComponent({
  props: { name: { type: String, required: true } },
  render() {
    return h('svg', {
      xmlns: 'http://www.w3.org/2000/svg',
      width: 18, height: 18,
      viewBox: '0 0 24 24',
      fill: 'none',
      stroke: 'currentColor',
      'stroke-width': 2,
      'stroke-linecap': 'round',
      'stroke-linejoin': 'round',
      innerHTML: ICONS[this.name] ?? '',
    })
  },
})

// ── Navegación por rol ───────────────────────────────────────────────────────
const navItems = computed(() => {
  const rol   = auth.rol
  const items = [
    { to: '/dashboard',     label: 'Dashboard',     icon: 'grid' },
    { to: '/convocatorias', label: 'Convocatorias', icon: 'clipboard' },
  ]
  if (rol === 'postulante') {
    items.push({ to: '/mis-postulaciones', label: 'Mis Postulaciones', icon: 'file-text' })
  }
  if (rol === 'evaluador' || rol === 'admin') {
    items.push({ to: '/evaluaciones', label: 'Evaluaciones', icon: 'check-square' })
  }
  if (rol === 'admin') {
    items.push({ to: '/auditoria',      label: 'Auditoría',  icon: 'activity' })
    items.push({ to: '/admin/usuarios', label: 'Usuarios',   icon: 'users' })
  }
  return items
})

const ROL_LABEL: Record<string, string> = {
  admin:      'Administrador',
  evaluador:  'Evaluador',
  postulante: 'Postulante',
}

const initials = computed(() =>
  (auth.user?.name ?? '')
    .split(' ')
    .map((w: string) => w[0])
    .join('')
    .slice(0, 2)
    .toUpperCase()
)

function isActive(to: string) {
  return to !== '/' && route.path.startsWith(to)
}

async function handleLogout() {
  await auth.logout()
  router.push('/login')
}
</script>

<template>
  <div class="app-shell">
    <!-- Backdrop — solo visible/activo en viewports angostos con la sidebar abierta -->
    <div class="sidebar-backdrop" :class="{ open: sidebarOpen }" @click="sidebarOpen = false"></div>

    <!-- ── Sidebar (hamburguesa en mobile, siempre visible en desktop) ──── -->
    <aside class="sidebar" :class="{ open: sidebarOpen }">
      <div class="sidebar-logo">
        <div class="sidebar-logo-mark">V</div>
        <div>
          <div class="sidebar-logo-text">Vocare</div>
          <div class="sidebar-logo-sub">Convocatorias Docentes</div>
        </div>
      </div>

      <nav class="sidebar-nav">
        <div class="nav-section-label">Navegación</div>
        <RouterLink
          v-for="item in navItems"
          :key="item.to"
          :to="item.to"
          class="nav-item"
          :class="{ active: isActive(item.to) }"
        >
          <NavIcon :name="item.icon" />
          {{ item.label }}
        </RouterLink>
      </nav>

      <div class="sidebar-footer">
        <div class="user-menu" @click="handleLogout" title="Cerrar sesión">
          <div class="user-avatar">{{ initials }}</div>
          <div style="flex:1;min-width:0">
            <div class="user-name truncate">{{ auth.user?.name }}</div>
            <div class="user-role">{{ ROL_LABEL[auth.rol ?? ''] ?? auth.rol }}</div>
          </div>
          <NavIcon name="logout" />
        </div>
      </div>
    </aside>

    <!-- ── Main content ──────────────────────────────────────────── -->
    <main class="main-content">
      <header class="topbar">
        <div class="flex items-center gap-3">
          <button class="hamburger-btn" aria-label="Abrir menú" @click="sidebarOpen = !sidebarOpen">
            <NavIcon name="menu" />
          </button>
          <div style="font-size:1rem;font-weight:600;color:var(--clr-gray-800)">
            Vocare — Sistema de Convocatorias
          </div>
        </div>
        <span class="badge badge-blue">{{ ROL_LABEL[auth.rol ?? ''] ?? auth.rol }}</span>
      </header>

      <div class="page-content">
        <RouterView />
      </div>
    </main>
  </div>
</template>
