<?php
/**
 * karty30/ti/periods.php — Okresy nauczania TI.
 *
 * 1) Zarządzanie okresami: kwartał (3 mies.), rok szkolny (10 mies.), wakacje.
 * 2) Narzędzie masowe: kopiowanie / przenoszenie zajęć między okresami
 *    (dla wielu kursów naraz, z podglądem przed zatwierdzeniem).
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';
require_once dirname(dirname(__DIR__)) . '/includes/ti_periods.php';

k30_require_access();
karty30_migrate();
ti_periods_migrate();

$can_write = can_write('karty30') || is_admin();

/** Buduje plan kopiowania/przenoszenia (bez zapisu do bazy). */
function ti_periods_build_plan(array $src, array $dst, array $course_ids, bool $skip_off): array {
    $sessions = ti_period_sessions($src, $course_ids);
    $plan = [];
    foreach ($sessions as $s) {
        $new_date = ti_period_map_date($s['lesson_date'], $src, $dst, $skip_off);
        $dup = db_one(
            "SELECT id FROM k30_ti_sessions WHERE course_id=? AND lesson_date=? AND time_from=? AND (id!=?)",
            [(int)$s['course_id'], $new_date, (string)$s['time_from'], (int)$s['id']]
        );
        // Zajętość konta Zoom w nowym terminie (jeden host = jedno spotkanie naraz)
        $zc = ti_zoom_slot_check(
            (int)$s['course_id'], (string)($s['lesson_method'] ?? ''),
            $new_date, (string)$s['time_from'], (string)$s['time_to'], (int)$s['id']
        );
        $plan[] = [
            'src'          => $s,
            'new_date'     => $new_date,
            'in_dst_range' => ($new_date >= $dst['date_from'] && $new_date <= $dst['date_to']),
            'duplicate'    => (bool)$dup,
            'zoom_busy'    => $zc['ok'] ? '' : $zc['reason'],
        ];
    }
    return $plan;
}

$preview = null;   // ['src','dst','courses','mode','skip_off','plan']
$form_err = [];

