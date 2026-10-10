<?php
/**
 * karty30/ti/dydaktyk/kartaKursanta.php — Karta kursanta (panel dydaktyka): dydaktyka i postępy kursanta TI.
 *
 * Jedno miejsce pracy prowadzącego z kursantem: profil tyflo (narzędzia, technologie, formaty), sprzęt i licencje,
 * statusy opanowania modułów (punktów planu kursu), dziennik lekcji z obecnością (odczyt), notatki o postępach
 * i trudnościach (wewnętrzne albo „dla kursanta”) oraz materiały przypisane kursantowi.
 *
 * Dostęp: kierownik — każdy kursant; prowadzący — kursanci jego grup (TiDydaktykaManager::canAccess). Dane wrażliwe
 * (art. 9 RODO): otwarcia karty i zapisy trafiają do audit_logs. Panel dydaktyka ma własną sesję — token dyd_token().
 * Logika: modules/ti_dydaktyka/logic/tiDydaktykaManager.php; widoki sekcji: modules/ti_dydaktyka/views/.
 * WCAG 2.1: jeden <h1>, sekcje z <h2>, etykiety pól, tabele z <caption>, odznaki z ikoną i tekstem, błędy w role="alert".
 */
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 3) . '/modules/ti_dydaktyka/logic/tiDydaktykaManager.php';
require_once dirname(__DIR__, 3) . '/modules/ti_dydaktyka/views/_helpers.php';

$me  = dyd_require();
$uid = (int)$me['user_id'];
$actor = ['id' => $uid, 'staff' => dyd_is_staff(), 'name' => (string)($me['name'] ?? ''), 'real_id' => (int)($me['real_user_id'] ?? $uid)];
ti_dydaktyka_migrate();

$clientId  = (int)($_GET['client'] ?? $_POST['client'] ?? 0);
$courseCtx = (int)($_GET['course'] ?? 0);                         // grupa, z której przyszedł użytkownik (link powrotny)
if (!TiDydaktykaManager::canAccess($actor, $clientId)) {
    flash_set('danger', 'Nie masz dostępu do karty tego kursanta.');
    header('Location: index.php'); exit;
}
$M = 'TiDydaktykaManager';

