<script setup lang="ts">
// Powrót z logowania SZO (?code=…&state=… albo ?error=access_denied).
definePageMeta({ layout: 'bare' })
useHead({ title: 'Logowanie' })
const route = useRoute()
const auth = useAuth()
const error = ref<string | null>(null)

onMounted(async () => {
  const q = route.query
  if (q.error) {
    error.value = q.error === 'access_denied' ? 'Logowanie zostało anulowane albo Twoje konto nie ma dostępu do CRM.' : 'Logowanie się nie powiodło.'
    return
  }
  try {
    const back = await auth.finishLogin(String(q.code || ''), String(q.state || ''))
    await navigateTo(back, { replace: true })
  } catch (e) {
    const err = e as { data?: { error?: string }, message?: string }
    error.value = err.data?.error || err.message || 'Logowanie się nie powiodło.'
  }
})
</script>

<template>
  <div>
    <h1 class="mb-4 text-2xl font-semibold text-highlighted">Logowanie do CRM</h1>
    <div v-if="error" class="space-y-4">
      <UAlert color="error" variant="subtle" icon="i-lucide-circle-alert" :description="error" role="alert" />
      <UButton label="Zaloguj się ponownie" icon="i-lucide-log-in" @click="auth.login('/')" />
    </div>
    <p v-else role="status" class="flex items-center gap-2 text-muted">
      <UIcon name="i-lucide-loader-circle" class="size-5 animate-spin" aria-hidden="true" /> Kończymy logowanie…
    </p>
  </div>
</template>
