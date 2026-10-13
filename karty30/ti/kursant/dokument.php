<?php
/**
 * karty30/ti/kursant/dokument.php — „Dokument potwierdzający” po zalogowaniu (konto z zaznaczonym wymogiem w Kontach
 * kursantów). Skan (PDF/JPG/PNG do 10 MB) albo oświadczenie o posiadaniu dokumentu; potem wejście do panelu.
 * Logika: modules/ti_student_docs/logic/studentDocs.php; bramka: student_require() w auth.php.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/functions.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/karty30.php';
require_once dirname(dirname(dirname(__DIR__))) . '/modules/ti_student_docs/logic/studentDocs.php';
require_once __DIR__ . '/auth.php';

$student = student_require();
$aid = (int)$student['id'];
$r = TiStudentDocs::get($aid);
if (!$r || !(int)$r['requires_doc'] || (string)$r['doc_status'] !== '') { header('Location: index.php'); exit; }
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals(student_token(), (string)($_POST['_token'] ?? ''))) { http_response_code(403); exit('Nieprawidłowy token sesji.'); }
    if (($_POST['how'] ?? '') === 'scan') {
        $err = TiStudentDocs::submitScan($aid, $_FILES['scan'] ?? []);
    } elseif (($_POST['how'] ?? '') === 'declare') {
        if (empty($_POST['declare'])) $err = 'Zaznacz oświadczenie albo wgraj skan.';
        else TiStudentDocs::submitDeclaration($aid);
    } else $err = 'Wybierz: skan dokumentu albo oświadczenie.';
    if ($err === '') { header('Location: index.php'); exit; }
}
$label = TiStudentDocs::label($r);
$KP_TITLE  = 'Dokument potwierdzający — panel kursanta';
$KP_TOPBAR = ['brand' => defined('ORG_NAME') ? ORG_NAME : 'Panel kursanta', 'icon' => 'pc-display', 'user' => (string)($student['login'] ?? ''), 'logout' => 'index.php?logout=1'];
include __DIR__ . '/_layout_head.php';
?>
<main id="main" class="container-xl px-3 py-4" style="max-width:640px">
  <div class="card shadow-sm border-0"><div class="card-body p-4">
    <h1 class="h5 fw-bold d-flex align-items-center gap-2"><i class="bi bi-file-earmark-check text-primary" aria-hidden="true"></i>Dokument potwierdzający</h1>
    <p>Zanim przejdziesz do panelu, potwierdź uprawnienie: <strong><?= h($label) ?></strong>. Wgraj skan dokumentu <em>albo</em> złóż oświadczenie, że go posiadasz.</p>
    <?php if ($err !== ''): ?><div class="alert alert-danger py-2" role="alert"><?= h($err) ?></div><?php endif; ?>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="_token" value="<?= h(student_token()) ?>">
      <fieldset class="mb-3">
        <legend class="fs-6 fw-semibold">Jak potwierdzasz?</legend>
        <div class="form-check mb-2"><input class="form-check-input" type="radio" name="how" id="d-scan" value="scan"<?= ($_POST['how'] ?? 'scan') === 'scan' ? ' checked' : '' ?>><label class="form-check-label" for="d-scan">Wgrywam skan lub zdjęcie dokumentu</label></div>
        <div class="ms-4 mb-3"><label class="form-label small mb-1" for="d-file">Plik PDF, JPG lub PNG (do 10 MB)</label><input class="form-control" type="file" id="d-file" name="scan" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png"></div>
        <div class="form-check mb-2"><input class="form-check-input" type="radio" name="how" id="d-decl" value="declare"<?= ($_POST['how'] ?? '') === 'declare' ? ' checked' : '' ?>><label class="form-check-label" for="d-decl">Składam oświadczenie</label></div>
        <div class="ms-4"><div class="form-check"><input class="form-check-input" type="checkbox" name="declare" id="d-dc" value="1"><label class="form-check-label small" for="d-dc"><?= h(TiStudentDocs::declarationText($r)) ?></label></div></div>
      </fieldset>
      <button class="btn btn-primary w-100 fw-semibold"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Zapisz i przejdź do panelu</button>
    </form>
    <p class="text-center mt-3 mb-0"><a class="small text-body-secondary" href="index.php?logout=1">Wyloguj się</a></p>
  </div></div>
</main>
<?php include __DIR__ . '/_layout_foot.php'; ?>
