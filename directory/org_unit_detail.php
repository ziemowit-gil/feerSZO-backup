<?php
/**
 * directory/org_unit_detail.php — Fragment HTML szczegółów jednostki (AJAX).
 * Wywoływany przez org_chart.php przy kliknięciu w węzeł drzewa.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/directory.php';
require_once dirname(__DIR__) . '/includes/org.php';

require_login();

if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
    header('Location: ' . APP_URL . '/directory/org_chart.php');
    exit;
}

$unit_id = (int)($_GET['id'] ?? 0);
if (!$unit_id) { http_response_code(400); echo 'Brak ID'; exit; }

try {
    $unit = org_unit_get($unit_id);
} catch (\Throwable $e) { $unit = null; }

if (!$unit) { http_response_code(404); echo '<p class="text-muted small">Nie znaleziono jednostki.</p>'; exit; }

$members = [];
try {
    $members = db_all("
        SELECT om.id, om.user_id, om.position_name, om.is_head, om.status,
               om.phone_direct, om.phone_mobile,
               u.first_name, u.last_name, u.name AS user_name, u.email,
               COALESCE(up.phone_public,0) AS phone_public,
               COALESCE(up.avatar_file,'') AS avatar_file
        FROM org_members om
        JOIN users u ON u.id = om.user_id
        LEFT JOIN user_profiles up ON up.user_id = om.user_id
        WHERE om.unit_id = ? AND om.status = 'active'
          AND (om.valid_to IS NULL OR om.valid_to >= date('now'))
        ORDER BY om.is_head DESC, om.sort_order, u.last_name
    ", [$unit_id]);
} catch (\Throwable $e) {}
?>
<div style="background:#fff;border:1px solid var(--dir-border);border-radius:12px;padding:1.25rem 1.5rem">

  <!-- Nagłówek jednostki -->
  <div style="font-size:1.05rem;font-weight:700;color:var(--dir-text);margin-bottom:.2rem">
    <i class="bi bi-diagram-3 me-2" style="color:var(--dir-primary)"></i><?= h($unit['name']) ?>
    <span style="font-size:.65rem;font-family:monospace;background:#F1F5F9;color:#475569;padding:.1rem .4rem;border-radius:4px;margin-left:.4rem"><?= h($unit['code']) ?></span>
  </div>

  <?php
  $meta = [];
  if ($unit['location'])    $meta[] = '<i class="bi bi-geo-alt me-1"></i>' . h($unit['location']);
  if ($unit['phone'])       $meta[] = '<i class="bi bi-telephone me-1"></i>' . h($unit['phone']);
  if ($unit['email'])       $meta[] = '<i class="bi bi-envelope me-1"></i>' . h($unit['email']);
  if ($unit['parent_name']) $meta[] = '<i class="bi bi-arrow-up-right me-1"></i>' . h($unit['parent_name']);
  if ($meta): ?>
  <div style="font-size:.79rem;color:var(--dir-text-muted);margin-bottom:.75rem;display:flex;flex-wrap:wrap;gap:.5rem .9rem">
    <?php foreach ($meta as $m) echo '<span>' . $m . '</span>'; ?>
  </div>
  <?php endif; ?>

  <?php if ($unit['description']): ?>
  <p style="font-size:.84rem;color:var(--dir-text-muted);white-space:pre-wrap;margin-bottom:.9rem"><?= h($unit['description']) ?></p>
  <?php endif; ?>

  <!-- Lista wolontariuszy -->
  <div style="font-size:.72rem;font-weight:700;color:var(--dir-text-muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:.5rem">
    <i class="bi bi-people me-1"></i>Wolontariusze
    <?php if ($members): ?><span style="background:var(--dir-primary-bg);color:var(--dir-primary);padding:.05rem .45rem;border-radius:10px;font-size:.7rem;margin-left:.25rem"><?= count($members) ?></span><?php endif; ?>
  </div>

  <?php if (empty($members)): ?>
  <p style="font-size:.84rem;color:var(--dir-text-muted);margin:0">Brak aktywnych wolontariuszy w tej jednostce.</p>
  <?php else: ?>
  <div>
    <?php foreach ($members as $m):
        $display = trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? ''));
        if (!$display) $display = $m['user_name'] ?? $m['email'];
        $ph = ($m['phone_public'] && ($m['phone_direct'] ?: $m['phone_mobile']))
              ? ($m['phone_direct'] ?: $m['phone_mobile']) : '';
    ?>
    <a href="<?= APP_URL ?>/directory/profile.php?id=<?= (int)$m['user_id'] ?>"
       style="display:flex;align-items:center;gap:.65rem;padding:.5rem .6rem;border-radius:8px;text-decoration:none;color:inherit;transition:background .1s"
       onmouseover="this.style.background='var(--dir-primary-bg)'"
       onmouseout="this.style.background=''">
      <?= directory_avatar_html($m, 34) ?>
      <div style="flex:1;min-width:0">
        <div style="font-size:.86rem;font-weight:600;color:var(--dir-text)"><?= h($display) ?></div>
        <?php if ($m['position_name']): ?>
        <div style="font-size:.74rem;color:var(--dir-text-muted)"><?= h($m['position_name']) ?></div>
        <?php endif; ?>
        <?php if ($ph): ?>
        <div style="font-size:.73rem;color:#16A34A"><i class="bi bi-telephone me-1" style="font-size:.65rem"></i><?= h($ph) ?></div>
        <?php endif; ?>
      </div>
      <?php if ($m['is_head']): ?>
      <span style="font-size:.65rem;font-weight:700;padding:.1rem .4rem;background:#FEF9C3;color:#A16207;border-radius:4px;border:1px solid #FDE68A;white-space:nowrap;flex-shrink:0">
        <i class="bi bi-star-fill me-1"></i>Kierownik
      </span>
      <?php endif; ?>
    </a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

</div>
