<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/it_helpers.php';

require_role('admin', 'editor');
$PAGE_TITLE = 'Dostępy i Infrastruktura IT';

// ── Statystyki ───────────────────────────────────────────────────────────────
$stats = [];
$services = db_all("SELECT * FROM it_services WHERE is_active=1 ORDER BY sort_order");
foreach ($services as $s) {
    $c_active   = (int)(db_one("SELECT COUNT(*) AS c FROM it_accounts WHERE service_id=? AND is_active=1",   [$s['id']])['c'] ?? 0);
    $c_inactive = (int)(db_one("SELECT COUNT(*) AS c FROM it_accounts WHERE service_id=? AND is_active=0", [$s['id']])['c'] ?? 0);
    $stats[$s['slug']] = ['service' => $s, 'active' => $c_active, 'inactive' => $c_inactive];
}

$recent_accounts = db_all(
    "SELECT a.*, s.name AS service_name, s.icon AS service_icon, s.color AS service_color, s.slug AS service_slug,
            u.name AS created_by_name
     FROM it_accounts a
     JOIN it_services s ON s.id = a.service_id
     LEFT JOIN users u ON u.id = a.created_by
     ORDER BY a.updated_at DESC LIMIT 12"
);

$recent_passwords = db_all(
    "SELECT p.*, s.name AS service_name, s.icon AS service_icon, s.color AS service_color,
            u.name AS issued_by_name
     FROM it_service_passwords p
     JOIN it_services s ON s.id = p.service_id
     LEFT JOIN users u ON u.id = p.issued_by
     ORDER BY p.issued_at DESC LIMIT 8"
);

$total_passwords = (int)(db_one("SELECT COUNT(*) AS c FROM it_service_passwords")['c'] ?? 0);

