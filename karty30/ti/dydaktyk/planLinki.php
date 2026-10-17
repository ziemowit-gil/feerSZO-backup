<?php
/**
 * karty30/ti/dydaktyk/planLinki.php — Publiczne linki do planów grup (kierownik).
 *
 * Osobny tajny link na każdą grupę: włącz / wyłącz / wygeneruj nowy adres, ustaw
 * zakres siatki, kontakty do prowadzących, listę terminów i komunikat dla odbiorców.
 * Strona publiczna: planPubliczny.php?g=<token> (na subdomenie ti.* także /plan/<token>).
 * Logika: modules/ti_plan_public/logic/.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 3) . '/modules/ti_plan_public/logic/planPublicPortal.php';
require_once dirname(__DIR__, 3) . '/modules/ti_duty/logic/dutyManager.php';
require_once dirname(__DIR__, 3) . '/modules/ti_plan_public/logic/planPublicNotify.php';

$me = dyd_require();
if (!dyd_is_staff()) { header('Location: index.php'); exit; }
$uid      = (int)$me['user_id'];
$dyd_name = (string)($me['name'] ?? '') ?: 'Kierownik';
tiPlanPublicMigrate();

$focus = (int)($_GET['course_id'] ?? 0);

// Akcje masowe na linkach zaznaczonych grup (widok „Lista”)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_op'] ?? '') === 'bulk_links') {
    dyd_token_check();
    $act = (string)($_POST['bulk_action'] ?? '');
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])))));
    $back = 'Location: planLinki.php?lv=lista' . (($_POST['q'] ?? '') !== '' ? '&q=' . urlencode((string)$_POST['q']) : '') . (($_POST['only'] ?? '') !== '' ? '&only=' . urlencode((string)$_POST['only']) : '');
    if (!$ids) { flash_set('warning', 'Nie zaznaczono żadnej grupy.'); header($back); exit; }
    $n = 0;
    foreach ($ids as $cid) {
        if (!db_one("SELECT 1 FROM k30_ti_courses WHERE id=?", [$cid])) continue;
        switch ($act) {
            case 'publish':    tiPlanPublicEnable($cid, $uid); break;
            case 'disable':    if (!tiPlanPublicGet($cid)) continue 2; tiPlanPublicDisable($cid); break;
            case 'dir_on':     tiPlanPublicEnable($cid, $uid);
                               db()->prepare("UPDATE k30_ti_plan_public_links SET in_directory=1, updated_at=datetime('now') WHERE course_id=?")->execute([$cid]); break;
            case 'dir_off':    db()->prepare("UPDATE k30_ti_plan_public_links SET in_directory=0, updated_at=datetime('now') WHERE course_id=?")->execute([$cid]); break;
            case 'regenerate': if (!tiPlanPublicGet($cid)) continue 2; tiPlanPublicRegenerate($cid, $uid); break;
            default: flash_set('warning', 'Wybierz akcję.'); header($back); exit;
        }
        $n++;
    }
    $lbl = ['publish' => 'Opublikowano plany', 'disable' => 'Wyłączono linki', 'dir_on' => 'Dodano do katalogu portalu', 'dir_off' => 'Usunięto z katalogu portalu', 'regenerate' => 'Wygenerowano nowe adresy'];
    flash_set($n ? 'success' : 'warning', ($lbl[$act] ?? 'Wykonano') . ' — grup: ' . $n . '.');
    header($back); exit;
}

// DK — dyżury kierownika / Zarządu
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array((string)($_POST['_op'] ?? ''), ['duty_save', 'duty_delete', 'duty_public'], true)) {
    dyd_token_check();
    $op = (string)$_POST['_op'];
    if ($op === 'duty_public') {
        org_setting_set('ti_duty_public', !empty($_POST['on']) ? '1' : '0');
        flash_set('success', !empty($_POST['on']) ? 'Zakładka DK opublikowana w portalu planu.' : 'Zakładka DK ukryta w portalu planu.');
    } elseif ($op === 'duty_delete') {
        tiDutyDelete((int)($_POST['id'] ?? 0));
        flash_set('warning', 'Dyżur usunięty.');
    } else {
        [$d, $err] = tiDutyValidate($_POST);
        $id = (int)($_POST['id'] ?? 0);
        if ($err !== '') { flash_set('danger', $err); header('Location: planLinki.php?tab=dk' . ($id ? '&edit=' . $id : '') . '#dk-form'); exit; }
        tiDutySave($d, $uid, $id ?: null);
        flash_set('success', $id ? 'Dyżur zapisany.' : 'Dyżur dodany.');
    }
    header('Location: planLinki.php?tab=dk'); exit;
}

// Portal (/plan/): zakładka Sale i linki prowadzących
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array((string)($_POST['_op'] ?? ''), ['rooms', 'privacy', 'full_pw', 'i_enable', 'i_disable', 'i_regenerate', 'i_options', 'all_groups', 'all_instr'], true)) {
    dyd_token_check();
    $op = (string)$_POST['_op'];
    if ($op === 'full_pw') {
        $pw = (string)($_POST['full_pw'] ?? '');
        if (!empty($_POST['clear'])) { tiPlanPublicFullSetPassword(''); flash_set('warning', 'Widok pełny planu wyłączony — dotychczasowe zalogowania wygasły.'); }
        elseif (mb_strlen($pw) < 10) { flash_set('danger', 'Hasło do pełnego planu musi mieć co najmniej 10 znaków.'); }
        else { tiPlanPublicFullSetPassword($pw); flash_set('success', 'Hasło do pełnego planu ustawione — poprzednie zalogowania wygasły. Przekaż je pracownikom bezpiecznym kanałem.'); }
        header('Location: planLinki.php#portal'); exit;
    }
    if ($op === 'privacy') {
        $pu = trim((string)($_POST['privacy_url'] ?? ''));
        if ($pu !== '' && !preg_match('#^https?://#i', $pu)) { flash_set('danger', 'Adres klauzuli musi zaczynać się od https://'); header('Location: planLinki.php#portal'); exit; }
        org_setting_set('ti_plan_public_privacy_url', mb_substr($pu, 0, 300));
        flash_set('success', 'Adres informacji o przetwarzaniu danych zapisany.');
        header('Location: planLinki.php#portal'); exit;
    }
    if ($op === 'all_groups' || $op === 'all_instr') {
        $dir = !empty($_POST['in_directory']);
        [$n, $all] = $op === 'all_groups' ? tiPlanPublicEnableAll($uid, $dir) : tiPlanPublicInstrEnableAll($uid, $dir);
        $what = $op === 'all_groups' ? 'grup' : 'prowadzących';
        flash_set('success', 'Opublikowano plany ' . $what . ': nowych ' . $n . ', łącznie aktywnych ' . $all . ($dir ? ' — wszystkie widoczne w katalogu portalu.' : '.'));
        header('Location: planLinki.php' . ($op === 'all_instr' ? '?tab=prowadzacy' : '')); exit;
    }
    if ($op === 'rooms') {
        org_setting_set('ti_plan_public_rooms', !empty($_POST['on']) ? '1' : '0');
        flash_set('success', !empty($_POST['on']) ? 'Zakładka „Sale” w portalu planu włączona.' : 'Zakładka „Sale” w portalu planu wyłączona.');
        header('Location: planLinki.php#portal'); exit;
    }
    $iu = (int)($_POST['user_id'] ?? 0);
    $u  = $iu ? db_one("SELECT name FROM users WHERE id=?", [$iu]) : null;
    if (!$u) { flash_set('danger', 'Nie znaleziono prowadzącego.'); header('Location: planLinki.php#prowadzacy'); exit; }
    switch ($op) {
        case 'i_enable':     tiPlanPublicInstrEnable($iu, $uid); flash_set('success', 'Plan prowadzącego ' . $u['name'] . ' opublikowany — skopiuj link.'); break;
        case 'i_disable':    tiPlanPublicInstrDisable($iu); flash_set('warning', 'Link do planu prowadzącego ' . $u['name'] . ' wyłączony.'); break;
        case 'i_regenerate': tiPlanPublicInstrRegenerate($iu, $uid); flash_set('success', 'Nowy adres planu prowadzącego ' . $u['name'] . ' — poprzedni już nie działa.'); break;
        case 'i_options':    if (!tiPlanPublicInstrGet($iu)) tiPlanPublicInstrEnable($iu, $uid);
                             tiPlanPublicInstrUpdateOptions($iu, $_POST); flash_set('success', 'Ustawienia planu prowadzącego ' . $u['name'] . ' zapisane.'); break;
    }
    header('Location: planLinki.php?tab=prowadzacy#i' . $iu); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    $op  = (string)($_POST['_op'] ?? '');
    $cid = (int)($_POST['course_id'] ?? 0);
    $ok  = $cid && db_one("SELECT id FROM k30_ti_courses WHERE id=?", [$cid]);
    if (!$ok) {
        flash_set('danger', 'Nie znaleziono grupy.');
    } else {
        $c = db_one("SELECT name FROM k30_ti_courses WHERE id=?", [$cid]);
        switch ($op) {
            case 'enable':
                tiPlanPublicEnable($cid, $uid);
                flash_set('success', 'Plan grupy „' . $c['name'] . '” jest opublikowany — skopiuj link poniżej.');
                break;
            case 'disable':
                tiPlanPublicDisable($cid);
                flash_set('warning', 'Publikacja planu grupy „' . $c['name'] . '” wyłączona — link przestał działać.');
                break;
            case 'regenerate':
                tiPlanPublicRegenerate($cid, $uid);
                flash_set('success', 'Nowy adres dla grupy „' . $c['name'] . '” — poprzedni link już nie działa.');
                break;
            case 'send':
                $ch = [];
                if (!empty($_POST['ch_email'])) $ch[] = 'email';
                if (!empty($_POST['ch_sms']))   $ch[] = 'sms';
                $res = TiPlanPublicNotifier::send($cid, $ch, $uid, (string)($_POST['extra_note'] ?? ''), !empty($_POST['with_guardians']));
                flash_set($res['ok'] ? 'success' : 'warning', 'Wysyłka linku do planu grupy „' . $c['name'] . '” — ' . $res['msg']);
                break;
            case 'options':
                if (!tiPlanPublicGet($cid)) tiPlanPublicEnable($cid, $uid);
                tiPlanPublicUpdateOptions($cid, $_POST);
                flash_set('success', 'Ustawienia linku grupy „' . $c['name'] . '” zapisane.');
                break;
            default:
                flash_set('danger', 'Nieznana operacja.');
        }
    }
    header('Location: planLinki.php' . ($cid ? '?course_id=' . $cid . '#g' . $cid : ''));
    exit;
}

$courses = k30_ti_courses(false);
usort($courses, fn($a, $b) => strcasecmp((string)$a['name'], (string)$b['name']));
$links   = tiPlanPublicMap(array_column($courses, 'id'));
$q = trim((string)($_GET['q'] ?? ''));
$only = (string)($_GET['only'] ?? '');
$rows = [];
foreach ($courses as $c) {
    $cid = (int)$c['id'];
    $l   = $links[$cid] ?? null;
    $on  = $l && (int)$l['is_enabled'] === 1;
    if ($q !== '' && mb_stripos((string)$c['name'], $q) === false) continue;
    if ($only === 'on' && !$on) continue;
    if ($only === 'off' && $on) continue;
    $rows[] = ['c' => $c, 'l' => $l, 'on' => $on];
}
$nOn = count(array_filter($links, fn($l) => (int)$l['is_enabled'] === 1));
$nDir = count(array_filter($links, fn($l) => (int)$l['is_enabled'] === 1 && (int)($l['in_directory'] ?? 0) === 1));
$tab = in_array($_GET['tab'] ?? '', ['prowadzacy', 'dk'], true) ? (string)$_GET['tab'] : 'grupy';
// Zakładka Grupy: widok „Lista” (tabela + akcje masowe, domyślny) albo „Karty” (ustawienia, wysyłka linku)
$lv = (($_GET['lv'] ?? '') === 'karty' || $focus) ? 'karty' : 'lista';
$instructors = $tab === 'prowadzacy' ? tiPlanPublicInstructors() : [];
$iLinks = tiPlanPublicInstrMap();
$roomsOn = tiPlanPublicRoomsEnabled();
$portalUrl = tiPlanPublicPortalUrl();

$KP_TITLE  = 'Linki do planów grup — Panel dydaktyka';
$KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => $dyd_name, 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">
<style>
  .pl-card { border: 1px solid #adb5bd; border-left-width: 6px; border-radius: 4px; background: #fff; margin-bottom: .75rem; }
  .pl-card.is-on  { border-left-color: #0a3622; }
  .pl-card.is-off { border-left-color: #6c757d; }
  .pl-card:target { box-shadow: 0 0 0 3px #10335c; }
  .pl-head { display: flex; flex-wrap: wrap; gap: .5rem 1rem; align-items: center; padding: .6rem .9rem; }
  .pl-body { border-top: 1px dashed #dee2e6; padding: .7rem .9rem; }
  .badge.pl-b { font-weight: 700; font-size: .78rem; padding: .3em .55em; border: 1px solid currentColor; white-space: nowrap; }
  .badge.pl-on  { background: #d1e7dd; color: #0a3622; }
  .badge.pl-off { background: #e2e3e5; color: #2b2f32; }
  .pl-url { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .85rem; }
  .pl-stats { font-size: .82rem; color: #495057; }
</style>

<?php $KIER_CUR = 'planLinki.php'; $KIER_LABEL = 'Linki do planów grup';
   include __DIR__ . '/_kierownik_bar.php'; ?>

<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:1100px">

<div class="d-flex align-items-center mb-2 gap-2 flex-wrap">
  <h1 class="h4 mb-0 fw-bold"><i class="bi bi-link-45deg text-primary me-2" aria-hidden="true"></i>Publiczne linki do planów grup</h1>
  <span class="text-body-secondary small">osobny tajny adres dla każdej grupy i prowadzącego, portal z katalogiem — bez logowania, bez danych kursantów</span>
</div>
<p class="small text-body-secondary mb-3">
  Link pokazuje plan grupy tydzień po tygodniu (siatka z kartami zajęć, na telefonie dzień pod dniem) i listę terminów.
  Odbiorca widzi zawsze aktualny stan — nie trzeba rozsyłać nowych PDF-ów po zmianie terminu.
  Wyłączenie linku blokuje go natychmiast; „Nowy adres” unieważnia stary (np. gdy trafił do niepowołanych osób).
  Link można też wysłać kursantom grupy e-mailem / SMS-em (szablon <em>ti_plan_link</em>, wg zgód z kont kursantów).
  Opublikowanych: <strong><?= $nOn ?></strong> z <?= count($courses) ?> grup.
</p>

<?= flash_html() ?>

<section class="pl-card is-on" id="portal" aria-labelledby="portal-h">
  <div class="pl-head">
    <h2 class="h6 mb-0 fw-bold" id="portal-h"><i class="bi bi-grid-3x3-gap me-1" aria-hidden="true"></i>Portal planu zajęć</h2>
    <span class="small text-body-secondary">katalog Grupy / Prowadzący / Sale — pokazuje TYLKO grupy i prowadzących z zaznaczonym „w katalogu” (grup w katalogu: <?= $nDir ?>)</span>
    <div class="ms-auto d-flex gap-1 flex-wrap">
      <a class="btn btn-sm btn-outline-primary" href="<?= h($portalUrl) ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Otwórz portal</a>
      <form method="post" class="d-inline">
        <input type="hidden" name="_token" value="<?= dyd_token() ?>"><input type="hidden" name="_op" value="rooms"><input type="hidden" name="on" value="<?= $roomsOn ? '' : '1' ?>">
        <button class="btn btn-sm <?= $roomsOn ? 'btn-outline-danger' : 'btn-outline-success' ?>"><i class="bi bi-door-open me-1" aria-hidden="true"></i><?= $roomsOn ? 'Wyłącz zakładkę Sale' : 'Włącz zakładkę Sale' ?></button>
      </form>
    </div>
  </div>
  <div class="pl-body">
    <div class="input-group input-group-sm">
      <span class="input-group-text">adres portalu</span>
      <input type="text" class="form-control pl-url" id="portal-url" value="<?= h($portalUrl) ?>" readonly onfocus="this.select()" aria-label="Adres portalu planu">
      <button type="button" class="btn btn-outline-secondary" data-copy="portal-url"><i class="bi bi-clipboard me-1" aria-hidden="true"></i>Kopiuj</button>
    </div>
    <p class="small text-body-secondary mb-0 mt-1">Sale: <?= $roomsOn ? '<strong>włączone</strong>' : 'wyłączone' ?> — zajętość sal z terminów TI i stan rezerwacji (zarezerwowana / do potwierdzenia); grupy spoza katalogu widoczne jako „niepubliczna”.</p>
    <p class="small mb-1 mt-2"><strong>RODO na stronach publicznych:</strong> bez danych uczestników, prowadzący inicjałami, nazwy grup zawierające imię lub nazwisko uczestnika
      zastępowane („przedmiot — grupa nr …”) albo „nazwą publiczną” z ustawień grupy; w katalogu bez linków do spotkań online i bez kontaktów prowadzących; bez plików cookie i analityki.</p>
    <form method="post" class="input-group input-group-sm">
      <input type="hidden" name="_token" value="<?= dyd_token() ?>"><input type="hidden" name="_op" value="privacy">
      <label class="input-group-text" for="privacy-url">klauzula RODO (link w stopce)</label>
      <input type="url" class="form-control" id="privacy-url" name="privacy_url" maxlength="300" placeholder="https://…/klauzula/…" value="<?= h((string)org_setting('ti_plan_public_privacy_url')) ?>">
      <button class="btn btn-outline-primary">Zapisz</button>
    </form>
    <?php $hasFull = tiPlanPublicFullHash() !== ''; ?>
    <form method="post" class="input-group input-group-sm mt-2" autocomplete="off">
      <input type="hidden" name="_token" value="<?= dyd_token() ?>"><input type="hidden" name="_op" value="full_pw">
      <label class="input-group-text" for="full-pw">hasło do pełnego planu</label>
      <input type="password" class="form-control" id="full-pw" name="full_pw" minlength="10" autocomplete="new-password" placeholder="<?= $hasFull ? 'ustawione — wpisz nowe, aby zmienić' : 'min. 10 znaków' ?>">
      <button class="btn btn-outline-primary" name="set" value="1">Ustaw</button>
      <?php if ($hasFull): ?><button class="btn btn-outline-danger" name="clear" value="1" formnovalidate onclick="return confirm('Wyłączyć widok pełny? Wszyscy zalogowani zostaną wylogowani.')">Wyłącz</button><?php endif; ?>
    </form>
    <p class="small text-body-secondary mb-0 mt-1">Widok pełny (<?= $hasFull ? '<strong>włączony</strong>' : 'wyłączony' ?>): po wpisaniu hasła na stronie portalu (link „Pełny plan — dla pracowników”) widać wszystkie grupy z prawdziwymi nazwami, pełne nazwiska prowadzących,
      linki do zajęć online i kontakty. Zalogowanie jest pamiętane 90 dni na danym urządzeniu; zmiana hasła wylogowuje wszystkich.</p>
  </div>
</section>

<ul class="nav nav-tabs mb-3" role="tablist">
  <li class="nav-item"><a class="nav-link<?= $tab === 'grupy' ? ' active' : '' ?>"<?= $tab === 'grupy' ? ' aria-current="page"' : '' ?> href="planLinki.php">Grupy</a></li>
  <li class="nav-item"><a class="nav-link<?= $tab === 'prowadzacy' ? ' active' : '' ?>"<?= $tab === 'prowadzacy' ? ' aria-current="page"' : '' ?> href="planLinki.php?tab=prowadzacy" id="prowadzacy">Prowadzący</a></li>
  <li class="nav-item"><a class="nav-link<?= $tab === 'dk' ? ' active' : '' ?>"<?= $tab === 'dk' ? ' aria-current="page"' : '' ?> href="planLinki.php?tab=dk" id="dk">DK — dyżury kierownika / Zarządu</a></li>
</ul>

<?php if ($tab === 'dk'):
  $duties = tiDutyAll(); $dutyPub = tiDutyPublic();
  $ed = isset($_GET['edit']) ? tiDutyGet((int)$_GET['edit']) : null;
  $ev = fn(string $k, $def = '') => h((string)($ed[$k] ?? $def)); ?>
<div class="d-flex flex-wrap gap-2 align-items-center mb-3 p-2 border rounded bg-body-tertiary">
  <span class="small">Zakładka DK w portalu planu: <?= $dutyPub ? '<strong>opublikowana</strong>' : 'ukryta' ?></span>
  <form method="post" class="d-inline">
    <input type="hidden" name="_token" value="<?= dyd_token() ?>"><input type="hidden" name="_op" value="duty_public"><input type="hidden" name="on" value="<?= $dutyPub ? '' : '1' ?>">
    <button class="btn btn-sm <?= $dutyPub ? 'btn-outline-danger' : 'btn-success' ?>"><?= $dutyPub ? 'Ukryj w portalu' : 'Opublikuj w portalu' ?></button>
  </form>
  <?php if ($dutyPub): ?><a class="btn btn-sm btn-outline-primary" href="<?= h(str_ends_with(tiPlanPublicPortalUrl(), '/plan/') ? tiPlanPublicPortalUrl() . 'dk' : tiPlanPublicPortalUrl() . '?k=dk') ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Otwórz DK</a><?php endif; ?>
  <span class="small text-body-secondary">Publicznie: inicjały + funkcja + kontakt dyżurowy; pełne imię i nazwisko tylko w widoku pełnym (po haśle).</span>
</div>

<section class="pl-card is-on" id="dk-form" aria-labelledby="dk-form-h">
  <div class="pl-head"><h2 class="h6 mb-0 fw-bold" id="dk-form-h"><?= $ed ? 'Edycja dyżuru' : 'Dodaj dyżur' ?></h2>
    <?php if ($ed): ?><a class="small ms-auto" href="planLinki.php?tab=dk">anuluj edycję</a><?php endif; ?></div>
  <div class="pl-body">
    <form method="post" class="row g-2 align-items-end">
      <input type="hidden" name="_token" value="<?= dyd_token() ?>"><input type="hidden" name="_op" value="duty_save"><input type="hidden" name="id" value="<?= (int)($ed['id'] ?? 0) ?>">
      <fieldset class="col-12 mb-1"><legend class="form-label small mb-0">Rodzaj</legend>
        <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="kind" id="dk-w" value="weekly" <?= ($ed['kind'] ?? 'weekly') === 'weekly' ? 'checked' : '' ?>><label class="form-check-label small" for="dk-w">stały (co tydzień)</label></div>
        <div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="kind" id="dk-d" value="date" <?= ($ed['kind'] ?? '') === 'date' ? 'checked' : '' ?>><label class="form-check-label small" for="dk-d">w konkretnym dniu (zastępuje stałe dyżury tego dnia)</label></div>
      </fieldset>
      <div class="col-6 col-md-2"><label class="form-label small mb-0" for="dk-dow">Dzień tygodnia (stały)</label>
        <select class="form-select form-select-sm" id="dk-dow" name="dow"><?php foreach (TI_DAYS_PL_FULL as $n => $lbl): ?><option value="<?= $n ?>" <?= (int)($ed['dow'] ?? 1) === $n ? 'selected' : '' ?>><?= h($lbl) ?></option><?php endforeach; ?></select></div>
      <div class="col-6 col-md-2"><label class="form-label small mb-0" for="dk-vf">obowiązuje od</label><input type="date" class="form-control form-control-sm" id="dk-vf" name="valid_from" value="<?= $ev('valid_from') ?>"></div>
      <div class="col-6 col-md-2"><label class="form-label small mb-0" for="dk-vt">do</label><input type="date" class="form-control form-control-sm" id="dk-vt" name="valid_to" value="<?= $ev('valid_to') ?>"></div>
      <div class="col-6 col-md-2"><label class="form-label small mb-0" for="dk-date">Data (konkretny dzień)</label><input type="date" class="form-control form-control-sm" id="dk-date" name="duty_date" value="<?= $ev('duty_date') ?>"></div>
      <div class="col-6 col-md-2 d-flex align-items-end"><div class="form-check mb-1"><input class="form-check-input" type="checkbox" id="dk-cn" name="is_cancelled" value="1" <?= (int)($ed['is_cancelled'] ?? 0) ? 'checked' : '' ?>><label class="form-check-label small" for="dk-cn">brak dyżuru w tym dniu</label></div></div>
      <div class="w-100"></div>
      <div class="col-6 col-md-1"><label class="form-label small mb-0" for="dk-tf">od</label><input type="time" class="form-control form-control-sm" id="dk-tf" name="time_from" value="<?= $ev('time_from') ?>"></div>
      <div class="col-6 col-md-1"><label class="form-label small mb-0" for="dk-tt">do</label><input type="time" class="form-control form-control-sm" id="dk-tt" name="time_to" value="<?= $ev('time_to') ?>"></div>
      <div class="col-md-3"><label class="form-label small mb-0" for="dk-pn">Osoba (imię i nazwisko)</label><input type="text" class="form-control form-control-sm" id="dk-pn" name="person_name" maxlength="120" value="<?= $ev('person_name') ?>"></div>
      <div class="col-md-2"><label class="form-label small mb-0" for="dk-rl">Funkcja</label><input type="text" class="form-control form-control-sm" id="dk-rl" name="role_label" maxlength="80" list="dk-roles" value="<?= $ev('role_label') ?>">
        <datalist id="dk-roles"><?php foreach (TI_DUTY_ROLES as $r): ?><option value="<?= h($r) ?>"><?php endforeach; ?></datalist></div>
      <div class="col-md-3"><label class="form-label small mb-0" for="dk-pl">Miejsce / forma</label><input type="text" class="form-control form-control-sm" id="dk-pl" name="place" maxlength="200" value="<?= $ev('place') ?>" placeholder="np. biuro, pok. 12 / telefonicznie / Teams"></div>
      <div class="col-md-2"><label class="form-label small mb-0" for="dk-ph">Telefon dyżurowy</label><input type="tel" class="form-control form-control-sm" id="dk-ph" name="contact_phone" maxlength="40" value="<?= $ev('contact_phone') ?>"></div>
      <div class="col-md-3"><label class="form-label small mb-0" for="dk-em">E-mail dyżurowy</label><input type="email" class="form-control form-control-sm" id="dk-em" name="contact_email" maxlength="120" value="<?= $ev('contact_email') ?>"></div>
      <div class="col-md-5"><label class="form-label small mb-0" for="dk-nt">Uwaga (opcjonalna)</label><input type="text" class="form-control form-control-sm" id="dk-nt" name="note" maxlength="300" value="<?= $ev('note') ?>"></div>
      <div class="col-auto"><button class="btn btn-sm btn-primary"><?= $ed ? 'Zapisz' : 'Dodaj dyżur' ?></button></div>
    </form>
    <p class="small text-body-secondary mb-0 mt-2">Kontakt dyżurowy wpisuj służbowy (nie prywatny) — trafia na stronę publiczną.</p>
  </div>
</section>

<?php foreach (['weekly' => 'Dyżury stałe (co tydzień)', 'date' => 'Dyżury w konkretnych dniach'] as $kk => $kl): $rows = array_values(array_filter($duties, fn($r) => $r['kind'] === $kk)); ?>
<h2 class="h6 fw-bold mt-3"><?= h($kl) ?> (<?= count($rows) ?>)</h2>
<?php if (!$rows): ?><p class="small text-body-secondary">Brak.</p><?php else: ?>
<div class="table-responsive"><table class="table table-sm align-middle">
  <thead><tr><th scope="col"><?= $kk === 'weekly' ? 'Dzień' : 'Data' ?></th><th scope="col">Godziny</th><th scope="col">Dyżurny</th><th scope="col">Miejsce</th><th scope="col">Kontakt</th><th scope="col"><?= $kk === 'weekly' ? 'Obowiązuje' : 'Uwaga' ?></th><th scope="col"><span class="visually-hidden">Akcje</span></th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r): $past = $kk === 'date' ? (string)$r['duty_date'] < date('Y-m-d') : (!empty($r['valid_to']) && (string)$r['valid_to'] < date('Y-m-d')); ?>
  <tr<?= $past ? ' class="text-body-secondary"' : '' ?>>
    <td><?= $kk === 'weekly' ? h(TI_DAYS_PL_FULL[(int)$r['dow']] ?? '') : h(date('d.m.Y', strtotime((string)$r['duty_date'])) . ' (' . (TI_DAYS_PL_FULL[(int)date('N', strtotime((string)$r['duty_date']))] ?? '') . ')') ?></td>
    <td><?= (int)$r['is_cancelled'] ? '<span class="badge text-bg-danger">brak dyżuru</span>' : h($r['time_from'] . '–' . $r['time_to']) ?></td>
    <td><?= h($r['person_name']) ?><?= $r['role_label'] !== '' ? '<br><span class="small text-body-secondary">' . h($r['role_label']) . '</span>' : '' ?></td>
    <td><?= h($r['place']) ?></td>
    <td class="small"><?= h(implode(' · ', array_filter([(string)$r['contact_phone'], (string)$r['contact_email']]))) ?></td>
    <td class="small"><?= $kk === 'weekly' ? h(($r['valid_from'] ? 'od ' . date('d.m.Y', strtotime((string)$r['valid_from'])) . ' ' : '') . ($r['valid_to'] ? 'do ' . date('d.m.Y', strtotime((string)$r['valid_to'])) : '') ?: 'bez ograniczeń') : h($r['note']) ?><?= $past ? ' <span class="badge text-bg-secondary">minął</span>' : '' ?></td>
    <td class="text-nowrap">
      <a class="btn btn-sm btn-outline-secondary" href="planLinki.php?tab=dk&amp;edit=<?= (int)$r['id'] ?>#dk-form">Edytuj<span class="visually-hidden"> dyżur <?= h($r['person_name']) ?></span></a>
      <form method="post" class="d-inline" onsubmit="return confirm('Usunąć ten dyżur?')">
        <input type="hidden" name="_token" value="<?= dyd_token() ?>"><input type="hidden" name="_op" value="duty_delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
        <button class="btn btn-sm btn-outline-danger">Usuń<span class="visually-hidden"> dyżur <?= h($r['person_name']) ?></span></button>
      </form>
    </td>
  </tr>
  <?php endforeach; ?>
  </tbody>
</table></div>
<?php endif; ?>
<?php endforeach; ?>

<?php elseif ($tab === 'prowadzacy'): ?>
<p class="small text-body-secondary">Tajny link do planu prowadzącego pokazuje wszystkie jego zajęcia (także zastępstwa), prowadzący inicjałami.
  Zaznaczenie „w katalogu” dodaje go do portalu — wtedy nazwy grup spoza katalogu są ukryte („niepubliczna”).</p>
<?php if ($instructors): $nIOn = count(array_filter($instructors, fn($x) => isset($iLinks[(int)$x['id']]) && (int)$iLinks[(int)$x['id']]['is_enabled'] === 1)); ?>
<form method="post" class="d-flex flex-wrap gap-2 align-items-center mb-3 p-2 border rounded bg-body-tertiary"
      onsubmit="return confirm('Opublikować plany WSZYSTKICH prowadzących (<?= count($instructors) ?>)?' + (this.in_directory.checked ? '\n\nWszyscy trafią też do publicznego katalogu portalu.' : ''))">
  <input type="hidden" name="_token" value="<?= dyd_token() ?>"><input type="hidden" name="_op" value="all_instr">
  <button class="btn btn-sm btn-success"><i class="bi bi-globe2 me-1" aria-hidden="true"></i>Opublikuj wszystkich prowadzących</button>
  <div class="form-check mb-0"><input class="form-check-input" type="checkbox" id="all-i-dir" name="in_directory" value="1"><label class="form-check-label small" for="all-i-dir">także pokaż w katalogu portalu</label></div>
  <span class="small text-body-secondary">opublikowanych: <?= $nIOn ?> z <?= count($instructors) ?>; istniejące linki się nie zmieniają</span>
</form>
<?php endif; ?>
<?php if (!$instructors): ?><div class="alert alert-secondary">Brak prowadzących w aktywnych grupach.</div><?php endif; ?>
<?php foreach ($instructors as $ins): $iu = (int)$ins['id']; $il = $iLinks[$iu] ?? null; $ion = $il && (int)$il['is_enabled'] === 1;
  $iurl = $il ? tiPlanPublicInstrUrl($il) : ''; $ishort = $il ? tiPlanPublicInstrShortUrl($il) : null; ?>
<section class="pl-card <?= $ion ? 'is-on' : 'is-off' ?>" id="i<?= $iu ?>" aria-labelledby="i<?= $iu ?>-h">
  <div class="pl-head">
    <h2 class="h6 mb-0 fw-bold" id="i<?= $iu ?>-h"><?= h($ins['name']) ?></h2>
    <span class="small text-body-secondary">grup: <?= (int)$ins['n_courses'] ?></span>
    <?php if ($ion): ?><span class="badge pl-b pl-on">opublikowany</span><?= (int)$il['in_directory'] ? '<span class="badge pl-b pl-on">w katalogu</span>' : '' ?>
    <?php else: ?><span class="badge pl-b pl-off">nieopublikowany</span><?php endif; ?>
    <?php if ($il): ?><span class="pl-stats">wyświetleń: <?= (int)$il['views'] ?></span><?php endif; ?>
    <div class="ms-auto d-flex gap-1 flex-wrap">
      <?php if ($ion): ?>
        <a class="btn btn-sm btn-outline-primary" href="<?= h($iurl) ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Otwórz</a>
        <form method="post" class="d-inline" onsubmit="return confirm('Wygenerować nowy adres? Dotychczasowy link przestanie działać.')">
          <input type="hidden" name="_token" value="<?= dyd_token() ?>"><input type="hidden" name="_op" value="i_regenerate"><input type="hidden" name="user_id" value="<?= $iu ?>">
          <button class="btn btn-sm btn-outline-warning"><i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>Nowy adres</button>
        </form>
        <form method="post" class="d-inline" onsubmit="return confirm('Wyłączyć link do planu prowadzącego?')">
          <input type="hidden" name="_token" value="<?= dyd_token() ?>"><input type="hidden" name="_op" value="i_disable"><input type="hidden" name="user_id" value="<?= $iu ?>">
          <button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-circle me-1" aria-hidden="true"></i>Wyłącz</button>
        </form>
      <?php else: ?>
        <form method="post" class="d-inline">
          <input type="hidden" name="_token" value="<?= dyd_token() ?>"><input type="hidden" name="_op" value="i_enable"><input type="hidden" name="user_id" value="<?= $iu ?>">
          <button class="btn btn-sm btn-success"><i class="bi bi-globe2 me-1" aria-hidden="true"></i>Opublikuj plan</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
  <?php if ($ion): ?>
  <div class="pl-body">
    <div class="input-group input-group-sm mb-1">
      <input type="text" class="form-control pl-url" id="iurl<?= $iu ?>" value="<?= h($ishort ?? $iurl) ?>" readonly onfocus="this.select()" aria-label="Adres planu prowadzącego">
      <button type="button" class="btn btn-outline-secondary" data-copy="iurl<?= $iu ?>"><i class="bi bi-clipboard me-1" aria-hidden="true"></i>Kopiuj</button>
    </div>
    <form method="post" class="row g-2 align-items-end mt-1">
      <input type="hidden" name="_token" value="<?= dyd_token() ?>"><input type="hidden" name="_op" value="i_options"><input type="hidden" name="user_id" value="<?= $iu ?>">
      <div class="col-sm-3 col-md-2">
        <label class="form-label small mb-0" for="iw<?= $iu ?>">Zakres</label>
        <select class="form-select form-select-sm" id="iw<?= $iu ?>" name="weeks">
          <?php foreach (TI_PLAN_PUBLIC_WEEKS as $w): ?><option value="<?= $w ?>" <?= (int)$il['weeks'] === $w ? 'selected' : '' ?>><?= $w ?> tyg.</option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-sm-3"><div class="form-check"><input class="form-check-input" type="checkbox" id="id<?= $iu ?>" name="in_directory" value="1" <?= (int)$il['in_directory'] ? 'checked' : '' ?>><label class="form-check-label small" for="id<?= $iu ?>">Pokaż w katalogu portalu</label></div></div>
      <div class="col-sm">
        <label class="form-label small mb-0" for="in<?= $iu ?>">Komunikat (opcjonalny)</label>
        <input type="text" class="form-control form-control-sm" id="in<?= $iu ?>" name="note" maxlength="500" value="<?= h($il['note']) ?>">
      </div>
      <div class="col-sm-auto"><button class="btn btn-sm btn-primary">Zapisz</button></div>
    </form>
  </div>
  <?php endif; ?>
</section>
<?php endforeach; ?>
<?php else: /* zakładka Grupy */ ?>

