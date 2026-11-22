import type { Dictionaries, Me } from '~/utils/types'

/** Dane wspólne całej aplikacji: kto jest zalogowany (uprawnienia) i słowniki. Wczytywane raz, odświeżane na żądanie. */
export function useCrmSession() {
  const me = useState<Me | null>('crm-me', () => null)
  const dict = useState<Dictionaries | null>('crm-dict', () => null)
  const loadError = useState<string | null>('crm-load-error', () => null)
  const api = useApi()

  async function load(force = false): Promise<void> {
    if (me.value && dict.value && !force) return
    loadError.value = null
    try {
      const [m, d] = await Promise.all([
        api.get<{ data: Me }>('/me'),
        api.get<{ data: Dictionaries }>('/dictionaries'),
      ])
      me.value = m.data
      dict.value = d.data
    } catch (e) {
      if ((e as { status?: number }).status !== 401) loadError.value = (e as Error).message
    }
  }

  const can = (area: string, op: 'read' | 'write' | 'delete' = 'read') => !!me.value?.perms[area]?.[op]
  const statusOf = (v: string | null | undefined) => dict.value?.statuses.find(s => s.value === v)
  const typeOf = (v: string | null | undefined) => dict.value?.types.find(t => t.value === v)
  const ownerName = (id: number | null | undefined) => (id ? dict.value?.owners.find(o => o.id === id)?.name : undefined) ?? null
  const legacy = (path: string) => `${me.value?.legacy_url ?? '/crm/'}${path.replace(/^\//, '')}`

  return { me, dict, loadError, load, can, statusOf, typeOf, ownerName, legacy }
}
