<?php
/**
 * karty30/ti/dydaktyk/protokoly_zajec.php — Protokoły zajęć (prowadzący i kierownik).
 * Lista spotkań kursu, tworzenie, punkty i zatwierdzenie w oknie dialogowym.
 * GET: course=ID (wybór grupy), open=ID (otwórz okno), frag=1&id=ID (zawartość okna — fetch).
 */
require_once __DIR__ . '/auth.php';
require_once dirname(__DIR__, 3) . '/modules/ti_meetings/logic/tiMeetingsManager.php';

$me      = dyd_require();
$uid     = (int)$me['user_id'];
$me_name = (string)($me['name'] ?? '');
$courses = dyd_courses($uid);
$own_ids = array_map(fn($c) => (int)$c['id'], $courses);

// ── Operacje zapisu ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    $op  = (string)($_POST['_op'] ?? '');
    $mid = (int)($_POST['meeting_id'] ?? 0);
    $cid = (int)($_POST['course_id'] ?? 0);
    $m   = $mid ? ti_meeting_get($mid) : null;
    $back = 'protokoly_zajec.php?course=' . $cid;

    try {
        if ($op === 'create') {
            if (!in_array($cid, $own_ids, true)) throw new \RuntimeException('Brak dostępu do tej grupy.');
            $title = trim((string)($_POST['title'] ?? ''));
            $date  = trim((string)($_POST['meeting_date'] ?? ''));
            if (mb_strlen($title) < 3) throw new \RuntimeException('Podaj tytuł protokołu (min. 3 znaki).');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) throw new \RuntimeException('Podaj datę zajęć.');
            $pid = (int)($_POST['period_id'] ?? 0);
            if (!$pid || !ti_period_get($pid)) throw new \RuntimeException('Wybierz okres rozliczeniowy — protokół zajęć dotyczy okresu.');
            $new = ti_meeting_create($cid, $pid, $title, $date, $uid, $me_name);
            flash_set('success', 'Utworzono protokół. Dodaj punkty, potem zatwierdź.');
            header('Location: protokoly_zajec.php?course=' . $cid . '&open=' . $new); exit;
        }

        if (!$m || !dyd_owns_course($uid, (int)$m['course_id'])) throw new \RuntimeException('Brak dostępu do tego protokołu.');
        $back = 'protokoly_zajec.php?course=' . (int)$m['course_id'] . '&open=' . $mid;

        if ($op === 'add_item') {
            if (!ti_meeting_is_editable($m)) throw new \RuntimeException('Protokół jest zatwierdzony — nie można dodawać punktów.');
            $kind  = (string)($_POST['kind'] ?? '');
            $title = trim((string)($_POST['item_title'] ?? ''));
            $body  = (string)($_POST['body'] ?? '');
            if (!array_key_exists($kind, TI_MEETING_KINDS)) throw new \RuntimeException('Wybierz rodzaj punktu.');
            if (mb_strlen($title) < 2) throw new \RuntimeException('Podaj tytuł punktu (min. 2 znaki).');
            ti_meeting_add_item($mid, $kind, $title, $body);
            flash_set('success', 'Dodano punkt.');
        } elseif ($op === 'delete_item') {
            if (!ti_meeting_is_editable($m)) throw new \RuntimeException('Protokół jest zatwierdzony — nie można usuwać punktów.');
            ti_meeting_delete_item((int)($_POST['item_id'] ?? 0), $mid);
            flash_set('success', 'Usunięto punkt.');
        } elseif ($op === 'approve') {
            if (!ti_meeting_is_editable($m)) throw new \RuntimeException('Ten protokół jest już zatwierdzony.');
            ti_meeting_approve($mid, $uid, $me_name);
            flash_set('success', 'Protokół zatwierdzony. Treść została zapisana z podpisem wewnętrznym.');
        }
    } catch (\RuntimeException $e) {
        flash_set('danger', $e->getMessage());
    }
    header('Location: ' . $back); exit;
}

