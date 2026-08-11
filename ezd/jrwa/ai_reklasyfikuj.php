<?php
/**
 * ezd/jrwa/ai_reklasyfikuj.php — Wsadowe przerejestrowanie AI po zmianie JRWA
 *
 * Po każdej przebudowie JRWA część koszulek (spraw) może być przypisana do
 * klas, które zmienily znaczenie. Strona listuje koszulki z "podejrzaną"
 * klasyfikacją i wywołuje AI (Claude) żeby zaproponował nową klasę JRWA.
 *
 * Logika "podejrzana":
 *   - Koszulka bez klasy JRWA (jrwa_id IS NULL)
 *   - Koszulka w klasie, której opis zawiera "[Klasa wycofana]"
 *
 * AJAX endpoint: POST ?_ajax=1&op=suggest_one&sprawa_id=N
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd_ai.php';

require_login();
require_module_enabled('ezd_enabled', 'Moduł EZD Wirtualne biurko'); ezd_require_access();
if (!can_edit()) { flash_set('error', 'Brak uprawnień.'); header('Location: ' . APP_URL . '/ezd/jrwa/index.php'); exit; }

$ai_on = ezd_ai_enabled();

// ── AJAX: zaproponuj klasę dla jednej koszulki ─────────────────────────────────
if (!empty($_POST['_ajax']) && ($_POST['op'] ?? '') === 'suggest_one') {
    header('Content-Type: application/json; charset=utf-8');
    csrf_check();
    $sid = (int)($_POST['sprawa_id'] ?? 0);
    if (!$sid) { echo json_encode(['ok'=>false,'error'=>'Brak ID']); exit; }

    $s = ezd_sprawa_get($sid);
    if (!$s || !ezd_sprawa_access($s, (int)current_user()['id'])) {
        echo json_encode(['ok'=>false,'error'=>'Brak dostępu']); exit;
    }

    $opis = trim($s['title'] . "\n" . ($s['description'] ?? ''));
    $r    = ezd_ai_przerejestruj($opis, (string)($s['znak_sprawy'] ?? ''));
    if (!$r['ok']) { echo json_encode(['ok'=>false,'error'=>$r['error']]); exit; }

    echo json_encode(['ok' => true, 'data' => [
        'sprawa_id'    => $sid,
        'kod_jrwa'     => $r['data']['kod_jrwa'],
        'kod_nazwa'    => $r['data']['kod_nazwa'],
        'kat_arch'     => $r['data']['kat_arch'],
        'uzasadnienie' => $r['data']['uzasadnienie'],
        'nowy_znak'    => $r['data']['nowy_znak'],
    ]]);
    exit;
}

// ── AJAX: zatwierdź — zmień klasę koszulki ────────────────────────────────────
if (!empty($_POST['_ajax']) && ($_POST['op'] ?? '') === 'apply_one') {
    header('Content-Type: application/json; charset=utf-8');
    csrf_check();
    $sid      = (int)($_POST['sprawa_id'] ?? 0);
    $kod_jrwa = trim($_POST['kod_jrwa'] ?? '');
    if (!$sid || !$kod_jrwa) { echo json_encode(['ok'=>false,'error'=>'Brak danych']); exit; }

    $s = ezd_sprawa_get($sid);
    if (!$s) { echo json_encode(['ok'=>false,'error'=>'Koszulka nie istnieje']); exit; }

    // Znajdź jrwa_id
    $jrwa = db_one("SELECT id, symbol FROM ezd_jrwa WHERE symbol=? LIMIT 1", [$kod_jrwa]);
    if (!$jrwa) {
        // Spróbuj z teczką
        echo json_encode(['ok'=>false,'error'=>"Nieznana klasa JRWA: {$kod_jrwa}"]); exit;
    }

    db()->prepare("UPDATE ezd_sprawy SET jrwa_id=?, updated_at=datetime('now','localtime') WHERE id=?")->execute([$jrwa['id'], $sid]);
    ezd_log(null, $sid, null, null, (int)current_user()['id'], 'ai_reklasyfikacja', "AI zaproponowało → {$kod_jrwa}");

    echo json_encode(['ok'=>true, 'kod'=>$kod_jrwa, 'sprawa_id'=>$sid]);
    exit;
}

// ── Pobierz koszulki do przerejestrowania ─────────────────────────────────────
$sprawy = db_all("
    SELECT s.id, s.title, s.znak_sprawy, s.status, s.jrwa_id,
           j.symbol AS jrwa_sym, j.title AS jrwa_title, j.description AS jrwa_desc
    FROM ezd_sprawy s
    LEFT JOIN ezd_jrwa j ON j.id = s.jrwa_id
    WHERE s.jrwa_id IS NULL
       OR j.description LIKE '%[Klasa wycofana]%'
    ORDER BY s.id DESC
    LIMIT 200
");

$PAGE_TITLE = 'AI — Wsadowe przerejestrowanie JRWA';
include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb" style="font-size:.8rem">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/">EZD</a></li>
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/jrwa/index.php">Wykaz akt (JRWA)</a></li>
  <li class="breadcrumb-item active">AI Reklasyfikacja</li>
</ol></nav>

<div class="d-flex align-items-center gap-3 mb-3">
  <h1 class="h4 mb-0"><i class="bi bi-robot me-2 text-primary"></i>AI — Wsadowe przerejestrowanie JRWA</h1>
  <span class="badge <?= $ai_on ? 'bg-success' : 'bg-danger' ?>">AI <?= $ai_on ? 'ON' : 'OFF' ?></span>
</div>

<?= flash_html() ?>

<?php if (!$ai_on): ?>
<div class="alert alert-warning">
  <i class="bi bi-exclamation-triangle-fill"></i>
  Brak klucza Anthropic API. Skonfiguruj w <a href="<?= APP_URL ?>/admin/ezd_settings.php">Ustawieniach EZD AI</a>.
</div>
<?php endif; ?>

<div class="card shadow-sm mb-3">
  <div class="card-body py-2 text-muted small">
    <i class="bi bi-info-circle me-1"></i>
    Strona listuje koszulki bez klasy JRWA lub z klasą wycofaną po ostatniej przebudowie.
    Kliknij <strong>Zaproponuj AI</strong> przy danej koszulce, by Claude zasugerował właściwą klasę — a następnie <strong>Zastosuj</strong>, by zapisać zmianę.
  </div>
</div>

<?php if (!$sprawy): ?>
<div class="card shadow-sm">
  <div class="card-body text-center py-5 text-muted">
    <i class="bi bi-check-circle-fill text-success fs-2 d-block mb-2"></i>
    Wszystkie koszulki mają aktualne klasy JRWA. Brak do przerejestrowania.
  </div>
</div>
<?php else: ?>

<div class="mb-2 d-flex gap-2 align-items-center">
  <span class="text-muted small"><?= count($sprawy) ?> koszulki do sprawdzenia</span>
  <?php if ($ai_on): ?>
  <button class="btn btn-sm btn-primary" id="btn-all" onclick="suggestAll()">
    <i class="bi bi-robot me-1"></i>Zaproponuj AI dla wszystkich
  </button>
  <?php endif; ?>
</div>

<div class="card shadow-sm">
<div class="table-responsive">
<table class="table table-hover table-sm align-middle mb-0" id="rek-table">
  <thead class="table-light">
    <tr>
      <th>Znak / Tytuł</th>
      <th>Obecna klasa JRWA</th>
      <th>Propozycja AI</th>
      <th></th>
    </tr>
  </thead>
  <tbody>
  <?php foreach ($sprawy as $s): ?>
  <tr id="row-<?= $s['id'] ?>">
    <td>
      <a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $s['id'] ?>" class="fw-semibold text-decoration-none">
        <?= h($s['znak_sprawy'] ?: '—') ?>
      </a>
      <div class="text-muted small"><?= h(mb_substr($s['title'],0,60)) ?></div>
      <span class="badge bg-<?= $s['status'] === 'open' ? 'success' : 'secondary' ?> small"><?= h($s['status']) ?></span>
    </td>
    <td class="small">
      <?php if ($s['jrwa_sym']): ?>
        <span class="badge bg-secondary"><?= h($s['jrwa_sym']) ?></span>
        <span class="text-muted"><?= h(mb_substr($s['jrwa_title'],0,40)) ?></span>
        <?php if (str_contains((string)$s['jrwa_desc'], '[Klasa wycofana]')): ?>
        <span class="badge bg-warning text-dark ms-1">wycofana</span>
        <?php endif; ?>
      <?php else: ?>
        <span class="text-danger"><i class="bi bi-exclamation-triangle"></i> brak klasy</span>
      <?php endif; ?>
    </td>
    <td id="sugg-<?= $s['id'] ?>" class="small text-muted">—</td>
    <td class="text-end text-nowrap">
      <?php if ($ai_on): ?>
      <button class="btn btn-sm btn-outline-primary py-0 px-1"
              onclick="suggestOne(<?= $s['id'] ?>)"
              id="btn-sugg-<?= $s['id'] ?>">
        <i class="bi bi-robot"></i> AI
      </button>
      <button class="btn btn-sm btn-success py-0 px-1 ms-1 d-none"
              id="btn-apply-<?= $s['id'] ?>" onclick="applyOne(<?= $s['id'] ?>)">
        <i class="bi bi-check-lg"></i> Zastosuj
      </button>
      <?php endif; ?>
      <a href="<?= APP_URL ?>/ezd/sprawy/przerejestruj.php?id=<?= $s['id'] ?>"
         class="btn btn-sm btn-outline-secondary py-0 px-1 ms-1" title="Ręczne przerejestrowanie">
        <i class="bi bi-arrow-repeat"></i>
      </a>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
</div>
<?php endif; ?>

<script>
const CSRF = '<?= csrf_token() ?>';
const API  = '<?= APP_URL ?>/ezd/jrwa/ai_reklasyfikuj.php';
const suggestions = {};

async function suggestOne(id) {
  const btn  = document.getElementById('btn-sugg-' + id);
  const cell = document.getElementById('sugg-' + id);
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
  cell.textContent = 'Analizuję…';

  const fd = new FormData();
  fd.append('_ajax', '1'); fd.append('_csrf', CSRF);
  fd.append('op', 'suggest_one'); fd.append('sprawa_id', id);

  try {
    const r = await (await fetch(API, {method:'POST', body:fd})).json();
    if (!r.ok) { cell.innerHTML = '<span class="text-danger small">' + r.error + '</span>'; }
    else {
      suggestions[id] = r.data;
      cell.innerHTML =
        '<strong class="text-primary">' + r.data.kod_jrwa + '</strong> ' + r.data.kod_nazwa +
        '<div class="text-muted" style="font-size:.75rem">' + (r.data.uzasadnienie||'') + '</div>';
      document.getElementById('btn-apply-' + id).classList.remove('d-none');
    }
  } catch(e) { cell.innerHTML = '<span class="text-danger small">Błąd sieci</span>'; }
  btn.disabled = false;
  btn.innerHTML = '<i class="bi bi-robot"></i> AI';
}

async function applyOne(id) {
  const s = suggestions[id];
  if (!s) return;
  const btn = document.getElementById('btn-apply-' + id);
  btn.disabled = true;

  const fd = new FormData();
  fd.append('_ajax', '1'); fd.append('_csrf', CSRF);
  fd.append('op', 'apply_one'); fd.append('sprawa_id', id);
  fd.append('kod_jrwa', s.kod_jrwa);

  try {
    const r = await (await fetch(API, {method:'POST', body:fd})).json();
    if (r.ok) {
      const row = document.getElementById('row-' + id);
      row.classList.add('table-success');
      btn.innerHTML = '<i class="bi bi-check-lg"></i> Zapisano';
    } else {
      alert(r.error);
      btn.disabled = false;
    }
  } catch(e) { btn.disabled = false; }
}

async function suggestAll() {
  const rows = document.querySelectorAll('#rek-table tbody tr');
  for (const row of rows) {
    const id = row.id.replace('row-','');
    await suggestOne(id);
    await new Promise(r => setTimeout(r, 800)); // throttle
  }
}
</script>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
