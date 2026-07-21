import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    // ── Pública ──────────────────────────────────────────────────
    { path: '/login',    name: 'login',    component: () => import('@/views/auth/LoginView.vue'),    meta: { public: true } },
    { path: '/registro', name: 'registro', component: () => import('@/views/auth/RegisterView.vue'), meta: { public: true } },

    // ── App shell con sidebar ─────────────────────────────────────
    {
      path: '/',
      component: () => import('@/components/layout/AppShell.vue'),
      children: [
        // Dashboard (redirige según rol)
        { path: '', redirect: '/dashboard' },
        {
          path: 'dashboard',
          name: 'dashboard',
          component: () => import('@/views/DashboardView.vue'),
        },

        // ── Admin — Usuarios ──────────────────────────────────────
        {
          path: 'admin/usuarios',
          name: 'admin.usuarios',
          component: () => import('@/views/admin/UsuariosView.vue'),
          meta: { roles: ['admin'] },
        },

        // ── Convocatorias ─────────────────────────────────────────
        {
          path: 'convocatorias',
          name: 'convocatorias',
          component: () => import('@/views/convocatorias/ConvocatoriasView.vue'),
        },
        {
          path: 'convocatorias/nueva',
          name: 'convocatorias.nueva',
          component: () => import('@/views/convocatorias/ConvocatoriaFormView.vue'),
          meta: { roles: ['admin'] },
        },
        {
          path: 'convocatorias/:id',
          name: 'convocatorias.detalle',
          component: () => import('@/views/convocatorias/ConvocatoriaDetalleView.vue'),
        },

        // ── Portal Postulante ─────────────────────────────────────
        {
          path: 'mis-postulaciones',
          name: 'postulante.postulaciones',
          component: () => import('@/views/postulante/MisPostulacionesView.vue'),
          meta: { roles: ['postulante'] },
        },
        {
          path: 'mis-postulaciones/:id',
          name: 'postulante.postulacion',
          component: () => import('@/views/postulante/PostulacionDetalleView.vue'),
          meta: { roles: ['postulante'] },
        },
        {
          path: 'mis-postulaciones/:id/expediente',
          name: 'postulante.expediente',
          component: () => import('@/views/postulante/ExpedienteView.vue'),
          meta: { roles: ['postulante'] },
        },

        // ── Evaluador ───────────────────────────────────────────────
        {
          path: 'evaluaciones',
          name: 'evaluador.bandeja',
          component: () => import('@/views/evaluador/BandejaEvaluacionesView.vue'),
          meta: { roles: ['evaluador', 'admin'] },
        },
        {
          path: 'evaluaciones/:id',
          name: 'evaluador.detalle',
          component: () => import('@/views/evaluador/EvaluacionDetalleView.vue'),
          meta: { roles: ['evaluador', 'admin'] },
        },

        // ── Resultados ────────────────────────────────────────────
        {
          path: 'convocatorias/:id/resultados',
          name: 'resultados',
          component: () => import('@/views/convocatorias/ResultadosView.vue'),
        },

        // ── Auditoría ─────────────────────────────────────────────
        {
          path: 'auditoria',
          name: 'auditoria',
          component: () => import('@/views/admin/AuditoriaView.vue'),
          meta: { roles: ['admin'] },
        },
      ],
    },

    // Not found
    { path: '/:pathMatch(.*)*', redirect: '/dashboard' },
  ],
})

// Guard global
router.beforeEach((to) => {
  const auth = useAuthStore()

  if (!to.meta.public && !auth.isAuthenticated) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }

  if (to.meta.roles && auth.user) {
    const userRole = auth.rol
    const allowed  = to.meta.roles as string[]
    if (!allowed.includes(userRole ?? '')) {
      return { name: 'dashboard' }
    }
  }
})

export default router
