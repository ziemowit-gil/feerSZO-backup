<?php
/**
 * panel/dyspozycyjnosc.php — Samoobsługa wolontariusza.
 * Wolontariusz sam określa swoją dyspozycyjność (konkretne sloty data+godziny)
 * oraz zgłasza urlopy (przedziały dat z formalną akceptacją opiekuna).
 *
 * Dostęp: każdy zalogowany użytkownik (require_login). Operuje wyłącznie na
 * własnej umowie wolontariatu (dyspo_contract_for_user).
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/dyspozycyjnosc.php';

require_login();
if (!module_enabled('dyspozycyjnosc_enabled')) {
    flash_set('warning', 'Moduł dyspozycyjności jest wyłączony przez administratora.');
    header('Location: ' . APP_URL . '/panel/index.php'); exit;
}
dyspo_migrate();

$PAGE_TITLE = 'Moja dyspozycyjność';
$user = current_user();
$_db_user = db_one("SELECT microsoft_id FROM users WHERE id=?", [(int)$user['id']]);
$user['microsoft_id'] = $_db_user['microsoft_id'] ?? '';

$contract = dyspo_contract_for_user($user);
$cid      = $contract ? (int)$contract['id'] : 0;

$slots  = $cid ? dyspo_slots($cid) : [];
$urlopy = $cid ? urlop_list($cid)  : [];

$_action_url = APP_URL . '/contracts/wolontariat/dyspo_action.php';
$_return_url = APP_URL . '/panel/dyspozycyjnosc.php';
$_today      = date('Y-m-d');

$_is_volunteer_only = is_viewer() && !db_one("SELECT id FROM users WHERE id=? AND k30_consultant=1", [(int)$user['id']]);

if ($_is_volunteer_only) {
    include __DIR__ . '/includes/header_panel.php';
} else {
    include dirname(__DIR__) . '/includes/header.php';
}
?>
<style>
.dy-wrap{max-width:980px;margin:0 auto}
.dy-page-title{font-size:1.25rem;font-weight:800;color:#111827;margin:0 0 .15rem;display:flex;align-items:center;gap:.5rem}
.dy-page-sub{color:#6B7280;font-size:.9rem;margin:0 0 1.25rem}
.dy-card{background:#fff;border:1px solid #E5E7EB;border-radius:14px;overflow:hidden;margin-bottom:1.25rem;box-shadow:0 1px 6px rgba(0,0,0,.05)}
.dy-card-head{display:flex;align-items:center;gap:.55rem;padding:.85rem 1.1rem;border-bottom:1px solid #F3F4F6;font-weight:700;color:#1F2937}
.dy-card-head .dy-ico{width:34px;height:34px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0}
.dy-card-head small{font-weight:400;color:#9CA3AF;font-size:.78rem}
.dy-card-body{padding:1rem 1.1rem}
.dy-row{display:flex;align-items:center;gap:.75rem;padding:.6rem .75rem;border:1px solid #EEF2F7;border-radius:10px;margin-bottom:.5rem;background:#FBFCFE}
.dy-row:last-child{margin-bottom:0}
.dy-row .dy-when{font-weight:700;color:#111827;font-size:.92rem}
.dy-row .dy-meta{font-size:.8rem;color:#6B7280}
.dy-empty{text-align:center;color:#9CA3AF;padding:1.25rem;font-size:.9rem}
.dy-add{display:flex;flex-wrap:wrap;gap:.6rem;align-items:flex-end;margin-top:.9rem;padding-top:.9rem;border-top:1px dashed #E5E7EB}
.dy-add .fg{display:flex;flex-direction:column;gap:.2rem}
.dy-add label{font-size:.72rem;font-weight:700;color:#6B7280;text-transform:uppercase;letter-spacing:.03em}
.dy-add input,.dy-add textarea{border:1px solid #D1D5DB;border-radius:8px;padding:.4rem .6rem;font-size:.9rem}
.dy-badge-src{font-size:.66rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;padding:.1rem .45rem;border-radius:20px;background:#EEF2FF;color:#4338CA}
.dy-note{background:#F9FAFB;border:1px solid #F3F4F6;border-radius:8px;padding:.5rem .75rem;font-size:.82rem;color:#4B5563;margin-top:.4rem}
</style>

<?php if ($_is_volunteer_only): ?><?= flash_html() ?><?php endif; ?>

<div class="dy-wrap">

  <h1 class="dy-page-title"><i class="bi bi-calendar-heart" style="color:#2563EB"></i>Moja dyspozycyjność</h1>
  <p class="dy-page-sub">Określ, kiedy jesteś dostępny(a) do działań, oraz zgłoś urlop lub okres niedostępności.</p>

  <?php if (!$cid): ?>
    <div class="dy-card"><div class="dy-card-body dy-empty">
      <i class="bi bi-info-circle d-block mb-2" style="font-size:1.6rem"></i>
      Nie znaleźliśmy przypisanej do Ciebie umowy wolontariatu.<br>
      Skontaktuj się z opiekunem, jeśli uważasz, że to błąd.
    </div></div>
  <?php else: ?>

  <!-- ── Dyspozycyjność (sloty) ─────────────────────────────────── -->
  <div class="dy-card">
    <div class="dy-card-head">
      <div class="dy-ico" style="background:#EFF6FF;color:#2563EB"><i class="bi bi-clock"></i></div>
      <div>Kiedy jestem dostępny(a) <small>konkretne terminy: data + godziny</small></div>
    </div>
    <div class="dy-card-body">

      <?php if (!$slots): ?>
        <div class="dy-empty">Nie dodano jeszcze żadnych terminów dostępności.</div>
      <?php else: foreach ($slots as $s): ?>
        <div class="dy-row">
          <div class="dy-ico" style="background:#EFF6FF;color:#2563EB;width:38px;height:38px"><i class="bi bi-calendar-event"></i></div>
          <div style="flex:1;min-width:0">
            <div class="dy-when"><?= h(date_pl($s['data'])) ?> · <?= h(substr($s['czas_od'],0,5)) ?>–<?= h(substr($s['czas_do'],0,5)) ?></div>
            <?php if (!empty($s['notatka'])): ?><div class="dy-meta"><?= h($s['notatka']) ?></div><?php endif; ?>
          </div>
          <?php if (($s['source'] ?? '') === 'admin'): ?><span class="dy-badge-src">opiekun</span><?php endif; ?>
          <form method="post" action="<?= h($_action_url) ?>" onsubmit="return confirm('Usunąć ten termin?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="slot_del">
            <input type="hidden" name="contract_id" value="<?= $cid ?>">
            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
            <input type="hidden" name="return" value="<?= h($_return_url) ?>">
            <button class="btn btn-sm btn-outline-danger border-0" title="Usuń"><i class="bi bi-trash"></i></button>
          </form>
        </div>
      <?php endforeach; endif; ?>

      <form method="post" action="<?= h($_action_url) ?>" class="dy-add">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="slot_add">
        <input type="hidden" name="contract_id" value="<?= $cid ?>">
        <input type="hidden" name="return" value="<?= h($_return_url) ?>">
        <div class="fg"><label>Data</label><input type="date" name="data" min="<?= $_today ?>" required></div>
        <div class="fg"><label>Od</label><input type="time" name="czas_od" required></div>
        <div class="fg"><label>Do</label><input type="time" name="czas_do" required></div>
        <div class="fg" style="flex:1;min-width:160px"><label>Notatka (opcjonalnie)</label><input type="text" name="notatka" placeholder="np. tylko zdalnie"></div>
        <button class="btn btn-primary"><i class="bi bi-plus-lg me-1"></i>Dodaj termin</button>
      </form>

    </div>
  </div>

  <!-- ── Urlopy / niedostępność ─────────────────────────────────── -->
  <div class="dy-card">
    <div class="dy-card-head">
      <div class="dy-ico" style="background:#FEF3C7;color:#D97706"><i class="bi bi-airplane"></i></div>
      <div>Urlop / niedostępność <small>zgłoszenie wymaga formalnej akceptacji opiekuna</small></div>
    </div>
    <div class="dy-card-body">

      <?php if (!$urlopy): ?>
        <div class="dy-empty">Brak zgłoszonych urlopów.</div>
      <?php else: foreach ($urlopy as $u):
        $same = $u['data_od'] === $u['data_do'];
      ?>
        <div class="dy-row" style="flex-wrap:wrap">
          <div class="dy-ico" style="background:#FEF3C7;color:#D97706;width:38px;height:38px"><i class="bi bi-airplane-engines"></i></div>
          <div style="flex:1;min-width:0">
            <div class="dy-when">
              <?= h(date_pl($u['data_od'])) ?><?= $same ? '' : ' – ' . h(date_pl($u['data_do'])) ?>
            </div>
            <?php if (!empty($u['powod'])): ?><div class="dy-meta"><?= h($u['powod']) ?></div><?php endif; ?>
          </div>
          <?= urlop_status_badge($u['status']) ?>
          <?php if ($u['status'] === 'oczekuje'): ?>
          <form method="post" action="<?= h($_action_url) ?>" onsubmit="return confirm('Wycofać wniosek o urlop?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="urlop_del">
            <input type="hidden" name="contract_id" value="<?= $cid ?>">
            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <input type="hidden" name="return" value="<?= h($_return_url) ?>">
            <button class="btn btn-sm btn-outline-danger border-0" title="Wycofaj"><i class="bi bi-x-lg"></i></button>
          </form>
          <?php endif; ?>
          <?php if (!empty($u['decision_note'])): ?>
            <div class="dy-note" style="flex-basis:100%"><i class="bi bi-chat-left-text me-1"></i><strong>Opiekun:</strong> <?= h($u['decision_note']) ?></div>
          <?php endif; ?>
        </div>
      <?php endforeach; endif; ?>

      <form method="post" action="<?= h($_action_url) ?>" class="dy-add">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="urlop_add">
        <input type="hidden" name="contract_id" value="<?= $cid ?>">
        <input type="hidden" name="return" value="<?= h($_return_url) ?>">
        <div class="fg"><label>Od</label><input type="date" name="data_od" min="<?= $_today ?>" required></div>
        <div class="fg"><label>Do</label><input type="date" name="data_do" min="<?= $_today ?>" required></div>
        <div class="fg" style="flex:1;min-width:180px"><label>Powód (opcjonalnie)</label><input type="text" name="powod" placeholder="np. wyjazd, egzaminy"></div>
        <button class="btn btn-warning text-dark"><i class="bi bi-plus-lg me-1"></i>Zgłoś urlop</button>
      </form>

    </div>
  </div>

  <?php endif; ?>

</div>

<?php
if ($_is_volunteer_only) {
    include __DIR__ . '/includes/footer_panel.php';
} else {
    include dirname(__DIR__) . '/includes/footer.php';
}
