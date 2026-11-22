<script setup lang="ts">
import type { ApiError } from '~/composables/useApi'
import type { CaseDetail, ContactRow, Paged } from '~/utils/types'

const route = useRoute()
const api = useApi()
const toast = useToast()
const session = useCrmSession()

const form = reactive({ contact_id: '', title: '', description: '', priority: 'medium', type_id: '', owner_id: '', due_date: '' })
const errors = ref<Record<string, string>>({})
const message = ref<string | undefined>()
const busy = ref(false)
const LABELS = { contact_id: 'Kontakt', title: 'Tytuł', due_date: 'Termin', type_id: 'Typ', owner_id: 'Opiekun' }

// Wybór kontaktu: wyszukiwarka (combobox Nuxt UI — klawiatura i czytnik ekranu obsłużone).
type Item = { label: string, value: string, description?: string }
const contact = ref<Item | undefined>()
const term = ref('')
const items = ref<Item[]>([])
const searching = ref(false)
let timer: ReturnType<typeof setTimeout> | undefined
watch(term, (q) => {
  clearTimeout(timer)
  if (q.trim().length < 2) { items.value = contact.value ? [contact.value] : []; return }
  timer = setTimeout(async () => {
    searching.value = true
    try {
      const r = await api.get<Paged<ContactRow>>('/contacts', { q, per_page: 10 })
      items.value = r.data.map(c => ({ label: c.imie_nazwisko || `#${c.id}`, value: String(c.id), description: c.organizacja || c.email || undefined }))
    } finally {
      searching.value = false
    }
  }, 250)
})
watch(contact, v => { form.contact_id = v?.value ?? '' })

onMounted(async () => {
  const pre = Number(route.query.contact || 0)
  if (pre) {
    try {
      const r = await api.get<{ data: { id: number, imie_nazwisko: string | null } }>(`/contacts/${pre}`)
      contact.value = { label: r.data.imie_nazwisko || `#${pre}`, value: String(pre) }
      items.value = [contact.value]
    } catch { /* brak dostępu — wybór ręczny */ }
  }
  form.owner_id = String(session.me.value?.user.id ?? '')
})

async function save() {
  busy.value = true
  errors.value = {}
  message.value = undefined
  try {
    const r = await api.post<CaseDetail>('/cases', form)
    toast.add({ title: 'Założono sprawę', description: r.data.case_number ?? '', color: 'success', icon: 'i-lucide-check' })
    await navigateTo(`/sprawy/${r.data.id}`)
  } catch (e) {
    errors.value = (e as ApiError).fields ?? {}
    message.value = (e as Error).message
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="max-w-3xl">
    <PageHeader title="Nowa sprawa" description="Sprawa dostanie numer CRM/NNNN/RRRR">
      <template #before>
        <UButton to="/sprawy" variant="link" color="neutral" icon="i-lucide-arrow-left" label="Sprawy" class="mb-2 -ml-2" />
      </template>
    </PageHeader>
    <form novalidate class="space-y-6" @submit.prevent="save">
      <FormErrorSummary :errors="errors" :labels="LABELS" :message="message" />
      <UCard>
        <div class="grid gap-4 sm:grid-cols-2">
          <UFormField label="Kontakt" name="contact_id" :error="errors.contact_id" required help="Wpisz co najmniej 2 litery nazwy, e-maila albo telefonu." class="sm:col-span-2">
            <UInputMenu v-model="contact" v-model:search-term="term" :items="items" :loading="searching" ignore-filter
                        name="contact_id" placeholder="Szukaj kontaktu…" icon="i-lucide-search" class="w-full" />
          </UFormField>
          <UFormField label="Tytuł" name="title" :error="errors.title" required class="sm:col-span-2">
            <UInput v-model="form.title" name="title" class="w-full" />
          </UFormField>
          <UFormField label="Opis" name="description" class="sm:col-span-2">
            <UTextarea v-model="form.description" name="description" :rows="4" class="w-full" />
          </UFormField>
          <NativeSelect id="c-prio" v-model="form.priority" label="Priorytet" :options="session.dict.value?.case_priorities ?? []" />
          <NativeSelect v-if="session.dict.value?.case_types.length" id="c-type" v-model="form.type_id" label="Typ sprawy" placeholder="— bez typu —"
                        :options="(session.dict.value?.case_types ?? []).map(t => ({ value: String(t.id), label: t.name }))" />
          <NativeSelect id="c-owner" v-model="form.owner_id" label="Opiekun" :options="(session.dict.value?.owners ?? []).map(o => ({ value: String(o.id), label: o.name }))" />
          <UFormField label="Termin" name="due_date" :error="errors.due_date">
            <UInput v-model="form.due_date" name="due_date" type="date" class="w-full" />
          </UFormField>
        </div>
      </UCard>
      <div class="flex gap-3">
        <UButton type="submit" label="Załóż sprawę" icon="i-lucide-save" :loading="busy" />
        <UButton to="/sprawy" label="Anuluj" color="neutral" variant="outline" />
      </div>
    </form>
  </div>
</template>
