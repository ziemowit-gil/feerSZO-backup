<?php
/**
 * crm/settings/ai_builder.php — „Statusy i listy z AI”: gotowe statusy dla firm i adresów technicznych (bez AI)
 * oraz propozycje modelu (statusy, reguły automatyczne, listy/segmenty) zbudowane na zbiorczych statystykach bazy.
 */
require_once dirname(dirname(__DIR__)) . '/config.php';
require_once dirname(dirname(__DIR__)) . '/includes/db.php';
require_once dirname(dirname(__DIR__)) . '/includes/auth.php';
require_once dirname(dirname(__DIR__)) . '/includes/functions.php';
require_once dirname(dirname(__DIR__)) . '/includes/crm.php';
require_once dirname(dirname(__DIR__)) . '/includes/crmAiBuilder.php';

require_login();
require_module_enabled('crm_enabled', 'CRM');
if (!can_write('crm_ustawienia') && !is_admin()) { flash_set('danger', 'Brak uprawnień do ustawień CRM.'); header('Location: ' . APP_URL . '/crm/dashboard.php'); exit; }
crm_migrate();
crm_require('settings', 'write');
crm_status_rules_schema();
$PAGE_TITLE = 'CRM — Statusy i listy';

$here = APP_URL . '/crm/settings/ai_builder.php';
$ai_ok = asai_enabled();
$goal_default = 'Zaproponuj statusy dla firm i adresów technicznych (noreply, biuro@) oraz praktyczne listy robocze do wysyłek i porządkowania kartotek.';
$goal = (string)($_POST['goal'] ?? $goal_default);
$proposal = null; $ai_error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = (string)($_POST['_op'] ?? '');
    if ($op === 'catalog') {
        $r = crm_ai_builder_apply_catalog((array)($_POST['keys'] ?? []));
        flash_set('success', "Dodano statusów: {$r['statuses']}, reguł (wyłączonych): {$r['rules']}.");
        header('Location: ' . $here); exit;
    } elseif ($op === 'segments') {
        $r = crm_ai_builder_apply_segments((array)($_POST['seg'] ?? []));
        flash_set($r['errors'] ? 'warning' : 'success', "Utworzono list: {$r['created']}." . ($r['errors'] ? ' Pominięto: ' . implode('; ', $r['errors']) : ''));
        header('Location: ' . $here); exit;
    } elseif ($op === 'ask') {
        $res = crm_ai_builder_ask($goal);
        if (!empty($res['error'])) $ai_error = $res['error'];
        else { $proposal = $res; $_SESSION['crm_ai_proposal'] = ['token' => bin2hex(random_bytes(8)), 'data' => $res, 'at' => time()]; }
    } elseif ($op === 'apply') {
        $sp = $_SESSION['crm_ai_proposal'] ?? null;
        if (!$sp || !hash_equals((string)$sp['token'], (string)($_POST['token'] ?? '')) || time() - (int)$sp['at'] > 3600) {
            flash_set('danger', 'Propozycje wygasły — poproś model o nowe.');
        } else {
            $r = crm_ai_builder_apply($sp['data'], (array)($_POST['sel'] ?? []));
            unset($_SESSION['crm_ai_proposal']);
            flash_set($r['errors'] ? 'warning' : 'success', "Dodano: statusów {$r['statuses']}, reguł {$r['rules']} (wyłączone — włącz po podglądzie), list {$r['segments']}." . ($r['errors'] ? ' Pominięto: ' . implode(' ', $r['errors']) : ''));
        }
        header('Location: ' . $here); exit;
    }
}

$existing = array_column(db_all("SELECT slug FROM crm_statuses"), 'slug');
$catalog = crm_ai_builder_catalog();
$token = $_SESSION['crm_ai_proposal']['token'] ?? '';
$seg_have = array_map('mb_strtolower', array_column(db_all("SELECT name FROM crm_segments"), 'name'));
$seg_cat = array_map(function ($c) use ($seg_have) {
    $c['have']  = in_array(mb_strtolower($c['name']), $seg_have, true);
    $c['count'] = count(crm_segment_resolve_ids(crm_segment_sanitize($c['filter'])));
    return $c;
}, crm_ai_builder_segment_catalog());

