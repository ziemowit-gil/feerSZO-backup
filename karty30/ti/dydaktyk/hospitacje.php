<?php
/**
 * karty30/ti/dydaktyk/hospitacje.php — Hospitacje i wizytacje (moduł kierownika).
 *
 * Kierownik (dyd_is_staff):  lista z filtrami, „Zaplanuj” (?nowa=1), arkusz do wypełnienia (?id=),
 *                            zmiana statusu, powiadomienie prowadzącego e-mailem, PDF (?pdf=ID).
 * Prowadzący:                tylko swoje pozycje (bez odwołanych), podgląd arkusza i potwierdzenie
 *                            zapoznania się z komentarzem.
 * Logika: modules/ti_hospitacje/logic/tiHospitacje.php.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 3) . '/modules/ti_hospitacje/logic/tiHospitacje.php';

$me    = dyd_require();
$uid   = (int)$me['user_id'];
$staff = dyd_is_staff();
karty30_migrate();
ti_hosp_migrate();

$can_see = fn(?array $h) => $h && ($staff || ((int)$h['instructor_id'] === $uid && $h['status'] !== 'odwolana'));
$self    = 'hospitacje.php';

// ── PDF ──────────────────────────────────────────────────────────────────────
if (!empty($_GET['pdf'])) {
    $h = ti_hosp_get((int)$_GET['pdf']);
    if (!$can_see($h)) { http_response_code(403); exit('Brak dostępu.'); }
    $pdf = ti_hosp_pdf($h);
    if ($pdf === null) { http_response_code(500); exit('Nie udało się wygenerować PDF.'); }
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $h['typ'] . '-' . $h['data'] . '-' . (int)$h['id'] . '.pdf"');
    header('Cache-Control: private, no-store');
    echo $pdf;
    exit;
}

// ── Zapisy ───────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    $op = (string)($_POST['_op'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    try {
        if ($op === 'ack') {
            ti_hosp_acknowledge($id, $uid, (string)($me['name'] ?? ''), (string)($_POST['komentarz'] ?? ''));
            flash_set('success', 'Potwierdzono zapoznanie się z arkuszem.');
        } elseif (!$staff) {
            http_response_code(403); exit('Hospitacje i wizytacje prowadzi kierownik.');
        } elseif ($op === 'save') {
            $id = ti_hosp_save($_POST, $uid, $id ?: null);
            $msg = 'Zapisano.';
            if (!empty($_POST['przeprowadzona']) && (ti_hosp_get($id)['status'] ?? '') === 'planowana') {
                ti_hosp_set_status($id, 'przeprowadzona', $uid);
                $msg = 'Zapisano arkusz i oznaczono jako przeprowadzoną.';
                if (!empty($_POST['powiadom'])) $msg .= ti_hosp_notify($id, 'arkusz') ? ' Prowadzący dostał e-mail z prośbą o zapoznanie się.' : ' Nie udało się wysłać e-maila (brak adresu prowadzącego?).';
            } elseif (!empty($_POST['powiadom']) && empty($_POST['id'])) {
                $msg .= ti_hosp_notify($id, 'plan') ? ' Prowadzący dostał e-mail o terminie.' : ' Nie udało się wysłać e-maila (brak adresu prowadzącego?).';
            }
            flash_set('success', $msg);
        } elseif ($op === 'status') {
            $st = (string)($_POST['status'] ?? '');
            ti_hosp_set_status($id, $st, $uid, trim((string)($_POST['powod'] ?? '')));
            if ($st === 'przeprowadzona' && !empty($_POST['powiadom'])) ti_hosp_notify($id, 'arkusz');
            flash_set('success', 'Zmieniono status: ' . mb_strtolower(TI_HOSP_STATUSES[$st] ?? $st) . '.');
        } elseif ($op === 'notify') {
            $h = ti_hosp_get($id);
            $ok = $h && ti_hosp_notify($id, $h['status'] === 'planowana' ? 'plan' : 'arkusz');
            flash_set($ok ? 'success' : 'warning', $ok ? 'Wysłano e-mail do prowadzącego.' : 'Nie udało się wysłać e-maila (brak adresu prowadzącego?).');
        } elseif ($op === 'delete') {
            $h = ti_hosp_get($id);
            if ($h && $h['status'] === 'planowana') { db_exec("DELETE FROM k30_ti_hospitacje WHERE id = ?", [$id]); flash_set('success', 'Usunięto zaplanowaną pozycję.'); $id = 0; }
            else flash_set('warning', 'Usunąć można tylko pozycję zaplanowaną — przeprowadzoną odwołaj albo zostaw w dokumentacji.');
        }
    } catch (\Throwable $e) {
        flash_set('danger', $e->getMessage());
        if ($op === 'save' && !$id) { header('Location: ' . $self . '?nowa=1'); exit; }
    }
    header('Location: ' . $self . ($id ? '?id=' . $id : '')); exit;
}

// ── Dane widoku ──────────────────────────────────────────────────────────────
$d  = fn($v) => $v ? date('d.m.Y', strtotime((string)$v)) : '';
$cur = !empty($_GET['id']) ? ti_hosp_get((int)$_GET['id']) : null;
if (!empty($_GET['id']) && !$can_see($cur)) { http_response_code(403); exit('Brak dostępu.'); }
$nowa = $staff && !empty($_GET['nowa']);
$edit = $staff && ($nowa || ($cur && $cur['status'] !== 'zakonczona' && $cur['status'] !== 'odwolana'));

$f = ['typ' => (string)($_GET['typ'] ?? ''), 'status' => (string)($_GET['status'] ?? ''),
      'instructor_id' => (int)($_GET['instructor_id'] ?? 0), 'ym' => (string)($_GET['ym'] ?? '')];
$lista = (!$cur && !$nowa) ? ti_hosp_list($staff ? $f : [], $staff ? null : $uid) : [];

// Formularz: grupa i zajęcia (zajęcia grupy z ostatnich 60 i najbliższych 60 dni).
$form = $cur ?: ['typ' => (string)($_GET['typ'] ?? 'hospitacja'), 'course_id' => (int)($_GET['course'] ?? 0), 'session_id' => 0,
                 'data' => '', 'godzina' => '', 'temat' => '', 'cel' => '', 'zakres' => '', 'hospitujacy' => (string)($me['name'] ?? ''),
                 'funkcja' => 'Kierownik', 'kryteria' => '{}', 'mocne_strony' => '', 'wnioski' => '', 'zalecenia' => '', 'termin_zalecen' => '',
                 'status' => 'planowana', 'instructor_id' => 0];
if ($cur && !empty($_GET['course'])) $form['course_id'] = (int)$_GET['course'];
$kursy = $edit ? db_all("SELECT c.id, COALESCE(NULLIF(c.display_name, ''), c.name) AS name, u.name AS instr
                           FROM k30_ti_courses c LEFT JOIN users u ON u.id = c.instructor_id
                          WHERE c.is_active = 1 OR c.id = ? ORDER BY name COLLATE NOCASE", [(int)$form['course_id']]) : [];
$zajecia = ($edit && (int)$form['course_id']) ? db_all(
    "SELECT id, lesson_date, time_from, time_to, topic, status FROM k30_ti_sessions
      WHERE course_id = ? AND status NOT IN ('draft', 'reserved') AND lesson_date BETWEEN date('now', '-60 day') AND date('now', '+60 day')
      ORDER BY lesson_date, time_from", [(int)$form['course_id']]) : [];
$instr = $staff && !$cur && !$nowa ? k30_ti_instructors() : [];

$KP_TITLE  = 'Hospitacje i wizytacje — Panel dydaktyka';
$KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => (string)($me['name'] ?? ''), 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">
<style>
  .hs-table td.num, .hs-table th.num { text-align: right; font-variant-numeric: tabular-nums; }
  .hs-crit th { width: 34%; font-weight: 600; } .hs-crit .hint { display: block; font-weight: 400; font-size: .8rem; color: #495057; }
  .hs-crit select { min-width: 9rem; }
</style>

<?php if ($staff): $KIER_CUR = $self; $KIER_LABEL = 'Hospitacje i wizytacje'; include __DIR__ . '/_kierownik_bar.php'; endif; ?>

<main id="main" class="dyd-wrap">
<div class="container-fluid py-3" style="max-width:1200px">
  <?= flash_html() ?>
  <div class="d-flex align-items-center gap-2 flex-wrap mb-3">
    <h1 class="h4 fw-bold mb-0 me-auto"><i class="bi bi-clipboard2-pulse me-2" aria-hidden="true"></i><?= $staff ? 'Hospitacje i wizytacje' : 'Moje hospitacje i wizytacje' ?></h1>
    <?php if ($cur || $nowa): ?>
      <a class="btn btn-outline-secondary btn-sm" href="<?= $self ?>"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Wróć do listy</a>
    <?php elseif ($staff): ?>
      <a class="btn btn-primary btn-sm" href="<?= $self ?>?nowa=1&amp;typ=hospitacja"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Zaplanuj hospitację</a>
      <a class="btn btn-outline-primary btn-sm" href="<?= $self ?>?nowa=1&amp;typ=wizytacja"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Zaplanuj wizytację</a>
    <?php endif; ?>
  </div>

<?php if (!$cur && !$nowa): /* ═══════════ LISTA ═══════════ */ ?>
  <?php if ($staff): ?>
  <form method="get" class="row g-2 align-items-end mb-3" aria-label="Filtry listy">
    <div class="col-sm-2"><label class="form-label small mb-1" for="fTyp">Rodzaj</label>
      <select class="form-select form-select-sm" id="fTyp" name="typ"><option value="">wszystkie</option>
        <?php foreach (TI_HOSP_TYPES as $k => $v): ?><option value="<?= $k ?>"<?= $f['typ'] === $k ? ' selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select></div>
    <div class="col-sm-3"><label class="form-label small mb-1" for="fSt">Status</label>
      <select class="form-select form-select-sm" id="fSt" name="status"><option value="">wszystkie</option>
        <?php foreach (TI_HOSP_STATUSES as $k => $v): ?><option value="<?= $k ?>"<?= $f['status'] === $k ? ' selected' : '' ?>><?= h($v) ?></option><?php endforeach; ?></select></div>
    <div class="col-sm-3"><label class="form-label small mb-1" for="fIn">Prowadzący</label>
      <select class="form-select form-select-sm" id="fIn" name="instructor_id"><option value="0">wszyscy</option>
        <?php foreach ($instr as $i): ?><option value="<?= (int)$i['id'] ?>"<?= $f['instructor_id'] === (int)$i['id'] ? ' selected' : '' ?>><?= h($i['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-sm-2"><label class="form-label small mb-1" for="fYm">Miesiąc</label>
      <input class="form-control form-control-sm" type="month" id="fYm" name="ym" value="<?= h($f['ym']) ?>"></div>
    <div class="col-sm-2"><button class="btn btn-sm btn-outline-secondary w-100" type="submit">Pokaż</button></div>
  </form>
  <?php endif; ?>

  <?php if (!$lista): ?>
    <p class="text-body-secondary"><?= $staff ? 'Brak hospitacji i wizytacji dla wybranych filtrów.' : 'Nie masz zaplanowanych ani przeprowadzonych hospitacji.' ?></p>
  <?php else: ?>
  <div class="table-responsive">
  <table class="table table-sm table-hover hs-table align-middle">
    <caption class="visually-hidden">Hospitacje i wizytacje</caption>
    <thead><tr><th scope="col">Data</th><th scope="col">Rodzaj</th><th scope="col">Grupa</th>
      <?php if ($staff): ?><th scope="col">Prowadzący</th><?php endif; ?>
      <th scope="col">Temat</th><th scope="col" class="num">Średnia</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Akcje</span></th></tr></thead>
    <tbody>
    <?php foreach ($lista as $r): $avg = $r['typ'] === 'hospitacja' && $r['status'] !== 'planowana' ? ti_hosp_average($r) : null; ?>
      <tr>
        <td class="text-nowrap"><?= h($d($r['data'])) ?><?= $r['godzina'] !== '' ? ' <span class="small text-body-secondary">' . h($r['godzina']) . '</span>' : '' ?></td>
        <td><?= h(TI_HOSP_TYPES[$r['typ']] ?? $r['typ']) ?></td>
        <td><?= h((string)$r['course_name']) ?></td>
        <?php if ($staff): ?><td><?= h((string)$r['instructor_name']) ?></td><?php endif; ?>
        <td><?= h($r['temat'] !== '' ? $r['temat'] : '—') ?></td>
        <td class="num"><?= $avg !== null ? h(number_format($avg, 2, ',', '')) : '—' ?></td>
        <td><span class="badge text-bg-<?= ['planowana' => 'info', 'przeprowadzona' => 'warning', 'zakonczona' => 'success', 'odwolana' => 'secondary'][$r['status']] ?? 'secondary' ?>">
          <?= h(['planowana' => 'planowana', 'przeprowadzona' => 'do zapoznania', 'zakonczona' => 'zakończona', 'odwolana' => 'odwołana'][$r['status']] ?? $r['status']) ?></span></td>
        <td class="text-nowrap"><a class="btn btn-sm btn-outline-primary" href="<?= $self ?>?id=<?= (int)$r['id'] ?>">Otwórz<span class="visually-hidden"> — <?= h(TI_HOSP_TYPES[$r['typ']] ?? '') ?> <?= h($d($r['data'])) ?></span></a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>

<?php elseif ($edit): /* ═══════════ FORMULARZ KIEROWNIKA ═══════════ */
    $hosp = $form['typ'] === 'hospitacja'; $kr = ti_hosp_criteria($form); ?>
  <?php if ($cur): ?>
  <p class="mb-2"><span class="badge text-bg-light border"><?= h(TI_HOSP_STATUSES[$cur['status']] ?? $cur['status']) ?></span>
    <?= $cur['instructor_name'] ? ' · prowadzący: <strong>' . h($cur['instructor_name']) . '</strong>' : '' ?></p>
  <?php endif; ?>

  <form method="get" class="card mb-3" aria-label="Wybór grupy">
    <div class="card-body row g-2 align-items-end">
      <?php if ($cur): ?><input type="hidden" name="id" value="<?= (int)$cur['id'] ?>"><?php else: ?><input type="hidden" name="nowa" value="1"><input type="hidden" name="typ" value="<?= h($form['typ']) ?>"><?php endif; ?>
      <div class="col-md-8"><label class="form-label small mb-1" for="hsKurs">Grupa</label>
        <select class="form-select" id="hsKurs" name="course"><option value="0">— wybierz grupę —</option>
          <?php foreach ($kursy as $k): ?><option value="<?= (int)$k['id'] ?>"<?= (int)$form['course_id'] === (int)$k['id'] ? ' selected' : '' ?>><?= h($k['name'] . ($k['instr'] ? ' — ' . $k['instr'] : '')) ?></option><?php endforeach; ?>
        </select></div>
      <div class="col-md-4"><button class="btn btn-outline-secondary w-100" type="submit">Pokaż zajęcia grupy</button></div>
    </div>
  </form>

  <form method="post" class="card">
    <div class="card-body">
      <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
      <input type="hidden" name="_op" value="save">
      <input type="hidden" name="id" value="<?= (int)($cur['id'] ?? 0) ?>">
      <input type="hidden" name="typ" value="<?= h($form['typ']) ?>">
      <input type="hidden" name="course_id" value="<?= (int)$form['course_id'] ?>">
      <h2 class="h5 fw-bold mb-3"><?= $hosp ? 'Hospitacja zajęć' : 'Wizytacja' ?></h2>
      <?php if (!(int)$form['course_id']): ?>
        <div class="alert alert-info small">Najpierw wybierz grupę powyżej i kliknij „Pokaż zajęcia grupy”.</div>
      <?php endif; ?>

      <fieldset class="mb-3"><legend class="h6 fw-semibold">Termin</legend>
        <div class="row g-2">
          <div class="col-md-6"><label class="form-label small mb-1" for="hsSesja">Zajęcia (z planu grupy)</label>
            <select class="form-select" id="hsSesja" name="session_id" aria-describedby="hsSesjaOpis"><option value="0">— bez wskazania zajęć —</option>
              <?php foreach ($zajecia as $z): ?><option value="<?= (int)$z['id'] ?>"<?= (int)$form['session_id'] === (int)$z['id'] ? ' selected' : '' ?>>
                <?= h($d($z['lesson_date']) . ' ' . substr((string)$z['time_from'], 0, 5) . '–' . substr((string)$z['time_to'], 0, 5) . ($z['topic'] ? ' · ' . $z['topic'] : '')) ?></option><?php endforeach; ?>
            </select>
            <div id="hsSesjaOpis" class="form-text">Data, godzina i temat uzupełnią się z zajęć, jeśli zostawisz je puste.</div></div>
          <div class="col-md-3"><label class="form-label small mb-1" for="hsData">Data</label>
            <input class="form-control" type="date" id="hsData" name="data" value="<?= h((string)$form['data']) ?>"></div>
          <div class="col-md-3"><label class="form-label small mb-1" for="hsGodz">Godzina</label>
            <input class="form-control" type="time" id="hsGodz" name="godzina" value="<?= h((string)$form['godzina']) ?>"></div>
          <div class="col-12"><label class="form-label small mb-1" for="hsTemat">Temat zajęć</label>
            <input class="form-control" id="hsTemat" name="temat" value="<?= h((string)$form['temat']) ?>"></div>
        </div>
      </fieldset>

      <fieldset class="mb-3"><legend class="h6 fw-semibold"><?= $hosp ? 'Hospitujący' : 'Wizytujący' ?> i cel</legend>
        <div class="row g-2">
          <div class="col-md-6"><label class="form-label small mb-1" for="hsKto">Imię i nazwisko</label>
            <input class="form-control" id="hsKto" name="hospitujacy" value="<?= h((string)$form['hospitujacy']) ?>"></div>
          <div class="col-md-6"><label class="form-label small mb-1" for="hsFun">Funkcja</label>
            <input class="form-control" id="hsFun" name="funkcja" value="<?= h((string)$form['funkcja']) ?>"></div>
          <div class="col-12"><label class="form-label small mb-1" for="hsCel">Cel</label>
            <textarea class="form-control" id="hsCel" name="cel" rows="2"><?= h((string)$form['cel']) ?></textarea></div>
          <?php if (!$hosp): ?>
          <div class="col-12"><label class="form-label small mb-1" for="hsZakres">Zakres wizytacji</label>
            <textarea class="form-control" id="hsZakres" name="zakres" rows="2" placeholder="np. warunki w sali, sprzęt, dostępność, dokumentacja grupy"><?= h((string)$form['zakres']) ?></textarea></div>
          <?php endif; ?>
        </div>
      </fieldset>

      <?php if ($hosp): ?>
      <fieldset class="mb-3"><legend class="h6 fw-semibold">Ocena zajęć <span class="small fw-normal text-body-secondary">(1 — niedostatecznie, 5 — bardzo dobrze; wypełnij po zajęciach)</span></legend>
        <table class="table table-sm hs-crit align-middle">
          <caption class="visually-hidden">Kryteria oceny zajęć</caption>
          <thead><tr><th scope="col">Kryterium</th><th scope="col">Ocena</th><th scope="col">Uwagi</th></tr></thead>
          <tbody>
          <?php foreach (TI_HOSP_CRITERIA as $k => [$nazwa, $hint]): ?>
            <tr><th scope="row" id="kr_<?= $k ?>"><?= h($nazwa) ?><span class="hint"><?= h($hint) ?></span></th>
              <td><select class="form-select form-select-sm" name="k_ocena[<?= $k ?>]" aria-labelledby="kr_<?= $k ?>">
                <option value="0"<?= $kr[$k]['ocena'] === 0 ? ' selected' : '' ?>>nie dotyczy / brak</option>
                <?php for ($o = 1; $o <= 5; $o++): ?><option value="<?= $o ?>"<?= $kr[$k]['ocena'] === $o ? ' selected' : '' ?>><?= $o ?></option><?php endfor; ?>
              </select></td>
              <td><input class="form-control form-control-sm" name="k_uwagi[<?= $k ?>]" value="<?= h($kr[$k]['uwagi']) ?>" aria-label="Uwagi: <?= h($nazwa) ?>"></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <label class="form-label small mb-1" for="hsMocne">Mocne strony</label>
        <textarea class="form-control" id="hsMocne" name="mocne_strony" rows="2"><?= h((string)$form['mocne_strony']) ?></textarea>
      </fieldset>
      <?php endif; ?>

      <fieldset class="mb-3"><legend class="h6 fw-semibold"><?= $hosp ? 'Wnioski i zalecenia' : 'Ustalenia i zalecenia' ?></legend>
        <div class="row g-2">
          <div class="col-12"><label class="form-label small mb-1" for="hsWn"><?= $hosp ? 'Wnioski' : 'Ustalenia' ?></label>
            <textarea class="form-control" id="hsWn" name="wnioski" rows="3"><?= h((string)$form['wnioski']) ?></textarea></div>
          <div class="col-md-9"><label class="form-label small mb-1" for="hsZal">Zalecenia</label>
            <textarea class="form-control" id="hsZal" name="zalecenia" rows="2"><?= h((string)$form['zalecenia']) ?></textarea></div>
          <div class="col-md-3"><label class="form-label small mb-1" for="hsTerm">Termin realizacji zaleceń</label>
            <input class="form-control" type="date" id="hsTerm" name="termin_zalecen" value="<?= h((string)$form['termin_zalecen']) ?>"></div>
        </div>
      </fieldset>

      <div class="border-top pt-3 d-flex flex-wrap gap-3 align-items-center">
        <?php if (($form['status'] ?? 'planowana') === 'planowana' && $cur): ?>
        <div class="form-check"><input class="form-check-input" type="checkbox" name="przeprowadzona" value="1" id="hsDone">
          <label class="form-check-label" for="hsDone">Przeprowadzona — arkusz wypełniony</label></div>
        <?php endif; ?>
        <div class="form-check"><input class="form-check-input" type="checkbox" name="powiadom" value="1" id="hsMail" checked>
          <label class="form-check-label" for="hsMail"><?= $cur ? 'Wyślij prowadzącemu arkusz do zapoznania się (przy oznaczeniu jako przeprowadzona)' : 'Powiadom prowadzącego e-mailem o terminie' ?></label></div>
        <button class="btn btn-primary ms-auto" type="submit"<?= (int)$form['course_id'] ? '' : ' disabled' ?>><i class="bi bi-save me-1" aria-hidden="true"></i><?= $cur ? 'Zapisz' : 'Zaplanuj' ?></button>
      </div>
    </div>
  </form>

  <?php if ($cur): ?>
  <div class="d-flex flex-wrap gap-2 mt-3">
    <a class="btn btn-outline-secondary" href="<?= $self ?>?pdf=<?= (int)$cur['id'] ?>" target="_blank" rel="noopener"><i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>PDF<span class="visually-hidden"> — nowa karta</span></a>
    <form method="post" class="d-inline"><input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="notify"><input type="hidden" name="id" value="<?= (int)$cur['id'] ?>">
      <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-envelope me-1" aria-hidden="true"></i>Wyślij e-mail prowadzącemu</button></form>
    <?php if ($cur['status'] === 'planowana'): ?>
    <form method="post" class="d-inline-flex gap-1"><input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="status"><input type="hidden" name="id" value="<?= (int)$cur['id'] ?>"><input type="hidden" name="status" value="odwolana">
      <label class="visually-hidden" for="hsPowod">Powód odwołania</label><input class="form-control" id="hsPowod" name="powod" placeholder="Powód odwołania">
      <button class="btn btn-outline-danger text-nowrap" type="submit">Odwołaj</button></form>
    <form method="post" class="d-inline"><input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="delete"><input type="hidden" name="id" value="<?= (int)$cur['id'] ?>">
      <button class="btn btn-link text-danger" type="submit">Usuń</button></form>
    <?php endif; ?>
  </div>
  <?php endif; ?>

<?php else: /* ═══════════ PODGLĄD (prowadzący albo zamknięta pozycja) ═══════════ */ ?>
  <section class="card mb-3" aria-labelledby="hsArk">
    <div class="card-body">
      <h2 class="h5 fw-bold mb-2" id="hsArk"><?= h(TI_HOSP_TYPES[$cur['typ']] ?? '') ?> — <?= h($d($cur['data'])) ?><?= $cur['godzina'] !== '' ? ', ' . h($cur['godzina']) : '' ?></h2>
      <p class="mb-2"><span class="badge text-bg-light border"><?= h(TI_HOSP_STATUSES[$cur['status']] ?? $cur['status']) ?></span></p>
      <?php if ($cur['status'] === 'planowana'): ?>
        <dl class="row small mb-0">
          <dt class="col-sm-3 fw-normal text-body-secondary">Grupa</dt><dd class="col-sm-9"><?= h((string)$cur['course_name']) ?></dd>
          <?php if ($cur['temat'] !== ''): ?><dt class="col-sm-3 fw-normal text-body-secondary">Temat</dt><dd class="col-sm-9"><?= h($cur['temat']) ?></dd><?php endif; ?>
          <dt class="col-sm-3 fw-normal text-body-secondary"><?= $cur['typ'] === 'hospitacja' ? 'Hospitujący' : 'Wizytujący' ?></dt><dd class="col-sm-9"><?= h($cur['hospitujacy'] . ($cur['funkcja'] !== '' ? ', ' . $cur['funkcja'] : '')) ?></dd>
          <?php if ($cur['cel'] !== ''): ?><dt class="col-sm-3 fw-normal text-body-secondary">Cel</dt><dd class="col-sm-9"><?= nl2br(h($cur['cel'])) ?></dd><?php endif; ?>
        </dl>
      <?php else: ?>
        <iframe title="Arkusz: <?= h(TI_HOSP_TYPES[$cur['typ']] ?? '') ?> z dnia <?= h($d($cur['data'])) ?>" src="<?= $self ?>?pdf=<?= (int)$cur['id'] ?>" class="border w-100" style="min-height:min(75vh,48rem)"></iframe>
      <?php endif; ?>
    </div>
  </section>

  <?php if (!$staff && $cur['status'] === 'przeprowadzona' && (int)$cur['instructor_id'] === $uid): ?>
  <form method="post" class="card">
    <div class="card-body">
      <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>"><input type="hidden" name="_op" value="ack"><input type="hidden" name="id" value="<?= (int)$cur['id'] ?>">
      <label class="form-label fw-semibold" for="hsKom">Twój komentarz do arkusza (nieobowiązkowy)</label>
      <textarea class="form-control mb-3" id="hsKom" name="komentarz" rows="3"></textarea>
      <button class="btn btn-success" type="submit"><i class="bi bi-check2-square me-1" aria-hidden="true"></i>Zapoznałem/am się z arkuszem</button>
    </div>
  </form>
  <?php endif; ?>
  <a class="btn btn-outline-secondary mt-3" href="<?= $self ?>?pdf=<?= (int)$cur['id'] ?>" target="_blank" rel="noopener"><i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>PDF<span class="visually-hidden"> — nowa karta</span></a>
<?php endif; ?>
</div>
</main>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
