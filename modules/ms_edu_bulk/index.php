<?php
/** Hurtowe generowanie kont M365 Edu z listy osób (uczniowie/nauczyciele). */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once __DIR__ . '/logic/msEduBulk.php';

require_role('admin');
$PAGE_TITLE = 'Generowanie kont M365 Edu';

$graph  = new M365Graph();
$skus   = $graph->is_configured() ? $graph->get_subscribed_skus() : [];
$domain = m365_setting('m365_domain') ?: 'feer.org.pl';

if (isset($_GET['csv']) && !empty($_SESSION['msEduBulkResult'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="konta_m365_edu_' . date('Ymd_His') . '.csv"');
    echo "\xEF\xBB\xBFimie;nazwisko;rola;klasa;login;haslo\n";
    foreach ($_SESSION['msEduBulkResult'] as $r)
        echo implode(';', [$r['imie'], $r['nazwisko'], $r['rola'], $r['klasa'], $r['upn'], $r['password']]) . "\n";
    unset($_SESSION['msEduBulkResult']); exit;
}

if (isset($_GET['pdf']) && !empty($_SESSION['msEduBulkResult'])) {
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="konta_m365_edu_' . date('Ymd_His') . '.pdf"');
    echo msEduBulkPdf($_SESSION['msEduBulkResult']); exit;
}

$input  = (string)($_POST['lista'] ?? '');
$year   = preg_match('/^\d{4}$/', $_POST['rok'] ?? '') ? $_POST['rok'] : date('Y');
$domIn  = preg_replace('/[^a-z0-9.\-]/i', '', $_POST['domena'] ?? '') ?: $domain;
$skuSel = ['uczen' => (string)($_POST['sku_uczen'] ?? ''), 'nauczyciel' => (string)($_POST['sku_nauczyciel'] ?? '')];
$mode   = in_array($_POST['tryb'] ?? '', ['rola','imie_nazwisko','szkolenie'], true) ? $_POST['tryb'] : 'rola';
$slug   = trim((string)($_POST['szkolenie'] ?? ''));
$plan = null; $res = null; $errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $parsed = msEduBulkParse($input);
    $errors = $parsed['errors'];
    if (!$graph->is_configured()) $errors[] = 'Integracja Microsoft 365 nie jest skonfigurowana.';
    if (count($parsed['rows']) > 300) $errors[] = 'Maksymalnie 300 osób na raz.';
    if ($parsed['rows'] && !$errors) {
        $plan = msEduBulkPlan($graph, $parsed['rows'], $domIn, $year, $mode, $slug);
        if (($_POST['_op'] ?? '') === 'create') {
            $res = msEduBulkCreate($graph, $plan, $skuSel, $year);
            if ($res['ok']) $_SESSION['msEduBulkResult'] = $res['ok'];
            $plan = null;
        }
    }
}

include dirname(__DIR__, 2) . '/includes/header.php';
?>
<div class="container-xl py-4">
  <h1 class="h3 fw-bold mb-3"><i class="bi bi-people-fill me-2"></i>Generowanie kont M365 Edu</h1>
  <?= flash_html() ?>
  <?php foreach ($errors as $e): ?><div class="alert alert-danger py-2 small mb-2"><?= h($e) ?></div><?php endforeach; ?>

  <?php if ($res): ?>
  <div class="card mb-4 border-success">
    <div class="card-header d-flex gap-2 align-items-center">
      <span>Utworzono: <strong><?= count($res['ok']) ?></strong>, błędów: <?= count($res['errors']) ?> — hasła widoczne tylko teraz</span>
      <?php if ($res['ok']): ?><a href="?pdf=1" target="_blank" class="btn btn-sm btn-primary ms-auto"><i class="bi bi-printer me-1"></i>Drukuj PDF</a> <a href="?csv=1" class="btn btn-sm btn-success"><i class="bi bi-download me-1"></i>CSV</a><?php endif; ?>
    </div>
    <div class="table-responsive"><table class="table table-sm mb-0 font-monospace">
      <thead class="table-light"><tr><th>Osoba</th><th>Klasa</th><th>Login</th><th>Hasło</th><th></th></tr></thead>
      <tbody><?php foreach ($res['ok'] as $r): ?>
        <tr><td><?= h($r['imie'] . ' ' . $r['nazwisko']) ?></td><td><?= h($r['klasa']) ?></td><td><?= h($r['upn']) ?></td><td><?= h($r['password']) ?></td>
            <td class="text-warning small"><?= h($r['warn']) ?></td></tr>
      <?php endforeach; ?></tbody></table></div>
    <?php foreach ($res['errors'] as $e): ?><div class="text-danger small px-3 py-1"><?= h($e) ?></div><?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if ($plan): ?>
  <div class="card mb-4 border-primary">
    <div class="card-header">Podgląd (<?= count($plan) ?> kont) — nic jeszcze nie utworzono</div>
    <div class="table-responsive"><table class="table table-sm mb-0">
      <thead class="table-light"><tr><th>Osoba</th><th>Rola</th><th>Klasa / dział</th><th>Login</th></tr></thead>
      <tbody><?php foreach ($plan as $r): ?>
        <tr><td><?= h($r['imie'] . ' ' . $r['nazwisko']) ?></td><td><?= h($r['rola']) ?></td><td><?= h($r['klasa']) ?></td><td class="font-monospace"><?= h($r['upn']) ?></td></tr>
      <?php endforeach; ?></tbody></table></div>
  </div>
  <?php endif; ?>

  <form method="post" class="card card-body">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <div class="mb-3">
      <label class="form-label fw-semibold" for="lista">Lista osób <span class="text-muted fw-normal">— jeden wiersz = jedna osoba: Imię;Nazwisko;Rola;Klasa</span></label>
      <textarea id="lista" name="lista" rows="10" class="form-control font-monospace" placeholder="Jan;Kowalski;uczeń;1A&#10;Anna;Wiśniewska;nauczyciel;Kadra Pedagogiczna"><?= h($input) ?></textarea>
    </div>
    <div class="row g-3 mb-3">
      <div class="col-md-3"><label class="form-label small fw-semibold" for="rok">Rok rozpoczęcia nauki</label>
        <input id="rok" name="rok" class="form-control" value="<?= h($year) ?>" maxlength="4"></div>
      <div class="col-md-3"><label class="form-label small fw-semibold" for="domena">Domena</label>
        <input id="domena" name="domena" class="form-control" value="<?= h($domIn) ?>"></div>
      <div class="col-md-3"><label class="form-label small fw-semibold" for="tryb">Schemat loginu</label>
        <select id="tryb" name="tryb" class="form-select">
          <option value="rola"<?= $mode === 'rola' ? ' selected' : '' ?>>wg roli (uczeń i.nazwisko+rok / nauczyciel imie.nazwisko)</option>
          <option value="szkolenie"<?= $mode === 'szkolenie' ? ' selected' : '' ?>>nazwa szkolenia + numer (excel01)</option>
          <option value="imie_nazwisko"<?= $mode === 'imie_nazwisko' ? ' selected' : '' ?>>imie.nazwisko</option>
        </select></div>
      <div class="col-md-3"><label class="form-label small fw-semibold" for="szkolenie">Nazwa szkolenia <span class="text-muted fw-normal">(dla schematu „szkolenie + numer”)</span></label>
        <input id="szkolenie" name="szkolenie" class="form-control" value="<?= h($slug) ?>"></div>
      <?php foreach (['uczen' => 'Licencja — uczniowie', 'nauczyciel' => 'Licencja — nauczyciele'] as $k => $lbl): ?>
      <div class="col-md-3"><label class="form-label small fw-semibold" for="sku_<?= $k ?>"><?= $lbl ?></label>
        <select id="sku_<?= $k ?>" name="sku_<?= $k ?>" class="form-select">
          <option value="">— bez licencji —</option>
          <?php foreach ($skus as $s): $sel = ($skuSel[$k] ?? '') === $s['skuId']; ?>
          <option value="<?= h($s['skuId']) ?>"<?= $sel ? ' selected' : '' ?>><?= h($s['skuPartNumber']) ?> (wolne: <?= (int)$s['prepaidUnits']['enabled'] - (int)$s['consumedUnits'] ?>)</option>
          <?php endforeach; ?>
        </select></div>
      <?php endforeach; ?>
    </div>
    <div class="d-flex gap-2">
      <button name="_op" value="preview" class="btn btn-outline-primary"><i class="bi bi-eye me-1"></i>Podgląd loginów</button>
      <?php if ($plan): ?>
      <button name="_op" value="create" class="btn btn-primary" onclick="return confirm('Utworzyć REALNE konta w Entra ID?')"><i class="bi bi-person-plus me-1"></i>Utwórz <?= count($plan) ?> kont</button>
      <?php endif; ?>
    </div>
    <p class="small text-muted mt-3 mb-0">Uczeń: <code>i.nazwisko<?= h($year) ?></code>, nauczyciel: <code>imie.nazwisko</code>; duplikat dostaje sufiks <code>.1</code>. Grupy „Uczniowie_<?= h($year) ?>” / „Nauczyciele” powstają automatycznie. Hasło 16 znaków, zmiana przy pierwszym logowaniu.</p>
  </form>
</div>
<?php include dirname(__DIR__, 2) . '/includes/footer.php';
