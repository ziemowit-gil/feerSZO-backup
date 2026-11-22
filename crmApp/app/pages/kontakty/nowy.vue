<script setup lang="ts">
import type { ContactDetail } from '~/utils/types'
import type { ApiError } from '~/composables/useApi'

const api = useApi()
const toast = useToast()
const errors = ref<Record<string, string>>({})
const message = ref<string | undefined>()
const busy = ref(false)

async function save(body: Record<string, unknown>) {
  busy.value = true
  errors.value = {}
  message.value = undefined
  try {
    const r = await api.post<ContactDetail>('/contacts', body)
    toast.add({ title: 'Dodano kontakt', description: r.data.imie_nazwisko ?? '', color: 'success', icon: 'i-lucide-check' })
    await navigateTo(`/kontakty/${r.data.id}`)
  } catch (e) {
    const err = e as ApiError
    errors.value = err.fields ?? {}
    message.value = err.message
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="max-w-4xl">
    <PageHeader title="Nowy kontakt" description="Osoba fizyczna albo organizacja">
      <template #before>
        <UButton to="/kontakty" variant="link" color="neutral" icon="i-lucide-arrow-left" label="Kontakty" class="mb-2 -ml-2" />
      </template>
    </PageHeader>
    <ContactForm submit-label="Dodaj kontakt" :busy="busy" :errors="errors" :message="message" @submit="save">
      <template #cancel><UButton to="/kontakty" label="Anuluj" color="neutral" variant="outline" /></template>
    </ContactForm>
  </div>
</template>
