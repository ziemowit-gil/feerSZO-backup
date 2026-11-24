<script setup lang="ts">
import type { ApiError } from '~/composables/useApi'
import type { CaseDetail } from '~/utils/types'

const route = useRoute()
const api = useApi()
const toast = useToast()
const session = useCrmSession()
const id = Number(route.params.id)
const { data, error, refresh } = await useAsyncData(`case-${id}`, () => api.get<CaseDetail>(`/cases/${id}`), { deep: true })
const cs = computed(() => data.value?.data)
const canEdit = computed(() => !!data.value?.perms.edit)
useHead({ title: () => cs.value?.title ?? 'Sprawa' })

const noteBody = ref('')
const noteErr = ref('')
const noteBusy = ref(false)
async function addNote() {
  noteErr.value = ''
  if (!noteBody.value.trim()) { noteErr.value = 'Wpisz treść notatki.'; return }
  noteBusy.value = true
  try {
    data.value = await api.post<CaseDetail>(`/cases/${id}/notes`, { body: noteBody.value })
    noteBody.value = ''
    toast.add({ title: 'Dodano notatkę', color: 'success', icon: 'i-lucide-check' })
  } catch (e) {
    noteErr.value = (e as ApiError).fields?.body || (e as Error).message
  } finally {
    noteBusy.value = false
  }
}

const stOpen = ref(false)
const stTo = ref('')
const stReason = ref('')
const stErr = ref<Record<string, string>>({})
const stBusy = ref(false)
const closing = computed(() => !!session.dict.value?.case_statuses.find(s => s.value === stTo.value)?.closed)
function openStatus() {
  stTo.value = cs.value?.status ?? ''
  stReason.value = ''
  stErr.value = {}
  stOpen.value = true
}
async function saveStatus() {
  stErr.value = {}
  if (closing.value && !stReason.value) { stErr.value = { reason: 'Wybierz powód zamknięcia.' }; return }
  stBusy.value = true
  try {
    data.value = await api.post<CaseDetail>(`/cases/${id}/status`, { status: stTo.value, reason: stReason.value })
    stOpen.value = false
    toast.add({ title: 'Zmieniono status', description: cs.value?.status_label, color: 'success', icon: 'i-lucide-check' })
  } catch (e) {
    stErr.value = (e as ApiError).fields ?? { status: (e as Error).message }
  } finally {
    stBusy.value = false
  }
}
const PRIO: Record<string, string> = { low: 'Niski', medium: 'Średni', high: 'Wysoki' }
const SLA_STATE: Record<string, { label: string, color: 'success' | 'warning' | 'error' | 'neutral' }> = {
  ok: { label: 'w terminie', color: 'success' }, soon: { label: 'termin blisko', color: 'warning' },
  breach: { label: 'przekroczony', color: 'error' }, met: { label: 'dotrzymany', color: 'success' },
}
</script>

