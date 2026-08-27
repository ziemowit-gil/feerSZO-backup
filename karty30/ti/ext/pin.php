<?php
/**
 * karty30/ti/ext/pin.php — przypięcie materiału do lekcji.
 *
 * Dostępne dla prowadzącego (własne zajęcia) i pracownika D3 (dowolne).
 * Kursant tu nie wchodzi — przypięcia widzi przy swoich lekcjach.
 */
require_once __DIR__ . '/_boot.php';

$EXT_SUBJECT = ext_require_subject($EXT_SUBJECT);
if ($EXT_SUBJECT['type'] !== 'user') {
    http_response_code(403);
    exit('Materiały do lekcji przypinają prowadzący i pracownicy.');
}

$uid = (int)$EXT_SUBJECT['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['_op'] ?? '') === 'unpin') {
        ext_pin_remove($EXT_SUBJECT, (int)($_POST['pin_id'] ?? 0))
            ? flash_set('success', 'Materiał odpięty od lekcji.')
            : flash_set('danger', 'Nie udało się odpiąć materiału.');
        header('Location: ' . ($_POST['back'] ?? 'index.php')); exit;
    }

    $res = ext_pin_add(
        $EXT_SUBJECT,
        (int)($_POST['resource_id'] ?? 0),
        (int)($_POST['session_id'] ?? 0),
        (string)($_POST['note'] ?? ''),
        !empty($_POST['share'])
    );
    flash_set($res['ok'] ? ($res['granted'] ? 'success' : 'warning') : 'danger', $res['msg']);
    header('Location: pin.php?resource=' . (int)($_POST['resource_id'] ?? 0)); exit;
}

$resource = ext_resource_get((int)($_GET['resource'] ?? 0));
$ctx      = $resource ? ext_resource_context((int)$resource['id']) : null;
if (!$ctx) { flash_set('danger', 'Nie znaleziono materiału.'); header('Location: index.php'); exit; }

// Kursy: pracownik widzi wszystkie, prowadzący swoje
$courses = ext_can_manage($EXT_SUBJECT)
    ? (function_exists('k30_ti_courses') ? k30_ti_courses(false) : [])
    : (function_exists('dyd_courses') ? dyd_courses($uid) : []);

$course_id = (int)($_GET['course'] ?? 0);
if (!$course_id && $courses) $course_id = (int)$courses[0]['id'];

