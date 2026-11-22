<script setup lang="ts">
import type { Dashboard } from '~/utils/types'

const api = useApi()
const session = useCrmSession()
const NuxtLink = resolveComponent('NuxtLink')
const { data, error, status, refresh } = await useAsyncData('dashboard', () => api.get<{ data: Dashboard }>('/dashboard').then(r => r.data))

const tiles = computed(() => {
  const t = data.value?.tiles
  if (!t) return []
  const out: { label: string, value: number, icon: string, to?: string, href?: string, tone: 'error' | 'warning' | 'primary' | 'neutral' }[] = [
    { label: 'Zaległe działania', value: t.acts_late, icon: 'i-lucide-alarm-clock', href: session.legacy('activities.php'), tone: t.acts_late ? 'error' : 'neutral' },
    { label: 'Działania na dziś', value: t.acts_today, icon: 'i-lucide-calendar-check', href: session.legacy('activities.php'), tone: t.acts_today ? 'primary' : 'neutral' },
  ]
  if (t.inbox_unread !== null) out.push({ label: 'Nieprzeczytane w skrzynce', value: t.inbox_unread, icon: 'i-lucide-inbox', href: session.legacy('inbox.php'), tone: t.inbox_unread ? 'primary' : 'neutral' })
  if (t.cases_open !== null) out.push({ label: 'Otwarte sprawy', value: t.cases_open, icon: 'i-lucide-briefcase', to: '/sprawy?status=active', tone: 'neutral' })
  if (t.cases_high) out.push({ label: 'Sprawy pilne', value: t.cases_high, icon: 'i-lucide-flame', to: '/sprawy?status=active&priority=high', tone: 'warning' })
  if (t.cases_stale) out.push({ label: `Bez ruchu ponad 60 dni`, value: t.cases_stale, icon: 'i-lucide-clock-alert', to: '/sprawy?status=active', tone: 'warning' })
  return out
})
const maxStatus = computed(() => Math.max(1, ...(data.value?.status_bars ?? []).map(s => s.count)))
const toneCls: Record<string, string> = {
  error: 'border-error/40 bg-error/5', warning: 'border-warning/50 bg-warning/5', primary: 'border-primary/40 bg-primary/5', neutral: 'border-default bg-default',
}
</script>