<template>
  <div>
    <UButton to="/sprawy" variant="link" color="neutral" icon="i-lucide-arrow-left" label="Sprawy" class="mb-2 -ml-2" />
    <ErrorState v-if="error" :message="error.message" @retry="refresh()" />
    <template v-else-if="cs && data">
      <PageHeader :title="cs.title" :description="[cs.case_number, cs.type_name].filter(Boolean).join(' · ') || undefined">
        <template #actions>
          <UButton v-if="canEdit" icon="i-lucide-refresh-cw" label="Zmień status" @click="openStatus" />
          <UButton :href="data.legacy_url" icon="i-lucide-external-link" label="Pisma, pliki, zadania" color="neutral" variant="outline" />
        </template>
      </PageHeader>

      <div class="grid gap-6 lg:grid-cols-[1fr_20rem]">
        <div class="space-y-6">
          <UCard v-if="cs.description" as="section" aria-labelledby="h-opis">
            <template #header><h2 id="h-opis" class="text-lg font-semibold text-highlighted">Opis</h2></template>
            <p class="whitespace-pre-line">{{ cs.description }}</p>
          </UCard>

          <UCard as="section" aria-labelledby="h-notatki">
            <template #header><h2 id="h-notatki" class="text-lg font-semibold text-highlighted">Notatki</h2></template>
            <form v-if="canEdit" novalidate class="mb-5 space-y-3" @submit.prevent="addNote">
              <UFormField label="Nowa notatka" name="body" :error="noteErr || undefined">
                <UTextarea v-model="noteBody" name="body" :rows="3" autoresize class="w-full" />
              </UFormField>
              <UButton type="submit" label="Dodaj notatkę" icon="i-lucide-plus" :loading="noteBusy" />
            </form>
            <EmptyState v-if="!cs.notes.length" title="Brak notatek" icon="i-lucide-sticky-note" />
            <ol v-else class="space-y-3">
              <li v-for="n in cs.notes" :key="n.id" class="rounded-lg border border-default p-4">
                <p class="mb-1 text-sm text-muted">{{ n.author || 'System' }} · {{ fmtDateTime(n.created_at) }}</p>
                <p class="whitespace-pre-line">{{ n.body }}</p>
              </li>
            </ol>
          </UCard>

          <UCard v-if="cs.history.length" as="section" aria-labelledby="h-historia">
            <template #header><h2 id="h-historia" class="text-lg font-semibold text-highlighted">Historia statusów</h2></template>
            <ol class="space-y-2">
              <li v-for="(h, i) in cs.history" :key="i" class="text-sm">
                <span class="text-muted">{{ fmtDateTime(h.at) }}</span> ·
                <span v-if="h.from_label">{{ h.from_label }} → </span><strong>{{ h.to_label }}</strong>
                <span v-if="h.user" class="text-muted"> · {{ h.user }}</span>
              </li>
            </ol>
          </UCard>
        </div>

        <aside aria-label="Szczegóły sprawy" class="space-y-6">
          <UCard>
            <dl class="space-y-3">
              <div><dt class="text-sm text-muted">Status</dt><dd class="mt-1"><StatusChip :label="cs.status_label" :color="cs.status_color" /></dd></div>
              <div v-if="cs.is_closed && cs.closed_reason_label"><dt class="text-sm text-muted">Powód zamknięcia</dt><dd>{{ cs.closed_reason_label }}</dd></div>
              <div><dt class="text-sm text-muted">Priorytet</dt><dd :class="cs.priority === 'high' && 'font-semibold text-error'">{{ PRIO[cs.priority] || cs.priority }}</dd></div>
              <div><dt class="text-sm text-muted">Kontakt</dt><dd><NuxtLink v-if="cs.contact_id" :to="`/kontakty/${cs.contact_id}`" class="text-primary underline underline-offset-2">{{ cs.contact_name }}</NuxtLink></dd></div>
              <div><dt class="text-sm text-muted">Opiekun</dt><dd>{{ cs.owner_name || '—' }}</dd></div>
              <div><dt class="text-sm text-muted">Termin</dt><dd :class="cs.is_overdue && 'font-semibold text-error'">{{ cs.due_date ? fmtDate(cs.due_date) : '—' }}<span v-if="cs.is_overdue"> (po terminie)</span></dd></div>
              <div><dt class="text-sm text-muted">Założona</dt><dd>{{ fmtDateTime(cs.created_at) }}<span v-if="cs.creator_name" class="text-muted"> · {{ cs.creator_name }}</span></dd></div>
            </dl>
          </UCard>
          <UCard v-if="cs.sla" as="section" aria-labelledby="h-sla">
            <template #header><h2 id="h-sla" class="font-semibold text-highlighted">SLA</h2></template>
            <dl class="space-y-3">
              <div v-for="[key, label] in [['response', 'Pierwsza odpowiedź'], ['close', 'Zamknięcie']] as const" :key="key">
                <template v-if="cs.sla[key]">
                  <dt class="text-sm text-muted">{{ label }}</dt>
                  <dd class="flex flex-wrap items-center gap-2">{{ cs.sla[key]!.deadline || '—' }}
                    <UBadge v-if="cs.sla[key]!.state" :label="SLA_STATE[cs.sla[key]!.state!]?.label" :color="SLA_STATE[cs.sla[key]!.state!]?.color" variant="subtle" />
                  </dd>
                </template>
              </div>
            </dl>
          </UCard>
          <UCard v-if="cs.parties.length > 1" as="section" aria-labelledby="h-strony">
            <template #header><h2 id="h-strony" class="font-semibold text-highlighted">Strony sprawy</h2></template>
            <ul class="space-y-2">
              <li v-for="p in cs.parties" :key="p.contact_id">
                <NuxtLink :to="`/kontakty/${p.contact_id}`" class="text-primary underline underline-offset-2">{{ p.name }}</NuxtLink>
                <span class="text-sm text-muted"> · {{ p.role }}</span>
              </li>
            </ul>
          </UCard>
        </aside>
      </div>

      <UModal v-model:open="stOpen" title="Zmień status sprawy">
        <template #body>
          <form id="st-form" novalidate class="space-y-4" @submit.prevent="saveStatus">
            <div class="flex flex-col gap-1">
              <label for="st-to" class="text-sm font-medium text-highlighted">Nowy status</label>
              <select id="st-to" v-model="stTo" class="min-h-11 rounded-md border border-accented bg-default px-3" :aria-invalid="!!stErr.status" :aria-describedby="stErr.status ? 'st-err' : undefined">
                <option v-for="s in session.dict.value?.case_statuses" :key="s.value" :value="s.value">{{ s.label }}</option>
              </select>
              <p v-if="stErr.status" id="st-err" role="alert" class="text-sm text-error">{{ stErr.status }}</p>
            </div>
            <div v-if="closing" class="flex flex-col gap-1">
              <label for="st-reason" class="text-sm font-medium text-highlighted">Powód zamknięcia <span aria-hidden="true">*</span></label>
              <select id="st-reason" v-model="stReason" required class="min-h-11 rounded-md border border-accented bg-default px-3" :aria-invalid="!!stErr.reason" :aria-describedby="stErr.reason ? 'st-rerr' : undefined">
                <option value="">Wybierz…</option>
                <option v-for="r in session.dict.value?.case_close_reasons" :key="r.value" :value="r.value">{{ r.label }}</option>
              </select>
              <p v-if="stErr.reason" id="st-rerr" role="alert" class="text-sm text-error">{{ stErr.reason }}</p>
            </div>
          </form>
        </template>
        <template #footer>
          <div class="flex w-full justify-end gap-2">
            <UButton label="Anuluj" color="neutral" variant="outline" @click="stOpen = false" />
            <UButton type="submit" form="st-form" label="Zapisz status" :loading="stBusy" />
          </div>
        </template>
      </UModal>
    </template>
    <p v-else role="status" class="text-muted">Wczytywanie sprawy…</p>
  </div>
</template>