// ── Operacje (POST → PRG; błąd walidacji = ta sama strona z wpisanymi danymi) ─────────
$errors = []; $old = null; $open = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    $op = (string)($_POST['_op'] ?? '');
    $done = function (string $type, string $msg, string $hash = '') use ($clientId, $courseCtx): void {
        flash_set($type, $msg);
        header('Location: ' . dk_url($clientId, $courseCtx, [], $hash)); exit;
    };
    $fail = function (array $res, string $section) use (&$errors, &$old, &$open): void { $errors = $res['errors']; $old = $_POST; $open = $section; };

    switch ($op) {
        case 'save_profile':
            $res = $M::saveProfile($actor, $clientId, $_POST);
            if ($res['ok']) $done('success', 'Profil tyflo zapisany.', '#profil');
            $fail($res, 'profil'); break;
        case 'save_equipment':
            $res = $M::saveEquipment($actor, $clientId, $_POST, (int)($_POST['equipment_id'] ?? 0));
            if ($res['ok']) $done('success', 'Sprzęt zapisany.', '#sprzet');
            $fail($res, 'sprzet'); break;
        case 'return_equipment':
            $res = $M::returnEquipment($actor, $clientId, (int)($_POST['equipment_id'] ?? 0));
            $done($res['ok'] ? 'success' : 'danger', $res['ok'] ? 'Sprzęt oznaczony jako zwrócony.' : implode(' ', $res['errors']), '#sprzet'); break;
        case 'delete_equipment':
            $res = $M::deleteEquipment($actor, $clientId, (int)($_POST['equipment_id'] ?? 0));
            $done($res['ok'] ? 'success' : 'danger', $res['ok'] ? 'Wpis o sprzęcie usunięty.' : implode(' ', $res['errors']), '#sprzet'); break;
        case 'save_note':
            $res = $M::saveNote($actor, $clientId, $_POST, (int)($_POST['note_id'] ?? 0));
            if ($res['ok']) $done('success', 'Notatka zapisana.', '#notatki');
            $fail($res, 'notatki'); break;
        case 'delete_note':
            $res = $M::deleteNote($actor, $clientId, (int)($_POST['note_id'] ?? 0));
            $done($res['ok'] ? 'success' : 'danger', $res['ok'] ? 'Notatka usunięta.' : implode(' ', $res['errors']), '#notatki'); break;
        case 'save_modules':
            $cid = (int)($_POST['course_id'] ?? 0);
            $res = $M::saveModules($actor, $clientId, $cid, (array)($_POST['modules'] ?? []));
            if ($res['ok']) $done('success', $res['changed'] > 0 ? 'Zapisano zmiany statusów modułów: ' . $res['changed'] . '.' : 'Brak zmian do zapisania.', '#moduly-' . $cid);
            $errors = $res['errors']; $open = 'moduly'; break;
        case 'assign_material':
            $res = $M::assignMaterials($actor, $clientId, (array)($_POST['material_ids'] ?? []), (string)($_POST['note'] ?? ''), (string)($_POST['due_at'] ?? ''));
            if ($res['ok']) $done('success', 'Przypisano materiałów: ' . $res['added'] . ($res['skipped'] ? ' (już przypisane pominięto: ' . $res['skipped'] . ')' : '') . '.', '#materialy');
            $fail($res, 'materialy'); break;
        case 'unassign_material':
            $res = $M::unassignMaterial($actor, $clientId, (int)($_POST['assignment_id'] ?? 0));
            $done($res['ok'] ? 'success' : 'danger', $res['ok'] ? 'Przypisanie materiału cofnięte.' : implode(' ', $res['errors']), '#materialy'); break;
    }
} else {
    $M::logView($actor, $clientId);
}

// ── Dane do widoku ───────────────────────────────────────────────────────────────────
$client  = $M::client($clientId);
$courses = $M::courses($actor, $clientId);
$profile = $M::profile($clientId); $profile_mode = $M::profileMode();
$equipment = $M::equipment($clientId); $licenses = $M::licenses($clientId); $licenseOptions = $M::licenseOptions($clientId);
$modulesByCourse = [];
foreach ($courses as $c) { $rows = $M::modules($clientId, (int)$c['id']); $modulesByCourse[(int)$c['id']] = ['rows' => $rows, 'sum' => $M::moduleSummary($rows)]; }
$timeline = $M::timeline($actor, $clientId, 30); $summary = $M::attendanceSummary($actor, $clientId);
$notes = $M::notes($clientId);
$assigned = $M::assignedMaterials($clientId); $assignable = $M::assignableMaterials($actor, $clientId);
$lessonOptions = array_slice($timeline, 0, 30);

// Formularze: wartości z błędnie wysłanego POST albo z edytowanego wiersza / zapisanego profilu
$sugg = ['tools' => $M::PROFILE_TOOLS, 'formats' => array_keys($M::PROFILE_FORMATS), 'tech' => $M::PROFILE_TECH];
$pf = ['notes' => ($open === 'profil' && $old) ? (string)($old['notes'] ?? '') : $profile['notes']];
foreach ($sugg as $k => $list) {
    if ($open === 'profil' && $old) { $pf[$k] = array_map('strval', (array)($old[$k] ?? [])); $pf[$k . '_other'] = (string)($old[$k . '_other'] ?? ''); }
    else { $pf[$k] = array_values(array_intersect($profile[$k], $list)); $pf[$k . '_other'] = implode(', ', array_diff($profile[$k], $list)); }
}
$editEquipId = (int)($_GET['equip'] ?? 0); $edit_equip = null;
foreach ($equipment as $q) if ((int)$q['id'] === $editEquipId) $edit_equip = $q;
$ef = ['kind' => '', 'model' => '', 'serial' => '', 'ownership' => 'own', 'lent_at' => '', 'returned_at' => '', 'license_assignment_id' => 0, 'note' => ''];
if ($edit_equip) foreach ($ef as $k => $_) $ef[$k] = (string)($edit_equip[$k] ?? $ef[$k]);
if ($open === 'sprzet' && $old) foreach ($ef as $k => $_) if (array_key_exists($k, $old)) $ef[$k] = (string)$old[$k];
if ($open === 'sprzet' && $old && !empty($old['equipment_id'])) { foreach ($equipment as $q) if ((int)$q['id'] === (int)$old['equipment_id']) $edit_equip = $q; }

