<?php
/**
 * karty30/ti/dydaktyk/grupaUproszczona.php — Grupy uproszczone (KON.xxxxx.yyy.rrrr, 2–4 zajęcia) i konwerter grup.
 *
 * 1. Nowa grupa uproszczona w jednym kroku: nazwa KON, prowadzący, uczestnicy (z bazy albo nowi), 1–4 terminy,
 *    tryb panelu uczestników (tylko terminy / + płatności / + płatności uproszczone).
 *    Opis grupy (internal_desc) widzi tylko kadra. Uczestnicy bez konta dostają konto panelu automatycznie —
 *    login i hasło pokazujemy raz po utworzeniu (i opcjonalnie wysyłamy SMS-em).
 * 2. Konwerter grup: istniejąca grupa → uproszczona (nazwa KON) i z powrotem; lekcje i rozliczenia bez zmian.
 * Tylko kierownik (dyd_is_staff). Logika: modules/ti_simple_groups/logic/simpleGroups.php.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 3) . '/modules/ti_simple_groups/logic/simpleGroups.php';

$me  = dyd_require();
$uid = (int)$me['user_id'];
if (!dyd_is_staff()) { header('Location: index.php'); exit; }
karty30_migrate();
$byName = (string)($me['name'] ?? '');

$errors = []; $warnings = []; $old = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    $op = (string)($_POST['_op'] ?? '');
    if ($op === 'create') {
        $old = $_POST;
        $cl = [];
        foreach ((array)($_POST['client'] ?? []) as $v) if (preg_match('/#(\d+)\s*$/', (string)$v, $m)) $cl[] = (int)$m[1];
        $people = [];
        foreach ((array)($_POST['np_name'] ?? []) as $i => $n) $people[] = ['name' => $n, 'phone' => $_POST['np_phone'][$i] ?? '', 'email' => $_POST['np_email'][$i] ?? ''];
        $lessons = [];
        foreach ((array)($_POST['l_date'] ?? []) as $i => $d) $lessons[] = ['date' => $d, 'from' => $_POST['l_from'][$i] ?? '', 'to' => $_POST['l_to'][$i] ?? '', 'method' => $_POST['l_method'][$i] ?? ''];
        $res = TiSimpleGroups::create([
            'number' => (int)($_POST['k_num'] ?? 0), 'letters' => (string)($_POST['k_let'] ?? ''), 'year' => (int)($_POST['k_year'] ?? 0),
            'instructor_id' => (int)($_POST['instructor_id'] ?? 0), 'class_type' => (string)($_POST['class_type'] ?? 'individual'),
            'desc' => (string)($_POST['desc'] ?? ''), 'accounts' => isset($_POST['accounts']), 'send_logins' => isset($_POST['send_logins']),
            'location' => (string)($_POST['location'] ?? ''), 'meeting_url' => (string)($_POST['meeting_url'] ?? ''),
            'mode' => (int)($_POST['mode'] ?? 1), 'notify' => isset($_POST['notify']),
            'clients' => $cl, 'new_people' => $people, 'lessons' => $lessons,
        ], $uid, $byName);
        if ($res['ok']) {
            $msg = 'Utworzono grupę uproszczoną ' . $res['name'] . '.';
            if ($res['warnings']) $msg .= ' Uwaga: ' . implode(' ', $res['warnings']);
            if ($res['no_account']) $msg .= ' Bez konta w panelu kursanta (załóż w Kontach kursantów): ' . implode(', ', $res['no_account']) . '.';
            if ($res['accounts']) $msg .= ' Założono konta panelu kursanta: ' . count($res['accounts']) . '.';
            flash_set($res['warnings'] || $res['no_account'] ? 'warning' : 'success', $msg);
            // Loginy i hasła nowych kont — pokazujemy raz, na tej stronie
            $_SESSION['gu_creds'] = ['course_id' => $res['course_id'], 'name' => $res['name'], 'rows' => $res['accounts']];
            header('Location: grupaUproszczona.php?utworzono=' . $res['course_id']); exit;
        }
        $errors = $res['errors']; $warnings = $res['warnings'];
    } elseif ($op === 'convert') {
        $cid  = (int)($_POST['course_id'] ?? 0);
        $mode = (int)($_POST['mode'] ?? 0);
        $name = $mode > 0 && ($_POST['k_num'] ?? '') !== ''
            ? TiSimpleGroups::name((int)$_POST['k_num'], (string)($_POST['k_let'] ?? ''), (int)($_POST['k_year'] ?? date('Y')))
            : trim((string)($_POST['new_name'] ?? ''));
        $res = TiSimpleGroups::convert($cid, $mode, $name, $uid, $byName, (string)($_POST['desc'] ?? ''));
        flash_set($res['ok'] ? 'success' : 'danger', $res['msg']);
        header('Location: grupaUproszczona.php?konwertuj=' . $cid . '#konwerter'); exit;
    }
}

$instructors = k30_ti_instructors();
$clients     = db_all("SELECT id, name FROM k30_clients WHERE TRIM(COALESCE(name,'')) <> '' ORDER BY name");
$courses     = db_all("SELECT id, name, simple_mode, is_active, internal_desc FROM k30_ti_courses WHERE COALESCE(status,'') NOT IN ('cancelled','archived') ORDER BY is_active DESC, name");
$year        = (int)date('Y');
$o = fn(string $k, $def = '') => $old[$k] ?? $def;
$oa = fn(string $k, int $i, $def = '') => $old[$k][$i] ?? $def;

$creds = null;
if (!empty($_SESSION['gu_creds']) && (int)$_SESSION['gu_creds']['course_id'] === (int)($_GET['utworzono'] ?? 0)) $creds = $_SESSION['gu_creds'];
unset($_SESSION['gu_creds']);
// Do wydruku PDF hasła zostają w sesji jeszcze 30 minut (grupaUproszczonaPdf.php), potem znikają
if ($creds && $creds['rows']) $_SESSION['gu_creds_pdf'][(int)$creds['course_id']] = ['rows' => $creds['rows'], 'ts' => time()];
$showAll = !empty($_GET['wszystkie']);
$groups  = TiSimpleGroups::listGroups(!$showAll);

$conv = (int)($_GET['konwertuj'] ?? 0);
$convC = $conv ? db_one("SELECT id, name, simple_mode, internal_desc FROM k30_ti_courses WHERE id=?", [$conv]) : null;
$convYear = $year; $convLet = '';
if ($convC) {
    $_first = db_one("SELECT MIN(lesson_date) AS d FROM k30_ti_sessions WHERE course_id=? AND draft_id IS NULL", [$conv]);
    if (!empty($_first['d'])) $convYear = (int)substr((string)$_first['d'], 0, 4);
    $_p = db_one("SELECT cl.name FROM k30_ti_enrollments e JOIN k30_clients cl ON cl.id=e.client_id WHERE e.course_id=? AND e.status='active' ORDER BY e.id LIMIT 1", [$conv]);
    $convLet = TiSimpleGroups::suggestLetters((string)($_p['name'] ?? ''));
}

$KP_TITLE  = 'Grupy uproszczone — Panel dydaktyka';
$KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => $byName, 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">
<style>
  .gu-kon { display: flex; align-items: center; gap: .25rem; flex-wrap: wrap; font-family: var(--bs-font-monospace); }
  .gu-kon input { width: auto; font-family: var(--bs-font-monospace); text-transform: uppercase; }
  .gu-row { border-left: 4px solid #10335c; padding-left: .6rem; }
</style>

<?php $KIER_CUR = 'grupaUproszczona.php'; $KIER_LABEL = 'Grupy uproszczone'; include __DIR__ . '/_kierownik_bar.php'; ?>

<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:1100px">

<h1 class="h4 fw-bold mb-1"><i class="bi bi-lightning-charge text-primary me-2" aria-hidden="true"></i>Grupy uproszczone</h1>
<p class="small text-body-secondary mb-3">Krótkie grupy na 2–4 zajęcia (konsultacje) o nazwie <span class="font-monospace">KON.xxxxx.yyy.rrrr</span>. Uczestnicy, którzy są tylko w takich grupach, widzą uproszczony panel kursanta.</p>
<?= flash_html() ?>
<?php if ($errors): ?>
<div class="alert alert-danger" role="alert"><strong>Nie utworzono grupy:</strong><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>
<?php if ($warnings && $errors): ?>
<div class="alert alert-warning small"><?php foreach ($warnings as $w): ?><div><?= h($w) ?></div><?php endforeach; ?></div>
<?php endif; ?>

<?php if (!empty($_GET['utworzono'])): $_cg = db_one("SELECT id, name FROM k30_ti_courses WHERE id=?", [(int)$_GET['utworzono']]); if ($_cg): ?>
<section class="card border-success mb-4" aria-labelledby="gu-done-h">
  <div class="card-header fw-semibold d-flex align-items-center flex-wrap gap-2" id="gu-done-h">
    <span><i class="bi bi-check-circle text-success me-1" aria-hidden="true"></i>Grupa <span class="font-monospace"><?= h($_cg['name']) ?></span> utworzona</span>
    <a class="btn btn-sm btn-primary ms-auto" href="index.php?course=<?= (int)$_cg['id'] ?>&amp;tab=lekcje">Otwórz grupę</a>
  </div>
  <?php if ($creds && $creds['rows']): ?>
  <div class="card-body">
    <p class="small mb-2"><strong>Nowe konta panelu kursanta</strong> — hasła widać tylko teraz (przy pierwszym logowaniu kursant ustawi własne). Zapisz je albo wydrukuj, jeśli SMS nie dotarł.</p>
    <div class="table-responsive"><table class="table table-sm mb-2">
      <caption class="visually-hidden">Loginy i hasła nowych kont</caption>
      <thead><tr><th scope="col">Uczestnik</th><th scope="col">Login</th><th scope="col">Hasło jednorazowe</th><th scope="col">SMS</th></tr></thead>
      <tbody><?php foreach ($creds['rows'] as $r): ?><tr><th scope="row" class="fw-normal"><?= h($r['name']) ?></th><td class="font-monospace"><?= h($r['login']) ?></td><td class="font-monospace"><?= h($r['password']) ?></td><td class="small"><?= h($r['sms'] ?: 'nie wysyłano') ?></td></tr><?php endforeach; ?></tbody>
    </table></div>
    <p class="small text-body-secondary mb-2">Logowanie: <?= h(rtrim(APP_URL, '/') . '/karty30/ti/kursant/login.php') ?></p>
    <form method="post" action="grupaUproszczonaPdf.php" target="_blank" class="d-inline">
      <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="course_id" value="<?= (int)$_cg['id'] ?>">
      <button class="btn btn-sm btn-outline-primary"><i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>Pobierz PDF z loginami i hasłami<span class="visually-hidden"> (otwiera się w nowej karcie)</span></button>
    </form>
    <span class="small text-body-secondary ms-2">Zestawienie dla kadry + kartki do wręczenia uczestnikom. Dostępne przez 30 minut.</span>
  </div>
  <?php endif; ?>
</section>
<?php endif; endif; ?>

<section class="card border-0 shadow-sm mb-4" id="lista" aria-labelledby="gu-list-h">
  <div class="card-header fw-semibold d-flex align-items-center flex-wrap gap-2" id="gu-list-h">
    <span><i class="bi bi-list-ul me-1 text-primary" aria-hidden="true"></i>Grupy uproszczone <span class="badge text-bg-secondary"><?= count($groups) ?></span></span>
    <a class="small ms-auto" href="grupaUproszczona.php<?= $showAll ? '' : '?wszystkie=1' ?>#lista"><?= $showAll ? 'Pokaż tylko aktywne' : 'Pokaż także zamknięte' ?></a>
    <a class="btn btn-sm btn-primary" href="#gu-new-h"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nowa grupa</a>
  </div>
  <?php if (!$groups): ?>
  <div class="card-body small text-body-secondary">Nie ma jeszcze <?= $showAll ? '' : 'aktywnych ' ?>grup uproszczonych.</div>
  <?php else: ?>
  <div class="table-responsive">
  <table class="table table-sm table-hover align-middle mb-0">
    <caption class="visually-hidden">Grupy uproszczone</caption>
    <thead class="table-light"><tr>
      <th scope="col">Grupa</th><th scope="col">Rodzaj</th><th scope="col">Panel uczestników</th><th scope="col">Prowadzący</th>
      <th scope="col">Uczestnicy</th><th scope="col" class="text-end">Zajęcia</th><th scope="col">Najbliższe</th><th scope="col"><span class="visually-hidden">Akcje</span></th>
    </tr></thead>
    <tbody>
    <?php foreach ($groups as $g): $_typ = ['individual' => 'Indywidualne', 'group' => 'Grupowe', 'hybrid' => 'Hybryda krótka'][$g['class_type']] ?? $g['class_type']; ?>
    <tr<?= empty($g['is_active']) ? ' class="text-body-secondary"' : '' ?>>
      <th scope="row"><a class="font-monospace fw-semibold" href="index.php?course=<?= (int)$g['id'] ?>&amp;tab=lekcje"><?= h($g['name']) ?></a><?= empty($g['is_active']) ? ' <span class="badge text-bg-secondary">nieaktywna</span>' : '' ?>
        <?php if (trim((string)$g['internal_desc']) !== ''): ?><div class="small fw-normal text-body-secondary"><?= h($g['internal_desc']) ?></div><?php endif; ?></th>
      <td><?= h($_typ) ?><?php if ((int)$g['online'] > 0 && (int)$g['online'] < (int)$g['lessons']): ?><div class="small text-body-secondary"><?= (int)$g['lessons'] - (int)$g['online'] ?> stac. + <?= (int)$g['online'] ?> online</div><?php elseif ((int)$g['lessons'] > 0 && (int)$g['online'] === (int)$g['lessons']): ?><div class="small text-body-secondary">online</div><?php endif; ?></td>
      <td class="small"><?= h(TiSimpleGroups::MODES[(int)$g['simple_mode']] ?? '') ?></td>
      <td class="small"><?= h((string)($g['instructor_name'] ?? '—')) ?></td>
      <td class="small" style="max-width:16rem"><?= (int)$g['people'] ?><?php if ($g['people_names']): ?> — <?= h(mb_strimwidth((string)$g['people_names'], 0, 80, '…')) ?><?php endif; ?></td>
      <td class="text-end small"><?= (int)$g['held'] ?> / <?= (int)$g['lessons'] ?><span class="visually-hidden"> odbytych</span></td>
      <td class="small text-nowrap"><?= $g['next_lesson'] ? h(date('d.m.Y', strtotime(substr((string)$g['next_lesson'], 0, 10))) . ' ' . substr((string)$g['next_lesson'], 11, 5)) : '—' ?></td>
      <td class="text-end text-nowrap"><a class="btn btn-sm btn-outline-secondary" href="grupaUproszczona.php?konwertuj=<?= (int)$g['id'] ?>#konwerter" title="Konwertuj grupę <?= h($g['name']) ?>"><i class="bi bi-arrow-left-right" aria-hidden="true"></i><span class="visually-hidden">Konwertuj</span></a></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</section>

<datalist id="gu-clients"><?php foreach ($clients as $c): ?><option value="<?= h($c['name'] . ' · #' . $c['id']) ?>"><?php endforeach; ?></datalist>

<section class="card border-0 shadow-sm mb-4" aria-labelledby="gu-new-h">
  <div class="card-header fw-semibold" id="gu-new-h"><i class="bi bi-plus-circle me-1 text-primary" aria-hidden="true"></i>Nowa grupa uproszczona</div>
  <div class="card-body">
  <form method="post" id="gu-form">
    <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="create">

    <fieldset class="mb-3">
      <legend class="h6 fw-bold">1. Nazwa grupy</legend>
      <div class="gu-kon" role="group" aria-label="Nazwa KON.xxxxx.yyy.rrrr">
        <span>KON.</span>
        <label class="visually-hidden" for="gu-num">Numer (5 cyfr)</label>
        <input class="form-control form-control-sm" id="gu-num" name="k_num" size="6" maxlength="5" pattern="\d{1,5}" inputmode="numeric"
               value="<?= h((string)$o('k_num', sprintf('%05d', TiSimpleGroups::nextNumber($year)))) ?>" title="5 cyfr — domyślnie kolejny wolny numer w roku">
        <span>.</span>
        <label class="visually-hidden" for="gu-let">Litery (3)</label>
        <input class="form-control form-control-sm" id="gu-let" name="k_let" size="4" maxlength="3" pattern="[A-Za-zĄĆĘŁŃÓŚŹŻąćęłńóśźż]{3}" required
               value="<?= h((string)$o('k_let', '')) ?>" placeholder="ABC" title="3 litery — np. pierwsze litery nazwiska i imienia">
        <span>.</span>
        <label class="visually-hidden" for="gu-year">Rok</label>
        <input class="form-control form-control-sm" id="gu-year" name="k_year" size="5" maxlength="4" pattern="\d{4}" inputmode="numeric"
               value="<?= h((string)$o('k_year', (string)$year)) ?>">
      </div>
      <div class="form-text">5 cyfr (kolejny numer w roku), 3 litery, rok. Litery podpowiadają się z pierwszego uczestnika. Kursanci widzą tylko ten numer.</div>
      <label class="form-label small mb-0 mt-2" for="gu-desc">Opis <span class="text-body-secondary fw-normal">(widzi tylko kadra — nie pokazujemy go kursantom)</span></label>
      <input class="form-control form-control-sm" id="gu-desc" name="desc" maxlength="300" value="<?= h((string)$o('desc')) ?>" placeholder="np. konsultacje z obsługi czytnika ekranu">
    </fieldset>

    <fieldset class="mb-3">
      <legend class="h6 fw-bold">2. Prowadzący i forma</legend>
      <div class="row g-2">
        <div class="col-sm-5">
          <label class="form-label small mb-0" for="gu-instr">Prowadzący</label>
          <select class="form-select form-select-sm" id="gu-instr" name="instructor_id">
            <option value="0">— brak przypisania —</option>
            <?php foreach ($instructors as $ins): ?><option value="<?= (int)$ins['id'] ?>"<?= (int)$o('instructor_id', 0) === (int)$ins['id'] ? ' selected' : '' ?>><?= h($ins['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="col-sm-3">
          <label class="form-label small mb-0" for="gu-type">Rodzaj</label>
          <select class="form-select form-select-sm" id="gu-type" name="class_type">
            <option value="individual">Indywidualne</option>
            <option value="group"<?= $o('class_type') === 'group' ? ' selected' : '' ?>>Grupowe</option>
            <option value="hybrid"<?= $o('class_type') === 'hybrid' ? ' selected' : '' ?>>Hybryda krótka (część stacjonarnie, część online)</option>
          </select>
        </div>
        <div class="col-sm-4 small text-body-secondary d-flex align-items-end">Formę (stacjonarnie / online) wybierasz przy każdym terminie — można mieszać.</div>
        <div class="col-sm-6"><label class="form-label small mb-0" for="gu-loc">Miejsce (zajęcia stacjonarne)</label><input class="form-control form-control-sm" id="gu-loc" name="location" value="<?= h((string)$o('location')) ?>"></div>
        <div class="col-sm-6"><label class="form-label small mb-0" for="gu-url">Link do spotkania (zajęcia online)</label><input class="form-control form-control-sm" id="gu-url" name="meeting_url" type="url" value="<?= h((string)$o('meeting_url')) ?>"></div>
      </div>
    </fieldset>

    <fieldset class="mb-3">
      <legend class="h6 fw-bold">3. Uczestnicy</legend>
      <div class="row g-2" id="gu-clients-rows">
        <?php $_cl = (array)$o('client', []); for ($i = 0; $i < max(3, count($_cl)); $i++): ?>
        <div class="col-sm-6 col-lg-4">
          <label class="form-label small mb-0" for="gu-cl-<?= $i ?>">Uczestnik z bazy <?= $i + 1 ?></label>
          <input class="form-control form-control-sm gu-client" id="gu-cl-<?= $i ?>" name="client[]" list="gu-clients" autocomplete="off" placeholder="zacznij pisać nazwisko…" value="<?= h((string)($_cl[$i] ?? '')) ?>">
        </div>
        <?php endfor; ?>
      </div>
      <button type="button" class="btn btn-sm btn-link px-0" id="gu-add-client"><i class="bi bi-plus" aria-hidden="true"></i>Kolejny uczestnik z bazy</button>
      <details class="mt-2"<?= array_filter((array)$o('np_name', [])) ? ' open' : '' ?>>
        <summary class="small fw-semibold">Nowa osoba (nie ma jej w bazie)</summary>
        <?php for ($i = 0; $i < 2; $i++): ?>
        <div class="row g-2 mt-1 gu-row">
          <div class="col-sm-4"><label class="form-label small mb-0" for="gu-np-n<?= $i ?>">Imię i nazwisko</label><input class="form-control form-control-sm" id="gu-np-n<?= $i ?>" name="np_name[]" value="<?= h((string)$oa('np_name', $i)) ?>"></div>
          <div class="col-sm-4"><label class="form-label small mb-0" for="gu-np-p<?= $i ?>">Telefon</label><input class="form-control form-control-sm" id="gu-np-p<?= $i ?>" name="np_phone[]" type="tel" value="<?= h((string)$oa('np_phone', $i)) ?>"></div>
          <div class="col-sm-4"><label class="form-label small mb-0" for="gu-np-e<?= $i ?>">E-mail</label><input class="form-control form-control-sm" id="gu-np-e<?= $i ?>" name="np_email[]" type="email" value="<?= h((string)$oa('np_email', $i)) ?>"></div>
        </div>
        <?php endfor; ?>
        <p class="form-text mb-0">Nowa osoba trafia do bazy kursantów i do CRM.</p>
      </details>
      <div class="form-check mt-2"><input class="form-check-input" type="checkbox" id="gu-acc" name="accounts" value="1"<?= !$old || $o('accounts') ? ' checked' : '' ?>>
        <label class="form-check-label small" for="gu-acc">Załóż konta w panelu kursanta uczestnikom, którzy ich nie mają</label></div>
      <div class="form-check ms-3"><input class="form-check-input" type="checkbox" id="gu-acc-sms" name="send_logins" value="1"<?= !$old || $o('send_logins') ? ' checked' : '' ?>>
        <label class="form-check-label small" for="gu-acc-sms">Wyślij login i hasło SMS-em (gdy jest numer telefonu)</label></div>
    </fieldset>

    <fieldset class="mb-3">
      <legend class="h6 fw-bold">4. Terminy (do <?= TiSimpleGroups::MAX_LESSONS ?>)</legend>
      <?php for ($i = 0; $i < TiSimpleGroups::MAX_LESSONS; $i++): ?>
      <div class="row g-2 mb-1 align-items-end">
        <div class="col-auto small fw-semibold pb-1" style="min-width:5.5rem">Zajęcia <?= $i + 1 ?><?= $i === 0 ? ' *' : '' ?></div>
        <div class="col-sm-3"><label class="form-label small mb-0" for="gu-d<?= $i ?>">Data</label><input class="form-control form-control-sm" type="date" id="gu-d<?= $i ?>" name="l_date[]" value="<?= h((string)$oa('l_date', $i)) ?>"<?= $i === 0 ? ' required' : '' ?>></div>
        <div class="col-sm-2"><label class="form-label small mb-0" for="gu-f<?= $i ?>">Od</label><input class="form-control form-control-sm" type="time" id="gu-f<?= $i ?>" name="l_from[]" value="<?= h((string)$oa('l_from', $i)) ?>"<?= $i === 0 ? ' required' : '' ?>></div>
        <div class="col-sm-2"><label class="form-label small mb-0" for="gu-t<?= $i ?>">Do</label><input class="form-control form-control-sm" type="time" id="gu-t<?= $i ?>" name="l_to[]" value="<?= h((string)$oa('l_to', $i)) ?>"<?= $i === 0 ? ' required' : '' ?>></div>
        <div class="col-sm-3"><label class="form-label small mb-0" for="gu-lm<?= $i ?>">Forma</label>
          <select class="form-select form-select-sm gu-method" id="gu-lm<?= $i ?>" name="l_method[]">
            <?php foreach (TiSimpleGroups::METHODS as $_mk => $_ml): ?><option value="<?= h($_mk) ?>"<?= $oa('l_method', $i, 'stacjonarna') === $_mk ? ' selected' : '' ?>><?= h($_ml) ?></option><?php endforeach; ?>
          </select></div>
      </div>
      <?php endfor; ?>
      <div class="form-text">Puste wiersze są pomijane. Brak dostępności prowadzącego nie blokuje zapisu — pokaże się ostrzeżenie.
        Terminy w różnych formach (część stacjonarnie, część online) zakładają grupę jako „Hybryda krótka” — stawkę online ustawisz przy uczestnikach.</div>
      <p class="small fw-semibold mb-0 mt-1" id="gu-mix" aria-live="polite"></p>
    </fieldset>

    <fieldset class="mb-3">
      <legend class="h6 fw-bold">5. Panel uczestników</legend>
      <?php foreach (TiSimpleGroups::MODES as $m => $ml): ?>
      <div class="form-check"><input class="form-check-input" type="radio" name="mode" id="gu-m<?= $m ?>" value="<?= $m ?>"<?= (int)$o('mode', 1) === $m ? ' checked' : '' ?>>
        <label class="form-check-label" for="gu-m<?= $m ?>">Uproszczony — <?= h($ml) ?></label></div>
      <?php endforeach; ?>
      <div class="form-check mt-2"><input class="form-check-input" type="checkbox" id="gu-notify" name="notify" value="1"<?= $o('notify') ? ' checked' : '' ?>>
        <label class="form-check-label small" for="gu-notify">Wyślij uczestnikom SMS z terminami</label></div>
    </fieldset>

    <button class="btn btn-primary"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Utwórz grupę</button>
  </form>
  </div>
</section>

<section class="card border-0 shadow-sm mb-4" id="konwerter" aria-labelledby="gu-conv-h">
  <div class="card-header fw-semibold" id="gu-conv-h"><i class="bi bi-arrow-left-right me-1 text-primary" aria-hidden="true"></i>Konwerter grup</div>
  <div class="card-body">
    <p class="small text-body-secondary">Zamienia istniejącą grupę w uproszczoną (z nazwą KON) albo uproszczoną w zwykłą. Lekcje, uczestnicy i rozliczenia zostają bez zmian — zmienia się nazwa i panel uczestników.</p>
    <form method="get" class="row g-2 align-items-end mb-3">
      <div class="col-sm-8">
        <label class="form-label small mb-0" for="gu-conv-c">Grupa</label>
        <select class="form-select form-select-sm" id="gu-conv-c" name="konwertuj" onchange="this.form.submit()">
          <option value="0">— wybierz grupę —</option>
          <?php foreach ($courses as $c): ?><option value="<?= (int)$c['id'] ?>"<?= (int)$c['id'] === $conv ? ' selected' : '' ?>><?= h($c['name']) ?><?= trim((string)($c['internal_desc'] ?? '')) !== '' ? ' — ' . h($c['internal_desc']) : '' ?><?= (int)$c['simple_mode'] > 0 ? ' (uproszczona)' : '' ?><?= empty($c['is_active']) ? ' — nieaktywna' : '' ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-auto"><button class="btn btn-sm btn-outline-secondary">Wybierz</button></div>
    </form>
    <?php if ($convC): $cm = (int)$convC['simple_mode']; ?>
    <form method="post">
      <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="convert"><input type="hidden" name="course_id" value="<?= (int)$convC['id'] ?>">
      <p class="mb-2">Teraz: <strong><?= h($convC['name']) ?></strong> — <?= $cm > 0 ? 'uproszczona (' . h(TiSimpleGroups::MODES[$cm] ?? '') . ')' : 'zwykła grupa' ?></p>
      <label class="form-label small mb-0" for="gu-conv-mode">Zamień na</label>
      <select class="form-select form-select-sm mb-2" id="gu-conv-mode" name="mode" style="max-width:26rem">
        <?php if ($cm > 0): ?><option value="<?= $cm ?>" selected>Bez zmiany trybu (uproszczona — <?= h(TiSimpleGroups::MODES[$cm] ?? '') ?>)</option><option value="0">Zwykła grupa (pełny panel uczestników)</option><?php endif; ?>
        <?php foreach (TiSimpleGroups::MODES as $m => $ml): if ($m === $cm) continue; ?><option value="<?= $m ?>">Uproszczona — <?= h($ml) ?></option><?php endforeach; ?>
      </select>
      <div id="gu-conv-kon" class="mb-2">
        <div class="small fw-semibold mb-1">Nazwa KON<?= TiSimpleGroups::isValidName((string)$convC['name']) ? ' (zostaw, by nie zmieniać)' : '' ?></div>
        <?php if (TiSimpleGroups::isValidName((string)$convC['name'])): [, $_n, $_l, $_y] = explode('.', (string)$convC['name']); else: $_n = sprintf('%05d', TiSimpleGroups::nextNumber($convYear)); $_l = $convLet; $_y = (string)$convYear; endif; ?>
        <div class="gu-kon"><span>KON.</span>
          <label class="visually-hidden" for="gu-cn">Numer</label><input class="form-control form-control-sm" id="gu-cn" name="k_num" size="6" maxlength="5" value="<?= h($_n) ?>"><span>.</span>
          <label class="visually-hidden" for="gu-cl">Litery</label><input class="form-control form-control-sm" id="gu-cl" name="k_let" size="4" maxlength="3" value="<?= h($_l) ?>"><span>.</span>
          <label class="visually-hidden" for="gu-cy">Rok</label><input class="form-control form-control-sm" id="gu-cy" name="k_year" size="5" maxlength="4" value="<?= h($_y) ?>">
        </div>
      </div>
      <label class="form-label small mb-0" for="gu-conv-desc">Opis <span class="text-body-secondary fw-normal">(tylko dla kadry)</span></label>
      <input class="form-control form-control-sm mb-2" id="gu-conv-desc" name="desc" maxlength="300" style="max-width:26rem" value="<?= h((string)($convC['internal_desc'] ?? '')) ?>">
      <?php if ($cm > 0): ?>
      <div id="gu-conv-plain" class="mb-2" hidden>
        <label class="form-label small mb-0" for="gu-conv-name">Nowa nazwa grupy (puste = bez zmiany)</label>
        <input class="form-control form-control-sm" id="gu-conv-name" name="new_name" style="max-width:26rem">
      </div>
      <?php endif; ?>
      <button class="btn btn-sm btn-primary"><i class="bi bi-arrow-left-right me-1" aria-hidden="true"></i>Konwertuj</button>
      <a class="btn btn-sm btn-link" href="index.php?course=<?= (int)$convC['id'] ?>&amp;tab=lekcje">Otwórz grupę</a>
    </form>
    <?php endif; ?>
  </div>
</section>

</div>
</main>
<script>
(function () {
  var rows = document.getElementById('gu-clients-rows'), add = document.getElementById('gu-add-client'), n = rows.children.length;
  add.addEventListener('click', function () {
    var d = rows.children[0].cloneNode(true), inp = d.querySelector('input'), lab = d.querySelector('label');
    n++; inp.value = ''; inp.id = 'gu-cl-' + n; lab.htmlFor = inp.id; lab.textContent = 'Uczestnik z bazy ' + n;
    rows.appendChild(d); inp.focus();
  });
  // Litery yyy: podpowiedź z pierwszego uczestnika (nazwisko 2 + imię 1)
  var letIn = document.getElementById('gu-let');
  function suggest(name) {
    if (!letIn || letIn.value.trim() !== '') return;
    var p = name.replace(/·\s*#\d+\s*$/, '').trim().split(/\s+/); if (!p[0]) return;
    var last = p[p.length - 1].replace(/[^\p{L}]/gu, ''), first = p[0].replace(/[^\p{L}]/gu, '');
    letIn.value = (last.slice(0, 2) + first.slice(0, 1)).toUpperCase().padEnd(3, 'X');
  }
  document.addEventListener('change', function (e) {
    if (e.target.classList && e.target.classList.contains('gu-client')) suggest(e.target.value);
    if (e.target.id === 'gu-np-n0') suggest(e.target.value);
  });
  // Podsumowanie form zajęć (mieszane → Hybryda krótka)
  var mix = document.getElementById('gu-mix'), typ = document.getElementById('gu-type');
  function mixInfo() {
    var on = 0, st = 0;
    document.querySelectorAll('.gu-method').forEach(function (s, i) {
      if (!document.getElementById('gu-d' + i).value) return;
      if (s.value.indexOf('zdalna') === 0) on++; else st++;
    });
    var m = on && st;
    if (m && typ) typ.value = 'hybrid';
    mix.textContent = m ? 'Hybryda krótka: ' + st + ' stacjonarnie, ' + on + ' online.' : '';
  }
  document.addEventListener('change', function (e) { if (e.target.matches && e.target.matches('.gu-method, input[type=date]')) mixInfo(); });
  mixInfo();
  // Rok z daty pierwszych zajęć
  var d0 = document.getElementById('gu-d0'), yr = document.getElementById('gu-year');
  if (d0 && yr) d0.addEventListener('change', function () { if (/^\d{4}/.test(d0.value)) yr.value = d0.value.slice(0, 4); });
  // Konwerter: przy zamianie na zwykłą — pole nazwy zamiast KON
  var cm = document.getElementById('gu-conv-mode'), kon = document.getElementById('gu-conv-kon'), plain = document.getElementById('gu-conv-plain');
  function sync() { if (!cm || !plain) return; var z = cm.value === '0'; kon.hidden = z; plain.hidden = !z; kon.querySelectorAll('input').forEach(function (i) { i.disabled = z; }); }
  if (cm) { cm.addEventListener('change', sync); sync(); }
})();
</script>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
