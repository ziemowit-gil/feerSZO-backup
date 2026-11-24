<script setup lang="ts">
import type { NavigationMenuItem, DropdownMenuItem } from '@nuxt/ui'

const session = useCrmSession()
const auth = useAuth()
const route = useRoute()
const router = useRouter()
const { me, can, legacy } = session

onMounted(() => session.load())

// Po zmianie strony fokus wraca na początek treści — użytkownik klawiatury i czytnika nie zostaje w menu.
const main = ref<HTMLElement | null>(null)
router.afterEach((to, from) => {
  if (to.path !== from.path) nextTick(() => main.value?.focus({ preventScroll: false }))
})

const badge = (n?: number | null) => (n ? String(n) : undefined)

// Sekcje przeniesione do nowej aplikacji + skróty do klasycznego CRM (reszta modułu, do czasu migracji).
const nav = computed<NavigationMenuItem[]>(() => {
  const items: NavigationMenuItem[] = [
    { label: 'Pulpit', icon: 'i-lucide-layout-dashboard', to: '/', exact: true },
    { label: 'Kontakty', icon: 'i-lucide-users', to: '/kontakty' },
  ]
  if (can('cases')) items.push({ label: 'Sprawy', icon: 'i-lucide-briefcase', to: '/sprawy', badge: badge(me.value?.badges.cases_stale) })
  return items
})

const legacyLinks = computed(() => {
  const l: { label: string, icon: string, href: string, badge?: number }[] = []
  if (can('inbox')) l.push({ label: 'Skrzynka', icon: 'i-lucide-inbox', href: legacy('inbox.php'), badge: me.value?.badges.inbox_unread })
  l.push({ label: 'Moja kolejka', icon: 'i-lucide-list-checks', href: legacy('activities.php'), badge: me.value?.badges.queue_due })
  l.push({ label: 'Kalendarz', icon: 'i-lucide-calendar', href: legacy('calendar.php') })
  if (me.value?.can_mailing) l.push({ label: 'Komunikacja', icon: 'i-lucide-send', href: legacy('communicate.php') })
  if (can('offers')) l.push({ label: 'Oferty', icon: 'i-lucide-file-text', href: legacy('offers/index.php') })
  if (can('settings')) l.push({ label: 'Ustawienia CRM', icon: 'i-lucide-settings', href: legacy('settings/index.php') })
  return l
})

const userMenu = computed<DropdownMenuItem[][]>(() => [
  [{ label: me.value?.user.name ?? 'Konto', type: 'label' }],
  [
    { label: 'Klasyczny CRM', icon: 'i-lucide-external-link', href: me.value?.legacy_url },
    { label: 'Konto SZO', icon: 'i-lucide-user-round', href: me.value ? `${me.value.szo_url}/tozsamosc/index.php` : undefined },
  ],
  [{ label: 'Wyloguj z aplikacji CRM', icon: 'i-lucide-log-out', onSelect: async () => { await auth.logout(); await navigateTo('/auth/wylogowano') } }],
])

const mobileOpen = ref(false)
watch(() => route.fullPath, () => { mobileOpen.value = false })

// Szybkie wyszukiwanie kontaktów z paska (klawisz „/” przenosi fokus do pola).
const q = ref('')
const searchInput = ref<{ inputRef?: HTMLInputElement } | null>(null)
function search() {
  navigateTo({ path: '/kontakty', query: q.value.trim() ? { q: q.value.trim() } : {} })
}
defineShortcuts({ '/': () => searchInput.value?.inputRef?.focus() })
</script>

