<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import api from '@/services/api'
import PasswordInput from '@/components/ui/PasswordInput.vue'

const router = useRouter()
const auth   = useAuthStore()

const form = reactive({
  name: '', dni: '', email: '', password: '', password_confirmation: '',
})
const errors  = ref<Record<string, string>>({})
const loading = ref(false)

async function registrar() {
  errors.value  = {}
  loading.value = true
  try {
    // El backend asigna automáticamente el rol 'postulante'
    const { data } = await api.post('/auth/register', form)
    // Guardar sesión directamente
    auth.token = data.token
    auth.user  = data.user
    localStorage.setItem('vocare_token', data.token)
    localStorage.setItem('vocare_user', JSON.stringify(data.user))
    router.push('/dashboard')
  } catch (e: any) {
    const errs = e.response?.data?.errors ?? {}
    for (const [field, msgs] of Object.entries(errs)) {
      errors.value[field] = (msgs as string[])[0]
    }
    if (!Object.keys(errors.value).length) {
      errors.value.general = e.response?.data?.message || 'Error al registrarse'
    }
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="login-page">
    <div class="login-panel" style="max-width:480px">
      <div class="login-card">
        <div class="login-logo">
          <div class="login-logo-mark">V</div>
          <span class="login-logo-text">Vocare</span>
        </div>

        <h1 class="login-title">Crear cuenta</h1>
        <p class="login-subtitle">Regístrate como postulante para participar en convocatorias docentes</p>

        <div v-if="errors.general" class="alert alert-error mb-4">
          {{ errors.general }}
        </div>

        <form @submit.prevent="registrar" novalidate>
          <div class="form-group mb-4">
            <label class="form-label">Nombre completo <span class="required">*</span></label>
            <input
              v-model="form.name"
              class="form-control"
              :class="{ error: errors.name }"
              placeholder="Juan Pérez Quispe"
              autocomplete="name"
            />
            <span v-if="errors.name" class="form-error">{{ errors.name }}</span>
          </div>

          <div class="form-group mb-4">
            <label class="form-label">DNI <span class="required">*</span></label>
            <input
              v-model="form.dni"
              class="form-control"
              :class="{ error: errors.dni }"
              placeholder="12345678"
              maxlength="8"
            />
            <span v-if="errors.dni" class="form-error">{{ errors.dni }}</span>
          </div>

          <div class="form-group mb-4">
            <label class="form-label">Correo electrónico <span class="required">*</span></label>
            <input
              v-model="form.email"
              type="email"
              class="form-control"
              :class="{ error: errors.email }"
              placeholder="correo@ejemplo.com"
              autocomplete="email"
            />
            <span v-if="errors.email" class="form-error">{{ errors.email }}</span>
          </div>

          <div class="grid-2 mb-6">
            <div class="form-group">
              <label class="form-label">Contraseña <span class="required">*</span></label>
              <PasswordInput
                v-model="form.password"
                :error="!!errors.password"
                placeholder="Mín. 8 caracteres"
                autocomplete="new-password"
              />
              <span v-if="errors.password" class="form-error">{{ errors.password }}</span>
            </div>
            <div class="form-group">
              <label class="form-label">Confirmar contraseña <span class="required">*</span></label>
              <PasswordInput
                v-model="form.password_confirmation"
                placeholder="Repetir contraseña"
                autocomplete="new-password"
              />
            </div>
          </div>

          <!-- Nota de seguridad: el rol se asigna automáticamente -->
          <div class="alert alert-info mb-4" style="background:rgba(14,165,233,0.1);border-color:rgba(14,165,233,0.2);color:rgba(255,255,255,0.7)">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="flex-shrink:0"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            Tu cuenta se creará como <strong>postulante</strong>. Si eres evaluador, solicita acceso al administrador del sistema.
          </div>

          <button type="submit" class="btn btn-primary w-full btn-lg" :disabled="loading">
            <span v-if="loading" class="spinner" style="border-color:rgba(255,255,255,.3);border-top-color:#fff;"/>
            {{ loading ? 'Registrando...' : 'Crear cuenta' }}
          </button>
        </form>

        <p class="text-center mt-4" style="font-size:0.875rem;color:rgba(255,255,255,.4)">
          ¿Ya tienes cuenta?
          <RouterLink to="/login" style="color:var(--clr-primary-300)">Inicia sesión</RouterLink>
        </p>
      </div>
    </div>
  </div>
</template>
