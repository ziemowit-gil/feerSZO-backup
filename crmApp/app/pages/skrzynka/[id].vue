<script setup lang="ts">
import type { ApiError } from '~/composables/useApi'
import type { InboxAssist, InboxMessage, InboxMeta } from '~/utils/types'

const route = useRoute()
const api = useApi()
const toast = useToast()
const session = useCrmSession()
const id = computed(() => Number(route.params.id))

const { data: meta } = await useAsyncData('inbox-meta-detail', () => api.get<{ data: InboxMeta }>('/inbox/meta').then(r => r.data))
// Tryb rozmowy (cały wątek jedną kolumną) — preferencja w adresie ?conv=1
const conv = computed({
  get: () => route.query.conv === '1',
  set: v => navigateTo({ query: { ...route.query, conv: v ? '1' : undefined } }, { replace: true }),
})
const { data, error, refresh } = await useAsyncData(() => `inbox-msg-${id.value}-${conv.value}`,
  () => api.get<{ data: InboxMessage, legacy_url: string }>(`/inbox/${id.value}`, { conv: conv.value }), { watch: [id, conv], deep: true })
const m = computed(() => data.value?.data)
const canWrite = computed(() => !!meta.value?.can_write)
useHead({ title: () => m.value?.subject || 'Wiadomość' })

// Otwarcie = przeczytana (jak w klasycznej Skrzynce), ale jawną akcją API.
watch(m, async (v) => {
  if (v && !v.is_read && canWrite.value) {
    try {
      await api.post(`/inbox/${v.id}/action`, { op: 'read' })
      v.is_read = true
      const b = session.me.value?.badges
      if (b && b.inbox_unread > 0) b.inbox_unread--
    } catch { /* bez znaczenia dla podglądu */ }
  }
}, { immediate: true })

// ── treść w ramce: zabezpieczony dokument z SZO (bez skryptów, CSP); obrazy z sieci dopiero na żądanie ───────
const showImages = ref(false)
watch(id, () => { showImages.value = false })
const frameDoc = computed(() => (m.value?.frame ?? '').replace(/CRM_IMG_SRC/g, showImages.value ? 'https: http:' : ''))
const frame = ref<HTMLIFrameElement | null>(null)
function fitFrame() {
  const el = frame.value
  const doc = el?.contentDocument
  if (!el || !doc?.body) return
  el.style.height = '0px'   // najpierw zwijamy, żeby scrollHeight mówił o treści, nie o ramce
  el.style.height = `${Math.min(Math.max(doc.documentElement.scrollHeight, doc.body.scrollHeight) + 8, 4000)}px`
}

// ── akcje ──────────────────────────────────────────────────────────────────
const busy = ref<string | null>(null)
async function act(op: string, body: Record<string, unknown> = {}, opts: { back?: boolean } = {}) {
  busy.value = op
  try {
    const r = await api.post<{ data: { message: string, case_id?: number, contact_id?: number } }>(`/inbox/${id.value}/action`, { op, ...body })
    toast.add({ title: r.data.message, color: 'success', icon: 'i-lucide-check' })
    if (opts.back) await navigateTo({ path: '/skrzynka', query: route.query })
    else await refresh()
    return r.data
  } catch (e) {
    toast.add({ title: 'Nie udało się', description: (e as Error).message, color: 'error', icon: 'i-lucide-circle-alert' })
  } finally { busy.value = null }
}
const assignTo = ref('')
const queueTo = ref('')
const attachCase = ref('')
watch(m, (v) => {
  assignTo.value = v?.assigned_to ? String(v.assigned_to) : ''
  queueTo.value = v?.queue ? String(v.queue.id) : ''
  attachCase.value = v?.case_id ? String(v.case_id) : ''
}, { immediate: true })
const newTag = ref('')
async function addTag() { if (newTag.value.trim()) { await act('tag_add', { tag: newTag.value }); newTag.value = '' } }

// Przekaż dalej
const fwdOpen = ref(false)
const fwd = reactive({ to: '', note: '' })
const fwdErr = ref('')
async function forward() {
  fwdErr.value = ''
  if (!/^\S+@\S+\.\S+$/.test(fwd.to)) { fwdErr.value = 'Wpisz adres w formacie nazwa@domena.pl.'; return }
  const r = await act('forward', { ...fwd })
  if (r) { fwdOpen.value = false; fwd.to = ''; fwd.note = '' }
}

// Nowa sprawa z wiadomości
const caseOpen = ref(false)
const cs = reactive({ title: '', priority: 'medium', owner_id: '', keep_open: false })
watch(caseOpen, (o) => { if (o) { cs.title = m.value?.subject || ''; cs.owner_id = String(session.me.value?.user.id ?? '') } })
async function createCase() {
  const r = await act('case', { ...cs, owner_id: Number(cs.owner_id || 0) })
  if (r?.case_id) { caseOpen.value = false; await navigateTo(`/sprawy/${r.case_id}`) }
}

