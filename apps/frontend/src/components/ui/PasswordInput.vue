<script setup lang="ts">
import { ref } from 'vue'
import Icon from '@/components/ui/Icon.vue'

withDefaults(defineProps<{
  modelValue: string
  id?: string
  placeholder?: string
  autocomplete?: string
  required?: boolean
  error?: boolean
}>(), {
  autocomplete: 'current-password',
})

defineEmits<{ 'update:modelValue': [string] }>()

const visible = ref(false)
</script>

<template>
  <div class="password-field">
    <input
      :id="id"
      :type="visible ? 'text' : 'password'"
      class="form-control"
      :class="{ error }"
      :placeholder="placeholder"
      :autocomplete="autocomplete"
      :required="required"
      :value="modelValue"
      @input="$emit('update:modelValue', ($event.target as HTMLInputElement).value)"
    />
    <button
      type="button"
      class="password-toggle"
      :aria-label="visible ? 'Ocultar contraseña' : 'Mostrar contraseña'"
      :aria-pressed="visible"
      @click="visible = !visible"
    >
      <Icon :name="visible ? 'eye-off' : 'eye'" :size="16" />
    </button>
  </div>
</template>
