<?php
/**
 * karty30/ti/dydaktyk/regulaminy.php — Regulaminy TI w panelu dydaktyka (przeniesione z karty30/ti/terms_admin.php
 * i terms_pdf.php). Tylko kierownik (dyd_is_staff).
 *
 * Zakładka na każdy regulamin z bazy (k30_ti_terms): treść (edytor), aktywność, „Kto ma zatwierdzić” (TI_TERM_AUDIENCES),
 * „Dodaj regulamin”; ostatnie akceptacje z cofnięciem; czynność administratora — pominięcie wymogu albo akceptacja
 * zdalna w imieniu kursanta (z uzasadnieniem i oświadczeniem PDF), wgranie podpisanego skanu oświadczenia.
 * ?pdf=<accept_id> — oświadczenie administratora (PDF), ?dl_signed=<accept_id> — podpisany skan. Logika: includes/ti_terms.php.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 3) . '/includes/ti_terms.php';
$me  = dyd_require();
$uid = (int)$me['user_id'];
if (!dyd_is_staff()) { flash_set('warning', 'Regulaminy zmienia kierownik.'); header('Location: index.php'); exit; }
karty30_migrate();
ti_terms_migrate();

if (isset($_GET['dl_signed'])) {
    $sf = db_one("SELECT signed_file, signed_file_orig FROM k30_ti_terms_accepts WHERE id=?", [(int)$_GET['dl_signed']]);
    if ($sf && $sf['signed_file'] !== '') ti_term_signed_send_file($sf['signed_file'], $sf['signed_file_orig']);
    http_response_code(404); exit('Plik nie istnieje.');
}
if (isset($_GET['pdf'])) {
    $acc = db_one("SELECT a.*, t.type, t.title, t.body_html, t.version AS term_version FROM k30_ti_terms_accepts a JOIN k30_ti_terms t ON t.id=a.term_id WHERE a.id=?", [(int)$_GET['pdf']]);
    if (!$acc || strpos((string)$acc['accepted_by_role'], 'admin_') !== 0) { http_response_code(404); exit('Nie znaleziono oświadczenia.'); }
    ti_term_admin_pdf($acc, db_one("SELECT * FROM k30_clients WHERE id=?", [$acc['client_id']]) ?: [],
                      !empty($acc['admin_id']) ? db_one("SELECT * FROM users WHERE id=?", [$acc['admin_id']]) : null);
    exit;
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    $op = (string)($_POST['_op'] ?? ''); $type = (string)($_POST['type'] ?? '');
    $isTerm = $type !== '' && (bool)db_one("SELECT 1 FROM k30_ti_terms WHERE type=?", [$type]);
    $back = 'regulaminy.php?type=' . urlencode($type);
    if ($op === 'create_term') {
        $nt = ti_term_create((string)($_POST['title'] ?? ''), '<p>Treść regulaminu do uzupełnienia.</p>', (array)($_POST['audience'] ?? []), $uid);
        if ($nt === '') $err = 'Podaj tytuł nowego regulaminu.';
        else { flash_set('success', 'Dodano regulamin — uzupełnij treść i włącz go przełącznikiem „aktywny”.'); header('Location: regulaminy.php?type=' . urlencode($nt)); exit; }
    } elseif ($op === 'save_term' && $isTerm) {
        $title = trim((string)($_POST['title'] ?? ''));
        if ($title === '') $err = 'Tytuł regulaminu nie może być pusty.';
        else {
            ti_term_save($type, $title, (string)($_POST['body_html'] ?? ''), !empty($_POST['is_active']) ? 1 : 0, $uid);
            ti_term_set_audience($type, (array)($_POST['audience'] ?? []));
            flash_set('success', 'Regulamin zapisany.'); header('Location: ' . $back); exit;
        }
    } elseif ($op === 'admin_action' && $isTerm) {
        $mode = ($_POST['mode'] ?? '') === 'skip' ? 'skip' : 'remote';
        $client = (int)($_POST['client_id'] ?? 0); $reason = trim((string)($_POST['reason'] ?? ''));
        $term = ti_term_get($type);
        if (!$client) $err = 'Wybierz kursanta.';
        elseif ($reason === '') $err = 'Podaj uzasadnienie / podstawę czynności.';
        elseif ($aid = ti_term_admin_action($client, (int)$term['id'], $uid, $mode, $reason)) {
            flash_set('success', $mode === 'skip' ? 'Wymóg akceptacji regulaminu pominięty.' : 'Regulamin zaakceptowany zdalnie w imieniu kursanta.');
            header('Location: ' . $back . '&last_accept=' . $aid); exit;
        } else $err = 'Nie udało się zapisać czynności.';
    } elseif ($op === 'revoke_accept') {
        $aid = (int)($_POST['accept_id'] ?? 0);
        flash_set($aid && ti_term_accept_revoke($aid) ? 'success' : 'danger', $aid ? 'Akceptacja cofnięta — kursant zaakceptuje regulamin ponownie przy następnym wejściu.' : 'Nie udało się cofnąć akceptacji.');
        header('Location: ' . $back); exit;
    } elseif ($op === 'upload_signed') {
        $aid = (int)($_POST['accept_id'] ?? 0);
        $acc = $aid ? db_one("SELECT * FROM k30_ti_terms_accepts WHERE id=?", [$aid]) : null;
        if (!$acc || strpos((string)$acc['accepted_by_role'], 'admin_') !== 0) $err = 'Nie znaleziono czynności administratora do podpięcia skanu.';
        else {
            try {
                $up = ti_term_signed_upload('signed_file');
                if (!$up) $err = 'Wybierz plik do wgrania.';
                else {
                    if ($acc['signed_file'] !== '') ti_term_signed_delete_file($acc['signed_file']);
                    db_update('k30_ti_terms_accepts', ['signed_file' => $up['stored'], 'signed_file_orig' => $up['name'], 'signed_uploaded_at' => date('Y-m-d H:i:s')], $aid);
                    flash_set('success', 'Podpisane oświadczenie zapisane.'); header('Location: ' . $back); exit;
                }
            } catch (\RuntimeException $e) { $err = $e->getMessage(); }
        }
    }
}

$terms = ti_terms_all();
$active = (string)($_GET['type'] ?? ($_POST['type'] ?? 'szkolenia'));
if (!in_array($active, array_column($terms, 'type'), true)) $active = (string)($terms[0]['type'] ?? 'szkolenia');
$cur = null; foreach ($terms as $t) if ($t['type'] === $active) $cur = $t;
$accepts = db_all("SELECT a.*, c.name AS client_name, COALESCE(NULLIF(TRIM(u.first_name||' '||u.last_name),''), u.name) AS admin_name
                     FROM k30_ti_terms_accepts a JOIN k30_ti_terms t ON t.id=a.term_id JOIN k30_clients c ON c.id=a.client_id LEFT JOIN users u ON u.id=a.admin_id
                    WHERE t.type=? ORDER BY a.accepted_at DESC LIMIT 100", [$active]);
$clients = db_all("SELECT DISTINCT a.client_id, cl.name AS client_name, a.is_minor FROM k30_ti_student_accounts a JOIN k30_clients cl ON cl.id=a.client_id ORDER BY cl.name");
$lastAccept = (int)($_GET['last_accept'] ?? 0);
$aud = $cur ? array_filter(explode(',', (string)($cur['audience'] ?? ''))) : [];

$KP_TITLE = 'Regulaminy — Panel dydaktyka';
$KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => (string)($me['name'] ?? ''), 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.min.css">
<?php $KIER_CUR = 'regulaminy.php'; $KIER_LABEL = 'Regulaminy'; include __DIR__ . '/_kierownik_bar.php'; ?>
<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:1300px">
<h1 class="h4 fw-bold mb-2"><i class="bi bi-file-earmark-text text-primary me-2" aria-hidden="true"></i>Regulaminy</h1>
<?= flash_html() ?>
<?php if ($err !== ''): ?><div class="alert alert-danger py-2" role="alert"><?= h($err) ?></div><?php endif; ?>

<ul class="nav nav-tabs mb-3">
  <?php foreach ($terms as $t): ?><li class="nav-item"><a class="nav-link<?= $t['type'] === $active ? ' active' : '' ?>" href="?type=<?= urlencode($t['type']) ?>"<?= $t['type'] === $active ? ' aria-current="page"' : '' ?>><?= h(TI_TERM_TYPES[$t['type']] ?? $t['title']) ?><?= empty($t['is_active']) ? ' <span class="badge text-bg-secondary">nieaktywny</span>' : '' ?></a></li><?php endforeach; ?>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="collapse" href="#nowy" role="button" aria-expanded="false" aria-controls="nowy"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Dodaj regulamin</a></li>
</ul>
<form method="post" class="collapse card card-body border-0 shadow-sm mb-3" id="nowy" style="max-width:720px">
  <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="create_term">
  <label class="form-label fw-semibold" for="nt-t">Tytuł nowego regulaminu</label><input class="form-control mb-2" id="nt-t" name="title" required maxlength="200">
  <fieldset class="mb-2"><legend class="form-label fw-semibold fs-6">Kto ma go zatwierdzić?</legend>
    <?php foreach (TI_TERM_AUDIENCES as $ak => $al): ?><div class="form-check"><input class="form-check-input" type="checkbox" name="audience[]" value="<?= h($ak) ?>" id="nt-<?= h($ak) ?>"><label class="form-check-label" for="nt-<?= h($ak) ?>"><?= h($al) ?></label></div><?php endforeach; ?>
    <div class="form-text">Nic nie zaznaczone = wszyscy kursanci i beneficjenci.</div></fieldset>
  <div><button class="btn btn-primary btn-sm">Dodaj</button> <span class="small text-body-secondary">Powstanie jako nieaktywny — uzupełnij treść i włącz.</span></div>
</form>

<?php if ($cur): ?>
<div class="row g-3">
  <div class="col-xl-7">
    <form method="post" id="term-form" class="card border-0 shadow-sm"><div class="card-body">
      <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="save_term"><input type="hidden" name="type" value="<?= h($active) ?>">
      <div class="d-flex align-items-center gap-2 mb-2"><h2 class="h6 fw-bold mb-0">Treść regulaminu</h2>
        <span class="badge <?= $cur['is_active'] ? 'text-bg-success' : 'text-bg-secondary' ?> ms-auto"><?= $cur['is_active'] ? 'Aktywny' : 'Nieaktywny' ?> · v<?= (int)$cur['version'] ?></span></div>
      <label class="form-label fw-semibold" for="term-title">Tytuł wyświetlany kursantom</label>
      <input class="form-control mb-3" id="term-title" name="title" required maxlength="200" value="<?= h($cur['title']) ?>">
      <label class="form-label fw-semibold" for="term-editor">Treść</label>
      <div id="term-editor" style="min-height:320px;background:#fff"></div>
      <textarea name="body_html" id="term-body" class="d-none"><?= h($cur['body_html']) ?></textarea>
      <div class="form-text mb-3">Zmiana treści podnosi numer wersji — osoby, których dotyczy regulamin, zaakceptują go ponownie.</div>
      <fieldset class="mb-3"><legend class="form-label fw-semibold fs-6">Kto ma zatwierdzić ten regulamin?</legend>
        <?php foreach (TI_TERM_AUDIENCES as $ak => $al): ?><div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="audience[]" value="<?= h($ak) ?>" id="ta-<?= h($ak) ?>"<?= in_array($ak, $aud, true) ? ' checked' : '' ?>><label class="form-check-label" for="ta-<?= h($ak) ?>"><?= h($al) ?></label></div><?php endforeach; ?>
        <div class="form-text">Nic nie zaznaczone = wszyscy. Osoby spoza wybranych grup nie zobaczą regulaminu do akceptacji.</div></fieldset>
      <div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" id="term-active" name="is_active" value="1"<?= $cur['is_active'] ? ' checked' : '' ?>><label class="form-check-label" for="term-active">Regulamin aktywny (wymagany do zaakceptowania)</label></div>
      <button class="btn btn-primary"><i class="bi bi-floppy me-1" aria-hidden="true"></i>Zapisz</button>
      <?php if (!empty($cur['updated_at'])): ?><span class="small text-body-secondary ms-2">Zmienił(a): <?= h($cur['editor_name'] ?: '—') ?> · <?= h(date('d.m.Y H:i', strtotime((string)$cur['updated_at']))) ?></span><?php endif; ?>
    </div></form>
  </div>
  <div class="col-xl-5">
    <section class="card border-0 shadow-sm" aria-labelledby="acc-h"><div class="card-header fw-semibold d-flex" id="acc-h">Ostatnie akceptacje <span class="badge text-bg-secondary ms-auto"><?= count($accepts) ?></span></div>
      <?php if (!$accepts): ?><div class="card-body small text-body-secondary">Brak akceptacji dla tego regulaminu.</div><?php else: ?>
      <div class="table-responsive" style="max-height:520px;overflow-y:auto"><table class="table table-sm mb-0 small">
        <caption class="visually-hidden">Akceptacje regulaminu</caption>
        <thead class="table-light sticky-top"><tr><th scope="col">Kursant</th><th scope="col">Kto</th><th scope="col">Data</th><th scope="col">v.</th><th scope="col"><span class="visually-hidden">Akcje</span></th></tr></thead>
        <tbody><?php foreach ($accepts as $a): $adm = str_starts_with((string)$a['accepted_by_role'], 'admin_'); ?>
          <tr><th scope="row" class="fw-normal"><?= h($a['client_name']) ?></th>
            <td><span class="badge <?= $adm ? 'text-bg-warning' : ($a['accepted_by_role'] === 'rodzic' ? 'text-bg-info' : 'text-bg-secondary') ?>"<?= $adm && $a['admin_note'] !== '' ? ' title="' . h($a['admin_note']) . '"' : '' ?>><?= h(ti_terms_role_label($a['accepted_by_role'])) ?></span><?= $adm ? '<div style="font-size:.75rem">' . h($a['admin_name'] ?? '') . '</div>' : '' ?></td>
            <td class="text-nowrap"><?= h(date('d.m.Y H:i', strtotime((string)$a['accepted_at']))) ?></td><td>v<?= (int)$a['version'] ?></td>
            <td class="text-end text-nowrap">
              <?php if ($adm): ?><a class="btn btn-sm btn-outline-secondary py-0" href="regulaminy.php?pdf=<?= (int)$a['id'] ?>" target="_blank" title="Oświadczenie (PDF)"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i><span class="visually-hidden">Oświadczenie PDF</span></a>
                <?php if ($a['signed_file']): ?><a class="btn btn-sm btn-outline-success py-0" href="regulaminy.php?dl_signed=<?= (int)$a['id'] ?>" title="Podpisany skan"><i class="bi bi-file-earmark-check" aria-hidden="true"></i><span class="visually-hidden">Podpisany skan</span></a><?php endif; ?>
                <button type="button" class="btn btn-sm btn-outline-primary py-0" onclick="regSigned(<?= (int)$a['id'] ?>)" title="Wgraj podpisane oświadczenie"><i class="bi bi-upload" aria-hidden="true"></i><span class="visually-hidden">Wgraj skan</span></button><?php endif; ?>
              <form method="post" class="d-inline" onsubmit="return confirm('Cofnąć tę akceptację? Kursant zaakceptuje regulamin ponownie.')">
                <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="revoke_accept"><input type="hidden" name="type" value="<?= h($active) ?>"><input type="hidden" name="accept_id" value="<?= (int)$a['id'] ?>">
                <button class="btn btn-sm btn-outline-danger py-0" title="Cofnij akceptację"><i class="bi bi-trash" aria-hidden="true"></i><span class="visually-hidden">Cofnij akceptację</span></button></form>
            </td></tr>
        <?php endforeach; ?></tbody></table></div><?php endif; ?>
    </section>
  </div>
  <div class="col-12">
    <section class="card border-warning shadow-sm" aria-labelledby="adm-h"><div class="card-header fw-semibold" id="adm-h"><i class="bi bi-person-gear me-1" aria-hidden="true"></i>Pomiń wymóg albo zaakceptuj zdalnie w imieniu kursanta</div><div class="card-body">
      <?php if ($lastAccept): ?><div class="alert alert-success py-2">Czynność zapisana. <a class="alert-link" href="regulaminy.php?pdf=<?= $lastAccept ?>" target="_blank">Pobierz oświadczenie (PDF)</a>.</div><?php endif; ?>
      <p class="small text-body-secondary">Gdy kursant (lub opiekun) nie może sam zaakceptować regulaminu w panelu — np. potwierdził zgodę telefonicznie. Każda czynność jest zapisywana i daje oświadczenie do wydruku.</p>
      <form method="post" class="row g-2 align-items-end">
        <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="admin_action"><input type="hidden" name="type" value="<?= h($active) ?>">
        <div class="col-md-4"><label class="form-label small mb-0" for="ad-c">Kursant</label><select class="form-select form-select-sm" id="ad-c" name="client_id" required><option value="">— wybierz —</option><?php foreach ($clients as $c): ?><option value="<?= (int)$c['client_id'] ?>"><?= h($c['client_name']) ?><?= $c['is_minor'] ? ' (niepełnoletni)' : '' ?></option><?php endforeach; ?></select></div>
        <div class="col-md-5"><label class="form-label small mb-0" for="ad-r">Uzasadnienie / podstawa</label><input class="form-control form-control-sm" id="ad-r" name="reason" required maxlength="500" placeholder="np. zgoda potwierdzona telefonicznie przez opiekuna"></div>
        <div class="col-md-3 d-flex gap-2"><button class="btn btn-sm btn-outline-warning flex-fill" name="mode" value="skip">Pomiń wymóg</button><button class="btn btn-sm btn-warning flex-fill" name="mode" value="remote">Zaakceptuj zdalnie</button></div>
      </form>
    </div></section>
  </div>
</div>
<?php endif; ?>

<div class="modal fade" id="regSignedModal" tabindex="-1" aria-labelledby="regSignedH" aria-hidden="true"><div class="modal-dialog"><form method="post" enctype="multipart/form-data" class="modal-content">
  <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="upload_signed"><input type="hidden" name="type" value="<?= h($active) ?>"><input type="hidden" name="accept_id" id="reg-aid" value="">
  <div class="modal-header"><h2 class="modal-title h6" id="regSignedH">Wgraj podpisane oświadczenie</h2><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button></div>
  <div class="modal-body"><p class="small text-body-secondary">Pobierz oświadczenie (PDF), wydrukuj, podpisz i wgraj skan.</p>
    <label class="form-label" for="reg-file">Skan (PDF/JPG/PNG, maks. 15 MB)</label><input class="form-control" type="file" id="reg-file" name="signed_file" accept=".pdf,.jpg,.jpeg,.png" required></div>
  <div class="modal-footer"><button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button><button class="btn btn-primary btn-sm">Wgraj</button></div>
</form></div></div>
</div>
</main>
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.js"></script>
<script>
function regSigned(aid) { document.getElementById('reg-aid').value = aid; new bootstrap.Modal(document.getElementById('regSignedModal')).show(); }
(function () {
  var ta = document.getElementById('term-body'), el = document.getElementById('term-editor');
  if (!ta || !el || !window.Quill) return;
  var q = new Quill(el, { theme: 'snow', modules: { toolbar: [[{ header: [1, 2, 3, false] }], ['bold', 'italic', 'underline'], [{ list: 'ordered' }, { list: 'bullet' }], ['link'], ['clean']] } });
  if (ta.value.trim()) q.root.innerHTML = ta.value;
  document.getElementById('term-form').addEventListener('submit', function () { ta.value = q.root.innerHTML; });
})();
</script>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
