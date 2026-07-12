<?php
/**
 * admin/cloudflare_m365.php — Autokonfigurator DNS dla Microsoft 365.
 *
 * Porównuje strefę Cloudflare ze standardowym zestawem rekordów wymaganych przez
 * M365 (poczta, Autodiscover, Teams/Skype, rejestracja urządzeń) i pozwala jednym
 * kliknięciem utworzyć brakujące. Nigdy nie nadpisuje istniejących rekordów —
 * konflikty trzeba rozstrzygnąć ręcznie w zarządzaniu DNS.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/cloudflare.php';

require_role('admin');
require_module_enabled('cloudflare_enabled', 'Cloudflare DNS');
$PAGE_TITLE = 'Cloudflare DNS — autokonfigurator M365';

if (!cloudflare_configured()) {
    flash_set('warning', 'Najpierw skonfiguruj token API Cloudflare.');
    header('Location: cloudflare_settings.php'); exit;
}

$zone_id = trim($_GET['zone'] ?? $_POST['zone_id'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if ($zone_id === '') { flash_set('danger', 'Wybierz strefę.'); header('Location: cloudflare_m365.php'); exit; }
    try {
        $zone = cloudflare_zone($zone_id);
        if (!$zone) throw new RuntimeException('Nie znaleziono strefy.');
        $keys = array_keys($_POST['keys'] ?? []);
        $r = cloudflare_m365_apply($zone_id, $zone['name'], $keys);
        if ($r['created']) {
            log_system_action((int)(current_user()['id'] ?? 0), 'cloudflare_m365_apply',
                "Strefa {$zone['name']}: utworzono " . implode(', ', $r['created']));
            flash_set($r['errors'] ? 'warning' : 'success', 'Utworzono rekordów: ' . count($r['created'])
                . ($r['errors'] ? '. Błędy: ' . implode('; ', $r['errors']) : '.'));
        } elseif ($r['errors']) {
            flash_set('danger', 'Nie udało się utworzyć żadnego rekordu: ' . implode('; ', $r['errors']));
        } else {
            flash_set('info', 'Nie zaznaczono żadnych brakujących rekordów do utworzenia.');
        }
    } catch (\Throwable $e) {
        flash_set('danger', $e->getMessage());
    }
    header('Location: cloudflare_m365.php?zone=' . urlencode($zone_id)); exit;
}

$zones = [];
try { $zones = cloudflare_list_zones(); } catch (\Throwable $e) { flash_set('danger', $e->getMessage()); }
if ($zone_id === '' && $zones) $zone_id = (string)$zones[0]['id'];

$domain = '';
$plan   = [];
if ($zone_id !== '') {
    foreach ($zones as $z) { if ($z['id'] === $zone_id) { $domain = $z['name']; break; } }
    if ($domain !== '') {
        try { $plan = cloudflare_m365_plan($zone_id, $domain); }
        catch (\Throwable $e) { flash_set('danger', $e->getMessage()); }
    }
}

$status_badge = [
    'ok'       => ['Gotowe', 'success'],
    'missing'  => ['Brak', 'warning text-dark'],
    'conflict' => ['Konflikt', 'danger'],
];

function _cf_m365_value(array $tpl): string {
    if ($tpl['type'] === 'SRV') {
        $d = $tpl['data'];
        return $d['priority'] . ' ' . $d['weight'] . ' ' . $d['port'] . ' ' . $d['target'];
    }
    if ($tpl['type'] === 'MX') return $tpl['content'] . ' (priorytet ' . $tpl['priority'] . ')';
    return $tpl['content'];
}

function _cf_m365_existing_value(?array $e): string {
    if (!$e) return '—';
    if ($e['type'] === 'SRV') {
        $d = $e['data'] ?? [];
        return ($d['priority'] ?? '?') . ' ' . ($d['weight'] ?? '?') . ' ' . ($d['port'] ?? '?') . ' ' . ($d['target'] ?? '?');
    }
    if ($e['type'] === 'MX') return $e['content'] . ' (priorytet ' . ($e['priority'] ?? '?') . ')';
    return (string)($e['content'] ?? '');
}

include dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
  <h4 class="mb-0 fw-bold"><i class="bi bi-magic text-primary me-2"></i>Autokonfigurator M365</h4>
  <a href="cloudflare_dns.php?zone=<?= h($zone_id) ?>" class="btn btn-sm btn-outline-secondary ms-auto"><i class="bi bi-arrow-left me-1"></i>Zarządzanie DNS</a>
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

<div class="alert alert-info small">
  Zestaw standardowych rekordów Microsoft 365 dla domeny <strong><?= h($domain) ?></strong> (poczta Exchange Online,
  SPF, Autodiscover, Teams/Skype, rejestracja urządzeń). Rekordy wskazane jako „Brak" zostaną utworzone jednym
  kliknięciem — istniejące i konfliktowe nie są nigdy nadpisywane automatycznie.
</div>

<form method="post">
  <?= csrf_field() ?>
  <input type="hidden" name="zone_id" value="<?= h($zone_id) ?>">

  <div class="card border-0 shadow-sm mb-3">
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0" style="font-size:.85rem">
        <thead class="table-light">
          <tr><th></th><th>Cel</th><th>Typ</th><th>Nazwa</th><th>Wartość docelowa</th><th>Aktualnie</th><th>Status</th></tr>
        </thead>
        <tbody>
          <?php foreach ($plan as $row):
            $tpl = $row['tpl']; $sb = $status_badge[$row['status']]; ?>
          <tr>
            <td>
              <?php if ($row['status'] === 'missing'): ?>
              <input type="checkbox" class="form-check-input" name="keys[<?= h($tpl['key']) ?>]" value="1" checked>
              <?php else: ?>
              <input type="checkbox" class="form-check-input" disabled>
              <?php endif; ?>
            </td>
            <td><?= h($tpl['purpose']) ?></td>
            <td><span class="badge bg-secondary-subtle text-secondary border font-monospace"><?= h($tpl['type']) ?></span></td>
            <td class="font-monospace"><?= h($tpl['name']) ?></td>
            <td class="font-monospace text-truncate" style="max-width:260px" title="<?= h(_cf_m365_value($tpl)) ?>"><?= h(_cf_m365_value($tpl)) ?></td>
            <td class="font-monospace text-truncate text-muted" style="max-width:220px" title="<?= h(_cf_m365_existing_value($row['existing'])) ?>"><?= h(_cf_m365_existing_value($row['existing'])) ?></td>
            <td><span class="badge bg-<?= $sb[1] ?>"><?= $sb[0] ?></span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <button type="submit" class="btn btn-primary" <?= (!$plan || !array_filter($plan, fn($r) => $r['status'] === 'missing')) ? 'disabled' : '' ?>>
    <i class="bi bi-magic me-1"></i>Utwórz zaznaczone brakujące rekordy
  </button>
</form>

<?php endif; ?>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
