<script setup lang="ts">
// Nagłówek strony: jedyny <h1>, opcjonalny opis i akcje po prawej. Tytuł karty przeglądarki ustawia useHead.
const props = defineProps<{ title: string, description?: string }>()
useHead({ title: () => props.title })
</script>

<template>
  <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
    <div class="min-w-0">
      <slot name="before" />
      <h1 class="text-2xl font-semibold text-highlighted sm:text-3xl">{{ title }}</h1>
      <p v-if="description" class="mt-1 text-muted">{{ description }}</p>
    </div>
    <div v-if="$slots.actions" class="flex flex-wrap items-center gap-2">
      <slot name="actions" />
    </div>
  </div>
</template>
