<script setup lang="ts">
// Prosta, dostępna paginacja: nav z etykietą, przyciski z opisem, bieżąca strona ogłaszana tekstem.
const props = defineProps<{ page: number, pages: number, total: number, label?: string }>()
const emit = defineEmits<{ 'update:page': [number] }>()
const go = (p: number) => emit('update:page', Math.min(Math.max(1, p), props.pages))
</script>

<template>
  <nav v-if="pages > 1" :aria-label="label || 'Strony wyników'" class="mt-4 flex flex-wrap items-center justify-between gap-3">
    <p class="text-muted">Strona {{ page }} z {{ pages }}</p>
    <div class="flex gap-2">
      <UButton variant="outline" color="neutral" icon="i-lucide-chevron-left" :disabled="page <= 1" label="Poprzednia" @click="go(page - 1)" />
      <UButton variant="outline" color="neutral" trailing-icon="i-lucide-chevron-right" :disabled="page >= pages" label="Następna" @click="go(page + 1)" />
    </div>
  </nav>
</template>