// Odpowiedź
const replyOpen = ref(false)
const reply = reactive({ subject: '', body: '', send_as: '', template: '' })
const replyErr = ref<Record<string, string>>({})
const replyBusy = ref(false)
watch(replyOpen, async (o) => {
  if (!o) return
  reply.subject = m.value?.subject?.startsWith('Re:') ? m.value.subject : `Re: ${m.value?.subject ?? ''}`
  reply.send_as = meta.value?.send_as_default || 'system'
  await nextTick()
  document.querySelector<HTMLElement>('textarea[name="reply_body"]')?.focus()
})
watch(() => reply.template, (tid) => {
  const t = meta.value?.templates.find(x => String(x.id) === tid)
  if (!t) return
  if (t.subject) reply.subject = t.subject
  reply.body = t.body.replace(/<br\s*\/?>/gi, '\n').replace(/<\/p>/gi, '\n\n').replace(/<[^>]+>/g, '').trim()
})
async function sendReply() {
  replyErr.value = {}
  if (!reply.subject.trim()) replyErr.value.subject = 'Wpisz temat.'
  if (!reply.body.trim()) replyErr.value.body = 'Wpisz treść odpowiedzi.'
  if (Object.keys(replyErr.value).length) return
  replyBusy.value = true
  try {
    const tpl = meta.value?.templates.find(x => String(x.id) === reply.template)
    const fd = new FormData()
    fd.append('subject', reply.subject)
    fd.append('body', reply.body)
    fd.append('send_as', reply.send_as)
    fd.append('template_name', tpl?.name ?? '')
    fd.append('template_id', tpl ? String(tpl.id) : '0')
    for (const file of replyFiles.value) fd.append('files[]', file, file.name)
    const r = await api.post<{ data: { message: string } }>(`/inbox/${id.value}/reply`, fd)
    replyFiles.value = []
    toast.add({ title: r.data.message, color: 'success', icon: 'i-lucide-send' })
    replyOpen.value = false
    reply.body = ''; reply.template = ''
    await refresh()
  } catch (e) {
    replyErr.value = (e as ApiError).fields ?? {}
    if (!Object.keys(replyErr.value).length) toast.add({ title: 'Nie wysłano', description: (e as Error).message, color: 'error' })
  } finally { replyBusy.value = false }
}

// Obserwujący
const followerTo = ref('')
async function addFollower() { if (followerTo.value) { await act('fol_add', { user_id: Number(followerTo.value) }); followerTo.value = '' } }

// Nozbe i EZD — po sukcesie link do utworzonego zadania / sprawy
const lastUrl = ref<string | null>(null)
async function toNozbe() { const r = await act('nozbe'); lastUrl.value = (r as { url?: string } | undefined)?.url ?? null }
const ezdOpen = ref(false)
const ezdOpts = ref<{ teczki: { id: number, label: string }[], sprawy: { id: number, label: string }[], roles: string[] } | null>(null)
const ezd = reactive({ mode: 'new', sprawa_id: '', teczka_id: '', strona_name: '', strona_rola: '', q: '' })
const ezdErr = ref('')
watch(ezdOpen, async (o) => {
  if (!o) return
  ezd.strona_name = m.value?.contact_name || m.value?.from_name || ''
  ezdOpts.value = (await api.get<{ data: NonNullable<typeof ezdOpts.value> }>('/inbox/ezd-options')).data
})
async function ezdSearch() { ezdOpts.value = (await api.get<{ data: NonNullable<typeof ezdOpts.value> }>('/inbox/ezd-options', { q: ezd.q })).data }
async function toEzd() {
  ezdErr.value = ''
  const body = ezd.mode === 'new' ? { teczka_id: Number(ezd.teczka_id || 0) } : { sprawa_id: Number(ezd.sprawa_id || 0) }
  if (!Object.values(body)[0]) { ezdErr.value = ezd.mode === 'new' ? 'Wybierz teczkę dla nowej sprawy.' : 'Wybierz sprawę.'; return }
  const r = await act('ezd', { ...body, strona_name: ezd.strona_name, strona_crm_id: m.value?.contact_id ?? 0, strona_rola: ezd.strona_rola })
  if (r) { ezdOpen.value = false; lastUrl.value = (r as { url?: string }).url ?? null }
}

