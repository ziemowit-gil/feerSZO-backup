<?php
/**
 * admin/short_urls.php — Zarządzanie krótkimi linkami (aliasami URL).
 *
 * Hardcoded aliasy (katalog, zadania, granty itp.) są w .htaccess.
 * Tu zarządzamy dynamicznymi aliasami z tabeli short_url_routes.
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

require_role('admin');
$PAGE_TITLE = 'Krótkie linki (aliasy URL)';

// Auto-migracja
try {
    db()->exec("CREATE TABLE IF NOT EXISTS short_url_routes (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        slug        TEXT    NOT NULL UNIQUE,
        target_url  TEXT    NOT NULL,
        label       TEXT    NOT NULL DEFAULT '',
        redirect_type TEXT  NOT NULL DEFAULT 'redirect',
        is_active   INTEGER NOT NULL DEFAULT 1,
        created_by  INTEGER REFERENCES users(id) ON DELETE SET NULL,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        hits        INTEGER NOT NULL DEFAULT 0
    )");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_surl_slug ON short_url_routes(slug)");
} catch (\Throwable $e) {}

$errors = [];

// ── POST ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['_action'] ?? '';

    if ($action === 'create') {
        $slug   = strtolower(trim(preg_replace('/[^a-z0-9_-]/i', '-', $_POST['slug'] ?? '')));
        $target = trim($_POST['target_url'] ?? '');
        $label  = trim($_POST['label'] ?? '');
        $rtype  = in_array($_POST['redirect_type']??'', ['redirect','internal']) ? $_POST['redirect_type'] : 'redirect';

        if (!$slug || !preg_match('/^[a-z0-9][a-z0-9_-]*$/', $slug)) {
            $errors[] = 'Alias musi zawierać tylko litery, cyfry i myślniki (bez spacji).';
        } elseif (in_array($slug, ['katalog','zadania','granty','dzialania','osoby','raporty','procedury','tyflo','crm','moj-panel','korespondencja','api','auth','admin','contracts','uploads','assets','directory','tasks','grants','actions','persons','reports','procedures','panel','karty30'])) {
            $errors[] = 'Alias "' . $slug . '" jest zarezerwowany przez system.';
        } elseif (!$target) {
            $errors[] = 'Docelowy URL jest wymagany.';
        }

        if (!$errors) {
            try {
                db_insert('short_url_routes', [
                    'slug'          => $slug,
                    'target_url'    => $target,
                    'label'         => $label ?: $slug,
                    'redirect_type' => $rtype,
                    'is_active'     => 1,
                    'created_by'    => (int)(current_user()['id'] ?? 0),
                    'created_at'    => date('Y-m-d H:i:s'),
                ]);
                flash_set('success', "Alias /{$slug} → {$target} utworzony.");
                header('Location: short_urls.php'); exit;
            } catch (\Throwable $e) {
                if (str_contains($e->getMessage(), 'UNIQUE')) {
                    $errors[] = 'Alias "' . $slug . '" już istnieje.';
                } else {
                    $errors[] = 'Błąd: ' . $e->getMessage();
                }
            }
        }
    }

    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        $r  = db_one("SELECT is_active FROM short_url_routes WHERE id=?", [$id]);
        if ($r) {
            db()->prepare("UPDATE short_url_routes SET is_active=? WHERE id=?")
                ->execute([$r['is_active'] ? 0 : 1, $id]);
        }
        header('Location: short_urls.php'); exit;
    }

    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare("DELETE FROM short_url_routes WHERE id=?")->execute([$id]);
        flash_set('success', 'Alias usunięty.');
        header('Location: short_urls.php'); exit;
    }

    if ($action === 'reset_hits') {
        $id = (int)($_POST['id'] ?? 0);
        db()->prepare("UPDATE short_url_routes SET hits=0 WHERE id=?")->execute([$id]);
        header('Location: short_urls.php'); exit;
    }
}

// ── Dane ─────────────────────────────────────────────────────────────────────
$routes = db_all("SELECT r.*, u.name AS creator FROM short_url_routes r LEFT JOIN users u ON u.id=r.created_by ORDER BY r.created_at DESC");

// Hardcoded (z .htaccess)
$hardcoded = [
    ['slug'=>'katalog',        'target'=>'/directory/',        'label'=>'Katalog współpracowników'],
    ['slug'=>'zadania',        'target'=>'/tasks/',            'label'=>'Tablica zadań'],
    ['slug'=>'granty',         'target'=>'/grants/',           'label'=>'Granty'],
    ['slug'=>'dzialania',      'target'=>'/actions/',          'label'=>'Działania'],
    ['slug'=>'osoby',          'target'=>'/persons/',          'label'=>'Strony umów'],
    ['slug'=>'raporty',        'target'=>'/reports/',          'label'=>'Raporty'],
    ['slug'=>'procedury',      'target'=>'/procedures/',       'label'=>'Procedury'],
    ['slug'=>'tyflo',          'target'=>'/karty30/',          'label'=>'Dydaktyka Karty 30 (d. TyfloKonsultacje)'],
    ['slug'=>'crm',            'target'=>'/crm/dashboard.php','label'=>'CRM'],
    ['slug'=>'moj-panel',      'target'=>'/panel/',            'label'=>'Panel użytkownika'],
    ['slug'=>'korespondencja', 'target'=>'/correspondence/',   'label'=>'Korespondencja'],
];

$base = APP_URL;

include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex align-items-center gap-3 mb-4">
  <div style="width:44px;height:44px;border-radius:11px;background:linear-gradient(135deg,#0F766E,#14B8A6);display:flex;align-items:center;justify-content:center;flex-shrink:0">
    <i class="bi bi-link-45deg text-white" style="font-size:1.4rem"></i>
  </div>
  <div>
    <h1 style="font-size:1.3rem;font-weight:800;margin:0 0 .1rem;color:#111827">Krótkie linki — aliasy URL</h1>
    <div style="font-size:.82rem;color:#6B7280">
      Przyjazne adresy URL dla modułów systemu.
      Np. <code><?= h($base) ?>/katalog</code> zamiast <code><?= h($base) ?>/directory/</code>
    </div>
  </div>
</div>

<?= flash_html() ?>

<?php if ($errors): ?>
<div class="alert alert-danger d-flex gap-2 mb-3">
  <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1"></i>
  <ul class="mb-0 ps-2"><?php foreach ($errors as $e) echo '<li>' . h($e) . '</li>'; ?></ul>
</div>
<?php endif; ?>

<!-- ══ HARDCODED (z .htaccess) ═════════════════════════════════════════════ -->
<div class="card shadow-sm mb-4">
  <div class="card-header fw-semibold d-flex align-items-center gap-2 py-2">
    <i class="bi bi-file-earmark-lock2 text-muted"></i>
    Wbudowane aliasy systemu
    <span class="badge bg-secondary ms-1"><?= count($hardcoded) ?></span>
    <span class="text-muted fw-normal small ms-auto">Zdefiniowane w <code>.htaccess</code> — zawsze aktywne</span>
  </div>
  <div class="table-responsive">
    <table class="table table-sm table-hover mb-0" style="font-size:.855rem">
      <thead class="table-light">
        <tr>
          <th>Alias</th>
          <th>Cel</th>
          <th>Moduł</th>
          <th>Typ</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($hardcoded as $h_row): ?>
        <tr>
          <td>
            <a href="<?= h($base . '/' . $h_row['slug']) ?>" target="_blank"
               class="fw-semibold text-decoration-none" style="color:#0F766E">
              <i class="bi bi-link-45deg me-1"></i><?= h($base . '/' . $h_row['slug']) ?>
            </a>
          </td>
          <td><code><?= h($h_row['target']) ?></code></td>
          <td class="text-muted"><?= h($h_row['label']) ?></td>
          <td><span class="badge bg-info bg-opacity-75 text-dark">internal rewrite</span></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ══ DYNAMICZNE (z DB) ══════════════════════════════════════════════════ -->
<div class="card shadow-sm mb-4">
  <div class="card-header fw-semibold d-flex align-items-center gap-2 py-2">
    <i class="bi bi-database text-muted"></i>
    Własne aliasy
    <span class="badge bg-primary ms-1"><?= count($routes) ?></span>
    <button class="btn btn-primary btn-sm ms-auto" data-bs-toggle="collapse" data-bs-target="#addForm">
      <i class="bi bi-plus-lg me-1"></i>Dodaj alias
    </button>
  </div>

  <!-- Formularz dodawania -->
  <div class="collapse" id="addForm">
    <div class="card-body border-bottom bg-light">
      <form method="post" class="row g-3 align-items-end">
        <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="create">

        <div class="col-md-3">
          <label class="form-label fw-semibold small">
            Alias <span class="text-danger">*</span>
          </label>
          <div class="input-group input-group-sm">
            <span class="input-group-text text-muted">/</span>
            <input type="text" name="slug" class="form-control"
                   placeholder="np. wolontariusze"
                   pattern="[a-z0-9][a-z0-9_\-]*"
                   required
                   aria-describedby="slug-hint">
          </div>
          <div id="slug-hint" class="form-text">Tylko litery, cyfry, myślnik</div>
        </div>

        <div class="col-md-4">
          <label class="form-label fw-semibold small">
            Docelowy URL <span class="text-danger">*</span>
          </label>
          <input type="text" name="target_url" class="form-control form-control-sm"
                 placeholder="np. /contracts/wolontariat/list.php lub https://..."
                 required>
          <div class="form-text">Ścieżka względna lub pełny URL</div>
        </div>

        <div class="col-md-2">
          <label class="form-label fw-semibold small">Etykieta</label>
          <input type="text" name="label" class="form-control form-control-sm"
                 placeholder="Opis aliasu">
        </div>

        <div class="col-md-2">
          <label class="form-label fw-semibold small">Typ</label>
          <select name="redirect_type" class="form-select form-select-sm">
            <option value="redirect" selected>301 Redirect</option>
            <option value="internal">Internal rewrite</option>
          </select>
          <div class="form-text">Redirect = URL zmienia się. Internal = URL zostaje.</div>
        </div>

        <div class="col-md-1">
          <button type="submit" class="btn btn-primary btn-sm w-100">
            <i class="bi bi-plus-lg"></i>
          </button>
        </div>
      </form>
    </div>
  </div>

  <?php if (!$routes): ?>
  <div class="card-body text-center py-4 text-muted">
    <i class="bi bi-link-45deg d-block mb-2 opacity-25" style="font-size:2rem"></i>
    Brak własnych aliasów. Kliknij „Dodaj alias", aby utworzyć pierwszy.
  </div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-sm table-hover mb-0 align-middle" style="font-size:.855rem">
      <thead class="table-light">
        <tr>
          <th>Alias</th>
          <th>Cel</th>
          <th>Etykieta</th>
          <th>Typ</th>
          <th class="text-center">Kliknięcia</th>
          <th class="text-center">Status</th>
          <th class="text-end">Akcje</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($routes as $r):
          $is_active = (bool)$r['is_active'];
          $slug_url  = $base . '/' . $r['slug'];
        ?>
        <tr class="<?= !$is_active ? 'opacity-50' : '' ?>">
          <td>
            <a href="<?= h($slug_url) ?>" target="_blank"
               class="fw-semibold text-decoration-none <?= $is_active ? 'text-primary' : 'text-muted' ?>">
              <i class="bi bi-link-45deg me-1"></i><?= h('/' . $r['slug']) ?>
            </a>
            <button class="btn btn-link btn-sm p-0 ms-1 text-muted"
                    onclick="navigator.clipboard.writeText('<?= h($slug_url) ?>').then(()=>this.innerHTML='<i class=\'bi bi-check-lg text-success\'></i>')"
                    title="Kopiuj link">
              <i class="bi bi-clipboard" style="font-size:.8rem"></i>
            </button>
          </td>
          <td>
            <code style="font-size:.78rem"><?= h($r['target_url']) ?></code>
          </td>
          <td class="text-muted"><?= h($r['label']) ?></td>
          <td>
            <?php if ($r['redirect_type'] === 'redirect'): ?>
            <span class="badge bg-primary bg-opacity-75 text-white" style="font-size:.68rem">301 Redirect</span>
            <?php else: ?>
            <span class="badge bg-teal text-white" style="font-size:.68rem;background:#0D9488">Internal</span>
            <?php endif; ?>
          </td>
          <td class="text-center">
            <span class="fw-semibold"><?= number_format((int)$r['hits']) ?></span>
            <?php if ($r['hits'] > 0): ?>
            <form method="post" class="d-inline ms-1">
              <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
              <input type="hidden" name="_action" value="reset_hits">
              <input type="hidden" name="id"      value="<?= (int)$r['id'] ?>">
              <button type="submit" class="btn btn-link btn-sm p-0 text-muted" title="Zeruj licznik">
                <i class="bi bi-arrow-counterclockwise" style="font-size:.75rem"></i>
              </button>
            </form>
            <?php endif; ?>
          </td>
          <td class="text-center">
            <form method="post" class="d-inline">
              <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
              <input type="hidden" name="_action" value="toggle">
              <input type="hidden" name="id"      value="<?= (int)$r['id'] ?>">
              <button type="submit" class="btn btn-sm <?= $is_active ? 'btn-success' : 'btn-outline-secondary' ?> py-0 px-2"
                      title="<?= $is_active ? 'Dezaktywuj' : 'Aktywuj' ?>">
                <i class="bi <?= $is_active ? 'bi-toggle-on' : 'bi-toggle-off' ?>"></i>
              </button>
            </form>
          </td>
          <td class="text-end">
            <form method="post" class="d-inline"
                  onsubmit="return confirm('Usunąć alias /<?= h(addslashes($r['slug'])) ?>?')">
              <input type="hidden" name="_csrf"   value="<?= csrf_token() ?>">
              <input type="hidden" name="_action" value="delete">
              <input type="hidden" name="id"      value="<?= (int)$r['id'] ?>">
              <button type="submit" class="btn btn-outline-danger btn-sm py-0 px-2"
                      aria-label="Usuń alias">
                <i class="bi bi-trash"></i>
              </button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<!-- Info -->
<div class="alert alert-light border d-flex gap-2 small">
  <i class="bi bi-info-circle-fill flex-shrink-0 mt-1 text-primary"></i>
  <div>
    <strong>Jak działają short linki?</strong><br>
    <strong>Wbudowane</strong> (.htaccess) — wewnętrzny rewrite na poziomie serwera Apache.
    URL w przeglądarce pozostaje krótki (np. <code>/katalog</code>) ale serwuje zawartość z <code>/directory/</code>.<br>
    <strong>Własne</strong> (DB, typ „301 Redirect") — przeglądarka zostaje przekierowana na docelowy URL.
    Typ „Internal" — URL pozostaje krótki, jak wbudowane.<br>
    Dla produkcji zalecane jest dodawanie popularnych aliasów do <code>.htaccess</code> (szybsze, bez PHP).
  </div>
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
