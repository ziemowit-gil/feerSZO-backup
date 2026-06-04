<?php
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/rodo.php';
rodo_migrate();
require_login();

$PAGE_TITLE  = 'Moje upoważnienia RODO';
$user        = current_user();
$uid         = (int)$user['id'];

// Znajdź aktywne upoważnienia powiązane z umowami użytkownika
// (szukamy przez kontrakt powiązany z mailem lub M365)
$_db_user    = db_one("SELECT microsoft_id, email FROM users WHERE id=?", [$uid]);
$email       = $_db_user['email'] ?? $user['email'] ?? '';
$ms_id       = $_db_user['microsoft_id'] ?? '';

$authorizations = [];

// Szukaj upoważnień powiązanych z wolontariat umowami użytkownika
try {
    $contract_ids = [];
    if ($email) {
        $rows = db_all(
            "SELECT id FROM umowy_wolontariat WHERE email=? OR m365_login=?",
            [$email, $email]
        );
        foreach ($rows as $r) $contract_ids[] = (int)$r['id'];
    }
    if ($ms_id) {
        $rows = db_all("SELECT id FROM umowy_wolontariat WHERE m365_user_id=?", [$ms_id]);
        foreach ($rows as $r) {
            if (!in_array((int)$r['id'], $contract_ids)) $contract_ids[] = (int)$r['id'];
        }
    }
    if ($contract_ids) {
        $ph = implode(',', array_fill(0, count($contract_ids), '?'));
        $authorizations = db_all(
            "SELECT * FROM rodo_authorizations WHERE contract_type='wolontariat' AND contract_id IN ({$ph}) ORDER BY created_at DESC",
            $contract_ids
        );
    }
} catch (\Throwable $e) {}

$_is_volunteer_only = is_viewer() && !db_one("SELECT id FROM users WHERE id=? AND k30_consultant=1", [$uid]);

if ($_is_volunteer_only) {
    include __DIR__ . '/includes/header_panel.php';
} else {
    include dirname(__DIR__) . '/includes/header.php';
}
?>

<div class="d-flex align-items-center gap-2 mb-3">
  <h4 class="mb-0 fw-bold"><i class="bi bi-shield-lock text-primary me-2"></i>Moje upoważnienia RODO</h4>
</div>

<div class="alert alert-info d-flex gap-2 py-2 mb-3 small">
  <i class="bi bi-info-circle-fill flex-shrink-0 mt-1"></i>
  <div>
    Upoważnienia uprawniają Cię do przetwarzania danych osobowych w ramach wolontariatu.
    Obowiązuje Cię <strong>poufność</strong> przetwarzanych danych — również po zakończeniu współpracy.
    W razie pytań skontaktuj się ze swoim opiekunem.
  </div>
</div>

<?php if (!$authorizations): ?>
<div class="text-center py-5 text-muted">
  <i class="bi bi-shield-check" style="font-size:2.5rem;opacity:.35"></i>
  <div class="mt-2 fw-semibold">Brak upoważnień powiązanych z Twoim kontem</div>
  <div class="small mt-1">Jeśli uważasz, że powinno ono istnieć — skontaktuj się z administratorem.</div>
</div>
<?php else: ?>
<div class="card border-0 shadow-sm">
  <?php foreach ($authorizations as $a):
    $scope = json_decode($a['scope_items'] ?? '[]', true) ?: [];
    $status_map = ['aktywne'=>['bg-success','Aktywne'],'cofnięte'=>['bg-danger','Odwołane'],'wygasłe'=>['bg-secondary','Wygasłe']];
    [$status_cls, $status_lbl] = $status_map[$a['status']] ?? ['bg-light text-dark border', $a['status']];
  ?>
  <div class="card-body <?= $a['status'] !== 'aktywne' ? 'opacity-75' : '' ?>">

    <!-- Nagłówek -->
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
      <div>
        <div class="d-flex align-items-center gap-2 flex-wrap">
          <span class="font-monospace fw-bold text-muted" style="font-size:.82rem"><?= h($a['number']) ?></span>
          <span class="badge <?= $status_cls ?>"><?= h($status_lbl) ?></span>
          <?php if (!$a['training_done'] && $a['status'] === 'aktywne'): ?>
          <span class="badge bg-warning text-dark" style="font-size:.72rem">
            <i class="bi bi-exclamation-circle me-1"></i>Brak szkolenia RODO
          </span>
          <?php endif; ?>
        </div>
        <div class="mt-1 text-muted small">
          Wydane przez: <strong><?= h($a['org_name']) ?></strong>
          · od <?= $a['authorized_from'] ? date('d.m.Y', strtotime($a['authorized_from'])) : '—' ?>
          <?= $a['authorized_until'] ? ' do ' . date('d.m.Y', strtotime($a['authorized_until'])) : ' (do zakończenia porozumienia)' ?>
        </div>
      </div>
      <a href="<?= APP_URL ?>/rodo/print.php?id=<?= $a['id'] ?>" target="_blank"
         class="btn btn-sm btn-outline-primary" rel="noopener">
        <i class="bi bi-printer me-1"></i>Pobierz / wydrukuj
      </a>
    </div>

    <!-- Zakres § 2 -->
    <?php if ($scope || $a['scope_custom']): ?>
    <div class="mb-2">
      <div class="small fw-semibold text-muted mb-1">Zakres upoważnienia (§ 2):</div>
      <ul class="mb-0 ps-3" style="font-size:.87rem">
        <?php foreach ($scope as $k): ?>
        <li><?= h(RODO_SCOPE_ITEMS[$k] ?? $k) ?></li>
        <?php endforeach; ?>
        <?php if ($a['scope_custom']): ?><li><?= h($a['scope_custom']) ?></li><?php endif; ?>
      </ul>
    </div>
    <?php endif; ?>

    <!-- Podpis oświadczenia -->
    <?php if ($a['status'] === 'aktywne'): ?>
    <div class="mt-2 pt-2 border-top small">
      <?php if ($a['vol_signed_at']): ?>
      <span class="text-success">
        <i class="bi bi-check-circle-fill me-1"></i>
        Oświadczenie podpisałeś/aś <?= date('d.m.Y', strtotime($a['vol_signed_at'])) ?>
      </span>
      <?php else: ?>
      <span class="text-warning">
        <i class="bi bi-exclamation-circle-fill me-1"></i>
        Oświadczenie RODO oczekuje na Twój podpis — skontaktuj się z administratorem.
      </span>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($a['status'] === 'cofnięte'): ?>
    <div class="alert alert-danger py-1 mt-2 small mb-0">
      <i class="bi bi-x-circle-fill me-1"></i>
      To upoważnienie zostało odwołane. Nie możesz przetwarzać danych osobowych w ramach tej umowy.
    </div>
    <?php endif; ?>
  </div>
  <?php if (!array_key_last($authorizations) !== $a): ?><hr class="m-0"><?php endif; ?>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($_is_volunteer_only): ?>
<?php include __DIR__ . '/includes/footer_panel.php'; ?>
<?php else: ?>
<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
<?php endif; ?>
