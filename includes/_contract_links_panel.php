<?php
/**
 * includes/_contract_links_panel.php — sekcja „Przypisanie” na karcie umowy (wszystkie rodzaje, obok „Zespoły”).
 * Wymaga $TYPE i $id. Kontakt (konto SZO), jednostka, obszar Zadań; edycja — administrator (contracts/przypisania.php, op=save).
 */
require_once dirname(__DIR__) . '/modules/contract_links/lib/ContractLinks.php';
try {
    ContractLinks::migrate();
    $_cl_type = (string)$TYPE; $_cl_id = (int)$id;
    $_cl_row = null;
    foreach (ContractLinks::activeContracts() as $_c) { if ($_c['key'] === $_cl_type . ':' . $_cl_id) { $_cl_row = $_c; break; } }
    $_cl_link = db_one("SELECT * FROM contract_links WHERE contract_type=? AND contract_id=?", [$_cl_type, $_cl_id]) ?: [];
} catch (\Throwable $e) { return; }
$_cl_sug = $_cl_row ? ContractLinks::suggest($_cl_row) : ['user_id' => (int)($_cl_link['user_id'] ?? 0), 'unit_id' => (int)($_cl_link['unit_id'] ?? 0), 'workspace_id' => (int)($_cl_link['workspace_id'] ?? 0)];
$_cl_admin = is_admin();
$_cl_name = function (string $sql, int $v) { if (!$v) return ''; $r = db_one($sql, [$v]); return (string)($r['n'] ?? ''); };
$_cl_vals = [
    'Kontakt' => $_cl_name("SELECT name n FROM users WHERE id=?", (int)($_cl_link['user_id'] ?? 0)),
    'Jednostka' => $_cl_name("SELECT name n FROM org_units WHERE id=?", (int)($_cl_link['unit_id'] ?? 0)),
    'Obszar Zadań' => $_cl_name("SELECT name n FROM task_workspaces WHERE id=?", (int)($_cl_link['workspace_id'] ?? 0)),
];
$_cl_miss = array_keys(array_filter($_cl_vals, fn($v) => $v === ''));
?>
<div class="card mb-3" id="contract-links-panel">
  <div class="card-header d-flex align-items-center gap-2">
    <i class="bi bi-diagram-2 text-primary" aria-hidden="true"></i>
    <h2 class="h6 mb-0 fw-bold">Przypisanie</h2>
    <?php if ($_cl_row && $_cl_miss): ?><span class="badge bg-warning text-dark ms-auto">brak: <?= h(mb_strtolower(implode(', ', $_cl_miss))) ?></span><?php endif; ?>
  </div>
  <div class="card-body">
    <?php if (!$_cl_admin): ?>
    <dl class="row mb-0 small">
      <?php foreach ($_cl_vals as $k => $v): ?><dt class="col-sm-4"><?= h($k) ?></dt><dd class="col-sm-8"><?= $v !== '' ? h($v) : '<span class="text-muted">—</span>' ?></dd><?php endforeach; ?>
    </dl>
    <?php else:
      $_cl_users = db_all("SELECT id, name FROM users WHERE COALESCE(is_active,1)=1 ORDER BY name");
      $_cl_units = org_units_all('active');
      $_cl_ws = db_all("SELECT id, name FROM task_workspaces WHERE is_active=1 ORDER BY name"); ?>
    <form method="post" action="<?= APP_URL ?>/contracts/przypisania.php" class="row g-2 align-items-end">
      <?= csrf_field() ?><input type="hidden" name="op" value="save"><input type="hidden" name="return" value="<?= h($_SERVER['REQUEST_URI'] ?? '') ?>">
      <input type="hidden" name="contract_type" value="<?= h($_cl_type) ?>"><input type="hidden" name="contract_id" value="<?= $_cl_id ?>">
      <div class="col-md-4"><label class="form-label small mb-1" for="cl-u">Kontakt (konto SZO)</label>
        <select id="cl-u" name="user_id" class="form-select form-select-sm"><option value="0">— brak —</option>
        <?php foreach ($_cl_users as $u): ?><option value="<?= (int)$u['id'] ?>"<?= (int)$u['id'] === (int)$_cl_sug['user_id'] ? ' selected' : '' ?>><?= h($u['name']) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-3"><label class="form-label small mb-1" for="cl-j">Jednostka</label>
        <select id="cl-j" name="unit_id" class="form-select form-select-sm"><option value="0">— brak —</option>
        <?php foreach ($_cl_units as $u): ?><option value="<?= (int)$u['id'] ?>"<?= (int)$u['id'] === (int)$_cl_sug['unit_id'] ? ' selected' : '' ?>><?= h($u['name']) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-3"><label class="form-label small mb-1" for="cl-o">Obszar Zadań</label>
        <select id="cl-o" name="workspace_id" class="form-select form-select-sm"><option value="0">— brak —</option>
        <?php foreach ($_cl_ws as $w): ?><option value="<?= (int)$w['id'] ?>"<?= (int)$w['id'] === (int)$_cl_sug['workspace_id'] ? ' selected' : '' ?>><?= h($w['name']) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-2"><button type="submit" class="btn btn-outline-primary btn-sm w-100">Zapisz</button></div>
    </form>
    <?php if ($_cl_miss && ($_cl_sug['user_id'] || $_cl_sug['unit_id'] || $_cl_sug['workspace_id'])): ?><p class="small text-muted mt-2 mb-0">Wstępnie wybrane pola to podpowiedzi (e-mail z umowy, jednostka osoby) — zapisz, jeśli się zgadzają.</p><?php endif; ?>
    <?php endif; ?>
  </div>
</div>
