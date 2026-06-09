<?php
/**
 * admin/prod_check.php — GUI listy kontrolnej wdrożenia produkcyjnego
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/m365.php';
require_once dirname(__DIR__) . '/includes/prod_check_lib.php';

auth_start();
require_role('admin');

$PAGE_TITLE = 'Lista kontrolna wdrożenia';

// ── Uruchom sprawdzenia ───────────────────────────────────────────────────────
$results  = run_prod_checks(dirname(__DIR__));
$count    = prod_check_summary($results);
$checked_at = date('H:i:s');

$overall = 'ok';
if ($count['fail'] > 0)        $overall = 'fail';
elseif ($count['warn'] > 0)    $overall = 'warn';

// Pogrupuj po sekcji
$sections = [];
foreach ($results as $r) {
    $sections[$r['section']][] = $r;
}

// Ikony sekcji
$section_icons = [
    'PHP'           => 'bi-filetype-php',
    'Konfiguracja'  => 'bi-gear',
    'Baza danych'   => 'bi-database',
    'Pliki'         => 'bi-folder2',
    'Bezpieczeństwo'=> 'bi-shield-lock',
    'Moduły'        => 'bi-puzzle',
];

include dirname(__DIR__) . '/includes/header.php';
?>

<div class="container-fluid px-4 py-4" style="max-width:1060px">

<nav aria-label="breadcrumb" class="mb-3">
  <ol class="breadcrumb small">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/admin/">Panel admina</a></li>
    <li class="breadcrumb-item active">Lista kontrolna wdrożenia</li>
  </ol>
</nav>

<!-- ══ KARTA STATUSU OGÓLNEGO ════════════════════════════════════════════════ -->
<div class="card shadow mb-4 border-<?= $overall === 'fail' ? 'danger' : ($overall === 'warn' ? 'warning' : 'success') ?>">
<div class="card-body py-3">
  <div class="d-flex align-items-center gap-4 flex-wrap">

    <!-- Ikona statusu -->
    <div class="flex-shrink-0 text-center" style="width:3.5rem">
      <?php if ($overall === 'fail'): ?>
        <i class="bi bi-x-circle-fill text-danger" style="font-size:2.8rem"></i>
      <?php elseif ($overall === 'warn'): ?>
        <i class="bi bi-exclamation-triangle-fill text-warning" style="font-size:2.8rem"></i>
      <?php else: ?>
        <i class="bi bi-check-circle-fill text-success" style="font-size:2.8rem"></i>
      <?php endif; ?>
    </div>

    <!-- Opis -->
    <div class="flex-grow-1">
      <h5 class="mb-1 fw-bold">
        <?php if ($overall === 'fail'): ?>
          <span class="text-danger">System nie jest gotowy do wdrożenia</span>
        <?php elseif ($overall === 'warn'): ?>
          <span class="text-warning">Są ostrzeżenia — przejrzyj przed wdrożeniem</span>
        <?php else: ?>
          <span class="text-success">System gotowy do wdrożenia na produkcję</span>
        <?php endif; ?>
      </h5>
      <div class="d-flex gap-2 flex-wrap">
        <span class="badge bg-success-subtle text-success border border-success-subtle fs-6 px-3">
          <i class="bi bi-check2 me-1"></i><?= $count['ok'] ?> OK
        </span>
        <span class="badge bg-warning-subtle text-warning border border-warning-subtle fs-6 px-3">
          <i class="bi bi-exclamation-triangle me-1"></i><?= $count['warn'] ?> ostrzeżeń
        </span>
        <span class="badge bg-danger-subtle text-danger border border-danger-subtle fs-6 px-3">
          <i class="bi bi-x-circle me-1"></i><?= $count['fail'] ?> błędów
        </span>
        <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle fs-6 px-3">
          <i class="bi bi-dash-circle me-1"></i><?= $count['skip'] ?> pominięto
        </span>
      </div>
    </div>

    <!-- Przycisk odświeżania + czas -->
    <div class="text-end flex-shrink-0">
      <div class="text-muted small mb-2">
        <i class="bi bi-clock me-1"></i>Sprawdzono: <?= h($checked_at) ?>
      </div>
      <a href="<?= APP_URL ?>/admin/prod_check.php" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-clockwise me-1"></i>Sprawdź ponownie
      </a>
    </div>

  </div>
</div>
</div>

<!-- ══ SZYBKIE AKCJE (tylko gdy są błędy/ostrzeżenia) ══════════════════════ -->
<?php
$actions = [];
foreach ($results as $r) {
    if ($r['status'] !== 'ok' && $r['status'] !== 'skip' && $r['link']) {
        $domain = parse_url($r['link'], PHP_URL_PATH);
        $key = basename($domain);
        if (!isset($actions[$key])) {
            $actions[$key] = ['label' => $r['label'], 'url' => $r['link']];
        }
    }
}
if ($actions):
?>
<div class="card shadow-sm mb-4">
<div class="card-header fw-semibold small">
  <i class="bi bi-lightning-charge me-1 text-warning"></i>Szybkie akcje naprawcze
</div>
<div class="card-body d-flex gap-2 flex-wrap py-2">
  <?php foreach ($actions as $a): ?>
  <a href="<?= h($a['url']) ?>" class="btn btn-sm btn-outline-primary">
    <i class="bi bi-arrow-right-circle me-1"></i><?= h($a['label']) ?>
  </a>
  <?php endforeach; ?>
  <a href="https://portal.azure.com/" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary">
    <i class="bi bi-box-arrow-up-right me-1"></i>Azure Portal
  </a>
</div>
</div>
<?php endif; ?>

<!-- ══ SEKCJE ══════════════════════════════════════════════════════════════════ -->
<?php foreach ($sections as $section_name => $checks):
    // Status sekcji
    $sec_fail = count(array_filter($checks, fn($c) => $c['status'] === 'fail'));
    $sec_warn = count(array_filter($checks, fn($c) => $c['status'] === 'warn'));
    $sec_ok   = count(array_filter($checks, fn($c) => $c['status'] === 'ok'));
    $sec_status = $sec_fail ? 'fail' : ($sec_warn ? 'warn' : 'ok');
    $icon = $section_icons[$section_name] ?? 'bi-check2-circle';
?>
<div class="card shadow-sm mb-3">
<div class="card-header d-flex align-items-center justify-content-between py-2">
  <span class="fw-semibold">
    <i class="bi <?= h($icon) ?> me-2"></i><?= h($section_name) ?>
  </span>
  <div class="d-flex align-items-center gap-2">
    <?php if ($sec_fail): ?>
      <span class="badge bg-danger"><i class="bi bi-x me-1"></i><?= $sec_fail ?> błąd<?= $sec_fail > 1 ? 'y' : '' ?></span>
    <?php endif; ?>
    <?php if ($sec_warn): ?>
      <span class="badge bg-warning text-dark"><i class="bi bi-exclamation me-1"></i><?= $sec_warn ?> uwag<?= $sec_warn > 1 ? 'i' : 'a' ?></span>
    <?php endif; ?>
    <?php if ($sec_ok && !$sec_fail && !$sec_warn): ?>
      <span class="badge bg-success"><i class="bi bi-check2 me-1"></i>OK</span>
    <?php endif; ?>
  </div>
</div>
<div class="card-body p-0">
<table class="table table-sm align-middle mb-0" style="font-size:.875rem">
<tbody>
<?php foreach ($checks as $idx => $chk):
    $row_class = match($chk['status']) {
        'fail'  => 'table-danger',
        'warn'  => 'table-warning',
        'ok'    => '',
        default => '',
    };
    $icon_html = match($chk['status']) {
        'ok'    => '<i class="bi bi-check-circle-fill text-success"></i>',
        'warn'  => '<i class="bi bi-exclamation-triangle-fill text-warning"></i>',
        'fail'  => '<i class="bi bi-x-circle-fill text-danger"></i>',
        default => '<i class="bi bi-dash-circle text-secondary"></i>',
    };
    $badge = match($chk['status']) {
        'ok'    => '<span class="badge bg-success">OK</span>',
        'warn'  => '<span class="badge bg-warning text-dark">Uwaga</span>',
        'fail'  => '<span class="badge bg-danger">Błąd</span>',
        default => '<span class="badge bg-secondary">Pominięto</span>',
    };
    $has_detail = !empty($chk['detail']);
    $detail_id = 'detail_' . $section_name . '_' . $idx;
    $detail_id = preg_replace('/\W+/', '_', $detail_id);
?>
<tr class="<?= $row_class ?>">
  <td class="text-center ps-3" style="width:2rem"><?= $icon_html ?></td>
  <td class="fw-medium" style="width:13rem"><?= h($chk['label']) ?></td>
  <td>
    <?php if ($has_detail): ?>
    <?php
      $lines = explode("\n", $chk['detail']);
      $first_line = array_shift($lines);
    ?>
    <span class="text-muted"><?= h($first_line) ?></span>
    <?php if ($lines): ?>
    <button class="btn btn-link btn-sm p-0 ms-1 text-secondary" style="font-size:.75rem"
            data-bs-toggle="collapse" data-bs-target="#<?= $detail_id ?>" aria-expanded="false">
      <i class="bi bi-chevron-down"></i>
    </button>
    <div class="collapse mt-1" id="<?= $detail_id ?>">
      <div class="bg-light rounded p-2 font-monospace small text-break"
           style="white-space:pre-wrap;font-size:.78rem"><?= h(implode("\n", $lines)) ?></div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </td>
  <td class="text-end pe-3" style="width:9rem">
    <?= $badge ?>
    <?php if ($chk['link']): ?>
    <a href="<?= h($chk['link']) ?>" class="btn btn-link btn-sm p-0 ms-1 text-secondary"
       title="Przejdź do ustawień" <?= str_starts_with($chk['link'], 'http') && !str_starts_with($chk['link'], APP_URL) ? 'target="_blank" rel="noopener"' : '' ?>>
      <i class="bi bi-arrow-right-circle"></i>
    </a>
    <?php endif; ?>
  </td>
</tr>
<?php endforeach; ?>
</tbody>
</table>
</div>
</div>
<?php endforeach; ?>

<!-- ══ INSTRUKCJA MIGRACJI MYSQL (jeśli SQLite) ════════════════════════════ -->
<?php if ((defined('DB_TYPE') ? DB_TYPE : 'sqlite') === 'sqlite'): ?>
<div class="card shadow-sm mb-4 border-0 bg-light">
<div class="card-body d-flex align-items-start gap-3">
  <i class="bi bi-database-gear text-primary mt-1" style="font-size:1.4rem"></i>
  <div class="small">
    <div class="fw-semibold mb-1">Przejście na MySQL / MariaDB</div>
    <p class="text-muted mb-1">
      Aktualnie używasz SQLite. Jeśli planujesz przejście na MySQL przed wdrożeniem produkcyjnym, użyj narzędzia migracji:
    </p>
    <code class="d-block bg-dark text-light rounded px-2 py-1 mb-1" style="font-size:.78rem">
      php cli/migrate_to_mysql.php --db=feer --user=root --dry-run
    </code>
    <code class="d-block bg-dark text-light rounded px-2 py-1 mb-1" style="font-size:.78rem">
      php cli/migrate_to_mysql.php --db=feer --user=root --pass=haslo
    </code>
    <div class="text-muted" style="font-size:.78rem">
      Następnie zaktualizuj <code>config.local.php</code>: ustaw <code>DB_TYPE=mysql</code> i dane połączenia.
    </div>
  </div>
</div>
</div>
<?php endif; ?>

<!-- ══ POLECENIA CLI ══════════════════════════════════════════════════════════ -->
<div class="card shadow-sm mb-4">
<div class="card-header fw-semibold small">
  <i class="bi bi-terminal me-1"></i>Narzędzia CLI
</div>
<div class="card-body small">
  <div class="row g-3">
    <div class="col-md-6">
      <div class="fw-semibold mb-1">Ten raport z linii poleceń:</div>
      <code class="d-block bg-dark text-light rounded px-2 py-1 mb-2" style="font-size:.78rem">
        php cli/prod_check.php
      </code>
      <code class="d-block bg-dark text-light rounded px-2 py-1 mb-2" style="font-size:.78rem">
        php cli/prod_check.php --strict --json
      </code>
    </div>
    <div class="col-md-6">
      <div class="fw-semibold mb-1">Czyszczenie danych testowych:</div>
      <code class="d-block bg-dark text-light rounded px-2 py-1 mb-2" style="font-size:.78rem">
        php cli/clean_for_prod.php
      </code>
      <div class="fw-semibold mb-1">Reset hasła admina:</div>
      <code class="d-block bg-dark text-light rounded px-2 py-1" style="font-size:.78rem">
        php cli/passwd.php
      </code>
    </div>
  </div>
</div>
</div>

</div><!-- /container -->

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
