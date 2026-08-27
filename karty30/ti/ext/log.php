<?php
/**
 * karty30/ti/ext/log.php — dziennik dostępu do materiałów.
 *
 * Przy materiałach licencjonowanych dziennik jest dowodem wykonania umowy
 * z wydawcą: pokazuje, że treść widziały wyłącznie osoby objęte licencją.
 * Dlatego widać w nim także ODMOWY — one dowodzą, że kontrola działa.
 */
require_once __DIR__ . '/_boot.php';

$EXT_SUBJECT = ext_require_manager($EXT_SUBJECT);

$f_dec = in_array($_GET['d'] ?? '', ['granted','denied','served'], true) ? $_GET['d'] : '';
$f_res = (int)($_GET['res'] ?? 0);
$page  = max(1, (int)($_GET['p'] ?? 1));
$per   = 100;

$where = []; $p = [];
if ($f_dec !== '') { $where[] = "l.decision = ?";                    $p[] = $f_dec; }
if ($f_res)        { $where[] = "l.resource_id = CAST(? AS INTEGER)"; $p[] = $f_res; }
$sql = "SELECT l.*, t.title AS title_name
        FROM k30_ext_access_log l
        LEFT JOIN k30_ext_titles t ON t.id = l.title_id"
     . ($where ? " WHERE " . implode(' AND ', $where) : "")
     . " ORDER BY l.id DESC LIMIT " . ($per + 1) . " OFFSET " . (($page - 1) * $per);
$rows = db_all($sql, $p);
$more = count($rows) > $per;
$rows = array_slice($rows, 0, $per);

$stats = db_one("SELECT
    SUM(CASE WHEN decision='served'  THEN 1 ELSE 0 END) AS served,
    SUM(CASE WHEN decision='denied'  THEN 1 ELSE 0 END) AS denied,
    COUNT(DISTINCT subject_type || subject_id)          AS people
  FROM k30_ext_access_log WHERE created_at >= datetime('now','-30 days')") ?: [];

$EXT_TITLE = 'Dziennik';
$EXT_TAB   = 'dziennik';
include __DIR__ . '/_head.php';
?>

<h1 class="h4 fw-bold mb-1"><i class="bi bi-list-check text-primary me-2" aria-hidden="true"></i>Dziennik dostępu</h1>
<p class="mb-3">
  Ostatnie 30 dni: wydań treści <strong><?= (int)($stats['served'] ?? 0) ?></strong>,
  odmów <strong><?= (int)($stats['denied'] ?? 0) ?></strong>,
  osób korzystających <strong><?= (int)($stats['people'] ?? 0) ?></strong>.
</p>

<form method="get" class="d-flex flex-wrap gap-2 align-items-end mb-3">
  <div>
    <label class="form-label" for="l-dec">Pokaż</label>
    <select class="form-select" id="l-dec" name="d" onchange="this.form.submit()">
      <option value="">wszystko</option>
      <option value="served"  <?= $f_dec === 'served'  ? 'selected' : '' ?>>wydania treści</option>
      <option value="granted" <?= $f_dec === 'granted' ? 'selected' : '' ?>>wystawione bilety</option>
      <option value="denied"  <?= $f_dec === 'denied'  ? 'selected' : '' ?>>odmowy</option>
    </select>
  </div>
  <noscript><button class="btn btn-sm btn-primary">Filtruj</button></noscript>
</form>

<div class="table-responsive">
  <table class="table table-sm align-middle usos-sticky">
    <caption class="visually-hidden">Zdarzenia dostępu do materiałów zewnętrznych</caption>
    <thead>
      <tr>
        <th scope="col">Kiedy</th><th scope="col">Kto</th><th scope="col">Materiał</th>
        <th scope="col">Czynność</th><th scope="col">Wynik</th><th scope="col">IP</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
      <tr>
        <td class="text-nowrap small"><time datetime="<?= h(str_replace(' ', 'T', substr((string)$r['created_at'], 0, 16))) ?>"><?= h(substr((string)$r['created_at'], 0, 16)) ?></time></td>
        <th scope="row" class="small fw-semibold"><?= h($r['subject_name'] !== '' ? $r['subject_name'] : $r['subject_type'] . ' #' . (int)$r['subject_id']) ?></th>
        <td class="small"><?= h((string)($r['title_name'] ?? '')) ?><?php if ($r['resource_id']): ?> <span class="text-body-secondary">#<?= (int)$r['resource_id'] ?></span><?php endif; ?></td>
        <td class="small"><?= h($r['ability']) ?></td>
        <td class="small">
          <?php if ($r['decision'] === 'denied'): ?>
          <span class="badge text-bg-danger">odmowa</span> <?= h(ext_reason_text($r['reason'])) ?>
          <?php elseif ($r['decision'] === 'served'): ?>
          <span class="badge text-bg-success">wydano</span>
          <?php else: ?>
          <span class="badge text-bg-secondary">bilet</span>
          <?php endif; ?>
        </td>
        <td class="small text-nowrap"><?= h($r['ip']) ?></td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="6" class="text-center text-body-secondary py-3">Brak zdarzeń.</td></tr><?php endif; ?>
    </tbody>
  </table>
</div>

<nav aria-label="Strony dziennika" class="d-flex gap-2">
  <?php if ($page > 1): ?><a class="btn btn-sm btn-outline-secondary" href="log.php?d=<?= h($f_dec) ?>&amp;p=<?= $page - 1 ?>">← Nowsze</a><?php endif; ?>
  <?php if ($more):     ?><a class="btn btn-sm btn-outline-secondary" href="log.php?d=<?= h($f_dec) ?>&amp;p=<?= $page + 1 ?>">Starsze →</a><?php endif; ?>
</nav>

<?php include __DIR__ . '/_foot.php'; ?>
