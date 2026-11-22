<script setup lang="ts">
// Natywny <select> w stylu Nuxt UI — pełna obsługa klawiatury i czytników, także pusta opcja „wszystkie”.
const model = defineModel<string>({ default: '' })
defineProps<{ id: string, label: string, options: { value: string, label: string }[], placeholder?: string, hint?: string }>()
</script>

<template>
  <div class="flex flex-col gap-1">
    <label :for="id" class="text-sm font-medium text-highlighted">{{ label }}</label>
    <select :id="id" v-model="model" :aria-describedby="hint ? `${id}-hint` : undefined"
            class="min-h-11 w-full rounded-md border border-accented bg-default px-3 text-default">
      <option v-if="placeholder !== undefined" value="">{{ placeholder }}</option>
      <option v-for="o in options" :key="o.value" :value="o.value">{{ o.label }}</option>
    </select>
    <p v-if="hint" :id="`${id}-hint`" class="text-sm text-muted">{{ hint }}</p>
  </div>
</template>
