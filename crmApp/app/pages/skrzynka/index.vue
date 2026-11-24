<script setup lang="ts">
import type { InboxMeta, InboxRow, Paged } from '~/utils/types'

const route = useRoute()
const router = useRouter()
const api = useApi()
const toast = useToast()

// Widok, skrzynka, kolejka, tag i filtry w adresie (?view=new&mailbox_id=…) — jak w klasycznej Skrzynce.
const KEYS = ['view', 'mailbox_id', 'queue_id', 'tag', 'q', 'unread', 'att', 'threads'] as const
const f = reactive<Record<string, string>>(Object.fromEntries(KEYS.map(k => [k, String(route.query[k] ?? '')])))
const view = computed(() => String(route.query.view || 'new'))
const page = computed(() => Math.max(1, Number(route.query.page || 1)))
function apply(p = 1, patch: Record<string, string> = {}) {
  Object.assign(f, patch)
  const query: Record<string, string> = {}
  for (const k of KEYS) if (f[k]) query[k] = f[k]
  if (p > 1) query.page = String(p)
  router.replace({ query })
}

const { data: meta, refresh: refreshMeta } = await useAsyncData('inbox-meta',
  () => api.get<{ data: InboxMeta }>('/inbox/meta', { view: view.value, mailbox_id: f.mailbox_id }).then(r => r.data),
  { watch: [view, () => route.query.mailbox_id] })
const { data, error, status, refresh } = await useAsyncData('inbox',
  () => api.get<Paged<InboxRow> & { meta: { grouped: boolean } }>('/inbox', flatQuery(route.query)),
  { watch: [() => route.query] })
const rows = computed(() => data.value?.data ?? [])
const total = computed(() => data.value?.meta.total ?? 0)
const currentView = computed(() => meta.value?.views.find(v => v.value === view.value))
const canWrite = computed(() => !!meta.value?.can_write)
const threadsOn = computed(() => f.threads ? f.threads === '1' : (meta.value?.threads_default ?? true))
useHead({ title: () => `Skrzynka — ${currentView.value?.label ?? ''}` })

// ── zaznaczanie i akcje masowe ─────────────────────────────────────────────
const selected = ref<Set<number>>(new Set())
watch(() => route.query, () => { selected.value = new Set() })
const allOn = computed(() => rows.value.length > 0 && rows.value.every(r => selected.value.has(r.id)))
const toggle = (id: number, on: boolean) => { const s = new Set(selected.value); if (on) s.add(id); else s.delete(id); selected.value = s }
const toggleAll = (on: boolean) => { selected.value = on ? new Set(rows.value.map(r => r.id)) : new Set() }
const bulkOp = ref('')
const bulkValue = ref('')
const busy = ref(false)
const bulkOps = computed(() => [
  { value: 'read', label: 'Oznacz jako przeczytane' }, { value: 'unread', label: 'Oznacz jako nieprzeczytane' },
  { value: 'archive', label: 'Przenieś do załatwionych' }, { value: 'hide', label: 'Ukryj w CRM' }, { value: 'unhide', label: 'Przywróć widoczność' },
  ...(meta.value?.queues.length ? [{ value: 'queue', label: 'Przenieś do kolejki' }] : []), { value: 'tag', label: 'Dodaj tag' },
])
async function runBulk() {
  if (!bulkOp.value || ((bulkOp.value === 'queue' || bulkOp.value === 'tag') && !bulkValue.value)) return
  busy.value = true
  try {
    const r = await api.post<{ data: { affected: number } }>('/inbox/bulk', { op: bulkOp.value, ids: [...selected.value], value: bulkValue.value })
    toast.add({ title: 'Gotowe', description: `Zmieniono ${r.data.affected} ${plural(r.data.affected, 'wiadomość', 'wiadomości', 'wiadomości')}.`, color: 'success', icon: 'i-lucide-check' })
    bulkOp.value = ''; bulkValue.value = ''
    await Promise.all([refresh(), refreshMeta()])
  } catch (e) {
    toast.add({ title: 'Nie udało się', description: (e as Error).message, color: 'error' })
  } finally { busy.value = false }
}
async function markAllRead() {
  if (!window.confirm(`Oznaczyć wszystkie wiadomości w widoku „${currentView.value?.label}” jako przeczytane?`)) return
  try {
    const r = await api.post<{ data: { affected: number } }>('/inbox/mark-all-read', { view: view.value, mailbox_id: Number(f.mailbox_id || 0) })
    toast.add({ title: `Oznaczono ${r.data.affected} jako przeczytane`, color: 'success', icon: 'i-lucide-check' })
    await Promise.all([refresh(), refreshMeta()])
  } catch (e) { toast.add({ title: 'Nie udało się', description: (e as Error).message, color: 'error' }) }
}

