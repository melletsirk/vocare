<script setup lang="ts">
import { computed } from 'vue'

export interface StepperStep {
  key: string
  label: string
  /** Si se define, gana sobre el estado calculado por posición respecto a currentKey. */
  state?: 'done' | 'current' | 'upcoming' | 'error'
}

const props = defineProps<{
  steps: StepperStep[]
  currentKey: string
}>()

const currentIndex = computed(() => props.steps.findIndex((s) => s.key === props.currentKey))

function stateOf(step: StepperStep, index: number): 'done' | 'current' | 'upcoming' | 'error' {
  if (step.state) return step.state
  if (index < currentIndex.value) return 'done'
  if (index === currentIndex.value) return 'current'
  return 'upcoming'
}
</script>

<template>
  <ol class="stepper" role="list">
    <li
      v-for="(step, i) in steps"
      :key="step.key"
      class="stepper-item"
      :class="`is-${stateOf(step, i)}`"
    >
      <span class="stepper-dot" aria-hidden="true"></span>
      <span class="stepper-label">{{ step.label }}</span>
    </li>
  </ol>
</template>
