<?php
/**
 * karty30/ti/syllabi.php — Sylabusy przedmiotów TI: tworzenie i edycja programów
 * przedmiotów, wymagań oraz kryteriów oceniania.
 *
 * Wzorzec master-detail (jak curriculum.php / grades.php): lista po lewej,
 * edytor po prawej. Bez modali — pełna obsługa klawiaturą, aria-live dla
 * komunikatów, kolejność zmieniana przyciskami, a nie drag&drop.
 *
 * Sylabus jest WZORCEM przedmiotu; realizacją w kursie pozostaje Plan nauczania
 * (curriculum.php) — stąd akcja „Skopiuj program do planu kursu".
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';
require_once dirname(dirname(__DIR__)) . '/includes/ti_periods.php';
require_once dirname(dirname(__DIR__)) . '/includes/ti_syllabus.php';

k30_require_access();
karty30_migrate();
ti_syllabus_migrate();

$can_write  = can_write('karty30') || is_admin();
$can_delete = is_admin();
$PAGE_TITLE = 'Sylabusy przedmiotów — TI';

$uid = (int)(current_user()['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_write) {
    csrf_check();
    $op  = $_POST['_op'] ?? '';
    $sid = (int)($_POST['syllabus_id'] ?? 0);

    if ($op === 'save_syllabus') {
        $subject = (int)($_POST['subject_type_id'] ?? 0);
        $title   = trim($_POST['title'] ?? '');
        if (!$subject) {
            flash_set('danger', 'Wybierz przedmiot (rodzaj zajęć), dla którego powstaje sylabus.');
            header('Location: syllabi.php' . ($sid ? '?id=' . $sid : '')); exit;
        }
        if ($title === '') {
            flash_set('danger', 'Podaj nazwę sylabusa.');
            header('Location: syllabi.php' . ($sid ? '?id=' . $sid : '')); exit;
        }
        $new_id = ti_syllabus_save([
            'subject_type_id' => $subject,
            'title'           => $title,
            'version'         => $_POST['version'] ?? '1',
            'period_id'       => (int)($_POST['period_id'] ?? 0),
            'status'          => $_POST['status'] ?? 'draft',
            'note'            => $_POST['note'] ?? '',
        ], $sid ?: null, $uid);
        flash_set('success', $sid ? 'Sylabus zaktualizowany.' : 'Sylabus utworzony — dodaj punkty programu, wymagania i kryteria.');
        header('Location: syllabi.php?id=' . $new_id); exit;
    }

    if ($op === 'delete_syllabus') {
        if (!$can_delete) { http_response_code(403); die('Brak uprawnień.'); }
        if ($sid) { ti_syllabus_delete($sid); flash_set('success', 'Sylabus usunięty. Plany nauczania kursów zostały zachowane.'); }
        header('Location: syllabi.php'); exit;
    }

    if ($op === 'clone_syllabus') {
        try {
            $new_id = ti_syllabus_clone($sid, $uid);
            flash_set('success', 'Utworzono nową wersję sylabusa (status: Projekt).');
            header('Location: syllabi.php?id=' . $new_id); exit;
        } catch (\Throwable $e) {
            flash_set('danger', 'Nie udało się utworzyć nowej wersji: ' . $e->getMessage());
            header('Location: syllabi.php?id=' . $sid); exit;
        }
    }

    if ($op === 'item_save') {
        $iid   = (int)($_POST['item_id'] ?? 0);
        $kind  = (string)($_POST['kind'] ?? 'program');
        $title = trim($_POST['title'] ?? '');
        if (!$sid || !ti_syllabus_get($sid)) { flash_set('danger', 'Sylabus nie istnieje.'); header('Location: syllabi.php'); exit; }
        if ($title === '') {
            flash_set('danger', 'Podaj treść pozycji (' . (TI_SYLLABUS_KINDS[$kind]['one'] ?? 'pozycja') . ').');
            header('Location: syllabi.php?id=' . $sid . '&kind=' . urlencode($kind) . '#item-form'); exit;
        }
        ti_syllabus_item_save([
            'syllabus_id' => $sid,
            'kind'        => $kind,
            'section'     => $_POST['section'] ?? '',
            'title'       => $title,
            'description' => $_POST['description'] ?? '',
            'est_minutes' => $_POST['est_minutes'] ?? 0,
            'is_active'   => isset($_POST['is_active']) ? 1 : 0,
        ], $iid ?: null, $uid);
        flash_set('success', $iid ? 'Pozycja zaktualizowana.' : 'Pozycja dodana.');
        header('Location: syllabi.php?id=' . $sid . '&kind=' . urlencode($kind) . '#kind-' . $kind); exit;
    }

    if ($op === 'item_delete') {
        if (!$can_delete) { http_response_code(403); die('Brak uprawnień.'); }
        $iid = (int)($_POST['item_id'] ?? 0);
        if ($iid) { ti_syllabus_item_delete($iid); flash_set('success', 'Pozycja usunięta.'); }
        header('Location: syllabi.php?id=' . $sid); exit;
    }

    if ($op === 'item_move') {
        $iid = (int)($_POST['item_id'] ?? 0);
        ti_syllabus_item_move($iid, ($_POST['dir'] ?? '') === 'up' ? 'up' : 'down');
        header('Location: syllabi.php?id=' . $sid . '#item-' . $iid); exit;
    }

    if ($op === 'assign_courses') {
        $picked = array_map('intval', (array)($_POST['course_ids'] ?? []));
        $all_ids = array_map(fn($c) => (int)$c['id'], k30_ti_courses(false));
        $n_on = 0; $n_off = 0;
        foreach ($all_ids as $cid) {
            $now = (int)(db_one("SELECT syllabus_id FROM k30_ti_courses WHERE id=?", [$cid])['syllabus_id'] ?? 0);
            if (in_array($cid, $picked, true)) {
                if ($now !== $sid) { ti_course_set_syllabus($cid, $sid); $n_on++; }
            } elseif ($now === $sid) {
                ti_course_set_syllabus($cid, 0); $n_off++;
            }
        }
        flash_set('success', "Przypisania zapisane: dodano {$n_on}, odpięto {$n_off}.");
        header('Location: syllabi.php?id=' . $sid . '#kursy'); exit;
    }

    if ($op === 'copy_to_course') {
        $cid = (int)($_POST['course_id'] ?? 0);
        if (!$cid) {
            flash_set('danger', 'Wybierz kurs, do którego skopiować program.');
        } else {
            $r = ti_syllabus_copy_to_curriculum($sid, $cid, $uid);
            $msg = "Do planu nauczania dodano {$r['added']} " . ($r['added'] === 1 ? 'punkt' : 'punktów') . '.';
            if ($r['skipped']) $msg .= " Pominięto {$r['skipped']} już przeniesionych.";
            flash_set($r['added'] ? 'success' : 'info', $msg);
        }
        header('Location: syllabi.php?id=' . $sid . '#kursy'); exit;
    }
}

// ── Dane do widoku ───────────────────────────────────────────────────────────
$id       = (int)($_GET['id'] ?? 0);
$syl      = $id ? ti_syllabus_get($id) : null;
$f_subject = (int)($_GET['subject'] ?? 0);
$f_status  = (string)($_GET['status'] ?? '');
$subjects  = k30_ti_subject_types(false);
$periods   = ti_periods_all();
$list      = ti_syllabi_list($f_subject, isset(TI_SYLLABUS_STATUSES[$f_status]) ? $f_status : '');

// Formularz nagłówka sylabusa
$hf = $syl ?: ['id'=>0,'subject_type_id'=>$f_subject,'title'=>'','version'=>'1','period_id'=>0,'status'=>'draft','note'=>''];

// Formularz pozycji
$kind = (string)($_GET['kind'] ?? 'program');
if (!isset(TI_SYLLABUS_KINDS[$kind])) $kind = 'program';
$edit_item = ti_syllabus_item_get((int)($_GET['edit'] ?? 0));
if ($edit_item && (int)$edit_item['syllabus_id'] !== $id) $edit_item = null;
if ($edit_item) $kind = (string)$edit_item['kind'];
$itf = $edit_item ?: ['id'=>0,'section'=>'','title'=>'','description'=>'','est_minutes'=>0,'is_active'=>1];

$items_by_kind = [];
$sections      = [];
if ($syl) {
    foreach (array_keys(TI_SYLLABUS_KINDS) as $k) $items_by_kind[$k] = ti_syllabus_items($id, $k);
    foreach ($items_by_kind['program'] as $it) {
        $s = trim((string)$it['section']);
        if ($s !== '' && !in_array($s, $sections, true)) $sections[] = $s;
    }
}
$courses        = $syl ? k30_ti_courses(false) : [];
$linked_courses = $syl ? array_map(fn($c) => (int)$c['id'], ti_syllabus_courses($id)) : [];

include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>

<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Dydaktyka 3</a></li>
  <li class="breadcrumb-item"><a href="index.php">Zajęcia TI</a></li>
  <li class="breadcrumb-item"><a href="syllabi.php">Sylabusy</a></li>
  <?php if ($syl): ?><li class="breadcrumb-item active"><?= h($syl['title']) ?></li><?php endif; ?>
</ol></nav>

<div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
  <h4 class="mb-0 fw-bold"><i class="bi bi-journal-text text-primary me-2" aria-hidden="true"></i>Sylabusy przedmiotów</h4>
  <a href="curriculum.php" class="btn btn-outline-secondary btn-sm ms-auto"><i class="bi bi-list-check me-1" aria-hidden="true"></i>Plany nauczania</a>
  <a href="subject_types.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-tags me-1" aria-hidden="true"></i>Rodzaje zajęć</a>
</div>

<?= flash_html() ?>

<?php if (!$syl): ?>
<!-- ══ WIDOK LISTY ══════════════════════════════════════════════════════════ -->
<form method="get" class="card border-0 shadow-sm mb-4">
  <div class="card-body d-flex align-items-end gap-2 flex-wrap">
    <div>
      <label class="form-label fw-semibold mb-1" for="f-subject">Przedmiot</label>
      <select class="form-select" id="f-subject" name="subject" onchange="this.form.submit()" style="min-width:220px">
        <option value="">— wszystkie —</option>
        <?php foreach ($subjects as $st): ?>
        <option value="<?= (int)$st['id'] ?>" <?= $f_subject===(int)$st['id']?'selected':'' ?>><?= h($st['abbreviation'].' — '.$st['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label class="form-label fw-semibold mb-1" for="f-status">Status</label>
      <select class="form-select" id="f-status" name="status" onchange="this.form.submit()">
        <option value="">— wszystkie —</option>
        <?php foreach (TI_SYLLABUS_STATUSES as $k => $v): ?>
        <option value="<?= h($k) ?>" <?= $f_status===$k?'selected':'' ?>><?= h($v['label']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <noscript><button class="btn btn-primary">Filtruj</button></noscript>
  </div>
</form>

<div class="row g-4">
  <div class="col-lg-8">
    <div class="card border-0 shadow-sm">
      <div class="card-header fw-semibold d-flex align-items-center gap-2">
        <span><i class="bi bi-journals me-2" aria-hidden="true"></i>Sylabusy</span>
        <span class="badge bg-secondary"><?= count($list) ?></span>
      </div>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <caption class="visually-hidden">Lista sylabusów przedmiotów z wersją, statusem i liczbą pozycji</caption>
          <thead class="table-light"><tr>
            <th scope="col">Przedmiot</th>
            <th scope="col">Sylabus</th>
            <th scope="col" class="text-nowrap">Wersja</th>
            <th scope="col">Status</th>
            <th scope="col" class="text-nowrap">Pozycje</th>
            <th scope="col" class="text-nowrap">Kursy</th>
            <th scope="col" class="text-end">Akcje</th>
          </tr></thead>
          <tbody>
            <?php if (!$list): ?>
            <tr><td colspan="7" class="text-center text-muted py-3">Brak sylabusów. Utwórz pierwszy w formularzu obok.</td></tr>
            <?php endif; ?>
            <?php foreach ($list as $r): $stt = TI_SYLLABUS_STATUSES[$r['status']] ?? ['label'=>$r['status'],'badge'=>'secondary']; ?>
            <tr>
              <td class="text-nowrap">
                <?php if (!empty($r['subject_abbr'])): ?>
                  <span class="badge bg-primary-subtle text-primary-emphasis border"><?= h($r['subject_abbr']) ?></span>
                  <span class="small text-muted"><?= h($r['subject_name']) ?></span>
                <?php else: ?><span class="text-muted small">bez przedmiotu</span><?php endif; ?>
              </td>
              <td>
                <a href="?id=<?= (int)$r['id'] ?>" class="fw-semibold text-decoration-none"><?= h($r['title'] !== '' ? $r['title'] : 'Sylabus #'.(int)$r['id']) ?></a>
                <?php if (!empty($r['period_name'])): ?><div class="small text-muted"><i class="bi bi-calendar-range me-1" aria-hidden="true"></i><?= h($r['period_name']) ?></div><?php endif; ?>
              </td>
              <td class="text-nowrap">v<?= h($r['version']) ?></td>
              <td><span class="badge text-bg-<?= h($stt['badge']) ?>"><?= h($stt['label']) ?></span></td>
              <td class="small text-nowrap">
                <?= (int)$r['n_program'] ?> prog. · <?= (int)$r['n_requirement'] ?> wym. · <?= (int)$r['n_criterion'] ?> kryt.
              </td>
              <td class="text-nowrap"><?= (int)$r['n_courses'] ? (int)$r['n_courses'] : '<span class="text-muted">—</span>' ?></td>
              <td class="text-end text-nowrap">
                <a href="?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-primary py-0 px-2" aria-label="Otwórz sylabus <?= h($r['title']) ?>"><i class="bi bi-pencil" aria-hidden="true"></i></a>
                <a href="syllabus_pdf.php?id=<?= (int)$r['id'] ?>" class="btn btn-sm btn-outline-secondary py-0 px-2" title="Pobierz PDF" aria-label="Pobierz PDF sylabusa <?= h($r['title']) ?>"><i class="bi bi-file-earmark-pdf" aria-hidden="true"></i></a>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <?php if ($can_write): ?>
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm">
      <div class="card-header fw-semibold"><i class="bi bi-plus-lg me-2" aria-hidden="true"></i>Nowy sylabus</div>
      <div class="card-body">
        <?php include __DIR__ . '/_syllabus_form.php'; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php else: ?>
<!-- ══ WIDOK SYLABUSA ═══════════════════════════════════════════════════════ -->
<?php $stt = TI_SYLLABUS_STATUSES[$syl['status']] ?? ['label'=>$syl['status'],'badge'=>'secondary']; ?>
<div class="row g-4">
  <div class="col-lg-8">
    <!-- Nagłówek sylabusa -->
    <div class="card border-0 shadow-sm mb-4">
      <div class="card-header fw-semibold d-flex align-items-center flex-wrap gap-2">
        <span><i class="bi bi-journal-text me-2" aria-hidden="true"></i><?= h($syl['title']) ?></span>
        <span class="badge text-bg-<?= h($stt['badge']) ?>"><?= h($stt['label']) ?></span>
        <span class="badge bg-light text-dark border">v<?= h($syl['version']) ?></span>
        <div class="ms-auto d-flex gap-2">
          <a href="syllabus_pdf.php?id=<?= $id ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>PDF</a>
          <?php if ($can_write): ?>
          <form method="post" onsubmit="return confirm('Utworzyć nową wersję tego sylabusa (kopia wszystkich pozycji)?')">
            <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="_op" value="clone_syllabus">
            <input type="hidden" name="syllabus_id" value="<?= $id ?>">
            <button class="btn btn-sm btn-outline-primary"><i class="bi bi-files me-1" aria-hidden="true"></i>Nowa wersja</button>
          </form>
          <?php endif; ?>
          <?php if ($can_delete): ?>
          <form method="post" onsubmit="return confirm('Usunąć sylabus „<?= h(addslashes($syl['title'])) ?>” wraz z pozycjami? Plany nauczania kursów zostaną zachowane.')">
            <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="_op" value="delete_syllabus">
            <input type="hidden" name="syllabus_id" value="<?= $id ?>">
            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash me-1" aria-hidden="true"></i>Usuń</button>
          </form>
          <?php endif; ?>
        </div>
      </div>
      <div class="card-body">
        <dl class="row mb-0 small">
          <dt class="col-sm-3">Przedmiot</dt>
          <dd class="col-sm-9"><?= h(trim(($syl['subject_abbr'] ?? '') . ' — ' . ($syl['subject_name'] ?? ''), ' —')) ?: '<span class="text-muted">nie wskazano</span>' ?></dd>
          <?php if (!empty($syl['period_name'])): ?>
          <dt class="col-sm-3">Okres nauczania</dt><dd class="col-sm-9"><?= h($syl['period_name']) ?></dd>
          <?php endif; ?>
          <?php if (trim((string)$syl['note']) !== ''): ?>
          <dt class="col-sm-3">Uwagi</dt><dd class="col-sm-9"><?= nl2br(h($syl['note'])) ?></dd>
          <?php endif; ?>
        </dl>
      </div>
    </div>

    <!-- Sekcje: program / wymagania / kryteria -->
    <?php foreach (TI_SYLLABUS_KINDS as $k => $meta): $rows = $items_by_kind[$k]; ?>
    <div class="card border-0 shadow-sm mb-4" id="kind-<?= h($k) ?>">
      <div class="card-header fw-semibold d-flex align-items-center flex-wrap gap-2">
        <span><i class="bi <?= h($meta['icon']) ?> me-2" aria-hidden="true"></i><?= h($meta['label']) ?></span>
        <span class="badge bg-secondary"><?= count($rows) ?></span>
        <?php if ($k === 'program'): $tm = array_sum(array_map(fn($r)=>(int)$r['est_minutes'], $rows)); ?>
          <?php if ($tm > 0): ?><span class="badge bg-light text-dark border">≈ <?= round($tm/60, 1) ?> h</span><?php endif; ?>
        <?php endif; ?>
        <?php if ($can_write): ?>
        <a href="?id=<?= $id ?>&kind=<?= h($k) ?>#item-form" class="btn btn-sm btn-outline-primary ms-auto">
          <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Dodaj <?= h($meta['one']) ?>
        </a>
        <?php endif; ?>
      </div>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <caption class="visually-hidden"><?= h($meta['label']) ?> w sylabusie <?= h($syl['title']) ?></caption>
          <thead class="table-light"><tr>
            <th scope="col" style="width:2.5rem">#</th>
            <th scope="col">Treść</th>
            <?php if ($meta['time']): ?><th scope="col" class="text-nowrap">Czas</th><?php endif; ?>
            <?php if ($can_write): ?><th scope="col" class="text-end">Akcje</th><?php endif; ?>
          </tr></thead>
          <tbody>
            <?php if (!$rows): ?>
            <tr><td colspan="4" class="text-center text-muted py-3">Brak pozycji — dodaj pierwszą.</td></tr>
            <?php endif; ?>
            <?php $last = null; $n = 0; foreach ($rows as $idx => $it): ?>
              <?php if ($meta['time']): $sec = (string)$it['section']; if ($sec !== $last): $last = $sec; ?>
              <tr class="table-light"><th colspan="4" scope="colgroup" class="small text-uppercase fw-bold text-secondary py-1">
                <i class="bi bi-folder2 me-1" aria-hidden="true"></i><?= $sec !== '' ? h($sec) : 'Bez działu' ?>
              </th></tr>
              <?php endif; endif; $n++; ?>
            <tr id="item-<?= (int)$it['id'] ?>" <?= !$it['is_active'] ? 'class="text-muted"' : '' ?>>
              <td class="text-muted small"><?= $n ?></td>
              <td>
                <span class="fw-semibold"><?= h($it['title']) ?></span>
                <?php if (!$it['is_active']): ?><span class="badge bg-secondary ms-1">ukryta</span><?php endif; ?>
                <?php if (trim((string)$it['description']) !== ''): ?>
                <div class="small text-muted"><?= nl2br(h(mb_strimwidth((string)$it['description'], 0, 200, '…', 'UTF-8'))) ?></div>
                <?php endif; ?>
              </td>
              <?php if ($meta['time']): ?>
              <td class="text-nowrap small"><?= (int)$it['est_minutes'] > 0 ? (int)$it['est_minutes'].' min' : '<span class="text-muted">—</span>' ?></td>
              <?php endif; ?>
              <?php if ($can_write): ?>
              <td class="text-end text-nowrap">
                <form method="post" class="d-inline">
                  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="_op" value="item_move">
                  <input type="hidden" name="syllabus_id" value="<?= $id ?>">
                  <input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
                  <button name="dir" value="up" class="btn btn-sm btn-outline-secondary py-0 px-2" title="Przesuń wyżej" aria-label="Przesuń wyżej: <?= h($it['title']) ?>" <?= $idx===0?'disabled':'' ?>><i class="bi bi-arrow-up" aria-hidden="true"></i></button>
                  <button name="dir" value="down" class="btn btn-sm btn-outline-secondary py-0 px-2" title="Przesuń niżej" aria-label="Przesuń niżej: <?= h($it['title']) ?>" <?= $idx===count($rows)-1?'disabled':'' ?>><i class="bi bi-arrow-down" aria-hidden="true"></i></button>
                </form>
                <a href="?id=<?= $id ?>&edit=<?= (int)$it['id'] ?>#item-form" class="btn btn-sm btn-outline-primary py-0 px-2" title="Edytuj" aria-label="Edytuj: <?= h($it['title']) ?>"><i class="bi bi-pencil" aria-hidden="true"></i></a>
                <?php if ($can_delete): ?>
                <form method="post" class="d-inline" onsubmit="return confirm('Usunąć pozycję „<?= h(addslashes($it['title'])) ?>”?')">
                  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="_op" value="item_delete">
                  <input type="hidden" name="syllabus_id" value="<?= $id ?>">
                  <input type="hidden" name="item_id" value="<?= (int)$it['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger py-0 px-2" title="Usuń" aria-label="Usuń: <?= h($it['title']) ?>"><i class="bi bi-trash" aria-hidden="true"></i></button>
                </form>
                <?php endif; ?>
              </td>
              <?php endif; ?>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endforeach; ?>

    <!-- Kursy korzystające z sylabusa -->
    <div class="card border-0 shadow-sm" id="kursy">
      <div class="card-header fw-semibold"><i class="bi bi-mortarboard me-2" aria-hidden="true"></i>Kursy korzystające z sylabusa</div>
      <div class="card-body">
        <p class="small text-body-secondary">
          Kurs bez własnego przypisania dziedziczy <em>obowiązujący</em> sylabus swojego rodzaju zajęć.
          Program sylabusa staje się planem nauczania kursu po skopiowaniu — plan dalej łączy się z lekcjami.
        </p>
        <?php if ($can_write): ?>
        <form method="post" class="mb-3">
          <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_op" value="assign_courses">
          <input type="hidden" name="syllabus_id" value="<?= $id ?>">
          <fieldset>
            <legend class="form-label fw-semibold">Przypisz kursy</legend>
            <div class="row row-cols-1 row-cols-md-2 g-1 mb-2" style="max-height:220px;overflow:auto">
              <?php foreach ($courses as $c): $cid=(int)$c['id']; ?>
              <div class="col">
                <div class="form-check">
                  <input class="form-check-input" type="checkbox" name="course_ids[]" value="<?= $cid ?>" id="c-<?= $cid ?>" <?= in_array($cid, $linked_courses, true) ? 'checked' : '' ?>>
                  <label class="form-check-label small" for="c-<?= $cid ?>">
                    <?= h($c['name']) ?>
                    <?php if (!empty($c['subject_abbr'])): ?><span class="text-muted">(<?= h($c['subject_abbr']) ?>)</span><?php endif; ?>
                  </label>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
          </fieldset>
          <button class="btn btn-primary btn-sm"><i class="bi bi-link-45deg me-1" aria-hidden="true"></i>Zapisz przypisania</button>
        </form>

        <form method="post" class="d-flex align-items-end gap-2 flex-wrap border-top pt-3">
          <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_op" value="copy_to_course">
          <input type="hidden" name="syllabus_id" value="<?= $id ?>">
          <div>
            <label class="form-label fw-semibold mb-1" for="copy-course">Skopiuj program do planu nauczania kursu</label>
            <select class="form-select form-select-sm" id="copy-course" name="course_id" style="min-width:260px">
              <option value="">— wybierz kurs —</option>
              <?php foreach ($courses as $c): ?>
              <option value="<?= (int)$c['id'] ?>"><?= h($c['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <button class="btn btn-outline-primary btn-sm"><i class="bi bi-box-arrow-down me-1" aria-hidden="true"></i>Skopiuj program</button>
          <div class="form-text w-100 mb-0">Punkty już przeniesione są pomijane — ponowne uruchomienie dokłada tylko nowości z sylabusa.</div>
        </form>
        <?php endif; ?>

        <?php if ($linked_courses): ?>
        <ul class="list-unstyled small mb-0 mt-3">
          <?php foreach (ti_syllabus_courses($id) as $lc): $cov = ti_syllabus_coverage($id, (int)$lc['id']); ?>
          <li class="d-flex align-items-center gap-2 py-1 border-bottom">
            <a href="curriculum.php?course=<?= (int)$lc['id'] ?>" class="text-decoration-none"><?= h($lc['name']) ?></a>
            <span class="badge <?= $cov['pct'] >= 100 ? 'text-bg-success' : 'text-bg-light border text-dark' ?> ms-auto">
              plan: <?= (int)$cov['covered'] ?>/<?= (int)$cov['total'] ?> punktów
            </span>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- DETAIL: edytor pozycji + nagłówek -->
  <?php if ($can_write): ?>
  <div class="col-lg-4">
    <div class="card border-0 shadow-sm" id="item-form">
      <div class="card-header fw-semibold">
        <i class="bi bi-<?= $edit_item ? 'pencil' : 'plus-lg' ?> me-2" aria-hidden="true"></i>
        <?= $edit_item ? 'Edytuj pozycję' : 'Nowa pozycja' ?>
      </div>
      <div class="card-body">
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="_op" value="item_save">
          <input type="hidden" name="syllabus_id" value="<?= $id ?>">
          <input type="hidden" name="item_id" value="<?= (int)$itf['id'] ?>">
          <div class="mb-2">
            <label class="form-label fw-semibold" for="i-kind">Rodzaj pozycji</label>
            <select class="form-select" id="i-kind" name="kind" <?= $edit_item ? 'disabled' : '' ?>>
              <?php foreach (TI_SYLLABUS_KINDS as $k => $meta): ?>
              <option value="<?= h($k) ?>" <?= $kind===$k?'selected':'' ?>><?= h($meta['label']) ?></option>
              <?php endforeach; ?>
            </select>
            <?php if ($edit_item): ?>
              <input type="hidden" name="kind" value="<?= h($kind) ?>">
              <div class="form-text">Rodzaju istniejącej pozycji nie zmienia się — usuń i dodaj w innej sekcji.</div>
            <?php endif; ?>
          </div>
          <div class="mb-2" id="i-section-wrap">
            <label class="form-label fw-semibold" for="i-section">Dział / moduł <span class="text-muted small">(tylko program)</span></label>
            <input type="text" class="form-control" id="i-section" name="section" list="sec-list" value="<?= h($itf['section']) ?>" maxlength="120" placeholder="np. Podstawy systemu">
            <datalist id="sec-list">
              <?php foreach ($sections as $s): ?><option value="<?= h($s) ?>"></option><?php endforeach; ?>
            </datalist>
          </div>
          <div class="mb-2">
            <label class="form-label fw-semibold" for="i-title">Treść <span class="text-danger" aria-hidden="true">*</span></label>
            <input type="text" class="form-control" id="i-title" name="title" value="<?= h($itf['title']) ?>" required maxlength="255"
                   placeholder="np. Kursant samodzielnie obsługuje czytnik ekranu">
          </div>
          <div class="mb-2">
            <label class="form-label" for="i-desc">Opis / uszczegółowienie</label>
            <textarea class="form-control" id="i-desc" name="description" rows="3" placeholder="np. warunki uzyskania oceny, zakres wymagania, efekty uczenia się"><?= h($itf['description']) ?></textarea>
          </div>
          <div class="mb-2" id="i-min-wrap">
            <label class="form-label" for="i-min">Szacowany czas (min) <span class="text-muted small">(tylko program)</span></label>
            <input type="number" class="form-control" id="i-min" name="est_minutes" min="0" max="100000" step="5" value="<?= (int)$itf['est_minutes'] ?: '' ?>" placeholder="np. 90">
          </div>
          <div class="form-check form-switch mb-3">
            <input class="form-check-input" type="checkbox" role="switch" name="is_active" id="i-active" value="1" <?= $itf['is_active'] ? 'checked' : '' ?>>
            <label class="form-check-label" for="i-active">Pozycja aktywna (widoczna w sylabusie i wydruku)</label>
          </div>
          <div class="d-flex gap-2">
            <button class="btn btn-primary"><?= $edit_item ? 'Zapisz zmiany' : 'Dodaj pozycję' ?></button>
            <?php if ($edit_item): ?><a href="?id=<?= $id ?>#item-form" class="btn btn-outline-secondary">Anuluj</a><?php endif; ?>
          </div>
        </form>
      </div>
    </div>

    <div class="card border-0 shadow-sm mt-4">
      <div class="card-header fw-semibold"><i class="bi bi-sliders me-2" aria-hidden="true"></i>Dane sylabusa</div>
      <div class="card-body">
        <?php include __DIR__ . '/_syllabus_form.php'; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<script>
// Dział i czas dotyczą tylko programu — chowamy je dla wymagań i kryteriów.
(function(){
  var kind = document.getElementById('i-kind');
  if (!kind) return;
  var sec = document.getElementById('i-section-wrap');
  var min = document.getElementById('i-min-wrap');
  function sync(){
    var isProgram = kind.value === 'program';
    if (sec) sec.hidden = !isProgram;
    if (min) min.hidden = !isProgram;
  }
  kind.addEventListener('change', sync);
  sync();
})();
</script>
<?php if ($edit_item): ?>
<script>(function(){ var t = document.getElementById('i-title'); if (t) t.focus(); })();</script>
<?php endif; ?>

<?php endif; ?>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
