<?php
/**
 * modules/gdpr_clauses/index.php — lista klauzul RODO (panel admina/edytora).
 * Logika i format treści: modules/gdpr_clauses/logic/gdpr_clauses.php.
 */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once __DIR__ . '/logic/gdpr_clauses.php';

require_role('admin', 'editor');
$svc = new GdprClauseService();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'delete') {
    csrf_check();
    $svc->deleteClause((int)($_POST['id'] ?? 0));
    flash_set('success', 'Klauzula usunięta (razem z historią wersji).');
    header('Location: ' . APP_URL . '/modules/gdpr_clauses/index.php');
    exit;
}

$clauses = $svc->listClauses();
$vars    = $svc->listVariables();

$PAGE_TITLE = 'Klauzule RODO';
include dirname(__DIR__, 2) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h4 class="mb-0"><i class="bi bi-shield-lock text-primary"></i> Klauzule RODO</h4>
  <div class="d-flex gap-2">
    <a href="<?= APP_URL ?>/modules/gdpr_clauses/variables.php" class="btn btn-sm btn-outline-secondary">
      <i class="bi bi-braces me-1"></i>Zmienne globalne
    </a>
    <a href="<?= APP_URL ?>/modules/gdpr_clauses/edit.php" class="btn btn-sm btn-primary">
      <i class="bi bi-plus-lg me-1"></i>Nowa klauzula
    </a>
  </div>
</div>

<?= flash_html() ?>

<div class="row g-3">
  <div class="col-lg-8">
    <div class="card shadow-sm">
      <?php if (!$clauses): ?>
        <div class="card-body text-center text-muted py-5">
          <i class="bi bi-shield-lock fs-1 d-block mb-2 opacity-25"></i>
          Brak klauzul. <a href="<?= APP_URL ?>/modules/gdpr_clauses/edit.php">Dodaj pierwszą</a>.
        </div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
          <thead class="table-light">
            <tr><th>Tytuł</th><th>Adres publiczny</th><th>Status</th><th>Zmieniono</th><th class="text-end">Akcje</th></tr>
          </thead>
          <tbody>
          <?php foreach ($clauses as $c): $url = gdpr_clauses_public_url($c['slug'], false, $c['lang']);
                $path = '/klauzula/' . $c['slug'] . ($c['lang'] !== GDPR_DEFAULT_LANG ? '/' . $c['lang'] : ''); ?>
            <tr>
              <td>
                <span class="badge bg-light text-dark border text-uppercase me-1" title="<?= h(GDPR_LANGS[$c['lang']] ?? $c['lang']) ?>"><?= h($c['lang']) ?></span>
                <a href="<?= APP_URL ?>/modules/gdpr_clauses/edit.php?id=<?= (int)$c['id'] ?>" class="fw-semibold text-decoration-none"><?= h($c['tytul']) ?></a>
                <?php if ((int)$c['versions'] > 0): ?>
                  <span class="badge bg-light text-muted border ms-1" title="Poprzednie wersje w historii"><?= (int)$c['versions'] ?> wer.</span>
                <?php endif; ?>
              </td>
              <td class="small">
                <?php if ((int)$c['is_published'] === 1): ?>
                  <a href="<?= h($url) ?>" target="_blank" rel="noopener"><code><?= h($path) ?></code> <i class="bi bi-box-arrow-up-right"></i></a>
                <?php else: ?>
                  <code class="text-muted"><?= h($path) ?></code>
                <?php endif; ?>
              </td>
              <td>
                <?= (int)$c['is_published'] === 1
                    ? '<span class="badge bg-success">opublikowana</span>'
                    : '<span class="badge bg-secondary">szkic</span>' ?>
              </td>
              <td class="small text-muted text-nowrap"><?= $c['updated_at'] ? h(date('d.m.Y H:i', strtotime($c['updated_at']))) : '—' ?></td>
              <td class="text-end text-nowrap">
                <a href="<?= APP_URL ?>/modules/gdpr_clauses/edit.php?id=<?= (int)$c['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edytuj"><i class="bi bi-pencil"></i></a>
                <form method="post" class="d-inline" onsubmit="return confirm('Usunąć klauzulę „<?= h(addslashes($c['tytul'])) ?>” wraz z historią? Osadzenia na innych stronach przestaną działać.');">
                  <?= csrf_field() ?>
                  <input type="hidden" name="_action" value="delete">
                  <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                  <button class="btn btn-sm btn-outline-danger" title="Usuń"><i class="bi bi-trash"></i></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="card shadow-sm">
      <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span class="fw-semibold small"><i class="bi bi-braces me-1"></i>Zmienne globalne</span>
        <a href="<?= APP_URL ?>/modules/gdpr_clauses/variables.php" class="small">Edytuj</a>
      </div>
      <ul class="list-group list-group-flush small">
        <?php foreach ($vars as $v): ?>
          <li class="list-group-item">
            <code>{{<?= h($v['key_']) ?>}}</code>
            <div class="<?= $v['value'] === '' ? 'text-danger' : 'text-muted' ?> text-truncate">
              <?= $v['value'] === '' ? 'brak wartości' : h($v['value']) ?>
            </div>
          </li>
        <?php endforeach; ?>
      </ul>
      <div class="card-footer bg-white small text-muted">
        Zmiana wartości od razu obowiązuje we wszystkich klauzulach — także osadzonych na innych stronach.
      </div>
    </div>
  </div>
</div>

<?php include dirname(__DIR__, 2) . '/includes/footer.php'; ?>
