<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import api from '@/services/api'
import { useAuthStore } from '@/stores/auth'
import Icon from '@/components/ui/Icon.vue'

const route  = useRoute()
const router = useRouter()
const auth   = useAuthStore()
const id     = route.params.id

const conv    = ref<any>(null)
const plazas  = ref<any[]>([])
const loading = ref(true)
const tab     = ref<'plazas'|'tabla'|'postulantes'|'evaluaciones'|'asignaciones'>('plazas')
const showPlazaModal = ref(false)
const savingPlaza    = ref(false)
const publicando     = ref(false)
const plazaForm = ref({ facultad:'', departamento:'', asignatura:'', modalidad:'', horas_semana:'' })

// Postulantes de esta convocatoria (todas, no solo enviadas)
const todasPostulaciones = ref<any[]>([])

// Evaluaciones de esta convocatoria
const evaluacionesConv = ref<any[]>([])

// Asignación de evaluadores
const postulaciones = ref<any[]>([])
const asignaciones  = ref<any[]>([])
const evaluadores   = ref<any[]>([])
const evaluadorElegido = reactive<Record<number, string>>({}) // postulacion_id -> evaluador_id
const asignando = reactive<Record<number, boolean>>({})
const quitando   = reactive<Record<number, boolean>>({})

const postulacionEstadoBadge: Record<string, string> = {
  en_proceso: 'badge-yellow', observada: 'badge-indigo',
  rechazada: 'badge-red', aprobada_etapa: 'badge-green', ganadora: 'badge-blue',
}
const evaluacionEstadoBadge: Record<string, string> = {
  en_proceso: 'badge-yellow', completada: 'badge-blue', cerrada: 'badge-green',
}

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

    if (canManage) {
      await cargarAsignaciones()
      const [postRes, evalRes] = await Promise.all([
        api.get('/postulaciones', { params: { convocatoria_id: id } }),
        api.get('/evaluaciones', { params: { convocatoria_id: id } }),
      ])
      todasPostulaciones.value = postRes.data.data ?? postRes.data
      evaluacionesConv.value   = evalRes.data.data ?? evalRes.data
    }
  } finally {
    loading.value = false
  }
}

async function cargarAsignaciones() {
  const [postRes, asigRes, evalRes] = await Promise.all([
    api.get('/postulaciones', { params: { convocatoria_id: id } }),
    api.get('/asignaciones', { params: { convocatoria_id: id } }),
    api.get('/users', { params: { rol: 'evaluador' } }),
  ])
  postulaciones.value = (postRes.data.data ?? postRes.data).filter((p: any) => p.fecha_envio)
  asignaciones.value  = asigRes.data.data ?? asigRes.data
  evaluadores.value   = evalRes.data.data ?? evalRes.data
}

function asignacionesDe(postulacionId: number) {
  return asignaciones.value.filter((a) => a.postulacion_id === postulacionId)
}

async function asignarEvaluador(postulacionId: number) {
  const evaluadorId = evaluadorElegido[postulacionId]
  if (!evaluadorId) return

  asignando[postulacionId] = true
  try {
    await api.post(`/convocatorias/${id}/asignaciones`, {
      postulacion_id: postulacionId,
      evaluador_id: evaluadorId,
    })
    evaluadorElegido[postulacionId] = ''
    await cargarAsignaciones()
  } catch (e: any) {
    alert(e.response?.data?.message || 'No se pudo asignar el evaluador')
  } finally {
    asignando[postulacionId] = false
  }
}

async function quitarAsignacion(asignacionId: number) {
  if (!confirm('¿Quitar esta asignación?')) return
  quitando[asignacionId] = true
  try {
    await api.delete(`/asignaciones/${asignacionId}`)
    await cargarAsignaciones()
  } catch (e: any) {
    alert(e.response?.data?.message || 'No se pudo quitar la asignación')
  } finally {
    quitando[asignacionId] = false
  }
}

