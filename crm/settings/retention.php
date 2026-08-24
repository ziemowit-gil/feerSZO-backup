<?php
/**
 * crm/settings/retention.php — retencja danych osobowych i anonimizacja.
 *
 * Ekran robi trzy rzeczy: pozwala ustawić reguły („kontakty ze statusem X po
 * 24 miesiącach bez kontaktu"), pokazuje KANDYDATÓW wyliczonych na żywo
 * i pozwala ich anonimizować — po obejrzeniu listy, nie w ciemno.
 *
 * Anonimizacja jest nieodwracalna, więc uruchamia ją człowiek. Cron tylko oznacza
 * i raportuje (zob. cron/crm_retention.php).
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm_retention.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
crm_require('settings', 'write');
crm_migrate();
crm_retention_migrate();

// Anonimizacja usuwa dane bezpowrotnie — to nie jest zwykłe ustawienie modułu
$can_wipe = is_admin();

$PAGE_TITLE = 'CRM — Retencja danych';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = (string)($_POST['_op'] ?? '');

    if ($op === 'rule_save') {
        $r = crm_retention_rule_save([
            'name'        => $_POST['name']        ?? '',
            'scope'       => $_POST['scope']       ?? 'status',
            'match_value' => $_POST['match_value'] ?? '',
            'months'      => $_POST['months']      ?? 24,
            'is_active'   => isset($_POST['is_active']) || empty($_POST['rule_id']),
        ], (int)($_POST['rule_id'] ?? 0) ?: null);
        flash_set($r['ok'] ? 'success' : 'danger', $r['ok'] ? 'Reguła zapisana.' : $r['error']);
    } elseif ($op === 'rule_del') {
        crm_retention_rule_delete((int)($_POST['rule_id'] ?? 0));
        flash_set('success', 'Reguła usunięta.');
    } elseif ($op === 'hold') {
        crm_retention_set_hold((int)($_POST['contact_id'] ?? 0), !empty($_POST['on']));
        flash_set('success', !empty($_POST['on'])
            ? 'Kartoteka wyłączona z retencji.' : 'Wyłączenie z retencji cofnięte.');
    } elseif ($op === 'anonymize' && $can_wipe) {
        // Potwierdzenie wpisaniem słowa: kliknięcie „OK" w okienku bywa odruchem,
        // przepisanie słowa wymaga przeczytania, co się właśnie stanie
        if (mb_strtoupper(trim((string)($_POST['confirm'] ?? ''))) !== 'ANONIMIZUJ') {
            flash_set('danger', 'Aby anonimizować, wpisz słowo ANONIMIZUJ.');
        } else {
            $ids  = array_map('intval', (array)($_POST['ids'] ?? []));
            $done = 0; $fail = 0;
            foreach ($ids as $cid) {
                $r = crm_retention_anonymize($cid, 'retencja — decyzja użytkownika');
                $r['ok'] ? $done++ : $fail++;
            }
            flash_set($fail ? 'warning' : 'success',
                "Zanonimizowano kartotek: {$done}" . ($fail ? ", nieudanych: {$fail}" : '') . '.');
        }
    }
    header('Location: ' . APP_URL . '/crm/settings/retention.php'); exit;
}

$rules   = crm_retention_rules(false);
$summary = crm_retention_summary();
$cands   = crm_retention_all_candidates(200);
$edit_id = (int)($_GET['edit'] ?? 0);
$edit    = $edit_id ? (db_one("SELECT * FROM crm_retention_rules WHERE id=?", [$edit_id]) ?: null) : null;

$statuses = crm_statuses();
$sources  = [];
try {
    foreach (db_all("SELECT DISTINCT source FROM crm_contacts WHERE source IS NOT NULL AND source<>'' ORDER BY source") as $r) {
        $sources[] = (string)$r['source'];
    }
} catch (\Throwable $e) {}

include __DIR__ . '/../includes/header_crm.php';
include __DIR__ . '/_nav.php';
?>
<div class="crm-page-header">
  <div>
    <div class="crm-page-title"><i class="bi bi-shield-check" style="color:var(--crm-primary)"></i> Retencja danych</div>
    <div class="crm-page-subtitle">
      Reguł: <?= (int)$summary['rules'] ?> ·
      oznaczonych: <?= (int)$summary['flagged'] ?> ·
      zanonimizowanych: <?= (int)$summary['anonymized'] ?> ·
      wyłączonych z retencji: <?= (int)$summary['held'] ?>
    </div>
  </div>
</div>

<div class="alert alert-light border" style="font-size:.85rem">
  <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
  Anonimizacja <strong>nie usuwa kartoteki</strong> — czyści z niej dane osobowe
  (kartoteka, notatki, treść korespondencji, osoby kontaktowe), zostawiając szkielet
  potrzebny do statystyk i do wykazania, że obowiązek wykonano.
  Kartoteki z <strong>darowizną lub fakturą z ostatnich 5 lat</strong>, z otwartą sprawą
  albo powiązane z beneficjentem programu są pomijane zawsze — ich przechowywanie
  wynika z innych przepisów niż RODO.
</div>

<!-- ── Reguły ─────────────────────────────────────────────────────────────── -->
<div class="card mb-3">
  <div class="card-header">Reguły retencji</div>
  <div class="card-body">
    <?php if (!$rules): ?>
    <p class="text-muted small mb-3">
      Nie ma jeszcze żadnej reguły. Bez reguł nic nie jest oznaczane ani proponowane
      do anonimizacji — moduł nie robi niczego z własnej inicjatywy.
    </p>
    <?php else: ?>
    <div class="table-responsive mb-3">
      <table class="table table-sm align-middle mb-0">
        <thead><tr><th>Nazwa</th><th>Kogo dotyczy</th><th>Po ilu miesiącach</th><th>Stan</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rules as $r): ?>
        <tr>
          <td class="fw-semibold"><?= h($r['name']) ?></td>
          <td class="small">
            <?= h(crm_retention_scopes()[$r['scope']] ?? $r['scope']) ?>
            <?php if ($r['match_value']): ?>
            <code><?= h($r['match_value']) ?></code>
            <?php endif; ?>
          </td>
          <td><?= (int)$r['months'] ?> mies.</td>
          <td>
            <?php if ($r['is_active']): ?>
            <span class="badge bg-success-subtle text-success border border-success-subtle">aktywna</span>
            <?php else: ?>
            <span class="badge bg-secondary-subtle text-secondary border">wyłączona</span>
            <?php endif; ?>
          </td>
          <td class="text-end text-nowrap">
            <a class="btn btn-sm btn-crm-outline py-0 px-2" href="?edit=<?= (int)$r['id'] ?>">Edytuj</a>
            <form method="post" class="d-inline" onsubmit="return confirm('Usunąć regułę?')">
              <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
              <input type="hidden" name="_op" value="rule_del">
              <input type="hidden" name="rule_id" value="<?= (int)$r['id'] ?>">
              <button class="btn btn-sm btn-outline-danger py-0 px-2">Usuń</button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <form method="post" class="row g-2 align-items-end">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_op" value="rule_save">
      <?php if ($edit): ?><input type="hidden" name="rule_id" value="<?= (int)$edit['id'] ?>"><?php endif; ?>
      <div class="col-md-4">
        <label class="form-label small fw-semibold mb-1" for="rName">Nazwa reguły</label>
        <input class="form-control form-control-sm" id="rName" name="name" required maxlength="120"
               value="<?= h($edit['name'] ?? '') ?>" placeholder="np. Leady bez reakcji">
      </div>
      <div class="col-md-3">
        <label class="form-label small fw-semibold mb-1" for="rScope">Kogo dotyczy</label>
        <select class="form-select form-select-sm" id="rScope" name="scope">
          <?php foreach (crm_retention_scopes() as $sk => $sl): ?>
          <option value="<?= h($sk) ?>" <?= ($edit['scope'] ?? 'status') === $sk ? 'selected' : '' ?>><?= h($sl) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label small fw-semibold mb-1" for="rVal">Wartość</label>
        <input class="form-control form-control-sm" id="rVal" name="match_value" list="rVals"
               value="<?= h($edit['match_value'] ?? '') ?>" placeholder="status / źródło / typ">
        <datalist id="rVals">
          <?php foreach ($statuses as $sk => $sv): ?><option value="<?= h($sk) ?>"><?= h($sv['label']) ?></option><?php endforeach; ?>
          <?php foreach ($sources as $sv): ?><option value="<?= h($sv) ?>"></option><?php endforeach; ?>
          <?php foreach (CRM_CONTACT_TYPES as $tk => $tv): ?><option value="<?= h($tk) ?>"><?= h($tv['label']) ?></option><?php endforeach; ?>
        </datalist>
      </div>
      <div class="col-md-2">
        <label class="form-label small fw-semibold mb-1" for="rMonths">Miesięcy</label>
        <input type="number" class="form-control form-control-sm" id="rMonths" name="months"
               min="6" max="240" value="<?= (int)($edit['months'] ?? 24) ?>">
        <div class="form-text" style="font-size:.7rem">minimum 6</div>
      </div>
      <?php if ($edit): ?>
      <div class="col-12">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" name="is_active" id="rAct" value="1"
                 <?= $edit['is_active'] ? 'checked' : '' ?>>
          <label class="form-check-label small" for="rAct">Reguła aktywna</label>
        </div>
      </div>
      <?php endif; ?>
      <div class="col-12">
        <button class="btn btn-crm-primary btn-sm">
          <i class="bi bi-check-lg me-1"></i><?= $edit ? 'Zapisz zmiany' : 'Dodaj regułę' ?>
        </button>
        <?php if ($edit): ?>
        <a href="retention.php" class="btn btn-crm-outline btn-sm">Anuluj</a>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<!-- ── Kandydaci ──────────────────────────────────────────────────────────── -->
<div class="card">
  <div class="card-header d-flex align-items-center justify-content-between">
    <span>Kartoteki spełniające reguły (<?= count($cands) ?>)</span>
    <?php if (!$can_wipe): ?>
    <span class="text-muted" style="font-size:.78rem">Anonimizować może wyłącznie administrator</span>
    <?php endif; ?>
  </div>
  <div class="card-body">
    <?php if (!$cands): ?>
    <p class="text-muted small mb-0">
      Nic nie spełnia reguł. To dobra wiadomość — albo znak, że reguły są zbyt wąskie.
    </p>
    <?php else: ?>
    <form method="post" id="anonForm">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_op" value="anonymize">
      <div class="table-responsive" style="max-height:420px;overflow-y:auto">
        <table class="table table-sm align-middle mb-0">
          <thead><tr>
            <?php if ($can_wipe): ?><th style="width:32px"><input type="checkbox" id="anonAll" class="form-check-input" aria-label="Zaznacz wszystkie"></th><?php endif; ?>
            <th>Kartoteka</th><th>Status</th><th>Źródło</th><th>Ostatni ruch</th><th>Reguła</th><th></th>
          </tr></thead>
          <tbody>
          <?php foreach ($cands as $c): ?>
          <tr>
            <?php if ($can_wipe): ?>
            <td><input type="checkbox" class="form-check-input anon-chk" name="ids[]" value="<?= (int)$c['id'] ?>"
                       aria-label="Zaznacz <?= h($c['imie_nazwisko']) ?>"></td>
            <?php endif; ?>
            <td>
              <a href="<?= APP_URL ?>/crm/contact/view.php?id=<?= (int)$c['id'] ?>" target="_blank" rel="noopener">
                <?= h($c['imie_nazwisko']) ?>
              </a>
              <?php if ($c['email']): ?><div class="text-muted" style="font-size:.74rem"><?= h($c['email']) ?></div><?php endif; ?>
            </td>
            <td class="small"><?= h($statuses[$c['status']]['label'] ?? $c['status']) ?></td>
            <td class="small"><?= h($c['source'] ?: '—') ?></td>
            <td class="small text-nowrap"><?= h(substr((string)$c['last_touch'], 0, 10)) ?></td>
            <td class="small"><?= h($c['rule']) ?> (<?= (int)$c['months'] ?> mies.)</td>
            <td class="text-end">
              <?php /* Wyłączenie z retencji dla przypadków, o których system nie wie
                       — trwająca sprawa poza CRM, prośba o kontakt za rok. */ ?>
              <button type="submit" form="holdForm<?= (int)$c['id'] ?>"
                      class="btn btn-sm btn-crm-outline py-0 px-2" title="Nie anonimizuj tej kartoteki">
                Wyłącz z retencji
              </button>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if ($can_wipe): ?>
      <div class="d-flex align-items-center gap-2 mt-3 flex-wrap">
        <input class="form-control form-control-sm" name="confirm" style="max-width:200px"
               placeholder="wpisz ANONIMIZUJ" aria-label="Potwierdzenie: wpisz słowo ANONIMIZUJ">
        <button class="btn btn-danger btn-sm" id="anonBtn" disabled
                onclick="return confirm('Zanonimizować zaznaczone kartoteki? Tej operacji NIE DA SIĘ cofnąć.')">
          <i class="bi bi-eraser me-1"></i>Anonimizuj zaznaczone (<span id="anonN">0</span>)
        </button>
        <span class="text-muted" style="font-size:.78rem">
          Operacja jest nieodwracalna — dlatego wymaga wpisania słowa, a nie samego kliknięcia.
        </span>
      </div>
      <?php endif; ?>
    </form>

    <?php /* Formularze wyłączania z retencji poza tabelą: zagnieżdżanie <form>
             w <form> jest nieprawidłowe i przeglądarka wysłałaby nie to, co trzeba. */ ?>
    <?php foreach ($cands as $c): ?>
    <form method="post" id="holdForm<?= (int)$c['id'] ?>" class="d-none">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_op" value="hold">
      <input type="hidden" name="on" value="1">
      <input type="hidden" name="contact_id" value="<?= (int)$c['id'] ?>">
    </form>
    <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<script>
(function () {
  var all = document.getElementById('anonAll');
  var btn = document.getElementById('anonBtn');
  var out = document.getElementById('anonN');
  if (!btn) return;
  function refresh() {
    var n = document.querySelectorAll('.anon-chk:checked').length;
    out.textContent = n;
    btn.disabled = n === 0;
  }
  if (all) all.addEventListener('change', function () {
    document.querySelectorAll('.anon-chk').forEach(function (b) { b.checked = all.checked; });
    refresh();
  });
  document.querySelectorAll('.anon-chk').forEach(function (b) { b.addEventListener('change', refresh); });
  refresh();
})();
</script>
<?php include __DIR__ . '/../includes/footer_crm.php'; ?>
