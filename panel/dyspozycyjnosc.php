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
require_once __DIR__ . '/includes/pv_ui.php';
?>

<?php if ($_is_volunteer_only): ?><?= flash_html() ?><?php endif; ?>

<div class="pv-wrap">

<?php pv_page_header('Dyspozycyjność', [
    'icon' => 'bi-calendar-check',
    'sub'  => 'Twój harmonogram dostępności',
    'back' => ['url' => APP_URL . '/panel/index.php', 'label' => 'Panel'],
]); ?>

  <?php if (!$cid): ?>
    <div class="pv-empty" role="status" aria-label="Brak umowy wolontariatu">
      <i class="bi bi-calendar-x" aria-hidden="true"></i>
      <div class="pv-empty-title">Nie znaleźliśmy przypisanej do Ciebie umowy wolontariatu.</div>
      <div class="pv-empty-sub">Skontaktuj się z opiekunem, jeśli uważasz, że to błąd.</div>
    </div>
  <?php else: ?>

  <!-- ── Dyspozycyjność (sloty) ─────────────────────────────────── -->
  <div class="tz-card">
    <div class="tz-card__hd">
      <i class="bi bi-clock" aria-hidden="true"></i>
      <span>Kiedy jestem dostępny(a)</span>
      <small class="fw-normal ms-1 text-muted" style="font-size:.78rem">konkretne terminy: data + godziny</small>
    </div>
    <div class="tz-card__bd">

      <?php if (!$slots): ?>
        <p class="text-center text-muted small py-2 mb-3" role="status">Nie dodano jeszcze żadnych terminów dostępności.</p>
      <?php else: foreach ($slots as $s): ?>
        <div class="d-flex align-items-center gap-3 py-2 border-bottom">
          <i class="bi bi-calendar-event text-primary flex-shrink-0 fs-5" aria-hidden="true"></i>
          <div class="flex-grow-1" style="min-width:0">
            <div class="fw-semibold" style="font-size:.9rem"><?= h(date_pl($s['data'])) ?> · <?= h(substr($s['czas_od'],0,5)) ?>–<?= h(substr($s['czas_do'],0,5)) ?></div>
            <?php if (!empty($s['notatka'])): ?><div class="text-muted" style="font-size:.8rem"><?= h($s['notatka']) ?></div><?php endif; ?>
          </div>
          <?php if (($s['source'] ?? '') === 'admin'): ?><span class="tz-badge">opiekun</span><?php endif; ?>
          <form method="post" action="<?= h($_action_url) ?>" onsubmit="return confirm('Usunąć ten termin?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="slot_del">
            <input type="hidden" name="contract_id" value="<?= $cid ?>">
            <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
            <input type="hidden" name="return" value="<?= h($_return_url) ?>">
            <button type="submit" class="btn btn-sm btn-outline-danger border-0"
                    aria-label="Usuń termin <?= h(date_pl($s['data'])) ?>">
              <i class="bi bi-trash" aria-hidden="true"></i>
            </button>
          </form>
        </div>
      <?php endforeach; endif; ?>

      <form method="post" action="<?= h($_action_url) ?>"
            class="d-flex flex-wrap gap-2 align-items-end pt-3 mt-2 border-top">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="slot_add">
        <input type="hidden" name="contract_id" value="<?= $cid ?>">
        <input type="hidden" name="return" value="<?= h($_return_url) ?>">
        <div class="d-flex flex-column">
          <label for="slot-data" class="form-label form-label-sm fw-semibold mb-1">Data</label>
          <input id="slot-data" type="date" name="data" class="form-control form-control-sm"
                 min="<?= $_today ?>" required>
        </div>
        <div class="d-flex flex-column">
          <label for="slot-od" class="form-label form-label-sm fw-semibold mb-1">Od</label>
          <input id="slot-od" type="time" name="czas_od" class="form-control form-control-sm" required>
        </div>
        <div class="d-flex flex-column">
          <label for="slot-do" class="form-label form-label-sm fw-semibold mb-1">Do</label>
          <input id="slot-do" type="time" name="czas_do" class="form-control form-control-sm" required>
        </div>
        <div class="d-flex flex-column flex-grow-1" style="min-width:160px">
          <label for="slot-notatka" class="form-label form-label-sm fw-semibold mb-1">Notatka (opcjonalnie)</label>
          <input id="slot-notatka" type="text" name="notatka" class="form-control form-control-sm"
                 placeholder="np. tylko zdalnie">
        </div>
        <button type="submit" class="tz-btn">
          <i class="bi bi-plus-lg" aria-hidden="true"></i>Dodaj termin
        </button>
      </form>

    </div>
  </div>

  <!-- ── Urlopy / niedostępność ─────────────────────────────────── -->
  <div class="tz-card">
    <div class="tz-card__hd">
      <i class="bi bi-airplane" aria-hidden="true"></i>
      <span>Urlop / niedostępność</span>
      <small class="fw-normal ms-1 text-muted" style="font-size:.78rem">zgłoszenie wymaga formalnej akceptacji opiekuna</small>
    </div>
    <div class="tz-card__bd">

      <?php if (!$urlopy): ?>
        <p class="text-center text-muted small py-2 mb-3" role="status">Brak zgłoszonych urlopów.</p>
      <?php else: foreach ($urlopy as $u):
        $same = $u['data_od'] === $u['data_do'];
      ?>
        <div class="d-flex align-items-center gap-3 py-2 border-bottom flex-wrap">
          <i class="bi bi-airplane-engines text-warning flex-shrink-0 fs-5" aria-hidden="true"></i>
          <div class="flex-grow-1" style="min-width:0">
            <div class="fw-semibold" style="font-size:.9rem">
              <?= h(date_pl($u['data_od'])) ?><?= $same ? '' : ' – ' . h(date_pl($u['data_do'])) ?>
            </div>
            <?php if (!empty($u['powod'])): ?><div class="text-muted" style="font-size:.8rem"><?= h($u['powod']) ?></div><?php endif; ?>
          </div>
          <?= urlop_status_badge($u['status']) ?>
          <?php if ($u['status'] === 'oczekuje'): ?>
          <form method="post" action="<?= h($_action_url) ?>"
                onsubmit="return confirm('Wycofać wniosek o urlop?')">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="urlop_del">
            <input type="hidden" name="contract_id" value="<?= $cid ?>">
            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
            <input type="hidden" name="return" value="<?= h($_return_url) ?>">
            <button type="submit" class="btn btn-sm btn-outline-danger border-0"
                    aria-label="Wycofaj wniosek o urlop od <?= h(date_pl($u['data_od'])) ?>">
              <i class="bi bi-x-lg" aria-hidden="true"></i>
            </button>
          </form>
          <?php endif; ?>
          <?php if (!empty($u['decision_note'])): ?>
            <div class="tz-note w-100 mb-0 mt-2" role="note">
              <i class="bi bi-chat-left-text" aria-hidden="true"></i>
              <div><strong>Opiekun:</strong> <?= h($u['decision_note']) ?></div>
            </div>
          <?php endif; ?>
        </div>
      <?php endforeach; endif; ?>

      <form method="post" action="<?= h($_action_url) ?>"
            class="d-flex flex-wrap gap-2 align-items-end pt-3 mt-2 border-top">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="urlop_add">
        <input type="hidden" name="contract_id" value="<?= $cid ?>">
        <input type="hidden" name="return" value="<?= h($_return_url) ?>">
        <div class="d-flex flex-column">
          <label for="urlop-od" class="form-label form-label-sm fw-semibold mb-1">Od</label>
          <input id="urlop-od" type="date" name="data_od" class="form-control form-control-sm"
                 min="<?= $_today ?>" required>
        </div>
        <div class="d-flex flex-column">
          <label for="urlop-do" class="form-label form-label-sm fw-semibold mb-1">Do</label>
          <input id="urlop-do" type="date" name="data_do" class="form-control form-control-sm"
                 min="<?= $_today ?>" required>
        </div>
        <div class="d-flex flex-column flex-grow-1" style="min-width:180px">
          <label for="urlop-powod" class="form-label form-label-sm fw-semibold mb-1">Powód (opcjonalnie)</label>
          <input id="urlop-powod" type="text" name="powod" class="form-control form-control-sm"
                 placeholder="np. wyjazd, egzaminy">
        </div>
        <button type="submit" class="tz-btn">
          <i class="bi bi-plus-lg" aria-hidden="true"></i>Zgłoś urlop
        </button>
      </form>

    </div>
  </div>

  <?php endif; ?>

</div><!-- /.pv-wrap -->

<?php
if ($_is_volunteer_only) {
    include __DIR__ . '/includes/footer_panel.php';
} else {
    include dirname(__DIR__) . '/includes/footer.php';
}
