<?php
/**
 * admin/module_perms.php — Widoczność modułów per rola w przełączniku modułów.
 *
 * Tabela msw_role_perms (role, module_key, visible):
 *   brak wiersza → domyślnie widoczny (jeśli moduł włączony)
 *   visible=0    → ukryty dla tej roli
 * Admin zawsze widzi wszystko — kolumna tylko informacyjna.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

require_role('admin');
$PAGE_TITLE = 'Widoczność modułów per rola';

/* ── Schema heal ──────────────────────────────────────────────────── */
try {
    db()->exec("CREATE TABLE IF NOT EXISTS msw_role_perms (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        role       TEXT NOT NULL,
        module_key TEXT NOT NULL,
        visible    INTEGER NOT NULL DEFAULT 1,
        UNIQUE(role, module_key)
    )");
} catch (\Throwable $e) {}

/* ── Lista modułów — z rejestru launchera (jedno źródło prawdy) ───── */
require_once dirname(__DIR__) . '/modules/launcher/logic/registry.php';
$ALL_MODS = array_map(fn($m) => ['key'=>$m['key'], 'label'=>$m['label'], 'icon'=>$m['icon'], 'mc'=>$m['mc'], 'sec'=>$m['sec']], launcherCatalog());

/* ── Edytowalne role (admin zawsze widzi wszystko) ────────────────── */
$EDIT_ROLES = [
    'editor' => ['label'=>'Editor',  'color'=>'#2563eb'],
    'viewer' => ['label'=>'Viewer',  'color'=>'#16a34a'],
];

/* ── POST: zapis ──────────────────────────────────────────────────── */
$saved = false;
$err   = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        $posted = $_POST['perms'] ?? [];   // ['editor'=>['szo'=>'1', 'crm'=>'1', ...], ...]
        $stmt = db()->prepare(
            "INSERT INTO msw_role_perms (role, module_key, visible)
             VALUES (?, ?, ?)
             ON CONFLICT(role, module_key) DO UPDATE SET visible=excluded.visible"
        );
        foreach ($EDIT_ROLES as $role => $_) {
            foreach ($ALL_MODS as $mod) {
                $key = $mod['key'];
                $vis = isset($posted[$role][$key]) ? 1 : 0;
                $stmt->execute([$role, $key, $vis]);
            }
        }
        $saved = true;
        flash_set('success', 'Widoczność modułów zapisana.');
        header('Location: module_perms.php'); exit;
    } catch (\Throwable $e) {
        $err = 'Błąd zapisu: ' . $e->getMessage();
    }
}

/* ── Odczyt bieżącej konfiguracji ────────────────────────────────── */
$perms = [];   // ['editor']['tasks'] = 1/0
try {
    foreach (db_all("SELECT role, module_key, visible FROM msw_role_perms") as $r) {
        $perms[$r['role']][$r['module_key']] = (int)$r['visible'];
    }
} catch (\Throwable $e) {}

function msw_is_visible(array $perms, string $role, string $key): bool {
    if (!isset($perms[$role][$key])) return true;   // brak wpisu = domyślnie widoczny
    return (bool)$perms[$role][$key];
}

/* ── Liczby użytkowników per rola ────────────────────────────────── */
$user_counts = [];
try {
    foreach (db_all("SELECT role, COUNT(*) AS n FROM users WHERE is_active=1 GROUP BY role") as $r) {
        $user_counts[$r['role']] = (int)$r['n'];
    }
} catch (\Throwable $e) {}

include dirname(__DIR__) . '/includes/header.php';
?>

