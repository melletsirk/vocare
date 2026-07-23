<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import Icon from '@/components/ui/Icon.vue'
import { initials as getInitials } from '@/utils/initials'

const auth   = useAuthStore()
const route  = useRoute()
const router = useRouter()

// Sidebar (hamburguesa) — colapsada por default en viewports angostos;
// en desktop el CSS la mantiene siempre visible sin importar este estado.
const sidebarOpen = ref(false)
watch(() => route.path, () => { sidebarOpen.value = false })

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

const initials = computed(() => getInitials(auth.user?.name))

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
          <Icon :name="item.icon" />
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
          <Icon name="logout" />
        </div>
      </div>
    </aside>

    <!-- ── Main content ──────────────────────────────────────────── -->
    <main class="main-content">
      <header class="topbar">
        <div class="flex items-center gap-3">
          <button class="hamburger-btn" aria-label="Abrir menú" @click="sidebarOpen = !sidebarOpen">
            <Icon name="menu" />
          </button>
          <div class="topbar-title">Vocare — Sistema de Convocatorias</div>
        </div>
        <span class="badge badge-blue">{{ ROL_LABEL[auth.rol ?? ''] ?? auth.rol }}</span>
      </header>

      <div class="page-content">
        <RouterView />
      </div>
    </main>
  </div>
</template>
