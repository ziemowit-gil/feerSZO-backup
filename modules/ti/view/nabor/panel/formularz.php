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

<div x-data="formEditor(<?= h(json_encode(['form' => $form, 'types' => NABOR_FORM_CUSTOM_TYPES], $J)) ?>)" x-cloak>
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

          <!-- pola wbudowane kroku -->
          <template x-for="key in fieldsOf(n)" :key="key">
            <div class="fe-field" :class="!cfg.fields[key].visible && 'is-off'">
              <div class="d-flex gap-2 align-items-start">
                <div class="flex-grow-1">
                  <input class="fe-inline fe-label" x-model="cfg.fields[key].label" :aria-label="'Etykieta pola ' + cfg.fields[key].label">
                  <div class="fe-fake" aria-hidden="true" x-show="key !== 'equipment'"></div>
                  <div class="d-flex flex-wrap gap-1 my-1" x-show="key === 'equipment'" aria-hidden="true">
                    <template x-for="e in cfg.equipment" :key="e.key"><span class="badge text-bg-light border" x-text="'☐ ' + e.label"></span></template>
                  </div>
                  <input class="fe-inline fe-help" x-model="cfg.fields[key].help" placeholder="podpowiedź pod polem (opcjonalna)" :aria-label="'Podpowiedź pola ' + cfg.fields[key].label">
                </div>
                <div class="text-nowrap small" x-show="!cfg.fields[key].core">
                  <div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" role="switch" :id="'v_' + key" x-model="cfg.fields[key].visible" @change="if (!cfg.fields[key].visible) cfg.fields[key].required = false"><label class="form-check-label" :for="'v_' + key">widoczne</label></div>
                  <div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" role="switch" :id="'r_' + key" x-model="cfg.fields[key].required" :disabled="!cfg.fields[key].visible"><label class="form-check-label" :for="'r_' + key">wymagane</label></div>
                </div>
                <span class="small text-muted text-nowrap" x-show="cfg.fields[key].core" title="Pole kluczowe — zawsze wymagane"><i class="bi bi-lock"></i> wymagane</span>
              </div>
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

          <!-- pytania dodatkowe kroku -->
          <template x-for="(q, i) in cfg.custom" :key="q.id">
            <div class="fe-field border-primary" x-show="q.step == n" style="border-style:solid">
              <div class="d-flex gap-2 align-items-start">
                <div class="flex-grow-1">
                  <span class="fe-tag">Pytanie dodatkowe</span>
                  <input class="fe-inline fe-label" x-model="q.label" placeholder="Treść pytania" aria-label="Treść pytania dodatkowego">
                  <input class="fe-inline fe-help" x-model="q.help" placeholder="podpowiedź (opcjonalna)" aria-label="Podpowiedź pytania">
                  <div class="row g-2 mt-1 small">
                    <div class="col-sm-5"><label class="visually-hidden" :for="'qt' + q.id">Typ</label>
                      <select class="form-select form-select-sm" :id="'qt' + q.id" x-model="q.type"><template x-for="(l, t) in types" :key="t"><option :value="t" x-text="l"></option></template></select></div>
                    <div class="col-sm-4"><label class="visually-hidden" :for="'qs' + q.id">Krok</label>
                      <select class="form-select form-select-sm" :id="'qs' + q.id" x-model.number="q.step"><template x-for="s in [1,2,3,4]" :key="s"><option :value="s" x-text="'Krok ' + s + ': ' + cfg.steps[s]"></option></template></select></div>
                    <div class="col-sm-3 d-flex align-items-center"><div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" role="switch" :id="'qr' + q.id" x-model="q.required"><label class="form-check-label" :for="'qr' + q.id">wymagane</label></div></div>
                    <div class="col-12" x-show="q.type === 'select'"><label class="small" :for="'qo' + q.id">Opcje (każda w osobnym wierszu)</label>
                      <textarea class="form-control form-control-sm" rows="3" :id="'qo' + q.id" :value="(q.options || []).join('\n')" @input="q.options = $event.target.value.split('\n')"></textarea></div>
                  </div>
                </div>
                <div class="btn-group-vertical btn-group-sm">
                  <button type="button" class="btn btn-outline-secondary" @click="moveQ(i, -1)" title="W górę" aria-label="Przesuń pytanie w górę"><i class="bi bi-arrow-up"></i></button>
                  <button type="button" class="btn btn-outline-secondary" @click="moveQ(i, 1)" title="W dół" aria-label="Przesuń pytanie w dół"><i class="bi bi-arrow-down"></i></button>
                  <button type="button" class="btn btn-outline-danger" @click="cfg.custom.splice(i, 1); dirty = true" title="Usuń" aria-label="Usuń pytanie"><i class="bi bi-trash"></i></button>
                </div>
              </div>
            </div>
          </template>
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
    fieldsOf(n) { return Object.keys(this.cfg.fields).filter(k => this.cfg.fields[k].step == n); },
    addQ(n) { this.cfg.custom.push({ id: 'q' + Date.now().toString(36), step: n, type: 'text', label: '', help: '', required: false, options: [] }); this.dirty = true; },
    moveQ(i, d) { const j = i + d; if (j < 0 || j >= this.cfg.custom.length) return; const a = this.cfg.custom; [a[i], a[j]] = [a[j], a[i]]; this.dirty = true; },
  };
}
</script>
<?php include __DIR__ . '/_foot.php'; ?>
