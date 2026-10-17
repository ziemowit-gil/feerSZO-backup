<?php
/**
 * modules/ti/view/nabor/public/zapisy.php — Nabór do grup TI: publiczny formularz zgłoszenia (5 kroków, Alpine.js + Tailwind) i strona podziękowania.
 * Zmienne: $org, $logo, $clause, $terms, $errors, $done, $doneMail, $init. Kontroler: NaborPublicController::form / submit.
 * Krok 3 potwierdza numer opiekuna kodem SMS (trasa nabor.sms → modules/weryfikacja_sms).
 */
$J = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?><!doctype html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#1d68f2">
<title>Zapisy na zajęcia — <?= h($org) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Ubuntu:wght@400;500;700&family=Montserrat:wght@700;800&display=swap">
<script src="https://cdn.tailwindcss.com"></script>
<script>tailwind.config = { prefix: 'tw-', theme: { extend: { colors: { feer: { DEFAULT: '#1d68f2', dark: '#164eb6', bg: '#edf3fe', line: '#d8e2f3' } } } } }</script>
<script src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js" defer></script>
<style type="text/tailwindcss">
  body { font-family: 'Ubuntu', ui-sans-serif, system-ui, sans-serif; }
  h1, h2, legend { font-family: 'Montserrat', 'Ubuntu', sans-serif; }
  [x-cloak] { display: none !important; }
  .zp-label { @apply tw-block tw-text-sm tw-font-medium tw-mb-1; }
  .zp-input { @apply tw-w-full tw-rounded-lg tw-border tw-border-feer-line tw-px-3 tw-py-2 tw-bg-white focus:tw-outline-none focus:tw-ring-4 focus:tw-ring-blue-200 focus:tw-border-feer; }
  .zp-help  { @apply tw-text-xs tw-text-gray-500 tw-mt-1; }
  .zp-btn-primary, .zp-btn-ghost { @apply tw-inline-flex tw-items-center tw-justify-center tw-gap-2 tw-rounded-xl tw-px-5 tw-py-2.5 tw-font-bold focus:tw-outline-none focus:tw-ring-4 focus:tw-ring-blue-300 disabled:tw-opacity-50 disabled:tw-cursor-not-allowed; }
  .zp-btn-primary { @apply tw-bg-feer tw-text-white hover:tw-bg-feer-dark; }
  .zp-btn-ghost   { @apply tw-bg-white tw-text-gray-800 tw-border tw-border-solid tw-border-gray-300 hover:tw-bg-gray-50; }
  .zp-pick { @apply tw-flex tw-gap-3 tw-items-start tw-rounded-xl tw-border tw-border-feer-line tw-p-4 tw-cursor-pointer hover:tw-border-feer; }
  .zp-pick-on { @apply tw-border-feer tw-ring-2 tw-ring-feer tw-bg-blue-50; }
  .zp-check { @apply tw-flex tw-gap-3 tw-items-start tw-py-2; }
  .zp-check input { @apply tw-mt-1 tw-h-5 tw-w-5 tw-shrink-0 tw-accent-[#1d68f2]; }
  .zp-sum dt { @apply tw-text-xs tw-uppercase tw-tracking-wide tw-text-gray-500 tw-mt-3; }
  .zp-sum dd { @apply tw-font-medium tw-whitespace-pre-line; }
</style>
</head>
<body class="tw-bg-feer-bg tw-text-gray-900">
<div class="tw-max-w-3xl tw-mx-auto tw-px-4 tw-py-8">
  <header class="tw-mb-6 tw-flex tw-items-center tw-gap-4">
    <?php if ($logo): ?><img src="<?= h($logo) ?>" alt="<?= h($org) ?>" class="tw-h-12 tw-bg-white tw-rounded-lg tw-p-1"><?php endif; ?>
    <div>
      <h1 class="tw-text-2xl tw-font-extrabold">Zapisy na zajęcia</h1>
      <p class="tw-text-gray-600"><?= h($org) ?></p>
    </div>
  </header>

<?php if ($done): ?>
  <main class="tw-bg-white tw-rounded-2xl tw-shadow-lg tw-p-8 tw-text-center">
    <div class="tw-text-5xl tw-mb-3" aria-hidden="true">📨</div>
    <h2 class="tw-text-xl tw-font-bold tw-mb-2">Dziękujemy! Zgłoszenie zostało zarejestrowane</h2>
    <p class="tw-text-gray-600">Numer zgłoszenia:</p>
    <p class="tw-text-3xl tw-font-extrabold tw-text-feer tw-my-3"><?= h($done) ?></p>
    <div class="tw-bg-amber-50 tw-border tw-border-amber-300 tw-rounded-xl tw-p-4 tw-text-left tw-mt-6" role="status">
      <p class="tw-font-bold tw-mb-1">Ostatni krok: potwierdź adres e-mail</p>
      <p>Na adres <strong><?= h($doneMail ?: 'podany w formularzu') ?></strong> wysłaliśmy link. Zgłoszenie trafi do rozpatrzenia dopiero po jego kliknięciu (link jest ważny <?= NABOR_CONFIRM_HOURS ?> godz.). Sprawdź też folder SPAM.</p>
    </div>
  </main>
<?php else: ?>
  <main x-data="naborForm(<?= h(json_encode($init, $J)) ?>)" x-cloak class="tw-bg-white tw-rounded-2xl tw-shadow-lg tw-p-5 sm:tw-p-8">
    <ol class="tw-flex tw-gap-1 tw-mb-6" aria-label="Kroki formularza">
      <template x-for="(t, i) in titles" :key="i">
        <li class="tw-flex-1">
          <div class="tw-h-1.5 tw-rounded-full" :class="i + 1 <= step ? 'tw-bg-feer' : 'tw-bg-gray-200'"></div>
          <span class="tw-hidden sm:tw-block tw-text-xs tw-mt-1" :class="i + 1 === step ? 'tw-font-bold tw-text-feer' : 'tw-text-gray-500'"
                :aria-current="i + 1 === step ? 'step' : null" x-text="(i + 1) + '. ' + t"></span>
        </li>
      </template>
    </ol>

    <?php if ($errors): ?>
    <div class="tw-bg-red-50 tw-border tw-border-red-300 tw-text-red-800 tw-rounded-xl tw-p-4 tw-mb-5" role="alert">
      <p class="tw-font-bold">Popraw zgłoszenie:</p>
      <ul class="tw-list-disc tw-pl-5"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
    </div>
    <?php endif; ?>
    <div x-show="err" class="tw-bg-red-50 tw-border tw-border-red-300 tw-text-red-800 tw-rounded-xl tw-p-3 tw-mb-5" role="alert" x-text="err"></div>

    <form method="post" action="<?= h(route('nabor.form')) ?>" novalidate @submit="if (!canSubmit()) $event.preventDefault()">
      <?= csrf_field() ?>
      <div class="tw-hidden" aria-hidden="true"><label>Strona www <input name="www" tabindex="-1" autocomplete="off"></label></div>
      <?= $clause['field'] ?? '' ?>

      <!-- Krok 1: zajęcia / termin -->
      <fieldset x-show="step === 1">
        <legend class="tw-text-lg tw-font-bold tw-mb-4" x-ref="h1" tabindex="-1">Wybierz zajęcia</legend>
        <div class="tw-grid tw-gap-3">
          <template x-for="c in courses" :key="c.id">
            <label class="zp-pick" :class="!f.is_custom_schedule && f.course_id == c.id && 'zp-pick-on'">
              <input type="radio" name="course_id" :value="c.id" x-model="f.course_id" @change="f.is_custom_schedule = false" class="tw-mt-1 tw-h-5 tw-w-5 tw-accent-[#1d68f2]">
              <span class="tw-flex-1">
                <span class="tw-flex tw-flex-wrap tw-justify-between tw-gap-2">
                  <span class="tw-font-bold" x-text="c.name"></span>
                  <span class="tw-text-xs tw-font-bold tw-rounded-full tw-px-2 tw-py-0.5"
                        :class="c.free === null ? 'tw-bg-gray-100' : (c.free > 0 ? 'tw-bg-green-100 tw-text-green-800' : 'tw-bg-amber-100 tw-text-amber-800')"
                        x-text="c.free === null ? 'bez limitu miejsc' : (c.free > 0 ? 'wolne miejsca: ' + c.free + ' z ' + c.max : 'brak miejsc — lista rezerwowa')"></span>
                </span>
                <span class="tw-block tw-text-sm tw-text-gray-600" x-text="[c.category, c.age, c.location].filter(Boolean).join(' · ')"></span>
                <span class="tw-block tw-text-sm" x-show="c.schedule" x-text="c.schedule"></span>
                <span class="tw-block tw-text-sm tw-text-gray-600" x-show="c.price" x-text="'Opłata: ' + c.price"></span>
              </span>
            </label>
          </template>
          <label class="zp-pick" :class="f.is_custom_schedule && 'zp-pick-on'">
            <input type="checkbox" name="is_custom_schedule" value="1" x-model="f.is_custom_schedule" @change="if (f.is_custom_schedule) f.course_id = ''" class="tw-mt-1 tw-h-5 tw-w-5 tw-accent-[#1d68f2]">
            <span><span class="tw-font-bold">Termin do ustalenia</span>
              <span class="tw-block tw-text-sm tw-text-gray-600">Żaden termin nie pasuje? Podaj dyspozycyjność — odezwiemy się, gdy zbierzemy grupę.</span></span>
          </label>
        </div>
        <div x-show="f.is_custom_schedule" class="tw-mt-5 tw-grid tw-gap-4 tw-bg-feer-bg tw-rounded-xl tw-p-4">
          <fieldset><legend class="zp-label">Preferowane dni</legend>
            <div class="tw-flex tw-flex-wrap tw-gap-x-4">
              <template x-for="(l, k) in days" :key="k"><label class="zp-check"><input type="checkbox" name="preferred_days[]" :value="k" x-model="f.preferred_days"><span x-text="l"></span></label></template>
            </div></fieldset>
          <fieldset><legend class="zp-label">Preferowane pory</legend>
            <div class="tw-flex tw-flex-wrap tw-gap-x-4">
              <template x-for="(l, k) in slots" :key="k"><label class="zp-check"><input type="checkbox" name="preferred_time_slots[]" :value="k" x-model="f.preferred_time_slots"><span x-text="l"></span></label></template>
            </div></fieldset>
          <div><label class="zp-label" for="zp-na">Uwagi do dyspozycyjności</label>
            <textarea id="zp-na" name="notes_availability" rows="3" class="zp-input" x-model="f.notes_availability" placeholder="np. wtorki po 15:00, poza okresem ferii"></textarea></div>
        </div>
      </fieldset>

      <!-- Krok 2: uczestnik -->
      <fieldset x-show="step === 2" class="tw-grid tw-gap-4">
        <legend class="tw-text-lg tw-font-bold tw-mb-4" x-ref="h2" tabindex="-1">Dane uczestnika</legend>
        <div><label class="zp-label" for="zp-pn">Imię i nazwisko *</label><input id="zp-pn" name="participant_name" class="zp-input" x-model="f.participant_name" autocomplete="off" required></div>
        <div class="tw-grid sm:tw-grid-cols-2 tw-gap-4">
          <div><label class="zp-label" for="zp-pp">PESEL lub data urodzenia *</label><input id="zp-pp" name="participant_birth_date_or_pesel" class="zp-input" x-model="f.participant_birth_date_or_pesel" inputmode="numeric" aria-describedby="zp-pp-h" required>
            <p id="zp-pp-h" class="zp-help">11 cyfr PESEL albo data w formacie RRRR-MM-DD.</p></div>
          <div><label class="zp-label" for="zp-pc">Szkoła i klasa</label><input id="zp-pc" name="participant_school_class" class="zp-input" x-model="f.participant_school_class" placeholder="np. SP 12, kl. 4b"></div>
        </div>
        <div><label class="zp-label" for="zp-pm">Uwagi medyczne i dostępnościowe</label>
          <textarea id="zp-pm" name="participant_medical_notes" rows="4" class="zp-input" x-model="f.participant_medical_notes" aria-describedby="zp-pm-h"></textarea>
          <p id="zp-pm-h" class="zp-help">Alergie, leki, choroby przewlekłe, potrzeby wynikające z niepełnosprawności — wszystko, co pomoże zapewnić bezpieczeństwo i dostosować zajęcia.</p></div>
      </fieldset>

      <!-- Krok 3: opiekun -->
      <fieldset x-show="step === 3" class="tw-grid tw-gap-4">
        <legend class="tw-text-lg tw-font-bold tw-mb-4" x-ref="h3" tabindex="-1">Rodzic / opiekun prawny</legend>
        <div><label class="zp-label" for="zp-gn">Imię i nazwisko *</label><input id="zp-gn" name="guardian_name" class="zp-input" x-model="f.guardian_name" autocomplete="name" required></div>
        <div class="tw-grid sm:tw-grid-cols-2 tw-gap-4">
          <div><label class="zp-label" for="zp-ge">E-mail *</label><input id="zp-ge" type="email" name="guardian_email" class="zp-input" x-model="f.guardian_email" autocomplete="email" aria-describedby="zp-ge-h" required>
            <p id="zp-ge-h" class="zp-help">Wyślemy na niego link potwierdzający — bez kliknięcia zgłoszenie nie zostanie rozpatrzone.</p></div>
          <div><label class="zp-label" for="zp-gp">Telefon komórkowy *</label>
            <div class="tw-flex tw-gap-2">
              <input id="zp-gp" type="tel" name="guardian_phone" class="zp-input" x-model="f.guardian_phone" @input="phoneChanged()" autocomplete="tel" inputmode="tel" required>
              <button type="button" class="zp-btn-ghost tw-whitespace-nowrap tw-text-sm" x-show="!isVerified()" @click="sendCode()" :disabled="busy || cooldown > 0"
                      x-text="cooldown > 0 ? 'Ponów za ' + cooldown + ' s' : (codeSent ? 'Wyślij ponownie' : 'Wyślij kod SMS')"></button>
            </div>
            <p class="tw-text-sm tw-text-green-700 tw-font-bold tw-mt-1" x-show="isVerified()" role="status">✓ Numer potwierdzony</p>
          </div>
        </div>
        <div x-show="codeSent && !isVerified()" class="tw-bg-feer-bg tw-rounded-xl tw-p-4">
          <label class="zp-label" for="zp-code">Kod z SMS-a</label>
          <div class="tw-flex tw-gap-2 tw-max-w-xs">
            <input id="zp-code" x-ref="code" class="zp-input tw-tracking-widest tw-text-center" x-model="code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" @keydown.enter.prevent="verifyCode()">
            <button type="button" class="zp-btn-primary tw-text-sm" @click="verifyCode()" :disabled="busy">Potwierdź</button>
          </div>
          <p class="zp-help" role="status" x-text="smsInfo"></p>
        </div>
        <div><label class="zp-label" for="zp-gs">Ulica i numer *</label><input id="zp-gs" name="guardian_street" class="zp-input" x-model="f.guardian_street" autocomplete="street-address" required></div>
        <div class="tw-grid tw-grid-cols-3 tw-gap-4">
          <div><label class="zp-label" for="zp-gz">Kod pocztowy *</label><input id="zp-gz" name="guardian_postal_code" class="zp-input" x-model="f.guardian_postal_code" autocomplete="postal-code" placeholder="00-000" required></div>
          <div class="tw-col-span-2"><label class="zp-label" for="zp-gc">Miejscowość *</label><input id="zp-gc" name="guardian_city" class="zp-input" x-model="f.guardian_city" autocomplete="address-level2" required></div>
        </div>
        <fieldset><legend class="zp-label">Zasady odbioru uczestnika po zajęciach *</legend>
          <template x-for="(l, k) in pickup" :key="k">
            <label class="zp-check"><input type="radio" name="pickup_type" :value="k" x-model="f.pickup_type"><span x-text="l"></span></label>
          </template>
        </fieldset>
        <div x-show="f.pickup_type === 'authorized_persons'"><label class="zp-label" for="zp-ap">Osoby upoważnione do odbioru *</label>
          <textarea id="zp-ap" name="authorized_persons_details" rows="3" class="zp-input" x-model="f.authorized_persons_details" placeholder="Imię i nazwisko, stopień pokrewieństwa, telefon lub nr dokumentu — każda osoba w osobnym wierszu"></textarea></div>
      </fieldset>

      <!-- Krok 4: zgody -->
      <fieldset x-show="step === 4">
        <legend class="tw-text-lg tw-font-bold tw-mb-4" x-ref="h4" tabindex="-1">Zgody i oświadczenia</legend>
        <label class="zp-check"><input type="checkbox" name="terms_accepted" value="1" x-model="f.terms_accepted">
          <span>Akceptuję <?= $terms !== '' ? '<a class="tw-text-feer tw-underline" href="' . h($terms) . '" target="_blank" rel="noopener">regulamin zajęć</a>' : 'regulamin zajęć' ?>. <strong>(wymagane)</strong></span></label>
        <label class="zp-check"><input type="checkbox" name="gdpr_accepted" value="1" x-model="f.gdpr_accepted">
          <span>Wyrażam zgodę na przetwarzanie danych osobowych moich i uczestnika w celu rekrutacji i organizacji zajęć, w tym danych o zdrowiu podanych w uwagach. <strong>(wymagane)</strong></span></label>
        <label class="zp-check"><input type="checkbox" name="image_consent" value="1" x-model="f.image_consent">
          <span>Zgadzam się na nieodpłatne wykorzystanie wizerunku uczestnika w materiałach informacyjnych organizacji. (dobrowolne)</span></label>
        <label class="zp-check"><input type="checkbox" name="marketing_consent" value="1" x-model="f.marketing_consent">
          <span>Chcę otrzymywać e-mailem informacje o kolejnych zajęciach i wydarzeniach. (dobrowolne)</span></label>
        <div class="tw-mt-4 tw-text-xs tw-text-gray-600 tw-bg-gray-50 tw-rounded-xl tw-p-4 tw-leading-relaxed">
          <?php if ($clause): ?>
            <details><summary class="tw-cursor-pointer tw-font-bold">Klauzula informacyjna</summary><div class="tw-mt-2"><?= $clause['html'] ?></div></details>
          <?php else: ?>
            Administratorem danych jest <?= h($org) ?>. Dane przetwarzamy w celu rekrutacji i organizacji zajęć (art. 6 ust. 1 lit. a, b i c oraz art. 9 ust. 2 lit. a RODO) przez okres zajęć i rozliczeń, a dane z dobrowolnych zgód — do ich wycofania. Masz prawo dostępu do danych, ich sprostowania, usunięcia, ograniczenia przetwarzania, przenoszenia, wycofania zgody w dowolnym momencie oraz skargi do Prezesa UODO. Zgody są zapisywane razem z adresem IP i czasem ich udzielenia.
          <?php endif; ?>
        </div>
      </fieldset>

      <!-- Krok 5: podsumowanie -->
      <section x-show="step === 5" aria-labelledby="zp-h5">
        <h2 id="zp-h5" class="tw-text-lg tw-font-bold tw-mb-2" x-ref="h5" tabindex="-1">Podsumowanie</h2>
        <dl class="zp-sum tw-grid sm:tw-grid-cols-2 tw-gap-x-6">
          <div><dt>Zajęcia</dt><dd x-text="courseLabel()"></dd></div>
          <div><dt>Uczestnik</dt><dd x-text="f.participant_name + '\n' + f.participant_birth_date_or_pesel + (f.participant_school_class ? ', ' + f.participant_school_class : '')"></dd></div>
          <div><dt>Opiekun</dt><dd x-text="f.guardian_name + '\n' + f.guardian_email + '\n' + f.guardian_phone"></dd></div>
          <div><dt>Adres</dt><dd x-text="f.guardian_street + '\n' + f.guardian_postal_code + ' ' + f.guardian_city"></dd></div>
          <div><dt>Odbiór</dt><dd x-text="pickup[f.pickup_type] || '—'"></dd></div>
          <div><dt>Zgody</dt><dd x-text="consentsLabel()"></dd></div>
          <div class="sm:tw-col-span-2" x-show="f.participant_medical_notes"><dt>Uwagi medyczne</dt><dd x-text="f.participant_medical_notes"></dd></div>
        </dl>
        <fieldset class="tw-mt-6" x-show="!f.is_custom_schedule">
          <legend class="zp-label">Forma płatności *</legend>
          <template x-for="(l, k) in payment" :key="k">
            <label class="zp-check"><input type="radio" name="payment_method" :value="k" x-model="f.payment_method"><span x-text="l"></span></label>
          </template>
          <p class="zp-help">To deklaracja — szczegóły płatności prześlemy po przyjęciu zgłoszenia.</p>
        </fieldset>
        <p class="tw-mt-4 tw-text-sm tw-text-gray-600" x-show="f.is_custom_schedule">Przy terminie do ustalenia płatność ustalimy razem z terminem.</p>
      </section>

      <div class="tw-flex tw-justify-between tw-gap-3 tw-mt-8 tw-pt-5 tw-border-t tw-border-feer-line">
        <button type="button" class="zp-btn-ghost" x-show="step > 1" @click="go(step - 1)">← Wstecz</button>
        <span x-show="step === 1"></span>
        <button type="button" class="zp-btn-primary" x-show="step < 5" @click="next()">Dalej →</button>
        <button type="submit" class="zp-btn-primary" x-show="step === 5">Wyślij zgłoszenie</button>
      </div>
    </form>
  </main>
<?php endif; ?>
  <footer class="tw-text-center tw-text-xs tw-text-gray-500 tw-mt-6"><?= h($org) ?><?= gdpr_clauses_footer_link() ?></footer>
</div>
<script>
function naborForm(init) {
  return {
    ...init, err: '', code: '', codeSent: false, busy: false, cooldown: 0, smsInfo: '',
    titles: ['Zajęcia', 'Uczestnik', 'Opiekun', 'Zgody', 'Podsumowanie'],
    norm(p) { let d = String(p || '').replace(/\D/g, ''); if (d.length === 9) d = '48' + d; return d; },
    isVerified() { return this.verified && this.verified === this.norm(this.f.guardian_phone); },
    phoneChanged() { if (!this.isVerified()) this.codeSent = false; },
    peselOrDate(v) {
      v = String(v).trim();
      if (/^\d{11}$/.test(v)) { const w = [1,3,7,9,1,3,7,9,1,3]; let s = 0; for (let i = 0; i < 10; i++) s += w[i] * +v[i]; return (10 - s % 10) % 10 === +v[10]; }
      return /^\d{4}-\d{2}-\d{2}$/.test(v) || /^\d{2}\.\d{2}\.\d{4}$/.test(v);
    },
    check(s) {
      const f = this.f;
      if (s === 1) {
        if (!f.is_custom_schedule && !f.course_id) return 'Wybierz zajęcia albo „Termin do ustalenia”.';
        if (f.is_custom_schedule && !f.preferred_days.length && !f.preferred_time_slots.length && !f.notes_availability.trim()) return 'Zaznacz preferowane dni lub pory albo opisz dyspozycyjność.';
      }
      if (s === 2) {
        if (f.participant_name.trim().length < 3) return 'Podaj imię i nazwisko uczestnika.';
        if (!this.peselOrDate(f.participant_birth_date_or_pesel)) return 'Podaj poprawny PESEL albo datę urodzenia (RRRR-MM-DD).';
      }
      if (s === 3) {
        if (f.guardian_name.trim().length < 3) return 'Podaj imię i nazwisko opiekuna.';
        if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(f.guardian_email)) return 'Podaj poprawny adres e-mail.';
        if (!/^48\d{9}$/.test(this.norm(f.guardian_phone))) return 'Podaj numer telefonu komórkowego (9 cyfr).';
        if (!this.isVerified()) return 'Potwierdź numer telefonu kodem SMS.';
        if (!f.guardian_street.trim() || !f.guardian_city.trim()) return 'Podaj adres zamieszkania.';
        if (!/^\d{2}-\d{3}$/.test(f.guardian_postal_code.trim())) return 'Podaj kod pocztowy w formacie 00-000.';
        if (!f.pickup_type) return 'Wybierz zasady odbioru uczestnika.';
        if (f.pickup_type === 'authorized_persons' && f.authorized_persons_details.trim().length < 5) return 'Wpisz osoby upoważnione do odbioru.';
      }
      if (s === 4 && (!f.terms_accepted || !f.gdpr_accepted)) return 'Zaznacz wymagane zgody (regulamin i RODO).';
      if (s === 5 && !f.is_custom_schedule && !f.payment_method) return 'Wybierz formę płatności.';
      return '';
    },
    go(s) { this.err = ''; this.step = s; this.$nextTick(() => { this.$refs['h' + s]?.focus(); window.scrollTo({ top: 0, behavior: 'smooth' }); }); },
    next() { this.err = this.check(this.step); if (!this.err) this.go(this.step + 1); },
    canSubmit() {
      for (let s = 1; s <= 5; s++) { const e = this.check(s); if (e) { this.err = e; if (s !== this.step) this.go(s); this.err = e; return false; } }
      return true;
    },
    async post(data) {
      const fd = new FormData(); fd.append('_csrf', this.csrf);
      Object.entries(data).forEach(([k, v]) => fd.append(k, v));
      const r = await fetch(this.smsUrl, { method: 'POST', body: fd, credentials: 'same-origin' });
      return r.json().catch(() => ({ ok: false, error: 'Błąd połączenia. Spróbuj ponownie.' }));
    },
    async sendCode() {
      this.err = ''; this.busy = true;
      const r = await this.post({ action: 'send', phone: this.f.guardian_phone });
      this.busy = false;
      if (!r.ok) { this.err = r.error; return; }
      this.codeSent = true; this.smsInfo = 'Wysłaliśmy SMS z 6-cyfrowym kodem (ważny 10 minut).';
      this.cooldown = 60; const t = setInterval(() => { if (--this.cooldown <= 0) clearInterval(t); }, 1000);
      this.$nextTick(() => this.$refs.code?.focus());
    },
    async verifyCode() {
      this.err = ''; this.busy = true;
      const r = await this.post({ action: 'verify', phone: this.f.guardian_phone, code: this.code });
      this.busy = false;
      if (!r.ok) { this.smsInfo = r.error; return; }
      this.verified = this.norm(this.f.guardian_phone); this.code = '';
    },
    courseLabel() {
      if (this.f.is_custom_schedule) {
        const d = this.f.preferred_days.map(k => this.days[k]), s = this.f.preferred_time_slots.map(k => this.slots[k]);
        return 'Termin do ustalenia' + (d.length ? '\nDni: ' + d.join(', ') : '') + (s.length ? '\nPory: ' + s.join(', ') : '');
      }
      const c = this.courses.find(c => c.id == this.f.course_id);
      return c ? c.name + (c.schedule ? '\n' + c.schedule : '') + (c.free === 0 ? '\n(lista rezerwowa)' : '') : '—';
    },
    consentsLabel() {
      const f = this.f, yn = v => v ? 'tak' : 'nie';
      return 'Regulamin: ' + yn(f.terms_accepted) + '\nRODO: ' + yn(f.gdpr_accepted) + '\nWizerunek: ' + yn(f.image_consent) + '\nMarketing: ' + yn(f.marketing_consent);
    },
  };
}
</script>
</body>
</html>