include dirname(__DIR__) . '/includes/header.php';
?>
<div class="container-fluid py-4">

  <!-- Header -->
  <div class="d-flex align-items-center gap-3 mb-4">
    <div style="width:48px;height:48px;border-radius:12px;background:#fff3e0;display:flex;align-items:center;justify-content:center;font-size:1.4rem;color:#fd7e14;flex-shrink:0">
      <i class="bi bi-hdd-network"></i>
    </div>
    <div>
      <h1 class="h4 mb-0 fw-bold">Dostępy i Infrastruktura IT</h1>
      <p class="text-muted mb-0 small">Zarządzanie kontami, dostępami i hasłami serwisów</p>
    </div>
    <div class="ms-auto d-flex gap-2">
      <a href="<?= APP_URL ?>/it/accounts.php" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-list-ul me-1"></i> Wszystkie konta
      </a>
      <a href="<?= APP_URL ?>/it/passwords.php" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-key me-1"></i> Hasła
      </a>
      <?php if (is_admin()): ?>
      <a href="<?= APP_URL ?>/it/services.php" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-gear me-1"></i> Serwisy
      </a>
      <?php endif; ?>
    </div>
  </div>

  <!-- Kafelki serwisów -->
  <div class="row g-3 mb-4">
    <?php foreach ($stats as $slug => $s): ?>
    <div class="col-sm-6 col-lg-3">
      <a href="<?= APP_URL ?>/it/accounts.php?service=<?= urlencode($slug) ?>" class="text-decoration-none">
        <div class="card border-0 shadow-sm h-100" style="border-left:4px solid <?= h($s['service']['color']) ?>!important;border-left-style:solid">
          <div class="card-body">
            <div class="d-flex align-items-center gap-2 mb-2">
              <i class="bi <?= h($s['service']['icon']) ?>" style="color:<?= h($s['service']['color']) ?>;font-size:1.2rem"></i>
              <span class="fw-semibold"><?= h($s['service']['name']) ?></span>
            </div>
            <div class="d-flex gap-3">
              <div>
                <div class="fs-4 fw-bold" style="color:<?= h($s['service']['color']) ?>"><?= $s['active'] ?></div>
                <div class="text-muted" style="font-size:.72rem">aktywne</div>
              </div>
              <?php if ($s['inactive']): ?>
              <div>
                <div class="fs-4 fw-bold text-secondary"><?= $s['inactive'] ?></div>
                <div class="text-muted" style="font-size:.72rem">nieaktywne</div>
              </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </a>
    </div>
    <?php endforeach; ?>
    <!-- Hasła -->
    <div class="col-sm-6 col-lg-3">
      <a href="<?= APP_URL ?>/it/passwords.php" class="text-decoration-none">
        <div class="card border-0 shadow-sm h-100" style="border-left:4px solid #fd7e14!important;border-left-style:solid">
          <div class="card-body">
            <div class="d-flex align-items-center gap-2 mb-2">
              <i class="bi bi-key-fill" style="color:#fd7e14;font-size:1.2rem"></i>
              <span class="fw-semibold">Wydane hasła</span>
            </div>
            <div class="fs-4 fw-bold" style="color:#fd7e14"><?= $total_passwords ?></div>
            <div class="text-muted" style="font-size:.72rem">łącznie w historii</div>
          </div>
        </div>
      </a>
    </div>
  </div>

  <div class="row g-4">

    <!-- Ostatnie konta -->
    <div class="col-lg-7">
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-white d-flex align-items-center justify-content-between py-2">
          <span class="fw-semibold"><i class="bi bi-person-badge me-2 text-orange"></i>Ostatnie konta IT</span>
          <a href="<?= APP_URL ?>/it/accounts.php" class="btn btn-sm btn-link p-0">Wszystkie →</a>
        </div>
        <div class="table-responsive">
          <table class="table table-hover mb-0 small">
            <thead class="table-light">
              <tr>
                <th>Serwis</th>
                <th>Login</th>
                <th>Umowa</th>
                <th>Status</th>
                <th>Akt.</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($recent_accounts as $a): ?>
              <tr>
                <td>
                  <i class="bi <?= h($a['service_icon']) ?>" style="color:<?= h($a['service_color']) ?>"></i>
                  <span class="ms-1"><?= h($a['service_name']) ?></span>
                </td>
                <td class="font-monospace">
                  <a href="<?= APP_URL ?>/it/accounts.php?id=<?= $a['id'] ?>" class="text-decoration-none">
                    <?= h($a['login'] ?: $a['display_name'] ?: '—') ?>
                  </a>
                </td>
                <td>
                  <?php if ($a['contract_type'] && $a['contract_id']): ?>
                  <a href="<?= it_contract_url($a['contract_type'], (int)$a['contract_id']) ?>"
                     class="text-decoration-none text-muted" style="font-size:.78rem">
                    <?= h(it_contract_label($a['contract_type'])) ?>
                    <i class="bi bi-box-arrow-up-right ms-1" style="font-size:.65rem"></i>
                  </a>
                  <?php else: ?>—<?php endif; ?>
                </td>
                <td>
                  <?php if ($a['is_active']): ?>
                  <span class="badge bg-success-subtle text-success border border-success-subtle">aktywne</span>
                  <?php else: ?>
                  <span class="badge bg-secondary-subtle text-secondary border">nieaktywne</span>
                  <?php endif; ?>
                </td>
                <td class="text-muted" style="font-size:.72rem"><?= date_pl(substr($a['updated_at'],0,10)) ?></td>
              </tr>
              <?php endforeach; ?>
              <?php if (!$recent_accounts): ?>
              <tr><td colspan="5" class="text-muted py-3 text-center">Brak kont IT</td></tr>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Ostatnie hasła -->
    <div class="col-lg-5">
      <div class="card border-0 shadow-sm">
        <div class="card-header bg-white d-flex align-items-center justify-content-between py-2">
          <span class="fw-semibold"><i class="bi bi-key me-2" style="color:#fd7e14"></i>Ostatnie hasła</span>
          <a href="<?= APP_URL ?>/it/passwords.php" class="btn btn-sm btn-link p-0">Wszystkie →</a>
        </div>
        <ul class="list-group list-group-flush">
          <?php foreach ($recent_passwords as $p): ?>
          <li class="list-group-item py-2 px-3">
            <div class="d-flex align-items-center gap-2">
              <i class="bi <?= h($p['service_icon']) ?>" style="color:<?= h($p['service_color']) ?>;font-size:.9rem"></i>
              <span class="font-monospace small"><?= h($p['login'] ?: '—') ?></span>
              <?php if ($p['sent_to_email']): ?>
              <i class="bi bi-envelope-check text-success ms-auto" title="Wysłano na: <?= h($p['sent_to_email']) ?>"></i>
              <?php endif; ?>
            </div>
            <div class="text-muted" style="font-size:.72rem">
              <?= date_pl(substr($p['issued_at'],0,10)) ?>
              <?php if ($p['issued_by_name']): ?>· <?= h($p['issued_by_name']) ?><?php endif; ?>
              <?php if ($p['is_superseded']): ?><span class="badge bg-secondary ms-1" style="font-size:.6rem">wygasłe</span><?php endif; ?>
            </div>
          </li>
          <?php endforeach; ?>
          <?php if (!$recent_passwords): ?>
          <li class="list-group-item text-muted py-3 text-center small">Brak historii haseł</li>
          <?php endif; ?>
        </ul>
      </div>
    </div>

  </div>
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
