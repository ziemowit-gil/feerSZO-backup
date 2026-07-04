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
$build     = app_build_version();
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

<div class="d-flex align-items-center gap-3 mb-3 flex-wrap">
  <h4 class="mb-0"><i class="bi bi-git me-2 text-primary"></i>Wersja aplikacji</h4>
  <span class="ms-auto text-muted small font-monospace" title="Każdy bump automatycznie tworzy tag git — plik wersji i tag wydania zawsze idą w parze">
    <i class="bi bi-terminal me-1"></i>php cli/bump_version.php minor
  </span>
</div>

<!-- Hero: dwie wersje obok siebie -->
<div class="card shadow-sm mb-4">
  <div class="card-body">
    <div class="row g-4 align-items-center">
      <div class="col-md-5 text-center border-end-md">
        <div class="small text-muted text-uppercase" style="letter-spacing:.05em">Wersja robocza</div>
        <div class="fw-bold text-primary" style="font-size:2.4rem;line-height:1.1">v<?= h($ver['main']) ?></div>
        <div class="small text-muted">min_version.txt — ustawiana ręcznie</div>
        <div class="small font-monospace text-secondary mt-1" title="Wersja tego commita: litera rośnie automatycznie od ostatniego bumpa, znacznik czasu to data commitu">
          <i class="bi bi-hash me-1"></i><?= h($build['full']) ?>
        </div>
      </div>
      <div class="col-md-2 text-center text-muted d-none d-md-block">
        <i class="bi bi-arrow-left-right fs-3"></i>
      </div>
      <div class="col-md-5 text-center">
        <div class="small text-muted text-uppercase" style="letter-spacing:.05em">Wydanie (git tag)</div>
        <?php if ($tag['name']): ?>
        <div class="fw-bold <?= $tag['synced'] ? 'text-success' : 'text-warning-emphasis' ?>" style="font-size:2.4rem;line-height:1.1"><?= h($tag['name']) ?></div>
        <?php if ($tag['synced']): ?>
        <div class="small text-success"><i class="bi bi-check-circle me-1"></i>zgodny z bieżącym commitem</div>
        <?php else: ?>
        <div class="small text-warning-emphasis"><i class="bi bi-exclamation-triangle me-1"></i><?= (int)$tag['commits_since'] ?> commit(ów) po tagu</div>
        <?php endif; ?>
        <?php else: ?>
        <div class="fw-bold text-muted" style="font-size:2.4rem;line-height:1.1">—</div>
        <div class="small text-muted">żaden bump jeszcze nie otagował repo</div>
        <?php endif; ?>
      </div>
    </div>
  </div>
  <div class="card-footer bg-light py-2 d-flex gap-4 flex-wrap small text-muted">
    <span title="Aktualny commit HEAD"><i class="bi bi-code-square me-1"></i>commit <code><?= h($ver['hash']) ?></code></span>
    <?php if ($tag['name']): ?>
    <span title="Commit, na który wskazuje tag"><i class="bi bi-tag me-1"></i>tag → <code><?= h($tag['hash']) ?></code></span>
    <?php endif; ?>
    <span><i class="bi bi-clock me-1"></i><?= h($ver['date'] ?: '—') ?></span>
    <span><i class="bi bi-diagram-2 me-1"></i>gałąź <code><?= h($ver['branch']) ?></code></span>
  </div>
</div>

<style>.border-end-md{border-inline-end:0}@media(min-width:768px){.border-end-md{border-inline-end:1px solid var(--bs-border-color)}}</style>

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