// ── Akcje ─────────────────────────────────────────────────────────────────────
$op = $_POST['_op'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $can_write) {
    csrf_check();
    $uid = (int)(current_user()['id'] ?? 0);

    if ($op === 'save') {
        $id   = (int)($_POST['id'] ?? 0);
        $df   = trim($_POST['date_from'] ?? '');
        $dt   = trim($_POST['date_to']   ?? '');
        $name = trim($_POST['name']      ?? '');
        $type = array_key_exists($_POST['type'] ?? '', TI_PERIOD_TYPES) ? $_POST['type'] : 'quarter';
        $note = trim($_POST['note'] ?? '');

        if (!$df || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $df)) $form_err[] = 'Podaj datę od.';
        if (!$dt || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dt)) $form_err[] = 'Podaj datę do.';
        if ($df && $dt && $dt < $df) $form_err[] = 'Data „do" musi być ≥ dacie „od".';
        if (!$name) $form_err[] = 'Podaj nazwę okresu.';

        if (!$form_err) {
            if ($id) {
                db()->prepare("UPDATE k30_ti_periods SET name=?, type=?, date_from=?, date_to=?, note=?, updated_at=datetime('now') WHERE id=?")
                    ->execute([$name, $type, $df, $dt, $note, $id]);
                flash_set('success', 'Zaktualizowano okres.');
            } else {
                db_insert('k30_ti_periods', ['name'=>$name,'type'=>$type,'date_from'=>$df,'date_to'=>$dt,'note'=>$note,'created_by'=>$uid]);
                flash_set('success', 'Dodano okres nauczania.');
            }
            header('Location: periods.php'); exit;
        }
    } elseif ($op === 'close_period') {
        $id = (int)($_POST['id'] ?? 0);
        try {
            ti_period_close($id, $uid, (string)(current_user()['name'] ?? ''), (string)($_POST['close_note'] ?? ''));
            flash_set('success', 'Okres zamknięty — zajęć w nim nie można już dodawać ani przesuwać.');
        } catch (\Throwable $e) {
            flash_set('danger', $e->getMessage());
        }
        header('Location: periods.php?ready=' . $id . '#zamykanie'); exit;

    } elseif ($op === 'reopen_period') {
        if (!is_admin()) { http_response_code(403); die('Okres może otworzyć ponownie tylko administrator.'); }
        $id = (int)($_POST['id'] ?? 0);
        try {
            ti_period_reopen($id, $uid, (string)(current_user()['name'] ?? ''), (string)($_POST['reason'] ?? ''));
            flash_set('success', 'Okres otwarty ponownie — powód zapisany.');
        } catch (\Throwable $e) {
            flash_set('danger', $e->getMessage());
        }
        header('Location: periods.php?ready=' . $id . '#zamykanie'); exit;

    } elseif ($op === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id) db()->prepare("DELETE FROM k30_ti_periods WHERE id=?")->execute([$id]);
        flash_set('success', 'Usunięto okres (lekcje nie zostały naruszone).');
        header('Location: periods.php'); exit;

    } elseif ($op === 'preview' || $op === 'apply') {
        $src_id     = (int)($_POST['src_id'] ?? 0);
        $dst_id     = (int)($_POST['dst_id'] ?? 0);
        $mode       = ($_POST['mode'] ?? 'copy') === 'move' ? 'move' : 'copy';
        $skip_off   = !empty($_POST['skip_off']);
        $course_ids = array_map('intval', (array)($_POST['course_ids'] ?? []));
        $src = $src_id ? ti_period_get($src_id) : null;
        $dst = $dst_id ? ti_period_get($dst_id) : null;

        if (!$src || !$dst)      $form_err[] = 'Wybierz okres źródłowy i docelowy.';
        elseif ($src_id === $dst_id) $form_err[] = 'Okres źródłowy i docelowy muszą być różne.';

        // Zamknięty okres jest rozliczony protokołami — nic do niego ani z niego
        if ($dst && ti_period_is_closed($dst)) {
            $form_err[] = 'Okres docelowy „' . $dst['name'] . '" jest zamknięty — nie można do niego kopiować ani przenosić zajęć.';
        }
        if ($mode === 'move' && $src && ti_period_is_closed($src)) {
            $form_err[] = 'Okres źródłowy „' . $src['name'] . '" jest zamknięty — zajęć z niego nie można przenosić.';
        }

        if (!$form_err) {
            $plan = ti_periods_build_plan($src, $dst, $course_ids, $skip_off);
            if (!$plan) {
                $form_err[] = 'W okresie źródłowym nie ma lekcji dla wybranych kursów.';
            } elseif ($op === 'apply') {
                $copied = 0; $moved = 0; $zoom_skipped = 0;
                db()->beginTransaction();
                try {
                    foreach ($plan as $p) {
                        $s = $p['src'];
                        // Kolizja z zajętością konta Zoom — lekcji nie da się odbyć,
                        // więc jej nie tworzymy/nie przenosimy (stan sprawdzany na świeżo,
                        // bo wcześniejsze przeniesienia w tej pętli zmieniają zajętość).
                        $zc = ti_zoom_slot_check(
                            (int)$s['course_id'], (string)($s['lesson_method'] ?? ''),
                            $p['new_date'], (string)$s['time_from'], (string)$s['time_to'], (int)$s['id']
                        );
                        if (!$zc['ok']) { $zoom_skipped++; continue; }
                        if ($mode === 'move') {
                            db()->prepare("UPDATE k30_ti_sessions SET lesson_date=?, updated_at=datetime('now') WHERE id=?")
                                ->execute([$p['new_date'], (int)$s['id']]);
                            $moved++;
                        } else {
                            $new_id = db_insert('k30_ti_sessions', [
                                'course_id'        => (int)$s['course_id'],
                                'lesson_date'      => $p['new_date'],
                                'time_from'        => $s['time_from'],
                                'time_to'          => $s['time_to'],
                                'duration_min'     => (int)$s['duration_min'],
                                'status'           => 'planned',
                                'notes'            => $s['notes'] ?? '',
                                'topic'            => $s['topic'] ?? '',
                                'instructor_notes' => $s['instructor_notes'] ?? '',
                                'has_homework'     => (int)($s['has_homework'] ?? 0),
                                'self_prep_remote' => (int)($s['self_prep_remote'] ?? 0),
                                'meeting_url'      => $s['meeting_url'] ?? '',
                                'created_by'       => $uid,
                                'created_at'       => date('Y-m-d H:i:s'),
                            ]);
                            // Obecność dla aktywnych uczestników (bez statusu — nowa lekcja)
                            $enr = db_all("SELECT client_id FROM k30_ti_enrollments WHERE course_id=? AND status='active'", [(int)$s['course_id']]);
                            foreach ($enr as $e) {
                                try { db_insert('k30_ti_attendance', ['session_id'=>$new_id,'client_id'=>(int)$e['client_id'],'attended'=>0]); }
                                catch (\Throwable $ex) {}
                            }
                            $copied++;
                        }
                    }
                    db()->commit();
                } catch (\Throwable $e) {
                    db()->rollBack();
                    flash_set('danger', 'Operacja nie powiodła się: ' . $e->getMessage());
                    header('Location: periods.php'); exit;
                }
                $dn = $dst['name'];
                $zmsg = $zoom_skipped
                    ? " Pominięto {$zoom_skipped} lekcji — konto Zoom jest w tych terminach zajęte."
                    : '';
                flash_set($zoom_skipped ? 'warning' : 'success', ($mode === 'move'
                    ? "Przeniesiono {$moved} lekcji do okresu „{$dn}”."
                    : "Skopiowano {$copied} lekcji do okresu „{$dn}”.") . $zmsg
                );
                header('Location: periods.php'); exit;
            } else {
                $preview = compact('src','dst','course_ids','mode','skip_off','plan');
            }
        }
    }
}

