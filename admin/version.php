<?php
/**
 * admin/version.php — Wersja aplikacji i historia zmian.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/version.php';

require_role('admin');
$PAGE_TITLE = 'Wersja i historia zmian';

$ver       = app_version();
$tag       = app_release_tag();
$changelog = app_changelog(50);

$type_badge = [
    'feat'     => ['bg-primary',   'Nowa funkcja'],
    'fix'      => ['bg-danger',    'Poprawka'],
    'refactor' => ['bg-secondary', 'Refaktor'],
    'perf'     => ['bg-warning text-dark', 'Wydajność'],
    'docs'     => ['bg-info text-dark', 'Dokumentacja'],
    'style'    => ['bg-light text-dark border', 'Styl'],
    'chore'    => ['bg-light text-dark border', 'Inne'],
    'other'    => ['bg-light text-dark border', 'Zmiana'],
];

include dirname(__DIR__) . '/includes/header.php';
?>

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb" style="font-size:.8rem">
    <li class="breadcrumb-item"><a href="index.php">Admin</a></li>
    <li class="breadcrumb-item active">Wersja</li>
  </ol>
</nav>

<div class="d-flex align-items-center gap-3 mb-4 flex-wrap">
  <h4 class="mb-0"><i class="bi bi-git me-2 text-primary"></i>Wersja aplikacji</h4>
  <span class="badge bg-primary" style="font-size:1rem;padding:.4rem .85rem">v<?= h($ver['main']) ?></span>
  <span class="badge bg-dark font-monospace" style="font-size:.8rem" title="Git commit hash"><?= h($ver['hash']) ?></span>
  <?php if ($ver['date']): ?>
  <span class="text-muted small"><?= h($ver['date']) ?></span>
  <?php endif; ?>
  <span class="ms-auto text-muted small font-monospace" title="Każdy bump automatycznie tworzy tag git — plik wersji i tag wydania zawsze idą w parze">
    <i class="bi bi-terminal me-1"></i>php cli/bump_version.php minor
  </span>
</div>

<!-- Karta wersji -->
<div class="row g-3 mb-4">
  <div class="col-sm-3">
    <div class="card shadow-sm h-100 border-primary">
      <div class="card-body py-3">
        <div class="small text-muted mb-1">Wersja robocza</div>
        <div class="fw-bold" style="font-size:1.4rem">v<?= h($ver['main']) ?></div>
        <div class="small text-muted">z min_version.txt</div>
      </div>
    </div>
  </div>
  <div class="col-sm-3">
    <div class="card shadow-sm h-100 <?= $tag['name'] ? ($tag['synced'] ? 'border-success' : 'border-warning') : '' ?>">
      <div class="card-body py-3">
        <div class="small text-muted mb-1">Wydanie (tag git)</div>
        <?php if ($tag['name']): ?>
        <div class="fw-bold font-monospace" style="font-size:1.2rem"><?= h($tag['name']) ?></div>
        <?php if ($tag['synced']): ?>
        <div class="small text-success"><i class="bi bi-check-circle me-1"></i>zgodny z bieżącym stanem</div>
        <?php else: ?>
        <div class="small text-warning-emphasis"><i class="bi bi-exclamation-triangle me-1"></i><?= (int)$tag['commits_since'] ?> commit(ów) po tagu</div>
        <?php endif; ?>
        <?php else: ?>
        <div class="fw-bold text-muted">brak</div>
        <div class="small text-muted">żaden bump jeszcze nie otagował repo</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-sm-3">
    <div class="card shadow-sm h-100">
      <div class="card-body py-3">
        <div class="small text-muted mb-1">Commit (git)</div>
        <div class="fw-bold font-monospace"><?= h($ver['hash']) ?></div>
        <?php if ($ver['hash_full']): ?>
        <div class="small text-muted font-monospace" style="font-size:.68rem;word-break:break-all"><?= h(substr($ver['hash_full'], 0, 20)) ?>…</div>
        <?php endif; ?>
        <div class="small text-muted"><?= h($ver['date'] ?: '—') ?></div>
      </div>
    </div>
  </div>
  <div class="col-sm-3">
    <div class="card shadow-sm h-100">
      <div class="card-body py-3">
        <div class="small text-muted mb-1">Gałąź (branch)</div>
        <div class="fw-bold font-monospace"><?= h($ver['branch']) ?></div>
      </div>
    </div>
  </div>
</div>

<!-- Historia zmian -->
<div class="card shadow-sm">
  <div class="card-header fw-semibold py-2">
    <i class="bi bi-clock-history me-1"></i>Historia zmian (ostatnie <?= count($changelog) ?>)
  </div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0" style="font-size:.84rem">
      <thead class="table-light">
        <tr>
          <th style="width:80px" class="ps-3">Commit</th>
          <th style="width:95px">Data</th>
          <th style="width:110px">Typ</th>
          <th>Opis</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$changelog): ?>
        <tr><td colspan="4" class="text-center text-muted py-4">Brak danych z git.</td></tr>
        <?php endif; ?>
        <?php foreach ($changelog as $c): ?>
        <?php [$cls, $lbl] = $type_badge[$c['type']] ?? $type_badge['other']; ?>
        <tr>
          <td class="ps-3"><code class="small"><?= h($c['hash']) ?></code></td>
          <td class="text-muted"><?= h($c['date']) ?></td>
          <td><span class="badge <?= $cls ?>" style="font-size:.7rem"><?= $lbl ?></span></td>
          <td><?= h($c['msg']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
