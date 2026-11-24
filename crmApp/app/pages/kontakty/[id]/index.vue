<script setup lang="ts">
import type { TabsItem } from '@nuxt/ui'
import type { ApiError } from '~/composables/useApi'
import type { Activity, CaseRow, Communication, ContactDetail, Note, Task } from '~/utils/types'

const route = useRoute()
const router = useRouter()
const api = useApi()
const toast = useToast()
const session = useCrmSession()
const id = Number(route.params.id)

// deep: true — notatki/tagi zmieniają dane na miejscu (w Nuxt 4 useAsyncData jest domyślnie płytkie)
const { data, error, refresh } = await useAsyncData(`contact-${id}`, () => api.get<ContactDetail>(`/contacts/${id}`), { deep: true })
const c = computed(() => data.value?.data)
useHead({ title: () => c.value?.imie_nazwisko || 'Kartoteka' })
const perms = computed(() => data.value?.perms)

// Zakładki: aktywna w ?tab= (link do konkretnej zakładki, „wstecz” działa).
const tab = computed({
  get: () => String(route.query.tab || 'aktywnosc'),
  set: v => router.replace({ query: { ...route.query, tab: v } }),
})
const tabs = computed<TabsItem[]>(() => {
  const t: TabsItem[] = [
    { label: 'Aktywność', value: 'aktywnosc', icon: 'i-lucide-activity', slot: 'aktywnosc' as const },
    { label: `Notatki${data.value?.facts.notes ? ` (${data.value.facts.notes})` : ''}`, value: 'notatki', icon: 'i-lucide-sticky-note', slot: 'notatki' as const },
  ]
  if (session.can('cases')) t.splice(1, 0, { label: 'Sprawy', value: 'sprawy', icon: 'i-lucide-briefcase', slot: 'sprawy' as const })
  t.push({ label: 'Dane i powiązania', value: 'dane', icon: 'i-lucide-id-card', slot: 'dane' as const })
  return t
})

// Dane zakładek wczytywane przy pierwszym wejściu.
const activity = ref<{ tasks: Task[], activities: Activity[], communications: Communication[] } | null>(null)
const cases = ref<CaseRow[] | null>(null)
const notes = ref<Note[] | null>(null)
const tabError = ref<string | null>(null)
async function loadTab(t: string) {
  tabError.value = null
  try {
    if (t === 'aktywnosc' && !activity.value) activity.value = (await api.get<{ data: typeof activity.value }>(`/contacts/${id}/activity`)).data
    if (t === 'sprawy' && !cases.value) cases.value = (await api.get<{ data: CaseRow[] }>(`/contacts/${id}/cases`)).data
    if (t === 'notatki' && !notes.value) notes.value = (await api.get<{ data: Note[] }>(`/contacts/${id}/notes`)).data
  } catch (e) {
    tabError.value = (e as Error).message
  }
}
watch(tab, t => loadTab(t), { immediate: true })

// ── notatki ────────────────────────────────────────────────────────────────
const noteBody = ref('')
const notePinned = ref(false)
const noteErr = ref('')
const noteBusy = ref(false)
async function addNote() {
  noteErr.value = ''
  if (!noteBody.value.trim()) {
    noteErr.value = 'Wpisz treść notatki.'
    document.querySelector<HTMLElement>('textarea[name="body"]')?.focus()
    return
  }
  noteBusy.value = true
  try {
    const r = await api.post<{ data: Note }>(`/contacts/${id}/notes`, { body: noteBody.value, pinned: notePinned.value })
    notes.value = [r.data, ...(notes.value ?? [])].sort((a, b) => Number(b.is_pinned) - Number(a.is_pinned))
    noteBody.value = ''
    notePinned.value = false
    if (data.value) data.value.facts.notes++
    toast.add({ title: 'Dodano notatkę', color: 'success', icon: 'i-lucide-check' })
  } catch (e) {
    noteErr.value = (e as ApiError).fields?.body || (e as Error).message
  } finally {
    noteBusy.value = false
  }
}
async function deleteNote(n: Note) {
  if (!window.confirm('Usunąć tę notatkę? Tego nie da się cofnąć.')) return
  try {
    await api.post(`/notes/${n.id}/delete`)
    notes.value = (notes.value ?? []).filter(x => x.id !== n.id)
    if (data.value) data.value.facts.notes--
    toast.add({ title: 'Usunięto notatkę', color: 'success' })
  } catch (e) {
    toast.add({ title: 'Nie udało się', description: (e as Error).message, color: 'error' })
  }
}

