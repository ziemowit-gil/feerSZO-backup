<?php
/**
 * modules/strategy_actions/view/form.php — formularz nowego działania i edycji (Metro).
 * Nazwy pól jak w dawnym strategy/actions/add.php (grant_id[], udzial_procent[], grant_kwota[], ind_*[]).
 * Zmienne: $row (wartości), $id (null = nowe), $errors, $users, $grants, $budgets (ActionsGrants::budgets bez tego działania).
 */
$navActive = $id ? '' : 'nowe';
$val = fn(string $k) => (string)($row[$k] ?? '');
$sel = fn(string $k, $v) => (string)($row[$k] ?? '') === (string)$v ? ' selected' : '';
$fnum = fn($v) => $v === null || $v === '' ? '' : rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');
$grantRows = array_map(fn($g) => ['grant_id' => (string)(int)$g['grant_id'], 'udzial' => $fnum($g['udzial_procent'] ?? null), 'kwota' => $fnum($g['kwota'] ?? null)], $row['grants'] ?? []);
$indRows = array_map(fn($i) => ['id' => (int)($i['id'] ?? 0), 'nazwa' => (string)$i['nazwa'], 'plan' => $fnum($i['wartosc_planowana'] ?? null), 'real' => $fnum($i['wartosc_realizowana'] ?? null)], $row['indicators'] ?? []);
$action = $id ? ActionsService::url($id . '/edycja') : ActionsService::url('nowe');
$json = fn($v) => h(json_encode($v, JSON_UNESCAPED_UNICODE));
include __DIR__ . '/_head.php';
?>
<nav aria-label="Ścieżka" class="small mb-2"><a href="<?= h(ActionsService::url()) ?>" class="text-decoration-none" style="color:var(--dz)">Działania</a> ›
  <?php if ($id): ?><a href="<?= h(ActionsService::url((string)$id)) ?>" class="text-decoration-none" style="color:var(--dz)">#<?= (int)$id ?></a> › <?php endif; ?>
  <span class="dz-muted"><?= $id ? 'Edycja' : 'Nowe działanie' ?></span></nav>
<div class="dz-top"><h1 class="dz-title"><?= $id ? 'Edycja działania' : 'Nowe działanie' ?></h1></div>

<?php if ($errors): ?>
<div class="dz-alert dz-alert--danger" role="alert" tabindex="-1" id="dz-errors">
  <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
  <div><b>Popraw formularz:</b><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
</div>
<script>document.addEventListener('DOMContentLoaded', () => document.getElementById('dz-errors')?.focus());</script>
<?php endif; ?>

