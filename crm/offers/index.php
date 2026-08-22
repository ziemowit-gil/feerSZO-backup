<?php
/**
 * crm/offers/index.php — Rejestr ofert (działalność odpłatna).
 *
 * Lista z pasami statusów, filtrami i kolumną „potwierdzenie" — dla ofert
 * skierowanych do osób fizycznych brak potwierdzenia jest widoczny na liście,
 * bo blokuje uruchomienie realizacji.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm_offers.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
crm_offers_migrate();

$PAGE_TITLE = 'Oferty';
$can_write  = crm_offer_can_write();

$search   = trim($_GET['q'] ?? '');
$status_f = (string)($_GET['status'] ?? '');
$owner_f  = (int)($_GET['owner_id'] ?? 0);
$contact_f= (int)($_GET['contact_id'] ?? 0);
$fund_f   = (string)($_GET['funding'] ?? '');
$mine     = !empty($_GET['mine']);
$noconf   = !empty($_GET['noconf']);
$page     = max(1, (int)($_GET['page'] ?? 1));
$per      = 25;
$uid      = (int)(current_user()['id'] ?? 0);

$where  = 'o.deleted_at IS NULL';
$params = [];
if ($search !== '') {
    $where .= " AND (o.offer_number LIKE ? OR o.title LIKE ? OR c.imie_nazwisko LIKE ? OR c.nip LIKE ?)";
    array_push($params, "%$search%", "%$search%", "%$search%", "%$search%");
}
if ($status_f !== '' && isset(CRM_OFFER_STATUSES[$status_f])) { $where .= " AND o.status=?"; $params[] = $status_f; }
if ($owner_f)   { $where .= " AND o.owner_id=?";   $params[] = $owner_f; }
if ($contact_f) { $where .= " AND o.contact_id=?"; $params[] = $contact_f; }
if ($fund_f !== '' && isset(CRM_OFFER_FUNDING[$fund_f])) { $where .= " AND o.funding_source=?"; $params[] = $fund_f; }
if ($mine)  { $where .= " AND (o.owner_id=? OR o.created_by=?)"; $params[] = $uid; $params[] = $uid; }
if ($noconf) {
    $where .= " AND o.requires_confirmation=1 AND o.confirmation_id IS NULL AND o.status IN ('wyslana','zaakceptowana')";
}

$total = (int)(crm_one("SELECT COUNT(*) AS n FROM crm_offers o JOIN crm_contacts c ON c.id=o.contact_id WHERE $where", $params)['n'] ?? 0);
$offset = ($page - 1) * $per;
$rows = crm_all(
    "SELECT o.*, c.imie_nazwisko AS contact_name, c.type AS contact_type, c.nip AS contact_nip,
            (SELECT COUNT(*) FROM crm_offer_variants v WHERE v.offer_id=o.id) AS variants_cnt
     FROM crm_offers o JOIN crm_contacts c ON c.id=o.contact_id
     WHERE $where ORDER BY o.updated_at DESC, o.id DESC LIMIT $per OFFSET $offset",
    $params
);

$stats = crm_offer_stats();
$need_conf_cnt = (int)(crm_one(
    "SELECT COUNT(*) AS n FROM crm_offers
     WHERE deleted_at IS NULL AND requires_confirmation=1 AND confirmation_id IS NULL
       AND status IN ('wyslana','zaakceptowana')"
)['n'] ?? 0);

$owners = crm_all("SELECT DISTINCT owner_id FROM crm_offers WHERE owner_id IS NOT NULL AND deleted_at IS NULL");

include dirname(__DIR__) . '/includes/header_crm.php';

$qs = static function (array $over = []) use ($search, $status_f, $owner_f, $contact_f, $fund_f, $mine, $noconf): string {
    $base = array_filter([
        'q' => $search, 'status' => $status_f, 'owner_id' => $owner_f ?: null,
        'contact_id' => $contact_f ?: null, 'funding' => $fund_f,
        'mine' => $mine ? 1 : null, 'noconf' => $noconf ? 1 : null,
    ], static fn($v) => $v !== null && $v !== '');
    return http_build_query(array_merge($base, array_filter($over, static fn($v) => $v !== null)));
};
?>

<style>
.of-row { display:flex;align-items:center;gap:.75rem;padding:.7rem 1rem;border-bottom:1px solid #F3F4F6;text-decoration:none;color:#111827;transition:background .1s }
.of-row:hover { background:#F9FAFB }
.of-row:last-child { border-bottom:none }
.of-nr { font-family:monospace;font-size:.74rem;font-weight:700;color:#1D4ED8;letter-spacing:.04em }
.of-meta { font-size:.75rem;color:#9CA3AF }
.of-val { font-weight:700;font-size:.88rem;white-space:nowrap }
.of-pill { display:inline-flex;align-items:center;gap:.4rem;padding:.35rem .85rem;border-radius:2rem;font-size:.78rem;font-weight:600;text-decoration:none;border:2px solid transparent;transition:all .12s }
.of-warnflag { display:inline-flex;align-items:center;gap:.25rem;font-size:.7rem;font-weight:700;color:#B45309;background:#FEF3E2;border-radius:2rem;padding:.1rem .5rem;white-space:nowrap }
.of-kpi { background:#fff;border:1px solid #E5E7EB;border-radius:10px;padding:.75rem .9rem }
.of-kpi-v { font-size:1.15rem;font-weight:800;line-height:1.1 }
.of-kpi-l { font-size:.72rem;color:#6B7280;text-transform:uppercase;letter-spacing:.05em;font-weight:600 }
</style>

<?php if (!crm_offers_available()): ?>
<div class="alert alert-danger" role="alert">
  <div class="fw-bold"><i class="bi bi-database-exclamation me-1"></i>Schemat modułu Oferty nie jest gotowy</div>
  <div style="font-size:.85rem">Nie udało się utworzyć / zaktualizować tabel modułu. Szczegóły:
    <code><?= h(crm_offers_last_error() ?: 'brak szczegółów — sprawdź log PHP') ?></code></div>
</div>
<?php endif; ?>

<div class="crm-page-header">
  <div>
    <div class="crm-page-title"><i class="bi bi-file-earmark-ruled-fill" style="color:#0176D3"></i> Oferty</div>
    <div class="crm-page-subtitle">Rejestr ofert działalności odpłatnej — od szkicu do uruchomienia realizacji</div>
  </div>
  <div class="crm-page-actions">
    <a href="catalog.php" class="btn btn-crm-outline btn-sm"><i class="bi bi-list-columns me-1"></i>Katalog usług</a>
    <?php if ($can_write): ?>
    <a href="form.php" class="btn btn-crm-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Nowa oferta</a>
    <?php endif; ?>
  </div>
</div>

<?php if ($need_conf_cnt): ?>
<div class="alert alert-warning d-flex align-items-center gap-2 py-2" role="alert">
  <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
  <div class="flex-grow-1" style="font-size:.86rem">
    <strong><?= $need_conf_cnt ?></strong> ofert(y) dla osób fizycznych czeka na potwierdzenie klienta —
    bez potwierdzenia nie wolno uruchomić realizacji.
  </div>
  <a href="?<?= $qs(['noconf' => $noconf ? null : 1, 'page' => null]) ?>" class="btn btn-sm btn-outline-dark">
    <?= $noconf ? 'Pokaż wszystkie' : 'Pokaż te oferty' ?>
  </a>
</div>
<?php endif; ?>

<!-- KPI -->
<div class="row g-2 mb-3">
  <div class="col-6 col-lg-3"><div class="of-kpi">
    <div class="of-kpi-l">Ofert łącznie</div><div class="of-kpi-v"><?= (int)$stats['total'] ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="of-kpi">
    <div class="of-kpi-l">W toku (pipeline)</div><div class="of-kpi-v" style="color:#1D4ED8"><?= h(number_format($stats['pipeline'], 0, ',', ' ')) ?> zł</div></div></div>
  <div class="col-6 col-lg-3"><div class="of-kpi">
    <div class="of-kpi-l">Wygrane (brutto)</div><div class="of-kpi-v" style="color:#2E844A"><?= h(number_format($stats['won_value'], 0, ',', ' ')) ?> zł</div></div></div>
  <div class="col-6 col-lg-3"><div class="of-kpi">
    <div class="of-kpi-l">Skuteczność</div><div class="of-kpi-v"><?= h(number_format($stats['win_rate'], 1, ',', ' ')) ?>%</div></div></div>
</div>

<!-- Pasy statusów -->
<div class="d-flex flex-wrap gap-2 mb-3">
  <a href="?<?= $qs(['status' => '', 'page' => null]) ?>" class="of-pill"
     style="background:#F3F4F6;color:#374151;border-color:<?= $status_f === '' ? '#374151' : 'transparent' ?>">
    Wszystkie <strong><?= (int)$stats['total'] ?></strong>
  </a>
  <?php foreach (CRM_OFFER_STATUSES as $sk => $sv):
    $n = (int)($stats['by_status'][$sk]['n'] ?? 0);
    if (!$n && $status_f !== $sk) continue; ?>
  <a href="?<?= $qs(['status' => $sk, 'page' => null]) ?>" class="of-pill" title="<?= h($sv['hint']) ?>"
     style="background:<?= $sv['bg'] ?>;color:<?= $sv['color'] ?>;border-color:<?= $status_f === $sk ? $sv['color'] : 'transparent' ?>">
    <i class="bi <?= $sv['icon'] ?>" aria-hidden="true"></i><?= h($sv['label']) ?> <strong><?= $n ?></strong>
  </a>
  <?php endforeach; ?>
  <a href="?<?= $qs(['mine' => $mine ? null : 1, 'page' => null]) ?>" class="of-pill"
     style="background:#EEF2FF;color:#4338CA;border-color:<?= $mine ? '#4338CA' : 'transparent' ?>">
    <i class="bi bi-person-check" aria-hidden="true"></i>Moje
  </a>
</div>

<!-- Filtry -->
<form method="get" class="card border-0 shadow-sm mb-3"><div class="card-body py-2">
  <?php if ($status_f !== ''): ?><input type="hidden" name="status" value="<?= h($status_f) ?>"><?php endif; ?>
  <?php if ($mine): ?><input type="hidden" name="mine" value="1"><?php endif; ?>
  <div class="row g-2 align-items-center">
    <div class="col-md-4">
      <div class="input-group input-group-sm">
        <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
        <input name="q" class="form-control" placeholder="Numer, tytuł, klient, NIP…" value="<?= h($search) ?>">
      </div>
    </div>
    <div class="col-md-3">
      <select name="funding" class="form-select form-select-sm">
        <option value="">— finansowanie: wszystkie —</option>
        <?php foreach (CRM_OFFER_FUNDING as $fk => $fl): ?>
        <option value="<?= h($fk) ?>" <?= $fund_f === $fk ? 'selected' : '' ?>><?= h($fl) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <select name="owner_id" class="form-select form-select-sm">
        <option value="">— opiekun: wszyscy —</option>
        <?php foreach ($owners as $ow): $oid = (int)$ow['owner_id']; $on = crm_offer_user_name($oid); if (!$on) continue; ?>
        <option value="<?= $oid ?>" <?= $owner_f === $oid ? 'selected' : '' ?>><?= h($on) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-auto">
      <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-funnel"></i> Filtruj</button>
      <?php if ($search || $status_f || $owner_f || $fund_f || $mine || $noconf || $contact_f): ?>
      <a href="?" class="btn btn-outline-secondary btn-sm ms-1" title="Wyczyść filtry"><i class="bi bi-x"></i></a>
      <?php endif; ?>
    </div>
  </div>
</div></form>

<!-- Lista -->
<div class="card shadow-sm">
<?php if (!$rows): ?>
  <div class="text-center py-5">
    <i class="bi bi-file-earmark-ruled display-4 text-secondary opacity-25 d-block mb-3"></i>
    <h5 class="text-muted">Brak ofert</h5>
    <p class="text-muted small mb-3"><?= ($search || $status_f || $fund_f || $noconf) ? 'Zmień filtry.' : 'Nie utworzono jeszcze żadnej oferty.' ?></p>
    <?php if ($can_write): ?><a href="form.php" class="btn btn-crm-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Nowa oferta</a><?php endif; ?>
  </div>
<?php else: foreach ($rows as $r):
  $needs = crm_offer_requires_confirmation($r);
  $conf  = $needs ? (int)($r['confirmation_id'] ?? 0) : 1;
  $ct    = CRM_CONTACT_TYPES[$r['contact_type']] ?? CRM_CONTACT_TYPES['osoba'];
  $exp_soon = !empty($r['valid_until']) && $r['status'] === 'wyslana'
              && strtotime($r['valid_until']) < strtotime('+3 days');
?>
  <a href="view.php?id=<?= (int)$r['id'] ?>" class="of-row">
    <div style="flex:1;min-width:0">
      <div class="d-flex align-items-center gap-2">
        <span class="of-nr"><?= h($r['offer_number']) ?></span>
        <?php if ((int)$r['variants_cnt'] > 1): ?>
        <span class="of-meta"><i class="bi bi-layers" aria-hidden="true"></i> <?= (int)$r['variants_cnt'] ?> warianty</span>
        <?php endif; ?>
        <?php if ($needs && !$conf): ?>
        <span class="of-warnflag"><i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>brak potwierdzenia</span>
        <?php endif; ?>
      </div>
      <div class="fw-semibold" style="font-size:.9rem"><?= h($r['title']) ?></div>
      <div class="of-meta">
        <i class="bi <?= $ct['icon'] ?>" aria-hidden="true"></i> <?= h($r['contact_name']) ?>
        <?php if (!empty($r['contact_nip'])): ?><span class="ms-2">NIP <?= h($r['contact_nip']) ?></span><?php endif; ?>
        <?php $on = crm_offer_user_name((int)($r['owner_id'] ?? 0)); if ($on): ?>
        <span class="ms-2"><i class="bi bi-person" aria-hidden="true"></i> <?= h($on) ?></span>
        <?php endif; ?>
      </div>
    </div>
    <div class="text-end flex-shrink-0 d-none d-md-block" style="min-width:130px">
      <div class="of-val"><?= h(crm_offer_money((float)$r['total_gross'], (string)$r['currency'])) ?></div>
      <div class="of-meta"><?= h(number_format((float)$r['total_net'], 2, ',', ' ')) ?> netto</div>
    </div>
    <div class="text-end flex-shrink-0" style="min-width:132px">
      <?= crm_offer_status_pill((string)$r['status']) ?>
      <div class="of-meta mt-1" <?= $exp_soon ? 'style="color:#B45309;font-weight:600"' : '' ?>>
        <?php if (!empty($r['valid_until'])): ?>
          do <?= h(date_pl($r['valid_until'])) ?>
        <?php else: ?>
          <?= h(date_pl($r['updated_at'])) ?>
        <?php endif; ?>
      </div>
    </div>
    <i class="bi bi-chevron-right text-muted opacity-50" style="font-size:.7rem" aria-hidden="true"></i>
  </a>
<?php endforeach; ?>
  <?php if ($total > $per): $pages = (int)ceil($total / $per); ?>
  <div class="card-footer d-flex justify-content-between align-items-center py-2" style="background:#FAFAFA">
    <small class="text-muted">Łącznie: <strong><?= $total ?></strong></small>
    <div class="d-flex gap-1 flex-wrap">
      <?php for ($p = 1; $p <= $pages; $p++): ?>
      <a href="?<?= $qs(['page' => $p]) ?>" class="btn btn-sm <?= $p === $page ? 'btn-primary' : 'btn-outline-secondary' ?>"><?= $p ?></a>
      <?php endfor; ?>
    </div>
  </div>
  <?php endif; ?>
<?php endif; ?>
</div>

<?php include dirname(__DIR__) . '/includes/footer_crm.php'; ?>
