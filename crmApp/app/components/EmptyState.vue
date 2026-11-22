<script setup lang="ts">
defineProps<{ title: string, description?: string, icon?: string }>()
</script>

<template>
  <div class="flex flex-col items-center gap-2 rounded-lg border border-dashed border-default px-6 py-10 text-center">
    <UIcon :name="icon || 'i-lucide-inbox'" class="size-8 text-dimmed" aria-hidden="true" />
    <p class="font-medium text-highlighted">{{ title }}</p>
    <p v-if="description" class="max-w-prose text-muted">{{ description }}</p>
    <slot />
  </div>
</template>
