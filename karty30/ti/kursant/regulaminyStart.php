<?php
/**
 * karty30/ti/kursant/regulaminyStart.php — regulaminy do zatwierdzenia zaraz po zalogowaniu.
 * Pokazywane, gdy są aktywne regulaminy z warunkiem „blokuje panel do akceptacji”, które dotyczą tej osoby
 * (odbiorcy i warunki — includes/ti_terms.php: ti_terms_gate_pending). Po zaakceptowaniu wszystkich — wejście do panelu.
 * Bramka: student_require() w auth.php.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/config.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/db.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/functions.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/karty30.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_terms.php';
require_once __DIR__ . '/auth.php';

$student = student_require();
$cid = (int)$student['client_id'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_op'] ?? '') === 'accept_term') {
    if (!hash_equals(student_token(), (string)($_POST['_token'] ?? ''))) { http_response_code(403); exit('Nieprawidłowy token sesji.'); }
    $t = db_one("SELECT * FROM k30_ti_terms WHERE id=? AND is_active=1", [(int)($_POST['term_id'] ?? 0)]);
    if ($t && ti_term_applies($t, $cid) && !ti_term_parent_only($t, $cid) && !ti_term_accepted($cid, (string)$t['type'])) ti_term_accept($cid, (int)$t['id'], (int)$student['id']);
    header('Location: regulaminyStart.php'); exit;
}
$pending = ti_terms_gate_pending($cid);
if (!$pending) { header('Location: index.php'); exit; }
$KP_TITLE  = 'Regulaminy do zatwierdzenia — panel kursanta';
$KP_TOPBAR = ['brand' => defined('ORG_NAME') ? ORG_NAME : 'Panel kursanta', 'icon' => 'pc-display', 'user' => (string)($student['login'] ?? ''), 'logout' => 'index.php?logout=1'];
include __DIR__ . '/_layout_head.php';
?>
<main id="main" class="container-xl px-3 py-4" style="max-width:820px">
  <h1 class="h5 fw-bold d-flex align-items-center gap-2"><i class="bi bi-file-earmark-text text-primary" aria-hidden="true"></i>Regulaminy do zatwierdzenia</h1>
  <p>Zanim przejdziesz do panelu, przeczytaj i zaakceptuj <?= count($pending) === 1 ? 'poniższy regulamin' : 'poniższe regulaminy (' . count($pending) . ')' ?>. Potwierdzenie każdej akceptacji pobierzesz później w zakładce „Regulaminy”.</p>
  <?php foreach ($pending as $t) echo _ti_terms_acceptance_block($t, student_token(), 'regulaminy'); ?>
  <p class="text-center mt-3 mb-0"><a class="small text-body-secondary" href="index.php?logout=1">Wyloguj się</a></p>
</main>
<?php include __DIR__ . '/_layout_foot.php'; ?>