// Asystent AI
const assist = ref<InboxAssist | null>(null)
const assistBusy = ref(false)
async function runAssist() {
  assistBusy.value = true
  try { assist.value = (await api.post<{ data: InboxAssist }>(`/inbox/${id.value}/assist`)).data } catch (e) {
    toast.add({ title: 'Asystent niedostępny', description: (e as Error).message, color: 'error' })
  } finally { assistBusy.value = false }
}
function useDraft() {
  if (!assist.value) return
  reply.body = assist.value.reply
  replyOpen.value = true
}
watch(id, () => { assist.value = null; lastUrl.value = null })

// Załączniki odpowiedzi
const replyFiles = ref<File[]>([])
function onFiles(e: Event) { replyFiles.value = Array.from((e.target as HTMLInputElement).files ?? []) }

async function download(a: { id: number, name: string }) {
  try { await downloadFromApi(`/inbox/attachments/${a.id}`, a.name) } catch (e) {
    toast.add({ title: 'Nie pobrano załącznika', description: (e as Error).message, color: 'error' })
  }
}
const fmtSize = (b: number) => b >= 1048576 ? `${(b / 1048576).toFixed(1).replace('.', ',')} MB` : b >= 1024 ? `${Math.round(b / 1024)} kB` : `${b} B`
const STATUS: Record<string, string> = { active: 'W skrzynce', archived: 'Załatwiona', spam: 'Spam', deleted: 'Usunięta' }
</script>

