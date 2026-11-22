<script setup lang="ts">
// Formularz kartoteki (nowa / edycja). Pola bez prawa podglądu są ukryte, bez prawa edycji — tylko do odczytu
// (to samo egzekwuje serwer). Błędy z serwera (422 fields) pokazujemy przy polu i w podsumowaniu nad formularzem.
const props = defineProps<{
  initial?: Record<string, unknown>
  fieldView?: Record<string, boolean>
  fieldEdit?: Record<string, boolean>
  submitLabel: string
  busy?: boolean
  errors: Record<string, string>
  message?: string
}>()
const emit = defineEmits<{ submit: [Record<string, unknown>] }>()
const { dict } = useCrmSession()

const s = reactive<Record<string, string | boolean>>({
  type: 'osoba', status: '', imie: '', nazwisko: '', imie_nazwisko: '', email: '', telefon: '', strona_www: '',
  organizacja: '', stanowisko: '', nip: '', krs: '', regon: '', forma_prawna: '', osoba_kontaktowa: '', branza: '',
  addr_street: '', addr_house: '', addr_flat: '', addr_postal: '', addr_city: '', wojewodztwo: '', powiat: '', gmina: '',
  owner_id: '', notatka: '', is_critical: false, critical_note: '',
})
watch(() => props.initial, (v) => {
  if (!v) return
  for (const k of Object.keys(s)) {
    const val = v[k]
    if (k === 'is_critical') s[k] = !!val
    else if (val !== null && val !== undefined) s[k] = String(val)
  }
}, { immediate: true })

const orgLike = computed(() => !!dict.value?.types.find(t => t.value === s.type)?.org_like)
const view = (f: string) => props.fieldView?.[f] !== false
const ro = (f: string) => props.fieldEdit !== undefined && f in props.fieldEdit && props.fieldEdit[f] === false

const LABELS: Record<string, string> = {
  type: 'Typ', status: 'Status', imie: 'Imię', nazwisko: 'Nazwisko', imie_nazwisko: orgLike.value ? 'Nazwa' : 'Imię i nazwisko',
  email: 'E-mail', telefon: 'Telefon', strona_www: 'Strona WWW', organizacja: 'Organizacja', stanowisko: 'Stanowisko',
  nip: 'NIP', krs: 'KRS', regon: 'REGON', forma_prawna: 'Forma prawna', osoba_kontaktowa: 'Osoba kontaktowa', branza: 'Branża',
  addr_street: 'Ulica', addr_house: 'Nr domu', addr_flat: 'Nr lokalu', addr_postal: 'Kod pocztowy', addr_city: 'Miejscowość',
  wojewodztwo: 'Województwo', owner_id: 'Opiekun', notatka: 'Notatka wewnętrzna', critical_note: 'Dlaczego krytyczny', pesel: 'PESEL',
}

function submit() {
  const out: Record<string, unknown> = {}
  for (const [k, v] of Object.entries(s)) {
    if (!view(k) || ro(k)) continue
    if (orgLike.value && ['imie', 'nazwisko'].includes(k)) continue
    out[k] = v
  }
  if (!orgLike.value && (s.imie || s.nazwisko)) out.imie_nazwisko = `${s.imie} ${s.nazwisko}`.trim()
  emit('submit', out)
}
</script>