// ── odświeżanie co 30 s (gdy karta jest widoczna) — nowe wiadomości ogłaszamy, lista nie skacze sama ─────
const newArrived = ref(false)
let timer: ReturnType<typeof setInterval> | undefined
onMounted(() => {
  timer = setInterval(async () => {
    if (document.hidden || newArrived.value) return
    try {
      const p = await api.get<{ data: { max_id: number } }>('/inbox/poll', flatQuery(route.query))
      const top = Math.max(0, ...rows.value.map(r => r.id))
      if (p.data.max_id > top) newArrived.value = true
    } catch { /* cicho — spróbujemy za 30 s */ }
  }, 30000)
})
onBeforeUnmount(() => clearInterval(timer))
async function showNew() { newArrived.value = false; await Promise.all([refresh(), refreshMeta()]) }

const sender = (r: InboxRow) => r.direction === 'out' ? `Do: ${r.contact_name || '—'}` : (r.from_name || r.from_email || r.contact_name || 'Nieznany nadawca')
</script>

<template>
  <div>
    <PageHeader title="Skrzynka" :description="currentView ? `${currentView.label} · ${total} ${plural(total, 'wiadomość', 'wiadomości', 'wiadomości')}` : undefined">
      <template #actions>
        <UButton v-if="canWrite && (view === 'new' || view === 'all' || view === 'mine')" label="Oznacz wszystkie jako przeczytane" icon="i-lucide-check-check" color="neutral" variant="outline" @click="markAllRead" />
      </template>
    </PageHeader>

    <!-- Widoki skrzynki: linki z liczbą, bieżący oznaczony aria-current -->
    <nav aria-label="Widoki skrzynki" class="mb-4 overflow-x-auto">
      <ul class="flex min-w-max gap-1 border-b border-default">
        <li v-for="v in meta?.views" :key="v.value">
          <NuxtLink :to="{ query: { ...flatQuery(route.query), view: v.value, page: undefined } }" :aria-current="v.value === view ? 'page' : undefined"
                    class="inline-flex min-h-11 items-center gap-2 border-b-2 px-3 font-medium"
                    :class="v.value === view ? 'border-primary text-primary' : 'border-transparent text-toned hover:text-highlighted'">
            {{ v.label }}
            <span v-if="v.count" class="rounded-full bg-elevated px-2 text-sm tabular-nums text-highlighted">{{ v.count }}<span class="sr-only"> wiadomości</span></span>
          </NuxtLink>
        </li>
      </ul>
    </nav>

    <form role="search" aria-label="Filtry skrzynki" class="mb-4 grid gap-4 rounded-xl border border-default bg-default p-4 sm:grid-cols-2 lg:grid-cols-4" @submit.prevent="apply()">
      <div class="flex flex-col gap-1 sm:col-span-2">
        <label for="ib-q" class="text-sm font-medium text-highlighted">Szukaj</label>
        <UInput id="ib-q" v-model="f.q" icon="i-lucide-search" placeholder="Temat, nadawca, kontakt, numer" class="w-full" />
      </div>
      <NativeSelect v-if="(meta?.mailboxes.length ?? 0) > 1" id="ib-mb" v-model="f.mailbox_id" label="Skrzynka pocztowa" placeholder="Wszystkie moje"
                    :options="(meta?.mailboxes ?? []).map(m => ({ value: String(m.id), label: `${m.name} (${m.count})` }))" />
      <NativeSelect v-if="meta?.queues.length" id="ib-queue" v-model="f.queue_id" label="Kolejka" placeholder="Wszystkie"
                    :options="(meta?.queues ?? []).map(q => ({ value: String(q.id), label: `${q.name} (${q.count})` }))" />
      <NativeSelect v-if="meta?.tags.length" id="ib-tag" v-model="f.tag" label="Tag" placeholder="Dowolny" :options="(meta?.tags ?? []).map(t => ({ value: t, label: t }))" />
      <fieldset class="flex flex-col gap-2">
        <legend class="mb-1 text-sm font-medium text-highlighted">Pokaż</legend>
        <UCheckbox :model-value="f.unread === '1'" label="tylko nieprzeczytane" @update:model-value="v => f.unread = v ? '1' : ''" />
        <UCheckbox :model-value="f.att === '1'" label="tylko z załącznikami" @update:model-value="v => f.att = v ? '1' : ''" />
        <UCheckbox :model-value="threadsOn" label="grupuj w wątki" @update:model-value="v => f.threads = v ? '1' : '0'" />
      </fieldset>
      <div class="flex items-end gap-2">
        <UButton type="submit" label="Pokaż" icon="i-lucide-filter" />
        <UButton color="neutral" variant="ghost" label="Wyczyść" @click="apply(1, { mailbox_id: '', queue_id: '', tag: '', q: '', unread: '', att: '' })" />
      </div>
    </form>

    <div aria-live="polite" class="mb-3">
      <p v-if="status === 'pending'" class="text-muted">Wczytywanie…</p>
      <UAlert v-else-if="newArrived" color="info" variant="subtle" icon="i-lucide-mail-plus" title="Przyszły nowe wiadomości">
        <template #actions><UButton label="Pokaż" color="info" variant="outline" @click="showNew" /></template>
      </UAlert>
    </div>
    <ErrorState v-if="error" :message="error.message" @retry="refresh()" />

    <section v-if="selected.size && canWrite" aria-label="Akcje dla zaznaczonych wiadomości"
             class="sticky top-18 z-30 mb-3 flex flex-wrap items-end gap-3 rounded-xl border border-primary/40 bg-primary/5 p-3">
      <p class="self-center font-medium">Zaznaczono: {{ selected.size }}</p>
      <NativeSelect id="ib-bulk" v-model="bulkOp" label="Akcja" placeholder="Wybierz…" :options="bulkOps" class="min-w-56" />
      <NativeSelect v-if="bulkOp === 'queue'" id="ib-bulk-q" v-model="bulkValue" label="Kolejka" placeholder="Wybierz…"
                    :options="(meta?.queues ?? []).map(q => ({ value: String(q.id), label: q.name }))" />
      <div v-if="bulkOp === 'tag'" class="flex flex-col gap-1">
        <label for="ib-bulk-tag" class="text-sm font-medium text-highlighted">Tag</label>
        <UInput id="ib-bulk-tag" v-model="bulkValue" placeholder="np. faktury" />
      </div>
      <UButton label="Wykonaj" :loading="busy" :disabled="!bulkOp" @click="runBulk" />
      <UButton label="Odznacz" color="neutral" variant="ghost" @click="toggleAll(false)" />
    </section>

    <div v-if="rows.length" class="rounded-xl border border-default bg-default">
      <div v-if="canWrite" class="flex items-center gap-3 border-b border-default px-4 py-2">
        <UCheckbox :model-value="allOn" label="Zaznacz wszystkie na tej stronie" @update:model-value="v => toggleAll(!!v)" />
      </div>
      <ol class="divide-y divide-default" :aria-label="`Wiadomości: ${currentView?.label ?? ''}`">
        <li v-for="r in rows" :key="r.id" class="flex gap-3 px-4 py-3" :class="[selected.has(r.id) ? 'bg-primary/5' : 'hover:bg-muted', !r.is_read && 'border-l-4 border-l-primary']">
          <UCheckbox v-if="canWrite" class="pt-1" :model-value="selected.has(r.id)" :aria-label="`Zaznacz: ${r.subject || 'bez tematu'} od ${sender(r)}`" @update:model-value="v => toggle(r.id, !!v)" />
          <div class="min-w-0 flex-1">
            <p class="flex flex-wrap items-baseline gap-x-3">
              <span class="truncate" :class="r.is_read ? 'text-toned' : 'font-semibold text-highlighted'">{{ sender(r) }}</span>
              <span class="ml-auto shrink-0 text-sm text-muted">{{ fmtRelative(r.sent_at) }}</span>
            </p>
            <NuxtLink :to="`/skrzynka/${r.id}`" class="block truncate underline-offset-2 hover:underline" :class="r.is_read ? 'text-default' : 'font-semibold text-highlighted'">
              <span v-if="!r.is_read" class="sr-only">Nieprzeczytana: </span>{{ r.subject || '(bez tematu)' }}
              <span v-if="r.thread_count > 1" class="ml-1 text-sm font-normal text-muted">({{ r.thread_count }} w wątku)</span>
            </NuxtLink>
            <p class="truncate text-sm text-muted">{{ r.snippet }}</p>
            <p class="mt-1 flex flex-wrap items-center gap-2 text-sm">
              <UBadge v-if="r.has_attachments" color="neutral" variant="outline" icon="i-lucide-paperclip" label="Załącznik" size="sm" />
              <UBadge v-if="r.contact_name && r.direction === 'in'" color="neutral" variant="outline" icon="i-lucide-user" :label="r.contact_name" size="sm" />
              <UBadge v-if="r.assigned_name" color="primary" variant="subtle" :label="`Prowadzi: ${r.assigned_name}`" size="sm" />
              <StatusChip v-if="r.queue_name" :label="r.queue_name" :color="r.queue_color" class="text-xs" />
              <UBadge v-for="t in r.tags" :key="t" color="neutral" variant="soft" :label="`#${t}`" size="sm" />
              <span v-if="r.mailbox_name && (meta?.mailboxes.length ?? 0) > 1" class="text-muted">{{ r.mailbox_name }}</span>
            </p>
          </div>
        </li>
      </ol>
    </div>
    <EmptyState v-else-if="status !== 'pending' && !error" title="Pusto" :description="view === 'new' ? 'Wszystko przeczytane. Nowe wiadomości pojawią się tutaj.' : 'W tym widoku nie ma wiadomości.'" icon="i-lucide-inbox" />

    <Pager v-if="data" :page="page" :pages="data.meta.pages" :total="total" label="Strony skrzynki" @update:page="p => apply(p)" />
  </div>
</template>