// ── Zawartość okna (fetch z listy) ───────────────────────────────────────────
if (!empty($_GET['frag'])) {
    $fm = ti_meeting_get((int)($_GET['id'] ?? 0));
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    if (!$fm || !dyd_owns_course($uid, (int)$fm['course_id'])) {
        http_response_code(404);
        echo '<div class="alert alert-danger mb-0" role="alert">Nie znaleziono protokołu albo brak dostępu.</div>';
        exit;
    }
    $items    = ti_meeting_items((int)$fm['id']);
    $editable = ti_meeting_is_editable($fm);
    $mid      = (int)$fm['id'];
    ?>
    <div class="dyd-meeting-frag">
      <dl class="row small mb-3">
        <dt class="col-sm-3">Grupa</dt><dd class="col-sm-9"><?= h($fm['course_name']) ?></dd>
        <dt class="col-sm-3">Data zajęć</dt><dd class="col-sm-9"><?= h(date('d.m.Y', strtotime((string)$fm['meeting_date']))) ?></dd>
        <dt class="col-sm-3">Status</dt><dd class="col-sm-9"><?= h(TI_MEETING_STATUS_LABELS[$fm['status']] ?? $fm['status']) ?></dd>
      </dl>

      <?php
        $_per = $fm['period_id'] ? ti_period_get((int)$fm['period_id']) : null;
        $_rows = $_per ? ti_meeting_participant_rows((int)$fm['course_id'], (int)$fm['period_id']) : [];
      ?>
      <h3 class="h6">Okres i kursanci</h3>
      <?php if (!$_per): ?>
        <p class="small text-body-secondary">Protokół nie ma przypisanego okresu.</p>
      <?php elseif (!$_rows): ?>
        <p class="small text-body-secondary">Brak aktywnych kursantów w tej grupie.</p>
      <?php else: ?>
        <p class="small mb-2">Okres: <strong><?= h($_per['name']) ?></strong> (<?= h($_per['date_from']) ?> – <?= h($_per['date_to']) ?>)</p>
        <div class="table-responsive mb-3">
          <table class="table table-sm align-middle">
            <caption class="visually-hidden">Godziny i średnie ocen kursantów za okres <?= h($_per['name']) ?></caption>
            <thead><tr><th scope="col">Kursant</th><th scope="col">Godziny</th><th scope="col">Średnia ocen</th></tr></thead>
            <tbody>
            <?php foreach ($_rows as $_r): ?>
              <tr>
                <th scope="row" class="fw-normal"><?= h($_r['name']) ?></th>
                <td><?= h(rtrim(rtrim(number_format((float)$_r['hours'], 1, ',', ''), '0'), ',')) ?> h</td>
                <td><?= $_r['avg'] !== null ? h(number_format((float)$_r['avg'], 2, ',', '')) : '<span class="text-body-secondary">brak ocen</span>' ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

      <h3 class="h6">Punkty protokołu</h3>
      <?php if (!$items): ?>
        <p class="small text-body-secondary">Brak punktów. Dodaj pierwszy punkt poniżej.</p>
      <?php else: ?>
        <ol class="list-group list-group-numbered mb-3">
          <?php foreach ($items as $it): ?>
          <li class="list-group-item d-flex justify-content-between align-items-start gap-2">
            <div class="ms-2 me-auto">
              <h4 class="h6 mb-1"><?= h($it['title']) ?></h4>
              <div class="small text-body-secondary mb-1"><?= h(TI_MEETING_KINDS[$it['kind']] ?? $it['kind']) ?></div>
              <?php if (trim((string)$it['body']) !== ''): ?><div class="small"><?= nl2br(h($it['body'])) ?></div><?php endif; ?>
            </div>
            <?php if ($editable): ?>
            <form method="post" action="protokoly_zajec.php" class="m-0">
              <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
              <input type="hidden" name="_op" value="delete_item">
              <input type="hidden" name="meeting_id" value="<?= $mid ?>">
              <input type="hidden" name="course_id" value="<?= (int)$fm['course_id'] ?>">
              <input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
              <button type="submit" class="btn btn-sm btn-outline-danger"
                      aria-label="Usuń punkt: <?= h($it['title']) ?>"
                      onclick="return confirm('Usunąć ten punkt?')">Usuń</button>
            </form>
            <?php endif; ?>
          </li>
          <?php endforeach; ?>
        </ol>
      <?php endif; ?>

      <?php if ($editable): ?>
      <form method="post" action="protokoly_zajec.php" class="border rounded p-3 mb-3" aria-labelledby="addItemH">
        <h3 class="h6" id="addItemH">Dodaj punkt</h3>
        <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
        <input type="hidden" name="_op" value="add_item">
        <input type="hidden" name="meeting_id" value="<?= $mid ?>">
        <input type="hidden" name="course_id" value="<?= (int)$fm['course_id'] ?>">
        <div class="mb-2">
          <label class="form-label" for="kind-<?= $mid ?>">Rodzaj punktu</label>
          <select class="form-select form-select-sm" id="kind-<?= $mid ?>" name="kind" required>
            <?php foreach (TI_MEETING_KINDS as $k => $lbl): ?>
              <option value="<?= h($k) ?>"><?= h($lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-2">
          <label class="form-label" for="ititle-<?= $mid ?>">Tytuł punktu</label>
          <input type="text" class="form-control form-control-sm" id="ititle-<?= $mid ?>" name="item_title" required minlength="2" maxlength="200">
        </div>
        <div class="mb-2">
          <label class="form-label" for="ibody-<?= $mid ?>">Treść</label>
          <div id="ibody-help-<?= $mid ?>" class="form-text">Opisz jednym lub kilkoma zdaniami, co ustalono.</div>
          <textarea class="form-control form-control-sm" id="ibody-<?= $mid ?>" name="body" rows="3"
                    aria-describedby="ibody-help-<?= $mid ?>"></textarea>
        </div>
        <button type="submit" class="btn btn-sm btn-primary">Dodaj punkt</button>
      </form>

      <form method="post" action="protokoly_zajec.php" class="d-flex flex-wrap gap-2 align-items-center"
            onsubmit="return <?= $items ? 'confirm(\'Zatwierdzić protokół? Po zatwierdzeniu treści nie można zmieniać.\')' : 'false' ?>">
        <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
        <input type="hidden" name="_op" value="approve">
        <input type="hidden" name="meeting_id" value="<?= $mid ?>">
        <input type="hidden" name="course_id" value="<?= (int)$fm['course_id'] ?>">
        <button type="submit" class="btn btn-success" <?= $items ? '' : 'disabled aria-disabled="true"' ?>>Zatwierdź protokół</button>
        <?php if (!$items): ?><span class="small text-body-secondary">Najpierw dodaj przynajmniej jeden punkt.</span><?php endif; ?>
      </form>
      <?php else: ?>
        <p class="small mb-0"><strong>Protokół zatwierdzony</strong> — treść jest tylko do odczytu.</p>
      <?php endif; ?>

      <?php $_sigs = db_all("SELECT signed_name, signed_at, content_hash FROM k30_ti_meeting_signatures WHERE meeting_id=? ORDER BY id DESC", [$mid]); ?>
      <?php if ($_sigs): ?>
      <h3 class="h6 mt-3">Podpisy wewnętrzne</h3>
      <ul class="small">
        <?php foreach ($_sigs as $sg): ?>
          <li><?= h($sg['signed_name']) ?>, <?= h($sg['signed_at']) ?> — skrót <code><?= h(substr((string)$sg['content_hash'], 0, 12)) ?></code></li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>
    <?php
    exit;
}

// ── Strona listy ─────────────────────────────────────────────────────────────
$sel_course = (int)($_GET['course'] ?? 0);
if (!$sel_course || !in_array($sel_course, $own_ids, true)) $sel_course = $own_ids[0] ?? 0;
$meetings = $sel_course ? ti_meeting_list($sel_course) : [];
$open_id  = (int)($_GET['open'] ?? 0);

$KP_TITLE  = 'Protokoły zajęć — Panel dydaktyka';
$KP_TOPBAR = ['brand' => 'Panel dydaktyka', 'icon' => 'easel2', 'user' => $me_name, 'logout' => 'logout.php'];
$KP_BODY_CLASS = 'dyd-usos ti-skin';
include dirname(__DIR__) . '/kursant/_layout_head.php';
$_skin_css = __DIR__ . '/../assets/ti_skin.css';
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">
<main id="main" class="dyd-wrap">
<h1 class="h4 mb-3"><i class="bi bi-journal-text me-2 text-primary" aria-hidden="true"></i>Protokoły zajęć</h1>
<?= flash_html() ?>

<?php if (!$courses): ?>
  <div class="alert alert-info">Nie prowadzisz żadnej grupy, więc nie ma dla kogo tworzyć protokołów.</div>
<?php else: ?>

<form method="get" class="row g-2 align-items-end mb-3">
  <div class="col-sm-6 col-md-4">
    <label class="form-label" for="sp-course">Grupa</label>
    <select class="form-select" id="sp-course" name="course" onchange="this.form.submit()">
      <?php foreach ($courses as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= (int)$c['id'] === $sel_course ? 'selected' : '' ?>><?= h($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <div class="col-auto"><button type="submit" class="btn btn-outline-secondary">Pokaż</button></div>
</form>

<section class="card mb-4" aria-labelledby="newMeetingH">
  <div class="card-body">
    <h2 class="h5 card-title" id="newMeetingH">Nowy protokół</h2>
    <form method="post" action="protokoly_zajec.php" class="row g-2 align-items-end">
      <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
      <input type="hidden" name="_op" value="create">
      <input type="hidden" name="course_id" value="<?= $sel_course ?>">
      <div class="col-md-5">
        <label class="form-label" for="sp-title">Tytuł</label>
        <input type="text" class="form-control" id="sp-title" name="title" required minlength="3" maxlength="200">
      </div>
      <div class="col-md-3">
        <label class="form-label" for="sp-period">Okres rozliczeniowy</label>
        <select class="form-select" id="sp-period" name="period_id" required>
          <option value="">— wybierz okres —</option>
          <?php foreach (ti_periods_all() as $pr): ?>
            <option value="<?= (int)$pr['id'] ?>"><?= h($pr['name']) ?> (<?= h($pr['date_from']) ?> – <?= h($pr['date_to']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label" for="sp-date">Data protokołu</label>
        <input type="date" class="form-control" id="sp-date" name="meeting_date" required value="<?= h(date('Y-m-d')) ?>">
      </div>
      <div class="col-md-2">
        <button type="submit" class="btn btn-primary w-100">Utwórz protokół</button>
      </div>
    </form>
  </div>
</section>

<section aria-labelledby="listH">
  <h2 class="h5" id="listH">Protokoły grupy</h2>
  <?php if (!$meetings): ?>
    <p class="text-body-secondary">Brak protokołów spotkań dla tej grupy.</p>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-sm align-middle">
      <caption class="visually-hidden">Protokoły zajęć wybranej grupy</caption>
      <thead><tr><th scope="col">Data</th><th scope="col">Tytuł</th><th scope="col">Punkty</th><th scope="col">Status</th><th scope="col"><span class="visually-hidden">Akcje</span></th></tr></thead>
      <tbody>
      <?php foreach ($meetings as $mt): ?>
        <tr>
          <td><?= h(date('d.m.Y', strtotime((string)$mt['meeting_date']))) ?></td>
          <td><?= h($mt['title']) ?></td>
          <td><?= (int)$mt['items_n'] ?></td>
          <td><?= h(TI_MEETING_STATUS_LABELS[$mt['status']] ?? $mt['status']) ?></td>
          <td class="text-end">
            <a class="btn btn-sm btn-outline-primary" href="protokoly_zajec.php?course=<?= $sel_course ?>&amp;open=<?= (int)$mt['id'] ?>"
               data-dyd-meeting="<?= (int)$mt['id'] ?>">Otwórz protokół<span class="visually-hidden">: <?= h($mt['title']) ?></span></a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</section>

<div class="modal fade" id="meetingModal" tabindex="-1" aria-labelledby="meetingModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title h5" id="meetingModalTitle">Protokół zajęć</h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij okno protokołu"></button>
      </div>
      <div class="modal-body" id="meetingBody" aria-live="polite"></div>
    </div>
  </div>
</div>
<script>
(function () {
  var modalEl = document.getElementById('meetingModal');
  var body    = document.getElementById('meetingBody');
  var trigger = null;
  if (!modalEl || typeof bootstrap === 'undefined') return;

  function openMeeting(id, from) {
    trigger = from || null;
    body.innerHTML = '<p class="text-body-secondary mb-0">Ładowanie protokołu…</p>';
    bootstrap.Modal.getOrCreateInstance(modalEl).show();
    var u = new URL(window.location.href);
    u.search = '';
    u.searchParams.set('frag', '1');
    u.searchParams.set('id', id);
    fetch(u.toString(), { credentials: 'same-origin' })
      .then(function (r) { if (!r.ok && r.status !== 404) throw new Error('HTTP ' + r.status); return r.text(); })
      .then(function (html) { body.innerHTML = html; })
      .catch(function () {
        body.innerHTML = '<div class="alert alert-danger mb-0" role="alert">Nie udało się załadować protokołu. Spróbuj ponownie.</div>';
      });
  }

  document.addEventListener('click', function (e) {
    var a = e.target.closest && e.target.closest('a[data-dyd-meeting]');
    if (!a || e.ctrlKey || e.metaKey || e.shiftKey || e.altKey || (e.button && e.button !== 0)) return;
    e.preventDefault();
    openMeeting(a.getAttribute('data-dyd-meeting'), a);
  });

  modalEl.addEventListener('hidden.bs.modal', function () {
    if (trigger && document.contains(trigger)) trigger.focus();
    trigger = null;
  });

  // Po zapisie (POST → redirect ?open=ID) okno otwiera się samo na tym protokole.
  var openId = new URLSearchParams(window.location.search).get('open');
  if (openId) openMeeting(openId, null);
})();
</script>
<?php endif; ?>
</main>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