<form method="post" class="d-flex flex-wrap gap-2 align-items-center mb-3 p-2 border rounded bg-body-tertiary"
      onsubmit="return confirm('Opublikować plany WSZYSTKICH aktywnych grup (<?= count($courses) ?>)?' + (this.in_directory.checked ? '\n\nWszystkie trafią też do publicznego katalogu portalu (nazwy grup widoczne publicznie).' : ''))">
  <input type="hidden" name="_token" value="<?= dyd_token() ?>"><input type="hidden" name="_op" value="all_groups">
  <button class="btn btn-sm btn-success"><i class="bi bi-globe2 me-1" aria-hidden="true"></i>Opublikuj wszystkie grupy</button>
  <div class="form-check mb-0"><input class="form-check-input" type="checkbox" id="all-g-dir" name="in_directory" value="1"><label class="form-check-label small" for="all-g-dir">także pokaż w katalogu portalu</label></div>
  <span class="small text-body-secondary">opublikowanych: <?= $nOn ?> z <?= count($courses) ?>; istniejące linki się nie zmieniają</span>
</form>

<form method="get" class="row g-2 align-items-end mb-3" role="search">
  <div class="col-sm-5">
    <label class="form-label small mb-0" for="pl-q">Szukaj grupy</label>
    <input type="search" class="form-control form-control-sm" id="pl-q" name="q" value="<?= h($q) ?>" placeholder="nazwa grupy">
  </div>
  <div class="col-sm-3">
    <label class="form-label small mb-0" for="pl-only">Pokaż</label>
    <select class="form-select form-select-sm" id="pl-only" name="only">
      <option value="" <?= $only === '' ? 'selected' : '' ?>>wszystkie grupy</option>
      <option value="on" <?= $only === 'on' ? 'selected' : '' ?>>tylko opublikowane</option>
      <option value="off" <?= $only === 'off' ? 'selected' : '' ?>>tylko nieopublikowane</option>
    </select>
  </div>
  <div class="col-sm-auto"><input type="hidden" name="lv" value="<?= h($lv) ?>"><button class="btn btn-sm btn-outline-primary">Filtruj</button>
    <?php if ($q !== '' || $only !== ''): ?><a class="btn btn-sm btn-link" href="planLinki.php?lv=<?= h($lv) ?>">Wyczyść</a><?php endif; ?></div>
  <div class="col-sm-auto ms-auto">
    <div class="btn-group btn-group-sm" role="group" aria-label="Widok listy grup">
      <a class="btn <?= $lv === 'lista' ? 'btn-primary' : 'btn-outline-primary' ?>" href="planLinki.php?lv=lista&amp;q=<?= h(urlencode($q)) ?>&amp;only=<?= h(urlencode($only)) ?>"<?= $lv === 'lista' ? ' aria-current="page"' : '' ?>><i class="bi bi-list-ul me-1" aria-hidden="true"></i>Lista</a>
      <a class="btn <?= $lv === 'karty' ? 'btn-primary' : 'btn-outline-primary' ?>" href="planLinki.php?lv=karty&amp;q=<?= h(urlencode($q)) ?>&amp;only=<?= h(urlencode($only)) ?>"<?= $lv === 'karty' ? ' aria-current="page"' : '' ?>><i class="bi bi-card-list me-1" aria-hidden="true"></i>Karty</a>
    </div>
  </div>
