<script setup lang="ts">
import { ref, onMounted, reactive } from 'vue'
import api from '@/services/api'
import Icon from '@/components/ui/Icon.vue'
import { initials } from '@/utils/initials'

const usuarios   = ref<any[]>([])
const roles      = ref<any[]>([])
const loading    = ref(true)
const showModal  = ref(false)
const saving     = ref(false)
const error      = ref('')
const filtroRol  = ref('')
const filtroQ    = ref('')

const form = reactive({
  name: '', email: '', dni: '', password: '', rol: '', is_active: true,
})

const rolBadge: Record<string, string> = {
  admin:      'badge-red',
  evaluador:  'badge-blue',
  postulante: 'badge-green',
}
const rolLabel: Record<string, string> = {
  admin:      'Administrador',
  evaluador:  'Evaluador',
  postulante: 'Postulante',
}

onMounted(async () => {
  const [rRes] = await Promise.all([api.get('/roles'), cargar()])
  roles.value = rRes.data
})

async function cargar() {
  loading.value = true
  try {
    const params: any = {}
    if (filtroRol.value) params.rol = filtroRol.value
    if (filtroQ.value)   params.q   = filtroQ.value
    const { data } = await api.get('/users', { params })
    usuarios.value = data.data ?? data
  } finally {
    loading.value = false
  }
}

async function guardar() {
  error.value  = ''
  saving.value = true
  try {
    await api.post('/users', form)
    showModal.value = false
    Object.assign(form, { name:'', email:'', dni:'', password:'', rol:'', is_active:true })
    await cargar()
  } catch (e: any) {
    const errs = e.response?.data?.errors
    error.value = errs ? Object.values(errs).flat().join(' ') : e.response?.data?.message || 'Error al guardar'
  } finally {
    saving.value = false
  }
}

async function desactivar(user: any) {
  if (!confirm(`¿Desactivar a ${user.name}?`)) return
  await api.patch(`/users/${user.id}/desactivar`)
  await cargar()
}
</script>

<template>
  <div>
    <div class="page-header">
      <div>
        <h1>Usuarios</h1>
        <p>Gestión de cuentas y roles del sistema</p>
      </div>
      <button class="btn btn-primary" @click="showModal = true">+ Nuevo usuario</button>
    </div>

    <!-- Filtros -->
    <div class="card mb-4" style="padding:1rem">
      <div class="flex gap-3 items-center" style="flex-wrap:wrap">
        <input v-model="filtroQ" class="form-control" style="max-width:220px" placeholder="Buscar nombre, email o DNI..." @input="cargar" />
        <select v-model="filtroRol" class="form-control" style="max-width:180px" @change="cargar">
          <option value="">Todos los roles</option>
          <option v-for="r in roles" :key="r.id" :value="r.name">{{ rolLabel[r.name] ?? r.name }}</option>
        </select>
      </div>
    </div>

    <div v-if="loading" class="loading-center"><span class="spinner"></span> Cargando...</div>

    <div v-else class="card" style="padding:0">
      <div class="table-wrap">
        <table>
          <thead>
            <tr>
              <th>Nombre</th>
              <th>Email</th>
              <th>DNI</th>
              <th>Rol</th>
              <th>Estado</th>
              <th>Creado</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="u in usuarios" :key="u.id">
              <td>
                <div class="flex items-center gap-2">
                  <div class="avatar-sm">{{ initials(u.name) }}</div>
                  <span class="font-medium">{{ u.name }}</span>
                </div>
              </td>
              <td class="text-sm text-muted">{{ u.email }}</td>
              <td class="text-sm">{{ u.dni || '—' }}</td>
              <td>
                <span class="badge" :class="rolBadge[u.roles?.[0]] ?? 'badge-gray'">
                  {{ rolLabel[u.roles?.[0]] ?? u.roles?.[0] ?? '—' }}
                </span>
              </td>
              <td>
                <span class="badge" :class="u.is_active ? 'badge-green' : 'badge-red'">
                  {{ u.is_active ? 'Activo' : 'Inactivo' }}
                </span>
              </td>
              <td class="text-sm text-muted">{{ new Date(u.created_at).toLocaleDateString('es-PE') }}</td>
              <td>
                <button v-if="u.is_active" class="btn btn-ghost btn-sm" @click="desactivar(u)">
                  Desactivar
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Modal nuevo usuario -->
    <div v-if="showModal" class="modal-overlay" @click.self="showModal = false">
      <div class="modal">
        <div class="modal-header">
          <h2>Nuevo usuario</h2>
          <button class="btn btn-ghost btn-icon" @click="showModal = false"><Icon name="x" :size="18" /></button>
        </div>
        <div class="modal-body">
          <div v-if="error" class="alert alert-error mb-4">{{ error }}</div>
          <div class="grid-2">
            <div class="form-group">
              <label class="form-label">Nombre completo <span class="required">*</span></label>
              <input v-model="form.name" class="form-control" placeholder="Juan Pérez" />
            </div>
            <div class="form-group">
              <label class="form-label">DNI</label>
              <input v-model="form.dni" class="form-control" placeholder="12345678" />
            </div>
          </div>
          <div class="form-group mt-4">
            <label class="form-label">Email <span class="required">*</span></label>
            <input v-model="form.email" type="email" class="form-control" placeholder="usuario@institucion.edu" />
          </div>
          <div class="grid-2 mt-4">
            <div class="form-group">
              <label class="form-label">Contraseña <span class="required">*</span></label>
              <input v-model="form.password" type="password" class="form-control" placeholder="Mín. 8 caracteres" />
            </div>
            <div class="form-group">
              <label class="form-label">Rol <span class="required">*</span></label>
              <select v-model="form.rol" class="form-control">
                <option value="">Seleccionar...</option>
                <option v-for="r in roles" :key="r.id" :value="r.name">{{ rolLabel[r.name] ?? r.name }}</option>
              </select>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" @click="showModal = false">Cancelar</button>
          <button class="btn btn-primary" :disabled="saving" @click="guardar">
            <span v-if="saving" class="spinner"></span>
            {{ saving ? 'Guardando...' : 'Crear usuario' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
