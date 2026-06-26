<?php
/**
 * karty30/ti/search.php — Wyszukiwarka globalna TI.
 * Zwraca JSON: {results:[{type,label,sub,url,icon},...]} (akcja AJAX)
 * lub renderuje widok HTML (GET ?q=...).
 * Przeszukuje: kursy, kursantów (k30_clients), lekcje (tematy), zadania, materiały.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/karty30.php';

k30_require_access();
karty30_migrate();

$q    = trim($_GET['q'] ?? '');
$ajax = !empty($_GET['_ajax']);

if ($ajax) {
    header('Content-Type: application/json; charset=utf-8');
    if (mb_strlen($q) < 2) { echo json_encode(['results' => []]); exit; }
    echo json_encode(['results' => ti_search($q, 12)]);
    exit;
}

$PAGE_TITLE = 'Szukaj — TI';
$results    = mb_strlen($q) >= 2 ? ti_search($q, 40) : [];

/**
 * Szukaj we wszystkich obiektach TI.
 * Zwraca tablicę [{type, label, sub, url, icon}].
 */
function ti_search(string $q, int $limit): array {
    $like = '%' . str_replace(['%','_'], ['\\%','\\_'], $q) . '%';
    $base = rtrim(APP_URL, '/') . '/karty30/ti/';
    $out  = [];

    // Kursy
    $rows = db_all(
        "SELECT id, name, location FROM k30_ti_courses
         WHERE status != 'cancelled' AND (name LIKE ? OR location LIKE ?) LIMIT ?",
        [$like, $like, $limit]
    );
    foreach ($rows as $r) {
        $out[] = ['type'=>'kurs','icon'=>'pc-display','label'=>$r['name'],
                  'sub'=>$r['location'] ?: '','url'=>$base.'course.php?id='.(int)$r['id']];
    }

    // Kursanci (clients z zapisem do kursu TI)
    $rows = db_all(
        "SELECT DISTINCT cl.id, cl.name, cl.email
         FROM k30_clients cl
         JOIN k30_ti_enrollments e ON e.client_id=cl.id
         WHERE (cl.name LIKE ? OR cl.email LIKE ? OR cl.phone LIKE ?) LIMIT ?",
        [$like, $like, $like, $limit]
    );
    foreach ($rows as $r) {
        $out[] = ['type'=>'kursant','icon'=>'person','label'=>$r['name'],
                  'sub'=>$r['email'] ?: '','url'=>$base.'course.php?client='.(int)$r['id']];
    }

    // Lekcje (temat)
    $rows = db_all(
        "SELECT s.id, s.topic, s.lesson_date, c.id AS cid, c.name AS cname
         FROM k30_ti_sessions s JOIN k30_ti_courses c ON c.id=s.course_id
         WHERE s.topic LIKE ? AND s.status != 'cancelled' LIMIT ?",
        [$like, $limit]
    );
    foreach ($rows as $r) {
        $out[] = ['type'=>'lekcja','icon'=>'calendar-event','label'=>$r['topic'],
                  'sub'=>$r['cname'].' · '.date('d.m.Y', strtotime($r['lesson_date'])),
                  'url'=>$base.'lesson.php?id='.(int)$r['id']];
    }

    // Zadania domowe
    $rows = db_all(
        "SELECT h.id, h.title, h.description, c.name AS cname
         FROM k30_ti_homework h JOIN k30_ti_courses c ON c.id=h.course_id
         WHERE h.title LIKE ? OR h.description LIKE ? LIMIT ?",
        [$like, $like, $limit]
    );
    foreach ($rows as $r) {
        $out[] = ['type'=>'zadanie','icon'=>'journal-check','label'=>$r['title'],
                  'sub'=>$r['cname'],'url'=>$base.'homework.php?id='.(int)$r['id']];
    }

    // Materiały
    $rows = db_all(
        "SELECT m.id, m.title, m.type, c.name AS cname
         FROM k30_ti_materials m JOIN k30_ti_courses c ON c.id=m.course_id
         WHERE m.title LIKE ? LIMIT ?",
        [$like, $limit]
    );
    foreach ($rows as $r) {
        $out[] = ['type'=>'materiał','icon'=>'collection-play','label'=>$r['title'],
                  'sub'=>$r['cname'].' · '.$r['type'],'url'=>$base.'materials.php'];
    }

    // Posortuj: najpierw kursy i kursanci, potem reszta
    $order = ['kurs'=>0,'kursant'=>1,'lekcja'=>2,'zadanie'=>3,'materiał'=>4];
    usort($out, fn($a,$b) => ($order[$a['type']]??9) <=> ($order[$b['type']]??9));
    return array_slice($out, 0, $limit);
}

include dirname(dirname(__DIR__)) . '/karty30/includes/header_k30.php';
?>

<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/karty30/index.php">Karty 30</a></li>
  <li class="breadcrumb-item"><a href="index.php">Zajęcia TI</a></li>
  <li class="breadcrumb-item active">Szukaj</li>
</ol></nav>

<div class="d-flex align-items-center mb-3 gap-2">
  <h4 class="mb-0 fw-bold"><i class="bi bi-search text-primary me-2"></i>Wyszukiwarka TI</h4>
</div>

<form method="get" class="mb-4">
  <div class="input-group" style="max-width:560px">
    <input type="search" name="q" class="form-control form-control-lg" value="<?= h($q) ?>"
           placeholder="Kurs, kursant, temat lekcji, zadanie…" autofocus
           autocomplete="off" aria-label="Wyszukiwarka TI">
    <button type="submit" class="btn btn-primary"><i class="bi bi-search"></i></button>
  </div>
  <?php if (mb_strlen($q) > 0 && mb_strlen($q) < 2): ?>
  <div class="form-text text-warning mt-1">Wpisz co najmniej 2 znaki.</div>
  <?php endif; ?>
</form>

<?php if (mb_strlen($q) >= 2): ?>
<div class="mb-2 text-body-secondary small">
  Wyniki dla: <strong><?= h($q) ?></strong> — znaleziono: <?= count($results) ?>
</div>

<?php if (!$results): ?>
<div class="alert alert-secondary">Brak wyników.</div>
<?php else: ?>
<div class="list-group">
  <?php
  $type_badge = [
    'kurs'    => 'text-bg-primary',
    'kursant' => 'text-bg-success',
    'lekcja'  => 'text-bg-info',
    'zadanie' => 'text-bg-warning',
    'materiał'=> 'text-bg-secondary',
  ];
  foreach ($results as $r): ?>
  <a href="<?= h($r['url']) ?>" class="list-group-item list-group-item-action d-flex align-items-center gap-3 py-2">
    <i class="bi bi-<?= h($r['icon']) ?> fs-5 text-primary flex-shrink-0" aria-hidden="true"></i>
    <div class="flex-grow-1 min-width-0">
      <div class="fw-semibold text-truncate"><?= h($r['label']) ?></div>
      <?php if ($r['sub']): ?><div class="small text-body-secondary text-truncate"><?= h($r['sub']) ?></div><?php endif; ?>
    </div>
    <span class="badge <?= h($type_badge[$r['type']] ?? 'text-bg-secondary') ?> flex-shrink-0"><?= h($r['type']) ?></span>
  </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php elseif ($q === ''): ?>
<p class="text-body-secondary">Wpisz szukaną frazę powyżej. Przeszukiwane są: kursy, kursanci, tematy lekcji, zadania domowe i materiały.</p>
<?php endif; ?>

<?php include dirname(dirname(__DIR__)) . '/karty30/includes/footer_k30.php'; ?>
