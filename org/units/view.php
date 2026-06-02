<?php
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/org.php';
require_login(); require_module_enabled('org_enabled','Moduł struktury organizacyjnej');

$id   = (int)($_GET['id'] ?? 0);
$unit = org_unit_get($id);
if (!$unit) { flash_set('error','Jednostka nie istnieje.'); header('Location:'.APP_URL.'/org/index.php'); exit; }

$PAGE_TITLE = $unit['code'].' — '.$unit['name'];
$members    = org_members_by_unit($id);
$head       = org_unit_head($id);
$subunits   = db_all("SELECT * FROM org_units WHERE parent_id=? AND status='active' ORDER BY sort_order,name", [$id]);
$hist_members = org_members_by_unit($id, true);

$show_hist = isset($_GET['history']);
$user_id   = (int)current_user()['id'];

// Breadcrumb chain
$chain = [];
$cur   = $unit;
while ($cur) {
    array_unshift($chain, $cur);
    $cur = $cur['parent_id'] ? org_unit_get((int)$cur['parent_id']) : null;
}

include dirname(dirname(__DIR__)) . '/includes/header.php';
?>
<style>
.meta-dl dt{font-size:.7rem;color:#94a3b8;font-weight:600;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.1rem}
.meta-dl dd{font-size:.84rem;color:#1e293b;margin-bottom:.75rem}
.member-card{background:#fff;border:1.5px solid #e2e8f0;border-radius:10px;padding:1rem;transition:box-shadow .12s}
.member-card:hover{box-shadow:0 3px 14px rgba(0,0,0,.08)}
.member-head{border-color:#fbbf24!important;background:#fffbeb}
</style>

<!-- Breadcrumb -->
<nav aria-label="breadcrumb" class="mb-3"><ol class="breadcrumb" style="font-size:.8rem">
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/org/index.php">Struktura</a></li>
  <?php foreach($chain as $i => $bc): ?>
  <?php if($i < count($chain)-1): ?>
  <li class="breadcrumb-item"><a href="<?= APP_URL ?>/org/units/view.php?id=<?= $bc['id'] ?>"><?= h($bc['code']) ?></a></li>
  <?php else: ?>
  <li class="breadcrumb-item active"><?= h($bc['code']) ?></li>
  <?php endif; ?>
  <?php endforeach; ?>
</ol></nav>

<?= flash_html() ?>

<div class="row g-4">
  <!-- Lewa: lista członków -->
  <div class="col-lg-8">
    <!-- Nagłówek jednostki -->
    <div class="card shadow-sm mb-4">
      <div class="card-body">
        <div class="d-flex align-items-start justify-content-between gap-3 flex-wrap">
          <div>
            <div class="d-flex align-items-center gap-2 mb-2 flex-wrap">
              <span class="badge bg-primary bg-opacity-15 text-primary fw-bold font-monospace fs-6"><?= h($unit['code']) ?></span>
              <?= org_unit_status_badge($unit['status']) ?>
              <?php if($unit['parent_name']): ?><span class="badge bg-light text-dark border" style="font-size:.68rem"><i class="bi bi-diagram-3 me-1"></i><?= h($unit['parent_code'].' — '.$unit['parent_name']) ?></span><?php endif; ?>
            </div>
            <h4 class="fw-bold mb-1"><?= h($unit['name']) ?></h4>
            <?php if($unit['short_name']): ?><div class="text-muted" style="font-size:.82rem"><?= h($unit['short_name']) ?></div><?php endif; ?>
            <?php if($unit['description']): ?><div class="text-muted mt-1" style="font-size:.8rem"><?= h($unit['description']) ?></div><?php endif; ?>
          </div>
          <?php if(is_admin()): ?>
          <div class="d-flex gap-2">
            <a href="<?= APP_URL ?>/org/members/add.php?unit_id=<?= $id ?>" class="btn btn-primary btn-sm"><i class="bi bi-person-plus me-1"></i>Dodaj osobę</a>
            <a href="<?= APP_URL ?>/org/units/add.php?parent_id=<?= $id ?>" class="btn btn-outline-secondary btn-sm" title="Dodaj podjednostkę"><i class="bi bi-diagram-3"></i></a>
            <a href="<?= APP_URL ?>/org/units/edit.php?id=<?= $id ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-pencil"></i></a>
          </div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <!-- Członkowie -->
    <div class="d-flex align-items-center justify-content-between mb-2">
      <h6 class="fw-bold mb-0"><i class="bi bi-people me-1 text-primary"></i>Skład (<?= count($members) ?>)</h6>
      <?php if($show_hist): ?>
      <a href="?" class="btn btn-sm btn-outline-secondary" style="font-size:.74rem">Ukryj historycznych</a>
      <?php elseif(count($hist_members) > count($members)): ?>
      <a href="?id=<?= $id ?>&history=1" class="btn btn-sm btn-outline-secondary" style="font-size:.74rem"><i class="bi bi-clock-history me-1"></i>Pokaż historię</a>
      <?php endif; ?>
    </div>

    <?php $display_members = $show_hist ? $hist_members : $members; ?>
    <div class="row g-3 mb-4">
      <?php foreach($display_members as $m): ?>
      <?php
        $is_current = !$m['valid_to'] || $m['valid_to'] >= date('Y-m-d');
        $is_unavail = in_array($m['status'], ['leave','sick']) && $is_current;
      ?>
      <div class="col-md-6">
        <div class="member-card <?= $m['is_head'] ? 'member-head' : '' ?> <?= !$is_current ? 'opacity-50' : '' ?>">
          <div class="d-flex align-items-start gap-2">
            <!-- Avatar initials -->
            <div style="width:40px;height:40px;border-radius:50%;background:#1e293b;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.85rem;flex-shrink:0">
              <?= mb_strtoupper(mb_substr($m['user_name'],0,1)) ?>
            </div>
            <div class="flex-grow-1 overflow-hidden">
              <div class="fw-semibold text-truncate" style="font-size:.9rem"><?= h($m['user_name']) ?></div>
              <div class="text-muted text-truncate" style="font-size:.75rem"><?= h($m['position_name'] ?: ($m['position_label'] ?? '')) ?></div>
              <div class="d-flex flex-wrap gap-1 mt-1">
                <?php if($m['is_head']): ?><span class="badge bg-warning text-dark" style="font-size:.62rem"><i class="bi bi-star-fill me-1"></i>Kierownik</span><?php endif; ?>
                <?= org_status_badge($m['status']) ?>
                <?php if(!$is_current): ?><span class="badge bg-secondary" style="font-size:.62rem">Historyczny</span><?php endif; ?>
              </div>
              <!-- Zastępstwo -->
              <?php if($is_unavail && $m['sub_name']): ?>
              <div class="mt-1 text-muted" style="font-size:.72rem"><i class="bi bi-arrow-right-short"></i>Zastępuje: <strong><?= h($m['sub_name']) ?></strong></div>
              <?php endif; ?>
              <!-- Kontakt -->
              <?php if($m['email_service'] || $m['phone_direct']): ?>
              <div class="mt-1" style="font-size:.7rem;color:#64748b">
                <?php if($m['email_service']): ?><a href="mailto:<?= h($m['email_service']) ?>" class="text-decoration-none me-2"><i class="bi bi-envelope me-1"></i><?= h($m['email_service']) ?></a><?php endif; ?>
                <?php if($m['phone_direct']): ?><span><i class="bi bi-telephone me-1"></i><?= h($m['phone_direct']) ?></span><?php endif; ?>
              </div>
              <?php endif; ?>
              <?php if($m['valid_from'] || $m['valid_to']): ?>
              <div class="text-muted" style="font-size:.68rem;margin-top:.2rem"><i class="bi bi-calendar3 me-1"></i><?= h($m['valid_from']??'…') ?> — <?= h($m['valid_to'] ?: 'teraz') ?></div>
              <?php endif; ?>
            </div>
            <?php if(is_admin() && $is_current): ?>
            <div class="d-flex flex-column gap-1">
              <a href="<?= APP_URL ?>/org/members/edit.php?id=<?= $m['id'] ?>" class="btn btn-xs btn-outline-secondary btn-sm" title="Edytuj" style="font-size:.7rem;padding:.2rem .45rem"><i class="bi bi-pencil"></i></a>
              <form method="post" action="<?= APP_URL ?>/org/members/remove.php" onsubmit="return confirm('Zakończyć przypisanie tej osoby?')">
                <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
                <input type="hidden" name="id" value="<?= $m['id'] ?>">
                <button type="submit" class="btn btn-xs btn-outline-danger btn-sm" title="Usuń z jednostki" style="font-size:.7rem;padding:.2rem .45rem"><i class="bi bi-x-lg"></i></button>
              </form>
            </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?php endforeach; ?>
      <?php if(!$members): ?>
      <div class="col-12 text-center text-muted py-4" style="font-size:.85rem">
        <i class="bi bi-people" style="font-size:2rem;display:block;margin-bottom:.5rem;opacity:.25"></i>
        Brak członków w tej jednostce.
        <?php if(is_admin()): ?><br><a href="<?= APP_URL ?>/org/members/add.php?unit_id=<?= $id ?>">Dodaj pierwszą osobę.</a><?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- Podjednostki -->
    <?php if($subunits): ?>
    <h6 class="fw-bold mb-2"><i class="bi bi-diagram-3 me-1 text-primary"></i>Podjednostki (<?= count($subunits) ?>)</h6>
    <div class="row g-2">
      <?php foreach($subunits as $s):
        $s_head = org_unit_head((int)$s['id']);
        $s_cnt  = db_one("SELECT COUNT(*) AS c FROM org_members WHERE unit_id=? AND (valid_to IS NULL OR valid_to >= date('now'))", [$s['id']])['c'] ?? 0;
      ?>
      <div class="col-md-6">
        <a href="<?= APP_URL ?>/org/units/view.php?id=<?= $s['id'] ?>" class="d-flex align-items-center gap-2 p-2 rounded border text-decoration-none text-dark hover-bg-light">
          <i class="bi bi-diagram-3 text-primary"></i>
          <div class="flex-grow-1">
            <div class="fw-semibold" style="font-size:.84rem"><?= h($s['name']) ?></div>
            <?php if($s_head): ?><div class="text-muted" style="font-size:.72rem"><?= h($s_head['user_name']) ?></div><?php endif; ?>
          </div>
          <span class="badge bg-light text-secondary border font-monospace" style="font-size:.65rem"><?= h($s['code']) ?></span>
          <span class="badge bg-light text-secondary" style="font-size:.65rem"><?= $s_cnt ?> os.</span>
        </a>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- Prawa: metadane -->
  <div class="col-lg-4">
    <div class="card shadow-sm mb-3">
      <div class="card-header fw-semibold" style="font-size:.82rem"><i class="bi bi-info-circle me-1 text-primary"></i>Dane jednostki</div>
      <div class="card-body">
        <dl class="meta-dl mb-0">
          <dt>Kod / Skrót</dt><dd class="font-monospace fw-bold"><?= h($unit['code']) ?></dd>
          <?php if($unit['short_name']): ?><dt>Nazwa skrócona</dt><dd><?= h($unit['short_name']) ?></dd><?php endif; ?>
          <?php if($unit['email']): ?><dt>E-mail jednostki</dt><dd><a href="mailto:<?= h($unit['email']) ?>"><?= h($unit['email']) ?></a></dd><?php endif; ?>
          <?php if($unit['phone']): ?><dt>Telefon</dt><dd><?= h($unit['phone']) ?></dd><?php endif; ?>
          <?php if($unit['location']): ?><dt>Lokalizacja / pokój</dt><dd><?= h($unit['location']) ?></dd><?php endif; ?>
          <?php if($head): ?><dt>Kierownik</dt><dd><strong><?= h($head['user_name']) ?></strong><br><small class="text-muted"><?= h($head['position_label'] ?? '') ?></small></dd><?php endif; ?>
          <?php if(!empty($unit['supervisor_unit_name'])): ?>
          <dt><i class="bi bi-eye me-1"></i>Jednostka nadzorująca</dt>
          <dd><a href="<?= APP_URL ?>/org/units/view.php?id=<?= (int)$unit['supervisor_unit_id'] ?>" class="text-decoration-none">
            <span class="font-monospace fw-semibold" style="font-size:.82rem"><?= h($unit['supervisor_unit_code']) ?></span>
            <span class="text-muted ms-1" style="font-size:.8rem"><?= h($unit['supervisor_unit_name']) ?></span>
          </a></dd>
          <?php endif; ?>
          <?php if(!empty($unit['supervisor_user_name'])): ?>
          <dt><i class="bi bi-person-lines-fill me-1"></i>Osoba nadzorująca</dt>
          <dd class="fw-semibold"><?= h($unit['supervisor_user_name']) ?></dd>
          <?php endif; ?>
          <dt>Status</dt><dd><?= org_unit_status_badge($unit['status']) ?></dd>
        </dl>
      </div>
    </div>

    <!-- Zastępstwa aktywne -->
    <?php
    $leaves = array_filter($members, fn($m) => in_array($m['status'], ['leave','sick']));
    if ($leaves):
    ?>
    <div class="card shadow-sm border-warning mb-3">
      <div class="card-header fw-semibold bg-warning bg-opacity-10 text-warning" style="font-size:.82rem"><i class="bi bi-person-slash me-1"></i>Nieobecności i zastępstwa</div>
      <div class="card-body p-0">
        <?php foreach($leaves as $m): ?>
        <div class="d-flex align-items-center gap-2 px-3 py-2 border-bottom" style="font-size:.8rem">
          <span class="fw-semibold"><?= h($m['user_name']) ?></span>
          <?= org_status_badge($m['status']) ?>
          <?php if($m['sub_name']): ?><span class="text-muted"><i class="bi bi-arrow-right-short"></i><?= h($m['sub_name']) ?></span><?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- Moje ustawienia w tej jednostce -->
    <?php
    $my_membership = db_one(
        "SELECT * FROM org_members WHERE unit_id=? AND user_id=? AND (valid_to IS NULL OR valid_to >= date('now'))",
        [$id, $user_id]
    );
    if ($my_membership):
    ?>
    <div class="card shadow-sm">
      <div class="card-header fw-semibold" style="font-size:.82rem"><i class="bi bi-person-gear me-1 text-primary"></i>Moje ustawienia w tej jednostce</div>
      <div class="card-body pb-2">
        <div class="mb-2"><?= org_status_badge($my_membership['status']) ?></div>
        <?php if($my_membership['availability']): ?><div class="text-muted mb-1" style="font-size:.76rem"><i class="bi bi-clock me-1"></i><?= h($my_membership['availability']) ?></div><?php endif; ?>
        <a href="<?= APP_URL ?>/org/members/edit.php?id=<?= $my_membership['id'] ?>" class="btn btn-sm btn-outline-primary w-100 mt-1">
          <i class="bi bi-gear me-1"></i>Edytuj moje ustawienia
        </a>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php include dirname(dirname(__DIR__)) . '/includes/footer.php'; ?>
