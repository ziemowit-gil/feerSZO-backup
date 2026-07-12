<?php
/**
 * admin/cloudflare_dns.php — Wizualne zarządzanie rekordami DNS w Cloudflare.
 *
 * Wybór strefy (domeny) widocznej dla skonfigurowanego tokenu API, podgląd i
 * edycja rekordów DNS (A/AAAA/CNAME/TXT/MX/NS/SRV/CAA) bez wychodzenia z panelu.
 * Operacje wykonywane od razu na koncie Cloudflare (brak trybu „szkicu").
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/cloudflare.php';

require_role('admin');
require_module_enabled('cloudflare_enabled', 'Cloudflare DNS');
$PAGE_TITLE = 'Cloudflare DNS';

if (!cloudflare_configured()) {
    flash_set('warning', 'Najpierw skonfiguruj token API Cloudflare.');
    header('Location: cloudflare_settings.php'); exit;
}

function _cf_record_payload(array $post): array {
    $type = strtoupper(trim($post['type'] ?? ''));
    if (!in_array($type, CLOUDFLARE_DNS_TYPES, true)) throw new RuntimeException('Nieprawidłowy typ rekordu.');
    $name = trim($post['name'] ?? '');
    if ($name === '') throw new RuntimeException('Nazwa jest wymagana.');
    $content = trim($post['content'] ?? '');
    if ($content === '') throw new RuntimeException('Treść rekordu jest wymagana.');
    $ttl = (int)($post['ttl'] ?? 1);
    if ($ttl !== 1 && $ttl < 60) $ttl = 60;

    $data = ['type' => $type, 'name' => $name, 'content' => $content, 'ttl' => $ttl];
    if (in_array($type, CLOUDFLARE_PROXIABLE_TYPES, true)) $data['proxied'] = !empty($post['proxied']);
    if (in_array($type, CLOUDFLARE_PRIORITY_TYPES, true))  $data['priority'] = (int)($post['priority'] ?? 10);
    return $data;
}

$zone_id = trim($_GET['zone'] ?? $_POST['zone_id'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $uid    = (int)(current_user()['id'] ?? 0);

    try {
        if ($action === 'add') {
            if ($zone_id === '') throw new RuntimeException('Wybierz strefę.');
            $data = _cf_record_payload($_POST);
            cloudflare_create_dns_record($zone_id, $data);
            log_system_action($uid, 'cloudflare_dns_create', "Strefa {$zone_id}: {$data['type']} {$data['name']} -> {$data['content']}");
            flash_set('success', 'Rekord DNS dodany.');
        } elseif ($action === 'update') {
            $record_id = trim($_POST['record_id'] ?? '');
            if ($zone_id === '' || $record_id === '') throw new RuntimeException('Brak identyfikatora rekordu.');
            $data = _cf_record_payload($_POST);
            cloudflare_update_dns_record($zone_id, $record_id, $data);
            log_system_action($uid, 'cloudflare_dns_update', "Strefa {$zone_id}, rekord {$record_id}: {$data['type']} {$data['name']} -> {$data['content']}");
            flash_set('success', 'Rekord DNS zaktualizowany.');
        } elseif ($action === 'delete') {
            $record_id = trim($_POST['record_id'] ?? '');
            if ($zone_id === '' || $record_id === '') throw new RuntimeException('Brak identyfikatora rekordu.');
            cloudflare_delete_dns_record($zone_id, $record_id);
            log_system_action($uid, 'cloudflare_dns_delete', "Strefa {$zone_id}, rekord {$record_id} usunięty");
            flash_set('success', 'Rekord DNS usunięty.');
        }
    } catch (\Throwable $e) {
        flash_set('danger', $e->getMessage());
    }
    header('Location: cloudflare_dns.php?zone=' . urlencode($zone_id)); exit;
}

$zones = [];
try { $zones = cloudflare_list_zones(); } catch (\Throwable $e) { flash_set('danger', $e->getMessage()); }

if ($zone_id === '' && $zones) $zone_id = (string)$zones[0]['id'];

$records = [];
if ($zone_id !== '') {
    try { $records = cloudflare_list_dns_records($zone_id); }
    catch (\Throwable $e) { flash_set('danger', $e->getMessage()); }
}

include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
  <h4 class="mb-0 fw-bold"><i class="bi bi-globe2 text-primary me-2"></i>Cloudflare DNS</h4>
  <a href="cloudflare_settings.php" class="btn btn-sm btn-outline-secondary ms-auto"><i class="bi bi-gear me-1"></i>Ustawienia</a>
</div>

<?= flash_html() ?>

<?php if (!$zones): ?>
<div class="alert alert-warning">Brak stref widocznych dla tokenu API. Sprawdź, czy token ma uprawnienie <code>Zone:Read</code> dla wybranych domen.</div>
<?php else: ?>

<div class="card border-0 shadow-sm mb-3">
  <div class="card-body py-2">
    <form method="get" class="d-flex align-items-center gap-2 flex-wrap">
      <label class="form-label fw-semibold mb-0" for="cf_zone">Strefa</label>
      <select class="form-select form-select-sm" style="max-width:320px" name="zone" id="cf_zone" onchange="this.form.submit()">
        <?php foreach ($zones as $z): ?>
        <option value="<?= h($z['id']) ?>" <?= $z['id'] === $zone_id ? 'selected' : '' ?>><?= h($z['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </form>
  </div>
</div>

<script>
function cfTypeChanged(ctx) {
  var sel = document.getElementById('cf-type-' + ctx);
  if (!sel) return;
  var type = sel.value;
  var prio = document.getElementById('cf-prio-' + ctx);
  var prox = document.getElementById('cf-proxied-' + ctx);
  if (prio) prio.style.display = (type === 'MX' || type === 'SRV') ? '' : 'none';
  if (prox) prox.style.display = (type === 'A' || type === 'AAAA' || type === 'CNAME') ? '' : 'none';
}
</script>

<div class="card border-0 shadow-sm mb-4">
  <div class="card-header d-flex align-items-center fw-semibold">
    <span>Rekordy DNS <span class="badge bg-secondary ms-1"><?= count($records) ?></span></span>
    <button type="button" class="btn btn-sm btn-primary ms-auto" data-bs-toggle="modal" data-bs-target="#cf-add-modal">
      <i class="bi bi-plus-circle me-1"></i>Dodaj rekord
    </button>
  </div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0" style="font-size:.85rem">
      <thead class="table-light">
        <tr><th>Typ</th><th>Nazwa</th><th>Treść</th><th>TTL</th><th>Proxy</th><th>Priorytet</th><th></th></tr>
      </thead>
      <tbody>
        <?php if (!$records): ?>
        <tr><td colspan="7" class="text-center text-muted py-3">Brak rekordów DNS w tej strefie.</td></tr>
        <?php endif; ?>
        <?php foreach ($records as $r): ?>
        <tr>
          <td><span class="badge bg-secondary-subtle text-secondary border font-monospace"><?= h($r['type']) ?></span></td>
          <td class="font-monospace"><?= h($r['name']) ?></td>
          <td class="font-monospace text-truncate" style="max-width:280px" title="<?= h($r['content']) ?>"><?= h($r['content']) ?></td>
          <td class="text-muted"><?= (int)$r['ttl'] === 1 ? 'Auto' : (int)$r['ttl'] ?></td>
          <td>
            <?php if (in_array($r['type'], CLOUDFLARE_PROXIABLE_TYPES, true)): ?>
              <?= !empty($r['proxied']) ? '<i class="bi bi-cloud-fill text-warning" title="Proxy Cloudflare włączone"></i>' : '<i class="bi bi-cloud text-muted" title="Tylko DNS"></i>' ?>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td class="text-muted"><?= isset($r['priority']) ? (int)$r['priority'] : '—' ?></td>
          <td class="d-flex gap-1">
            <button type="button" class="btn btn-xs btn-outline-secondary" style="font-size:.7rem;padding:2px 8px"
                    data-bs-toggle="modal" data-bs-target="#cf-edit-<?= h($r['id']) ?>">
              <i class="bi bi-pencil"></i>
            </button>
            <form method="post" onsubmit="return confirm('Usunąć rekord <?= h(addslashes($r['type'] . ' ' . $r['name'])) ?>?')">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="zone_id" value="<?= h($zone_id) ?>">
              <input type="hidden" name="record_id" value="<?= h($r['id']) ?>">
              <button class="btn btn-xs btn-outline-danger" style="font-size:.7rem;padding:2px 8px"><i class="bi bi-trash"></i></button>
            </form>
          </td>
        </tr>

        <!-- Edit modal -->
        <div class="modal fade" id="cf-edit-<?= h($r['id']) ?>" tabindex="-1">
          <div class="modal-dialog">
            <form method="post" class="modal-content">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="update">
              <input type="hidden" name="zone_id" value="<?= h($zone_id) ?>">
              <input type="hidden" name="record_id" value="<?= h($r['id']) ?>">
              <div class="modal-header py-2">
                <h6 class="modal-title">Edytuj rekord: <?= h($r['type']) ?> <?= h($r['name']) ?></h6>
                <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal"></button>
              </div>
              <div class="modal-body">
                <div class="mb-2">
                  <label class="form-label small mb-1">Typ</label>
                  <select class="form-select form-select-sm" name="type" id="cf-type-edit<?= h($r['id']) ?>" onchange="cfTypeChanged('edit<?= h($r['id']) ?>')">
                    <?php foreach (CLOUDFLARE_DNS_TYPES as $t): ?>
                    <option value="<?= $t ?>" <?= $r['type'] === $t ? 'selected' : '' ?>><?= $t ?></option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="mb-2">
                  <label class="form-label small mb-1">Nazwa</label>
                  <input type="text" name="name" class="form-control form-control-sm font-monospace" value="<?= h($r['name']) ?>" required>
                </div>
                <div class="mb-2">
                  <label class="form-label small mb-1">Treść</label>
                  <input type="text" name="content" class="form-control form-control-sm font-monospace" value="<?= h($r['content']) ?>" required>
                </div>
                <div class="mb-2">
                  <label class="form-label small mb-1">TTL (sekundy, 1 = automatyczny)</label>
                  <input type="number" name="ttl" class="form-control form-control-sm" value="<?= (int)$r['ttl'] ?>" min="1">
                </div>
                <div class="mb-2" id="cf-prio-edit<?= h($r['id']) ?>" style="display:<?= in_array($r['type'], CLOUDFLARE_PRIORITY_TYPES, true) ? '' : 'none' ?>">
                  <label class="form-label small mb-1">Priorytet</label>
                  <input type="number" name="priority" class="form-control form-control-sm" value="<?= (int)($r['priority'] ?? 10) ?>" min="0">
                </div>
                <div class="form-check form-switch" id="cf-proxied-edit<?= h($r['id']) ?>" style="display:<?= in_array($r['type'], CLOUDFLARE_PROXIABLE_TYPES, true) ? '' : 'none' ?>">
                  <input class="form-check-input" type="checkbox" name="proxied" id="cf-px-<?= h($r['id']) ?>" <?= !empty($r['proxied']) ? 'checked' : '' ?>>
                  <label class="form-check-label" for="cf-px-<?= h($r['id']) ?>">Proxy Cloudflare (chmurka)</label>
                </div>
              </div>
              <div class="modal-footer py-2">
                <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Anuluj</button>
                <button type="submit" class="btn btn-sm btn-primary">Zapisz</button>
              </div>
            </form>
          </div>
        </div>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- Add modal -->
<div class="modal fade" id="cf-add-modal" tabindex="-1">
  <div class="modal-dialog">
    <form method="post" class="modal-content">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add">
      <input type="hidden" name="zone_id" value="<?= h($zone_id) ?>">
      <div class="modal-header py-2">
        <h6 class="modal-title">Dodaj rekord DNS</h6>
        <button type="button" class="btn-close btn-sm" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="mb-2">
          <label class="form-label small mb-1">Typ</label>
          <select class="form-select form-select-sm" name="type" id="cf-type-add" onchange="cfTypeChanged('add')">
            <?php foreach (CLOUDFLARE_DNS_TYPES as $t): ?>
            <option value="<?= $t ?>"><?= $t ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-2">
          <label class="form-label small mb-1">Nazwa</label>
          <input type="text" name="name" class="form-control form-control-sm font-monospace" placeholder="np. www.example.org lub example.org" required>
        </div>
        <div class="mb-2">
          <label class="form-label small mb-1">Treść</label>
          <input type="text" name="content" class="form-control form-control-sm font-monospace" placeholder="np. 203.0.113.10" required>
        </div>
        <div class="mb-2">
          <label class="form-label small mb-1">TTL (sekundy, 1 = automatyczny)</label>
          <input type="number" name="ttl" class="form-control form-control-sm" value="1" min="1">
        </div>
        <div class="mb-2" id="cf-prio-add" style="display:none">
          <label class="form-label small mb-1">Priorytet</label>
          <input type="number" name="priority" class="form-control form-control-sm" value="10" min="0">
        </div>
        <div class="form-check form-switch" id="cf-proxied-add">
          <input class="form-check-input" type="checkbox" name="proxied" id="cf-px-add" checked>
          <label class="form-check-label" for="cf-px-add">Proxy Cloudflare (chmurka)</label>
        </div>
      </div>
      <div class="modal-footer py-2">
        <button type="button" class="btn btn-sm btn-secondary" data-bs-dismiss="modal">Anuluj</button>
        <button type="submit" class="btn btn-sm btn-primary">Dodaj</button>
      </div>
    </form>
  </div>
</div>

<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
