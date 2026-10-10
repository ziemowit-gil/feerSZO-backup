<?php
/**
 * karty30/ti/dydaktyk/onboarding.php — lista startowa dla nowego prowadzącego.
 * Stan każdego kroku liczony z bazy (telefon, e-mail, certyfikat/klucz, przypisany kurs).
 * Statusy są tekstowe (nie tylko kolorem) — WCAG 1.4.1.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/x509_login.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/webauthn.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_protocols.php';

$me      = dyd_require();
$uid     = (int)$me['user_id'];
karty30_migrate();
x509_init();
webauthn_migrate();

$konto = db_one("SELECT name, email, phone_number FROM users WHERE id = ?", [$uid]);
$ma_mail    = trim((string)($konto['email'] ?? '')) !== '';
$ma_tel     = preg_replace('/\D/', '', (string)($konto['phone_number'] ?? '')) !== '';
$ma_cert    = x509_user_has_active_cert($uid);
$ma_klucz   = !empty(webauthn_get_credentials($uid));
// Prowadzący główny albo współprowadzący przypisanego kursu.
$ma_kurs    = (bool)db_one(
    "SELECT 1 FROM k30_ti_courses c WHERE c.instructor_id = ?
     OR EXISTS (SELECT 1 FROM k30_ti_course_coinstructors ci WHERE ci.course_id = c.id AND ci.user_id = ?) LIMIT 1",
    [$uid, $uid]);

// Kroki: [etykieta, czy zrobione, opis, adres, etykieta linku]
$kroki = [
    ['Konto i e-mail', $ma_mail,
     'Na adres e-mail przychodzą protokoły, certyfikat i powiadomienia.',
     'moj_certyfikat.php', 'Sprawdź w koncie'],
    ['Numer telefonu', $ma_tel,
     'Na telefon przychodzi kod SMS, który potwierdza podpis protokołu.',
     'moj_certyfikat.php', 'Uzupełnij telefon'],
    ['Certyfikat albo klucz YubiKey', $ma_cert || $ma_klucz,
     'Protokół podpisujesz certyfikatem (plik .p12 i hasło) albo kluczem YubiKey.',
     'moj_certyfikat.php', 'Przejdź do „Mój certyfikat”'],
    ['Przypisany kurs', $ma_kurs,
     'Protokoły dotyczą kursów, które prowadzisz. Bez przypisanego kursu nie ma czego zatwierdzać.',
     'index.php?tab=kursy', 'Zobacz kursy'],
];
$zrobione = count(array_filter($kroki, fn($k) => $k[1]));

// Grupy prowadzącego: numer (group_code, a gdy go nie ma — numer kursu) i nazwa.
$moje_grupy = db_all(
    "SELECT DISTINCT c.id, c.name, c.group_code, c.is_active FROM k30_ti_courses c
      WHERE c.instructor_id = ? OR EXISTS (SELECT 1 FROM k30_ti_course_coinstructors ci WHERE ci.course_id = c.id AND ci.user_id = ?)
      ORDER BY c.is_active DESC, c.name",
    [$uid, $uid]);

$KP_TITLE      = 'Onboarding — Panel dydaktyka';
$KP_TOPBAR     = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => (string)($me['name'] ?? ''), 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
?>
<link rel="stylesheet" href="../assets/ti_skin.css">

<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:760px">
<?= flash_html() ?>

<p class="small text-body-secondary mb-1">Instytucja: <strong><?= h(function_exists('org_setting') && org_setting('org_name') !== '' ? org_setting('org_name') : (defined('ORG_NAME') ? ORG_NAME : '')) ?></strong></p>
<h1 class="h4 fw-bold mb-2"><i class="bi bi-person-check text-primary me-2" aria-hidden="true"></i>Start w panelu prowadzącego</h1>
<p class="small mb-3">Ta lista pokazuje, co jest już gotowe, a co zostało do zrobienia. Postęp: <strong><?= $zrobione ?> z <?= count($kroki) ?></strong> kroków.</p>

<ol class="list-unstyled mb-0" aria-label="Kroki startowe">
<?php foreach ($kroki as $i => [$etykieta, $ok, $opis, $adres, $link]): ?>
  <li class="card border-0 shadow-sm mb-3">
    <div class="card-body d-flex gap-3 align-items-start">
      <div class="flex-shrink-0 fw-bold text-body-secondary" aria-hidden="true"><?= $i + 1 ?>.</div>
      <div class="flex-grow-1">
        <div class="d-flex align-items-center gap-2 flex-wrap">
          <h2 class="h6 fw-semibold mb-0"><?= h($etykieta) ?></h2>
          <?php if ($ok): ?>
          <span class="badge bg-success">zrobione</span>
          <?php else: ?>
          <span class="badge bg-warning text-dark">do zrobienia</span>
          <?php endif; ?>
        </div>
        <p class="small mb-2 mt-1"><?= h($opis) ?></p>
        <a href="<?= h($adres) ?>" class="btn btn-sm <?= $ok ? 'btn-outline-secondary' : 'btn-primary' ?>"><?= h($link) ?></a>
      </div>
    </div>
  </li>
<?php endforeach; ?>
</ol>

<p class="small text-body-secondary mt-3">
  <?php if ($moje_grupy): ?>
<section class="card border-0 shadow-sm mb-3" aria-labelledby="grupy-h">
  <div class="card-header bg-transparent fw-semibold" id="grupy-h">Twoje grupy</div>
  <div class="table-responsive">
  <table class="table table-sm align-middle mb-0">
    <thead><tr><th scope="col">Numer grupy</th><th scope="col">Nazwa</th><th scope="col">Stan</th></tr></thead>
    <tbody>
    <?php foreach ($moje_grupy as $g): ?>
      <tr>
        <td class="font-monospace small"><?= h($g['group_code'] !== '' ? $g['group_code'] : '#' . (int)$g['id']) ?></td>
        <td><?= h($g['name']) ?></td>
        <td><?= (int)$g['is_active'] ? '<span class="badge bg-success">aktywna</span>' : '<span class="badge bg-secondary">zakończona</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>
<?php endif; ?>

<p class="small mb-3"><strong>Dopisanie kursanta do grupy oznacza dołączenie go do zajęć.</strong> Kursant zobaczy wtedy plan i zajęcia tej grupy.</p>

Pytania kieruj do kierownika jednostki.
</p>
</div>
</main>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