<template>
  <div>
    <PageHeader :title="`Dzień dobry${session.me.value ? ', ' + session.me.value.user.name.split(' ')[0] : ''}`" description="Co wymaga Twojej uwagi w CRM">
      <template #actions>
        <UButton v-if="session.can('contacts', 'write')" to="/kontakty/nowy" icon="i-lucide-user-plus" label="Nowy kontakt" />
        <UButton v-if="session.can('cases', 'write')" to="/sprawy/nowa" icon="i-lucide-briefcase" label="Nowa sprawa" color="neutral" variant="outline" />
      </template>
    </PageHeader>

    <ErrorState v-if="error" :message="error.message" @retry="refresh()" />
    <p v-else-if="status === 'pending' && !data" role="status" class="text-muted">Wczytywanie pulpitu…</p>

    <template v-else-if="data">
      <section aria-labelledby="h-uwaga" class="mb-8">
        <h2 id="h-uwaga" class="sr-only">Wymaga reakcji</h2>
        <ul class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
          <li v-for="t in tiles" :key="t.label">
            <component :is="t.to ? NuxtLink : 'a'" :to="t.to" :href="t.href"
                       class="flex min-h-24 items-center gap-4 rounded-xl border p-4 transition hover:shadow-md" :class="toneCls[t.tone]">
              <UIcon :name="t.icon" class="size-7 shrink-0 text-toned" aria-hidden="true" />
              <span class="flex flex-col">
                <span class="text-3xl font-semibold leading-none text-highlighted">{{ t.value }}</span>
                <span class="mt-1 text-toned">{{ t.label }}</span>
              </span>
            </component>
          </li>
        </ul>
      </section>

      <div class="grid gap-6 xl:grid-cols-2">
        <UCard as="section" aria-labelledby="h-dzialania">
          <template #header><h2 id="h-dzialania" class="text-lg font-semibold text-highlighted">Moje działania na dziś</h2></template>
          <EmptyState v-if="!data.my_activities.length" title="Nic zaległego" description="Nie masz zaplanowanych działań na dziś." icon="i-lucide-party-popper" />
          <ul v-else class="divide-y divide-default">
            <li v-for="a in data.my_activities" :key="a.id" class="flex items-start justify-between gap-3 py-3">
              <div class="min-w-0">
                <p class="font-medium text-highlighted">{{ a.title }}</p>
                <NuxtLink v-if="a.contact_id" :to="`/kontakty/${a.contact_id}`" class="text-primary underline-offset-2 hover:underline">{{ a.contact_name }}</NuxtLink>
              </div>
              <span class="shrink-0 text-sm text-muted">{{ fmtDateTime(a.scheduled_at) }}</span>
            </li>
          </ul>
        </UCard>

        <UCard v-if="session.can('cases')" as="section" aria-labelledby="h-sprawy">
          <template #header>
            <div class="flex items-center justify-between gap-2">
              <h2 id="h-sprawy" class="text-lg font-semibold text-highlighted">Otwarte sprawy</h2>
              <UButton to="/sprawy?status=active" variant="link" label="Wszystkie" trailing-icon="i-lucide-arrow-right" aria-label="Wszystkie otwarte sprawy" />
            </div>
          </template>
          <EmptyState v-if="!data.open_cases.length" title="Brak otwartych spraw" icon="i-lucide-briefcase" />
          <ul v-else class="divide-y divide-default">
            <li v-for="c in data.open_cases" :key="c.id" class="py-3">
              <NuxtLink :to="`/sprawy/${c.id}`" class="font-medium text-highlighted underline-offset-2 hover:underline">{{ c.title }}</NuxtLink>
              <p class="mt-1 flex flex-wrap items-center gap-2 text-sm text-muted">
                <span v-if="c.case_number">{{ c.case_number }}</span>
                <span v-if="c.contact_name">· {{ c.contact_name }}</span>
                <UBadge v-if="c.priority === 'high'" color="error" variant="subtle" label="Pilna" />
                <UBadge v-if="c.is_overdue" color="warning" variant="subtle" label="Po terminie" />
              </p>
            </li>
          </ul>
        </UCard>

        <UCard as="section" aria-labelledby="h-ostatnie">
          <template #header><h2 id="h-ostatnie" class="text-lg font-semibold text-highlighted">Ostatnio zmieniane kontakty</h2></template>
          <ul class="divide-y divide-default">
            <li v-for="c in data.recent_contacts" :key="c.id" class="flex items-center gap-3 py-3">
              <UAvatar :text="c.avatar_initials || initials(c.imie_nazwisko)" size="md" aria-hidden="true" />
              <div class="min-w-0 flex-1">
                <NuxtLink :to="`/kontakty/${c.id}`" class="font-medium text-highlighted underline-offset-2 hover:underline">{{ c.imie_nazwisko || 'Bez nazwy' }}</NuxtLink>
                <p class="truncate text-sm text-muted">{{ c.organizacja || c.email || '—' }}</p>
              </div>
              <span class="text-sm text-muted">{{ fmtRelative(c.updated_at) }}</span>
            </li>
          </ul>
        </UCard>

        <UCard as="section" aria-labelledby="h-kom">
          <template #header><h2 id="h-kom" class="text-lg font-semibold text-highlighted">Ostatnia komunikacja</h2></template>
          <EmptyState v-if="!data.recent_communications.length" title="Brak komunikacji" icon="i-lucide-mail" />
          <ul v-else class="divide-y divide-default">
            <li v-for="m in data.recent_communications" :key="m.id" class="py-3">
              <p class="flex items-center gap-2">
                <UIcon :name="m.direction === 'in' ? 'i-lucide-arrow-down-left' : 'i-lucide-arrow-up-right'" class="size-4 shrink-0 text-muted" aria-hidden="true" />
                <span class="sr-only">{{ m.direction === 'in' ? 'Odebrana od' : 'Wysłana do' }}</span>
                <NuxtLink :to="`/kontakty/${m.contact_id}`" class="font-medium text-highlighted underline-offset-2 hover:underline">{{ m.contact_name }}</NuxtLink>
                <span class="ml-auto text-sm text-muted">{{ fmtRelative(m.sent_at) }}</span>
              </p>
              <p class="truncate text-sm text-muted">{{ m.subject || m.excerpt }}</p>
            </li>
          </ul>
        </UCard>
      </div>

      <section aria-labelledby="h-statusy" class="mt-8">
        <UCard>
          <template #header>
            <h2 id="h-statusy" class="text-lg font-semibold text-highlighted">Kontakty według statusu</h2>
            <p class="text-sm text-muted">Razem {{ data.stats.total }} · osoby {{ data.stats.persons }} · organizacje {{ data.stats.orgs }} · nowe w tym miesiącu {{ data.stats.new_this_month }}</p>
          </template>
          <ul class="space-y-2">
            <li v-for="s in data.status_bars" :key="s.value">
              <NuxtLink :to="{ path: '/kontakty', query: { status: s.value } }" class="grid grid-cols-[9rem_1fr_3rem] items-center gap-3 rounded-md py-1 hover:bg-elevated">
                <span class="truncate">{{ s.label }}</span>
                <span class="h-3 overflow-hidden rounded-full bg-accented" aria-hidden="true">
                  <span class="block h-full rounded-full" :style="{ width: `${(s.count / maxStatus) * 100}%`, background: s.color || 'var(--ui-primary)' }" />
                </span>
                <span class="text-right font-medium tabular-nums">{{ s.count }}</span>
              </NuxtLink>
            </li>
          </ul>
        </UCard>
      </section>
    </template>
  </div>
</template>
