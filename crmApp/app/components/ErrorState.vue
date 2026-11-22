<script setup lang="ts">
// Błąd wczytywania z przyciskiem ponowienia. role="alert" — czytnik ekranu ogłasza go od razu.
defineProps<{ message: string }>()
defineEmits<{ retry: [] }>()
</script>

<template>
  <UAlert color="error" variant="subtle" icon="i-lucide-circle-alert" title="Nie udało się wczytać danych" :description="message" role="alert">
    <template #actions>
      <UButton color="error" variant="outline" label="Spróbuj ponownie" @click="$emit('retry')" />
    </template>
  </UAlert>
</template>