$sessions = $course_id ? db_all(
    "SELECT id, lesson_date, time_from, topic FROM k30_ti_sessions
     WHERE course_id = CAST(? AS INTEGER)
     ORDER BY lesson_date DESC, time_from DESC LIMIT 60", [$course_id]) : [];

$pins = db_all(
    "SELECT pn.*, s.lesson_date, s.topic, c.name AS course_name
     FROM k30_ext_pins pn
     JOIN k30_ti_sessions s ON s.id = pn.session_id
     JOIN k30_ti_courses  c ON c.id = s.course_id
     WHERE pn.resource_id = CAST(? AS INTEGER)
     ORDER BY s.lesson_date DESC", [(int)$resource['id']]);

$EXT_TITLE = 'Przypnij do lekcji';
$EXT_TAB   = 'katalog';
include __DIR__ . '/_head.php';
?>

<div class="skin-crumbs mb-3">
  <a href="index.php">Katalog</a> &rsaquo;
  <a href="title.php?id=<?= (int)$ctx['title']['id'] ?>"><?= h($ctx['title']['title']) ?></a> &rsaquo;
  <strong>Przypnij do lekcji</strong>
</div>

<h1 class="h4 fw-bold mb-1"><i class="bi bi-pin-angle text-primary me-2" aria-hidden="true"></i>Przypnij materiał do lekcji</h1>
<p class="text-body-secondary small mb-3">
  Materiał: <strong><?= h($resource['name']) ?></strong> — <?= h($ctx['title']['title']) ?>.
  Przypięcie pokazuje go uczestnikom przy tych zajęciach.
</p>

<form method="get" class="row g-2 align-items-end mb-3">
  <input type="hidden" name="resource" value="<?= (int)$resource['id'] ?>">
  <div class="col-12 col-md-6">
    <label class="form-label" for="p-course">Grupa</label>
    <select class="form-select" id="p-course" name="course" onchange="this.form.submit()">
      <?php foreach ($courses as $c): ?>
      <option value="<?= (int)$c['id'] ?>" <?= $course_id === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
  <noscript><div class="col-auto"><button class="btn btn-sm btn-primary">Pokaż lekcje</button></div></noscript>
</form>

<?php if (!$sessions): ?>
<div class="alert alert-info">Ta grupa nie ma jeszcze zaplanowanych zajęć.</div>
<?php else: ?>
<form method="post" class="card mb-3">
  <div class="card-header">Nowe przypięcie</div>
  <div class="card-body row g-3">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="resource_id" value="<?= (int)$resource['id'] ?>">
    <div class="col-12 col-md-5">
      <label class="form-label" for="p-ses">Lekcja <span class="text-danger" aria-hidden="true">*</span></label>
      <select class="form-select" id="p-ses" name="session_id" required>
        <?php foreach ($sessions as $s): ?>
        <option value="<?= (int)$s['id'] ?>">
          <?= h($s['lesson_date']) ?><?= $s['time_from'] ? ' ' . h(substr($s['time_from'], 0, 5)) : '' ?><?= $s['topic'] !== '' ? ' — ' . h($s['topic']) : '' ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-12 col-md-4">
      <label class="form-label" for="p-note">Notatka dla uczestników</label>
      <input class="form-control" id="p-note" name="note" maxlength="200" placeholder="np. rozdział 3, s. 45–70">
    </div>
    <div class="col-12 col-md-3 d-flex align-items-end">
      <div class="form-check">
        <input class="form-check-input" type="checkbox" id="p-share" name="share" value="1" checked>
        <label class="form-check-label" for="p-share">Udostępnij grupie do czytania</label>
      </div>
    </div>
    <div class="col-12">
      <p class="small text-body-secondary mb-2">
        Udostępnienie daje uczestnikom prawo <strong>czytania</strong>. Pobieranie i druk
        zostają przy regułach wydawcy — przypięcie ich nie odblokuje. Jeśli tytuł wymaga
        licencji, której nie ma, materiał zostanie przypięty bez dostępu i dostaniesz o tym informację.
      </p>
      <button class="btn btn-primary btn-sm"><i class="bi bi-pin-angle me-1" aria-hidden="true"></i>Przypnij</button>
      <a href="title.php?id=<?= (int)$ctx['title']['id'] ?>" class="btn btn-outline-secondary btn-sm">Wróć do tytułu</a>
    </div>
  </div>
</form>
<?php endif; ?>

<h2 class="h6 fw-bold mb-2">Gdzie ten materiał już wisi</h2>
<?php if (!$pins): ?>
<p class="text-body-secondary small">Nigdzie — jeszcze go nie przypięto.</p>
<?php else: ?>
<div class="table-responsive">
  <table class="table table-sm align-middle">
    <caption class="visually-hidden">Lekcje, do których przypięto ten materiał</caption>
    <thead><tr><th scope="col">Grupa</th><th scope="col">Lekcja</th><th scope="col">Notatka</th><th scope="col">Dostęp grupy</th><th scope="col">Kto przypiął</th><th scope="col"></th></tr></thead>
    <tbody>
      <?php foreach ($pins as $pn): ?>
      <tr>
        <th scope="row" class="small"><?= h($pn['course_name']) ?></th>
        <td class="small text-nowrap"><?= h($pn['lesson_date']) ?><?= $pn['topic'] !== '' ? ' — ' . h($pn['topic']) : '' ?></td>
        <td class="small"><?= h($pn['note']) ?></td>
        <td class="small"><?= $pn['grant_id'] ? 'tak — czytanie' : 'nie (brak licencji)' ?></td>
        <td class="small"><?= h($pn['created_name']) ?></td>
        <td class="text-end">
          <form method="post" class="d-inline" onsubmit="return confirm('Odpiąć materiał od tej lekcji?')">
            <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="_op" value="unpin">
            <input type="hidden" name="pin_id" value="<?= (int)$pn['id'] ?>">
            <input type="hidden" name="back" value="pin.php?resource=<?= (int)$resource['id'] ?>">
            <button class="btn btn-sm btn-outline-danger py-0 px-2">Odepnij</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php endif; ?>

<?php include __DIR__ . '/_foot.php'; ?>
