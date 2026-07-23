<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useAuthStore } from '@/stores/auth'
import api from '@/services/api'
import Icon from '@/components/ui/Icon.vue'

const auth = useAuthStore()

interface Stat { label: string; value: number | string; color: string; icon: string; to: string }

const stats   = ref<Stat[]>([])
const loading = ref(true)

onMounted(async () => {
  try {
    const [convsRes] = await Promise.all([
      api.get('/convocatorias'),
    ])
    const convs = convsRes.data.data ?? []

    if (auth.isAdmin || auth.isEvaluador) {
      // Conteos accionables: cada tarjeta lleva al listado ya filtrado, no
      // es solo un número informativo (design.md — regla de admin).
      stats.value = [
        { label: 'Convocatorias Activas', value: convs.filter((c: any) => c.estado === 'publicada' || c.estado === 'en_proceso').length, color: '#2563eb', icon: 'clipboard', to: '/convocatorias?estado=en_proceso' },
        { label: 'Total Convocatorias',   value: convsRes.data.total ?? convs.length, color: '#7c3aed', icon: 'grid', to: '/convocatorias' },
        { label: 'En Proceso',            value: convs.filter((c: any) => c.estado === 'en_proceso').length, color: '#d97706', icon: 'clock', to: '/convocatorias?estado=en_proceso' },
        { label: 'Cerradas',              value: convs.filter((c: any) => c.estado === 'cerrada').length, color: '#16a34a', icon: 'check-circle', to: '/convocatorias?estado=cerrada' },
      ]
    } else if (auth.isPostulante) {
      const postsRes = await api.get('/postulaciones')
      const posts    = postsRes.data.data ?? []
      stats.value = [
        { label: 'Mis Postulaciones',      value: posts.length, color: '#2563eb', icon: 'file-text', to: '/mis-postulaciones' },
        { label: 'En Proceso',             value: posts.filter((p: any) => p.estado === 'en_proceso').length, color: '#d97706', icon: 'clock', to: '/mis-postulaciones' },
        { label: 'Aprobadas',              value: posts.filter((p: any) => p.estado === 'aprobada_etapa').length, color: '#16a34a', icon: 'check-circle', to: '/mis-postulaciones' },
        { label: 'Convocatorias Abiertas', value: convs.filter((c: any) => c.estado === 'publicada').length, color: '#7c3aed', icon: 'send', to: '/convocatorias?estado=publicada' },
      ]
    }
  } catch (e) {
    console.error(e)
  } finally {
    loading.value = false
  }
})
</script>

<template>
  <div>
    <div class="page-header">
      <div>
        <h1>Dashboard</h1>
        <p>Bienvenido, <strong>{{ auth.user?.name }}</strong></p>
      </div>
    </div>

    <div v-if="loading" class="loading-center">
      <span class="spinner"></span> Cargando...
    </div>

    <div v-else>
      <div class="stats-grid">
        <RouterLink
          v-for="stat in stats"
          :key="stat.label"
          :to="stat.to"
          class="stat-card"
          style="cursor:pointer;text-decoration:none;color:inherit"
        >
          <div class="stat-icon" :style="{ background: stat.color + '18', color: stat.color }">
            <Icon :name="stat.icon" :size="22" />
          </div>
          <div>
            <div class="stat-value">{{ stat.value }}</div>
            <div class="stat-label">{{ stat.label }}</div>
          </div>
        </RouterLink>
      </div>

      <!-- Acciones rápidas — secundarias, ver design.md: jerarquía primero -->
      <div class="card">
        <div class="card-header">
          <h3 class="card-title">Acciones rápidas</h3>
        </div>
        <div class="flex gap-3" style="flex-wrap:wrap">
          <RouterLink to="/convocatorias" class="btn btn-primary">
            Ver convocatorias
          </RouterLink>
          <RouterLink v-if="auth.isPostulante" to="/mis-postulaciones" class="btn btn-secondary">
            Mis postulaciones
          </RouterLink>
          <RouterLink v-if="auth.isEvaluador" to="/evaluaciones" class="btn btn-secondary">
            Bandeja de evaluaciones
          </RouterLink>
          <RouterLink v-if="auth.isAdmin" to="/admin/usuarios" class="btn btn-secondary">
            Gestionar usuarios
          </RouterLink>
        </div>
      </div>
    </div>
  </div>
</template>