// ── Dane ────────────────────────────────────────────────────────────────────
$periods = ti_periods_all();
$courses = k30_ti_courses(false); // wszystkie nieusunięte kursy
$current_vac = ti_current_vacation();

// Gotowość okresu do zamknięcia (wybór z listy przez ?ready=)
$ready_id     = (int)($_GET['ready'] ?? 0);
$ready_period = $ready_id ? ti_period_get($ready_id) : null;
$readiness    = $ready_period ? ti_period_close_readiness($ready_id) : ['ready'=>false,'courses'=>[],'missing'=>0];

$PAGE_TITLE = 'Okresy nauczania TI';
require_once dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<div class="container-fluid py-3" style="max-width:1100px">
<?= flash_html() ?>

<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb mb-0">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Dydaktyka 3</a></li>
  <li class="breadcrumb-item"><a href="index.php">Zajęcia TI</a></li>
  <li class="breadcrumb-item active" aria-current="page">Okresy nauczania</li>
</ol></nav>

<?php if ($form_err): ?>
<div class="alert alert-danger"><ul class="mb-0"><?php foreach ($form_err as $e): ?><li><?= h($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<?php if ($current_vac): ?>
<div class="alert alert-warning d-flex align-items-center gap-2">
  <i class="bi bi-sun-fill fs-5" aria-hidden="true"></i>
  <div><strong>Trwa okres wakacyjny:</strong> <?= h($current_vac['name']) ?>
    (do <?= h(date('d.m.Y', strtotime($current_vac['date_to']))) ?>). Kursanci i dydaktycy widzą stosowny komunikat w panelach.</div>
</div>
<?php endif; ?>

<div class="d-flex flex-wrap align-items-center gap-2 mb-3">
  <h1 class="h4 mb-0"><i class="bi bi-calendar-range me-2 text-primary"></i>Okresy nauczania TI</h1>
  <?php if ($can_write): ?>
  <button class="btn btn-primary btn-sm ms-auto" data-bs-toggle="modal" data-bs-target="#periodModal" id="btnAddPeriod">
    <i class="bi bi-plus-lg me-1"></i>Dodaj okres
  </button>
  <?php endif; ?>
</div>

<!-- ── Lista okresów ─────────────────────────────────────────────────────────── -->
<div class="card border-0 shadow-sm mb-4">
  <div class="card-header fw-semibold bg-body-tertiary"><i class="bi bi-list-ul me-1"></i>Zdefiniowane okresy</div>
  <?php if ($periods): ?>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <thead class="table-light">
        <tr><th>Nazwa</th><th>Typ</th><th>Od</th><th>Do</th><th class="text-center">Dni</th><th>Stan</th><th>Uwagi</th><?php if ($can_write): ?><th class="text-end">Akcje</th><?php endif; ?></tr>
      </thead>
      <tbody>
      <?php foreach ($periods as $p):
        $m = ti_period_type_meta($p['type']);
        $is_now = $p['date_from'] <= date('Y-m-d') && $p['date_to'] >= date('Y-m-d');
      ?>
      <tr class="<?= $is_now ? 'table-warning' : '' ?>">
        <td class="fw-semibold"><?= h($p['name']) ?><?= $is_now ? ' <span class="badge text-bg-warning ms-1">Teraz</span>' : '' ?></td>
        <td><span class="badge" style="background:<?= h($m['bg']) ?>;color:<?= h($m['color']) ?>;border:1px solid <?= h($m['color']) ?>44"><i class="<?= h($m['icon']) ?> me-1"></i><?= h($m['short']) ?></span></td>
        <td class="text-nowrap"><?= h(date('d.m.Y', strtotime($p['date_from']))) ?></td>
        <td class="text-nowrap"><?= h(date('d.m.Y', strtotime($p['date_to']))) ?></td>
        <td class="text-center"><?= ti_period_days($p) ?></td>
        <td class="text-nowrap small">
          <?php if (ti_period_is_closed($p)): ?>
            <span class="badge text-bg-dark" title="Zamknięty: <?= h($p['closed_name'] ?: '') ?>, <?= h(date('d.m.Y H:i', strtotime((string)$p['closed_at']))) ?>">zamknięty</span>
          <?php else: ?>
            <a href="?ready=<?= (int)$p['id'] ?>#zamykanie" class="text-decoration-none"><span class="badge text-bg-light border text-dark">otwarty</span></a>
          <?php endif; ?>
        </td>
        <td class="text-body-secondary small"><?= $p['note'] !== '' ? h($p['note']) : '—' ?></td>
        <?php if ($can_write): ?>
        <td class="text-end text-nowrap">
          <button class="btn btn-sm btn-outline-secondary py-0 px-2" onclick='periodEdit(<?= json_encode($p, JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_TAG|JSON_HEX_AMP) ?>)'><i class="bi bi-pencil"></i></button>
          <form method="post" class="d-inline" onsubmit="return confirm('Usunąć ten okres? Lekcje pozostaną bez zmian.')">
            <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="_op" value="delete">
            <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
            <button class="btn btn-sm btn-outline-danger py-0 px-2"><i class="bi bi-trash"></i></button>
          </form>
        </td>
        <?php endif; ?>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php else: ?>
  <div class="card-body text-body-secondary"><i class="bi bi-info-circle me-1"></i>Brak zdefiniowanych okresów. <?php if ($can_write): ?>Kliknij „Dodaj okres".<?php endif; ?></div>
  <?php endif; ?>
</div>

<!-- ── Zamykanie okresu (wymaga zatwierdzonych protokołów) ───────────────────── -->
<?php if ($can_write): ?>
<div class="card border-0 shadow-sm mb-4" id="zamykanie">
  <div class="card-header fw-semibold bg-body-tertiary"><i class="bi bi-lock me-1"></i>Zamykanie okresu</div>
  <div class="card-body">
    <p class="small text-body-secondary">
      Okres można zamknąć dopiero wtedy, gdy <strong>każdy kurs, który miał w nim zajęcia,
      ma zatwierdzony protokół zajęć za ten okres</strong> (panel dydaktyka → Protokoły). Zamknięty okres
      jest rozliczony: nie da się w nim dodawać ani przesuwać zajęć, a protokołów nie można
      odblokować, dopóki administrator nie otworzy okresu ponownie.
    </p>

    <form method="get" class="d-flex align-items-end gap-2 flex-wrap mb-3">
      <div>
        <label class="form-label fw-semibold mb-1" for="ready-period">Okres</label>
        <select class="form-select form-select-sm" id="ready-period" name="ready" onchange="this.form.submit()" style="min-width:280px">
          <option value="">— wybierz okres —</option>
          <?php foreach ($periods as $p): ?>
          <option value="<?= (int)$p['id'] ?>" <?= $ready_id === (int)$p['id'] ? 'selected' : '' ?>>
            <?= h($p['name']) ?> (<?= h(date('d.m.Y', strtotime($p['date_from']))) ?>–<?= h(date('d.m.Y', strtotime($p['date_to']))) ?>)<?= !empty($p['closed_at']) ? ' — zamknięty' : '' ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <noscript><button class="btn btn-sm btn-primary">Sprawdź</button></noscript>
    </form>

    <?php if ($ready_period): ?>
      <?php $closed = ti_period_is_closed($ready_period); ?>
      <div class="d-flex align-items-center gap-2 flex-wrap mb-2">
        <span class="fw-semibold"><?= h($ready_period['name']) ?></span>
        <?php if ($closed): ?>
          <span class="badge text-bg-dark">zamknięty</span>
          <span class="small text-body-secondary">
            <?= h($ready_period['closed_name'] ?: '—') ?>,
            <?= h(date('d.m.Y H:i', strtotime((string)$ready_period['closed_at']))) ?>
            <?= trim((string)$ready_period['close_note']) !== '' ? '— ' . h($ready_period['close_note']) : '' ?>
          </span>
        <?php elseif ($readiness['ready']): ?>
          <span class="badge text-bg-success">gotowy do zamknięcia</span>
        <?php else: ?>
          <span class="badge text-bg-warning">brakuje protokołów: <?= (int)$readiness['missing'] ?></span>
        <?php endif; ?>
      </div>

      <?php if (!empty($ready_period['reopened_at'])): ?>
      <div class="small text-body-secondary mb-2">
        <i class="bi bi-unlock me-1"></i>Otwarty ponownie: <?= h($ready_period['reopened_name'] ?: '—') ?>,
        <?= h(date('d.m.Y H:i', strtotime((string)$ready_period['reopened_at']))) ?> — powód: <?= h($ready_period['reopen_reason']) ?>.
      </div>
      <?php endif; ?>

      <div class="table-responsive mb-3">
        <table class="table table-sm align-middle mb-0">
          <caption class="visually-hidden">Kursy z zajęciami w okresie i stan ich protokołów ocen</caption>
          <thead class="table-light"><tr>
            <th scope="col">Kurs</th><th scope="col" class="text-center">Zajęć w okresie</th><th scope="col">Protokół</th>
          </tr></thead>
          <tbody>
            <?php if (!$readiness['courses']): ?>
            <tr><td colspan="3" class="text-center text-muted py-3">W tym okresie żaden kurs nie ma zajęć — nie ma czego rozliczać.</td></tr>
            <?php endif; ?>
            <?php foreach ($readiness['courses'] as $rc): ?>
            <tr>
              <td><a href="course.php?id=<?= (int)$rc['course_id'] ?>"><?= h($rc['name']) ?></a></td>
              <td class="text-center"><?= (int)$rc['sessions'] ?></td>
              <td>
                <?php if ($rc['status'] === 'approved'): ?>
                  <span class="badge text-bg-success">zatwierdzony</span>
                <?php elseif ($rc['status'] === 'open'): ?>
                  <span class="badge text-bg-warning">otwarty — do zatwierdzenia</span>
                <?php else: ?>
                  <span class="badge text-bg-danger">brak protokołu</span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if (!$closed): ?>
      <form method="post" class="d-flex align-items-end gap-2 flex-wrap"
            onsubmit="return confirm('Zamknąć okres „<?= h(addslashes($ready_period['name'])) ?>”? W zamkniętym okresie nie można dodawać ani przesuwać zajęć.')">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="_op" value="close_period">
        <input type="hidden" name="id" value="<?= (int)$ready_period['id'] ?>">
        <div class="flex-grow-1">
          <label class="form-label mb-1" for="close-note">Notatka do zamknięcia <span class="text-body-secondary">(opcjonalnie)</span></label>
          <input type="text" class="form-control form-control-sm" id="close-note" name="close_note" maxlength="255" placeholder="np. rok rozliczony, protokoły podpisane">
        </div>
        <button class="btn btn-sm btn-primary" <?= $readiness['ready'] ? '' : 'disabled aria-disabled="true"' ?>>
          <i class="bi bi-lock me-1"></i>Zamknij okres
        </button>
      </form>
      <?php if (!$readiness['ready']): ?>
      <div class="form-text">Przycisk odblokuje się, gdy wszystkie kursy z tego okresu będą miały zatwierdzone protokoły.</div>
      <?php endif; ?>
      <?php elseif (is_admin()): ?>
      <form method="post" class="d-flex align-items-end gap-2 flex-wrap"
            onsubmit="return confirm('Otworzyć okres ponownie? Powód zostanie zapisany.')">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="_op" value="reopen_period">
        <input type="hidden" name="id" value="<?= (int)$ready_period['id'] ?>">
        <div class="flex-grow-1">
          <label class="form-label mb-1" for="reopen-reason">Powód ponownego otwarcia <span class="text-danger" aria-hidden="true">*</span></label>
          <input type="text" class="form-control form-control-sm" id="reopen-reason" name="reason" required maxlength="255" placeholder="np. korekta protokołu grupy INF-1">
        </div>
        <button class="btn btn-sm btn-outline-danger"><i class="bi bi-unlock me-1"></i>Otwórz okres ponownie</button>
      </form>
      <?php else: ?>
      <div class="small text-body-secondary">Okres zamknięty — otworzyć ponownie może tylko administrator.</div>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>

<!-- ── Kopiowanie / przenoszenie zajęć ───────────────────────────────────────── -->
<?php if ($can_write): ?>
<div class="card border-0 shadow-sm mb-4">
  <div class="card-header fw-semibold bg-body-tertiary"><i class="bi bi-arrow-left-right me-1"></i>Kopiuj / przenieś zajęcia między okresami</div>
  <div class="card-body">
  <?php if (count($periods) < 2): ?>
    <div class="text-body-secondary"><i class="bi bi-info-circle me-1"></i>Zdefiniuj co najmniej dwa okresy, aby przenosić między nimi zajęcia.</div>
  <?php else: ?>
    <form method="post" class="row g-3">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_op" value="preview">
      <div class="col-md-6">
        <label class="form-label fw-semibold">Okres źródłowy</label>
        <select class="form-select" name="src_id" required>
          <option value="">— wybierz —</option>
          <?php foreach ($periods as $p): ?>
          <option value="<?= (int)$p['id'] ?>" <?= (($preview['src']['id'] ?? 0) == $p['id']) ? 'selected' : '' ?>><?= h($p['name']) ?> (<?= h(ti_period_type_label($p['type'], true)) ?>, <?= h(date('d.m.Y', strtotime($p['date_from']))) ?>–<?= h(date('d.m.Y', strtotime($p['date_to']))) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold">Okres docelowy</label>
        <select class="form-select" name="dst_id" required>
          <option value="">— wybierz —</option>
          <?php foreach ($periods as $p): ?>
          <option value="<?= (int)$p['id'] ?>" <?= (($preview['dst']['id'] ?? 0) == $p['id']) ? 'selected' : '' ?>><?= h($p['name']) ?> (<?= h(ti_period_type_label($p['type'], true)) ?>, <?= h(date('d.m.Y', strtotime($p['date_from']))) ?>–<?= h(date('d.m.Y', strtotime($p['date_to']))) ?>)</option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-12">
        <label class="form-label fw-semibold">Kursy</label>
        <div class="border rounded p-2" style="max-height:190px;overflow:auto">
          <div class="form-check">
            <input class="form-check-input" type="checkbox" id="chk_all_courses" onchange="document.querySelectorAll('.course-chk').forEach(c=>c.checked=this.checked)">
            <label class="form-check-label fw-semibold" for="chk_all_courses">Wszystkie kursy</label>
          </div>
          <hr class="my-1">
          <?php $sel_courses = $preview['course_ids'] ?? []; foreach ($courses as $c): ?>
          <div class="form-check">
            <input class="form-check-input course-chk" type="checkbox" name="course_ids[]" value="<?= (int)$c['id'] ?>" id="crs<?= (int)$c['id'] ?>" <?= in_array((int)$c['id'], $sel_courses, true) ? 'checked' : '' ?>>
            <label class="form-check-label" for="crs<?= (int)$c['id'] ?>"><?= h($c['name']) ?><?php if (!$c['is_active']): ?> <span class="badge text-bg-secondary">nieaktywny</span><?php endif; ?></label>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="form-text">Nie zaznaczaj nic, aby objąć <strong>wszystkie</strong> kursy.</div>
      </div>
      <div class="col-md-6">
        <label class="form-label fw-semibold d-block">Tryb</label>
        <div class="btn-group" role="group">
          <input type="radio" class="btn-check" name="mode" id="mode_copy" value="copy" <?= (($preview['mode'] ?? 'copy') !== 'move') ? 'checked' : '' ?>>
          <label class="btn btn-outline-primary" for="mode_copy"><i class="bi bi-files me-1"></i>Kopiuj</label>
          <input type="radio" class="btn-check" name="mode" id="mode_move" value="move" <?= (($preview['mode'] ?? '') === 'move') ? 'checked' : '' ?>>
          <label class="btn btn-outline-primary" for="mode_move"><i class="bi bi-arrow-right me-1"></i>Przenieś</label>
        </div>
        <div class="form-text"><strong>Kopiuj</strong> tworzy nowe lekcje. <strong>Przenieś</strong> zmienia daty istniejących.</div>
      </div>
      <div class="col-md-6 d-flex align-items-center">
        <div class="form-check mt-3">
          <input class="form-check-input" type="checkbox" name="skip_off" id="skip_off" value="1" <?= (!empty($preview['skip_off'])) ? 'checked' : '' ?>>
          <label class="form-check-label" for="skip_off">Omijaj dni wolne / przerwy (przesuń na kolejny dzień roboczy)</label>
        </div>
      </div>
      <div class="col-12">
        <button type="submit" class="btn btn-outline-primary"><i class="bi bi-eye me-1"></i>Podgląd zmian</button>
      </div>
    </form>

    <?php if ($preview): $offset_days = (new DateTime($preview['src']['date_from']))->diff(new DateTime($preview['dst']['date_from']))->days; ?>
    <hr class="my-3">
    <h2 class="h6"><i class="bi bi-list-check me-1"></i>Podgląd:
      <?= $preview['mode'] === 'move' ? 'przeniesienie' : 'skopiowanie' ?>
      <?= count($preview['plan']) ?> lekcji z „<?= h($preview['src']['name']) ?>" do „<?= h($preview['dst']['name']) ?>"
      (przesunięcie o <?= (int)$offset_days ?> dni)</h2>
    <?php $dups = array_filter($preview['plan'], fn($p)=>$p['duplicate']); $outside = array_filter($preview['plan'], fn($p)=>!$p['in_dst_range']); $zbusy = array_filter($preview['plan'], fn($p)=>!empty($p['zoom_busy'])); ?>
    <?php if ($zbusy): ?><div class="alert alert-danger py-2 small mb-2"><i class="bi bi-camera-video-off me-1"></i><?= count($zbusy) ?> lekcji trafia na termin, w którym konto Zoom jest już zajęte — te lekcje zostaną pominięte (jeden host Zoom nie prowadzi dwóch spotkań jednocześnie).</div><?php endif; ?>
    <?php if ($dups): ?><div class="alert alert-warning py-2 small mb-2"><i class="bi bi-exclamation-triangle me-1"></i>Uwaga: <?= count($dups) ?> lekcji trafi na termin, gdzie już istnieje lekcja tego kursu o tej samej godzinie.</div><?php endif; ?>
    <?php if ($outside): ?><div class="alert alert-info py-2 small mb-2"><i class="bi bi-info-circle me-1"></i><?= count($outside) ?> lekcji wypada poza zakresem dat okresu docelowego (offset liczony od dat „od" obu okresów).</div><?php endif; ?>
    <div class="table-responsive" style="max-height:340px;overflow:auto">
      <table class="table table-sm mb-0">
        <thead class="table-light"><tr><th>Kurs</th><th>Godz.</th><th>Data źródłowa</th><th></th><th>Nowa data</th><th>Uwaga</th></tr></thead>
        <tbody>
        <?php foreach ($preview['plan'] as $p): $s=$p['src']; ?>
          <tr>
            <td><?= h($s['course_name']) ?></td>
            <td class="text-nowrap small"><?= h($s['time_from']) ?><?= $s['time_to'] ? '–'.h($s['time_to']) : '' ?></td>
            <td class="text-nowrap"><?= h(date('d.m.Y', strtotime($s['lesson_date']))) ?> <span class="text-body-secondary small"><?= h(['Nd','Pn','Wt','Śr','Cz','Pt','Sb'][date('w', strtotime($s['lesson_date']))]) ?></span></td>
            <td class="text-body-secondary">→</td>
            <td class="text-nowrap fw-semibold"><?= h(date('d.m.Y', strtotime($p['new_date']))) ?> <span class="text-body-secondary small"><?= h(['Nd','Pn','Wt','Śr','Cz','Pt','Sb'][date('w', strtotime($p['new_date']))]) ?></span></td>
            <td class="small">
              <?php if ($p['duplicate']): ?><span class="badge text-bg-warning">duplikat</span> <?php endif; ?>
              <?php if (!$p['in_dst_range']): ?><span class="badge text-bg-info">poza okresem</span> <?php endif; ?>
              <?php if (!empty($p['zoom_busy'])): ?><span class="badge text-bg-danger" title="<?= h($p['zoom_busy']) ?>">Zoom zajęty — pominięte</span><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <form method="post" class="mt-3" onsubmit="return confirm('<?= $preview['mode']==='move' ? 'Przenieść' : 'Skopiować' ?> <?= count($preview['plan']) ?> lekcji? Tej operacji nie można cofnąć automatycznie.')">
      <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="_op" value="apply">
      <input type="hidden" name="src_id" value="<?= (int)$preview['src']['id'] ?>">
      <input type="hidden" name="dst_id" value="<?= (int)$preview['dst']['id'] ?>">
      <input type="hidden" name="mode" value="<?= h($preview['mode']) ?>">
      <input type="hidden" name="skip_off" value="<?= !empty($preview['skip_off']) ? '1' : '0' ?>">
      <?php foreach ($preview['course_ids'] as $cid): ?><input type="hidden" name="course_ids[]" value="<?= (int)$cid ?>"><?php endforeach; ?>
      <button type="submit" class="btn btn-primary"><i class="bi bi-check2-circle me-1"></i>Wykonaj <?= $preview['mode']==='move' ? 'przeniesienie' : 'kopiowanie' ?></button>
    </form>
    <?php endif; ?>
  <?php endif; ?>
  </div>
</div>
<?php endif; ?>
</div>

<?php if ($can_write): ?>
<div class="modal fade" id="periodModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="_op" value="save">
        <input type="hidden" name="id" id="pf_id" value="0">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-calendar-range me-2"></i><span id="pf_title">Dodaj okres</span></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-2">
            <label class="form-label fw-semibold">Nazwa <span class="text-danger">*</span></label>
            <input type="text" class="form-control" name="name" id="pf_name" required placeholder="np. Rok szkolny 2025/2026, I kwartał 2026, Wakacje 2026">
          </div>
          <div class="mb-2">
            <label class="form-label fw-semibold">Typ</label>
            <select class="form-select" name="type" id="pf_type">
              <?php foreach (TI_PERIOD_TYPES as $k=>$v): ?><option value="<?= h($k) ?>"><?= h($v['label']) ?></option><?php endforeach; ?>
            </select>
          </div>
          <div class="row g-2 mb-2">
            <div class="col-6">
              <label class="form-label fw-semibold">Data od <span class="text-danger">*</span></label>
              <input type="date" class="form-control" name="date_from" id="pf_df" required>
            </div>
            <div class="col-6">
              <label class="form-label fw-semibold">Data do <span class="text-danger">*</span></label>
              <input type="date" class="form-control" name="date_to" id="pf_dt" required>
            </div>
          </div>
          <div class="mb-2">
            <label class="form-label">Uwagi</label>
            <input type="text" class="form-control" name="note" id="pf_note" placeholder="Opcjonalnie">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-primary">Zapisz</button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
function periodEdit(p) {
  document.getElementById('pf_id').value = p.id;
  document.getElementById('pf_name').value = p.name;
  document.getElementById('pf_type').value = p.type;
  document.getElementById('pf_df').value = p.date_from;
  document.getElementById('pf_dt').value = p.date_to;
  document.getElementById('pf_note').value = p.note || '';
  document.getElementById('pf_title').textContent = 'Edytuj okres';
  new bootstrap.Modal(document.getElementById('periodModal')).show();
}
document.getElementById('btnAddPeriod').addEventListener('click', function() {
  document.getElementById('pf_id').value = '0';
  document.getElementById('pf_name').value = '';
  document.getElementById('pf_type').value = 'quarter';
  document.getElementById('pf_note').value = '';
  const t = new Date().toISOString().slice(0,10);
  document.getElementById('pf_df').value = t;
  document.getElementById('pf_dt').value = t;
  document.getElementById('pf_title').textContent = 'Dodaj okres';
});
</script>
<?php endif; ?>
<?php require_once dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
