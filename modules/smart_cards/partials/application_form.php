<?php
/**
 * modules/smart_cards/partials/application_form.php — pola rozbudowanego wniosku o kartę,
 * wspólne dla „Wnioski o karty” (składanie w imieniu osoby) i „Moja karta” (za siebie).
 * Kreator krok po kroku (sekcje A–F jak na wydruku) z podsumowaniem przed złożeniem. Stan formularza w Alpine (scAppForm): rodzaj wniosku
 * pokazuje wybór dotychczasowej karty, wzór ze zdjęciem — wymagane zdjęcie, wybór osoby
 * (tylko wersja administracyjna) dociąga dane kontaktowe z applications.php?defaults=ID.
 *
 * $ctx: users (null = wniosek za siebie), user_id, defaults (applicantDefaults), old (POST po błędzie),
 *       templates, zones, defaults_url (wersja administracyjna).
 */

function scard_application_fields(array $ctx): void {
    $old       = $ctx['old'] ?? [];
    $def       = $ctx['defaults'] ?? ['org_unit' => '', 'job_title' => '', 'phone' => '', 'email' => '', 'cards' => []];
    $users     = $ctx['users'] ?? null;
    $templates = $ctx['templates'];
    $zones     = $ctx['zones'];
    $val = fn(string $k) => (string)($old[$k] ?? ($def[$k] ?? ''));
    $state = [
        'type'      => (string)($old['type'] ?? 'new'),
        'tplId'     => (int)($old['template_id'] ?? 0),
        'userId'    => (int)($old['user_id'] ?? ($ctx['user_id'] ?? 0)),
        'replaced'  => (int)($old['replaced_card_id'] ?? 0),
        'cards'     => $def['cards'] ?? [],
        'f'         => ['org_unit' => $val('org_unit'), 'job_title' => $val('job_title'), 'phone' => $val('phone'), 'email' => $val('email')],
        'templates' => array_map(fn($t) => ['id' => (int)$t['id'], 'photo' => !empty($t['design']['photo']), 'def' => (bool)$t['is_default'], 'zones' => $t['zone_ids']], $templates),
        'replaces'  => array_keys(array_filter(SCARD_APP_TYPES, fn($t) => $t['replaces'])),
        'url'       => (string)($ctx['defaults_url'] ?? ''),
        'step'      => $old ? 6 : 1,   // po błędzie zapisu — od razu podsumowanie z komunikatem
        'steps'     => ['Rodzaj', 'Osoba', 'Zakres', 'Uzasadnienie', 'Zdjęcie', 'Podsumowanie'],
        'sum'       => [],
    ];
    $typeIco = ['new' => 'bi-credit-card-2-front', 'renewal' => 'bi-arrow-repeat', 'replacement' => 'bi-arrow-left-right', 'duplicate' => 'bi-files', 'lost' => 'bi-question-octagon'];
    $typeHint = ['new' => 'Osoba nie miała dotąd karty.', 'renewal' => 'Poprzednia karta straciła ważność.', 'replacement' => 'Zmiana danych, wzoru lub zakresu.',
                 'duplicate' => 'Karta uszkodzona lub zniszczona.', 'lost' => 'Karta zgubiona lub skradziona — zostanie unieważniona.'];
    $pickedZones = array_map('intval', (array)($old['zones'] ?? []));
    $p = $ctx['prefix'] ?? 'af';
    ?>
<div x-data="scAppForm(<?= h(json_encode($state, JSON_UNESCAPED_UNICODE)) ?>)" class="af-wiz">
  <ol class="af-steps" aria-label="Kroki wniosku">
    <template x-for="(lbl, i) in steps" :key="i">
      <li :class="{ 'is-on': step === i + 1, 'is-done': step > i + 1 }" :aria-current="step === i + 1 ? 'step' : null">
        <button type="button" @click="go(i + 1)" :disabled="i + 1 > maxStep">
          <span class="af-steps__n" x-text="step > i + 1 ? '✓' : i + 1"></span><span class="af-steps__l" x-text="lbl"></span>
        </button>
      </li>
    </template>
  </ol>
  <p class="tw-sr-only" aria-live="polite" x-text="'Krok ' + step + ' z ' + steps.length + ': ' + steps[step - 1]"></p>
  <fieldset class="af-sec" data-step="1" x-show="step === 1" x-cloak>
    <legend><span class="af-sec__n">A</span> Rodzaj wniosku</legend>
    <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-2 tw-gap-2">
      <?php foreach (SCARD_APP_TYPES as $k => $t): ?>
      <label class="af-tile" :class="{ 'is-on': type === '<?= h($k) ?>' }"><input type="radio" name="type" value="<?= h($k) ?>" x-model="type" class="tw-sr-only" data-sum="Rodzaj wniosku" data-sum-text="<?= h($t['label']) ?>"<?= $k === $state['type'] ? ' checked' : '' ?>>
        <i class="bi <?= h($typeIco[$k] ?? 'bi-card-text') ?>" aria-hidden="true"></i>
        <span class="af-tile__t"><?= h($t['label']) ?></span><span class="af-tile__h"><?= h($typeHint[$k] ?? '') ?></span></label>
      <?php endforeach; ?>
    </div>
    <div class="tw-mt-3" x-show="replaces.includes(type)" x-cloak>
      <label for="<?= $p ?>_repl" class="f-label">Dotychczasowa karta</label>
      <select id="<?= $p ?>_repl" name="replaced_card_id" data-sum="Dotychczasowa karta" x-model.number="replaced" class="f-input" aria-describedby="<?= $p ?>_repl_h">
        <option value="0">— nie wskazano / brak w ewidencji —</option>
        <template x-for="c in cards" :key="c.id"><option :value="c.id" x-text="c.label" :selected="c.id === replaced"></option></template>
      </select>
      <p id="<?= $p ?>_repl_h" class="f-help" x-show="type === 'lost'">Po zatwierdzeniu wskazana karta zostanie trwale oznaczona jako zgubiona. Jeśli zgubienie jeszcze nie zostało zgłoszone — zgłoś je od razu administratorowi, żeby zablokował kartę.</p>
    </div>
  </fieldset>

  <fieldset class="af-sec" data-step="2" x-show="step === 2" x-cloak>
    <legend><span class="af-sec__n">B</span> Dane osoby, dla której ma być wydana karta</legend>
    <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-2 tw-gap-3">
      <?php if ($users !== null): ?>
      <div class="sm:tw-col-span-2"><label for="<?= $p ?>_user" class="f-label">Osoba <span aria-hidden="true" style="color:#b91c1c">*</span></label>
        <select id="<?= $p ?>_user" name="user_id" data-sum="Osoba" required class="f-input" x-model.number="userId" @change="loadDefaults()">
          <option value="">— wybierz —</option>
          <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>"<?= $state['userId'] === (int)$u['id'] ? ' selected' : '' ?>><?= h($u['name'] . ' — ' . $u['email']) ?></option><?php endforeach; ?>
        </select></div>
      <?php endif; ?>
      <div><label for="<?= $p ?>_unit" class="f-label">Jednostka organizacyjna</label>
        <input id="<?= $p ?>_unit" name="org_unit" data-sum="Jednostka" x-model="f.org_unit" maxlength="190" class="f-input" autocomplete="organization"></div>
      <div><label for="<?= $p ?>_job" class="f-label">Stanowisko / funkcja</label>
        <input id="<?= $p ?>_job" name="job_title" data-sum="Stanowisko" x-model="f.job_title" maxlength="190" class="f-input" autocomplete="organization-title"></div>
      <div><label for="<?= $p ?>_tel" class="f-label">Telefon</label>
        <input id="<?= $p ?>_tel" name="phone" data-sum="Telefon" type="tel" x-model="f.phone" maxlength="40" class="f-input" autocomplete="tel"></div>
      <div><label for="<?= $p ?>_mail" class="f-label">E-mail</label>
        <input id="<?= $p ?>_mail" name="email" data-sum="E-mail" type="email" x-model="f.email" maxlength="190" class="f-input" autocomplete="email"></div>
    </div>
  </fieldset>

  <fieldset class="af-sec" data-step="3" x-show="step === 3" x-cloak>
    <legend><span class="af-sec__n">C</span> Wnioskowany zakres</legend>
    <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-3 tw-gap-3">
      <div><label for="<?= $p ?>_tpl" class="f-label">Wzór karty</label>
        <select id="<?= $p ?>_tpl" name="template_id" data-sum="Wzór karty" x-model.number="tplId" class="f-input">
          <option value="0">— bez preferencji (domyślny) —</option>
          <?php foreach ($templates as $t): ?><option value="<?= (int)$t['id'] ?>"<?= $state['tplId'] === (int)$t['id'] ? ' selected' : '' ?>><?= h($t['name']) ?><?= !empty($t['design']['photo']) ? ' (ze zdjęciem)' : '' ?></option><?php endforeach; ?>
        </select></div>
      <div><label for="<?= $p ?>_from" class="f-label">Ważność od</label>
        <input id="<?= $p ?>_from" type="date" name="valid_from" data-sum="Ważność od" value="<?= h($old['valid_from'] ?? date('Y-m-d')) ?>" class="f-input"></div>
      <div><label for="<?= $p ?>_to" class="f-label">Ważność do</label>
        <input id="<?= $p ?>_to" type="date" name="valid_to" data-sum="Ważność do" value="<?= h($old['valid_to'] ?? '') ?>" class="f-input" aria-describedby="<?= $p ?>_to_h"
               min="<?= h(date('Y-m-d', strtotime('+1 day'))) ?>" max="<?= h(date('Y-m-d', strtotime('+' . SCARD_MAX_VALIDITY_YEARS . ' years'))) ?>">
        <p id="<?= $p ?>_to_h" class="f-help">Puste — standardowo <?= SCARD_DEFAULT_VALIDITY_YEARS ?> lata.</p></div>
    </div>
    <p class="f-label tw-mt-3">Wnioskowane strefy dostępu</p>
    <?php if (!$zones): ?><p class="f-help">Brak zdefiniowanych stref — o zakresie dostępu zdecyduje administrator.</p><?php endif; ?>
    <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-2 tw-gap-2">
      <?php foreach ($zones as $z): ?>
      <label class="f-check zone-opt"><input type="checkbox" name="zones[]" data-sum="Strefy" value="<?= (int)$z['id'] ?>"<?= in_array((int)$z['id'], $pickedZones, true) ? ' checked' : '' ?>>
        <span><span class="sc-lvl l<?= (int)$z['security_level'] ?>">L<?= (int)$z['security_level'] ?></span> <?= h($z['zone_name']) ?>
          <span class="sub" x-show="inherited(<?= (int)$z['id'] ?>)" x-cloak>w standardzie wybranego wzoru</span></span></label>
      <?php endforeach; ?>
    </div>
  </fieldset>

  <fieldset class="af-sec" data-step="4" x-show="step === 4" x-cloak>
    <legend><span class="af-sec__n">D</span> Uzasadnienie i akceptacja przełożonego</legend>
    <div class="tw-space-y-3">
      <div><label for="<?= $p ?>_reason" class="f-label">Uzasadnienie <span aria-hidden="true" style="color:#b91c1c">*</span></label>
        <textarea id="<?= $p ?>_reason" name="reason" data-sum="Uzasadnienie" rows="3" required minlength="5" maxlength="2000" class="f-input" placeholder="np. rozpoczęcie pracy w biurze, potrzebny dostęp do magazynu sprzętu"><?= h($old['reason'] ?? '') ?></textarea></div>
      <div><label for="<?= $p ?>_sup" class="f-label">Przełożony akceptujący</label>
        <select id="<?= $p ?>_sup" name="supervisor_user_id" data-sum="Przełożony" class="f-input" aria-describedby="<?= $p ?>_sup_h">
          <option value="0">— nie wskazano —</option>
          <?php foreach ($ctx['supervisors'] as $u): ?><option value="<?= (int)$u['id'] ?>"<?= (int)($old['supervisor_user_id'] ?? 0) === (int)$u['id'] ? ' selected' : '' ?>><?= h($u['name']) ?></option><?php endforeach; ?>
        </select>
        <p id="<?= $p ?>_sup_h" class="f-help">Na wydruku wniosku jest miejsce na podpis przełożonego.</p></div>
    </div>
  </fieldset>

  <fieldset class="af-sec" data-step="5" x-show="step === 5" x-cloak>
    <legend><span class="af-sec__n">E</span> Zdjęcie posiadacza</legend>
    <label for="<?= $p ?>_photo" class="f-label">Zdjęcie legitymacyjne (JPG/PNG, do 3 MB) <span x-show="photoRequired" x-cloak style="color:#b91c1c">— wymagane dla wybranego wzoru</span></label>
    <input id="<?= $p ?>_photo" type="file" name="photo" data-sum="Zdjęcie" accept="image/jpeg,image/png" class="f-input" :required="photoRequired" aria-describedby="<?= $p ?>_photo_h">
    <p id="<?= $p ?>_photo_h" class="f-help">Twarz na wprost, jasne tło, min. 200×200 px. Zdjęcie trafi tylko na kartę i do akt wniosku.</p>
  </fieldset>

  <fieldset class="af-sec" data-step="6" x-show="step === 6" x-cloak>
    <legend><span class="af-sec__n">F</span> Podsumowanie i oświadczenia</legend>
    <dl class="af-sum">
      <template x-for="r in sum" :key="r[0]"><div><dt x-text="r[0]"></dt><dd x-text="r[1] || '—'"></dd></div></template>
    </dl>
    <div class="tw-space-y-2">
      <label class="f-check"><input type="checkbox" name="consent_responsibility" value="1" required<?= !empty($old['consent_responsibility']) ? ' checked' : '' ?>> <span><?= h(SCARD_CONSENTS['responsibility']) ?></span></label>
      <label class="f-check"><input type="checkbox" name="consent_rodo" value="1" required<?= !empty($old['consent_rodo']) ? ' checked' : '' ?>> <span><?= h(SCARD_CONSENTS['rodo']) ?></span></label>
    </div>
  </fieldset>
  <div class="af-nav">
    <button type="button" class="h-btn h-btn--ghost" x-show="step > 1" @click="go(step - 1)"><i class="bi bi-arrow-left" aria-hidden="true"></i> Wstecz</button>
    <span class="tw-flex-1"></span>
    <button type="button" class="h-btn h-btn--primary" x-show="step < steps.length" @click="next()">Dalej <i class="bi bi-arrow-right" aria-hidden="true"></i></button>
    <button type="submit" class="h-btn h-btn--primary" x-show="step === steps.length" x-cloak><i class="bi bi-send" aria-hidden="true"></i> Złóż wniosek</button>
  </div>
</div>
<?php }

