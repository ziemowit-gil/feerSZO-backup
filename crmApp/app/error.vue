<script setup lang="ts">
import type { NuxtError } from '#app'

const props = defineProps<{ error: NuxtError }>()
const notFound = computed(() => props.error.statusCode === 404)
useHead({ title: notFound.value ? 'Nie znaleziono strony' : 'Błąd' })
</script>

<template>
  <UApp>
    <main id="tresc" class="mx-auto flex min-h-dvh max-w-lg flex-col justify-center gap-4 px-4">
      <h1 class="text-2xl font-semibold text-highlighted">{{ notFound ? 'Nie ma takiej strony' : 'Coś poszło nie tak' }}</h1>
      <p class="text-muted">{{ notFound ? 'Adres jest nieaktualny albo ma literówkę.' : error.message }}</p>
      <div><UButton label="Wróć na pulpit CRM" icon="i-lucide-house" @click="clearError({ redirect: '/' })" /></div>
    </main>
  </UApp>
</template>
