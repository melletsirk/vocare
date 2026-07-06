<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '@/services/api'
import { useAuthStore } from '@/stores/auth'

const route  = useRoute()
const router = useRouter()
const auth   = useAuthStore()
const id     = route.params.id

const conv    = ref<any>(null)
const plazas  = ref<any[]>([])
const loading = ref(true)
const tab     = ref<'plazas'|'tabla'|'resultados'>('plazas')
const showPlazaModal = ref(false)
const savingPlaza    = ref(false)
const plazaForm = ref({ facultad:'', departamento:'', asignatura:'', modalidad:'', horas_semana:'' })

const estadoBadge: Record<string, string> = {
  borrador:'badge-gray', publicada:'badge-blue',
  en_proceso:'badge-yellow', cerrada:'badge-green',
}
const estadoLabel: Record<string, string> = {
  borrador:'Borrador', publicada:'Publicada', en_proceso:'En Proceso', cerrada:'Cerrada',
}
const plazaEstadoBadge: Record<string, string> = {
  activa:'badge-blue', cubierta:'badge-green', desierta:'badge-red',
}

onMounted(async () => {
  await cargar()
})

async function cargar() {
  loading.value = true
  try {
    const [cRes, pRes] = await Promise.all([
      api.get(`/convocatorias/${id}`),
      api.get(`/convocatorias/${id}/plazas`),
    ])
    conv.value   = cRes.data
    plazas.value = pRes.data
  } finally {
    loading.value = false
  }
}

async function guardarPlaza() {
  savingPlaza.value = true
  try {
    await api.post(`/convocatorias/${id}/plazas`, plazaForm.value)
    showPlazaModal.value = false
    plazaForm.value = { facultad:'', departamento:'', asignatura:'', modalidad:'', horas_semana:'' }
    await cargar()
  } finally {
    savingPlaza.value = false
  }
}

const canManage = auth.isAdmin || auth.rol === 'admin_convocatoria'
</script>

