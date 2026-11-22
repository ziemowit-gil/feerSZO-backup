<script setup lang="ts">
definePageMeta({ layout: 'bare' })
useHead({ title: 'Wylogowano' })
const auth = useAuth()
</script>

<template>
  <div class="space-y-4">
    <h1 class="text-2xl font-semibold text-highlighted">Wylogowano z aplikacji CRM</h1>
    <p class="text-muted">Dostęp tej karty do CRM został cofnięty. Konto SZO w przeglądarce pozostaje zalogowane — wylogujesz je w SZO.</p>
    <UButton label="Zaloguj się ponownie" icon="i-lucide-log-in" @click="auth.login('/')" />
  </div>
</template>