// ── tagi ───────────────────────────────────────────────────────────────────
const newTag = ref('')
async function addTag() {
  const t = newTag.value.trim()
  if (!t || !c.value) return
  try {
    c.value.tags = (await api.post<{ data: string[] }>(`/contacts/${id}/tags`, { tag: t })).data
    newTag.value = ''
    toast.add({ title: `Dodano tag „${t.toLowerCase()}”`, color: 'success', icon: 'i-lucide-check' })
  } catch (e) {
    toast.add({ title: 'Nie dodano tagu', description: (e as Error).message, color: 'error' })
  }
}
async function removeTag(t: string) {
  if (!c.value) return
  try {
    c.value.tags = (await api.post<{ data: string[] }>(`/contacts/${id}/tags/delete`, { tag: t })).data
    toast.add({ title: `Usunięto tag „${t}”`, color: 'success' })
  } catch (e) {
    toast.add({ title: 'Nie usunięto tagu', description: (e as Error).message, color: 'error' })
  }
}

// ── usuwanie kartoteki ─────────────────────────────────────────────────────
const delOpen = ref(false)
const delReason = ref('')
const delNote = ref('')
const delErr = ref('')
const delBusy = ref(false)
async function destroy() {
  delErr.value = ''
  if (!delReason.value) { delErr.value = 'Wybierz powód usunięcia.'; return }
  delBusy.value = true
  try {
    await api.post(`/contacts/${id}/delete`, { reason: delReason.value, note: delNote.value })
    toast.add({ title: 'Usunięto kartotekę', color: 'success' })
    await navigateTo('/kontakty')
  } catch (e) {
    delErr.value = (e as ApiError).fields?.reason || (e as Error).message
  } finally {
    delBusy.value = false
  }
}

const status = computed(() => session.statusOf(c.value?.status))
const orgLike = computed(() => !!session.typeOf(c.value?.type)?.org_like)
const show = (f: string) => perms.value?.field_view[f] !== false
const address = computed(() => {
  if (!c.value) return ''
  const v = c.value as Record<string, unknown>
  const street = [v.addr_street, [v.addr_house, v.addr_flat].filter(Boolean).join('/')].filter(Boolean).join(' ')
  return [street, [v.addr_postal, v.addr_city].filter(Boolean).join(' '), v.wojewodztwo].filter(Boolean).join(', ') || (v.adres as string) || ''
})
const dataRows = computed(() => {
  const v = (c.value ?? {}) as Record<string, unknown>
  const rows: [string, string, unknown][] = orgLike.value
    ? [['nip', 'NIP', v.nip], ['regon', 'REGON', v.regon], ['krs', 'KRS', v.krs], ['', 'Forma prawna', v.forma_prawna], ['', 'Osoba kontaktowa', v.osoba_kontaktowa], ['branza', 'Branża', v.branza]]
    : [['organizacja', 'Organizacja', v.organizacja], ['stanowisko', 'Stanowisko', v.stanowisko], ['data_urodzenia', 'Data urodzenia', v.data_urodzenia]]
  rows.push(['strona_www', 'Strona WWW', v.strona_www], ['adres', 'Adres', address.value], ['', 'Źródło', v.source], ['', 'Utworzono', fmtDateTime(v.created_at as string)])
  return rows.filter(([f]) => !f || show(f)).map(([, l, val]) => [l, val ? String(val) : '—'] as const)
})
const ACT_LABEL: Record<string, string> = { call: 'Telefon', email: 'E-mail', meeting: 'Spotkanie', task: 'Zadanie', demo: 'Prezentacja', lunch: 'Spotkanie', other: 'Inne' }
const CH_LABEL: Record<string, string> = { email: 'E-mail', sms: 'SMS', telefon: 'Telefon', osobisty: 'Osobiście' }
</script>

