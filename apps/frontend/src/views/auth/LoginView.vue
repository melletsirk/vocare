<script setup lang="ts">
import { ref, reactive } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import PasswordInput from '@/components/ui/PasswordInput.vue'

const router = useRouter()
const route  = useRoute()
const auth   = useAuthStore()

const form = reactive({ email: '', password: '' })
const error   = ref('')
const loading = ref(false)

async function submit() {
  error.value   = ''
  loading.value = true
  try {
    const user = await auth.login(form.email, form.password)
    const redirect = (route.query.redirect as string) || '/dashboard'
    router.push(redirect)
  } catch (e: any) {
    const msg = e.response?.data?.errors?.email?.[0]
             || e.response?.data?.message
             || 'Error al iniciar sesión'
    error.value = msg
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="login-page">
    <div class="login-panel">
      <div class="login-card">
        <div class="login-logo">
          <div class="login-logo-mark">V</div>
          <span class="login-logo-text">Vocare</span>
        </div>

        <h1 class="login-title">Bienvenido</h1>
        <p class="login-subtitle">Sistema de Convocatorias Docentes</p>

        <div v-if="error" class="alert alert-error mb-4">
          <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
          {{ error }}
        </div>

        <form @submit.prevent="submit" novalidate>
          <div class="form-group mb-4">
            <label class="form-label" for="email">Correo electrónico</label>
            <input
              id="email"
              v-model="form.email"
              type="email"
              class="form-control"
              placeholder="usuario@institucion.edu"
              autocomplete="email"
              required
            />
          </div>

          <div class="form-group mb-6">
            <label class="form-label" for="password">Contraseña</label>
            <PasswordInput
              id="password"
              v-model="form.password"
              placeholder="••••••••"
              autocomplete="current-password"
              required
            />
          </div>

          <button
            type="submit"
            class="btn btn-primary w-full btn-lg"
            :disabled="loading"
          >
            <span v-if="loading" class="spinner" style="border-color:rgba(255,255,255,.3);border-top-color:#fff;" />
            {{ loading ? 'Iniciando sesión...' : 'Iniciar sesión' }}
          </button>
        </form>

        <p class="text-center text-xs mt-4" style="color:rgba(255,255,255,.35)">
          ¿Eres postulante y no tienes cuenta?
          <RouterLink to="/registro" style="color:var(--clr-primary-300)">Regístrate aquí</RouterLink>
        </p>

        <p class="text-center text-xs text-muted mt-4" style="color:rgba(255,255,255,.3)">
          Universidad Católica de Santa María — GTH
        </p>
      </div>
    </div>
  </div>
</template>