</form>

<div role="status" class="visually-hidden">Wyświetlono <?= count($rows) ?> grup.</div>

<?php if (!$rows): ?>
<div class="alert alert-secondary">Brak grup spełniających kryteria.</div>
<?php endif; ?>

<?php if ($lv === 'lista'): /* ── Widok Lista: tabela + akcje masowe ── */
  $bl_opts = [
      'publish'    => ['Opublikuj plan', 'Włącza tajny link do planu grupy (istniejący adres zostaje bez zmian).'],
      'dir_on'     => ['Dodaj do katalogu portalu', 'Grupa pojawi się w portalu /plan/ (Grupy) — nazwa publicznie, z ochroną RODO (inicjały / nazwa zastępcza). Nieopublikowane zostaną też opublikowane.'],
      'dir_off'    => ['Usuń z katalogu portalu', 'Grupa znika z katalogu; tajny link dalej działa dla osób, które go mają.'],
      'disable'    => ['Wyłącz link', 'Adres przestaje działać natychmiast (ponowne włączenie przywraca ten sam adres).'],
      'regenerate' => ['Nowy adres', 'Unieważnia dotychczasowe linki (np. gdy trafiły do niepowołanych osób) — trzeba rozesłać nowe.'],
  ]; ?>
<form method="post" id="pl-bulk" class="d-flex flex-wrap align-items-end gap-2 mb-2 p-2 border rounded bg-body-tertiary"
      onsubmit="var n=document.querySelectorAll('input[name=&quot;ids[]&quot;][form=pl-bulk]:checked').length; if(!n){alert('Zaznacz co najmniej jedną grupę.');return false;} if(!this.bulk_action.value){alert('Wybierz akcję.');return false;} var o=this.bulk_action.options[this.bulk_action.selectedIndex]; return confirm(o.text+' — zaznaczonych grup: '+n+'?\n\n'+o.getAttribute('data-desc'));">
  <input type="hidden" name="_token" value="<?= dyd_token() ?>"><input type="hidden" name="_op" value="bulk_links">
  <input type="hidden" name="q" value="<?= h($q) ?>"><input type="hidden" name="only" value="<?= h($only) ?>">
  <span class="small fw-semibold mb-1" id="pl-bulk-n" role="status" aria-live="polite">Zaznaczone: 0</span>
  <div>
    <label class="form-label small mb-0" for="pl-bulk-act">Akcja dla zaznaczonych</label>
    <select class="form-select form-select-sm" id="pl-bulk-act" name="bulk_action" aria-describedby="pl-bulk-desc"
            onchange="var o=this.options[this.selectedIndex]; document.getElementById('pl-bulk-desc').textContent=o.getAttribute('data-desc')||'Zaznacz grupy w tabeli i wybierz akcję — tu pojawi się jej opis.';">
      <option value="" data-desc="">— wybierz —</option>
      <?php foreach ($bl_opts as $k => $o): ?><option value="<?= $k ?>" data-desc="<?= h($o[1]) ?>"><?= h($o[0]) ?></option><?php endforeach; ?>
    </select>
  </div>
  <button class="btn btn-sm btn-primary"><i class="bi bi-check2-square me-1" aria-hidden="true"></i>Wykonaj</button>
  <p class="small text-body-secondary mb-0 w-100" id="pl-bulk-desc">Zaznacz grupy w tabeli i wybierz akcję — tu pojawi się jej opis.</p>