<template>
  <div v-if="loading" class="loading-center"><span class="spinner"></span> Cargando...</div>

  <div v-else-if="conv">
    <!-- Header -->
    <div class="page-header">
      <div>
        <div class="flex items-center gap-3 mb-1">
          <button class="btn btn-ghost btn-sm" @click="router.back()">← Volver</button>
          <span class="badge" :class="estadoBadge[conv.estado]">{{ estadoLabel[conv.estado] }}</span>
        </div>
        <h1>{{ conv.nombre }}</h1>
        <p>{{ conv.codigo }} · {{ conv.tipo_proceso }}</p>
      </div>
      <div class="flex gap-2">
        <RouterLink :to="`/convocatorias/${id}/resultados`" class="btn btn-secondary btn-sm">
          Ver resultados
        </RouterLink>
      </div>
    </div>

    <!-- Info rápida -->
    <div class="stats-grid mb-4">
      <div class="stat-card">
        <div class="stat-icon" style="background:#eff6ff">📋</div>
        <div><div class="stat-value">{{ plazas.length }}</div><div class="stat-label">Plazas</div></div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#f0fdf4">✅</div>
        <div>
          <div class="stat-value">{{ plazas.filter(p => p.estado === 'cubierta').length }}</div>
          <div class="stat-label">Cubiertas</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#fffbeb">📅</div>
        <div>
          <div class="stat-value">{{ new Date(conv.fecha_inicio).toLocaleDateString('es-PE') }}</div>
          <div class="stat-label">Inicio</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#fef2f2">🏁</div>
        <div>
          <div class="stat-value">{{ new Date(conv.fecha_fin).toLocaleDateString('es-PE') }}</div>
          <div class="stat-label">Fin</div>
        </div>
      </div>
    </div>

    <!-- Tabs -->
    <div class="flex gap-1 mb-4" style="border-bottom:1px solid var(--surface-border)">
      <button
        v-for="t in [['plazas','Plazas'],['tabla','Tabla Evaluación']]"
        :key="t[0]"
        class="btn btn-ghost"
        :style="tab === t[0] ? 'border-bottom:2px solid var(--clr-primary-600);border-radius:0;color:var(--clr-primary-700);font-weight:600' : ''"
        @click="tab = t[0] as any"
      >
        {{ t[1] }}
      </button>
    </div>

    <!-- Plazas -->
    <div v-if="tab === 'plazas'">
      <div class="flex justify-between items-center mb-3">
        <h3 class="font-semibold">Plazas disponibles</h3>
        <button v-if="canManage" class="btn btn-primary btn-sm" @click="showPlazaModal = true">+ Agregar plaza</button>
      </div>

      <div v-if="plazas.length === 0" class="card">
        <div class="empty-state">
          <h3>Sin plazas registradas</h3>
          <p>Agrega las plazas docentes de esta convocatoria.</p>
        </div>
      </div>

      <div v-else class="card" style="padding:0">
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Facultad</th>
                <th>Departamento</th>
                <th>Asignatura</th>
                <th>Modalidad</th>
                <th>Horas/Sem</th>
                <th>Estado</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="p in plazas" :key="p.id">
                <td>{{ p.facultad }}</td>
                <td>{{ p.departamento }}</td>
                <td class="font-medium">{{ p.asignatura }}</td>
                <td>{{ p.modalidad || '—' }}</td>
                <td>{{ p.horas_semana || '—' }}</td>
                <td>
                  <span class="badge" :class="plazaEstadoBadge[p.estado] ?? 'badge-gray'">
                    {{ p.estado }}
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Tabla evaluación -->
    <div v-else-if="tab === 'tabla'">
      <div v-if="conv.tabla_snapshot">
        <div class="alert alert-info mb-4">
          Esta convocatoria tiene un snapshot inmutable de la tabla de evaluación.
        </div>
        <div v-for="rubro in conv.tabla_snapshot?.rubros" :key="rubro.nombre" class="card mb-3">
          <div class="card-header">
            <h3 class="card-title">{{ rubro.nombre }}</h3>
            <span class="badge badge-blue">Tope: {{ rubro.puntaje_max_subrubro }} pts</span>
          </div>
          <div class="table-wrap">
            <table>
              <thead>
                <tr><th>Variable</th><th>Tipo</th><th>Puntaje máx.</th></tr>
              </thead>
              <tbody>
                <tr v-for="v in rubro.variables" :key="v.id">
                  <td>{{ v.nombre }}</td>
                  <td><span class="badge badge-gray">{{ v.tipo_calculo }}</span></td>
                  <td>{{ v.puntaje_max }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
      <div v-else class="card">
        <div class="empty-state">
          <h3>Sin tabla de evaluación</h3>
          <p>Publica la convocatoria para generar el snapshot inmutable.</p>
        </div>
      </div>
    </div>

    <!-- Modal plaza -->
    <div v-if="showPlazaModal" class="modal-overlay" @click.self="showPlazaModal = false">
      <div class="modal">
        <div class="modal-header">
          <h2>Agregar plaza</h2>
          <button class="btn btn-ghost btn-icon" @click="showPlazaModal = false">✕</button>
        </div>
        <div class="modal-body">
          <div class="form-group mb-4">
            <label class="form-label">Facultad <span class="required">*</span></label>
            <input v-model="plazaForm.facultad" class="form-control" placeholder="Facultad de Ingeniería" />
          </div>
          <div class="grid-2">
            <div class="form-group">
              <label class="form-label">Departamento <span class="required">*</span></label>
              <input v-model="plazaForm.departamento" class="form-control" />
            </div>
            <div class="form-group">
              <label class="form-label">Asignatura <span class="required">*</span></label>
              <input v-model="plazaForm.asignatura" class="form-control" />
            </div>
          </div>
          <div class="grid-2 mt-4">
            <div class="form-group">
              <label class="form-label">Modalidad</label>
              <select v-model="plazaForm.modalidad" class="form-control">
                <option value="">—</option>
                <option>Tiempo completo</option>
                <option>Tiempo parcial</option>
                <option>Por horas</option>
              </select>
            </div>
            <div class="form-group">
              <label class="form-label">Horas / semana</label>
              <input v-model="plazaForm.horas_semana" class="form-control" placeholder="20" />
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" @click="showPlazaModal = false">Cancelar</button>
          <button class="btn btn-primary" :disabled="savingPlaza" @click="guardarPlaza">
            <span v-if="savingPlaza" class="spinner"></span>
            Guardar plaza
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