async function publicar() {
  if (!confirm('¿Publicar esta convocatoria? Los postulantes podrán verla y postular.')) return
  publicando.value = true
  try {
    await api.patch(`/convocatorias/${id}`, { estado: 'publicada' })
    await cargar()
  } catch (e: any) {
    alert(e.response?.data?.message || 'No se pudo publicar la convocatoria')
  } finally {
    publicando.value = false
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
        <button
          v-if="canManage && conv.estado === 'borrador'"
          class="btn btn-primary btn-sm"
          :disabled="publicando"
          @click="publicar"
        >
          <span v-if="publicando" class="spinner"></span>
          {{ publicando ? 'Publicando...' : 'Publicar convocatoria' }}
        </button>
        <RouterLink :to="`/convocatorias/${id}/resultados`" class="btn btn-secondary btn-sm">
          Ver resultados
        </RouterLink>
      </div>
    </div>

    <!-- Info rápida -->
    <div class="stats-grid mb-4">
      <div class="stat-card">
        <div class="stat-icon" style="background:#EEF1EE;color:#3A423E"><Icon name="clipboard" :size="22" /></div>
        <div><div class="stat-value">{{ plazas.length }}</div><div class="stat-label">Plazas</div></div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#EDF5EE;color:#3B7548"><Icon name="check-circle" :size="22" /></div>
        <div>
          <div class="stat-value">{{ plazas.filter(p => p.estado === 'cubierta').length }}</div>
          <div class="stat-label">Cubiertas</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#EEF1EE;color:#525C57"><Icon name="calendar" :size="22" /></div>
        <div>
          <div class="stat-value">{{ new Date(conv.fecha_inicio).toLocaleDateString('es-PE') }}</div>
          <div class="stat-label">Inicio</div>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#F7F0E4;color:#AD8130"><Icon name="flag" :size="22" /></div>
        <div>
          <div class="stat-value">{{ new Date(conv.fecha_fin).toLocaleDateString('es-PE') }}</div>
          <div class="stat-label">Fin</div>
        </div>
      </div>
    </div>

    <!-- Tabs -->
    <div class="tabs">
      <button
        v-for="t in (canManage
          ? [['plazas','Plazas'],['tabla','Tabla Evaluación'],['postulantes','Postulantes'],['evaluaciones','Evaluaciones'],['asignaciones','Asignaciones']]
          : [['plazas','Plazas'],['tabla','Tabla Evaluación']])"
        :key="t[0]"
        class="tab"
        :class="{ active: tab === t[0] }"
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

    <!-- Postulantes -->
    <div v-else-if="tab === 'postulantes'">
      <div v-if="todasPostulaciones.length === 0" class="card">
        <div class="empty-state">
          <h3>Sin postulantes todavía</h3>
        </div>
      </div>
      <div v-else class="card" style="padding:0">
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Postulante</th>
                <th>Plaza</th>
                <th>Enviada</th>
                <th>Estado</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="p in todasPostulaciones" :key="p.id">
                <td class="font-medium">{{ p.postulante?.name ?? p.user_id }}</td>
                <td class="text-sm">{{ p.plaza?.asignatura ?? '—' }}</td>
                <td class="text-sm">
                  <span v-if="p.fecha_envio">{{ new Date(p.fecha_envio).toLocaleDateString('es-PE') }}</span>
                  <span v-else class="badge badge-gray" style="font-size:0.7rem">Borrador</span>
                </td>
                <td><span class="badge" :class="postulacionEstadoBadge[p.estado] ?? 'badge-gray'">{{ p.estado }}</span></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Evaluaciones -->
    <div v-else-if="tab === 'evaluaciones'">
      <div v-if="evaluacionesConv.length === 0" class="card">
        <div class="empty-state">
          <h3>Sin evaluaciones todavía</h3>
        </div>
      </div>
      <div v-else class="card" style="padding:0">
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Postulante</th>
                <th>Evaluador</th>
                <th>Puntaje</th>
                <th>Estado</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="e in evaluacionesConv" :key="e.id">
                <td class="font-medium">{{ e.postulacion?.postulante?.name ?? '—' }}</td>
                <td class="text-sm">{{ e.evaluador?.name ?? '—' }}</td>
                <td>
                  <span v-if="e.puntaje_total" class="font-semibold" style="color:var(--clr-primary-700)">{{ e.puntaje_total }}</span>
                  <span v-else class="text-muted">—</span>
                </td>
                <td><span class="badge" :class="evaluacionEstadoBadge[e.estado] ?? 'badge-gray'">{{ e.estado }}</span></td>
                <td><RouterLink :to="`/evaluaciones/${e.id}`" class="btn btn-ghost btn-sm">Ver →</RouterLink></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Asignación de evaluadores -->
    <div v-else-if="tab === 'asignaciones'">
      <div v-if="postulaciones.length === 0" class="card">
        <div class="empty-state">
          <h3>Sin postulaciones enviadas</h3>
          <p>Solo se pueden asignar evaluadores a postulaciones ya enviadas formalmente.</p>
        </div>
      </div>

      <div v-else class="card" style="padding:0">
        <div class="table-wrap">
          <table>
            <thead>
              <tr>
                <th>Postulante</th>
                <th>Plaza</th>
                <th>Evaluadores asignados</th>
                <th>Asignar</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="p in postulaciones" :key="p.id">
                <td class="font-medium">{{ p.postulante?.name ?? p.user_id }}</td>
                <td class="text-sm">{{ p.plaza?.asignatura ?? '—' }}</td>
                <td>
                  <div v-if="asignacionesDe(p.id).length === 0" class="text-muted text-sm">Sin asignar</div>
                  <div v-for="a in asignacionesDe(p.id)" :key="a.id" class="flex items-center gap-2 mb-1">
                    <span class="badge badge-blue">{{ a.evaluador?.name ?? a.evaluador_id }}</span>
                    <button
                      class="btn btn-ghost btn-icon btn-sm"
                      :disabled="quitando[a.id]"
                      title="Quitar asignación"
                      @click="quitarAsignacion(a.id)"
                    ><Icon name="x" :size="14" /></button>
                  </div>
                </td>
                <td>
                  <div class="flex gap-2">
                    <select v-model="evaluadorElegido[p.id]" class="form-control" style="min-width:180px">
                      <option value="">Elegir evaluador...</option>
                      <option v-for="e in evaluadores" :key="e.id" :value="e.id">{{ e.name }}</option>
                    </select>
                    <button
                      class="btn btn-primary btn-sm"
                      :disabled="!evaluadorElegido[p.id] || asignando[p.id]"
                      @click="asignarEvaluador(p.id)"
                    >
                      <span v-if="asignando[p.id]" class="spinner"></span>
                      Asignar
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Modal plaza -->
    <div v-if="showPlazaModal" class="modal-overlay" @click.self="showPlazaModal = false">
      <div class="modal">
        <div class="modal-header">
          <h2>Agregar plaza</h2>
          <button class="btn btn-ghost btn-icon" @click="showPlazaModal = false"><Icon name="x" :size="18" /></button>
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
