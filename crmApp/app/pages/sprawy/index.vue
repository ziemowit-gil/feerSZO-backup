<script setup lang="ts">
import type { CaseRow, Paged } from '~/utils/types'

const route = useRoute()
const router = useRouter()
const api = useApi()
const session = useCrmSession()
const { dict } = session

const KEYS = ['q', 'status', 'priority', 'type_id', 'mine', 'noowner', 'overdue'] as const
const f = reactive<Record<string, string>>(Object.fromEntries(KEYS.map(k => [k, String(route.query[k] ?? '')])))
const page = computed(() => Math.max(1, Number(route.query.page || 1)))
function apply(p = 1) {
  const query: Record<string, string> = {}
  for (const k of KEYS) if (f[k]) query[k] = f[k]
  if (p > 1) query.page = String(p)
  router.replace({ query })
}
function quick(k: 'mine' | 'noowner' | 'overdue') {
  const on = !f[k]
  f.mine = f.noowner = f.overdue = ''
  f[k] = on ? '1' : ''
  apply()
}

type CasesResponse = Paged<CaseRow> & { counters: { active: number, mine: number, noowner: number, overdue: number } }
const { data, error, status, refresh } = await useAsyncData('cases', () => api.get<CasesResponse>('/cases', flatQuery(route.query)), { watch: [() => route.query] })
const rows = computed(() => data.value?.data ?? [])
const total = computed(() => data.value?.meta.total ?? 0)
const PRIO: Record<string, string> = { low: 'Niski', medium: 'Średni', high: 'Wysoki' }
</script>

<template>
  <div>
    <PageHeader title="Sprawy" description="Rejestr spraw CRM">
      <template #actions>
        <UButton v-if="session.can('cases', 'write')" to="/sprawy/nowa" icon="i-lucide-plus" label="Nowa sprawa" />
      </template>
    </PageHeader>

    <div v-if="data" class="mb-4 flex flex-wrap gap-2" role="group" aria-label="Szybkie filtry">
      <UButton :variant="f.mine ? 'solid' : 'outline'" color="neutral" :aria-pressed="!!f.mine" :label="`Moje (${data.counters.mine})`" @click="quick('mine')" />
      <UButton :variant="f.noowner ? 'solid' : 'outline'" color="neutral" :aria-pressed="!!f.noowner" :label="`Bez opiekuna (${data.counters.noowner})`" @click="quick('noowner')" />
      <UButton :variant="f.overdue ? 'solid' : 'outline'" :color="data.counters.overdue ? 'warning' : 'neutral'" :aria-pressed="!!f.overdue" :label="`Po terminie (${data.counters.overdue})`" @click="quick('overdue')" />
    </div>

    <form role="search" aria-label="Wyszukiwanie spraw" class="mb-4 grid gap-4 rounded-xl border border-default bg-default p-4 sm:grid-cols-2 lg:grid-cols-5" @submit.prevent="apply()">
      <div class="flex flex-col gap-1 lg:col-span-2">
        <label for="s-q" class="text-sm font-medium text-highlighted">Szukaj</label>
        <UInput id="s-q" v-model="f.q" icon="i-lucide-search" placeholder="Tytuł, numer, kontakt" class="w-full" />
      </div>
      <NativeSelect id="s-status" v-model="f.status" label="Status" placeholder="Wszystkie"
                    :options="[{ value: 'active', label: 'Tylko otwarte' }, ...(dict?.case_statuses ?? [])]" />
      <NativeSelect id="s-prio" v-model="f.priority" label="Priorytet" placeholder="Każdy" :options="dict?.case_priorities ?? []" />
      <NativeSelect v-if="dict?.case_types.length" id="s-type" v-model="f.type_id" label="Typ" placeholder="Każdy" :options="(dict?.case_types ?? []).map(t => ({ value: String(t.id), label: t.name }))" />
      <div class="flex items-end gap-2 sm:col-span-2 lg:col-span-5">
        <UButton type="submit" label="Pokaż" icon="i-lucide-filter" />
      </div>
    </form>

    <p class="mb-3 text-muted" role="status" aria-live="polite">
      <template v-if="status === 'pending'">Wyszukiwanie…</template>
      <template v-else-if="data">Znaleziono {{ total }} {{ plural(total, 'sprawę', 'sprawy', 'spraw') }}</template>
    </p>
    <ErrorState v-if="error" :message="error.message" @retry="refresh()" />

    <div v-if="rows.length" class="overflow-x-auto rounded-xl border border-default bg-default">
      <table class="w-full min-w-[42rem] text-left">
        <caption class="sr-only">Rejestr spraw, strona {{ page }}</caption>
        <thead class="border-b border-default bg-muted text-sm text-toned">
          <tr>
            <th scope="col" class="px-4 py-3 font-semibold">Sprawa</th>
            <th scope="col" class="px-4 py-3 font-semibold">Status</th>
            <th scope="col" class="px-4 py-3 font-semibold">Kontakt</th>
            <th scope="col" class="hidden px-4 py-3 font-semibold md:table-cell">Opiekun</th>
            <th scope="col" class="px-4 py-3 font-semibold">Termin</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-default">
          <tr v-for="c in rows" :key="c.id" class="hover:bg-muted">
            <td class="px-4 py-3 align-top">
              <NuxtLink :to="`/sprawy/${c.id}`" class="font-medium text-highlighted underline-offset-2 hover:underline">{{ c.title }}</NuxtLink>
              <p class="flex flex-wrap items-center gap-2 text-sm text-muted">
                {{ c.case_number }}
                <UBadge v-if="c.priority === 'high'" color="error" variant="subtle" :label="`Priorytet: ${PRIO.high}`" />
              </p>
            </td>
            <td class="px-4 py-3 align-top"><StatusChip :label="c.status_label" :color="c.status_color" /></td>
            <td class="px-4 py-3 align-top">
              <NuxtLink v-if="c.contact_id" :to="`/kontakty/${c.contact_id}`" class="text-primary underline-offset-2 hover:underline">{{ c.contact_name }}</NuxtLink>
            </td>
            <td class="hidden px-4 py-3 align-top text-sm md:table-cell">{{ c.owner_name || '—' }}</td>
            <td class="px-4 py-3 align-top text-sm">
              <span :class="c.is_overdue && 'font-semibold text-error'">{{ c.due_date ? fmtDate(c.due_date) : '—' }}</span>
              <span v-if="c.is_overdue" class="block text-error">po terminie</span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
    <EmptyState v-else-if="status !== 'pending' && !error" title="Brak spraw" description="Zmień filtry albo załóż nową sprawę." icon="i-lucide-briefcase" />

    <Pager v-if="data" :page="page" :pages="data.meta.pages" :total="total" label="Strony rejestru spraw" @update:page="apply" />
  </div>
</template>