$editNoteId = (int)($_GET['note'] ?? 0); $edit_note = null;
foreach ($notes as $n) if ((int)$n['id'] === $editNoteId && $M::canEditNote($actor, $n)) $edit_note = $n;
$nf = ['kind' => 'progress', 'topic' => '', 'body' => '', 'visibility' => 'staff', 'course_id' => 0, 'session_id' => 0];
if ($edit_note) foreach ($nf as $k => $_) $nf[$k] = $edit_note[$k] ?? $nf[$k];
if ($open === 'notatki' && $old) {
    foreach ($nf as $k => $_) if (array_key_exists($k, $old)) $nf[$k] = $old[$k];
    if (!empty($old['note_id'])) foreach ($notes as $n) if ((int)$n['id'] === (int)$old['note_id']) $edit_note = $n;
}
$af = ['note' => '', 'due_at' => ''];
if ($open === 'materialy' && $old) { $af['note'] = (string)($old['note'] ?? ''); $af['due_at'] = (string)($old['due_at'] ?? ''); }

$KP_TITLE  = 'Karta kursanta: ' . ($client['name'] ?? '') . ' — Panel dydaktyka';
$KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => $actor['name'] ?: 'Prowadzący', 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">
<style>
  /* Odznaki: jasne tło + ciemny tekst (≥ 7:1) + obramowanie w kolorze tekstu + ikona i etykieta tekstowa */
  .badge.dk-b { font-weight: 700; font-size: .78rem; padding: .3em .55em; border: 1px solid currentColor; white-space: nowrap; }
  .badge.dk-ok   { background: #d1e7dd; color: #0a3622; } .badge.dk-info { background: #cff4fc; color: #032830; }
  .badge.dk-bad  { background: #f8d7da; color: #58151c; } .badge.dk-warn { background: #fff3cd; color: #664d03; }
  .badge.dk-muted{ background: #e2e3e5; color: #2b2f32; } .badge.dk-prog { background: #cfe2ff; color: #052c65; }
  .badge.dk-goal { background: #e6dcf5; color: #2d1b4e; } .badge.dk-dark { background: #212529; color: #ffffff; }
  /* METRO: treść po lewej, menu sekcji (kafle 48px) po prawej, przyklejone przy przewijaniu */
  .dk-uklad { display: grid; grid-template-columns: minmax(0, 1fr) 260px; grid-template-areas: "tresc menu"; gap: 1.25rem; align-items: start; }
  .dk-tresc { grid-area: tresc; min-width: 0; }
  .dk-menu  { grid-area: menu; position: sticky; top: .75rem; }
  .dk-sections { display: flex; flex-direction: column; gap: .5rem; }
  .dk-sections a, body.ti-skin .dk-sections a { display: flex; align-items: center; gap: .6rem; min-height: 48px; padding: .5rem .8rem; border: 2px solid #1d1d1d; border-bottom: 5px solid #1ba1e2; border-radius: 0; background: #fff; color: #1d1d1d; text-decoration: none; font-weight: 600; font-size: .95rem; }
  .dk-sections a i { font-size: 1.15rem; color: #0f6fa8; }
  .dk-sections a:hover, body.ti-skin .dk-sections a:hover { background: #eef6fc; text-decoration: underline; }
  .dk-sections a:focus-visible { outline: 3px solid #facc15; outline-offset: 2px; }
  @media (max-width: 991.98px) {
    .dk-uklad { grid-template-columns: minmax(0, 1fr); grid-template-areas: "menu" "tresc"; }
    .dk-menu { position: static; }
    .dk-sections { flex-direction: row; flex-wrap: wrap; }
  }
  details > summary:focus-visible { outline: 3px solid #60a5fa; outline-offset: 2px; }
</style>

<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:1440px">

<nav aria-label="Ścieżka nawigacji" class="small mb-2">
  <a href="index.php">Panel dydaktyka</a> &rsaquo;
  <?php if ($courseCtx): ?><a href="index.php?course=<?= $courseCtx ?>&amp;tab=uczestnicy">Uczestnicy grupy</a> &rsaquo;<?php endif; ?>
  <strong>Karta kursanta</strong>
</nav>

<div class="d-flex align-items-center flex-wrap gap-2 mb-2">
  <h1 class="h4 mb-0 fw-bold"><i class="bi bi-person-vcard text-primary me-2" aria-hidden="true"></i>Karta kursanta: <?= h($client['name'] ?? '') ?></h1>
  <?php if (!empty($client['is_minor'])): ?><?= dk_badge('bi-person-hearts', 'dk-info', 'małoletni' . (!empty($client['guardian_name']) ? ' — opiekun: ' . $client['guardian_name'] : '')) ?><?php endif; ?>
</div>
<p class="small text-body-secondary mb-3">
  Grupy: <?php if (!$courses): ?>brak w Twoich grupach<?php endif; ?>
  <?php foreach ($courses as $i => $c): ?><?= $i ? ', ' : '' ?><a href="index.php?course=<?= (int)$c['id'] ?>&amp;tab=uczestnicy"><?= h($c['name']) ?></a><?= $c['enroll_status'] === 'active' ? '' : ' (wypisany)' ?><?php endforeach; ?>
  · Karta zawiera dane wrażliwe — jej otwarcia i zmiany są zapisywane w dzienniku audytu.
</p>

<?= flash_html() ?>
<?php if ($errors): ?>
<div class="alert alert-danger" role="alert" id="dk-errors" tabindex="-1">
  <strong>Nie zapisano — popraw dane:</strong>
  <ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul>
</div>
<script>document.getElementById('dk-errors').focus();</script>
<?php endif; ?>

<!-- Układ: sekcje karty po lewej, menu sekcji po prawej (na wąskim ekranie menu nad treścią).
     W kodzie menu jest PRZED treścią — klawiatura i czytnik ekranu najpierw trafiają na spis sekcji. -->
<div class="dk-uklad">
<nav aria-label="Sekcje karty kursanta" class="dk-menu">
  <ul class="list-unstyled mb-0 dk-sections">
    <li><a href="#profil"><i class="bi bi-person-badge" aria-hidden="true"></i>Profil tyflo</a></li>
    <li><a href="#sprzet"><i class="bi bi-laptop" aria-hidden="true"></i>Sprzęt i licencje</a></li>
    <li><a href="#moduly"><i class="bi bi-diagram-3" aria-hidden="true"></i>Postępy w modułach</a></li>
    <li><a href="#dziennik"><i class="bi bi-journal-text" aria-hidden="true"></i>Dziennik zajęć</a></li>
    <li><a href="#notatki"><i class="bi bi-sticky" aria-hidden="true"></i>Notatki</a></li>
    <li><a href="#materialy"><i class="bi bi-folder2-open" aria-hidden="true"></i>Materiały</a></li>
  </ul>
</nav>

<div class="dk-tresc">
<?php
$_v = dirname(__DIR__, 3) . '/modules/ti_dydaktyka/views/';
include $_v . '_profil.php';
include $_v . '_sprzet.php';
include $_v . '_moduly.php';
include $_v . '_dziennik.php';
include $_v . '_notatki.php';
include $_v . '_materialy.php';
?>
</div>
</div>

</div>
</main>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
