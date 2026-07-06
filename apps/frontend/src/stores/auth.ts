import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import api from '@/services/api'

export interface User {
  id: number
  name: string
  email: string
  dni: string | null
  is_active: boolean
  roles: string[]
  created_at: string
}

export const useAuthStore = defineStore('auth', () => {
  const token = ref<string | null>(localStorage.getItem('vocare_token'))
  const user  = ref<User | null>(JSON.parse(localStorage.getItem('vocare_user') || 'null'))

  const isAuthenticated = computed(() => !!token.value)
  const rol = computed(() => user.value?.roles?.[0] ?? null)
  // Los 3 roles reales del sistema: postulante | evaluador | admin
  // admin tiene todos los permisos de evaluador también
  const isAdmin      = computed(() => rol.value === 'admin')
  const isEvaluador  = computed(() => rol.value === 'evaluador' || rol.value === 'admin')
  const isPostulante = computed(() => rol.value === 'postulante')

  async function login(email: string, password: string) {
    const { data } = await api.post('/auth/login', { email, password })
    token.value = data.token
    user.value  = data.user
    localStorage.setItem('vocare_token', data.token)
    localStorage.setItem('vocare_user', JSON.stringify(data.user))
    return data.user
  }

  async function logout() {
    try { await api.post('/auth/logout') } catch {}
    token.value = null
    user.value  = null
    localStorage.removeItem('vocare_token')
    localStorage.removeItem('vocare_user')
  }

  async function fetchMe() {
    const { data } = await api.get('/me')
    user.value = data
    localStorage.setItem('vocare_user', JSON.stringify(data))
    return data
  }

  return { token, user, isAuthenticated, rol, isAdmin, isEvaluador, isPostulante, login, logout, fetchMe }
})
