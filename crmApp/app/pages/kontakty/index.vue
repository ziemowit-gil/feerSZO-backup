<script setup lang="ts">
import type { ContactRow, Paged } from '~/utils/types'

const route = useRoute()
const router = useRouter()
const api = useApi()
const toast = useToast()
const session = useCrmSession()
const { dict } = session

// Filtry trzymamy w adresie (?q=…&status=…) — da się wrócić „wstecz”, odświeżyć i wysłać link.
const FILTERS = ['q', 'status', 'type', 'owner', 'tag', 'group', 'wojewodztwo', 'category', 'has_email', 'has_phone', 'critical'] as const
type FilterKey = typeof FILTERS[number]
const f = reactive<Record<FilterKey, string>>(Object.fromEntries(FILTERS.map(k => [k, String(route.query[k] ?? '')])) as Record<FilterKey, string>)
const page = computed(() => Math.max(1, Number(route.query.page || 1)))
const showFilters = ref(FILTERS.some(k => k !== 'q' && f[k]))

function apply(p = 1) {
  const query: Record<string, string> = {}
  for (const k of FILTERS) if (f[k]) query[k] = f[k]
  if (p > 1) query.page = String(p)
  router.replace({ query })
}
function reset() {
  for (const k of FILTERS) f[k] = ''
  apply()
}
const activeCount = computed(() => FILTERS.filter(k => k !== 'q' && f[k]).length)

const { data, error, status, refresh } = await useAsyncData('contacts',
  () => api.get<Paged<ContactRow>>('/contacts', { ...flatQuery(route.query), per_page: 25 }),
  { watch: [() => route.query] })

const rows = computed(() => data.value?.data ?? [])
const meta = computed(() => data.value?.meta)
const summary = computed(() => {
  const n = meta.value?.total ?? 0
  return `${n} ${plural(n, 'kontakt', 'kontakty', 'kontaktów')}`
})

// ── zaznaczanie i akcje masowe ─────────────────────────────────────────────
const selected = ref<Set<number>>(new Set())
watch(() => route.query, () => { selected.value = new Set() })
const allOnPage = computed(() => rows.value.length > 0 && rows.value.every(r => selected.value.has(r.id)))
function toggle(id: number, on: boolean) {
  const s = new Set(selected.value)
  if (on) s.add(id); else s.delete(id)
  selected.value = s
}
function toggleAll(on: boolean) {
  selected.value = on ? new Set(rows.value.map(r => r.id)) : new Set()
}

const bulkAction = ref('')
const bulkValue = ref('')
const bulkBusy = ref(false)
const deleteOpen = ref(false)
const deleteNote = ref('')
const bulkOptions = computed(() => {
  const o = [
    { value: 'set_status', label: 'Zmień status' },
    { value: 'set_owner', label: 'Przypisz opiekuna' },
    { value: 'add_tag', label: 'Dodaj tag' },
    { value: 'remove_tag', label: 'Usuń tag' },
  ]
  if (dict.value?.groups.length) o.push({ value: 'add_to_group', label: 'Dodaj do grupy' })
  if (session.can('contacts', 'delete')) o.push({ value: 'delete', label: 'Usuń kartoteki' })
  return o
})
const bulkValues = computed(() => {
  const d = dict.value
  if (!d) return []
  switch (bulkAction.value) {
    case 'set_status': return d.statuses.map(s => ({ value: s.value, label: s.label }))
    case 'set_owner': return [{ value: '0', label: '— bez opiekuna —' }, ...d.owners.map(o => ({ value: String(o.id), label: o.name }))]
    case 'add_to_group': return d.groups.map(g => ({ value: String(g.id), label: g.name }))
    case 'delete': return d.delete_reasons
    default: return []
  }
})
watch(bulkAction, () => { bulkValue.value = '' })

