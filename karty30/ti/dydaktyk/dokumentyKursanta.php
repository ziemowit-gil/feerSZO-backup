<?php
/**
 * karty30/ti/dydaktyk/dokumentyKursanta.php — „Dokumenty kursantów” (kierownik): przegląd zbiorczy, obsługa formularzy
 * zakładki „Dokumenty” z Kont kursantów i pobieranie plików teczki.
 *   GET  ?file=ID        → plik z teczki (dziennik dostępu k30_log_access)
 *   POST _op=…           → operacje teczki / dokumentów do akceptacji; back=konta wraca do konta kursanta
 *   GET  (bez parametrów) → do zatwierdzenia, wygasające, czekające na akceptację, odmowy + ustawienia przypomnień
 * Logika: modules/ti_student_folder/logic/studentFolder.php.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 3) . '/modules/ti_student_folder/logic/studentFolder.php';

$me = dyd_require();
if (!dyd_is_staff()) { header('Location: index.php'); exit; }
$uid      = (int)$me['user_id'];
$dyd_name = (string)($me['name'] ?? '');
karty30_migrate();
TiStudentFolder::migrate();

if (isset($_GET['file'])) {
    $f = TiStudentFolder::file((int)$_GET['file']);
    if (!$f) { http_response_code(404); exit('Nie znaleziono dokumentu.'); }
    k30_log_access('student_folder_file', (int)$f['id'], 'view', (string)$f['title']);
    TiStudentFolder::send($f);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    $op  = (string)($_POST['_op'] ?? '');
    $aid = (int)($_POST['account_id'] ?? 0);
    $err = '';
    $ok  = '';
    $acc = $aid ? db_one("SELECT id FROM k30_ti_student_accounts WHERE id=?", [$aid]) : null;
    // Operacje na wpisie muszą dotyczyć wskazanego konta (formularz z zakładki konta)
    $fid = (int)($_POST['file_id'] ?? 0);
    if ($fid && $op !== 'req_create' && (!($f = TiStudentFolder::file($fid)) || (int)$f['account_id'] !== $aid)) $err = 'Nie znaleziono dokumentu.';
    elseif ($op !== 'settings' && !$acc) $err = 'Nie znaleziono konta kursanta.';
    elseif ($op === 'file_upload') {
        [, $err] = TiStudentFolder::upload($aid, $_FILES['file'] ?? [], $_POST, 'staff', $uid, $dyd_name);
        $ok = 'Dodano dokument do teczki.';
    } elseif ($op === 'file_update') {
        $err = TiStudentFolder::update($fid, $_POST, $uid, $dyd_name);
        $ok  = 'Zapisano opis dokumentu.';
    } elseif ($op === 'file_approve' || $op === 'file_reject') {
        $err = TiStudentFolder::review($fid, $op === 'file_approve', (string)($_POST['reason'] ?? ''), $uid, $dyd_name);
        $ok  = $op === 'file_approve' ? 'Dokument zatwierdzony.' : 'Dokument odrzucony — kursant zobaczy powód.';
    } elseif ($op === 'file_delete') {
        $err = TiStudentFolder::delete($fid, $uid, $dyd_name);
        $ok  = 'Usunięto dokument z teczki.';
    } elseif ($op === 'req_create') {
        [$rid, $err] = TiStudentFolder::createRequest($aid, (string)($_POST['title'] ?? ''), (string)($_POST['body'] ?? ''), (string)($_POST['due_date'] ?? ''),
                                                      $fid, $_FILES['file'] ?? null, $uid, $dyd_name);
        $ok = 'Wystawiono dokument do akceptacji.';
        if ($err === '' && !empty($_POST['notify'])) {
            $ok .= TiStudentFolder::notifyRequest($rid) ? ' Wysłano powiadomienie e-mail.' : ' Nie wysłano e-maila (brak adresu).';
        }
    } elseif ($op === 'req_cancel') {
        $r = TiStudentFolder::request((int)($_POST['request_id'] ?? 0));
        $err = ($r && (int)$r['account_id'] === $aid) ? TiStudentFolder::cancelRequest((int)$r['id'], $uid, $dyd_name) : 'Nie znaleziono dokumentu.';
        $ok  = 'Anulowano dokument do akceptacji.';
    } elseif ($op === 'settings') {
        TiStudentFolder::saveSettings(!empty($_POST['enabled']), (int)($_POST['days'] ?? 30), $dyd_name);
        $ok = 'Zapisano ustawienia przypomnień.';
    } else $err = 'Nieznana operacja.';
    flash_set($err === '' ? 'success' : 'danger', $err === '' ? $ok : $err);
    header('Location: ' . (($_POST['back'] ?? '') === 'konta' && $aid ? 'konta.php?selected=' . $aid . '&ptab=dokumenty#tab-dokumenty' : 'dokumentyKursanta.php'));
    exit;
}

$set      = TiStudentFolder::settings();
$pending  = db_all("SELECT f.*, cl.name AS client_name FROM k30_ti_sfile f JOIN k30_ti_student_accounts a ON a.id=f.account_id LEFT JOIN k30_clients cl ON cl.id=a.client_id
                    WHERE f.deleted_at IS NULL AND f.status='pending' ORDER BY f.created_at");
$expiring = TiStudentFolder::expiring($set['days']);
$reqs     = db_all("SELECT r.*, cl.name AS client_name, a.is_minor FROM k30_ti_srequest r JOIN k30_ti_student_accounts a ON a.id=r.account_id LEFT JOIN k30_clients cl ON cl.id=a.client_id
                    WHERE r.status='pending' ORDER BY COALESCE(r.due_date,'9999-12-31'), r.created_at");
$declined = db_all("SELECT r.*, cl.name AS client_name FROM k30_ti_srequest r JOIN k30_ti_student_accounts a ON a.id=r.account_id LEFT JOIN k30_clients cl ON cl.id=a.client_id
                    WHERE r.status='declined' AND r.decided_at >= datetime('now','-60 days') ORDER BY r.decided_at DESC");
$link = fn(array $r) => 'konta.php?selected=' . (int)$r['account_id'] . '&amp;ptab=dokumenty#tab-dokumenty';

$KP_TITLE      = 'Dokumenty kursantów — Panel dydaktyka';
$KP_TOPBAR     = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => $dyd_name, 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">
<?php $KIER_CUR = 'dokumentyKursanta.php'; $KIER_LABEL = 'Dokumenty kursantów'; include __DIR__ . '/_kierownik_bar.php'; ?>
<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:1200px">
  <h1 class="h4 fw-bold mb-1"><i class="bi bi-folder2-open text-primary me-2" aria-hidden="true"></i>Dokumenty kursantów</h1>
  <p class="small text-body-secondary mb-3">Teczki i dokumenty do akceptacji prowadzisz w <a href="konta.php">Kontach kursantów</a> → zakładka „Dokumenty”. Tu widać, co wymaga Twojej uwagi.</p>
  <?= flash_html() ?>

  <div class="row g-3">
  <div class="col-lg-6">
    <div class="card"><div class="card-header"><i class="bi bi-hourglass-split me-1" aria-hidden="true"></i>Do zatwierdzenia (<?= count($pending) ?>)</div>
      <ul class="list-group list-group-flush">
      <?php if (!$pending): ?><li class="list-group-item small text-body-secondary">Nic nie czeka.</li><?php endif; ?>
      <?php foreach ($pending as $r): ?>
        <li class="list-group-item small"><a href="<?= $link($r) ?>" class="fw-semibold"><?= h($r['client_name'] ?: '—') ?></a> — <?= h($r['title']) ?>
          <span class="text-body-secondary">(<?= h(TiStudentFolder::SOURCES[$r['source']] ?? $r['source']) ?>, <?= h(date('d.m.Y', strtotime((string)$r['created_at']))) ?>)</span></li>
      <?php endforeach; ?>
      </ul></div>

    <div class="card"><div class="card-header"><i class="bi bi-calendar-x me-1" aria-hidden="true"></i>Wygasają w ciągu <?= (int)$set['days'] ?> dni albo wygasły (<?= count($expiring) ?>)</div>
      <ul class="list-group list-group-flush">
      <?php if (!$expiring): ?><li class="list-group-item small text-body-secondary">Brak.</li><?php endif; ?>
      <?php foreach ($expiring as $r): $st = TiStudentFolder::validity($r['valid_until'], $set['days']); ?>
        <li class="list-group-item small"><a href="<?= $link($r) ?>" class="fw-semibold"><?= h($r['client_name'] ?: '—') ?></a> — <?= h($r['title']) ?>
          <span class="badge <?= $st === 'expired' ? 'text-bg-danger' : 'text-bg-warning' ?>"><?= $st === 'expired' ? 'wygasł' : 'do' ?> <?= h(date('d.m.Y', strtotime((string)$r['valid_until']))) ?></span>
          <?php if ($r['expiry_notified_at']): ?><span class="text-body-secondary">· przypomniano <?= h(date('d.m.Y', strtotime((string)$r['expiry_notified_at']))) ?></span><?php endif; ?></li>
      <?php endforeach; ?>
      </ul></div>
  </div>
  <div class="col-lg-6">
    <div class="card"><div class="card-header"><i class="bi bi-pen me-1" aria-hidden="true"></i>Czekają na akceptację (<?= count($reqs) ?>)</div>
      <ul class="list-group list-group-flush">
      <?php if (!$reqs): ?><li class="list-group-item small text-body-secondary">Brak.</li><?php endif; ?>
      <?php foreach ($reqs as $r): $over = $r['due_date'] && $r['due_date'] < date('Y-m-d'); ?>
        <li class="list-group-item small"><a href="<?= $link($r) ?>" class="fw-semibold"><?= h($r['client_name'] ?: '—') ?></a> — <?= h($r['title']) ?>
          <?php if (!empty($r['is_minor'])): ?><span class="text-body-secondary">(opiekun)</span><?php endif; ?>
          <?php if ($r['due_date']): ?><span class="badge <?= $over ? 'text-bg-danger' : 'text-bg-light border' ?>"><?= $over ? 'po terminie' : 'termin' ?> <?= h(date('d.m.Y', strtotime((string)$r['due_date']))) ?></span><?php endif; ?></li>
      <?php endforeach; ?>
      </ul></div>

    <div class="card"><div class="card-header"><i class="bi bi-x-octagon me-1" aria-hidden="true"></i>Odmowy z ostatnich 60 dni (<?= count($declined) ?>)</div>
      <ul class="list-group list-group-flush">
      <?php if (!$declined): ?><li class="list-group-item small text-body-secondary">Brak.</li><?php endif; ?>
      <?php foreach ($declined as $r): ?>
        <li class="list-group-item small"><a href="<?= $link($r) ?>" class="fw-semibold"><?= h($r['client_name'] ?: '—') ?></a> — <?= h($r['title']) ?>
          <div class="text-body-secondary"><?= h($r['decided_name']) ?>, <?= h(date('d.m.Y', strtotime((string)$r['decided_at']))) ?>: „<?= h($r['decline_reason']) ?>”</div></li>
      <?php endforeach; ?>
      </ul></div>

    <div class="card"><div class="card-header"><i class="bi bi-bell me-1" aria-hidden="true"></i>Przypomnienia o ważności</div>
      <div class="card-body small">
        <form method="post" class="d-flex flex-wrap align-items-center gap-2">
          <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="settings">
          <div class="form-check mb-0"><input class="form-check-input" type="checkbox" name="enabled" value="1" id="sf-se"<?= $set['enabled'] ? ' checked' : '' ?>>
            <label class="form-check-label" for="sf-se">Wysyłaj e-mail</label></div>
          <label for="sf-sd">na</label>
          <input class="form-control form-control-sm" type="number" min="1" max="180" id="sf-sd" name="days" value="<?= (int)$set['days'] ?>" style="width:80px">
          <span>dni przed końcem ważności</span>
          <button class="btn btn-sm btn-outline-secondary ms-auto">Zapisz</button>
        </form>
        <p class="text-body-secondary mt-2 mb-0">Codziennie o 7:00 (CRON <code>ti_student_folder_expiry</code>). Każdy dokument dostaje jedno przypomnienie — do kursanta, a za niepełnoletniego do opiekuna. Zmiana daty ważności wysyła je ponownie.</p>
      </div></div>
  </div>
  </div>
</div>
</main>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
