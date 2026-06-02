<?php
/**
 * org_intro/index.php — Wprowadzenie do organizacji.
 * Strona dostępna dla wszystkich zalogowanych.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/org_rules.php';

require_login();
org_rules_migrate();

$PAGE_TITLE = 'Wprowadzenie do organizacji';
$user       = current_user();
$uid        = (int)$user['id'];

// POST: potwierdź przeczytanie
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['_ack'])) {
    csrf_check();
    $rule_id = (int)($_POST['rule_id'] ?? 0);
    if ($rule_id) {
        org_rule_acknowledge($rule_id, $uid);
    }
    flash_set('success', 'Potwierdzono przeczytanie zasady.');
    header('Location: index.php' . ($_GET['cat'] ? '?cat=' . urlencode($_GET['cat']) : ''));
    exit;
}

$active_cat = $_GET['cat'] ?? '';
$all_cats   = ORG_RULE_CATEGORIES;

// Zasady do wyświetlenia
$rules = org_rules_all($active_cat ? $active_cat : '');

// Grupuj wg kategorii
$by_cat = [];
foreach ($rules as $r) {
    $by_cat[$r['category']][] = $r;
}

// Niepotwierdzonych obowiązkowych
$unread_mandatory = org_rules_unread($uid);
$unread_count     = count($unread_mandatory);

// Sprawdź stan potwierdzenia dla każdej zasady
$read_map = [];
foreach ($rules as $r) {
    $read_map[(int)$r['id']] = org_rule_is_read((int)$r['id'], $uid);
}

// Statystyki (ile reguł w każdej kategorii)
$cat_counts = [];
foreach ($all_cats as $ck => $cv) {
    $c = (int)(db_one("SELECT COUNT(*) AS c FROM org_rules WHERE category=? AND status='active'", [$ck])['c'] ?? 0);
    if ($c > 0) $cat_counts[$ck] = $c;
}

include dirname(__DIR__) . '/includes/header.php';
?>

<style>
.oi-hero { background:linear-gradient(135deg,#1E3A5F 0%,#2563EB 100%); border-radius:16px; padding:2rem 2rem 1.5rem; color:#fff; margin-bottom:1.5rem; position:relative; overflow:hidden }
.oi-hero::after { content:''; position:absolute; width:280px; height:280px; border-radius:50%; background:rgba(255,255,255,.05); top:-80px; right:-80px; pointer-events:none }
.oi-hero-title { font-size:1.5rem; font-weight:800; margin-bottom:.3rem }
.oi-hero-sub { font-size:.9rem; opacity:.8 }
.oi-progress { background:rgba(255,255,255,.2); border-radius:4px; height:8px; margin-top:1rem }
.oi-progress-fill { background:#fff; height:8px; border-radius:4px; transition:width .6s }
.oi-cat-pill { display:inline-flex; align-items:center; gap:.35rem; padding:.35rem .85rem; border-radius:2rem; font-size:.8rem; font-weight:600; text-decoration:none; border:2px solid transparent; transition:all .12s; margin:.15rem }
.oi-cat-pill:hover { text-decoration:none }
.oi-rule-card { background:#fff; border:1px solid #E5E7EB; border-radius:12px; padding:1.1rem 1.25rem; margin-bottom:.75rem; border-left:4px solid var(--rc,#2563EB); box-shadow:0 1px 3px rgba(0,0,0,.04); transition:box-shadow .15s }
.oi-rule-card:hover { box-shadow:0 4px 12px rgba(0,0,0,.1) }
.oi-rule-title { font-size:1rem; font-weight:700; color:#111827; margin-bottom:.25rem }
.oi-rule-meta { font-size:.76rem; color:#9CA3AF }
.oi-badge-mandatory { display:inline-flex; align-items:center; gap:.25rem; font-size:.7rem; font-weight:700; padding:.15rem .55rem; border-radius:2rem; background:#FEF2F2; color:#DC2626; border:1px solid #FECACA }
.oi-badge-read { display:inline-flex; align-items:center; gap:.25rem; font-size:.7rem; font-weight:700; padding:.15rem .55rem; border-radius:2rem; background:#F0FDF4; color:#16A34A; border:1px solid #BBF7D0 }
.oi-unread-banner { background:linear-gradient(90deg,#FEF3E2,#FFF7ED); border:2px solid #FED7AA; border-radius:12px; padding:1rem 1.25rem; margin-bottom:1.25rem; display:flex; align-items:center; gap:.85rem }
.oi-section-head { display:flex; align-items:center; gap:.5rem; font-size:.7rem; font-weight:700; letter-spacing:.08em; text-transform:uppercase; color:#9CA3AF; padding-bottom:.5rem; border-bottom:2px solid #F3F4F6; margin-bottom:.85rem; margin-top:1.5rem }
</style>

<!-- Hero -->
<?php
$total_mandatory = (int)(db_one("SELECT COUNT(*) AS c FROM org_rules WHERE is_mandatory=1 AND status='active'")['c'] ?? 0);
$read_mandatory  = $total_mandatory - $unread_count;
$pct = $total_mandatory > 0 ? round($read_mandatory / $total_mandatory * 100) : 100;
?>
<div class="oi-hero" role="banner">
  <div style="position:relative;z-index:1">
    <div class="oi-hero-title">
      <i class="bi bi-building-heart me-2" aria-hidden="true"></i>
      Wprowadzenie do <?= h(ORG_NAME) ?>
    </div>
    <div class="oi-hero-sub">
      Zapoznaj się z zasadami, strukturą i kulturą organizacji.
      <?php if ($total_mandatory > 0): ?>
      Zasad obowiązkowych: <strong><?= $read_mandatory ?>/<?= $total_mandatory ?></strong> przeczytanych.
      <?php endif; ?>
    </div>
    <?php if ($total_mandatory > 0): ?>
    <div class="oi-progress" role="progressbar" aria-valuenow="<?= $pct ?>" aria-valuemin="0" aria-valuemax="100"
         aria-label="Postęp: <?= $pct ?>% zasad przeczytanych">
      <div class="oi-progress-fill" style="width:<?= $pct ?>%"></div>
    </div>
    <div style="font-size:.78rem;opacity:.75;margin-top:.4rem"><?= $pct ?>% ukończono</div>
    <?php endif; ?>
  </div>
</div>

<!-- Baner niepotwierdzonych -->
<?php if ($unread_count > 0): ?>
<div class="oi-unread-banner" role="alert" aria-live="polite">
  <div style="width:40px;height:40px;border-radius:10px;background:#FEF3C7;color:#D97706;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0">
    <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
  </div>
  <div style="flex:1">
    <div style="font-weight:700;color:#92400E">Masz <?= $unread_count ?> niepotwierdzonych zasad obowiązkowych</div>
    <div style="font-size:.82rem;color:#B45309">Przeczytaj i potwierdź poniższe zasady, aby ukończyć wprowadzenie.</div>
  </div>
  <a href="?cat=regulamin" class="btn btn-sm" style="background:#D97706;color:#fff;border:none;white-space:nowrap">
    <i class="bi bi-arrow-right me-1" aria-hidden="true"></i>Przejdź do zasad
  </a>
</div>
<?php endif; ?>

<div class="row g-3">
<div class="col-lg-3">

  <!-- Filtr kategorii -->
  <div class="card shadow-sm mb-3 p-3">
    <div style="font-size:.7rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#9CA3AF;margin-bottom:.5rem">Kategorie</div>
    <div>
      <a href="?" class="oi-cat-pill <?= !$active_cat ? 'active' : '' ?>"
         style="background:<?= !$active_cat?'#1E3A5F':'#F3F4F6' ?>;color:<?= !$active_cat?'#fff':'#374151' ?>;border-color:<?= !$active_cat?'#1E3A5F':'transparent' ?>">
        <i class="bi bi-grid" aria-hidden="true"></i> Wszystkie
        <span style="font-size:.65rem;margin-left:.2rem"><?= array_sum($cat_counts) ?></span>
      </a>
      <?php foreach ($all_cats as $ck => $cv):
        if (!isset($cat_counts[$ck])) continue;
        $is_active = $active_cat === $ck;
      ?>
      <a href="?cat=<?= h($ck) ?>" class="oi-cat-pill <?= $is_active ? 'active' : '' ?>"
         style="background:<?= $is_active ? $cv['color'] : '#F3F4F6' ?>;color:<?= $is_active?'#fff':'#374151' ?>;border-color:<?= $is_active?$cv['color']:'transparent' ?>">
        <i class="bi <?= h($cv['icon']) ?>" aria-hidden="true"></i>
        <?= h($cv['label']) ?>
        <span style="font-size:.65rem;margin-left:.2rem"><?= $cat_counts[$ck] ?></span>
      </a>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Przewodnik -->
  <a href="panel_guide.php" class="d-block mb-3 text-decoration-none">
    <div style="background:linear-gradient(135deg,#1E3A5F,#2563EB);border-radius:10px;padding:.85rem 1rem;color:#fff">
      <div style="font-size:.82rem;font-weight:700;margin-bottom:.15rem">
        <i class="bi bi-book me-1" aria-hidden="true"></i>Przewodnik po panelu
      </div>
      <div style="font-size:.76rem;opacity:.8">Jak korzystać z systemu — krok po kroku</div>
      <div style="font-size:.72rem;opacity:.65;margin-top:.3rem">≈ 3 minuty →</div>
    </div>
  </a>

  <!-- Szybkie linki -->
  <div class="card shadow-sm p-3">
    <div style="font-size:.7rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#9CA3AF;margin-bottom:.5rem">Powiązane</div>
    <div class="d-flex flex-column gap-1">
      <?php if (module_enabled('org_enabled')): ?>
      <a href="<?= APP_URL ?>/org/index.php" class="btn btn-outline-secondary btn-sm text-start">
        <i class="bi bi-diagram-3-fill me-2" aria-hidden="true"></i>Schemat organizacyjny
      </a>
      <?php endif; ?>
      <?php if (module_enabled('procedures_enabled')): ?>
      <a href="<?= APP_URL ?>/procedures/index.php" class="btn btn-outline-secondary btn-sm text-start">
        <i class="bi bi-journal-bookmark-fill me-2" aria-hidden="true"></i>Procedury i instrukcje
      </a>
      <?php endif; ?>
      <a href="<?= APP_URL ?>/directory/index.php" class="btn btn-outline-secondary btn-sm text-start">
        <i class="bi bi-people-fill me-2" aria-hidden="true"></i>Katalog współpracowników
      </a>
    </div>
  </div>

</div>
<div class="col-lg-9">

<?php if (empty($rules)): ?>
<div class="text-center py-5">
  <i class="bi bi-file-earmark-text" style="font-size:2.5rem;color:#D1D5DB;display:block;margin-bottom:.75rem" aria-hidden="true"></i>
  <p class="text-muted">Brak materiałów w tej kategorii.</p>
  <?php if (can_edit()): ?>
  <a href="<?= APP_URL ?>/admin/org_rules.php" class="btn btn-primary btn-sm">
    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Dodaj zasadę / artykuł
  </a>
  <?php endif; ?>
</div>
<?php else: ?>

<?php
// Renderuj po kategoriach
$rendered_cats = [];
foreach ($by_cat as $cat_key => $cat_rules):
  $cat_cfg = $all_cats[$cat_key] ?? ['label'=>ucfirst($cat_key),'icon'=>'bi-file-text','color'=>'#6B7280'];
?>

<div class="oi-section-head">
  <div style="width:22px;height:22px;border-radius:5px;background:<?= h($cat_cfg['color']) ?>;display:flex;align-items:center;justify-content:center;flex-shrink:0">
    <i class="bi <?= h($cat_cfg['icon']) ?>" style="color:#fff;font-size:.72rem" aria-hidden="true"></i>
  </div>
  <?= h($cat_cfg['label']) ?>
  <span style="margin-left:auto;font-weight:400;letter-spacing:0;font-size:.75rem;text-transform:none;color:#D1D5DB"><?= count($cat_rules) ?> <?= count($cat_rules)===1?'pozycja':'pozycji' ?></span>
</div>

<?php foreach ($cat_rules as $rule):
  $rid      = (int)$rule['id'];
  $is_read  = $read_map[$rid] ?? false;
  $is_mand  = (bool)$rule['is_mandatory'];
  $rc_color = h($rule['color'] ?: ($all_cats[$rule['category']]['color'] ?? '#2563EB'));
?>
<article class="oi-rule-card" style="--rc:<?= $rc_color ?>" aria-labelledby="rule-title-<?= $rid ?>">
  <div class="d-flex align-items-start gap-3">
    <div style="width:40px;height:40px;border-radius:9px;background:<?= $rc_color ?>18;color:<?= $rc_color ?>;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0;margin-top:.1rem">
      <i class="bi <?= h($rule['icon'] ?? 'bi-file-text') ?>" aria-hidden="true"></i>
    </div>
    <div style="flex:1;min-width:0">
      <div class="oi-rule-title" id="rule-title-<?= $rid ?>"><?= h($rule['title']) ?></div>
      <div class="oi-rule-meta d-flex align-items-center gap-2 flex-wrap">
        <?php if ($is_mand): ?>
        <span class="oi-badge-mandatory"><i class="bi bi-asterisk" aria-hidden="true"></i>Obowiązkowe</span>
        <?php endif; ?>
        <?php if ($is_read): ?>
        <span class="oi-badge-read"><i class="bi bi-check-circle-fill" aria-hidden="true"></i>Przeczytano</span>
        <?php endif; ?>
        <span>Zaktualizowano: <?= date_pl($rule['updated_at']) ?></span>
      </div>
    </div>
    <div class="d-flex gap-1 flex-shrink-0">
      <a href="view.php?id=<?= $rid ?>"
         class="btn btn-sm btn-outline-secondary py-0 px-2"
         aria-label="Czytaj: <?= h($rule['title']) ?>">
        <i class="bi bi-eye me-1" aria-hidden="true"></i>Czytaj
      </a>
      <?php if ($is_mand && !$is_read): ?>
      <form method="post" class="d-inline">
        <input type="hidden" name="_csrf"    value="<?= csrf_token() ?>">
        <input type="hidden" name="_ack"     value="1">
        <input type="hidden" name="rule_id"  value="<?= $rid ?>">
        <button type="submit" class="btn btn-sm btn-success py-0 px-2"
                aria-label="Potwierdź przeczytanie: <?= h($rule['title']) ?>">
          <i class="bi bi-check-lg me-1" aria-hidden="true"></i>Potwierdzam
        </button>
      </form>
      <?php endif; ?>
    </div>
  </div>
  <?php if ($rule['content']): ?>
  <div style="font-size:.85rem;color:#4B5563;margin-top:.65rem;padding-top:.65rem;border-top:1px solid #F3F4F6;overflow:hidden;max-height:3.5rem;mask-image:linear-gradient(to bottom,#000 60%,transparent)">
    <?= strip_tags($rule['content']) ?>
  </div>
  <?php endif; ?>
</article>
<?php endforeach; ?>

<?php endforeach; ?>
<?php endif; ?>

</div>
</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