async function runBulk() {
  if (!bulkAction.value || !bulkValue.value) return
  if (bulkAction.value === 'delete' && !deleteOpen.value) { deleteOpen.value = true; return }
  bulkBusy.value = true
  try {
    const r = await api.post<{ data: { affected: number } }>('/contacts/bulk', {
      action: bulkAction.value, ids: [...selected.value], value: bulkValue.value, note: deleteNote.value,
    })
    toast.add({ title: 'Gotowe', description: `Zmieniono ${r.data.affected} ${plural(r.data.affected, 'kartotekę', 'kartoteki', 'kartotek')}.`, color: 'success', icon: 'i-lucide-check' })
    deleteOpen.value = false
    deleteNote.value = ''
    bulkAction.value = ''
    selected.value = new Set()
    await refresh()
  } catch (e) {
    toast.add({ title: 'Nie udało się', description: (e as Error).message, color: 'error', icon: 'i-lucide-circle-alert' })
  } finally {
    bulkBusy.value = false
  }
}

const opt = (list?: { value: string, label: string }[]) => list ?? []
</script>

<template>
  <div>
    <PageHeader title="Kontakty" description="Osoby i organizacje w kartotece CRM">
      <template #actions>
        <UButton v-if="session.can('contacts', 'write')" to="/kontakty/nowy" icon="i-lucide-user-plus" label="Nowy kontakt" />
      </template>
    </PageHeader>

    <form role="search" aria-label="Wyszukiwanie i filtry kontaktów" class="mb-4 rounded-xl border border-default bg-default p-4" @submit.prevent="apply()">
      <div class="flex flex-wrap items-end gap-3">
        <div class="flex min-w-60 flex-1 flex-col gap-1">
          <label for="f-q" class="text-sm font-medium text-highlighted">Szukaj</label>
          <UInput id="f-q" v-model="f.q" icon="i-lucide-search" placeholder="Imię, nazwisko, e-mail, telefon, organizacja" class="w-full" />
        </div>
        <UButton type="submit" label="Szukaj" icon="i-lucide-search" />
        <UButton color="neutral" variant="outline" :label="activeCount ? `Filtry (${activeCount})` : 'Filtry'" icon="i-lucide-sliders-horizontal"
                 :aria-expanded="showFilters" aria-controls="panel-filtrow" @click="showFilters = !showFilters" />
        <UButton v-if="activeCount || f.q" color="neutral" variant="ghost" label="Wyczyść" @click="reset" />
      </div>

      <div v-show="showFilters" id="panel-filtrow" class="mt-4 grid gap-4 border-t border-default pt-4 sm:grid-cols-2 lg:grid-cols-4">
        <NativeSelect id="f-status" v-model="f.status" label="Status" placeholder="Wszystkie" :options="opt(dict?.statuses)" />
        <NativeSelect id="f-type" v-model="f.type" label="Typ" placeholder="Wszystkie" :options="opt(dict?.types)" />
        <NativeSelect id="f-owner" v-model="f.owner" label="Opiekun" placeholder="Każdy"
                      :options="[{ value: 'none', label: '— bez opiekuna —' }, ...(dict?.owners ?? []).map(o => ({ value: String(o.id), label: o.name }))]" />
        <NativeSelect id="f-tag" v-model="f.tag" label="Tag" placeholder="Dowolny" :options="(dict?.tags ?? []).map(t => ({ value: t.tag, label: `${t.tag} (${t.count})` }))" />
        <NativeSelect v-if="dict?.groups.length" id="f-group" v-model="f.group" label="Grupa" placeholder="Wszystkie" :options="(dict?.groups ?? []).map(g => ({ value: String(g.id), label: g.name }))" />
        <NativeSelect v-if="dict?.categories.length" id="f-category" v-model="f.category" label="Kategoria" placeholder="Wszystkie" :options="opt(dict?.categories)" />
        <NativeSelect id="f-woj" v-model="f.wojewodztwo" label="Województwo" placeholder="Wszystkie" :options="(dict?.wojewodztwa ?? []).map(w => ({ value: w, label: w }))" />
        <fieldset class="flex flex-col gap-2">
          <legend class="mb-1 text-sm font-medium text-highlighted">Tylko z</legend>
          <UCheckbox :model-value="f.has_email === '1'" label="adresem e-mail" @update:model-value="v => f.has_email = v ? '1' : ''" />
          <UCheckbox :model-value="f.has_phone === '1'" label="telefonem" @update:model-value="v => f.has_phone = v ? '1' : ''" />
          <UCheckbox :model-value="f.critical === '1'" label="oznaczeniem „krytyczny”" @update:model-value="v => f.critical = v ? '1' : ''" />
        </fieldset>
        <div class="sm:col-span-2 lg:col-span-4">
          <UButton type="submit" label="Pokaż wyniki" icon="i-lucide-filter" />
        </div>
      </div>
    </form>

    <p class="mb-3 text-muted" role="status" aria-live="polite">
      <template v-if="status === 'pending'">Wyszukiwanie…</template>
      <template v-else-if="meta">Znaleziono {{ summary }}</template>
    </p>

    <ErrorState v-if="error" :message="error.message" @retry="refresh()" />

    <section v-if="selected.size && session.can('contacts', 'write')" aria-label="Akcje dla zaznaczonych"
             class="sticky top-18 z-30 mb-3 flex flex-wrap items-end gap-3 rounded-xl border border-primary/40 bg-primary/5 p-3">
      <p class="self-center font-medium">Zaznaczono: {{ selected.size }}</p>
      <NativeSelect id="bulk-action" v-model="bulkAction" label="Akcja" placeholder="Wybierz…" :options="bulkOptions" class="min-w-48" />
      <NativeSelect v-if="bulkValues.length" id="bulk-value" v-model="bulkValue" :label="bulkAction === 'delete' ? 'Powód' : 'Wartość'" placeholder="Wybierz…" :options="bulkValues" class="min-w-48" />
      <div v-else-if="bulkAction === 'add_tag' || bulkAction === 'remove_tag'" class="flex flex-col gap-1">
        <label for="bulk-tag" class="text-sm font-medium text-highlighted">Tag</label>
        <UInput id="bulk-tag" v-model="bulkValue" placeholder="np. wolontariusz" />
      </div>
      <UButton label="Wykonaj" :loading="bulkBusy" :disabled="!bulkAction || !bulkValue" :color="bulkAction === 'delete' ? 'error' : 'primary'" @click="runBulk" />
      <UButton label="Odznacz" color="neutral" variant="ghost" @click="toggleAll(false)" />
    </section>

    <div v-if="rows.length" class="overflow-x-auto rounded-xl border border-default bg-default">
      <table class="w-full min-w-[44rem] text-left">
        <caption class="sr-only">Lista kontaktów, {{ summary }}, strona {{ page }}</caption>
        <thead class="border-b border-default bg-muted text-sm text-toned">
          <tr>
            <th scope="col" class="w-12 px-3 py-3">
              <UCheckbox :model-value="allOnPage" aria-label="Zaznacz wszystkie na tej stronie" @update:model-value="v => toggleAll(!!v)" />
            </th>
            <th scope="col" class="px-3 py-3 font-semibold">Nazwa</th>
            <th scope="col" class="px-3 py-3 font-semibold">Status</th>
            <th scope="col" class="px-3 py-3 font-semibold">Kontakt</th>
            <th scope="col" class="hidden px-3 py-3 font-semibold lg:table-cell">Opiekun</th>
            <th scope="col" class="hidden px-3 py-3 font-semibold md:table-cell">Ostatni kontakt</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-default">
          <tr v-for="c in rows" :key="c.id" :class="selected.has(c.id) ? 'bg-primary/5' : 'hover:bg-muted'">
            <td class="px-3 py-3 align-top">
              <UCheckbox :model-value="selected.has(c.id)" :aria-label="`Zaznacz: ${c.imie_nazwisko || 'kontakt ' + c.id}`" @update:model-value="v => toggle(c.id, !!v)" />
            </td>
            <td class="px-3 py-3 align-top">
              <div class="flex items-start gap-3">
                <UAvatar :text="c.avatar_initials || initials(c.imie_nazwisko)" size="md" aria-hidden="true" class="hidden sm:inline-flex" />
                <div class="min-w-0">
                  <NuxtLink :to="`/kontakty/${c.id}`" class="font-medium text-highlighted underline-offset-2 hover:underline">{{ c.imie_nazwisko || 'Bez nazwy' }}</NuxtLink>
                  <UBadge v-if="c.is_critical" color="warning" variant="subtle" label="Krytyczny" class="ml-2" />
                  <p class="text-sm text-muted">
                    {{ session.typeOf(c.type)?.label }}<template v-if="c.organizacja || c.stanowisko"> · {{ [c.stanowisko, c.organizacja].filter(Boolean).join(', ') }}</template>
                  </p>
                  <ul v-if="c.tags.length" class="mt-1 flex flex-wrap gap-1" aria-label="Tagi">
                    <li v-for="t in c.tags.slice(0, 4)" :key="t"><UBadge color="neutral" variant="outline" :label="t" size="sm" /></li>
                  </ul>
                </div>
              </div>
            </td>
            <td class="px-3 py-3 align-top">
              <StatusChip v-if="c.status" :label="session.statusOf(c.status)?.label || c.status" :color="session.statusOf(c.status)?.color" />
            </td>
            <td class="px-3 py-3 align-top text-sm">
              <a v-if="c.email" :href="`mailto:${c.email}`" class="block break-all text-primary underline-offset-2 hover:underline">{{ c.email }}</a>
              <a v-if="c.telefon" :href="`tel:${c.telefon.replace(/\s/g, '')}`" class="block text-primary underline-offset-2 hover:underline">{{ c.telefon }}</a>
              <span v-if="!c.email && !c.telefon" class="text-muted">—</span>
            </td>
            <td class="hidden px-3 py-3 align-top text-sm lg:table-cell">{{ session.ownerName(c.owner_id) || '—' }}</td>
            <td class="hidden px-3 py-3 align-top text-sm md:table-cell">{{ fmtRelative(c.last_comm_at) }}</td>
          </tr>
        </tbody>
      </table>
    </div>
    <EmptyState v-else-if="status !== 'pending' && !error" title="Brak kontaktów" description="Zmień wyszukiwane słowa albo wyczyść filtry." icon="i-lucide-search-x">
      <UButton v-if="activeCount || f.q" label="Wyczyść filtry" color="neutral" variant="outline" @click="reset" />
    </EmptyState>

    <Pager v-if="meta" :page="page" :pages="meta.pages" :total="meta.total" label="Strony listy kontaktów" @update:page="apply" />

    <UModal v-model:open="deleteOpen" title="Usunąć zaznaczone kartoteki?" :description="`Usuniesz ${selected.size} ${plural(selected.size, 'kartotekę', 'kartoteki', 'kartotek')}. Powód trafi do historii.`">
      <template #body>
        <div class="space-y-4">
          <p>Powód: <strong>{{ dict?.delete_reasons.find(r => r.value === bulkValue)?.label }}</strong></p>
          <UFormField label="Uwagi (opcjonalnie)" name="delete_note">
            <UTextarea v-model="deleteNote" :rows="3" class="w-full" />
          </UFormField>
        </div>
      </template>
      <template #footer>
        <div class="flex w-full justify-end gap-2">
          <UButton label="Anuluj" color="neutral" variant="outline" @click="deleteOpen = false" />
          <UButton label="Usuń kartoteki" color="error" icon="i-lucide-trash-2" :loading="bulkBusy" @click="runBulk" />
        </div>
      </template>
    </UModal>
  </div>
</template>
