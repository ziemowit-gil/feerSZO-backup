<?php
/**
 * Historia wersji pisma — zestawienie zapisanych wersji treści z możliwością diff i przywrócenia.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/ezd.php';
require_login();
require_module_enabled('ezd_enabled', 'Moduł EZD Wirtualne biurko');
ezd_require_access();

$id     = (int)($_GET['id'] ?? 0);
$pismo  = ezd_pismo_get($id);
if (!$pismo) {
    http_response_code(404);
    flash_set('error', 'Pismo nie istnieje.');
    header('Location:' . APP_URL . '/ezd/sprawy/index.php');
    exit;
}

$sprawa_id = (int)$pismo['sprawa_id'];
$sprawa    = ezd_sprawa_get($sprawa_id);
$access    = $sprawa ? ezd_sprawa_access($sprawa, (int)current_user()['id']) : null;
if (!$access) {
    http_response_code(403);
    flash_set('error', 'Brak dostępu do tej sprawy.');
    header('Location:' . APP_URL . '/ezd/index.php');
    exit;
}
$can_act  = $access === 'write';
$user_id  = (int)current_user()['id'];

// Przywrócenie wersji
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action    = $_POST['_action'] ?? '';
    $wersja_id = (int)($_POST['wersja_id'] ?? 0);
    if ($action === 'przywroc' && $can_act && $wersja_id > 0) {
        $wersja = db_one(
            "SELECT * FROM ezd_pisma_wersje WHERE id=? AND pismo_id=?",
            [$wersja_id, $id]
        );
        if ($wersja) {
            try {
                ezd_pismo_update($id, array_merge((array)$pismo, [
                    'tresc' => $wersja['tresc'],
                    'title' => $wersja['title'],
                ]), $user_id);
                flash_set('success', 'Wersja #' . $wersja_id . ' została przywrócona.');
            } catch (\Throwable $e) {
                flash_set('error', $e->getMessage());
            }
        } else {
            flash_set('error', 'Nie znaleziono wybranej wersji.');
        }
    }
    header('Location:' . APP_URL . '/ezd/pisma/wersje.php?id=' . $id);
    exit;
}

// Pobranie wersji
$wersje = db_all(
    "SELECT v.*, u.name AS user_name
     FROM ezd_pisma_wersje v
     LEFT JOIN users u ON u.id = v.user_id
     WHERE v.pismo_id = ?
     ORDER BY v.created_at DESC",
    [$id]
);

// Tryb porównania
$va_id = (int)($_GET['a'] ?? 0);
$vb_id = (int)($_GET['b'] ?? 0);
$va = $vb = null;
if ($va_id && $vb_id) {
    foreach ($wersje as $w) {
        if ((int)$w['id'] === $va_id) $va = $w;
        if ((int)$w['id'] === $vb_id) $vb = $w;
    }
}

// Diff — LCS na poziomie wierszy
function _lcs_diff(array $a, array $b): array {
    $m = count($a);
    $n = count($b);
    $dp = array_fill(0, $m + 1, array_fill(0, $n + 1, 0));
    for ($i = $m - 1; $i >= 0; $i--) {
        for ($j = $n - 1; $j >= 0; $j--) {
            $dp[$i][$j] = $a[$i] === $b[$j]
                ? 1 + $dp[$i + 1][$j + 1]
                : max($dp[$i + 1][$j], $dp[$i][$j + 1]);
        }
    }
    $res = [];
    $i = 0;
    $j = 0;
    while ($i < $m || $j < $n) {
        if ($i < $m && $j < $n && $a[$i] === $b[$j]) {
            $res[] = ['eq', $a[$i]];
            $i++;
            $j++;
        } elseif ($j < $n && ($i >= $m || $dp[$i][$j + 1] >= $dp[$i + 1][$j])) {
            $res[] = ['ins', $b[$j]];
            $j++;
        } else {
            $res[] = ['del', $a[$i]];
            $i++;
        }
    }
    return $res;
}

$PAGE_TITLE = 'Historia wersji — ' . $pismo['sygnatura'];
include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<style>
ins.diff-line { background:#d1fae5; text-decoration:none; display:block; padding:1px 4px; border-left:3px solid #22c55e; }
del.diff-line { background:#fee2e2; text-decoration:line-through; display:block; padding:1px 4px; border-left:3px solid #ef4444; color:#991b1b; }
span.diff-eq  { display:block; padding:1px 4px; color:#64748b; font-size:.82rem; border-left:3px solid transparent; }
.diff-block   { font-family:monospace; font-size:.8rem; white-space:pre-wrap; border:1px solid #e2e8f0; border-radius:.375rem; padding:.5rem .25rem; max-height:480px; overflow-y:auto; background:#f8fafc; }
</style>

<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb" style="font-size:.8rem">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/index.php">EZD Wirtualne biurko</a></li>
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= $sprawa_id ?>"><?= h($pismo['znak_sprawy']) ?></a></li>
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/pisma/view.php?id=<?= $id ?>"><?= h($pismo['sygnatura']) ?></a></li>
  <li class="breadcrumb-item active">Historia wersji</li>
</ol></nav>

<?= flash_html() ?>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <div>
    <h5 class="mb-0 fw-bold"><i class="bi bi-clock-history text-primary me-2"></i>Historia wersji</h5>
    <div class="text-muted" style="font-size:.78rem;margin-top:.15rem">
      Pismo: <span class="font-monospace"><?= h($pismo['sygnatura']) ?></span> — <?= h($pismo['title']) ?>
    </div>
  </div>
  <a href="<?= APP_URL ?>/ezd/pisma/view.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left me-1"></i>Wróć do pisma
  </a>
</div>

<?php if ($va && $vb): ?>
<!-- Widok diff -->
<div class="card shadow-sm mb-4">
  <div class="card-header d-flex align-items-center justify-content-between">
    <span class="fw-semibold" style="font-size:.82rem">
      <i class="bi bi-arrows-angle-expand me-1 text-primary"></i>
      Porównanie wersji #<?= (int)$va['id'] ?> i #<?= (int)$vb['id'] ?>
    </span>
    <a href="<?= APP_URL ?>/ezd/pisma/wersje.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm btn-xs">
      <i class="bi bi-x-lg me-1"></i>Zamknij
    </a>
  </div>
  <div class="card-body">
    <div class="row g-3 mb-2" style="font-size:.78rem">
      <div class="col-md-6">
        <div class="p-2 rounded border bg-danger bg-opacity-5 border-danger border-opacity-25">
          <strong>Wersja #<?= (int)$va['id'] ?></strong> —
          <?= date('d.m.Y H:i', strtotime($va['created_at'])) ?>
          <?php if ($va['user_name']): ?>&bull; <?= h($va['user_name']) ?><?php endif; ?>
          <div class="mt-1 text-muted">Tytuł: <em><?= h($va['title']) ?></em></div>
        </div>
      </div>
      <div class="col-md-6">
        <div class="p-2 rounded border bg-success bg-opacity-5 border-success border-opacity-25">
          <strong>Wersja #<?= (int)$vb['id'] ?></strong> —
          <?= date('d.m.Y H:i', strtotime($vb['created_at'])) ?>
          <?php if ($vb['user_name']): ?>&bull; <?= h($vb['user_name']) ?><?php endif; ?>
          <div class="mt-1 text-muted">Tytuł: <em><?= h($vb['title']) ?></em></div>
        </div>
      </div>
    </div>
    <?php
    $lines_a = explode("\n", $va['tresc']);
    $lines_b = explode("\n", $vb['tresc']);
    $diff    = _lcs_diff($lines_a, $lines_b);
    ?>
    <div class="diff-block">
<?php foreach ($diff as [$type, $line]): ?>
<?php if ($type === 'ins'): ?><ins class="diff-line">+ <?= h($line) ?></ins>
<?php elseif ($type === 'del'): ?><del class="diff-line">- <?= h($line) ?></del>
<?php else: ?><span class="diff-eq">  <?= h($line) ?></span>
<?php endif; ?>
<?php endforeach; ?>
<?php if (!$diff): ?><span class="diff-eq text-muted">Treść identyczna.</span><?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Lista wersji -->
<div class="card shadow-sm">
  <div class="card-header d-flex align-items-center justify-content-between">
    <span class="fw-semibold" style="font-size:.82rem">
      <i class="bi bi-list-ul me-1 text-primary"></i>Zapisane wersje (<?= count($wersje) ?>)
    </span>
    <?php if (count($wersje) >= 2 && !($va && $vb)): ?>
    <span class="text-muted" style="font-size:.74rem">Zaznacz dwie wersje i kliknij „Porównaj"</span>
    <?php endif; ?>
  </div>
  <?php if ($wersje): ?>
  <form method="get" action="<?= APP_URL ?>/ezd/pisma/wersje.php" class="mb-0">
    <input type="hidden" name="id" value="<?= $id ?>">
    <div class="table-responsive">
      <table class="table table-sm table-hover mb-0 align-middle" style="font-size:.83rem">
        <thead class="table-light">
          <tr>
            <th style="width:40px" class="text-center">#</th>
            <th style="width:50px" class="text-center">Wer.</th>
            <th style="width:160px">Data</th>
            <th>Użytkownik</th>
            <th>Tytuł w tej wersji</th>
            <th style="width:50px" class="text-center">Porównaj</th>
            <?php if ($can_act): ?><th style="width:100px"></th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($wersje as $idx => $w): ?>
          <?php $num = count($wersje) - $idx; ?>
          <tr>
            <td class="text-center text-muted" style="font-size:.75rem"><?= $num ?></td>
            <td class="text-center"><span class="badge bg-secondary bg-opacity-15 text-secondary border">#<?= (int)$w['id'] ?></span></td>
            <td style="font-size:.78rem;white-space:nowrap"><?= date('d.m.Y H:i', strtotime($w['created_at'])) ?></td>
            <td><?= h($w['user_name'] ?? '—') ?></td>
            <td class="text-truncate" style="max-width:220px" title="<?= h($w['title']) ?>"><?= h($w['title'] ?: '(bez tytułu)') ?></td>
            <td class="text-center">
              <input type="checkbox" name="<?= !$va_id ? 'a' : 'b' ?>" value="<?= (int)$w['id'] ?>"
                     <?php
                     if ($va_id === (int)$w['id']) echo 'name="a" checked';
                     elseif ($vb_id === (int)$w['id']) echo 'name="b" checked';
                     ?>
                     class="form-check-input diff-check"
                     data-vid="<?= (int)$w['id'] ?>">
            </td>
            <?php if ($can_act): ?>
            <td class="text-end">
              <form method="post" class="d-inline"
                    onsubmit="return confirm('Przywrócić wersję #<?= (int)$w['id'] ?>? Aktualna treść zostanie zapisana jako nowa wersja.')">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="_action" value="przywroc">
                <input type="hidden" name="wersja_id" value="<?= (int)$w['id'] ?>">
                <button class="btn btn-xs btn-outline-warning btn-sm" style="font-size:.72rem" title="Przywróć tę wersję">
                  <i class="bi bi-arrow-counterclockwise me-1"></i>Przywróć
                </button>
              </form>
            </td>
            <?php endif; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php if (count($wersje) >= 2): ?>
    <div class="card-footer">
      <button type="submit" class="btn btn-outline-primary btn-sm" id="btn-diff">
        <i class="bi bi-arrows-angle-expand me-1"></i>Porównaj zaznaczone
      </button>
    </div>
    <?php endif; ?>
  </form>
  <?php else: ?>
  <div class="card-body text-center text-muted py-5">
    <i class="bi bi-clock-history" style="font-size:2.5rem;display:block;margin-bottom:.5rem;opacity:.3"></i>
    Brak zapisanych wersji.<br>
    <span style="font-size:.8rem">Wersje są tworzone automatycznie przy każdej edycji pisma.</span>
  </div>
  <?php endif; ?>
</div>

<script>
(function() {
  // Ograniczenie do max 2 checkboxów i obsługa parametrów a/b
  var checks = document.querySelectorAll('.diff-check');
  checks.forEach(function(cb) {
    cb.addEventListener('change', function() {
      var checked = Array.from(checks).filter(function(c) { return c.checked; });
      if (checked.length > 2) { this.checked = false; return; }
      // Ustaw name=a dla pierwszego, name=b dla drugiego
      checks.forEach(function(c) { c.removeAttribute('name'); });
      var sel = Array.from(checks).filter(function(c) { return c.checked; });
      if (sel[0]) sel[0].name = 'a';
      if (sel[1]) sel[1].name = 'b';
    });
  });
  // Preset z URL
  var urlA = <?= $va_id ?>, urlB = <?= $vb_id ?>;
  if (urlA || urlB) {
    checks.forEach(function(c) {
      var v = parseInt(c.getAttribute('data-vid'));
      if (v === urlA) { c.checked = true; c.name = 'a'; }
      else if (v === urlB) { c.checked = true; c.name = 'b'; }
      else { c.checked = false; c.removeAttribute('name'); }
    });
  }
})();
</script>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
