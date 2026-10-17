<?php
/**
 * modules/ti/view/nabor/panel/formularz.php — edytor wizualny formularza naboru (kierownik).
 *
 * Każdy krok formularza to karta wyglądająca jak w formularzu: etykiety i podpowiedzi edytuje się w miejscu,
 * przełączniki „widoczne / wymagane” przy polach niekluczowych, zgody, lista sprzętu, pytania dodatkowe.
 * Obok podgląd prawdziwego formularza (iframe z tokenem podglądu — działa także, gdy nabór jest zamknięty).
 * Stan w Alpine, zapis jednym POST-em (pole config = JSON) → naborFormSave() sanityzuje.
 * Zmienne: $me, $form, $defaults, $previewUrl. Kontroler: NaborPanelController::formEditor / formSave.
 */
$title = 'Nabór — formularz';
$kierLabel = 'Nabór do grup';
$J = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
include __DIR__ . '/_head.php';
?>
<script src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js" defer></script>
<style>
  [x-cloak] { display: none !important; }
  .fe-step { border: 1px solid #b9c6d6; border-radius: .6rem; background: #fff; margin-bottom: 1rem; }
  .fe-step > header { display: flex; gap: .5rem; align-items: center; padding: .6rem .8rem; background: #dde6f0; border-radius: .6rem .6rem 0 0; }
  .fe-step > header .fe-no { background: #10335c; color: #fff; border-radius: 50%; width: 1.7rem; height: 1.7rem; display: grid; place-items: center; font-weight: 700; flex: none; }
  .fe-body { padding: .8rem; display: grid; gap: .6rem; }
  .fe-field { border: 1px dashed #b9c6d6; border-radius: .5rem; padding: .5rem .6rem; }
  .fe-field.is-off { opacity: .55; background: #f4f7fa; }
  .fe-inline { border: 0; border-bottom: 1px dashed transparent; background: transparent; width: 100%; padding: .1rem 0; font: inherit; }
  .fe-inline:hover, .fe-inline:focus { border-bottom-color: #10335c; outline: none; background: #f8fbff; }
  .fe-label { font-weight: 600; }
  .fe-help { font-size: .8rem; color: #5b6677; }
  .fe-fake { height: 1.9rem; border: 1px solid #d8e2f3; border-radius: .4rem; background: #fafcff; margin: .25rem 0; }
  .fe-tag { font-size: .7rem; text-transform: uppercase; letter-spacing: .04em; color: #5b6677; }
  .fe-preview { position: sticky; top: 1rem; }
  .fe-preview iframe { width: 100%; height: 78vh; border: 1px solid #b9c6d6; border-radius: .6rem; background: #edf3fe; }
</style>

<nav aria-label="breadcrumb"><ol class="breadcrumb small"><li class="breadcrumb-item"><a href="<?= h(route('nabor.index')) ?>">Nabór do grup</a></li><li class="breadcrumb-item active">Formularz</li></ol></nav>
<?= flash_html() ?>

<div x-data="formEditor(<?= h(json_encode(['form' => $form, 'types' => NABOR_FORM_CUSTOM_TYPES, 'ops' => NABOR_COND_OPS], $J)) ?>)" x-cloak>
<form method="post" action="<?= h(route('nabor.form.save')) ?>" @submit="dirty = false">
  <?= Router::csrfField() ?>
  <input type="hidden" name="config" :value="JSON.stringify(cfg)">
  <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
    <h4 class="mb-0 me-auto"><i class="bi bi-ui-checks text-primary"></i> Formularz naboru</h4>
    <span class="small text-warning-emphasis" x-show="dirty" role="status">Niezapisane zmiany</span>
    <button class="btn btn-sm btn-primary"><i class="bi bi-save"></i> Zapisz formularz</button>
    <button class="btn btn-sm btn-outline-secondary" name="reset" value="1" onclick="return confirm('Przywrócić domyślny formularz? Pytania dodatkowe zostaną usunięte.')">Przywróć domyślny</button>
  </div>
  <p class="small text-muted">Kliknij tekst, żeby go zmienić. Pola z kłódką są zawsze wymagane (od nich zależą weryfikacja SMS i e-mail, EZD i konto uczestnika). Zmiany widać w formularzu publicznym od razu po zapisie.</p>

  <div class="row g-3">
  <div class="col-xl-7" @input="dirty = true" @change="dirty = true">

    <!-- Nagłówek formularza -->
    <section class="fe-step" aria-label="Nagłówek formularza">
      <header><i class="bi bi-type-h1"></i><strong>Nagłówek i podziękowanie</strong></header>
      <div class="fe-body">
        <div><span class="fe-tag">Tytuł</span><input class="fe-inline fs-5 fw-bold" x-model="cfg.texts.title" aria-label="Tytuł formularza"></div>
        <div><span class="fe-tag">Wstęp nad formularzem (opcjonalny)</span><textarea class="fe-inline" rows="2" x-model="cfg.texts.intro" placeholder="np. Zapraszamy na zajęcia online dla uczniów z niepełnosprawnością wzroku…" aria-label="Wstęp"></textarea></div>
        <div><span class="fe-tag">Nagłówek podziękowania</span><input class="fe-inline fw-semibold" x-model="cfg.texts.thanks" aria-label="Nagłówek podziękowania"></div>
      </div>
    </section>

    <!-- Kroki 1–5 -->
    <template x-for="n in [1, 2, 3, 4, 5]" :key="n">
      <section class="fe-step" :aria-label="'Krok ' + n">
        <header>
          <span class="fe-no" x-text="n"></span>
          <input class="fe-inline fw-bold" style="max-width:12rem" x-model="cfg.steps[n]" :aria-label="'Nazwa kroku ' + n + ' w pasku postępu'">
          <span class="small text-muted">—</span>
          <input class="fe-inline" x-model="cfg.step_titles[n]" :aria-label="'Nagłówek kroku ' + n">
        </header>
        <div class="fe-body">
          <p class="fe-help mb-0" x-show="n === 1">Lista grup otwartych w naborze (z liczbą miejsc i pierwszym terminem) oraz „Termin do ustalenia” z preferencjami dni i pór — ustawiasz w „Grupy w naborze”.</p>

          <!-- elementy kroku: w krokach 2–3 w kolejności cfg.order (pola i pytania dodatkowe), w 1 i 4 pytania dodatkowe -->
          <template x-for="item in itemsOf(n)" :key="item">
            <div>
              <template x-if="!item.startsWith('q:')"><div x-data="{ key: item }">
            <div class="fe-field" :class="!cfg.fields[key].visible && 'is-off'">
              <div class="d-flex gap-2 align-items-start">
                <div class="flex-grow-1">
                  <input class="fe-inline fe-label" x-model="cfg.fields[key].label" :aria-label="'Etykieta pola ' + cfg.fields[key].label">
                  <div class="fe-fake" aria-hidden="true" x-show="key !== 'equipment'"></div>
                  <div class="d-flex flex-wrap gap-1 my-1" x-show="key === 'equipment'" aria-hidden="true">
                    <template x-for="e in cfg.equipment" :key="e.key"><span class="badge text-bg-light border" x-text="'☐ ' + e.label"></span></template>
                  </div>
                  <input class="fe-inline fe-help" x-model="cfg.fields[key].help" placeholder="podpowiedź pod polem (opcjonalna)" :aria-label="'Podpowiedź pola ' + cfg.fields[key].label">
                  <details x-show="!cfg.fields[key].core" class="mt-1 small" :open="!!cfg.fields[key].cond">
                    <summary class="text-primary" style="cursor:pointer"><i class="bi bi-diagram-2"></i> Pokaż tylko gdy… <span class="text-muted" x-text="condLabel(cfg.fields[key].cond)"></span></summary>
                    <div class="row g-1 mt-1">
                      <div class="col-sm-5"><label class="visually-hidden" :for="'cs' + key">Źródło warunku</label>
                        <select class="form-select form-select-sm" :id="'cs' + key" :value="cfg.fields[key].cond ? cfg.fields[key].cond.src : ''" @change="setCond(cfg.fields[key], $event.target.value)">
                          <option value="">— zawsze widoczne —</option>
                          <template x-for="o in condSources(key)" :key="o.v"><option :value="o.v" x-text="o.l" :selected="cfg.fields[key].cond && cfg.fields[key].cond.src === o.v"></option></template>
                        </select></div>
                      <template x-if="cfg.fields[key].cond">
                        <div class="col-sm-7 d-flex gap-1">
                          <label class="visually-hidden" :for="'co' + key">Warunek</label>
                          <select class="form-select form-select-sm" :id="'co' + key" x-model="cfg.fields[key].cond.op"><template x-for="(l, o) in ops" :key="o"><option :value="o" x-text="l"></option></template></select>
                          <template x-if="!['filled', 'empty'].includes(cfg.fields[key].cond.op)">
                            <div class="flex-grow-1">
                              <label class="visually-hidden" :for="'cv' + key">Wartość</label>
                              <template x-if="condValues(cfg.fields[key].cond.src).length"><select class="form-select form-select-sm" :id="'cv' + key" x-model="cfg.fields[key].cond.val"><option value="">—</option><template x-for="o in condValues(cfg.fields[key].cond.src)" :key="o.v"><option :value="o.v" x-text="o.l"></option></template></select></template>
                              <template x-if="!condValues(cfg.fields[key].cond.src).length"><input class="form-control form-control-sm" :id="'cv' + key" x-model="cfg.fields[key].cond.val" placeholder="wartość"></template>
                            </div>
                          </template>
                        </div>
                      </template>
                    </div>
                  </details>
                </div>
                <div class="text-nowrap small" x-show="!cfg.fields[key].core">
                  <div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" role="switch" :id="'v_' + key" x-model="cfg.fields[key].visible" @change="if (!cfg.fields[key].visible) cfg.fields[key].required = false"><label class="form-check-label" :for="'v_' + key">widoczne</label></div>
                  <div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" role="switch" :id="'r_' + key" x-model="cfg.fields[key].required" :disabled="!cfg.fields[key].visible"><label class="form-check-label" :for="'r_' + key">wymagane</label></div>
                </div>
                <span class="small text-muted text-nowrap" x-show="cfg.fields[key].core" title="Pole kluczowe — zawsze wymagane"><i class="bi bi-lock"></i> wymagane</span>
                <div class="btn-group-vertical btn-group-sm" x-show="n === 2 || n === 3">
                  <button type="button" class="btn btn-outline-secondary" @click="move(n, key, -1)" :aria-label="'Przesuń w górę: ' + cfg.fields[key].label"><i class="bi bi-arrow-up"></i></button>
                  <button type="button" class="btn btn-outline-secondary" @click="move(n, key, 1)" :aria-label="'Przesuń w dół: ' + cfg.fields[key].label"><i class="bi bi-arrow-down"></i></button>
                </div>
              </div>
            </div>
              </div></template>
              <template x-if="item.startsWith('q:') && qById(item.slice(2))"><div x-data="{ q: qById(item.slice(2)) }">
            <div class="fe-field border-primary" style="border-style:solid">
              <div class="d-flex gap-2 align-items-start">
                <div class="flex-grow-1">
                  <span class="fe-tag">Pytanie dodatkowe</span>
                  <input class="fe-inline fe-label" x-model="q.label" placeholder="Treść pytania" aria-label="Treść pytania dodatkowego">
                  <input class="fe-inline fe-help" x-model="q.help" placeholder="podpowiedź (opcjonalna)" aria-label="Podpowiedź pytania">
                  <div class="row g-2 mt-1 small">
                    <div class="col-sm-5"><label class="visually-hidden" :for="'qt' + q.id">Typ</label>
                      <select class="form-select form-select-sm" :id="'qt' + q.id" x-model="q.type"><template x-for="(l, t) in types" :key="t"><option :value="t" x-text="l"></option></template></select></div>
                    <div class="col-sm-4"><label class="visually-hidden" :for="'qs' + q.id">Krok</label>
                      <select class="form-select form-select-sm" :id="'qs' + q.id" :value="q.step" @change="setStep(q, +$event.target.value)"><template x-for="s in [1,2,3,4]" :key="s"><option :value="s" :selected="q.step == s" x-text="'Krok ' + s + ': ' + cfg.steps[s]"></option></template></select></div>
                    <div class="col-sm-3 d-flex align-items-center"><div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" role="switch" :id="'qr' + q.id" x-model="q.required"><label class="form-check-label" :for="'qr' + q.id">wymagane</label></div></div>
                    <div class="col-12" x-show="q.type === 'select'"><label class="small" :for="'qo' + q.id">Opcje (każda w osobnym wierszu)</label>
                      <textarea class="form-control form-control-sm" rows="3" :id="'qo' + q.id" :value="(q.options || []).join('\n')" @input="q.options = $event.target.value.split('\n')"></textarea></div>
                  </div>
                  <details class="mt-1 small" :open="!!q.cond">
                    <summary class="text-primary" style="cursor:pointer"><i class="bi bi-diagram-2"></i> Pokaż tylko gdy… <span class="text-muted" x-text="condLabel(q.cond)"></span></summary>
                    <div class="row g-1 mt-1">
                      <div class="col-sm-5"><label class="visually-hidden" :for="'cs' + q.id">Źródło warunku</label>
                        <select class="form-select form-select-sm" :id="'cs' + q.id" :value="q.cond ? q.cond.src : ''" @change="setCond(q, $event.target.value)">
                          <option value="">— zawsze widoczne —</option>
                          <template x-for="o in condSources('q:' + q.id)" :key="o.v"><option :value="o.v" x-text="o.l" :selected="q.cond && q.cond.src === o.v"></option></template>
                        </select></div>
                      <template x-if="q.cond">
                        <div class="col-sm-7 d-flex gap-1">
                          <label class="visually-hidden" :for="'co' + q.id">Warunek</label>
                          <select class="form-select form-select-sm" :id="'co' + q.id" x-model="q.cond.op"><template x-for="(l, o) in ops" :key="o"><option :value="o" x-text="l"></option></template></select>
                          <template x-if="!['filled', 'empty'].includes(q.cond.op)">
                            <div class="flex-grow-1">
                              <label class="visually-hidden" :for="'cv' + q.id">Wartość</label>
                              <template x-if="condValues(q.cond.src).length"><select class="form-select form-select-sm" :id="'cv' + q.id" x-model="q.cond.val"><option value="">—</option><template x-for="o in condValues(q.cond.src)" :key="o.v"><option :value="o.v" x-text="o.l"></option></template></select></template>
                              <template x-if="!condValues(q.cond.src).length"><input class="form-control form-control-sm" :id="'cv' + q.id" x-model="q.cond.val" placeholder="wartość"></template>
                            </div>
                          </template>
                        </div>
                      </template>
                    </div>
                  </details>
                </div>
                <div class="btn-group-vertical btn-group-sm">
                  <button type="button" class="btn btn-outline-secondary" @click="moveQItem(q, -1)" aria-label="Przesuń pytanie w górę"><i class="bi bi-arrow-up"></i></button>
                  <button type="button" class="btn btn-outline-secondary" @click="moveQItem(q, 1)" aria-label="Przesuń pytanie w dół"><i class="bi bi-arrow-down"></i></button>
                  <button type="button" class="btn btn-outline-danger" @click="removeQ(q)" aria-label="Usuń pytanie"><i class="bi bi-trash"></i></button>
                </div>
              </div>
            </div>
              </div></template>
            </div>
          </template>

          <!-- zgody (krok 4) -->
          <template x-if="n === 4">
            <div class="d-grid gap-2">
              <template x-for="(c, k) in cfg.consents" :key="k">
                <div class="fe-field" :class="!c.visible && 'is-off'">
                  <div class="d-flex gap-2 align-items-start">
                    <span aria-hidden="true">☐</span>
                    <textarea class="fe-inline flex-grow-1" rows="2" x-model="c.label" :aria-label="'Treść zgody ' + k"></textarea>
                    <span class="small text-muted text-nowrap" x-show="c.core"><i class="bi bi-lock"></i> wymagana</span>
                    <div class="form-check form-switch small text-nowrap" x-show="!c.core"><input class="form-check-input" type="checkbox" role="switch" :id="'c_' + k" x-model="c.visible"><label class="form-check-label" :for="'c_' + k">widoczna</label></div>
                  </div>
                </div>
              </template>
              <div><span class="fe-tag">Mikroklauzula RODO pod zgodami</span>
                <textarea class="fe-inline fe-help" rows="3" x-model="cfg.texts.clause" placeholder="Puste = klauzula z rejestru RODO (kontekst „TI — nabór do grup”) albo tekst wbudowany." aria-label="Mikroklauzula RODO"></textarea></div>
            </div>
          </template>

          <p class="fe-help mb-0" x-show="n === 5">Podsumowanie wpisanych danych, deklaracja płatności (pole wyżej) i wysyłka. Pytań dodatkowych nie dodaje się do podsumowania.</p>

          <div x-show="n <= 4"><button type="button" class="btn btn-sm btn-outline-primary" @click="addQ(n)"><i class="bi bi-plus-lg"></i> Dodaj pytanie w tym kroku</button></div>
        </div>
      </section>
    </template>

    <!-- Sprzęt do zajęć online -->
    <section class="fe-step" aria-label="Sprzęt do zajęć online">
      <header><i class="bi bi-laptop"></i><strong>Lista sprzętu do zajęć online</strong><span class="small text-muted ms-auto">pole „<span x-text="cfg.fields.equipment.label"></span>” w kroku 2</span></header>
      <div class="fe-body">
        <template x-for="(e, i) in cfg.equipment" :key="e.key">
          <div class="d-flex gap-2 align-items-center">
            <span aria-hidden="true">☐</span>
            <input class="fe-inline" x-model="e.label" :aria-label="'Pozycja sprzętu ' + (i + 1)">
            <button type="button" class="btn btn-sm btn-outline-danger" @click="cfg.equipment.splice(i, 1); dirty = true" aria-label="Usuń pozycję"><i class="bi bi-x-lg"></i></button>
          </div>
        </template>
        <div><button type="button" class="btn btn-sm btn-outline-primary" @click="cfg.equipment.push({key: 'e' + Date.now().toString(36), label: 'Nowa pozycja'}); dirty = true"><i class="bi bi-plus-lg"></i> Dodaj pozycję</button></div>
      </div>
    </section>
  </div>

  <div class="col-xl-5">
    <div class="fe-preview">
      <div class="d-flex align-items-center gap-2 mb-1">
        <strong class="small">Podgląd formularza</strong>
        <span class="small text-muted me-auto">(po zapisie; działa też przy zamkniętym naborze)</span>
        <a class="btn btn-sm btn-outline-secondary" href="<?= h($previewUrl) ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right"></i> W nowej karcie</a>
      </div>
      <iframe src="<?= h($previewUrl) ?>" title="Podgląd formularza naboru"></iframe>
    </div>
  </div>
  </div>
</form>
</div>

<script>
function formEditor(init) {
  return {
    cfg: init.form, types: init.types, dirty: false,
    init() { window.addEventListener('beforeunload', e => { if (this.dirty) { e.preventDefault(); e.returnValue = ''; } }); },
    ops: init.ops,
    /** Elementy kroku: 2–3 wg cfg.order (pola + 'q:id'), pozostałe — pytania dodatkowe kroku (krok 5: pole płatności). */
    itemsOf(n) {
      if (n === 2 || n === 3) return this.cfg.order[n];
      const f = Object.keys(this.cfg.fields).filter(k => this.cfg.fields[k].step == n);
      return [...f, ...this.cfg.custom.filter(q => q.step == n).map(q => 'q:' + q.id)];
    },
    qById(id) { return this.cfg.custom.find(q => q.id === id); },
    addQ(n) {
      const q = { id: 'q' + Date.now().toString(36), step: n, type: 'text', label: '', help: '', required: false, options: [], cond: null };
      this.cfg.custom.push(q); if (n === 2 || n === 3) this.cfg.order[n].push('q:' + q.id); this.dirty = true;
    },
    removeQ(q) {
      this.cfg.custom = this.cfg.custom.filter(x => x !== q);
      [2, 3].forEach(n => this.cfg.order[n] = this.cfg.order[n].filter(k => k !== 'q:' + q.id)); this.dirty = true;
    },
    setStep(q, s) {
      [2, 3].forEach(n => this.cfg.order[n] = this.cfg.order[n].filter(k => k !== 'q:' + q.id));
      q.step = s; if (s === 2 || s === 3) this.cfg.order[s].push('q:' + q.id); this.dirty = true;
    },
    move(n, key, d) { const a = this.cfg.order[n], i = a.indexOf(key), j = i + d; if (i < 0 || j < 0 || j >= a.length) return; [a[i], a[j]] = [a[j], a[i]]; this.dirty = true; },
    moveQItem(q, d) {
      if (q.step == 2 || q.step == 3) return this.move(q.step, 'q:' + q.id, d);
      const a = this.cfg.custom, i = a.indexOf(q); let j = i + d;
      while (j >= 0 && j < a.length && a[j].step != q.step) j += d;
      if (j < 0 || j >= a.length) return; [a[i], a[j]] = [a[j], a[i]]; this.dirty = true;
    },
    /** Pola zależne: źródła warunku (pytania dodatkowe, sprzęt, termin do ustalenia) — bez samego elementu. */
    condSources(self) {
      const o = this.cfg.custom.filter(q => 'q:' + q.id !== self && q.label).map(q => ({ v: 'q:' + q.id, l: 'Pytanie: ' + q.label }));
      if (self !== 'equipment') o.push({ v: 'equipment', l: 'Sprzęt do zajęć online' });
      o.push({ v: 'is_custom_schedule', l: 'Wybrano „Termin do ustalenia”' });
      return o;
    },
    condValues(src) {
      if (src === 'equipment') return this.cfg.equipment.map(e => ({ v: e.key, l: e.label }));
      const q = src && src.startsWith('q:') ? this.qById(src.slice(2)) : null;
      if (q && q.type === 'select') return (q.options || []).filter(Boolean).map(o => ({ v: o, l: o }));
      if (q && q.type === 'checkbox') return [{ v: 'tak', l: 'zaznaczone' }];
      return [];
    },
    setCond(item, src) { item.cond = src ? { src, op: src === 'equipment' ? 'has' : (src === 'is_custom_schedule' ? 'filled' : 'eq'), val: '' } : null; this.dirty = true; },
    condLabel(c) {
      if (!c) return '';
      const s = this.condSources('').find(o => o.v === c.src); const v = (this.condValues(c.src).find(o => o.v === c.val) || {}).l || c.val;
      return '(' + (s ? s.l : '?') + ' ' + (this.ops[c.op] || '') + (['filled', 'empty'].includes(c.op) ? '' : ' „' + v + '”') + ')';
    },
  };
}
</script>
<?php include __DIR__ . '/_foot.php'; ?>