<template>
  <form novalidate class="space-y-6" @submit.prevent="submit">
    <FormErrorSummary :errors="errors" :labels="LABELS" :message="message" />

    <UCard as="section" aria-labelledby="cf-h1">
      <template #header><h2 id="cf-h1" class="text-lg font-semibold text-highlighted">Podstawowe dane</h2></template>
      <div class="grid gap-4 sm:grid-cols-2">
        <div class="flex flex-col gap-1">
          <label for="f-type" class="text-sm font-medium text-highlighted">Typ kartoteki</label>
          <select id="f-type" v-model="s.type" name="type" class="min-h-11 rounded-md border border-accented bg-default px-3">
            <option v-for="t in dict?.types" :key="t.value" :value="t.value">{{ t.label }}</option>
          </select>
        </div>
        <div class="flex flex-col gap-1">
          <label for="f-status" class="text-sm font-medium text-highlighted">Status</label>
          <select id="f-status" v-model="s.status" name="status" class="min-h-11 rounded-md border border-accented bg-default px-3"
                  :aria-invalid="!!errors.status" :aria-describedby="errors.status ? 'e-status' : undefined">
            <option value="">— domyślny —</option>
            <option v-for="o in dict?.statuses" :key="o.value" :value="o.value">{{ o.label }}</option>
          </select>
          <p v-if="errors.status" id="e-status" class="text-sm text-error">{{ errors.status }}</p>
        </div>

        <template v-if="!orgLike && view('imie_nazwisko')">
          <UFormField label="Imię" name="imie" :error="errors.imie" required>
            <UInput v-model="s.imie as string" name="imie" autocomplete="off" class="w-full" :disabled="ro('imie_nazwisko')" />
          </UFormField>
          <UFormField label="Nazwisko" name="nazwisko" :error="errors.nazwisko || errors.imie_nazwisko" required>
            <UInput v-model="s.nazwisko as string" name="nazwisko" autocomplete="off" class="w-full" :disabled="ro('imie_nazwisko')" />
          </UFormField>
        </template>
        <UFormField v-else-if="view('imie_nazwisko')" label="Nazwa organizacji" name="imie_nazwisko" :error="errors.imie_nazwisko" required class="sm:col-span-2">
          <UInput v-model="s.imie_nazwisko as string" name="imie_nazwisko" autocomplete="off" class="w-full" :disabled="ro('imie_nazwisko')" />
        </UFormField>

        <div class="flex flex-col gap-1">
          <label for="f-owner" class="text-sm font-medium text-highlighted">Opiekun</label>
          <select id="f-owner" v-model="s.owner_id" name="owner_id" class="min-h-11 rounded-md border border-accented bg-default px-3">
            <option value="">— bez opiekuna —</option>
            <option v-for="o in dict?.owners" :key="o.id" :value="String(o.id)">{{ o.name }}</option>
          </select>
        </div>
      </div>
    </UCard>

    <UCard as="section" aria-labelledby="cf-h2">
      <template #header><h2 id="cf-h2" class="text-lg font-semibold text-highlighted">Kontakt</h2></template>
      <div class="grid gap-4 sm:grid-cols-2">
        <UFormField v-if="view('email')" label="E-mail" name="email" :error="errors.email" help="np. jan.kowalski@feer.org.pl">
          <UInput v-model="s.email as string" name="email" type="email" autocomplete="off" class="w-full" :disabled="ro('email')" />
        </UFormField>
        <UFormField v-if="view('telefon')" label="Telefon" name="telefon" :error="errors.telefon">
          <UInput v-model="s.telefon as string" name="telefon" type="tel" autocomplete="off" class="w-full" :disabled="ro('telefon')" />
        </UFormField>
        <UFormField v-if="view('strona_www')" label="Strona WWW" name="strona_www" :error="errors.strona_www">
          <UInput v-model="s.strona_www as string" name="strona_www" type="url" class="w-full" :disabled="ro('strona_www')" />
        </UFormField>
        <template v-if="!orgLike">
          <UFormField v-if="view('organizacja')" label="Organizacja (pracodawca)" name="organizacja" :error="errors.organizacja">
            <UInput v-model="s.organizacja as string" name="organizacja" class="w-full" :disabled="ro('organizacja')" />
          </UFormField>
          <UFormField v-if="view('stanowisko')" label="Stanowisko" name="stanowisko" :error="errors.stanowisko">
            <UInput v-model="s.stanowisko as string" name="stanowisko" class="w-full" :disabled="ro('stanowisko')" />
          </UFormField>
        </template>
      </div>
    </UCard>

    <UCard as="section" v-if="orgLike" aria-labelledby="cf-h3">
      <template #header><h2 id="cf-h3" class="text-lg font-semibold text-highlighted">Dane rejestrowe</h2></template>
      <div class="grid gap-4 sm:grid-cols-3">
        <UFormField v-if="view('nip')" label="NIP" name="nip" :error="errors.nip" help="10 cyfr">
          <UInput v-model="s.nip as string" name="nip" inputmode="numeric" class="w-full" :disabled="ro('nip')" />
        </UFormField>
        <UFormField v-if="view('regon')" label="REGON" name="regon" :error="errors.regon" help="9 albo 14 cyfr">
          <UInput v-model="s.regon as string" name="regon" inputmode="numeric" class="w-full" :disabled="ro('regon')" />
        </UFormField>
        <UFormField v-if="view('krs')" label="KRS" name="krs" :error="errors.krs" help="10 cyfr">
          <UInput v-model="s.krs as string" name="krs" inputmode="numeric" class="w-full" :disabled="ro('krs')" />
        </UFormField>
        <UFormField label="Forma prawna" name="forma_prawna" :error="errors.forma_prawna">
          <UInput v-model="s.forma_prawna as string" name="forma_prawna" class="w-full" />
        </UFormField>
        <UFormField label="Osoba kontaktowa" name="osoba_kontaktowa" :error="errors.osoba_kontaktowa">
          <UInput v-model="s.osoba_kontaktowa as string" name="osoba_kontaktowa" class="w-full" />
        </UFormField>
        <UFormField v-if="view('branza')" label="Branża" name="branza" :error="errors.branza">
          <UInput v-model="s.branza as string" name="branza" class="w-full" :disabled="ro('branza')" />
        </UFormField>
      </div>
    </UCard>

    <UCard as="section" v-if="view('adres')" aria-labelledby="cf-h4">
      <template #header><h2 id="cf-h4" class="text-lg font-semibold text-highlighted">Adres</h2></template>
      <div class="grid gap-4 sm:grid-cols-6">
        <UFormField label="Ulica" name="addr_street" :error="errors.addr_street" class="sm:col-span-3">
          <UInput v-model="s.addr_street as string" name="addr_street" class="w-full" :disabled="ro('adres')" />
        </UFormField>
        <UFormField label="Nr domu" name="addr_house" :error="errors.addr_house" class="sm:col-span-2">
          <UInput v-model="s.addr_house as string" name="addr_house" class="w-full" :disabled="ro('adres')" />
        </UFormField>
        <UFormField label="Nr lokalu" name="addr_flat" :error="errors.addr_flat">
          <UInput v-model="s.addr_flat as string" name="addr_flat" class="w-full" :disabled="ro('adres')" />
        </UFormField>
        <UFormField label="Kod pocztowy" name="addr_postal" :error="errors.addr_postal" help="np. 00-950" class="sm:col-span-2">
          <UInput v-model="s.addr_postal as string" name="addr_postal" class="w-full" :disabled="ro('adres')" />
        </UFormField>
        <UFormField label="Miejscowość" name="addr_city" :error="errors.addr_city" class="sm:col-span-4">
          <UInput v-model="s.addr_city as string" name="addr_city" class="w-full" :disabled="ro('adres')" />
        </UFormField>
        <div class="flex flex-col gap-1 sm:col-span-3">
          <label for="f-woj" class="text-sm font-medium text-highlighted">Województwo</label>
          <select id="f-woj" v-model="s.wojewodztwo" name="wojewodztwo" class="min-h-11 rounded-md border border-accented bg-default px-3" :disabled="ro('adres')">
            <option value="">—</option>
            <option v-for="w in dict?.wojewodztwa" :key="w" :value="w">{{ w }}</option>
          </select>
        </div>
      </div>
    </UCard>

    <UCard as="section" aria-labelledby="cf-h5">
      <template #header><h2 id="cf-h5" class="text-lg font-semibold text-highlighted">Dodatkowe</h2></template>
      <div class="grid gap-4">
        <UFormField v-if="view('notatka')" label="Notatka wewnętrzna" name="notatka" :error="errors.notatka" help="Widoczna tylko w CRM.">
          <UTextarea v-model="s.notatka as string" name="notatka" :rows="4" class="w-full" :disabled="ro('notatka')" />
        </UFormField>
        <UCheckbox v-model="s.is_critical as boolean" name="is_critical" label="Kontakt krytyczny operacyjnie"
                   description="Np. bank, dostawca łącza, hosting — wyróżniany na listach." />
        <UFormField v-if="s.is_critical" label="Dlaczego krytyczny" name="critical_note" :error="errors.critical_note">
          <UInput v-model="s.critical_note as string" name="critical_note" class="w-full" />
        </UFormField>
      </div>
    </UCard>

    <div class="flex flex-wrap gap-3">
      <UButton type="submit" :label="submitLabel" icon="i-lucide-save" :loading="busy" />
      <slot name="cancel" />
    </div>
  </form>
</template>
