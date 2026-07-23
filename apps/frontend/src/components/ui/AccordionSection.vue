<script setup lang="ts">
import { computed } from 'vue'
import Icon from '@/components/ui/Icon.vue'

const props = withDefaults(defineProps<{
  title: string
  meta?: string
  /** Controla el ícono/color de la derecha — el resumen del estado de la sección sin abrirla. */
  status?: 'done' | 'warn' | 'info' | 'neutral'
  open: boolean
}>(), {
  status: 'neutral',
})

const emit = defineEmits<{ 'update:open': [boolean] }>()

function toggle() {
  emit('update:open', !props.open)
}

const statusIcon = computed(() => ({
  done: 'check-circle',
  warn: 'alert-triangle',
  info: 'info',
  neutral: '',
}[props.status]))
</script>

<template>
  <div class="accordion" :class="{ 'is-open': open }">
    <button
      type="button"
      class="accordion-trigger"
      :aria-expanded="open"
      @click="toggle"
    >
      <Icon :name="open ? 'chevron-up' : 'chevron-down'" :size="16" class="accordion-chevron" />
      <span class="accordion-title">{{ title }}</span>
      <span v-if="meta" class="accordion-meta">{{ meta }}</span>
      <Icon
        v-if="statusIcon"
        :name="statusIcon"
        :size="16"
        class="accordion-status"
        :class="`status-${status}`"
      />
    </button>
    <div v-show="open" class="accordion-body">
      <slot />
    </div>
  </div>
</template>
