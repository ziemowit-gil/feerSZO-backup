<?php
/**
 * karty30/ti/kursant/dokumenty.php — „Dokumenty” w panelu kursanta i w panelu rodzica (?as=parent).
 *   • Do akceptacji: dokumenty wystawione przez kadrę — czytam, akceptuję albo odmawiam z powodem. Za niepełnoletniego
 *     decyduje tylko opiekun; kursant widzi stan.
 *   • Teczka: dokumenty widoczne dla kursanta + wgrywanie własnych (czekają na zatwierdzenie kadry; odrzucone z powodem).
 *   • GET ?file=ID — plik z teczki (tylko widoczny dla tego konta).
 * Logika: modules/ti_student_folder/logic/studentFolder.php.
 */
require_once dirname(__DIR__, 3) . '/config.php';
require_once dirname(__DIR__, 3) . '/includes/db.php';
require_once dirname(__DIR__, 3) . '/includes/functions.php';
require_once dirname(__DIR__, 3) . '/includes/karty30.php';
require_once dirname(__DIR__, 3) . '/modules/ti_student_folder/logic/studentFolder.php';
require_once __DIR__ . '/auth.php';

// Kto patrzy: opiekun (panel rodzica) albo kursant. ?as=parent wybiera opiekuna, gdy w przeglądarce są obie sesje.
$asParent = ($_GET['as'] ?? $_POST['as'] ?? '') === 'parent';
if ($asParent || !student_current()) {
    $parent = parent_current();
    if (!$parent) { header('Location: ' . ($asParent ? 'parent.php' : 'login.php')); exit; }
    $kind = 'parent';
    $aid  = (int)$parent['student_id'];
} else {
    $student = student_require();
    $kind = 'student';
    $aid  = (int)$student['id'];
}
TiStudentFolder::migrate();
$acc    = db_one("SELECT a.*, cl.name AS client_name FROM k30_ti_student_accounts a LEFT JOIN k30_clients cl ON cl.id=a.client_id WHERE a.id=?", [$aid]) ?: [];
$isImp  = $kind === 'student' && !empty($student['imp']);
$self   = 'dokumenty.php' . ($kind === 'parent' ? '?as=parent' : '');
$selfQ  = $kind === 'parent' ? '&as=parent' : '';
$myName = $kind === 'parent' ? (string)($acc['guardian_name'] ?? '') : (string)($acc['client_name'] ?? '');
$canDecide = TiStudentFolder::canDecide($acc, $kind) && !$isImp;

if (isset($_GET['file'])) {
    $f = TiStudentFolder::file((int)$_GET['file']);
    $visible = $f && (int)$f['account_id'] === $aid && (in_array($f['source'], ['student', 'parent'], true) || ((int)$f['visible_student'] && $f['status'] === 'approved'));
    if (!$visible) { http_response_code(404); exit('Nie znaleziono dokumentu.'); }
    TiStudentFolder::send($f);
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(student_token(), (string)($_POST['_token'] ?? ''))) { http_response_code(403); exit('Nieprawidłowy token sesji.'); }
    $op = (string)($_POST['_op'] ?? '');
    if ($isImp) $err = 'Podgląd administratora — zmiany są wyłączone.';
    elseif ($op === 'upload') {
        [, $err] = TiStudentFolder::upload($aid, $_FILES['file'] ?? [], [
            'category' => $_POST['category'] ?? 'inne', 'title' => $_POST['title'] ?? '', 'note' => $_POST['note'] ?? '', 'valid_until' => $_POST['valid_until'] ?? '',
        ], $kind, null, $kind === 'parent' ? ('opiekun: ' . $myName) : $myName);
        if ($err === '') $_SESSION['k30_sfold_flash'] = ('Dokument wysłany. Organizator sprawdzi go i zatwierdzi.');
    } elseif ($op === 'delete') {
        $err = TiStudentFolder::delete((int)($_POST['file_id'] ?? 0), null, $myName, $aid);
        if ($err === '') $_SESSION['k30_sfold_flash'] = ('Usunięto dokument.');
    } elseif ($op === 'accept' || $op === 'decline') {
        $name = trim((string)($_POST['name'] ?? ''));
        if ($op === 'accept' && empty($_POST['confirm'])) $err = 'Zaznacz, że zapoznałeś(-aś) się z treścią dokumentu.';
        elseif (mb_strlen($name) < 3) $err = 'Wpisz swoje imię i nazwisko.';
        else $err = TiStudentFolder::decide((int)($_POST['request_id'] ?? 0), $aid, $op === 'accept', (string)($_POST['reason'] ?? ''), $kind, $name);
        if ($err === '') $_SESSION['k30_sfold_flash'] = ($op === 'accept' ? 'Dziękujemy — dokument zaakceptowany. Potwierdzenie jest w teczce.' : 'Zapisano odmowę. Organizator zobaczy Twój powód.');
    } else $err = 'Nieznana operacja.';
    if ($err === '') { header('Location: ' . $self); exit; }
}