<template>
  <div>
    <ErrorState v-if="error" :message="error.message" @retry="refresh()" />
    <template v-else-if="c && data">
      <UButton to="/kontakty" variant="link" color="neutral" icon="i-lucide-arrow-left" label="Kontakty" class="mb-2 -ml-2" />

      <!-- Nagłówek kartoteki -->
      <section aria-labelledby="h-kontakt" class="mb-6 rounded-2xl border border-default bg-default p-5 sm:p-6">
        <div class="flex flex-wrap items-start gap-4">
          <UAvatar :text="(c.avatar_initials as string) || initials(c.imie_nazwisko)" size="3xl" aria-hidden="true" />
          <div class="min-w-0 flex-1">
            <h1 id="h-kontakt" class="text-2xl font-semibold text-highlighted sm:text-3xl">{{ c.imie_nazwisko || 'Bez nazwy' }}</h1>
            <p class="mt-1 text-muted">
              {{ session.typeOf(c.type)?.label }}
              <template v-if="!orgLike && (c.stanowisko || c.organizacja)"> · {{ [c.stanowisko, c.organizacja].filter(Boolean).join(', ') }}</template>
            </p>
            <div class="mt-3 flex flex-wrap items-center gap-2">
              <StatusChip v-if="c.status" :label="status?.label || String(c.status)" :color="status?.color" />
              <UBadge v-if="c.is_critical" color="warning" variant="subtle" icon="i-lucide-triangle-alert" label="Krytyczny" />
              <UBadge v-if="c.email_opt_out" color="neutral" variant="outline" icon="i-lucide-mail-x" label="Nie chce maili" />
              <span class="text-sm text-muted">Opiekun: {{ c.owner_name || 'brak' }}</span>
            </div>
          </div>
          <div class="flex flex-wrap gap-2">
            <UButton v-if="perms?.edit" :to="`/kontakty/${id}/edycja`" icon="i-lucide-pencil" label="Edytuj" />
            <UButton v-if="session.can('cases', 'write')" :to="{ path: '/sprawy/nowa', query: { contact: id } }" icon="i-lucide-briefcase" label="Nowa sprawa" color="neutral" variant="outline" />
            <UDropdownMenu :items="[[{ label: 'Otwórz w klasycznym CRM', icon: 'i-lucide-external-link', href: data.legacy_url }],
                                    ...(perms?.delete ? [[{ label: 'Usuń kartotekę…', icon: 'i-lucide-trash-2', color: 'error' as const, onSelect: () => { delOpen = true } }]] : [])]">
              <UButton icon="i-lucide-ellipsis" color="neutral" variant="outline" aria-label="Więcej akcji" />
            </UDropdownMenu>
          </div>
        </div>

        <!-- Kontakt bezpośredni -->
        <ul class="mt-5 flex flex-wrap gap-x-6 gap-y-2">
          <li v-if="c.email && show('email')"><a :href="`mailto:${c.email}`" class="inline-flex min-h-11 items-center gap-2 text-primary underline-offset-2 hover:underline"><UIcon name="i-lucide-mail" class="size-5" aria-hidden="true" /><span class="sr-only">E-mail:</span>{{ c.email }}</a></li>
          <li v-if="c.telefon && show('telefon')"><a :href="`tel:${String(c.telefon).replace(/\s/g, '')}`" class="inline-flex min-h-11 items-center gap-2 text-primary underline-offset-2 hover:underline"><UIcon name="i-lucide-phone" class="size-5" aria-hidden="true" /><span class="sr-only">Telefon:</span>{{ c.telefon }}</a></li>
          <li v-if="address && show('adres')" class="inline-flex min-h-11 items-center gap-2 text-toned"><UIcon name="i-lucide-map-pin" class="size-5" aria-hidden="true" /><span class="sr-only">Adres:</span>{{ address }}</li>
        </ul>

        <!-- Fakty -->
        <dl class="mt-5 grid grid-cols-2 gap-3 border-t border-default pt-5 sm:grid-cols-4">
          <div><dt class="text-sm text-muted">Otwarte sprawy</dt><dd class="text-xl font-semibold text-highlighted">{{ data.facts.open_cases }}</dd></div>
          <div v-if="data.facts.offers"><dt class="text-sm text-muted">Oferty w toku</dt><dd class="text-xl font-semibold text-highlighted">{{ data.facts.offers.open }}</dd></div>
          <div v-if="data.facts.offers?.won_value"><dt class="text-sm text-muted">Wygrane oferty</dt><dd class="text-xl font-semibold text-highlighted">{{ fmtMoney(data.facts.offers.won_value) }}</dd></div>
          <div><dt class="text-sm text-muted">Ostatni kontakt</dt><dd class="text-xl font-semibold text-highlighted">{{ fmtRelative(data.facts.last_contact_at) }}<span v-if="data.facts.last_contact_in" class="ml-1 text-sm font-normal text-muted">(od kontaktu)</span></dd></div>
        </dl>

        <!-- Tagi -->
        <div class="mt-5 border-t border-default pt-5">
          <h2 class="mb-2 text-sm font-semibold text-muted">Tagi</h2>
          <ul class="flex flex-wrap items-center gap-2">
            <li v-for="t in c.tags" :key="t">
              <span class="inline-flex items-center gap-1 rounded-full border border-default py-0.5 pr-1 pl-3 text-sm">
                {{ t }}
                <UButton v-if="perms?.edit" size="xs" color="neutral" variant="ghost" icon="i-lucide-x" :aria-label="`Usuń tag ${t}`" @click="removeTag(t)" />
              </span>
            </li>
            <li v-if="!c.tags.length" class="text-muted">Brak tagów</li>
          </ul>
          <form v-if="perms?.edit" class="mt-3 flex max-w-sm gap-2" @submit.prevent="addTag">
            <label for="nowy-tag" class="sr-only">Nowy tag</label>
            <UInput id="nowy-tag" v-model="newTag" placeholder="Nowy tag" size="md" class="flex-1" />
            <UButton type="submit" label="Dodaj tag" size="md" color="neutral" variant="outline" />
          </form>
        </div>
      </section>

      <UTabs v-model="tab" :items="tabs" variant="link" class="w-full" :ui="{ list: 'overflow-x-auto overflow-y-hidden', trigger: 'min-h-11 shrink-0' }">
        <template #aktywnosc>
          <div class="space-y-6 pt-4">
            <ErrorState v-if="tabError" :message="tabError" @retry="activity = null; loadTab('aktywnosc')" />
            <p v-else-if="!activity" role="status" class="text-muted">Wczytywanie…</p>
            <template v-else>
              <UCard v-if="activity.tasks.length" as="section" aria-labelledby="h-zadania">
                <template #header><h2 id="h-zadania" class="text-lg font-semibold text-highlighted">Do zrobienia</h2></template>
                <ul class="divide-y divide-default">
                  <li v-for="t in activity.tasks" :key="t.id" class="flex flex-wrap items-center justify-between gap-2 py-3">
                    <span :class="t.status === 'done' ? 'text-muted line-through' : 'font-medium'">{{ t.title }}<span v-if="t.status === 'done'" class="sr-only"> (zrobione)</span></span>
                    <span class="text-sm text-muted">{{ t.due_date ? `termin ${fmtDate(t.due_date)}` : '' }} {{ t.owner_name ? `· ${t.owner_name}` : '' }}</span>
                  </li>
                </ul>
              </UCard>
              <UCard as="section" aria-labelledby="h-plan">
                <template #header><h2 id="h-plan" class="text-lg font-semibold text-highlighted">Planowane działania</h2></template>
                <EmptyState v-if="!activity.activities.length" title="Brak zaplanowanych działań" icon="i-lucide-calendar" />
                <ul v-else class="divide-y divide-default">
                  <li v-for="a in activity.activities" :key="a.id" class="py-3">
                    <p class="flex flex-wrap items-center gap-2">
                      <UBadge :label="ACT_LABEL[a.type] || a.type" color="neutral" variant="outline" />
                      <span class="font-medium" :class="a.status === 'done' && 'text-muted'">{{ a.title }}</span>
                      <UBadge v-if="a.status === 'done'" label="Wykonane" color="success" variant="subtle" />
                    </p>
                    <p class="mt-1 text-sm text-muted">{{ fmtDateTime(a.scheduled_at) }}<template v-if="a.assigned_name"> · {{ a.assigned_name }}</template></p>
                  </li>
                </ul>
              </UCard>
              <UCard as="section" aria-labelledby="h-kom">
                <template #header><h2 id="h-kom" class="text-lg font-semibold text-highlighted">Historia komunikacji</h2></template>
                <EmptyState v-if="!activity.communications.length" title="Brak komunikacji" icon="i-lucide-mail" />
                <ol v-else class="space-y-4">
                  <li v-for="m in activity.communications" :key="m.id" class="rounded-lg border border-default p-4">
                    <p class="flex flex-wrap items-center gap-2 text-sm text-muted">
                      <UIcon :name="m.direction === 'in' ? 'i-lucide-arrow-down-left' : 'i-lucide-arrow-up-right'" class="size-4" aria-hidden="true" />
                      <span class="font-medium text-toned">{{ m.direction === 'in' ? 'Odebrana' : 'Wysłana' }}</span>
                      <span>{{ CH_LABEL[m.channel || ''] || m.channel }}</span>
                      <span>· {{ fmtDateTime(m.sent_at) }}</span>
                      <span v-if="m.direction === 'out' && m.sender_name">· {{ m.sender_name }}</span>
                    </p>
                    <p v-if="m.subject" class="mt-1 font-medium text-highlighted">{{ m.subject }}</p>
                    <p class="mt-1 line-clamp-3 text-toned">{{ m.excerpt }}</p>
                  </li>
                </ol>
              </UCard>
            </template>
          </div>
        </template>

        <template #sprawy>
          <div class="pt-4">
            <ErrorState v-if="tabError" :message="tabError" @retry="cases = null; loadTab('sprawy')" />
            <p v-else-if="!cases" role="status" class="text-muted">Wczytywanie…</p>
            <EmptyState v-else-if="!cases.length" title="Brak spraw" description="Ten kontakt nie ma jeszcze spraw." icon="i-lucide-briefcase">
              <UButton v-if="session.can('cases', 'write')" :to="{ path: '/sprawy/nowa', query: { contact: id } }" label="Załóż sprawę" icon="i-lucide-plus" />
            </EmptyState>
            <ul v-else class="divide-y divide-default rounded-xl border border-default bg-default">
              <li v-for="cs in cases" :key="cs.id" class="flex flex-wrap items-center gap-3 p-4">
                <div class="min-w-0 flex-1">
                  <NuxtLink :to="`/sprawy/${cs.id}`" class="font-medium text-highlighted underline-offset-2 hover:underline">{{ cs.title }}</NuxtLink>
                  <p class="text-sm text-muted">{{ cs.case_number }} · zmieniona {{ fmtRelative(cs.updated_at) }}</p>
                </div>
                <StatusChip :label="cs.status_label" :color="cs.status_color" />
              </li>
            </ul>
          </div>
        </template>

        <template #notatki>
          <div class="space-y-4 pt-4">
            <UCard v-if="perms?.edit" as="section" aria-labelledby="h-nowa-notatka">
              <template #header><h2 id="h-nowa-notatka" class="text-lg font-semibold text-highlighted">Nowa notatka</h2></template>
              <form novalidate class="space-y-3" @submit.prevent="addNote">
                <UFormField label="Treść" name="body" :error="noteErr || undefined" required>
                  <UTextarea v-model="noteBody" name="body" :rows="4" autoresize class="w-full" />
                </UFormField>
                <div class="flex flex-wrap items-center justify-between gap-3">
                  <UCheckbox v-model="notePinned" label="Przypnij na górze" />
                  <UButton type="submit" label="Dodaj notatkę" icon="i-lucide-plus" :loading="noteBusy" />
                </div>
              </form>
            </UCard>
            <ErrorState v-if="tabError" :message="tabError" @retry="notes = null; loadTab('notatki')" />
            <p v-else-if="!notes" role="status" class="text-muted">Wczytywanie…</p>
            <EmptyState v-else-if="!notes.length" title="Brak notatek" icon="i-lucide-sticky-note" />
            <ol v-else class="space-y-3" aria-label="Notatki">
              <li v-for="n in notes" :key="n.id" class="rounded-xl border bg-default p-4" :class="n.is_pinned ? 'border-primary/50' : 'border-default'">
                <p class="mb-2 flex flex-wrap items-center gap-2 text-sm text-muted">
                  <UBadge v-if="n.is_pinned" label="Przypięta" icon="i-lucide-pin" color="primary" variant="subtle" />
                  <span>{{ n.author || 'System' }}</span><span>· {{ fmtDateTime(n.created_at) }}</span>
                  <UButton v-if="perms?.delete" class="ml-auto" size="sm" color="error" variant="ghost" icon="i-lucide-trash-2" label="Usuń" :aria-label="`Usuń notatkę z ${fmtDateTime(n.created_at)}`" @click="deleteNote(n)" />
                </p>
                <p class="whitespace-pre-line text-default">{{ n.body }}</p>
              </li>
            </ol>
          </div>
        </template>

        <template #dane>
          <div class="grid gap-6 pt-4 lg:grid-cols-2">
            <UCard as="section" aria-labelledby="h-dane">
              <template #header><h2 id="h-dane" class="text-lg font-semibold text-highlighted">Dane</h2></template>
              <dl class="divide-y divide-default">
                <div v-for="[l, v] in dataRows" :key="l" class="grid grid-cols-[10rem_1fr] gap-3 py-2">
                  <dt class="text-muted">{{ l }}</dt><dd class="break-words">{{ v }}</dd>
                </div>
              </dl>
              <div v-if="c.notatka && show('notatka')" class="mt-4 rounded-lg bg-muted p-3">
                <p class="mb-1 text-sm font-semibold text-muted">Notatka wewnętrzna</p>
                <p class="whitespace-pre-line">{{ c.notatka }}</p>
              </div>
            </UCard>
            <div class="space-y-6">
              <UCard v-if="c.persons.length" as="section" aria-labelledby="h-osoby">
                <template #header><h2 id="h-osoby" class="text-lg font-semibold text-highlighted">Osoby kontaktowe</h2></template>
                <ul class="divide-y divide-default">
                  <li v-for="p in c.persons" :key="p.id" class="py-2">
                    <p class="font-medium">{{ p.name }}<span v-if="p.role" class="font-normal text-muted"> · {{ p.role }}</span></p>
                    <p class="text-sm"><a v-if="p.email" :href="`mailto:${p.email}`" class="text-primary underline-offset-2 hover:underline">{{ p.email }}</a> {{ p.telefon }}</p>
                  </li>
                </ul>
              </UCard>
              <UCard as="section" aria-labelledby="h-grupy">
                <template #header><h2 id="h-grupy" class="text-lg font-semibold text-highlighted">Grupy</h2></template>
                <ul v-if="c.groups.length" class="flex flex-wrap gap-2">
                  <li v-for="g in c.groups" :key="g.id"><StatusChip :label="g.name" :color="g.color" /></li>
                </ul>
                <p v-else class="text-muted">Nie należy do żadnej grupy.</p>
              </UCard>
              <p class="text-sm text-muted">Zgody, darowizny, oferty, umowy i relacje są na razie w <a :href="data.legacy_url" class="text-primary underline underline-offset-2">klasycznej kartotece</a>.</p>
            </div>
          </div>
        </template>
      </UTabs>

      <UModal v-model:open="delOpen" title="Usunąć kartotekę?" description="Kartoteka zniknie z list CRM. Powód zostanie zapisany w historii.">
        <template #body>
          <form id="del-form" novalidate class="space-y-4" @submit.prevent="destroy">
            <div class="flex flex-col gap-1">
              <label for="del-reason" class="text-sm font-medium text-highlighted">Powód usunięcia <span aria-hidden="true">*</span></label>
              <select id="del-reason" v-model="delReason" name="reason" required class="min-h-11 rounded-md border border-accented bg-default px-3"
                      :aria-invalid="!!delErr" :aria-describedby="delErr ? 'del-err' : 'del-help'">
                <option value="">Wybierz…</option>
                <option v-for="r in session.dict.value?.delete_reasons" :key="r.value" :value="r.value">{{ r.label }}</option>
              </select>
              <p id="del-help" class="text-sm text-muted">„Spam” zablokuje też nadawcę, żeby kartoteka nie wróciła z poczty.</p>
              <p v-if="delErr" id="del-err" class="text-sm text-error" role="alert">{{ delErr }}</p>
            </div>
            <UFormField label="Uwagi (opcjonalnie)" name="note"><UTextarea v-model="delNote" :rows="3" class="w-full" /></UFormField>
          </form>
        </template>
        <template #footer>
          <div class="flex w-full justify-end gap-2">
            <UButton label="Anuluj" color="neutral" variant="outline" @click="delOpen = false" />
            <UButton type="submit" form="del-form" label="Usuń kartotekę" color="error" icon="i-lucide-trash-2" :loading="delBusy" />
          </div>
        </template>
      </UModal>
    </template>
    <p v-else role="status" class="text-muted">Wczytywanie kartoteki…</p>
  </div>
</template>
