<?php
/**
 * modules/smart_cards/partials/application_form.php — pola rozbudowanego wniosku o kartę,
 * wspólne dla „Wnioski o karty” (składanie w imieniu osoby) i „Moja karta” (za siebie).
 * Sekcje jak na wydruku (A–F). Stan formularza w Alpine (scAppForm): rodzaj wniosku
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
    ];
    $pickedZones = array_map('intval', (array)($old['zones'] ?? []));
    $p = $ctx['prefix'] ?? 'af';
    ?>
<div x-data="scAppForm(<?= h(json_encode($state, JSON_UNESCAPED_UNICODE)) ?>)" class="tw-space-y-5">
  <fieldset class="af-sec">
    <legend><span class="af-sec__n">A</span> Rodzaj wniosku</legend>
    <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-2 tw-gap-2">
      <?php foreach (SCARD_APP_TYPES as $k => $t): ?>
      <label class="f-check zone-opt"><input type="radio" name="type" value="<?= h($k) ?>" x-model="type"<?= $k === $state['type'] ? ' checked' : '' ?>> <span><?= h($t['label']) ?></span></label>
      <?php endforeach; ?>
    </div>
    <div class="tw-mt-3" x-show="replaces.includes(type)" x-cloak>
      <label for="<?= $p ?>_repl" class="f-label">Dotychczasowa karta</label>
      <select id="<?= $p ?>_repl" name="replaced_card_id" x-model.number="replaced" class="f-input" aria-describedby="<?= $p ?>_repl_h">
        <option value="0">— nie wskazano / brak w ewidencji —</option>
        <template x-for="c in cards" :key="c.id"><option :value="c.id" x-text="c.label" :selected="c.id === replaced"></option></template>
      </select>
      <p id="<?= $p ?>_repl_h" class="f-help" x-show="type === 'lost'">Po zatwierdzeniu wskazana karta zostanie trwale oznaczona jako zgubiona. Jeśli zgubienie jeszcze nie zostało zgłoszone — zgłoś je od razu administratorowi, żeby zablokował kartę.</p>
    </div>
  </fieldset>

  <fieldset class="af-sec">
    <legend><span class="af-sec__n">B</span> Dane osoby, dla której ma być wydana karta</legend>
    <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-2 tw-gap-3">
      <?php if ($users !== null): ?>
      <div class="sm:tw-col-span-2"><label for="<?= $p ?>_user" class="f-label">Osoba <span aria-hidden="true" style="color:#b91c1c">*</span></label>
        <select id="<?= $p ?>_user" name="user_id" required class="f-input" x-model.number="userId" @change="loadDefaults()">
          <option value="">— wybierz —</option>
          <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>"<?= $state['userId'] === (int)$u['id'] ? ' selected' : '' ?>><?= h($u['name'] . ' — ' . $u['email']) ?></option><?php endforeach; ?>
        </select></div>
      <?php endif; ?>
      <div><label for="<?= $p ?>_unit" class="f-label">Jednostka organizacyjna</label>
        <input id="<?= $p ?>_unit" name="org_unit" x-model="f.org_unit" maxlength="190" class="f-input" autocomplete="organization"></div>
      <div><label for="<?= $p ?>_job" class="f-label">Stanowisko / funkcja</label>
        <input id="<?= $p ?>_job" name="job_title" x-model="f.job_title" maxlength="190" class="f-input" autocomplete="organization-title"></div>
      <div><label for="<?= $p ?>_tel" class="f-label">Telefon</label>
        <input id="<?= $p ?>_tel" name="phone" type="tel" x-model="f.phone" maxlength="40" class="f-input" autocomplete="tel"></div>
      <div><label for="<?= $p ?>_mail" class="f-label">E-mail</label>
        <input id="<?= $p ?>_mail" name="email" type="email" x-model="f.email" maxlength="190" class="f-input" autocomplete="email"></div>
    </div>
  </fieldset>

  <fieldset class="af-sec">
    <legend><span class="af-sec__n">C</span> Wnioskowany zakres</legend>
    <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-3 tw-gap-3">
      <div><label for="<?= $p ?>_tpl" class="f-label">Wzór karty</label>
        <select id="<?= $p ?>_tpl" name="template_id" x-model.number="tplId" class="f-input">
          <option value="0">— bez preferencji (domyślny) —</option>
          <?php foreach ($templates as $t): ?><option value="<?= (int)$t['id'] ?>"<?= $state['tplId'] === (int)$t['id'] ? ' selected' : '' ?>><?= h($t['name']) ?><?= !empty($t['design']['photo']) ? ' (ze zdjęciem)' : '' ?></option><?php endforeach; ?>
        </select></div>
      <div><label for="<?= $p ?>_from" class="f-label">Ważność od</label>
        <input id="<?= $p ?>_from" type="date" name="valid_from" value="<?= h($old['valid_from'] ?? date('Y-m-d')) ?>" class="f-input"></div>
      <div><label for="<?= $p ?>_to" class="f-label">Ważność do</label>
        <input id="<?= $p ?>_to" type="date" name="valid_to" value="<?= h($old['valid_to'] ?? '') ?>" class="f-input" aria-describedby="<?= $p ?>_to_h"
               min="<?= h(date('Y-m-d', strtotime('+1 day'))) ?>" max="<?= h(date('Y-m-d', strtotime('+' . SCARD_MAX_VALIDITY_YEARS . ' years'))) ?>">
        <p id="<?= $p ?>_to_h" class="f-help">Puste — standardowo <?= SCARD_DEFAULT_VALIDITY_YEARS ?> lata.</p></div>
    </div>
    <p class="f-label tw-mt-3">Wnioskowane strefy dostępu</p>
    <?php if (!$zones): ?><p class="f-help">Brak zdefiniowanych stref — o zakresie dostępu zdecyduje administrator.</p><?php endif; ?>
    <div class="tw-grid tw-grid-cols-1 sm:tw-grid-cols-2 tw-gap-2">
      <?php foreach ($zones as $z): ?>
      <label class="f-check zone-opt"><input type="checkbox" name="zones[]" value="<?= (int)$z['id'] ?>"<?= in_array((int)$z['id'], $pickedZones, true) ? ' checked' : '' ?>>
        <span><span class="sc-lvl l<?= (int)$z['security_level'] ?>">L<?= (int)$z['security_level'] ?></span> <?= h($z['zone_name']) ?>
          <span class="sub" x-show="inherited(<?= (int)$z['id'] ?>)" x-cloak>w standardzie wybranego wzoru</span></span></label>
      <?php endforeach; ?>
    </div>
  </fieldset>

  <fieldset class="af-sec">
    <legend><span class="af-sec__n">D</span> Uzasadnienie i akceptacja przełożonego</legend>
    <div class="tw-space-y-3">
      <div><label for="<?= $p ?>_reason" class="f-label">Uzasadnienie <span aria-hidden="true" style="color:#b91c1c">*</span></label>
        <textarea id="<?= $p ?>_reason" name="reason" rows="3" required minlength="5" maxlength="2000" class="f-input" placeholder="np. rozpoczęcie pracy w biurze, potrzebny dostęp do magazynu sprzętu"><?= h($old['reason'] ?? '') ?></textarea></div>
      <div><label for="<?= $p ?>_sup" class="f-label">Przełożony akceptujący</label>
        <select id="<?= $p ?>_sup" name="supervisor_user_id" class="f-input" aria-describedby="<?= $p ?>_sup_h">
          <option value="0">— nie wskazano —</option>
          <?php foreach ($ctx['supervisors'] as $u): ?><option value="<?= (int)$u['id'] ?>"<?= (int)($old['supervisor_user_id'] ?? 0) === (int)$u['id'] ? ' selected' : '' ?>><?= h($u['name']) ?></option><?php endforeach; ?>
        </select>
        <p id="<?= $p ?>_sup_h" class="f-help">Na wydruku wniosku jest miejsce na podpis przełożonego.</p></div>
    </div>
  </fieldset>

  <fieldset class="af-sec">
    <legend><span class="af-sec__n">E</span> Zdjęcie posiadacza</legend>
    <label for="<?= $p ?>_photo" class="f-label">Zdjęcie legitymacyjne (JPG/PNG, do 3 MB) <span x-show="photoRequired" x-cloak style="color:#b91c1c">— wymagane dla wybranego wzoru</span></label>
    <input id="<?= $p ?>_photo" type="file" name="photo" accept="image/jpeg,image/png" class="f-input" :required="photoRequired" aria-describedby="<?= $p ?>_photo_h">
    <p id="<?= $p ?>_photo_h" class="f-help">Twarz na wprost, jasne tło, min. 200×200 px. Zdjęcie trafi tylko na kartę i do akt wniosku.</p>
  </fieldset>

  <fieldset class="af-sec">
    <legend><span class="af-sec__n">F</span> Oświadczenia</legend>
    <div class="tw-space-y-2">
      <label class="f-check"><input type="checkbox" name="consent_responsibility" value="1" required<?= !empty($old['consent_responsibility']) ? ' checked' : '' ?>> <span><?= h(SCARD_CONSENTS['responsibility']) ?></span></label>
      <label class="f-check"><input type="checkbox" name="consent_rodo" value="1" required<?= !empty($old['consent_rodo']) ? ' checked' : '' ?>> <span><?= h(SCARD_CONSENTS['rodo']) ?></span></label>
    </div>
  </fieldset>
</div>
<?php }

/** Skrypt komponentu formularza — dołączany raz na stronę. */
function scard_application_form_js(): void { ?>
<style>
.scm .af-sec { margin: 0; padding: 0; }
.scm .af-sec > legend { display: flex; align-items: center; gap: .5rem; font-size: .9rem; font-weight: 700; margin-bottom: .6rem; padding: 0; }
.scm .af-sec__n { display: inline-flex; width: 1.5rem; height: 1.5rem; align-items: center; justify-content: center; border-radius: 6px; background: #e2e8f0; color: #0f172a; font-size: .75rem; }
</style>
<script>
function scAppForm(s) {
  // getterów nie da się przenieść przez Object.assign/spread — stan dopisujemy do obiektu z getterami
  const o = {
    get tpl() { return this.templates.find(t => t.id === this.tplId) || this.templates.find(t => t.def) || null; },
    get photoRequired() { return !!(this.tpl && this.tpl.photo); },
    inherited(id) { return !!(this.tpl && this.tpl.zones.includes(id)); },
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