$reqs  = TiStudentFolder::requests($aid);
$files = TiStudentFolder::files($aid, true);
$open  = (int)($_GET['req'] ?? 0);
$back  = $kind === 'parent' ? 'parent.php' : 'index.php';

$KP_TITLE  = 'Dokumenty — ' . ($kind === 'parent' ? 'panel rodzica' : 'panel kursanta');
$KP_TOPBAR = ['brand' => defined('ORG_NAME') ? ORG_NAME : 'Panel kursanta', 'icon' => 'pc-display',
              'user' => $kind === 'parent' ? ('Opiekun: ' . ($acc['client_name'] ?? '')) : (string)($student['login'] ?? ''),
              'logout' => $kind === 'parent' ? 'parent.php?logout=1' : 'index.php?logout=1'];
include __DIR__ . '/_layout_head.php';
$fmtDate = fn($d) => $d ? date('d.m.Y', strtotime((string)$d)) : '';
?>
<main id="main" class="container-xl px-3 py-4" style="max-width:900px">
  <p class="mb-2"><a href="<?= $back ?>" class="small"><i class="bi bi-arrow-left" aria-hidden="true"></i> Wróć do panelu</a></p>
  <h1 class="h4 fw-bold d-flex align-items-center gap-2 mb-1"><i class="bi bi-folder2-open text-primary" aria-hidden="true"></i>Dokumenty</h1>
  <p class="text-body-secondary small mb-3"><?= $kind === 'parent' ? 'Dokumenty kursanta: ' . h($acc['client_name'] ?? '') . '.' : 'Twoje dokumenty u organizatora zajęć.' ?></p>
  <?php if (!empty($_SESSION['k30_sfold_flash'])): ?><div class="alert alert-success py-2" role="status"><?= h((string)$_SESSION['k30_sfold_flash']) ?></div><?php unset($_SESSION['k30_sfold_flash']); endif; ?>
  <?php if ($err !== ''): ?><div class="alert alert-danger py-2" role="alert"><?= h($err) ?></div><?php endif; ?>
  <?php if ($isImp): ?><div class="alert alert-info py-2 small" role="status">Podgląd administratora — przyciski zmian są wyłączone.</div><?php endif; ?>

  <section class="mb-4" aria-labelledby="h-req">
    <h2 class="h5 fw-semibold" id="h-req">Do akceptacji</h2>
    <?php if (!$reqs): ?><p class="text-body-secondary small">Nie ma dokumentów do akceptacji.</p><?php endif; ?>
    <?php foreach ($reqs as $r): $rid = (int)$r['id']; $pending = $r['status'] === 'pending'; ?>
    <article class="card mb-3 <?= $pending ? 'border-warning' : '' ?>" aria-labelledby="req-t-<?= $rid ?>">
      <div class="card-body">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
          <h3 class="h6 fw-bold mb-0" id="req-t-<?= $rid ?>"><?= h($r['title']) ?></h3>
          <?php if ($pending): ?><span class="badge text-bg-warning">czeka na decyzję</span>
          <?php elseif ($r['status'] === 'accepted'): ?><span class="badge text-bg-success">zaakceptowany <?= h($fmtDate($r['decided_at'])) ?></span>
          <?php else: ?><span class="badge text-bg-danger">odmowa <?= h($fmtDate($r['decided_at'])) ?></span><?php endif; ?>
          <?php if ($pending && $r['due_date']): ?><span class="small text-body-secondary">termin: <?= h($fmtDate($r['due_date'])) ?></span><?php endif; ?>
        </div>
        <?php if ($r['body'] !== ''): ?>
        <div class="border rounded p-3 my-2 bg-body-tertiary" style="max-height:340px;overflow:auto;white-space:pre-wrap" tabindex="0" aria-label="Treść dokumentu: <?= h($r['title']) ?>"><?= h($r['body']) ?></div>
        <?php endif; ?>
        <div class="d-flex flex-wrap gap-2 my-2">
          <?php if ($r['file_id']): ?><a class="btn btn-sm btn-outline-secondary" href="dokumenty.php?file=<?= (int)$r['file_id'] . $selfQ ?>" target="_blank" rel="noopener"><i class="bi bi-paperclip me-1" aria-hidden="true"></i>Otwórz załącznik</a><?php endif; ?>
          <?php if ($r['confirm_file_id']): ?><a class="btn btn-sm btn-outline-success" href="dokumenty.php?file=<?= (int)$r['confirm_file_id'] . $selfQ ?>" target="_blank" rel="noopener"><i class="bi bi-file-earmark-check me-1" aria-hidden="true"></i>Potwierdzenie (PDF)</a><?php endif; ?>
        </div>
        <?php if ($r['status'] === 'declined' && $r['decline_reason'] !== ''): ?><p class="small mb-0"><strong>Powód odmowy:</strong> <?= h($r['decline_reason']) ?></p><?php endif; ?>

        <?php if ($pending && !$canDecide): ?>
        <p class="small text-body-secondary mb-0"><?= !empty($acc['is_minor']) && $kind === 'student' ? 'Ten dokument akceptuje Twój opiekun w panelu rodzica.' : 'Decyzję podejmuje kursant w swoim panelu.' ?></p>
        <?php elseif ($pending): ?>
        <form method="post" class="border-top pt-3 mt-2">
          <input type="hidden" name="_token" value="<?= h(student_token()) ?>">
          <input type="hidden" name="request_id" value="<?= $rid ?>">
          <?php if ($kind === 'parent'): ?><input type="hidden" name="as" value="parent"><?php endif; ?>
          <div class="mb-2" style="max-width:360px">
            <label class="form-label small mb-0" for="nm-<?= $rid ?>">Imię i nazwisko <?= $kind === 'parent' ? 'opiekuna' : '' ?></label>
            <input class="form-control form-control-sm" id="nm-<?= $rid ?>" name="name" value="<?= h($myName) ?>" required autocomplete="name">
          </div>
          <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="confirm" value="1" id="cf-<?= $rid ?>">
            <label class="form-check-label small" for="cf-<?= $rid ?>">Zapoznałem(-am) się z treścią dokumentu<?= $r['file_id'] ? ' i załącznikiem' : '' ?> i akceptuję go<?= $kind === 'parent' ? ' jako opiekun (przedstawiciel ustawowy) kursanta' : '' ?>.</label></div>
          <div class="d-flex flex-wrap gap-2 align-items-start">
            <button class="btn btn-success btn-sm" name="_op" value="accept"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Akceptuję</button>
            <details>
              <summary class="btn btn-outline-danger btn-sm">Nie akceptuję…</summary>
              <div class="mt-2" style="max-width:420px">
                <label class="form-label small mb-0" for="rs-<?= $rid ?>">Dlaczego? (organizator zobaczy ten powód)</label>
                <textarea class="form-control form-control-sm mb-2" id="rs-<?= $rid ?>" name="reason" rows="2"></textarea>
                <button class="btn btn-danger btn-sm" name="_op" value="decline">Wyślij odmowę</button>
              </div>
            </details>
          </div>
        </form>
        <?php endif; ?>
      </div>
    </article>
    <?php endforeach; ?>
  </section>

  <section class="mb-4" aria-labelledby="h-files">
    <h2 class="h5 fw-semibold" id="h-files">Teczka</h2>
    <?php if (!$files): ?><p class="text-body-secondary small">Teczka jest pusta.</p>
    <?php else: ?>
    <div class="table-responsive">
      <table class="table table-sm align-middle">
        <caption class="visually-hidden">Dokumenty w teczce</caption>
        <thead><tr><th scope="col">Dokument</th><th scope="col">Rodzaj</th><th scope="col">Ważność</th><th scope="col">Stan</th><th scope="col"><span class="visually-hidden">Akcje</span></th></tr></thead>
        <tbody>
        <?php foreach ($files as $f): $vs = TiStudentFolder::validity($f['valid_until']); ?>
          <tr>
            <td><a href="dokumenty.php?file=<?= (int)$f['id'] . $selfQ ?>" target="_blank" rel="noopener"><?= h($f['title']) ?></a>
              <div class="small text-body-secondary"><?= h($fmtDate($f['created_at'])) ?> · <?= h(TiStudentFolder::SOURCES[$f['source']] ?? '') ?></div></td>
            <td class="small"><?= h(TiStudentFolder::CATEGORIES[$f['category']] ?? '') ?></td>
            <td class="small"><?php if (!$f['valid_until']): ?>—<?php else: ?><span class="<?= $vs === 'expired' ? 'text-danger fw-semibold' : ($vs === 'soon' ? 'text-warning-emphasis fw-semibold' : '') ?>"><?= $vs === 'expired' ? 'wygasł ' : 'do ' ?><?= h($fmtDate($f['valid_until'])) ?></span><?php endif; ?></td>
            <td class="small">
              <?php if ($f['status'] === 'pending'): ?><span class="badge text-bg-warning">czeka na sprawdzenie</span>
              <?php elseif ($f['status'] === 'rejected'): ?><span class="badge text-bg-danger">odrzucony</span><?php if ($f['reject_reason'] !== ''): ?><div><?= h($f['reject_reason']) ?></div><?php endif; ?>
              <?php else: ?><span class="badge text-bg-success">przyjęty</span><?php endif; ?>
            </td>
            <td class="text-end">
              <?php if (in_array($f['source'], ['student', 'parent'], true) && $f['status'] !== 'approved' && !$isImp): ?>
              <form method="post" onsubmit="return confirm('Usunąć ten dokument?')">
                <input type="hidden" name="_token" value="<?= h(student_token()) ?>"><input type="hidden" name="_op" value="delete">
                <input type="hidden" name="file_id" value="<?= (int)$f['id'] ?>"><?php if ($kind === 'parent'): ?><input type="hidden" name="as" value="parent"><?php endif; ?>
                <button class="btn btn-sm btn-outline-danger" aria-label="Usuń: <?= h($f['title']) ?>"><i class="bi bi-trash" aria-hidden="true"></i></button>
              </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <?php if (!$isImp): ?>
    <div class="card mt-2"><div class="card-body">
      <h3 class="h6 fw-semibold">Wyślij dokument organizatorowi</h3>
      <p class="small text-body-secondary">Np. nowe orzeczenie, zaświadczenie albo podpisana zgoda. Organizator sprawdzi plik i go zatwierdzi.</p>
      <form method="post" enctype="multipart/form-data" class="row g-2">
        <input type="hidden" name="_token" value="<?= h(student_token()) ?>"><input type="hidden" name="_op" value="upload">
        <?php if ($kind === 'parent'): ?><input type="hidden" name="as" value="parent"><?php endif; ?>
        <div class="col-md-6"><label class="form-label small mb-0" for="up-f">Plik (PDF, JPG, PNG, DOCX, ODT — do 15 MB)</label>
          <input class="form-control form-control-sm" type="file" id="up-f" name="file" required accept="<?= TiStudentFolder::ACCEPT_ATTR ?>"></div>
        <div class="col-md-6"><label class="form-label small mb-0" for="up-t">Nazwa dokumentu</label>
          <input class="form-control form-control-sm" id="up-t" name="title" maxlength="200" placeholder="np. Orzeczenie o niepełnosprawności"></div>
        <div class="col-md-4"><label class="form-label small mb-0" for="up-c">Rodzaj</label>
          <select class="form-select form-select-sm" id="up-c" name="category">
            <?php foreach (TiStudentFolder::CATEGORIES as $k => $l): if ($k === 'potwierdzenie') continue; ?><option value="<?= $k ?>"<?= $k === 'inne' ? ' selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?>
          </select></div>
        <div class="col-md-4"><label class="form-label small mb-0" for="up-v">Ważny do (jeśli dotyczy)</label>
          <input class="form-control form-control-sm" type="date" id="up-v" name="valid_until"></div>
        <div class="col-md-4"><label class="form-label small mb-0" for="up-n">Uwagi (opcjonalnie)</label>
          <input class="form-control form-control-sm" id="up-n" name="note" maxlength="1000"></div>
        <div class="col-12"><button class="btn btn-primary btn-sm"><i class="bi bi-upload me-1" aria-hidden="true"></i>Wyślij</button></div>
      </form>
    </div></div>
    <?php endif; ?>
  </section>
</main>
<?php include __DIR__ . '/_layout_foot.php'; ?>
