<?php
/**
 * Wyszukiwarka EZD — szuka po numerze dokumentu (sygnatura pisma/dokumentu/umowy),
 * znaku koszulki i tytułach. Wyniki grupowane, z poszanowaniem dostępu do koszulek
 * (ezd_sprawa_access — użytkownik bez dostępu nie zobaczy cudzej koszulki).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/ezd.php';

require_login();
require_module_enabled('ezd_enabled', 'Moduł EZD Wirtualne biurko'); ezd_require_access();

$user_id = (int)current_user()['id'];
$q       = trim($_GET['q'] ?? '');
$like    = '%' . $q . '%';

$PAGE_TITLE = 'Szukaj w EZD' . ($q !== '' ? ' — ' . $q : '');

$sprawy = $pisma = $dokumenty = $umowy = [];
if (mb_strlen($q) >= 2) {
    // Koszulki: po znaku i tytule. Dostęp filtrowany per wiersz.
    $sprawy = array_values(array_filter(
        db_all("SELECT s.*, t.symbol AS teczka_symbol FROM ezd_sprawy s
                JOIN ezd_teczki t ON t.id=s.teczka_id
                WHERE s.znak_sprawy LIKE ? OR s.title LIKE ?
                ORDER BY s.updated_at DESC LIMIT 100", [$like, $like]),
        fn($s) => ezd_sprawa_access($s, $user_id) !== null
    ));

    // Pisma / dokumenty wewnętrzne / umowy: po sygnaturze i tytule,
    // dostęp = dostęp do koszulki nadrzędnej.
    $rows_ok = function (array $rows) use ($user_id): array {
        $cache = [];
        return array_values(array_filter($rows, function ($r) use ($user_id, &$cache) {
            $sid = (int)$r['sprawa_id'];
            if (!isset($cache[$sid])) {
                $s = ezd_sprawa_get($sid);
                $cache[$sid] = $s ? (ezd_sprawa_access($s, $user_id) !== null) : false;
            }
            return $cache[$sid];
        }));
    };
    $pisma = $rows_ok(db_all(
        "SELECT p.id, p.sprawa_id, p.sygnatura, p.title, p.status, p.kierunek, s.znak_sprawy
         FROM ezd_pisma p JOIN ezd_sprawy s ON s.id=p.sprawa_id
         WHERE p.sygnatura LIKE ? OR p.title LIKE ?
         ORDER BY p.updated_at DESC LIMIT 100", [$like, $like]));
    $dokumenty = $rows_ok(db_all(
        "SELECT d.id, d.sprawa_id, d.sygnatura, d.title, d.status, s.znak_sprawy
         FROM ezd_dokumenty d JOIN ezd_sprawy s ON s.id=d.sprawa_id
         WHERE d.sygnatura LIKE ? OR d.title LIKE ?
         ORDER BY d.updated_at DESC LIMIT 100", [$like, $like]));
    $umowy = $rows_ok(db_all(
        "SELECT u.id, u.sprawa_id, u.sygnatura, u.title, u.status, s.znak_sprawy
         FROM ezd_umowy u JOIN ezd_sprawy s ON s.id=u.sprawa_id
         WHERE u.sygnatura LIKE ? OR u.title LIKE ?
         ORDER BY u.updated_at DESC LIMIT 100", [$like, $like]));
}
$total = count($sprawy) + count($pisma) + count($dokumenty) + count($umowy);

include dirname(__DIR__) . '/includes/header.php';
?>
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb" style="font-size:.8rem">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/ezd/index.php">EZD Wirtualne biurko</a></li>
  <li class="breadcrumb-item active">Szukaj</li>
</ol></nav>

<h4 class="fw-bold mb-3"><i class="bi bi-search text-primary me-2"></i>Wyszukiwanie w EZD</h4>

<form method="get" class="mb-4" role="search" aria-label="Wyszukiwanie w EZD">
  <div class="input-group input-group-lg" style="max-width:720px">
    <span class="input-group-text bg-white"><i class="bi bi-search text-muted" aria-hidden="true"></i></span>
    <input type="search" name="q" class="form-control" value="<?= h($q) ?>" required minlength="2" autofocus
           placeholder="Numer dokumentu, znak koszulki lub tytuł…" autocomplete="off">
    <button type="submit" class="btn btn-primary px-4">Szukaj</button>
  </div>
  <small class="text-muted d-block mt-1">Szuka po: numerze dokumentu (sygnatura pisma/dokumentu/umowy), znaku koszulki i tytułach. Min. 2 znaki.</small>
</form>

<?php if ($q !== '' && mb_strlen($q) < 2): ?>
<div class="alert alert-warning">Wpisz co najmniej 2 znaki.</div>
<?php elseif ($q !== ''): ?>

<div class="text-muted mb-3" style="font-size:.85rem">Wyników dla „<strong><?= h($q) ?></strong>": <strong><?= $total ?></strong></div>

<?php if (!$total): ?>
<div class="card shadow-sm"><div class="card-body text-center text-muted py-5">
  <i class="bi bi-search display-6 d-block mb-2 opacity-25"></i>
  Nic nie znaleziono. Spróbuj innego fragmentu numeru lub tytułu.
</div></div>
<?php endif; ?>

<?php if ($sprawy): ?>
<div class="card shadow-sm mb-3">
  <div class="card-header py-2 bg-light fw-semibold" style="font-size:.85rem"><i class="bi bi-folder2-open text-primary me-2"></i>Koszulki (<?= count($sprawy) ?>)</div>
  <div class="card-body p-0">
    <?php foreach ($sprawy as $s): ?>
    <a href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= (int)$s['id'] ?>" class="d-flex align-items-center gap-3 px-3 py-2 border-bottom text-decoration-none text-reset" style="font-size:.85rem">
      <code class="flex-shrink-0" style="font-size:.75rem"><?= h($s['znak_sprawy']) ?></code>
      <span class="flex-grow-1 text-truncate fw-semibold"><?= h($s['title']) ?></span>
      <?= ezd_status_badge_sprawa($s['status']) ?>
    </a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php
$groups = [
    ['Pisma',                $pisma,     'bi-envelope',          'pisma'],
    ['Dokumenty wewnętrzne', $dokumenty, 'bi-file-earmark-text', 'dokumenty'],
    ['Umowy (EZD)',          $umowy,     'bi-file-text',         'umowy'],
];
foreach ($groups as [$glabel, $grows, $gicon, $gdir]): if (!$grows) continue; ?>
<div class="card shadow-sm mb-3">
  <div class="card-header py-2 bg-light fw-semibold" style="font-size:.85rem"><i class="bi <?= $gicon ?> text-primary me-2"></i><?= h($glabel) ?> (<?= count($grows) ?>)</div>
  <div class="card-body p-0">
    <?php foreach ($grows as $r): ?>
    <a href="<?= APP_URL ?>/ezd/<?= $gdir ?>/view.php?id=<?= (int)$r['id'] ?>" class="d-flex align-items-center gap-3 px-3 py-2 border-bottom text-decoration-none text-reset" style="font-size:.85rem">
      <code class="flex-shrink-0" style="font-size:.75rem"><?= h($r['sygnatura']) ?></code>
      <span class="flex-grow-1 text-truncate fw-semibold"><?= h($r['title']) ?></span>
      <span class="badge bg-light text-dark border flex-shrink-0" style="font-size:.62rem"><?= h($r['status']) ?></span>
      <span class="font-monospace text-muted flex-shrink-0" style="font-size:.7rem" title="Koszulka"><?= h($r['znak_sprawy']) ?></span>
    </a>
    <?php endforeach; ?>
  </div>
</div>
<?php endforeach; ?>

<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