<template>
  <div class="min-h-dvh">
    <a href="#tresc" class="skip-link">Przejdź do treści</a>

    <header class="sticky top-0 z-40 border-b border-default bg-default/95 backdrop-blur">
      <div class="mx-auto flex h-16 max-w-7xl items-center gap-3 px-4 sm:px-6">
        <UButton class="xl:hidden" color="neutral" variant="ghost" icon="i-lucide-menu" aria-label="Otwórz menu" :aria-expanded="mobileOpen" @click="mobileOpen = true" />
        <NuxtLink to="/" class="flex items-center gap-2 rounded-md font-semibold text-highlighted">
          <span class="grid size-9 place-items-center rounded-lg bg-primary text-inverted" aria-hidden="true"><UIcon name="i-lucide-contact" class="size-5" /></span>
          <span>CRM <span class="font-normal text-muted">SZO</span></span>
        </NuxtLink>

        <UNavigationMenu :items="nav" class="ml-4 hidden lg:flex" aria-label="Główne sekcje" />

        <form role="search" class="ml-auto hidden flex-1 justify-end md:flex" @submit.prevent="search">
          <label for="szukaj-kontaktu" class="sr-only">Szukaj kontaktu</label>
          <UInput id="szukaj-kontaktu" ref="searchInput" v-model="q" icon="i-lucide-search" placeholder="Szukaj kontaktu… ( / )" class="w-full max-w-xs" size="md" />
        </form>

        <UColorModeButton class="ml-auto md:ml-0" />
        <UDropdownMenu :items="userMenu" :content="{ align: 'end' }">
          <UButton color="neutral" variant="ghost" :aria-label="`Menu konta: ${me?.user.name ?? ''}`" class="gap-2">
            <UAvatar :text="initials(me?.user.name)" size="sm" aria-hidden="true" />
            <span class="hidden max-w-40 truncate xl:inline">{{ me?.user.name }}</span>
          </UButton>
        </UDropdownMenu>
      </div>
    </header>

    <USlideover v-model:open="mobileOpen" side="left" title="Menu CRM" description="Sekcje aplikacji CRM">
      <template #body>
        <form role="search" class="mb-4" @submit.prevent="search">
          <label for="szukaj-mobile" class="mb-1 block font-medium">Szukaj kontaktu</label>
          <UInput id="szukaj-mobile" v-model="q" icon="i-lucide-search" class="w-full" />
        </form>
        <UNavigationMenu :items="nav" orientation="vertical" aria-label="Główne sekcje" />
        <USeparator class="my-4" />
        <p class="mb-2 text-sm font-semibold text-muted">W klasycznym CRM</p>
        <ul class="space-y-1">
          <li v-for="l in legacyLinks" :key="l.href">
            <a :href="l.href" class="flex min-h-11 items-center gap-2 rounded-md px-2 hover:bg-elevated">
              <UIcon :name="l.icon" class="size-5" aria-hidden="true" />{{ l.label }}
              <UBadge v-if="l.badge" :label="String(l.badge)" color="neutral" variant="outline" class="ml-auto" />
            </a>
          </li>
        </ul>
      </template>
    </USlideover>

    <div class="mx-auto max-w-7xl px-4 sm:px-6 xl:grid xl:grid-cols-[1fr_14rem] xl:gap-8">
      <main id="tresc" ref="main" tabindex="-1" class="min-w-0 py-6 sm:py-8">
        <UAlert v-if="session.loadError.value" color="error" variant="subtle" class="mb-6" icon="i-lucide-circle-alert"
                title="Nie udało się połączyć z SZO" :description="session.loadError.value" role="alert" />
        <slot />
      </main>

      <aside class="hidden py-8 xl:block" aria-labelledby="klasyczny-crm">
        <h2 id="klasyczny-crm" class="mb-2 text-sm font-semibold text-muted">W klasycznym CRM</h2>
        <ul class="space-y-1">
          <li v-for="l in legacyLinks" :key="l.href">
            <a :href="l.href" class="flex min-h-11 items-center gap-2 rounded-md px-2 text-default hover:bg-elevated">
              <UIcon :name="l.icon" class="size-5 shrink-0" aria-hidden="true" />
              <span class="truncate">{{ l.label }}</span>
              <UBadge v-if="l.badge" :label="String(l.badge)" color="neutral" variant="outline" class="ml-auto" />
            </a>
          </li>
        </ul>
        <p class="mt-4 text-sm text-muted">Te sekcje otworzą się w dotychczasowym wyglądzie, do czasu przeniesienia.</p>
      </aside>
    </div>

    <footer class="border-t border-default py-6 text-center text-sm text-muted">
      SZO · Moduł CRM · nowa aplikacja (wersja testowa)
    </footer>
  </div>
</template>