<template>
  <div>
    <UButton :to="{ path: '/skrzynka', query: route.query }" variant="link" color="neutral" icon="i-lucide-arrow-left" label="Skrzynka" class="mb-2 -ml-2" />
    <ErrorState v-if="error" :message="error.message" @retry="refresh()" />
    <template v-else-if="m && data">
      <PageHeader :title="m.subject || '(bez tematu)'">
        <template #actions>
          <UButton v-if="canWrite && m.contact_id && m.direction === 'in'" icon="i-lucide-reply" label="Odpowiedz" @click="replyOpen = true" />
          <UButton v-if="canWrite" icon="i-lucide-forward" label="Przekaż" color="neutral" variant="outline" @click="fwdOpen = true" />
          <UButton v-if="canWrite && meta?.assist_available" icon="i-lucide-sparkles" label="Asystent AI" color="neutral" variant="outline" :loading="assistBusy" @click="runAssist" />
          <UDropdownMenu :items="[[
            ...(canWrite && meta?.ezd_available ? [{ label: 'Zarejestruj w EZD…', icon: 'i-lucide-book-marked', onSelect: () => { ezdOpen = true } }] : []),
            ...(canWrite && meta?.nozbe_available ? [{ label: 'Zadanie w Nozbe', icon: 'i-lucide-list-todo', onSelect: () => toNozbe() }] : []),
            { label: 'Zadanie w SZO', icon: 'i-lucide-square-check', href: `${session.me.value?.szo_url}/tasks/router.php?_route=/nowe&comm=${m.id}&src=crm` },
            { label: 'Otwórz w klasycznym CRM', icon: 'i-lucide-external-link', href: data.legacy_url },
          ]]">
            <UButton icon="i-lucide-ellipsis" color="neutral" variant="outline" aria-label="Więcej akcji" />
          </UDropdownMenu>
        </template>
      </PageHeader>

      <div class="grid gap-6 xl:grid-cols-[1fr_20rem]">
        <div class="min-w-0 space-y-4">
          <!-- nagłówek wiadomości -->
          <UCard>
            <dl class="grid gap-x-6 gap-y-2 sm:grid-cols-[8rem_1fr]">
              <dt class="text-muted">{{ m.direction === 'in' ? 'Od' : 'Do' }}</dt>
              <dd class="break-words">
                <template v-if="m.direction === 'in'"><strong>{{ m.from_name || m.from_email }}</strong><span v-if="m.from_name" class="text-muted"> &lt;{{ m.from_email }}&gt;</span></template>
                <template v-else>{{ m.contact_name }}</template>
              </dd>
              <dt class="text-muted">Data</dt><dd>{{ fmtDateTime(m.sent_at) }}</dd>
              <template v-if="m.mailbox_name"><dt class="text-muted">Skrzynka</dt><dd>{{ m.mailbox_name }}</dd></template>
              <dt class="text-muted">Stan</dt>
              <dd class="flex flex-wrap items-center gap-2">
                <UBadge :label="STATUS[m.status || 'active'] || m.status || ''" color="neutral" variant="outline" />
                <UBadge v-if="m.hidden" label="Ukryta w CRM" color="warning" variant="subtle" />
                <span v-if="m.msg_no" class="text-sm text-muted">Nr {{ m.msg_no }}</span>
                <UBadge v-if="m.trust" color="success" variant="subtle" icon="i-lucide-shield-check" :label="m.trust.label" :title="m.trust.title" />
                <a v-if="m.ezd_sprawa_id" :href="`${session.me.value?.szo_url}/ezd/sprawy/view.php?id=${m.ezd_sprawa_id}`" class="text-sm text-primary underline">Sprawa w EZD</a>
              </dd>
            </dl>
          </UCard>

          <UAlert v-if="lastUrl" color="success" variant="subtle" icon="i-lucide-check" title="Gotowe">
            <template #actions><UButton :href="lastUrl" target="_blank" label="Otwórz" color="success" variant="outline" trailing-icon="i-lucide-external-link" /></template>
          </UAlert>

          <UCard v-if="assist" as="section" aria-labelledby="h-ai">
            <template #header>
              <div class="flex flex-wrap items-center gap-2">
                <h2 id="h-ai" class="font-semibold text-highlighted">Asystent AI</h2>
                <UBadge :label="assist.intent_label" color="neutral" variant="outline" />
                <UBadge v-if="assist.sensitive" label="Dane wrażliwe" color="warning" variant="subtle" />
              </div>
            </template>
            <div class="space-y-3">
              <p>{{ assist.summary }}</p>
              <UAlert v-if="assist.escalate" color="warning" variant="subtle" icon="i-lucide-triangle-alert" title="Do decyzji człowieka" :description="assist.escalate_reason" />
              <div v-if="assist.missing.length"><p class="font-medium">Brakuje:</p><ul class="list-disc pl-5"><li v-for="x in assist.missing" :key="x">{{ x }}</li></ul></div>
              <details v-if="assist.reply"><summary class="cursor-pointer font-medium">Proponowana odpowiedź</summary><p class="mt-2 whitespace-pre-line rounded-lg bg-muted p-3">{{ assist.reply }}</p></details>
              <div class="flex flex-wrap gap-2">
                <UButton v-if="assist.reply && m.contact_id" label="Użyj w odpowiedzi" icon="i-lucide-reply" @click="useDraft" />
                <UButton label="Zamknij" color="neutral" variant="ghost" @click="assist = null" />
              </div>
              <p class="text-sm text-muted">Podpowiedź modelu AI — sprawdź przed wysłaniem.</p>
            </div>
          </UCard>

          <div v-if="m.thread.length > 1" class="flex justify-end">
            <USwitch v-model="conv" label="Pokaż całą rozmowę" />
          </div>

          <UAlert v-if="m.is_preview" color="info" variant="subtle" icon="i-lucide-info" title="To tylko podgląd treści" description="Pełną wiadomość można pobrać ze skrzynki pocztowej.">
            <template #actions><UButton label="Pobierz pełną treść" color="info" variant="outline" :loading="busy === 'fetch_body'" @click="act('fetch_body')" /></template>
          </UAlert>
          <UAlert v-if="m.has_remote_images && !showImages" color="neutral" variant="subtle" icon="i-lucide-image-off" title="Obrazy z internetu są zablokowane"
                  description="Chroni to przed śledzeniem otwarcia wiadomości.">
            <template #actions><UButton label="Pokaż obrazy" color="neutral" variant="outline" @click="showImages = true" /></template>
          </UAlert>

          <section v-if="m.conversation" aria-labelledby="h-rozmowa" class="space-y-3">
            <h2 id="h-rozmowa" class="sr-only">Cała rozmowa</h2>
            <article v-for="c in m.conversation" :key="c.id" :aria-current="c.id === m.id ? 'true' : undefined"
                     class="overflow-hidden rounded-xl border bg-default" :class="c.id === m.id ? 'border-primary' : 'border-default'">
              <header class="flex flex-wrap items-center gap-2 border-b border-default px-4 py-2 text-sm">
                <UIcon :name="c.direction === 'in' ? 'i-lucide-arrow-down-left' : 'i-lucide-arrow-up-right'" class="size-4" aria-hidden="true" />
                <strong>{{ c.direction === 'in' ? 'Od:' : 'Wysłana:' }} {{ c.from || '—' }}</strong>
                <span class="ml-auto text-muted">{{ fmtDateTime(c.sent_at) }}</span>
              </header>
              <iframe :srcdoc="c.frame.replace(/CRM_IMG_SRC/g, showImages ? 'https: http:' : '')" :title="`Treść wiadomości z ${fmtDateTime(c.sent_at)}`"
                      sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox" class="block w-full border-0 bg-white" style="height: 200px"
                      @load="e => { const f = e.target as HTMLIFrameElement; const d = f.contentDocument; if (d?.body) { f.style.height = '0px'; f.style.height = `${Math.min(d.body.scrollHeight + 8, 3000)}px` } }" />
            </article>
          </section>
          <section v-else aria-labelledby="h-tresc" class="overflow-hidden rounded-xl border border-default bg-white">
            <h2 id="h-tresc" class="sr-only">Treść wiadomości</h2>
            <iframe ref="frame" :srcdoc="frameDoc" title="Treść wiadomości" sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"
                    class="block w-full border-0" style="height: 240px" @load="fitFrame" />
          </section>

          <section v-if="m.attachments.length" aria-labelledby="h-zal">
            <h2 id="h-zal" class="mb-2 font-semibold text-highlighted">Załączniki</h2>
            <ul class="flex flex-wrap gap-2">
              <li v-for="a in m.attachments" :key="a.id">
                <UButton color="neutral" variant="outline" icon="i-lucide-paperclip" :label="`${a.name} (${fmtSize(a.size)})`" :aria-label="`Pobierz ${a.name}, ${fmtSize(a.size)}`" @click="download(a)" />
              </li>
            </ul>
          </section>

          <!-- odpowiedź -->
          <UCard v-if="replyOpen" as="section" aria-labelledby="h-odp">
            <template #header><h2 id="h-odp" class="text-lg font-semibold text-highlighted">Odpowiedź do: {{ m.contact_name }}</h2></template>
            <form novalidate class="space-y-4" @submit.prevent="sendReply">
              <div class="grid gap-4 sm:grid-cols-2">
                <NativeSelect id="r-from" v-model="reply.send_as" label="Wyślij jako" :options="(meta?.accounts ?? []).map(a => ({ value: a.key, label: a.label }))" />
                <NativeSelect v-if="meta?.templates.length" id="r-tpl" v-model="reply.template" label="Szablon" placeholder="— bez szablonu —"
                              :options="(meta?.templates ?? []).map(t => ({ value: String(t.id), label: t.name }))" />
              </div>
              <UFormField label="Temat" name="reply_subject" :error="replyErr.subject" required>
                <UInput v-model="reply.subject" name="reply_subject" class="w-full" />
              </UFormField>
              <UFormField label="Treść" name="reply_body" :error="replyErr.body" required help="Podpis i stopka Fundacji dołączą się same. Zmienne szablonu ({imie}, {nadawca_imie}…) też działają.">
                <UTextarea v-model="reply.body" name="reply_body" :rows="8" autoresize class="w-full" />
              </UFormField>
              <UFormField label="Załączniki" name="files" :error="replyErr.files" help="Najwyżej 5 plików, razem do 15 MB (pdf, dokumenty, arkusze, obrazy, archiwa). Załączniki szablonu dołączą się same.">
                <input id="r-files" type="file" name="files" multiple class="block w-full rounded-md border border-accented p-2 text-sm file:mr-3 file:min-h-9 file:rounded-md file:border-0 file:bg-elevated file:px-3"
                       accept=".pdf,.doc,.docx,.xls,.xlsx,.csv,.txt,.png,.jpg,.jpeg,.gif,.zip,.rar,.7z,.odt,.ods" @change="onFiles">
              </UFormField>
              <ul v-if="replyFiles.length" class="text-sm text-muted" aria-label="Wybrane pliki"><li v-for="f in replyFiles" :key="f.name">{{ f.name }} ({{ fmtSize(f.size) }})</li></ul>
              <div class="flex flex-wrap gap-3">
                <UButton type="submit" label="Wyślij odpowiedź" icon="i-lucide-send" :loading="replyBusy" />
                <UButton label="Anuluj" color="neutral" variant="outline" @click="replyOpen = false" />
              </div>
            </form>
          </UCard>
          <UAlert v-else-if="canWrite && !m.contact_id && m.direction === 'in'" color="neutral" variant="subtle" icon="i-lucide-info"
                  description="Aby odpowiedzieć, dodaj nadawcę do kontaktów albo powiąż go z istniejącym kontaktem (panel obok)." />

          <section v-if="m.thread.length > 1" aria-labelledby="h-watek">
            <h2 id="h-watek" class="mb-2 font-semibold text-highlighted">Wątek ({{ m.thread.length }})</h2>
            <ol class="divide-y divide-default rounded-xl border border-default bg-default">
              <li v-for="t in m.thread" :key="t.id" class="flex items-center gap-3 px-4 py-2">
                <UIcon :name="t.direction === 'in' ? 'i-lucide-arrow-down-left' : 'i-lucide-arrow-up-right'" class="size-4 shrink-0 text-muted" aria-hidden="true" />
                <span class="sr-only">{{ t.direction === 'in' ? 'Odebrana' : 'Wysłana' }}:</span>
                <span v-if="t.id === m.id" class="flex-1 truncate font-semibold" aria-current="true">{{ t.subject || '(bez tematu)' }} (ta wiadomość)</span>
                <NuxtLink v-else :to="{ path: `/skrzynka/${t.id}`, query: route.query }" class="flex-1 truncate text-primary underline-offset-2 hover:underline">{{ t.subject || '(bez tematu)' }}</NuxtLink>
                <span class="shrink-0 text-sm text-muted">{{ fmtDateTime(t.sent_at) }}</span>
              </li>
            </ol>
          </section>
        </div>

        <!-- panel boczny: kontakt i akcje -->
        <aside aria-label="Kontakt i akcje" class="space-y-4">
          <UCard as="section" aria-labelledby="h-kontakt">
            <template #header><h2 id="h-kontakt" class="font-semibold text-highlighted">Kontakt</h2></template>
            <div v-if="m.contact" class="space-y-1">
              <NuxtLink :to="`/kontakty/${m.contact.id}`" class="font-medium text-primary underline underline-offset-2">{{ m.contact.name }}</NuxtLink>
              <p class="text-sm text-muted">{{ m.contact.messages }} {{ plural(m.contact.messages, 'wiadomość', 'wiadomości', 'wiadomości') }} · {{ m.contact.cases }} {{ plural(m.contact.cases, 'sprawa', 'sprawy', 'spraw') }}</p>
              <p v-if="m.contact.owner_name" class="text-sm text-muted">Opiekun: {{ m.contact.owner_name }}</p>
            </div>
            <div v-else class="space-y-3">
              <p class="text-muted">Nadawca nie jest w kontaktach.</p>
              <UButton v-if="canWrite && session.can('contacts', 'write')" label="Dodaj do kontaktów" icon="i-lucide-user-plus" block :loading="busy === 'add_contact'" @click="act('add_contact')" />
              <template v-if="m.candidates.length && canWrite">
                <p class="text-sm font-medium">Albo powiąż z podobnym:</p>
                <ul class="space-y-1">
                  <li v-for="c in m.candidates" :key="c.id">
                    <UButton color="neutral" variant="outline" block class="justify-start" :label="[c.name, c.email || c.organizacja].filter(Boolean).join(' — ')" @click="act('link_contact', { contact_id: c.id })" />
                  </li>
                </ul>
              </template>
            </div>
          </UCard>

          <UCard v-if="canWrite" as="section" aria-labelledby="h-akcje">
            <template #header><h2 id="h-akcje" class="font-semibold text-highlighted">Obsługa</h2></template>
            <div class="space-y-4">
              <div class="grid grid-cols-2 gap-2">
                <UButton v-if="m.status !== 'archived'" label="Załatwione" icon="i-lucide-archive" color="success" variant="soft" :loading="busy === 'archive'" @click="act('archive', {}, { back: true })" />
                <UButton v-else label="Przywróć" icon="i-lucide-inbox" color="neutral" variant="outline" @click="act('restore')" />
                <UButton :label="m.is_read ? 'Nieprzeczytana' : 'Przeczytana'" icon="i-lucide-mail" color="neutral" variant="outline" @click="act(m.is_read ? 'unread' : 'read')" />
                <UButton v-if="m.status !== 'spam'" label="Spam" icon="i-lucide-ban" color="error" variant="soft" @click="act('spam', {}, { back: true })" />
                <UButton :label="m.hidden ? 'Pokaż w CRM' : 'Ukryj w CRM'" :icon="m.hidden ? 'i-lucide-eye' : 'i-lucide-eye-off'" color="neutral" variant="outline" @click="act(m.hidden ? 'unhide' : 'hide')" />
              </div>
              <form class="flex items-end gap-2" @submit.prevent="act('assign', { user_id: Number(assignTo || 0) })">
                <NativeSelect id="a-user" v-model="assignTo" label="Prowadzi" placeholder="— nikt —" class="flex-1"
                              :options="(session.dict.value?.owners ?? []).map(o => ({ value: String(o.id), label: o.name }))" />
                <UButton type="submit" label="Zapisz" color="neutral" variant="outline" :aria-label="'Zapisz osobę prowadzącą'" />
              </form>
              <UButton v-if="m.assigned_to !== meta?.me" label="Biorę to" icon="i-lucide-hand" color="primary" variant="soft" block @click="act('assign', { user_id: meta?.me })" />
              <form v-if="meta?.queues.length" class="flex items-end gap-2" @submit.prevent="act('queue', { queue_id: Number(queueTo || 0) })">
                <NativeSelect id="a-queue" v-model="queueTo" label="Kolejka" placeholder="— domyślna —" class="flex-1" :options="(meta?.queues ?? []).map(q => ({ value: String(q.id), label: q.name }))" />
                <UButton type="submit" label="Przenieś" color="neutral" variant="outline" aria-label="Przenieś do kolejki" />
              </form>
              <div>
                <h3 class="mb-1 text-sm font-medium text-highlighted">Tagi</h3>
                <ul class="mb-2 flex flex-wrap gap-1">
                  <li v-for="t in m.tags" :key="t" class="inline-flex items-center gap-1 rounded-full border border-default py-0.5 pr-1 pl-3 text-sm">
                    #{{ t }}<UButton size="xs" color="neutral" variant="ghost" icon="i-lucide-x" :aria-label="`Usuń tag ${t}`" @click="act('tag_remove', { tag: t })" />
                  </li>
                  <li v-if="!m.tags.length" class="text-sm text-muted">Brak</li>
                </ul>
                <form class="flex gap-2" @submit.prevent="addTag">
                  <label for="a-tag" class="sr-only">Nowy tag</label>
                  <UInput id="a-tag" v-model="newTag" placeholder="Nowy tag" size="md" class="flex-1" list="ib-tag-list" />
                  <datalist id="ib-tag-list"><option v-for="t in meta?.tags" :key="t" :value="t" /></datalist>
                  <UButton type="submit" label="Dodaj" size="md" color="neutral" variant="outline" aria-label="Dodaj tag" />
                </form>
              </div>
            </div>
          </UCard>

          <UCard as="section" aria-labelledby="h-obs">
            <template #header><h2 id="h-obs" class="font-semibold text-highlighted">Obserwujący</h2></template>
            <div class="space-y-3">
              <ul v-if="m.followers.length" class="space-y-1">
                <li v-for="u in m.followers" :key="u.id" class="flex items-center justify-between gap-2">
                  <span>{{ u.name }}</span>
                  <UButton v-if="canWrite" size="xs" color="neutral" variant="ghost" icon="i-lucide-x" :aria-label="`Usuń obserwującego ${u.name}`" @click="act('fol_remove', { user_id: u.id })" />
                </li>
              </ul>
              <p v-else class="text-sm text-muted">Nikt nie obserwuje tej rozmowy.</p>
              <UButton :label="m.following ? 'Przestań obserwować' : 'Obserwuj rozmowę'" :icon="m.following ? 'i-lucide-bell-off' : 'i-lucide-bell'" color="neutral" variant="outline" block @click="act('fol_me')" />
              <form v-if="canWrite" class="flex items-end gap-2" @submit.prevent="addFollower">
                <NativeSelect id="a-fol" v-model="followerTo" label="Dodaj osobę" placeholder="Wybierz…" class="flex-1"
                              :options="(session.dict.value?.owners ?? []).filter(o => !(m?.followers ?? []).some(f => f.id === o.id)).map(o => ({ value: String(o.id), label: o.name }))" />
                <UButton type="submit" label="Dodaj" color="neutral" variant="outline" aria-label="Dodaj obserwującego" />
              </form>
            </div>
          </UCard>

          <UCard v-if="session.can('cases')" as="section" aria-labelledby="h-sprawa">
            <template #header><h2 id="h-sprawa" class="font-semibold text-highlighted">Sprawa</h2></template>
            <div class="space-y-3">
              <p v-if="m.case_id"><NuxtLink :to="`/sprawy/${m.case_id}`" class="text-primary underline underline-offset-2">Otwórz sprawę</NuxtLink></p>
              <UButton v-if="canWrite && session.can('cases', 'write') && m.contact_id" label="Utwórz sprawę z wiadomości" icon="i-lucide-briefcase" block @click="caseOpen = true" />
              <form v-if="canWrite && m.contact_cases.length" class="flex items-end gap-2" @submit.prevent="act('attach_case', { case_id: Number(attachCase || 0) })">
                <NativeSelect id="a-case" v-model="attachCase" label="Dołącz do sprawy" placeholder="— żadna —" class="flex-1"
                              :options="m.contact_cases.map(c => ({ value: String(c.id), label: `${c.case_number ?? ''} ${c.title}${c.is_closed ? ' (zamknięta)' : ''}` }))" />
                <UButton type="submit" label="Zapisz" color="neutral" variant="outline" aria-label="Zapisz powiązanie ze sprawą" />
              </form>
              <p v-if="!m.contact_id" class="text-sm text-muted">Sprawę można założyć, gdy nadawca jest w kontaktach.</p>
            </div>
          </UCard>
        </aside>
      </div>

      <UModal v-model:open="ezdOpen" title="Zarejestruj w dzienniku EZD" description="Wiadomość z załącznikami trafi do EZD jako pismo przychodzące.">
        <template #body>
          <form id="ezd-form" novalidate class="space-y-4" @submit.prevent="toEzd">
            <fieldset class="space-y-2">
              <legend class="text-sm font-medium text-highlighted">Gdzie</legend>
              <URadioGroup v-model="ezd.mode" :items="[{ value: 'new', label: 'Nowa sprawa w teczce' }, { value: 'existing', label: 'Istniejąca sprawa' }]" />
            </fieldset>
            <NativeSelect v-if="ezd.mode === 'new'" id="ezd-teczka" v-model="ezd.teczka_id" label="Teczka" placeholder="Wybierz…" :options="(ezdOpts?.teczki ?? []).map(t => ({ value: String(t.id), label: t.label }))" />
            <template v-else>
              <div class="flex items-end gap-2">
                <div class="flex flex-1 flex-col gap-1">
                  <label for="ezd-q" class="text-sm font-medium text-highlighted">Szukaj sprawy</label>
                  <UInput id="ezd-q" v-model="ezd.q" placeholder="Znak albo tytuł" class="w-full" @keydown.enter.prevent="ezdSearch" />
                </div>
                <UButton label="Szukaj" color="neutral" variant="outline" @click="ezdSearch" />
              </div>
              <NativeSelect id="ezd-sprawa" v-model="ezd.sprawa_id" label="Sprawa" placeholder="Wybierz…" :options="(ezdOpts?.sprawy ?? []).map(t => ({ value: String(t.id), label: t.label }))" />
            </template>
            <div class="grid gap-4 sm:grid-cols-2">
              <UFormField label="Strona (nadawca)" name="strona_name"><UInput v-model="ezd.strona_name" class="w-full" /></UFormField>
              <NativeSelect id="ezd-rola" v-model="ezd.strona_rola" label="Rola strony" placeholder="— rola —" :options="(ezdOpts?.roles ?? []).map(r => ({ value: r, label: r }))" />
            </div>
            <p v-if="ezdErr" role="alert" class="text-sm text-error">{{ ezdErr }}</p>
          </form>
        </template>
        <template #footer>
          <div class="flex w-full justify-end gap-2">
            <UButton label="Anuluj" color="neutral" variant="outline" @click="ezdOpen = false" />
            <UButton type="submit" form="ezd-form" label="Zarejestruj" icon="i-lucide-book-marked" :loading="busy === 'ezd'" />
          </div>
        </template>
      </UModal>

      <UModal v-model:open="fwdOpen" title="Przekaż wiadomość" description="Wiadomość z załącznikami trafi pod wskazany adres.">
        <template #body>
          <form id="fwd-form" novalidate class="space-y-4" @submit.prevent="forward">
            <UFormField label="Adres e-mail" name="fwd_to" :error="fwdErr || undefined" required>
              <UInput v-model="fwd.to" name="fwd_to" type="email" class="w-full" />
            </UFormField>
            <UFormField label="Notatka (opcjonalnie)" name="fwd_note"><UTextarea v-model="fwd.note" :rows="3" class="w-full" /></UFormField>
          </form>
        </template>
        <template #footer>
          <div class="flex w-full justify-end gap-2">
            <UButton label="Anuluj" color="neutral" variant="outline" @click="fwdOpen = false" />
            <UButton type="submit" form="fwd-form" label="Przekaż" icon="i-lucide-forward" :loading="busy === 'forward'" />
          </div>
        </template>
      </UModal>

      <UModal v-model:open="caseOpen" title="Nowa sprawa z wiadomości" description="Treść wiadomości trafi do opisu sprawy.">
        <template #body>
          <form id="case-form" novalidate class="space-y-4" @submit.prevent="createCase">
            <UFormField label="Tytuł" name="case_title" required><UInput v-model="cs.title" name="case_title" class="w-full" /></UFormField>
            <NativeSelect id="c-prio" v-model="cs.priority" label="Priorytet" :options="session.dict.value?.case_priorities ?? []" />
            <NativeSelect id="c-owner" v-model="cs.owner_id" label="Opiekun" :options="(session.dict.value?.owners ?? []).map(o => ({ value: String(o.id), label: o.name }))" />
            <UCheckbox v-model="cs.keep_open" label="Zostaw wiadomość w skrzynce" description="Bez tego wiadomość przejdzie do załatwionych." />
          </form>
        </template>
        <template #footer>
          <div class="flex w-full justify-end gap-2">
            <UButton label="Anuluj" color="neutral" variant="outline" @click="caseOpen = false" />
            <UButton type="submit" form="case-form" label="Utwórz sprawę" icon="i-lucide-briefcase" :loading="busy === 'case'" />
          </div>
        </template>
      </UModal>
    </template>
    <p v-else role="status" class="text-muted">Wczytywanie wiadomości…</p>
  </div>
</template>
