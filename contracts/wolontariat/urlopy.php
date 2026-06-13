<?php
/**
 * contracts/wolontariat/urlopy.php
 *
 * Moduł zatwierdzania urlopów wolontariuszy — wizualny dashboard dla
 * opiekuna / administratora. Akceptacja jest formalna (potwierdza odnotowanie
 * nieobecności, niczego nie blokuje). Decyzje zapisuje wspólny endpoint
 * dyspo_action.php (akcja urlop_decide).
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/dyspozycyjnosc.php';

require_login();
if (is_viewer()) { header('Location: ' . APP_URL . '/panel/index.php'); exit; }
require_role('admin', 'editor');
require_module_enabled('dyspozycyjnosc_enabled', 'Moduł dyspozycyjności i urlopów');
dyspo_migrate();

$PAGE_TITLE = 'Zatwierdzanie urlopów wolontariuszy';

$filter = $_GET['status'] ?? '';
if (!array_key_exists($filter, URLOP_STATUSES)) $filter = '';

$where  = '1=1';
$params = [];
if ($filter) { $where .= ' AND u.status = ?'; $params[] = $filter; }

$rows = db_all(
    "SELECT u.*, w.numer_umowy, w.imie_nazwisko, w.email, d.name AS decided_by_name
     FROM wol_urlopy u
     JOIN umowy_wolontariat w ON w.id = u.contract_id
     LEFT JOIN users d ON d.id = u.decided_by
     WHERE {$where}
     ORDER BY (u.status='oczekuje') DESC, u.data_od ASC, u.id DESC",
    $params
);

// Statystyki (niezależne od filtra)
$counts = ['oczekuje'=>0,'zaakceptowany'=>0,'odrzucony'=>0];
foreach (db_all("SELECT status, COUNT(*) AS c FROM wol_urlopy GROUP BY status") as $r) {
    $counts[$r['status']] = (int)$r['c'];
}
$total = array_sum($counts);

$_ret = APP_URL . '/contracts/wolontariat/urlopy.php' . ($filter ? '?status=' . urlencode($filter) : '');

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<style>
.ur-card{background:#fff;border:1px solid #E5E7EB;border-radius:14px;padding:1rem 1.15rem;margin-bottom:.85rem;display:flex;gap:1rem;align-items:flex-start;transition:box-shadow .15s}
.ur-card:hover{box-shadow:0 4px 16px rgba(0,0,0,.08)}
.ur-card.is-pending{border-left:4px solid #f59e0b}
.ur-ico{width:46px;height:46px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.25rem;flex-shrink:0;background:#fff7ed;color:#d97706}
.ur-who{font-weight:700;color:#111827}
.ur-num{font-family:monospace;font-size:.74rem;color:#9CA3AF}
.ur-range{font-size:.95rem;font-weight:700;color:#1f2937;margin-top:.25rem}
.ur-reason{font-size:.85rem;color:#6B7280;margin-top:.1rem}
.ur-meta{font-size:.74rem;color:#9CA3AF;margin-top:.35rem}
.ur-actions{margin-left:auto;display:flex;flex-direction:column;gap:.4rem;align-items:flex-end;flex-shrink:0}
.ur-pill{display:inline-flex;align-items:center;gap:.4rem;padding:.35rem .85rem;border-radius:2rem;font-size:.78rem;font-weight:600;text-decoration:none;border:2px solid transparent;transition:all .12s;cursor:pointer}
.ur-pill:hover{filter:brightness(.96);transform:translateY(-1px)}
.ur-pill.selected{box-shadow:0 0 0 3px rgba(0,0,0,.12)}
</style>

<div class="d-flex align-items-center justify-content-between mb-3 flex-wrap gap-2">
  <div>
    <h4 class="mb-0 d-flex align-items-center gap-2">
      <span class="rounded-circle bg-warning bg-opacity-10 d-inline-flex align-items-center justify-content-center" style="width:38px;height:38px">
        <i class="bi bi-airplane-fill text-warning" style="font-size:1rem"></i>
      </span>
      Urlopy wolontariuszy
      <?php if ($counts['oczekuje']): ?><span class="badge bg-warning text-dark"><?= $counts['oczekuje'] ?> do akceptacji</span><?php endif; ?>
    </h4>
    <div class="text-muted small" style="margin-left:50px">Formalne zatwierdzanie zgłoszonych nieobecności</div>
  </div>
  <a href="<?= APP_URL ?>/contracts/wolontariat/list.php" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-arrow-left me-1"></i>Lista wolontariuszy
  </a>
</div>

<!-- Filtry statusów -->
<div class="d-flex flex-wrap gap-2 mb-3">
  <a href="?" class="ur-pill <?= !$filter ? 'selected' : '' ?>" style="background:#F3F4F6;color:#374151;border-color:<?= !$filter?'#374151':'transparent' ?>">
    <i class="bi bi-list-ul"></i> Wszystkie <strong><?= $total ?></strong>
  </a>
  <?php foreach (URLOP_STATUSES as $sk => $sv): ?>
  <a href="?status=<?= $sk ?>" class="ur-pill <?= $filter===$sk?'selected':'' ?>"
     style="background:var(--bs-<?= $sv['class'] ?>-bg-subtle,#f8fafc);color:var(--bs-<?= $sv['class'] ?>-text-emphasis,#374151);border-color:<?= $filter===$sk?'currentColor':'transparent' ?>">
    <i class="bi <?= $sv['icon'] ?>"></i> <?= h($sv['label']) ?> <strong><?= $counts[$sk] ?></strong>
  </a>
  <?php endforeach; ?>
</div>

<?php if (!$rows): ?>
  <div class="text-center text-muted py-5">
    <i class="bi bi-inbox d-block mb-2" style="font-size:2.5rem;opacity:.4"></i>
    <?= $filter ? 'Brak urlopów o wybranym statusie.' : 'Nie zgłoszono jeszcze żadnych urlopów.' ?>
  </div>
<?php else: foreach ($rows as $u):
  $same = $u['data_od'] === $u['data_do'];
  $days = (int)((strtotime($u['data_do']) - strtotime($u['data_od'])) / 86400) + 1;
?>
  <div class="ur-card<?= $u['status']==='oczekuje' ? ' is-pending' : '' ?>">
    <div class="ur-ico"><i class="bi bi-airplane-engines"></i></div>
    <div style="min-width:0;flex:1">
      <a href="<?= APP_URL ?>/contracts/wolontariat/view.php?id=<?= (int)$u['contract_id'] ?>&tab=profil" class="text-decoration-none">
        <span class="ur-who"><?= h($u['imie_nazwisko'] ?: '—') ?></span>
      </a>
      <span class="ur-num">· <?= h($u['numer_umowy']) ?></span>
      <div class="ur-range">
        <?= h(date_pl($u['data_od'])) ?><?= $same ? '' : ' – ' . h(date_pl($u['data_do'])) ?>
        <span class="text-muted fw-normal small">(<?= $days ?> <?= $days===1?'dzień':'dni' ?>)</span>
      </div>
      <?php if (!empty($u['powod'])): ?><div class="ur-reason"><i class="bi bi-chat-left-quote me-1"></i><?= h($u['powod']) ?></div><?php endif; ?>
      <div class="ur-meta">
        Zgłoszono: <?= h(date_pl(substr($u['created_at'],0,10))) ?> · źródło: <?= ($u['source']??'')==='admin' ? 'opiekun' : 'wolontariusz' ?>
        <?php if (!empty($u['decided_at'])): ?>
          · decyzja: <?= h(date_pl(substr($u['decided_at'],0,10))) ?><?= $u['decided_by_name'] ? ' (' . h($u['decided_by_name']) . ')' : '' ?>
        <?php endif; ?>
      </div>
      <?php if (!empty($u['decision_note'])): ?>
        <div class="alert alert-light border py-1 px-2 mb-0 mt-2 small"><i class="bi bi-card-text me-1"></i><?= h($u['decision_note']) ?></div>
      <?php endif; ?>
    </div>
    <div class="ur-actions">
      <?= urlop_status_badge($u['status']) ?>
      <?php if ($u['status'] === 'oczekuje'): ?>
      <div class="d-flex gap-1">
        <form method="post" action="<?= APP_URL ?>/contracts/wolontariat/dyspo_action.php">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="urlop_decide">
          <input type="hidden" name="contract_id" value="<?= (int)$u['contract_id'] ?>">
          <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
          <input type="hidden" name="decision" value="zaakceptowany">
          <input type="hidden" name="return" value="<?= h($_ret) ?>">
          <button class="btn btn-sm btn-success"><i class="bi bi-check-lg me-1"></i>Zatwierdź</button>
        </form>
        <form method="post" action="<?= APP_URL ?>/contracts/wolontariat/dyspo_action.php"
              onsubmit="this.decision_note.value = prompt('Powód odrzucenia (opcjonalnie):') || '';">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="urlop_decide">
          <input type="hidden" name="contract_id" value="<?= (int)$u['contract_id'] ?>">
          <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
          <input type="hidden" name="decision" value="odrzucony">
          <input type="hidden" name="decision_note" value="">
          <input type="hidden" name="return" value="<?= h($_ret) ?>">
          <button class="btn btn-sm btn-outline-danger"><i class="bi bi-x-lg me-1"></i>Odrzuć</button>
        </form>
      </div>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; endif; ?>

<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