/** Skrypt komponentu formularza — dołączany raz na stronę. */
function scard_application_form_js(): void { ?>
<style>
.scm .af-sec { margin: 0; padding: 0; }
.scm .af-sec > legend { display: flex; align-items: center; gap: .5rem; font-size: .9rem; font-weight: 700; margin-bottom: .6rem; padding: 0; }
.scm .af-steps { list-style: none; margin: 0 0 1.25rem; padding: 0; display: flex; flex-wrap: wrap; gap: 2px; }
.scm .af-steps li { flex: 1 1 0; min-width: 90px; }
.scm .af-steps button { width: 100%; display: flex; align-items: center; gap: .45rem; padding: .55rem .6rem; background: #f1f5f9; color: #475569; border: 0; border-bottom: 3px solid transparent; font-size: .78rem; font-weight: 600; text-align: left; cursor: pointer; }
.scm .af-steps button:disabled { cursor: default; opacity: .6; }
.scm .af-steps .is-done button { color: #0f172a; border-bottom-color: #94a3b8; }
.scm .af-steps .is-on button { background: var(--sc-brand); color: #fff; border-bottom-color: #0f172a; }
.scm .af-steps__n { display: inline-flex; width: 1.4rem; height: 1.4rem; flex-shrink: 0; align-items: center; justify-content: center; border-radius: 999px; background: rgba(255,255,255,.85); color: #0f172a; font-size: .72rem; }
.scm .af-steps .is-on .af-steps__n { background: #fff; color: var(--sc-brand); }
@media (max-width: 640px) { .scm .af-steps__l { display: none; } .scm .af-steps .is-on .af-steps__l { display: inline; } }
.scm .af-wiz .af-sec { min-height: 220px; }
.scm .af-tile { position: relative; display: flex; flex-direction: column; gap: .2rem; padding: .9rem 1rem .9rem 3.2rem; border: 2px solid var(--tz-line); border-radius: 12px; cursor: pointer; background: #fff; }
.scm .af-tile > i { position: absolute; left: 1rem; top: .95rem; font-size: 1.35rem; color: var(--sc-brand); }
.scm .af-tile:hover { border-color: #94a3b8; }
.scm .af-tile.is-on { border-color: var(--sc-brand); background: #eff6ff; box-shadow: inset 0 0 0 1px var(--sc-brand); }
.scm .af-tile:focus-within { outline: 2px solid var(--sc-brand); outline-offset: 2px; }
.scm .af-tile__t { font-weight: 700; font-size: .9rem; }
.scm .af-tile__h { font-size: .78rem; color: var(--tz-muted); }
.scm .af-sum { display: grid; grid-template-columns: 1fr; gap: 0; margin: 0 0 1rem; border: 1px solid var(--tz-line); border-radius: 12px; overflow: hidden; }
.scm .af-sum > div { display: grid; grid-template-columns: minmax(120px, 32%) 1fr; gap: 1rem; padding: .45rem .8rem; font-size: .85rem; }
.scm .af-sum > div:nth-child(odd) { background: #f8fafc; }
.scm .af-sum dt { color: var(--tz-muted); font-weight: 600; }
.scm .af-sum dd { margin: 0; white-space: pre-line; overflow-wrap: anywhere; }
.scm .af-nav { display: flex; align-items: center; gap: .5rem; margin-top: 1.25rem; padding-top: 1rem; border-top: 1px solid var(--tz-line); }
.scm .af-sec__n { display: inline-flex; width: 1.5rem; height: 1.5rem; align-items: center; justify-content: center; border-radius: 6px; background: #e2e8f0; color: #0f172a; font-size: .75rem; }
</style>
<script>
function scAppForm(s) {
  // getterów nie da się przenieść przez Object.assign/spread — stan dopisujemy do obiektu z getterami
  const o = {
    get tpl() { return this.templates.find(t => t.id === this.tplId) || this.templates.find(t => t.def) || null; },
    get photoRequired() { return !!(this.tpl && this.tpl.photo); },
    inherited(id) { return !!(this.tpl && this.tpl.zones.includes(id)); },
    maxStep: s.step,
    init() { if (this.step === this.steps.length) this.$nextTick(() => this.collect()); },
    // pola bieżącego kroku muszą przejść walidację przeglądarki, zanim przejdziemy dalej
    next() {
      const box = this.$root.querySelector('[data-step="' + this.step + '"]');
      for (const el of box ? box.querySelectorAll('input, select, textarea') : []) {
        if (!el.checkValidity()) { el.reportValidity(); return; }
      }
      this.go(this.step + 1);
    },
    go(n) {
      if (n < 1 || n > this.steps.length) return;
      this.step = n; this.maxStep = Math.max(this.maxStep, n);
      if (n === this.steps.length) this.collect();
      this.$nextTick(() => { const f = this.$root.querySelector('[data-step="' + n + '"] input:not(.tw-sr-only), [data-step="' + n + '"] select, [data-step="' + n + '"] textarea'); if (f) f.focus({ preventScroll: true }); this.$root.scrollIntoView({ block: 'nearest' }); });
    },
    // podsumowanie z pól oznaczonych data-sum (kolejność jak w formularzu, strefy zebrane w jedną pozycję)
    collect() {
      const out = new Map();
      for (const el of this.$root.querySelectorAll('[data-sum]')) {
        const k = el.dataset.sum; let v = '';
        if (el.type === 'radio') { if (!el.checked) { if (!out.has(k)) out.set(k, ''); continue; } v = el.dataset.sumText || el.value; }
        else if (el.type === 'checkbox') { v = el.checked ? el.closest('label').innerText.replace(/\s+/g, ' ').trim() : ''; if (out.get(k)) { if (v) out.set(k, out.get(k) + '; ' + v); continue; } }
        else if (el.type === 'file') v = el.files && el.files[0] ? el.files[0].name : '';
        else if (el.tagName === 'SELECT') v = el.value && el.value !== '0' ? el.options[el.selectedIndex].text : '';
        else if (el.type === 'date') v = el.value ? el.value.split('-').reverse().join('.') : '';
        else v = el.value.trim();
        if (el.closest('[x-show]') && el.closest('[x-show]').style.display === 'none' && !el.closest('[data-step]').isSameNode(el.closest('[x-show]'))) continue;
        out.set(k, v);
      }
      if (out.has('Strefy') && !out.get('Strefy')) out.set('Strefy', 'według wzoru');
      this.sum = [...out.entries()];
    },
    async loadDefaults() {
      if (!this.url || !this.userId) { this.cards = []; return; }
      try {
        const r = await fetch(this.url + this.userId, { headers: { 'Accept': 'application/json' } });
        if (!r.ok) return;
        const d = await r.json();
        this.cards = d.cards || []; this.replaced = 0;
        for (const k of ['org_unit', 'job_title', 'phone', 'email']) this.f[k] = d[k] || '';
      } catch (e) {}
    },
  };
  for (const k in s) o[k] = s[k];
  return o;
}
</script>
<?php }