include dirname(__DIR__) . '/includes/header_crm.php';
include __DIR__ . '/_nav.php';
?>
<style>
.ab-card{background:#fff;border:1px solid #D1D5DB;margin-bottom:1rem}
.ab-card>h2{margin:0;padding:.6rem 1rem;font-size:.95rem;font-weight:600;background:#F3F4F6;border-bottom:1px solid #D1D5DB;display:flex;gap:.5rem;align-items:center}
.ab-body{padding:1rem}.ab-help{font-size:.8rem;color:#4B5563}
.ab-item{display:flex;gap:.6rem;align-items:flex-start;padding:.55rem .25rem;border-bottom:1px solid #F3F4F6}
.ab-item:last-child{border-bottom:0}.ab-item input{margin-top:.3rem}
.ab-dot{display:inline-block;width:.7rem;height:.7rem;border-radius:50%;margin-right:.35rem;vertical-align:baseline}
.ab-grp{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#4B5563;margin:.6rem 0 .1rem}
.ab-chip{display:inline-block;padding:.05rem .45rem;background:#EEF2FF;color:#3730A3;font-size:.74rem;font-weight:600;margin-left:.4rem}
.ab-done{color:#166534;font-size:.78rem;font-weight:600}
</style>

<div class="crm-page-header mb-3">
  <div><h1 class="crm-page-title"><i class="bi bi-stars" style="color:var(--crm-primary)" aria-hidden="true"></i> Statusy i listy</h1>
    <div class="crm-page-subtitle">Gotowe statusy i listy (bez AI) oraz — opcjonalnie — propozycje modelu na podstawie statystyk Twojej bazy</div></div>
  <div class="crm-page-actions">
    <a class="btn btn-crm-outline btn-sm" href="<?= APP_URL ?>/crm/settings/statuses.php"><i class="bi bi-bookmark-fill me-1" aria-hidden="true"></i>Statusy</a>
    <a class="btn btn-crm-outline btn-sm" href="<?= APP_URL ?>/crm/settings/status_rules.php"><i class="bi bi-magic me-1" aria-hidden="true"></i>Reguły</a>
  </div>
</div>
<?= flash_get() ?>

<div class="ab-card"><h2><i class="bi bi-check2-square" aria-hidden="true"></i> Gotowe statusy (bez AI)</h2>
  <form method="post" class="ab-body"><?= csrf_field() ?><input type="hidden" name="_op" value="catalog">
    <p class="ab-help mb-2">Zaznacz, co chcesz dodać do słownika statusów. Dołączone reguły automatyczne powstają <strong>wyłączone</strong> — włączysz je po obejrzeniu podglądu w „Statusach automatycznych”.</p>
    <?php $lastg = ''; foreach ($catalog as $c): $have = in_array($c['slug'], $existing, true);
      if ($c['group'] !== $lastg) { echo '<div class="ab-grp">' . h($c['group']) . '</div>'; $lastg = $c['group']; } ?>
    <label class="ab-item">
      <input type="checkbox" name="keys[]" value="<?= h($c['key']) ?>" <?= $have ? 'disabled' : '' ?> aria-describedby="abd_<?= h($c['key']) ?>">
      <span><span class="ab-dot" style="background:<?= h($c['color']) ?>"></span><strong><?= h($c['label']) ?></strong>
        <?= $have ? '<span class="ab-done"> — już istnieje</span>' : '' ?>
        <?php if ($c['rule']): ?><span class="ab-chip">+ reguła: <?= h(CRM_STATUS_RULE_CONDITIONS[$c['rule'][1]]) ?><?= $c['rule'][2] !== '' ? ' ' . h($c['rule'][2]) : '' ?></span><?php endif; ?>
        <div class="ab-help" id="abd_<?= h($c['key']) ?>"><?= h($c['desc']) ?></div></span>
    </label>
    <?php endforeach; ?>
    <button class="btn btn-crm-primary btn-sm mt-2"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Dodaj zaznaczone</button>
  </form>
</div>

<div class="ab-card"><h2><i class="bi bi-funnel" aria-hidden="true"></i> Gotowe listy (bez AI)</h2>
  <form method="post" class="ab-body"><?= csrf_field() ?><input type="hidden" name="_op" value="segments">
    <p class="ab-help mb-2">Listy dynamiczne — liczą się na bieżąco i nadają się do kampanii oraz wysyłek. Liczba obok to stan w tej chwili.</p>
    <?php foreach ($seg_cat as $c): ?>
    <label class="ab-item">
      <input type="checkbox" name="seg[]" value="<?= h($c['key']) ?>" <?= $c['have'] ? 'disabled' : '' ?>>
      <span><strong><?= h($c['name']) ?></strong> <span class="ab-chip"><?= (int)$c['count'] ?> kontaktów</span>
        <?= $c['have'] ? '<span class="ab-done"> — już istnieje</span>' : '' ?>
        <div class="ab-help"><?= h($c['desc']) ?></div></span>
    </label>
    <?php endforeach; ?>
    <button class="btn btn-crm-primary btn-sm mt-2"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Utwórz zaznaczone listy</button>
  </form>
</div>

<div class="ab-card"><h2><i class="bi bi-robot" aria-hidden="true"></i> Zapytaj AI o statusy, reguły i listy</h2>
  <form method="post" class="ab-body"><?= csrf_field() ?><input type="hidden" name="_op" value="ask">
    <p class="ab-help mb-2">Model dostaje wyłącznie <strong>zbiorcze statystyki</strong> (liczby wg typu, statusu, branży, województwa, najczęstsze domeny firm) — bez nazw osób, adresów e-mail i treści korespondencji. Nic nie jest zakładane bez Twojego zatwierdzenia.</p>
    <label class="form-label small fw-semibold" for="abGoal">Czego potrzebujesz?</label>
    <textarea id="abGoal" name="goal" rows="3" class="form-control form-control-sm" maxlength="600"><?= h($goal) ?></textarea>
    <div class="mt-2 d-flex gap-2 align-items-center">
      <button class="btn btn-crm-primary btn-sm" <?= $ai_ok ? '' : 'disabled' ?>><i class="bi bi-stars me-1" aria-hidden="true"></i>Zaproponuj</button>
      <?php if (!$ai_ok): ?><span class="ab-help">Brak klucza Anthropic API — Admin → Ustawienia AI.</span><?php endif; ?>
    </div>
  </form>
  <?php if ($ai_error): ?><div class="ab-body"><div class="alert alert-danger mb-0" role="alert"><?= h($ai_error) ?></div></div><?php endif; ?>
</div>

<?php if ($proposal): $nothing = !$proposal['statuses'] && !$proposal['rules'] && !$proposal['segments']; ?>
<form method="post" class="ab-card"><?= csrf_field() ?><input type="hidden" name="_op" value="apply"><input type="hidden" name="token" value="<?= h($token) ?>">
  <h2><i class="bi bi-lightbulb" aria-hidden="true"></i> Propozycje modelu</h2>
  <div class="ab-body">
    <?php if ($proposal['summary']): ?><p class="mb-3"><?= h($proposal['summary']) ?></p><?php endif; ?>
    <?php if ($nothing): ?><p class="ab-help mb-0">Model nie znalazł nic nowego do zaproponowania (albo wszystko już istnieje).</p><?php endif; ?>

    <?php if ($proposal['statuses']): ?><div class="ab-grp">Statusy</div>
      <?php foreach ($proposal['statuses'] as $i => $s): ?>
      <label class="ab-item"><input type="checkbox" name="sel[statuses][]" value="<?= $i ?>" checked>
        <span><span class="ab-dot" style="background:<?= h($s['color']) ?>"></span><strong><?= h($s['label']) ?></strong> <code><?= h($s['slug']) ?></code>
          <div class="ab-help"><?= h($s['reason']) ?></div></span></label>
      <?php endforeach; endif; ?>

    <?php if ($proposal['rules']): ?><div class="ab-grp">Reguły automatyczne (powstaną wyłączone)</div>
      <?php foreach ($proposal['rules'] as $i => $r): ?>
      <label class="ab-item"><input type="checkbox" name="sel[rules][]" value="<?= $i ?>" checked>
        <span><strong><?= h($r['name']) ?></strong> <span class="ab-chip"><?= h(CRM_STATUS_RULE_CONDITIONS[$r['cond']]) ?><?= $r['param'] !== '' ? ': ' . h($r['param']) : '' ?> → <?= h($r['to']) ?></span>
          <span class="ab-chip"><?= (int)$r['candidates'] ?> kontaktów spełnia warunek</span>
          <div class="ab-help"><?= h($r['reason']) ?></div></span></label>
      <?php endforeach; endif; ?>

    <?php if ($proposal['segments']): ?><div class="ab-grp">Listy (segmenty dynamiczne)</div>
      <?php foreach ($proposal['segments'] as $i => $g): ?>
      <label class="ab-item"><input type="checkbox" name="sel[segments][]" value="<?= $i ?>" checked>
        <span><strong><?= h($g['name']) ?></strong> <span class="ab-chip"><?= (int)$g['count'] ?> kontaktów teraz</span>
          <div class="ab-help"><?= h($g['description']) ?></div>
          <div class="ab-help"><i class="bi bi-funnel" aria-hidden="true"></i> <?= h($g['describe']) ?></div></span></label>
      <?php endforeach; endif; ?>

    <?php if (!$nothing): ?><button class="btn btn-crm-primary btn-sm mt-2"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Utwórz zaznaczone</button><?php endif; ?>
  </div>
</form>
<?php endif; ?>
<?php include __DIR__ . '/_nav_end.php'; include dirname(__DIR__) . '/includes/footer_crm.php'; ?>