</form>
<div class="table-responsive mb-3">
  <table class="table table-sm table-hover align-middle bg-white border">
    <caption class="visually-hidden">Linki do planów grup: publikacja, katalog portalu, nazwa publiczna i wyświetlenia</caption>
    <thead class="table-light"><tr>
      <th scope="col" style="width:2rem"><input type="checkbox" class="form-check-input" aria-label="Zaznacz wszystkie grupy"
        onclick="document.querySelectorAll('input[name=&quot;ids[]&quot;][form=pl-bulk]').forEach(function(x){x.checked=this.checked}.bind(this)); plBulkCount()"></th>
      <th scope="col">Grupa</th><th scope="col">Nr</th><th scope="col">Prowadzący</th><th scope="col">Plan</th><th scope="col">Katalog</th>
      <th scope="col">Publicznie jako</th><th scope="col" class="text-end">Wyśw.</th><th scope="col" class="text-end">Akcje</th>
    </tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $c = $r['c']; $l = $r['l']; $cid = (int)$c['id']; $on = $r['on'];
      $pubLabel = tiPlanPublicGroupLabel($cid, (string)$c['name'], (string)($l['public_name'] ?? ''), (string)($c['subject_name'] ?? ''));
      $url = $l ? (tiPlanPublicShortUrl($l) ?? tiPlanPublicUrl($l)) : ''; ?>
    <tr<?= $on ? '' : ' class="text-body-secondary"' ?>>
      <td><input type="checkbox" name="ids[]" value="<?= $cid ?>" form="pl-bulk" class="form-check-input" aria-label="Zaznacz grupę <?= h($c['name']) ?>"></td>
      <th scope="row" class="fw-semibold"><?= h($c['name']) ?></th>
      <td class="font-monospace small"><?= h((string)($c['group_code'] ?? '')) ?></td>
      <td class="small"><?= h((string)($c['instructor_name'] ?? '')) ?></td>
      <td><?= $on ? '<span class="badge pl-b pl-on">opublikowany</span>' : '<span class="badge pl-b pl-off">—</span>' ?></td>
      <td><?= $on && (int)($l['in_directory'] ?? 0) ? '<span class="badge pl-b pl-on">w katalogu</span>' : '<span class="text-body-secondary">—</span>' ?></td>
      <td class="small"><?= $pubLabel !== (string)$c['name'] ? '<i class="bi bi-shield-lock me-1" aria-hidden="true"></i>' . h($pubLabel) : '<span class="text-body-secondary">bez zmian</span>' ?></td>
      <td class="text-end small"><?= $l ? (int)$l['views'] : '' ?></td>
      <td class="text-end text-nowrap">
        <?php if ($on): ?>
        <a class="btn btn-sm btn-outline-primary py-0 px-2" href="<?= h($url) ?>" target="_blank" rel="noopener" title="Otwórz plan grupy"><i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Otwórz</a>
        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2" data-copy-text="<?= h($url) ?>" title="Kopiuj adres planu"><i class="bi bi-clipboard me-1" aria-hidden="true"></i>Kopiuj</button>
        <?php endif; ?>
        <a class="btn btn-sm btn-outline-secondary py-0 px-2" href="planLinki.php?lv=karty&amp;course_id=<?= $cid ?>#g<?= $cid ?>" title="Ustawienia linku, nazwa publiczna, wysyłka do kursantów"><i class="bi bi-sliders me-1" aria-hidden="true"></i>Ustawienia</a>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>
