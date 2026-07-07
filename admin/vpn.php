<?php
/**
 * admin/vpn.php — Zarządzanie wnioskami/dostępami VPN.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/vpn.php';

require_role('admin');
require_module_enabled('vpn_enabled', 'Moduł VPN');

$PAGE_TITLE = 'VPN — dostępy';
$me = (int)current_user()['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';
    $id     = (int)($_POST['id'] ?? 0);
    try {
        if ($action === 'approve') {
            vpn_approve($id, trim($_POST['vpn_username'] ?? ''), (string)($_POST['vpn_config'] ?? ''), (string)($_POST['instrukcja'] ?? ''), $me, trim($_POST['note'] ?? ''));
            flash_set('success', 'Dostęp VPN nadany.');
        } elseif ($action === 'reject') {
            vpn_reject($id, $me, trim($_POST['note'] ?? ''));
            flash_set('success', 'Wniosek odrzucony.');
        } elseif ($action === 'revoke') {
            vpn_revoke($id, $me, trim($_POST['note'] ?? ''));
            flash_set('success', 'Dostęp VPN cofnięty.');
        }
    } catch (\Throwable $e) {
        flash_set('error', $e->getMessage());
    }
    header('Location: admin/vpn.php'); exit;
}

$filter = $_GET['status'] ?? '';
$rows   = vpn_all($filter);
$pending = vpn_pending_count();

include dirname(__DIR__) . '/includes/header.php';
?>
<div class="container my-4" style="max-width:1000px">
  <div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
    <h1 class="h4 mb-0"><i class="bi bi-shield-lock me-2"></i>VPN — dostępy<?php if ($pending): ?> <span class="badge bg-warning text-dark"><?= $pending ?> oczekuje</span><?php endif; ?></h1>
    <a href="<?= APP_URL ?>/admin/org_settings.php#vpn" class="btn btn-outline-secondary btn-sm"><i class="bi bi-hdd-network me-1"></i>Ustawienia „tylko przez VPN"</a>
  </div>
  <?= flash_html() ?>

  <div class="alert alert-light border small">
    <i class="bi bi-info-circle me-1"></i>Ten moduł zarządza <strong>nadawaniem i ewidencją</strong> dostępu do VPN.
    Sieciową blokadę „moduł dostępny tylko z VPN" (np. Wirtualne biurko) włączysz w
    <a href="<?= APP_URL ?>/admin/org_settings.php">ustawieniach organizacji</a>.
  </div>

  <ul class="nav nav-pills mb-3">
    <?php foreach (['' => 'Wszystkie', 'oczekuje' => 'Oczekujące', 'aktywny' => 'Aktywne', 'odrzucony' => 'Odrzucone', 'cofniety' => 'Cofnięte'] as $k => $lbl): ?>
      <li class="nav-item"><a class="nav-link<?= $filter === $k ? ' active' : '' ?>" href="?status=<?= h($k) ?>"><?= h($lbl) ?></a></li>
    <?php endforeach; ?>
  </ul>

  <div class="card shadow-sm">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead><tr><th>Użytkownik</th><th>Uzasadnienie</th><th>Status</th><th>Złożono</th><th class="text-end">Akcje</th></tr></thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="5" class="text-center text-muted py-4">Brak wpisów.</td></tr>
          <?php endif; ?>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td><?= h($r['user_name'] ?? '—') ?><div class="small text-muted"><?= h($r['user_email'] ?? '') ?></div></td>
              <td class="small" style="max-width:280px"><?= nl2br(h(mb_substr((string)$r['reason'], 0, 200))) ?></td>
              <td><?= vpn_status_badge($r['status']) ?></td>
              <td class="small text-muted"><?= h(substr((string)$r['requested_at'], 0, 16)) ?></td>
              <td class="text-end">
                <?php if ($r['status'] === 'oczekuje'): ?>
                  <button class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#approveModal<?= (int)$r['id'] ?>"><i class="bi bi-check-lg"></i> Nadaj</button>
                  <button class="btn btn-sm btn-outline-danger" data-bs-toggle="modal" data-bs-target="#rejectModal<?= (int)$r['id'] ?>"><i class="bi bi-x-lg"></i></button>
                <?php elseif ($r['status'] === 'aktywny'): ?>
                  <?php if ($r['vpn_username']): ?><span class="small text-muted me-2">👤 <code><?= h($r['vpn_username']) ?></code></span><?php endif; ?>
                  <button class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#revokeModal<?= (int)$r['id'] ?>"><i class="bi bi-slash-circle"></i> Cofnij</button>
                <?php else: ?>
                  <span class="text-muted small">—</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php foreach ($rows as $r): if ($r['status'] === 'oczekuje'): ?>
  <!-- Nadaj dostęp -->
  <div class="modal fade" id="approveModal<?= (int)$r['id'] ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
      <div class="modal-content">
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="approve">
          <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <div class="modal-header"><h5 class="modal-title">Nadaj dostęp VPN — <?= h($r['user_name']) ?></h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button></div>
          <div class="modal-body">
            <div class="mb-3">
              <label class="form-label">Nazwa użytkownika VPN</label>
              <input name="vpn_username" class="form-control" placeholder="np. j.kowalski">
            </div>
            <div class="mb-3">
              <label class="form-label">Konfiguracja (WireGuard / OpenVPN) <span class="text-muted small">— użytkownik pobierze jako plik</span></label>
              <textarea name="vpn_config" class="form-control font-monospace" rows="6" placeholder="[Interface]&#10;PrivateKey = ...&#10;Address = 10.8.0.5/32&#10;..."></textarea>
            </div>
            <div class="mb-3">
              <label class="form-label">Instrukcja dla użytkownika <span class="text-muted small">(opcjonalnie)</span></label>
              <textarea name="instrukcja" class="form-control" rows="3" placeholder="Jak połączyć się z VPN..."></textarea>
            </div>
            <div class="mb-1">
              <label class="form-label">Notatka do decyzji <span class="text-muted small">(opcjonalnie)</span></label>
              <input name="note" class="form-control">
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
            <button class="btn btn-success"><i class="bi bi-check-lg me-1"></i>Nadaj dostęp</button>
          </div>
        </form>
      </div>
    </div>
  </div>
  <!-- Odrzuć -->
  <div class="modal fade" id="rejectModal<?= (int)$r['id'] ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="reject">
          <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <div class="modal-header"><h5 class="modal-title">Odrzuć wniosek</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button></div>
          <div class="modal-body">
            <label class="form-label">Powód odrzucenia</label>
            <textarea name="note" class="form-control" rows="3" required></textarea>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
            <button class="btn btn-danger">Odrzuć</button>
          </div>
        </form>
      </div>
    </div>
  </div>
<?php elseif ($r['status'] === 'aktywny'): ?>
  <!-- Cofnij -->
  <div class="modal fade" id="revokeModal<?= (int)$r['id'] ?>" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <form method="post">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="revoke">
          <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
          <div class="modal-header"><h5 class="modal-title">Cofnij dostęp VPN</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button></div>
          <div class="modal-body">
            <p class="small">Cofasz dostęp dla <strong><?= h($r['user_name']) ?></strong>. Pamiętaj, aby unieważnić dane po stronie serwera VPN.</p>
            <label class="form-label">Powód / notatka <span class="text-muted small">(opcjonalnie)</span></label>
            <textarea name="note" class="form-control" rows="2"></textarea>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
            <button class="btn btn-secondary">Cofnij dostęp</button>
          </div>
        </form>
      </div>
    </div>
  </div>
<?php endif; endforeach; ?>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