<form method="post" action="<?= h($action) ?>" class="dz-form"
      x-data="dzForm({ forma: <?= $json($val('forma') ?: 'stacjonarne') ?>, cykl: <?= !empty($row['cykliczne']) ? 'true' : 'false' ?>,
                wlasne: <?= !empty($row['wlasne_dzialanie']) ? 'true' : 'false' ?>,
                grants: <?= $json($grantRows) ?>, inds: <?= $json($indRows) ?> })">
  <?= Router::csrfField() ?>

  <h2 class="dz-head">Podstawowe</h2>
  <div class="row-g">
    <div class="full">
      <label class="dz-lbl" for="f-nazwa">Nazwa działania *</label>
      <input id="f-nazwa" name="nazwa" class="dz-in" required maxlength="300" value="<?= h($val('nazwa')) ?>" autofocus>
    </div>
    <div>
      <label class="dz-lbl" for="f-typ">Typ</label>
      <select id="f-typ" name="typ" class="dz-in">
        <?php foreach (ActionsService::TYPES as $k => $t): ?><option value="<?= h($k) ?>"<?= $sel('typ', $k) ?>><?= h($t['label']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="dz-lbl" for="f-status">Status</label>
      <select id="f-status" name="status" class="dz-in">
        <?php foreach (ActionsService::STATUSES as $k => $s): ?><option value="<?= h($k) ?>"<?= $sel('status', $k) ?>><?= h($s['label']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="dz-lbl" for="f-koord">Koordynator</label>
      <select id="f-koord" name="koordynator_id" class="dz-in">
        <option value="">— brak —</option>
        <?php foreach ($users as $u): ?><option value="<?= (int)$u['id'] ?>"<?= $sel('koordynator_id', (int)$u['id']) ?>><?= h($u['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="dz-lbl" for="f-cel">Cel statutowy §6</label>
      <select id="f-cel" name="cel_statutowy" class="dz-in" aria-describedby="f-cel-h">
        <option value="">— brak —</option>
        <?php foreach (ActionsService::goals() as $k => $l): ?><option value="<?= (int)$k ?>"<?= $sel('cel_statutowy', $k) ?>><?= (int)$k ?>. <?= h($l) ?></option><?php endforeach; ?>
      </select>
      <small id="f-cel-h" class="dz-muted"><?= !empty($row['jrwa_symbol']) ? 'Segregator EZD: JRWA ' . h($row['jrwa_symbol']) : ($id || !$ezdOn ? 'Po zapisie powstaje pozycja JRWA w klasie celu (EZD).' : 'Po zapisie powstaje pozycja JRWA i koszulka w EZD Wirtualne biurko.') ?></small>
    </div>
    <div class="full">
      <label class="dz-lbl" for="f-opis">Opis</label>
      <textarea id="f-opis" name="opis" class="dz-in" rows="4"><?= h($val('opis')) ?></textarea>
    </div>
  </div>

  <h2 class="dz-head">Termin i forma</h2>
  <div class="row-g">
    <div>
      <label class="dz-lbl" for="f-od">Rozpoczęcie</label>
      <input id="f-od" type="date" name="data_od" class="dz-in" value="<?= h($val('data_od')) ?>">
    </div>
    <div>
      <label class="dz-lbl" for="f-do">Zakończenie</label>
      <input id="f-do" type="date" name="data_do" class="dz-in" value="<?= h($val('data_do')) ?>">
    </div>
    <div class="d-flex align-items-end gap-3 flex-wrap">
      <label class="dz-check"><input type="checkbox" name="cykliczne" value="1" x-model="cykl"> Działanie cykliczne</label>
    </div>
    <div x-show="cykl" x-cloak>
      <label class="dz-lbl" for="f-freq">Częstotliwość</label>
      <select id="f-freq" name="czestotliwosc" class="dz-in">
        <option value="">— wybierz —</option>
        <?php foreach (ActionsService::FREQS as $k => $l): ?><option value="<?= h($k) ?>"<?= $sel('czestotliwosc', $k) ?>><?= h($l) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="dz-lbl" for="f-forma">Forma</label>
      <select id="f-forma" name="forma" class="dz-in" x-model="forma">
        <?php foreach (ActionsService::FORMS as $k => $l): ?><option value="<?= h($k) ?>"><?= h($l) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div x-show="forma !== 'stacjonarne'" x-cloak>
      <label class="dz-lbl" for="f-link">Link do spotkania online</label>
      <input id="f-link" type="url" name="link_online" class="dz-in" value="<?= h($val('link_online')) ?>" placeholder="https://…">
    </div>
    <div x-show="forma !== 'online'">
      <label class="dz-lbl" for="f-lok">Miejsce</label>
      <input id="f-lok" name="lokalizacja" class="dz-in" value="<?= h($val('lokalizacja')) ?>" maxlength="250">
    </div>
  </div>

  <h2 class="dz-head">Dla uczestników</h2>
  <div class="row-g">
    <div>
      <label class="dz-lbl" for="f-kt">Nagłówek sekcji korzyści</label>
      <select id="f-kt" name="korzysci_tytul" class="dz-in">
        <?php foreach (ActionsService::BENEFIT_TITLES as $t): ?><option value="<?= h($t) ?>"<?= $sel('korzysci_tytul', $t) ?>><?= h($t) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="full">
      <label class="dz-lbl" for="f-korz">Korzyści (każda w nowej linii)</label>
      <textarea id="f-korz" name="korzysci" class="dz-in" rows="3"><?= h($val('korzysci')) ?></textarea>
    </div>
    <div class="full">
      <label class="dz-lbl" for="f-dla">Dla kogo (każda grupa w nowej linii)</label>
      <textarea id="f-dla" name="dla_kogo" class="dz-in" rows="3"><?= h($val('dla_kogo')) ?></textarea>
    </div>
  </div>

  <h2 class="dz-head">Finansowanie</h2>
  <label class="dz-check mb-2"><input type="checkbox" name="wlasne_dzialanie" value="1" x-model="wlasne"> Działanie własne (finansowane ze środków organizacji)</label>
  <div class="dz-scroll">
    <table class="dz-table" x-show="grants.length">
      <thead><tr><th scope="col">Grant</th><th scope="col" style="width:130px">Udział %</th><th scope="col" style="width:170px">Kwota</th><th scope="col" style="width:50px"><span class="visually-hidden">Usuń</span></th></tr></thead>
      <tbody>
        <template x-for="(g, i) in grants" :key="i">
          <tr>
            <td><select name="grant_id[]" class="dz-in" x-model="g.grant_id" :aria-label="'Grant ' + (i + 1)" :aria-describedby="'gb-' + i">
              <option value="">— wybierz grant —</option>
              <?php foreach ($grants as $gr): ?><option value="<?= (int)$gr['id'] ?>"><?= h($gr['nazwa']) ?><?= $gr['donator'] ? ' — ' . h($gr['donator']) : '' ?></option><?php endforeach; ?>
            </select>
            <small class="d-block mt-1" :id="'gb-' + i" x-show="budgetOf(g)" :style="overBy(g) > 0 ? 'color:var(--red);font-weight:600' : 'color:var(--muted)'" x-text="budgetText(g)" aria-live="polite"></small>
            <button type="button" class="dz-btn dz-btn--ghost dz-btn--sm mt-1" x-show="suggest(g) !== null" @click="g.kwota = String(suggest(g))" x-text="'Wstaw kwotę z udziału: ' + money(suggest(g), budgetOf(g) ? budgetOf(g).waluta : 'PLN')"></button></td>
            <td><input name="udzial_procent[]" class="dz-in" inputmode="decimal" x-model="g.udzial" :aria-label="'Udział procentowy grantu ' + (i + 1)"></td>
            <td><input name="grant_kwota[]" class="dz-in" inputmode="decimal" x-model="g.kwota" :aria-label="'Kwota z grantu ' + (i + 1)"></td>
            <td><button type="button" class="dz-btn dz-btn--danger dz-btn--sm" @click="grants.splice(i, 1)" :aria-label="'Usuń grant ' + (i + 1)"><i class="bi bi-x-lg" aria-hidden="true"></i></button></td>
          </tr>
        </template>
      </tbody>
    </table>
  </div>
  <button type="button" class="dz-btn dz-btn--ghost dz-btn--sm mt-2" @click="grants.push({grant_id: '', udzial: '', kwota: ''})"><i class="bi bi-plus-lg" aria-hidden="true"></i>Dodaj grant</button>
  <?php if (!$grants): ?><p class="dz-muted small mt-1">W rejestrze nie ma jeszcze grantów — <a href="<?= APP_URL ?>/grants/add.php">dodaj grant</a>.</p><?php endif; ?>

  <h2 class="dz-head">Wskaźniki</h2>
  <div class="dz-scroll">
    <table class="dz-table" x-show="inds.length">
      <thead><tr><th scope="col">Wskaźnik</th><th scope="col" style="width:150px">Plan</th><th scope="col" style="width:150px">Wykonanie</th><th scope="col" style="width:50px"><span class="visually-hidden">Usuń</span></th></tr></thead>
      <tbody>
        <template x-for="(w, i) in inds" :key="i">
          <tr>
            <td><input type="hidden" name="ind_id[]" :value="w.id"><input name="ind_nazwa[]" class="dz-in" x-model="w.nazwa" maxlength="200" :aria-label="'Nazwa wskaźnika ' + (i + 1)" placeholder="np. liczba uczestników"></td>
            <td><input name="ind_planowana[]" class="dz-in" inputmode="decimal" x-model="w.plan" :aria-label="'Wartość planowana wskaźnika ' + (i + 1)"></td>
            <td><input name="ind_realizowana[]" class="dz-in" inputmode="decimal" x-model="w.real" :aria-label="'Wartość zrealizowana wskaźnika ' + (i + 1)"></td>
            <td><button type="button" class="dz-btn dz-btn--danger dz-btn--sm" @click="inds.splice(i, 1)" :aria-label="'Usuń wskaźnik ' + (i + 1)"><i class="bi bi-x-lg" aria-hidden="true"></i></button></td>
          </tr>
        </template>
      </tbody>
    </table>
  </div>
  <button type="button" class="dz-btn dz-btn--ghost dz-btn--sm mt-2" @click="inds.push({id: 0, nazwa: '', plan: '', real: ''})"><i class="bi bi-plus-lg" aria-hidden="true"></i>Dodaj wskaźnik</button>

  <div class="d-flex gap-2 flex-wrap mt-4 pt-3" style="border-top:2px solid var(--dark)">
    <button type="submit" class="dz-btn"><i class="bi bi-check-lg" aria-hidden="true"></i><?= $id ? 'Zapisz zmiany' : 'Dodaj działanie' ?></button>
    <a href="<?= h($id ? ActionsService::url((string)$id) : ActionsService::url()) ?>" class="dz-btn dz-btn--ghost">Anuluj</a>
  </div>
</form>
<script>
/** Formularz działania: pola zależne i budżet grantu (kwota przyznana − rozdysponowane na inne działania). */
function dzForm(init) {
  const budgets = <?= json_encode($budgets, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
  const n = v => { const x = parseFloat(String(v ?? '').replace(/\s/g, '').replace(',', '.')); return isNaN(x) ? null : x; };
  return Object.assign(init, {
    money(v, cur) { return Number(v || 0).toLocaleString('pl-PL', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' ' + (cur === 'PLN' ? 'zł' : cur); },
    budgetOf(g) { return g.grant_id ? (budgets[g.grant_id] || null) : null; },
    overBy(g) {
      const b = this.budgetOf(g), k = n(g.kwota);
      return b && b.remaining !== null && k !== null ? Math.round((k - b.remaining) * 100) / 100 : 0;
    },
    suggest(g) {
      const b = this.budgetOf(g), u = n(g.udzial);
      return b && b.budget && u !== null && u > 0 && n(g.kwota) === null ? Math.round(b.budget * u) / 100 : null;
    },
    budgetText(g) {
      const b = this.budgetOf(g);
      if (!b) return '';
      if (b.budget === null) return 'Grant bez kwoty — uzupełnij ją w module Grantów, żeby liczyć budżet.';
      const t = (b.estimated ? 'Wnioskowano ' : 'Przyznano ') + this.money(b.budget, b.waluta) + ' · inne działania: ' + this.money(b.allocated, b.waluta)
              + ' · zostaje ' + this.money(b.remaining, b.waluta);
      const o = this.overBy(g);
      return o > 0 ? t + ' — ta kwota przekracza pozostały budżet o ' + this.money(o, b.waluta) + '.' : t;
    },
  });
}
</script>
<?php include __DIR__ . '/_foot.php'; ?>