<style>
/* ── Tabela macierzy ──────────────────────────────────────────────── */
.mp-wrap { background:#fff; border-radius:14px; border:1px solid #e2e8f0; box-shadow:0 1px 4px rgba(16,24,40,.06); overflow:hidden; }
.mp-table { width:100%; border-collapse:collapse; }
.mp-table th, .mp-table td { padding:.6rem .85rem; border-bottom:1px solid #f1f5f9; vertical-align:middle; }
.mp-table th { font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.07em; color:#64748b; background:#f8fafc; }
.mp-table tr:last-child td { border-bottom:none; }
.mp-table tr:hover td { background:#fafbfc; }
.mp-mod-icon { width:30px; height:30px; border-radius:8px; display:inline-flex; align-items:center; justify-content:center; font-size:.85rem; margin-right:.5rem; flex-shrink:0; }
.mp-mod-label { font-weight:600; font-size:.9rem; color:#111827; }
.mp-col-role { text-align:center; min-width:110px; }
.mp-col-admin { text-align:center; min-width:110px; }
.mp-admin-badge { display:inline-flex; align-items:center; gap:.25rem; font-size:.72rem; color:#94a3b8; background:#f1f5f9; padding:.2rem .5rem; border-radius:999px; }

/* Toggle switch */
.mp-switch { position:relative; display:inline-flex; align-items:center; justify-content:center; }
.mp-switch input[type="checkbox"] { position:absolute; opacity:0; width:0; height:0; }
.mp-switch-track {
    display:block; width:42px; height:24px; border-radius:999px;
    background:#e2e8f0; transition:background .15s; cursor:pointer; position:relative;
}
.mp-switch input:checked + .mp-switch-track { background:var(--sw-color, #059669); }
.mp-switch-knob {
    position:absolute; top:3px; left:3px;
    width:18px; height:18px; border-radius:50%;
    background:#fff; box-shadow:0 1px 3px rgba(0,0,0,.18);
    transition:transform .15s; pointer-events:none;
}
.mp-switch input:checked ~ .mp-switch-knob { transform:translateX(18px); }
.mp-switch-track, .mp-switch-knob { display:block; }

/* Role header */
.mp-role-hd { display:flex; flex-direction:column; align-items:center; gap:.2rem; }
.mp-role-pill { font-size:.7rem; font-weight:700; padding:.15rem .5rem; border-radius:999px; }
.mp-role-count { font-size:.65rem; color:#94a3b8; }

/* Akcje masowe */
.mp-bulk-btn { font-size:.72rem; border:1px solid #e2e8f0; background:#f8fafc; color:#374151; padding:.18rem .55rem; border-radius:6px; cursor:pointer; }
.mp-bulk-btn:hover { background:#f1f5f9; }
</style>

<div class="d-flex align-items-center justify-content-between mb-4">
  <div>
    <h1 class="h4 fw-bold mb-1">Widoczność modułów per rola</h1>
    <p class="text-muted small mb-0">
      Kontroluj, które moduły są widoczne w przełączniku modułów dla poszczególnych ról.
      Admin zawsze widzi wszystko. Brak wpisu = widoczny (domyślnie).
    </p>
  </div>
  <a href="modules_settings.php" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-grid me-1"></i>Moduły systemowe
  </a>
</div>

<?php if ($err): ?>
<div class="alert alert-danger py-2"><?= h($err) ?></div>
<?php endif; ?>

<form method="post" id="mp-form">
  <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">

  <div class="mp-wrap mb-4">
    <table class="mp-table" role="grid" aria-label="Widoczność modułów per rola">
      <thead>
        <tr>
          <th style="min-width:180px">Moduł</th>

          <?php foreach ($EDIT_ROLES as $role => $rdata): ?>
          <th class="mp-col-role">
            <div class="mp-role-hd">
              <span class="mp-role-pill"
                    style="background:<?= h($rdata['color']) ?>22;color:<?= h($rdata['color']) ?>">
                <?= h($rdata['label']) ?>
              </span>
              <span class="mp-role-count">
                <?= (int)($user_counts[$role] ?? 0) ?> użytkownik(-ów)
              </span>
              <div class="d-flex gap-1 mt-1">
                <button type="button" class="mp-bulk-btn"
                        data-bulk-role="<?= h($role) ?>" data-bulk-val="1"
                        title="Zaznacz wszystko dla roli <?= h($rdata['label']) ?>">
                  Wszystkie
                </button>
                <button type="button" class="mp-bulk-btn"
                        data-bulk-role="<?= h($role) ?>" data-bulk-val="0"
                        title="Odznacz wszystko dla roli <?= h($rdata['label']) ?>">
                  Żadne
                </button>
              </div>
            </div>
          </th>
          <?php endforeach; ?>

          <th class="mp-col-admin">
            <div class="mp-role-hd">
              <span class="mp-role-pill" style="background:#1e293b22;color:#1e293b">Admin</span>
              <span class="mp-role-count">
                <?= (int)($user_counts['admin'] ?? 0) ?> użytkownik(-ów)
              </span>
              <span class="mp-role-count" style="font-style:italic">zawsze pełny</span>
            </div>
          </th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($ALL_MODS as $mod): ?>
        <tr>
          <td>
            <div class="d-flex align-items-center">
              <span class="mp-mod-icon" style="background:<?= h($mod['mc']) ?>22;color:<?= h($mod['mc']) ?>">
                <i class="bi <?= h($mod['icon']) ?>"></i>
              </span>
              <span class="mp-mod-label"><?= h($mod['label']) ?></span>
            </div>
          </td>

          <?php foreach ($EDIT_ROLES as $role => $rdata):
            $checked  = msw_is_visible($perms, $role, $mod['key']);
            $inp_id   = 'mp_' . $role . '_' . $mod['key'];
          ?>
          <td class="mp-col-role">
            <label class="mp-switch" for="<?= $inp_id ?>"
                   aria-label="<?= h($mod['label']) ?> widoczny dla <?= h($rdata['label']) ?>">
              <input type="checkbox"
                     id="<?= $inp_id ?>"
                     name="perms[<?= h($role) ?>][<?= h($mod['key']) ?>]"
                     value="1"
                     data-role="<?= h($role) ?>"
                     <?= $checked ? 'checked' : '' ?>>
              <span class="mp-switch-track" style="--sw-color:<?= h($rdata['color']) ?>"></span>
              <span class="mp-switch-knob"></span>
            </label>
          </td>
          <?php endforeach; ?>

          <!-- Admin — zawsze widoczny, niemodyfikowalny -->
          <td class="mp-col-admin">
            <span class="mp-admin-badge">
              <i class="bi bi-check-circle-fill"></i>zawsze
            </span>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="d-flex gap-2 mb-4">
    <button type="submit" class="btn btn-primary">
      <i class="bi bi-check-lg me-1"></i>Zapisz konfigurację
    </button>
    <a href="index.php" class="btn btn-outline-secondary">Anuluj</a>
    <button type="button" class="btn btn-outline-danger ms-auto" id="mp-reset-btn"
            title="Przywróć domyślne (wszyscy widzą wszystko)">
      <i class="bi bi-arrow-counterclockwise me-1"></i>Resetuj do domyślnych
    </button>
  </div>
</form>

<!-- Podgląd per rola -->
<div class="card border-0 shadow-sm mb-4">
  <div class="card-header fw-semibold bg-light border-bottom">
    <i class="bi bi-eye me-1"></i>Podgląd — co widzi każda rola
  </div>
  <div class="card-body p-0">
    <div class="d-flex border-bottom" style="gap:0">
      <?php foreach ($EDIT_ROLES as $role => $rdata): ?>
      <div class="flex-fill p-3 border-end" id="preview-<?= $role ?>">
        <div class="fw-semibold mb-2" style="color:<?= h($rdata['color']) ?>">
          <i class="bi bi-person-badge me-1"></i><?= h($rdata['label']) ?>
        </div>
        <div class="d-flex flex-wrap gap-1" id="preview-chips-<?= $role ?>">
          <?php foreach ($ALL_MODS as $mod):
            $vis = msw_is_visible($perms, $role, $mod['key']);
          ?>
          <span class="badge rounded-pill preview-badge preview-badge-<?= $role ?>-<?= h($mod['key']) ?>"
                style="background:<?= $vis ? h($mod['mc']).'22' : '#f1f5f9' ?>;
                       color:<?= $vis ? h($mod['mc']) : '#94a3b8' ?>;
                       border:1px solid <?= $vis ? h($mod['mc']).'44' : '#e2e8f0' ?>;
                       font-weight:600;font-size:.7rem;opacity:<?= $vis ? '1' : '.45' ?>;
                       text-decoration:<?= $vis ? 'none' : 'line-through' ?>">
            <i class="bi <?= h($mod['icon']) ?> me-1"></i><?= h($mod['label']) ?>
          </span>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; ?>

      <!-- Admin preview -->
      <div class="flex-fill p-3" id="preview-admin">
        <div class="fw-semibold mb-2" style="color:#1e293b">
          <i class="bi bi-person-badge me-1"></i>Admin
        </div>
        <div class="d-flex flex-wrap gap-1">
          <?php foreach ($ALL_MODS as $mod): ?>
          <span class="badge rounded-pill"
                style="background:<?= h($mod['mc']) ?>22;color:<?= h($mod['mc']) ?>;
                       border:1px solid <?= h($mod['mc']) ?>44;font-weight:600;font-size:.7rem">
            <i class="bi <?= h($mod['icon']) ?> me-1"></i><?= h($mod['label']) ?>
          </span>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
/* ── Toggles → live preview update ───────────────────────────────── */
var MOD_COLORS = <?= json_encode(array_combine(
    array_column($ALL_MODS, 'key'),
    array_column($ALL_MODS, 'mc')
), JSON_UNESCAPED_UNICODE) ?>;

document.querySelectorAll('.mp-switch input[type="checkbox"]').forEach(function(cb) {
    cb.addEventListener('change', function() {
        updatePreview(this.dataset.role, this.name.match(/\[(\w+)\]$/)?.[1], this.checked);
    });
});

function updatePreview(role, key, visible) {
    var badge = document.querySelector('.preview-badge-' + role + '-' + key);
    if (!badge) return;
    var mc = MOD_COLORS[key] || '#64748b';
    badge.style.background  = visible ? mc + '22' : '#f1f5f9';
    badge.style.color       = visible ? mc : '#94a3b8';
    badge.style.border      = '1px solid ' + (visible ? mc + '44' : '#e2e8f0');
    badge.style.opacity     = visible ? '1' : '.45';
    badge.style.textDecoration = visible ? 'none' : 'line-through';
}

/* ── Bulk select / deselect per rola ─────────────────────────────── */
document.querySelectorAll('[data-bulk-role]').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var role = this.dataset.bulkRole;
        var val  = this.dataset.bulkVal === '1';
        document.querySelectorAll('[data-role="' + role + '"]').forEach(function(cb) {
            if (cb.checked !== val) {
                cb.checked = val;
                var key = cb.name.match(/\[(\w+)\]$/)?.[1];
                updatePreview(role, key, val);
            }
        });
    });
});

/* ── Reset do domyślnych ──────────────────────────────────────────── */
document.getElementById('mp-reset-btn').addEventListener('click', function() {
    if (!confirm('Przywrócić domyślne? Wszystkie role będą widzieć wszystkie moduły.')) return;
    document.querySelectorAll('.mp-switch input[type="checkbox"]').forEach(function(cb) {
        if (!cb.checked) {
            cb.checked = true;
            var role = cb.dataset.role;
            var key  = cb.name.match(/\[(\w+)\]$/)?.[1];
            updatePreview(role, key, true);
        }
    });
});
</script>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
