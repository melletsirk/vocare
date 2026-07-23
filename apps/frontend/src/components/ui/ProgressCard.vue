<script setup lang="ts">
withDefaults(defineProps<{
  title: string
  meta?: string
  /** 0–100. Si se omite, la card no muestra barra de progreso. */
  progressPercent?: number
  progressLabel?: string
  tone?: 'default' | 'urgent' | 'success'
}>(), {
  tone: 'default',
})
</script>

<template>
  <div class="progress-card" :class="`tone-${tone}`">
    <div class="progress-card-body">
      <p class="progress-card-title">{{ title }}</p>
      <p v-if="meta" class="progress-card-meta">{{ meta }}</p>
      <div v-if="progressPercent !== undefined" class="progress-card-track">
        <div class="progress-bar">
          <div class="progress-fill" :style="{ width: progressPercent + '%' }"></div>
        </div>
        <span v-if="progressLabel" class="progress-card-track-label">{{ progressLabel }}</span>
      </div>
    </div>
    <div class="progress-card-action">
      <slot name="action" />
    </div>
  </div>
</template>
