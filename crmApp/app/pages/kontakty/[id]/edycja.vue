<script setup lang="ts">
import type { ContactDetail } from '~/utils/types'
import type { ApiError } from '~/composables/useApi'

const route = useRoute()
const api = useApi()
const toast = useToast()
const id = Number(route.params.id)
const { data, error, refresh } = await useAsyncData(`contact-${id}`, () => api.get<ContactDetail>(`/contacts/${id}`))
const errors = ref<Record<string, string>>({})
const message = ref<string | undefined>()
const busy = ref(false)

async function save(body: Record<string, unknown>) {
  busy.value = true
  errors.value = {}
  message.value = undefined
  try {
    await api.post<ContactDetail>(`/contacts/${id}`, body)
    toast.add({ title: 'Zapisano zmiany', color: 'success', icon: 'i-lucide-check' })
    await navigateTo(`/kontakty/${id}`)
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
    <PageHeader :title="data ? `Edycja: ${data.data.imie_nazwisko ?? 'kontakt'}` : 'Edycja kontaktu'">
      <template #before>
        <UButton :to="`/kontakty/${id}`" variant="link" color="neutral" icon="i-lucide-arrow-left" label="Wróć do kartoteki" class="mb-2 -ml-2" />
      </template>
    </PageHeader>
    <ErrorState v-if="error" :message="error.message" @retry="refresh()" />
    <UAlert v-else-if="data && !data.perms.edit" color="warning" variant="subtle" title="Tylko podgląd" description="Nie masz prawa zmiany kartotek." />
    <ContactForm v-else-if="data" :initial="data.data" :field-view="data.perms.field_view" :field-edit="data.perms.field_edit"
                 submit-label="Zapisz zmiany" :busy="busy" :errors="errors" :message="message" @submit="save">
      <template #cancel><UButton :to="`/kontakty/${id}`" label="Anuluj" color="neutral" variant="outline" /></template>
    </ContactForm>
  </div>
</template>
