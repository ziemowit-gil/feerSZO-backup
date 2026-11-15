<?php
/**
 * modules/helpdeskClaude/view/wizard.php — kreator wbudowany w helpdesk/new.php (?type=claude); Alpine, 4 kroki.
 * Zmienne: $errors, $old (ostatni POST). Formularz idzie na new.php z _wizard=claude.
 * Pola różnych typów mają te same nazwy (np. claude_email), więc nieaktywny typ jest w <fieldset disabled> i nie jest wysyłany.
 */
$types = hdcTypes();
$oldType = (string)($old['type'] ?? '');
$val = fn(string $k) => (string)($old['f'][$k] ?? '');
$reqVal = fn(string $k) => (string)($old[$k] ?? '');
$initStep = $errors ? 4 : 1;
?>
<style>
.hdc-wrap { max-width: 860px; }
.hdc-steps { display: flex; gap: 0; margin: 0 0 1.25rem; padding: 0; list-style: none; }
.hdc-steps li { flex: 1; padding: .55rem .75rem; border-bottom: 3px solid #e1dfdd; font-size: .85rem; color: #605e5c; }
.hdc-steps li[aria-current="step"] { border-color: #0f6cbd; color: #242424; font-weight: 600; }
.hdc-steps li.done { border-color: #107c10; color: #242424; }
.hdc-type { display: flex; gap: .85rem; align-items: flex-start; width: 100%; text-align: left; padding: .9rem 1rem; margin: 0 0 .6rem;
  border: 1px solid #8a8886; background: #fff; cursor: pointer; }
.hdc-type:hover { background: #f3f2f1; }
.hdc-type:focus-within { outline: 2px solid #0f6cbd; outline-offset: 1px; }
.hdc-type.on { border: 2px solid #0f6cbd; background: #eff6fc; padding: calc(.9rem - 1px) calc(1rem - 1px); }
.hdc-type i { font-size: 1.4rem; color: #0f6cbd; }
.hdc-type b { display: block; color: #242424; }
.hdc-type span { font-size: .85rem; color: #605e5c; }
.hdc-card { background: #fff; border: 1px solid #e1dfdd; padding: 1.25rem 1.5rem; }
.hdc-wrap .form-label { font-weight: 600; font-size: .85rem; color: #242424; }
.hdc-wrap .form-control, .hdc-wrap .form-select { border: 1px solid #8a8886; border-radius: 2px; }
.hdc-wrap .form-control:focus, .hdc-wrap .form-select:focus { border-color: #0f6cbd; box-shadow: none; outline: 2px solid #0f6cbd; outline-offset: 0; }
.hdc-sum dt { font-weight: 600; color: #242424; font-size: .85rem; }
.hdc-sum dd { margin: 0 0 .6rem; color: #242424; white-space: pre-wrap; }
.hdc-note { background: #fff4ce; border-left: 4px solid #835c00; padding: .6rem .9rem; font-size: .88rem; color: #242424; }
</style>

<div class="hdc-wrap" x-data='hdcWizard(<?= json_encode(['step' => $initStep, 'type' => $oldType, 'url' => APP_URL . '/helpdesk/new.php?type=claude'], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>
  <?php if ($errors): ?>
  <div class="alert alert-danger py-2 mb-3" role="alert"><ul class="mb-0 ps-3">
    <?php foreach ($errors as $e) echo '<li style="font-size:.85rem">' . h($e) . '</li>'; ?>
  </ul></div>
  <?php endif; ?>

  <ol class="hdc-steps" aria-label="Kroki kreatora">
    <template x-for="(n, i) in names" :key="i">
      <li :aria-current="step === i + 1 ? 'step' : null" :class="step > i + 1 ? 'done' : ''"><span x-text="(i + 1) + '. ' + n"></span></li>
    </template>
  </ol>

  <form method="post" action="<?= APP_URL ?>/helpdesk/new.php?type=claude" enctype="multipart/form-data" novalidate x-ref="form" @submit="beforeSubmit($event)" class="hdc-card">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="_wizard" value="claude">

    <!-- Krok 1: kursant z kartoteki TI (opcjonalnie) -->
    <section x-show="step === 1" aria-labelledby="hdc-h1">
      <h2 id="hdc-h1" class="h5 mb-1">Kursant z kartoteki</h2>
      <p class="text-muted small mb-3">Wyszukaj kursanta, a kreator uzupełni jego dane i pokaże konta @edu, @stud oraz licencję Claude. Możesz pominąć ten krok.</p>
      <input type="hidden" name="client_id" :value="card ? card.id : ''">
      <div x-show="!card">
        <label class="form-label" for="hdc-q">Imię i nazwisko, e-mail albo telefon</label>
        <input id="hdc-q" type="search" class="form-control" autocomplete="off" x-model="q" @input.debounce.350ms="search()"
               role="combobox" aria-autocomplete="list" aria-controls="hdc-results" :aria-expanded="results.length > 0 ? 'true' : 'false'" placeholder="np. Kowalski Jan">
        <p class="form-text" x-show="searching" x-cloak>Szukam…</p>
        <p class="form-text" x-show="!searching && searched && !results.length" x-cloak>Nie znaleziono nikogo w kartotece. Wypełnisz dane ręcznie w kolejnych krokach.</p>
        <ul id="hdc-results" class="list-group mt-2" role="listbox" x-show="results.length" x-cloak>
          <template x-for="r in results" :key="r.id">
            <li class="list-group-item list-group-item-action p-0" role="option">
              <button type="button" class="btn btn-link text-start text-decoration-none w-100 px-3 py-2" @click="pick(r.id)">
                <strong x-text="r.name"></strong>
                <span class="d-block small text-muted" x-text="[r.groups || 'bez aktywnej grupy', r.email, r.phone].filter(Boolean).join(' · ')"></span>
              </button>
            </li>
          </template>
        </ul>
      </div>
      <div x-show="card" x-cloak class="border p-3" role="region" aria-label="Karta kursanta">
        <div class="d-flex justify-content-between align-items-start gap-2">
          <div><strong x-text="card?.name"></strong>
            <span class="d-block small text-muted" x-text="(card?.groups || 'bez aktywnej grupy') + (card?.minor ? ' · małoletni' : '')"></span></div>
          <button type="button" class="btn btn-sm btn-outline-secondary" @click="clearCard()">Zmień kursanta</button>
        </div>
        <dl class="hdc-sum mt-3 mb-0">
          <template x-for="l in (card?.lines || [])" :key="l[0]"><div><dt x-text="l[0]"></dt><dd x-text="l[1]"></dd></div></template>
        </dl>
        <a x-show="card?.link" :href="card?.link" target="_blank" rel="noopener" class="small">Otwórz w Ewidencji Uczestników</a>
        <p class="hdc-note mt-3 mb-0">Dane z kartoteki wpisano w kolejne kroki. Sprawdź je i popraw, jeśli kursant podaje inne.</p>
      </div>
      <p class="text-danger small mt-2" x-show="msg && step === 1" x-text="msg" role="alert"></p>
    </section>

    <!-- Krok 2: typ -->
    <section x-show="step === 2" aria-labelledby="hdc-h2">
      <h2 id="hdc-h2" class="h5 mb-3">Czego dotyczy problem?</h2>
      <?php foreach ($types as $k => $t): ?>
      <label class="hdc-type" :class="type === '<?= h($k) ?>' ? 'on' : ''">
        <input type="radio" name="type" value="<?= h($k) ?>" class="visually-hidden" x-model="type">
        <i class="bi <?= h($t['icon']) ?>" aria-hidden="true"></i>
        <span><b><?= h($t['label']) ?></b><span><?= h($t['desc']) ?></span></span>
      </label>
      <?php endforeach; ?>
      <p class="text-danger small mt-2" x-show="msg && step === 2" x-text="msg" role="alert"></p>
    </section>

    <!-- Krok 3: szczegóły wybranego typu -->
    <section x-show="step === 3" aria-labelledby="hdc-h3">
      <h2 id="hdc-h3" class="h5 mb-3">Szczegóły problemu</h2>
      <?php foreach ($types as $k => $t): ?>
      <fieldset data-type="<?= h($k) ?>" x-show="type === '<?= h($k) ?>'" :disabled="type !== '<?= h($k) ?>'" class="border-0 p-0 m-0">
        <legend class="visually-hidden"><?= h($t['label']) ?></legend>
        <?php foreach ($t['fields'] as $f): $fid = 'f-' . $k . '-' . $f['key']; ?>
        <div class="mb-3">
          <label class="form-label" for="<?= h($fid) ?>"><?= h($f['label']) ?><?= $f['required'] ? ' <span class="text-danger" aria-hidden="true">*</span>' : '' ?></label>
          <?php if ($f['type'] === 'select'): ?>
            <select id="<?= h($fid) ?>" name="f[<?= h($f['key']) ?>]" class="form-select" <?= $f['required'] ? 'required' : '' ?>>
              <option value="">Wybierz…</option>
              <?php foreach ($f['options'] as $o): ?><option value="<?= h($o) ?>" <?= $oldType === $k && $val($f['key']) === $o ? 'selected' : '' ?>><?= h($o) ?></option><?php endforeach; ?>
            </select>
          <?php elseif ($f['type'] === 'textarea'): ?>
            <textarea id="<?= h($fid) ?>" name="f[<?= h($f['key']) ?>]" rows="4" class="form-control" maxlength="4000" <?= $f['required'] ? 'required' : '' ?>><?= $oldType === $k ? h($val($f['key'])) : '' ?></textarea>
          <?php else: ?>
            <input id="<?= h($fid) ?>" type="<?= $f['type'] === 'email' ? 'email' : 'text' ?>" name="f[<?= h($f['key']) ?>]" class="form-control" maxlength="300"
                   placeholder="<?= h($f['placeholder'] ?? '') ?>" value="<?= $oldType === $k ? h($val($f['key'])) : '' ?>" <?= $f['required'] ? 'required' : '' ?>>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </fieldset>
      <?php endforeach; ?>
      <div class="mb-1">
        <label class="form-label" for="hdc-files">Zrzuty ekranu <span class="text-muted fw-normal">(opcjonalnie)</span></label>
        <input id="hdc-files" name="attachments[]" type="file" class="form-control" multiple accept=".pdf,.doc,.docx,.xls,.xlsx,.jpg,.jpeg,.png,.gif,.zip,.txt,.csv">
        <div class="form-text">Maks. 10 MB na plik. Zrzut komunikatu błędu bardzo przyspiesza pomoc.</div>
      </div>
      <p class="text-danger small mt-2" x-show="msg && step === 3" x-text="msg" role="alert"></p>
    </section>

    <!-- Krok 4: zgłaszający -->
    <section x-show="step === 4" aria-labelledby="hdc-h4">
      <h2 id="hdc-h4" class="h5 mb-3">Kto zgłasza</h2>
      <div class="row g-3">
        <div class="col-md-6"><label class="form-label" for="r-name">Imię i nazwisko <span class="text-danger" aria-hidden="true">*</span></label>
          <input id="r-name" name="req_name" class="form-control" required maxlength="120" autocomplete="off" value="<?= h($reqVal('req_name')) ?>"></div>
        <div class="col-md-6"><label class="form-label" for="r-role">Zgłaszający to <span class="text-danger" aria-hidden="true">*</span></label>
          <select id="r-role" name="req_role" class="form-select" required>
            <option value="">Wybierz…</option>
            <?php foreach (hdcRoles() as $r): ?><option value="<?= h($r) ?>" <?= $reqVal('req_role') === $r ? 'selected' : '' ?>><?= h($r) ?></option><?php endforeach; ?>
          </select></div>
        <div class="col-md-6"><label class="form-label" for="r-email">E-mail do kontaktu <span class="text-danger" aria-hidden="true">*</span></label>
          <input id="r-email" type="email" name="req_email" class="form-control" required maxlength="200" autocomplete="off" value="<?= h($reqVal('req_email')) ?>">
          <div class="form-text">Najlepiej prywatny. Adres @edu przestanie działać 20.11.2026.</div></div>
        <div class="col-md-6"><label class="form-label" for="r-phone">Telefon <span class="text-danger" aria-hidden="true">*</span></label>
          <input id="r-phone" type="tel" name="req_phone" class="form-control" required maxlength="20" autocomplete="off" value="<?= h($reqVal('req_phone')) ?>">
          <div class="form-text">Wyślemy na niego SMS z potwierdzeniem. Dla małoletniego podaj telefon opiekuna.</div></div>
        <div class="col-md-6"><label class="form-label" for="r-group">Grupa lub kurs <span class="text-muted fw-normal">(opcjonalnie)</span></label>
          <input id="r-group" name="req_group" class="form-control" maxlength="120" value="<?= h($reqVal('req_group')) ?>"></div>
        <div class="col-md-6"><label class="form-label" for="r-pri">Priorytet</label>
          <select id="r-pri" name="priority" class="form-select">
            <?php foreach (HD_PRIORITIES as $pk => $p): ?><option value="<?= h($pk) ?>" <?= ($reqVal('priority') ?: ($types[$oldType]['priority'] ?? 'normalny')) === $pk ? 'selected' : '' ?>><?= h($p['label']) ?></option><?php endforeach; ?>
          </select>
          <div class="form-text">Migracja @edu na @stud domyślnie ma priorytet wysoki.</div></div>
      </div>
      <p class="text-danger small mt-2" x-show="msg && step === 4" x-text="msg" role="alert"></p>
    </section>

    <!-- Krok 5: podsumowanie -->
    <section x-show="step === 5" aria-labelledby="hdc-h5">
      <h2 id="hdc-h5" class="h5 mb-3">Sprawdź i wyślij</h2>
      <dl class="hdc-sum" x-html="summary"></dl>
      <p class="hdc-note mt-3 mb-0">Po zapisaniu zgłaszający dostanie SMS i e-mail z numerem zgłoszenia i linkiem do śledzenia. Tożsamość nie jest weryfikowana w kreatorze, więc operator potwierdzi ją przed zmianą dostępu.</p>
    </section>

    <div class="d-flex justify-content-between mt-4">
      <button type="button" class="btn btn-outline-secondary" x-show="step > 1" @click="back()"><i class="bi bi-arrow-left" aria-hidden="true"></i> Wstecz</button>
      <span x-show="step === 1"></span>
      <button type="button" class="btn btn-primary" x-show="step < 5" @click="next()">Dalej <i class="bi bi-arrow-right" aria-hidden="true"></i></button>
      <button type="submit" class="btn btn-primary" x-show="step === 5"><i class="bi bi-send" aria-hidden="true"></i> Zapisz zgłoszenie</button>
    </div>
  </form>
</div>

<script>
function hdcWizard(init) {
  const PRIO = <?= json_encode(array_map(fn($t) => $t['priority'], $types), JSON_UNESCAPED_UNICODE) ?>;
  const LABEL = <?= json_encode(array_map(fn($t) => $t['label'], $types), JSON_UNESCAPED_UNICODE) ?>;
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
  return {
    step: init.step, type: init.type, msg: '', summary: '', touchedPrio: false,
    q: '', results: [], searching: false, searched: false, card: null, url: init.url,
    names: ['Kursant', 'Typ', 'Szczegóły', 'Zgłaszający', 'Podsumowanie'],
    get prioDefault() { return PRIO[this.type] || ''; },
    init() {
      this.$watch('type', () => { this.msg = ''; const s = this.$refs.form.querySelector('[name=priority]'); if (s && !this.touchedPrio && PRIO[this.type]) s.value = PRIO[this.type]; });
      this.$refs.form.querySelector('[name=priority]')?.addEventListener('change', () => { this.touchedPrio = true; });
    },
    // Walidacja bieżącego kroku: wymagane pola widoczne w tym kroku (nieaktywne fieldsety są wyłączone, więc pomijane).
    valid() {
      if (this.step === 1) return true;   // kursant z kartoteki jest opcjonalny
      if (this.step === 2) { if (!this.type) { this.msg = 'Wybierz typ zgłoszenia.'; return false; } return true; }
      const sec = this.$refs.form.querySelectorAll('section')[this.step - 1];
      for (const el of sec.querySelectorAll('input:not([type=file]), select, textarea')) {
        if (el.disabled) continue;
        if (!el.checkValidity()) { this.msg = 'Uzupełnij poprawnie pole: ' + (el.labels?.[0]?.textContent.replace('*', '').trim() || el.name); el.focus(); return false; }
      }
      return true;
    },
    next() {
      this.msg = '';
      if (!this.valid()) return;
      this.step++;
      if (this.step === 5) this.buildSummary();
      window.scrollTo({ top: 0, behavior: 'smooth' });
    },
    back() { this.msg = ''; if (this.step > 1) this.step--; },
    async search() {
      const q = this.q.trim();
      if (q.length < 2) { this.results = []; this.searched = false; return; }
      this.searching = true;
      try {
        const r = await fetch(this.url + '&ajax=kursant&q=' + encodeURIComponent(q), { credentials: 'same-origin' });
        this.results = (await r.json()).items || [];
      } catch (e) { this.results = []; }
      this.searching = false; this.searched = true;
    },
    async pick(id) {
      this.msg = '';
      try {
        const r = await fetch(this.url + '&ajax=karta&id=' + id, { credentials: 'same-origin' });
        this.card = (await r.json()).card || null;
      } catch (e) { this.card = null; }
      if (!this.card) { this.msg = 'Nie udało się pobrać karty kursanta.'; return; }
      this.results = []; this.q = '';
      this.fill(this.card.prefill || {});
    },
    clearCard() { this.card = null; this.searched = false; },
    // Wpisuje dane z kartoteki w pola formularza (pola o tej samej nazwie w różnych typach dostają tę samą wartość); puste wartości pomija.
    fill(p) {
      const f = this.$refs.form;
      for (const k of ['edu_email', 'stud_email', 'claude_email']) {
        if (!p[k]) continue;
        f.querySelectorAll('[name="f[' + k + ']"]').forEach(el => { el.value = p[k]; });
      }
      for (const k of ['req_name', 'req_email', 'req_phone', 'req_role', 'req_group']) {
        const el = f.querySelector('[name="' + k + '"]');
        if (el && p[k]) el.value = p[k];
      }
    },
    buildSummary() {
      const f = this.$refs.form, rows = [];
      if (this.card) rows.push(['Kursant z kartoteki', this.card.name + (this.card.groups ? ' (' + this.card.groups + ')' : '')]);
      rows.push(['Typ problemu', LABEL[this.type]]);
      f.querySelectorAll('fieldset[data-type="' + this.type + '"] [name^="f["]').forEach(el => {
        if (el.value.trim()) rows.push([(el.labels?.[0]?.textContent.replace('*', '').trim() || el.name), el.value.trim()]);
      });
      for (const [n, l] of [['req_name', 'Zgłaszający'], ['req_role', 'Zgłaszający to'], ['req_email', 'E-mail do kontaktu'], ['req_phone', 'Telefon'], ['req_group', 'Grupa lub kurs'], ['priority', 'Priorytet']]) {
        const el = f.querySelector('[name=' + n + ']'); if (!el || !el.value) continue;
        rows.push([l, el.tagName === 'SELECT' ? el.options[el.selectedIndex].text : el.value]);
      }
      this.summary = rows.map(([k, v]) => '<dt>' + esc(k) + '</dt><dd>' + esc(v) + '</dd>').join('');
    },
    beforeSubmit(e) { if (this.step !== 5) { e.preventDefault(); this.next(); } },
  };
}
</script>