<script>
function plBulkCount(){ var n=document.querySelectorAll('input[name="ids[]"][form=pl-bulk]:checked').length; document.getElementById('pl-bulk-n').textContent='Zaznaczone: '+n; }
document.addEventListener('change', function(e){ if (e.target.matches('input[name="ids[]"][form=pl-bulk]')) plBulkCount(); });
document.addEventListener('click', function (e) {
  var b = e.target.closest('[data-copy-text]'); if (!b) return;
  var v = b.getAttribute('data-copy-text'), t = b.innerHTML;
  var done = function () { b.innerHTML = '<i class="bi bi-check2 me-1" aria-hidden="true"></i>Skopiowano'; setTimeout(function () { b.innerHTML = t; }, 1600); };
  if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(v).then(done); else { prompt('Adres planu:', v); }
});
</script>
<?php else: /* ── Widok Karty ── */ ?>
<?php foreach ($rows as $r): $c = $r['c']; $l = $r['l']; $cid = (int)$c['id']; $on = $r['on'];
  $url = $l ? tiPlanPublicUrl($l) : ''; $short = $l ? tiPlanPublicShortUrl($l) : null; $open = $on && ($focus === $cid || $focus === 0 && count($rows) <= 3); ?>
<section class="pl-card <?= $on ? 'is-on' : 'is-off' ?>" id="g<?= $cid ?>" aria-labelledby="g<?= $cid ?>-h">
  <div class="pl-head">
    <h2 class="h6 mb-0 fw-bold" id="g<?= $cid ?>-h"><?= h($c['name']) ?></h2>
    <span class="small text-body-secondary"><?= h($c['instructor_name'] ?? '') ?></span>
    <?php $pubLabel = tiPlanPublicGroupLabel($cid, (string)$c['name'], (string)($l['public_name'] ?? ''), (string)($c['subject_name'] ?? ''));
      if ($pubLabel !== (string)$c['name']): ?><span class="badge pl-b pl-off" title="Tak grupa wygląda na stronach publicznych"><i class="bi bi-shield-lock me-1" aria-hidden="true"></i>publicznie: <?= h($pubLabel) ?></span><?php endif; ?>
    <?php if ($on): ?><span class="badge pl-b pl-on"><i class="bi bi-globe2 me-1" aria-hidden="true"></i>opublikowany</span><?= (int)($l['in_directory'] ?? 0) ? '<span class="badge pl-b pl-on"><i class="bi bi-grid-3x3-gap me-1" aria-hidden="true"></i>w katalogu</span>' : '' ?>
    <?php else: ?><span class="badge pl-b pl-off"><i class="bi bi-eye-slash me-1" aria-hidden="true"></i>nieopublikowany</span><?php endif; ?>
    <?php if ($l): ?><span class="pl-stats">wyświetleń: <?= (int)$l['views'] ?><?= $l['last_view_at'] ? ', ostatnio ' . h(date('d.m.Y H:i', strtotime((string)$l['last_view_at']))) : '' ?></span><?php endif; ?>
    <div class="ms-auto d-flex gap-1 flex-wrap">
      <?php if ($on): ?>
        <a class="btn btn-sm btn-outline-primary" href="<?= h($url) ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Otwórz</a>
        <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#opt<?= $cid ?>" aria-expanded="<?= $open ? 'true' : 'false' ?>" aria-controls="opt<?= $cid ?>"><i class="bi bi-sliders me-1" aria-hidden="true"></i>Ustawienia</button>
        <form method="post" class="d-inline" onsubmit="return confirm('Wygenerować nowy adres dla grupy „<?= h(addslashes($c['name'])) ?>&quot;?\n\nDotychczasowy link przestanie działać.')">
          <input type="hidden" name="_token" value="<?= dyd_token() ?>"><input type="hidden" name="_op" value="regenerate"><input type="hidden" name="course_id" value="<?= $cid ?>">
          <button class="btn btn-sm btn-outline-warning"><i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>Nowy adres</button>
        </form>
        <form method="post" class="d-inline" onsubmit="return confirm('Wyłączyć publikację planu grupy „<?= h(addslashes($c['name'])) ?>&quot;?\n\nLink przestanie działać (ponowne włączenie przywróci ten sam adres).')">
          <input type="hidden" name="_token" value="<?= dyd_token() ?>"><input type="hidden" name="_op" value="disable"><input type="hidden" name="course_id" value="<?= $cid ?>">
          <button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-circle me-1" aria-hidden="true"></i>Wyłącz</button>
        </form>
      <?php else: ?>
        <form method="post" class="d-inline">
          <input type="hidden" name="_token" value="<?= dyd_token() ?>"><input type="hidden" name="_op" value="enable"><input type="hidden" name="course_id" value="<?= $cid ?>">
          <button class="btn btn-sm btn-success"><i class="bi bi-globe2 me-1" aria-hidden="true"></i>Opublikuj plan</button>
        </form>
      <?php endif; ?>
    </div>
  </div>
  <?php if ($on): ?>
  <div class="pl-body">
    <label class="form-label small fw-semibold mb-1" for="url<?= $cid ?>">Adres planu (do przekazania kursantom / rodzicom / instytucjom)</label>
    <div class="input-group input-group-sm mb-1">
      <input type="text" class="form-control pl-url" id="url<?= $cid ?>" value="<?= h($url) ?>" readonly onfocus="this.select()">
      <button type="button" class="btn btn-outline-secondary" data-copy="url<?= $cid ?>"><i class="bi bi-clipboard me-1" aria-hidden="true"></i>Kopiuj</button>
    </div>
    <?php if ($short): ?>
    <div class="input-group input-group-sm mb-2">
      <span class="input-group-text">krótki</span>
      <input type="text" class="form-control pl-url" id="surl<?= $cid ?>" value="<?= h($short) ?>" readonly onfocus="this.select()">
      <button type="button" class="btn btn-outline-secondary" data-copy="surl<?= $cid ?>"><i class="bi bi-clipboard me-1" aria-hidden="true"></i>Kopiuj</button>
    </div>
    <?php endif; ?>
    <div class="collapse<?= $open ? ' show' : '' ?>" id="opt<?= $cid ?>">
      <form method="post" class="row g-2 align-items-end mt-1">
        <input type="hidden" name="_token" value="<?= dyd_token() ?>"><input type="hidden" name="_op" value="options"><input type="hidden" name="course_id" value="<?= $cid ?>">
        <div class="col-sm-3 col-md-2">
          <label class="form-label small mb-0" for="w<?= $cid ?>">Zakres siatki</label>
          <select class="form-select form-select-sm" id="w<?= $cid ?>" name="weeks">
            <?php foreach (TI_PLAN_PUBLIC_WEEKS as $w): ?><option value="<?= $w ?>" <?= (int)$l['weeks'] === $w ? 'selected' : '' ?>><?= $w ?> tyg.</option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-sm-4 col-md-3">
          <div class="form-check"><input class="form-check-input" type="checkbox" id="sl<?= $cid ?>" name="show_list" value="1" <?= (int)$l['show_list'] ? 'checked' : '' ?>><label class="form-check-label small" for="sl<?= $cid ?>">Lista terminów pod siatką</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" id="sc<?= $cid ?>" name="show_contacts" value="1" <?= (int)$l['show_contacts'] ? 'checked' : '' ?>><label class="form-check-label small" for="sc<?= $cid ?>">Kontakty do prowadzących (telefon, e-mail) — tylko gdy grupy nie ma w katalogu</label></div>
          <div class="form-check"><input class="form-check-input" type="checkbox" id="cd<?= $cid ?>" name="in_directory" value="1" <?= (int)($l['in_directory'] ?? 0) ? 'checked' : '' ?>><label class="form-check-label small" for="cd<?= $cid ?>">Pokaż w katalogu portalu (nazwa grupy publicznie)</label></div>
        </div>
        <div class="col-sm-6 col-md-4">
          <label class="form-label small mb-0" for="pn<?= $cid ?>">Nazwa publiczna (opcjonalna)</label>
          <input type="text" class="form-control form-control-sm" id="pn<?= $cid ?>" name="public_name" maxlength="120" value="<?= h($l['public_name'] ?? '') ?>" placeholder="<?= h($pubLabel) ?>" aria-describedby="pnh<?= $cid ?>">
          <div class="form-text small" id="pnh<?= $cid ?>"><?= tiPlanPublicNameHasPerson($cid, (string)$c['name']) ? '<strong class="text-danger">Nazwa grupy zawiera dane uczestnika</strong> — publicznie pokazujemy nazwę zastępczą albo tę z pola.' : 'Puste = nazwa grupy.' ?></div>
        </div>
        <div class="col-sm-6 col-md-4">
          <label class="form-label small mb-0" for="n<?= $cid ?>">Komunikat na stronie planu (opcjonalny)</label>
          <input type="text" class="form-control form-control-sm" id="n<?= $cid ?>" name="note" maxlength="500" value="<?= h($l['note']) ?>" placeholder="np. plan może się zmienić do 15 października">
        </div>
        <div class="col-sm-auto"><button class="btn btn-sm btn-primary">Zapisz</button></div>
      </form>
    </div>

    <?php /* ── Wysyłka linku do kursantów (e-mail / SMS) ── */ ?>
    <div class="mt-2 pt-2 border-top" id="send<?= $cid ?>">
      <?php if ($open): $pv = TiPlanPublicNotifier::preview($cid); $hist = TiPlanPublicNotifier::history($cid, 3); ?>
      <form method="post" class="row g-2 align-items-end"
            onsubmit="return confirm('Wysłać link do planu grupy „<?= h(addslashes($c['name'])) ?>&quot; do kursantów?\n\nE-mail: <?= (int)$pv['email_ok'] ?> adresów, SMS: <?= (int)$pv['sms_ok'] ?> numerów.')">
        <input type="hidden" name="_token" value="<?= dyd_token() ?>"><input type="hidden" name="_op" value="send"><input type="hidden" name="course_id" value="<?= $cid ?>">
        <div class="col-12">
          <span class="fw-semibold small"><i class="bi bi-send me-1" aria-hidden="true"></i>Wyślij link kursantom</span>
          <span class="small text-body-secondary">— aktywne zapisy: <?= (int)$pv['students'] ?>, opiekunowie z panelem rodzica: <?= (int)$pv['guardians'] ?>; e-mail dotrze do <?= (int)$pv['email_ok'] ?> (w tym opiekunów: <?= (int)$pv['email_ok_g'] ?>), SMS do <?= (int)$pv['sms_ok'] ?> (opiekunów: <?= (int)$pv['sms_ok_g'] ?>) — wg zgód z kont.</span>
          <?php $sk = []; foreach ($pv['email_skip'] as $why => $n) $sk[] = 'e-mail: ' . $why . ' (' . $n . ')'; foreach ($pv['sms_skip'] as $why => $n) $sk[] = 'SMS: ' . $why . ' (' . $n . ')'; ?>
          <?php if ($sk): ?><div class="small text-body-secondary">Pominięci — <?= h(implode('; ', $sk)) ?>.</div><?php endif; ?>
        </div>
        <div class="col-sm-auto">
          <div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" id="ce<?= $cid ?>" name="ch_email" value="1" <?= $pv['email_ok'] ? 'checked' : 'disabled' ?>><label class="form-check-label small" for="ce<?= $cid ?>">E-mail</label></div>
          <div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" id="cs<?= $cid ?>" name="ch_sms" value="1" <?= $pv['sms_ok'] ? 'checked' : 'disabled' ?>><label class="form-check-label small" for="cs<?= $cid ?>">SMS</label></div>
          <div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" id="cg<?= $cid ?>" name="with_guardians" value="1" <?= $pv['guardians'] ? 'checked' : 'disabled' ?>><label class="form-check-label small" for="cg<?= $cid ?>" title="Opiekunowie z dostępem do panelu rodzica — link widzą też w zakładce Harmonogram">także opiekunowie (panel rodzica)</label></div>
        </div>
        <div class="col-sm">
          <label class="form-label small mb-0 visually-hidden" for="en<?= $cid ?>">Dodatkowa uwaga w e-mailu</label>
          <input type="text" class="form-control form-control-sm" id="en<?= $cid ?>" name="extra_note" maxlength="300" placeholder="dodatkowa uwaga w e-mailu (opcjonalnie)">
        </div>
        <div class="col-sm-auto">
          <button class="btn btn-sm btn-primary" <?= ($pv['email_ok'] || $pv['sms_ok']) ? '' : 'disabled' ?>><i class="bi bi-send me-1" aria-hidden="true"></i>Wyślij</button>
        </div>
      </form>
      <?php if ($hist): ?>
      <ul class="list-unstyled small text-body-secondary mb-0 mt-2">
        <?php foreach ($hist as $hh): ?>
        <li><i class="bi bi-clock-history me-1" aria-hidden="true"></i><?= h(date('d.m.Y H:i', strtotime((string)$hh['created_at']))) ?> — wysłano <?= (int)$hh['sent'] ?> (e-mail <?= (int)$hh['sent_email'] ?>, SMS <?= (int)$hh['sent_sms'] ?>)<?= (int)$hh['failed'] ? ', błędy ' . (int)$hh['failed'] : '' ?><?= (int)$hh['skipped'] ? ', pominięto ' . (int)$hh['skipped'] : '' ?></li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
      <?php else: ?>
      <a class="btn btn-sm btn-outline-primary" href="planLinki.php?course_id=<?= $cid ?>#send<?= $cid ?>"><i class="bi bi-send me-1" aria-hidden="true"></i>Wyślij link kursantom…</a>
      <?php if (!empty($l['notified_at'])): ?><span class="small text-body-secondary ms-2">ostatnia wysyłka: <?= h(date('d.m.Y H:i', strtotime((string)$l['notified_at']))) ?></span><?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</section>
<?php endforeach; ?>
<?php endif; /* lista / karty */ ?>
<?php endif; /* zakładki */ ?>

</div>
</main>
<script>
document.addEventListener('click', function (e) {
  var b = e.target.closest('[data-copy]'); if (!b) return;
  var inp = document.getElementById(b.getAttribute('data-copy')); if (!inp) return;
  var done = function () { var t = b.innerHTML; b.innerHTML = '<i class="bi bi-check2 me-1" aria-hidden="true"></i>Skopiowano'; b.setAttribute('aria-live', 'polite'); setTimeout(function () { b.innerHTML = t; }, 1800); };
  if (navigator.clipboard && navigator.clipboard.writeText) navigator.clipboard.writeText(inp.value).then(done, function () { inp.select(); document.execCommand('copy'); done(); });
  else { inp.select(); document.execCommand('copy'); done(); }
});
</script>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
