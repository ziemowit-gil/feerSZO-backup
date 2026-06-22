<?php
/**
 * crm/templates.php — Zarządzanie szablonami wiadomości CRM (crm_templates).
 *
 * Lista + pełny CRUD: tworzenie, edycja, usuwanie oraz włączanie/wyłączanie.
 * Szablony są wykorzystywane w: communicate.php, compose_modal.php, mass_send.php.
 */

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/crm.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
crm_migrate();

$crm_can_write  = can_write('crm') || is_admin();
$crm_can_delete = can_delete('crm') || is_admin();
$is_admin_user  = is_admin();
$PAGE_TITLE = 'CRM — Szablony wiadomości';

// Brakujące dane nadawcy (do monitu o uzupełnieniu profilu)
$sender_missing = CrmManager::senderMissing();

$CHANNELS = [
    'email' => ['label' => 'E-mail', 'icon' => 'bi-envelope-fill', 'color' => '#0176D3'],
    'sms'   => ['label' => 'SMS',    'icon' => 'bi-phone-fill',    'color' => '#B45309'],
];

// ── POST ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $crm_can_write) {
    csrf_check();
    $action  = $_POST['_action'] ?? '';
    $user_id = (int)(current_user()['id'] ?? 0);

    if ($action === 'create' || $action === 'update') {
        $tid     = (int)($_POST['template_id'] ?? 0);
        $name    = trim($_POST['name'] ?? '');
        $channel = in_array($_POST['channel'] ?? '', ['email', 'sms'], true) ? $_POST['channel'] : 'email';
        $subject = $channel === 'email' ? (trim($_POST['subject'] ?? '') ?: null) : null;
        $body    = trim($_POST['body'] ?? '');
        $active  = !empty($_POST['is_active']) ? 1 : 0;

        // Szablon zastrzeżony — flagę ustawia wyłącznie administrator
        $existing = $tid ? db_one("SELECT * FROM crm_templates WHERE id=?", [$tid]) : null;
        $locked   = $is_admin_user
            ? (!empty($_POST['is_locked']) ? 1 : 0)
            : (int)($existing['is_locked'] ?? 0);

        if ($action === 'update' && $existing && (int)$existing['is_locked'] === 1 && !$is_admin_user) {
            flash_set('danger', 'Szablon zastrzeżony — może go edytować tylko administrator.');
        } elseif ($name === '' || $body === '') {
            flash_set('danger', 'Nazwa i treść szablonu są wymagane.');
        } else {
            // Unikalność nazwy (z pominięciem edytowanego rekordu)
            $dup = db_one("SELECT id FROM crm_templates WHERE name=? AND id<>?", [$name, $tid]);
            if ($dup) {
                flash_set('danger', 'Szablon o nazwie „' . $name . '" już istnieje.');
            } elseif ($action === 'update' && $tid) {
                db()->prepare(
                    "UPDATE crm_templates SET name=?, channel=?, subject=?, body=?, is_active=?, is_locked=?, updated_at=? WHERE id=?"
                )->execute([$name, $channel, $subject, $body, $active, $locked, date('Y-m-d H:i:s'), $tid]);
                flash_set('success', 'Szablon „' . $name . '" zaktualizowany.');
            } else {
                db_insert('crm_templates', [
                    'name'       => $name,
                    'channel'    => $channel,
                    'subject'    => $subject,
                    'body'       => $body,
                    'is_active'  => $active,
                    'is_locked'  => $locked,
                    'created_by' => $user_id,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                flash_set('success', 'Szablon „' . $name . '" utworzony.');
            }
        }
    }

    if ($action === 'toggle') {
        $tid = (int)($_POST['template_id'] ?? 0);
        $t   = db_one("SELECT id, is_active, is_locked FROM crm_templates WHERE id=?", [$tid]);
        if ($t && (int)$t['is_locked'] === 1 && !$is_admin_user) {
            flash_set('danger', 'Szablon zastrzeżony — może go zmieniać tylko administrator.');
        } elseif ($t) {
            $new = $t['is_active'] ? 0 : 1;
            db()->prepare("UPDATE crm_templates SET is_active=?, updated_at=? WHERE id=?")
                ->execute([$new, date('Y-m-d H:i:s'), $tid]);
            flash_set('success', $new ? 'Szablon aktywowany.' : 'Szablon wyłączony.');
        }
    }

    if ($action === 'delete' && $crm_can_delete) {
        $tid = (int)($_POST['template_id'] ?? 0);
        $t   = db_one("SELECT name, is_locked FROM crm_templates WHERE id=?", [$tid]);
        if ($t && (int)$t['is_locked'] === 1 && !$is_admin_user) {
            flash_set('danger', 'Szablon zastrzeżony — może go usunąć tylko administrator.');
        } elseif ($t) {
            db()->prepare("DELETE FROM crm_templates WHERE id=?")->execute([$tid]);
            flash_set('success', 'Szablon „' . ($t['name'] ?? '') . '" usunięty.');
        }
    }

    header('Location: ' . APP_URL . '/crm/templates.php');
    exit;
}

$templates = db_all("SELECT * FROM crm_templates ORDER BY is_active DESC, channel, name");
$active_cnt = count(array_filter($templates, fn($t) => (int)$t['is_active'] === 1));

include __DIR__ . '/includes/header_crm.php';
?>

<style>
.tpl-row{
  background:#fff;border:1px solid var(--crm-border);border-radius:var(--crm-radius-lg);
  box-shadow:var(--crm-shadow);padding:1rem 1.1rem;display:flex;align-items:flex-start;gap:.9rem;
}
.tpl-row.is-off{opacity:.62}
.tpl-icon{
  width:42px;height:42px;border-radius:10px;flex-shrink:0;
  display:flex;align-items:center;justify-content:center;font-size:1.2rem;color:#fff;
}
.tpl-name{font-weight:700;font-size:.95rem;color:var(--crm-text)}
.tpl-meta{font-size:.78rem;color:#5E6470;margin-top:.15rem}
.tpl-preview{
  font-size:.82rem;color:#3E3E3C;margin-top:.45rem;white-space:pre-wrap;
  max-height:3.4em;overflow:hidden;line-height:1.5;
  -webkit-mask-image:linear-gradient(#000 60%,transparent);mask-image:linear-gradient(#000 60%,transparent);
}
.tpl-badge{
  display:inline-flex;align-items:center;gap:.3rem;padding:.12rem .55rem;border-radius:2rem;
  font-size:.72rem;font-weight:600;
}
.tpl-actions{display:flex;gap:.25rem;flex-shrink:0}
.var-chip{
  font-family:monospace;font-size:.74rem;background:var(--crm-primary-bg);
  border:1px solid var(--crm-primary-light);color:var(--crm-primary-dark);
  border-radius:6px;padding:.1rem .4rem;cursor:pointer;
}
.var-chip:hover{background:var(--crm-primary);color:#fff}
</style>

<div class="crm-page-header mb-4">
  <div>
    <h1 class="crm-page-title"><i class="bi bi-file-earmark-text" style="color:var(--crm-primary)" aria-hidden="true"></i> Szablony wiadomości</h1>
    <div class="crm-page-subtitle"><?= count($templates) ?> szablonów · <?= $active_cnt ?> aktywnych · używane w e-mailach i SMS</div>
  </div>
  <div class="crm-page-actions">
    <?php if ($crm_can_write): ?>
    <button class="btn btn-crm-primary btn-sm" data-bs-toggle="modal" data-bs-target="#tplModal" onclick="tplNew()">
      <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nowy szablon
    </button>
    <?php endif; ?>
    <a href="<?= APP_URL ?>/crm/communicate.php" class="btn btn-crm-outline btn-sm">
      <i class="bi bi-send me-1" aria-hidden="true"></i>Wyślij wiadomość
    </a>
  </div>
</div>

<!-- Monit: niekompletne dane nadawcy (zalogowanego użytkownika) -->
<?php if ($sender_missing): ?>
<div class="alert alert-warning d-flex align-items-start gap-2 mb-3" role="alert" style="border-radius:10px">
  <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1" aria-hidden="true"></i>
  <div style="font-size:.86rem">
    Twoje dane używane w zmiennych nadawcy są niekompletne — brakuje:
    <strong><?= h(implode(', ', $sender_missing)) ?></strong>.
    Szablony ze zmiennymi <code>{nadawca_…}</code> wstawią w tych miejscach pustą wartość.
    <a href="<?= APP_URL ?>/panel/index.php" class="alert-link">Uzupełnij lub zweryfikuj swoje dane →</a>
  </div>
</div>
<?php endif; ?>

<!-- Pomoc: zmienne -->
<div class="cv-panel" style="background:var(--crm-primary-bg);border-color:var(--crm-primary-light)">
  <div class="cv-panel__body" style="padding:.7rem 1rem;font-size:.82rem">
    <div class="d-flex flex-wrap align-items-center gap-1">
      <span class="fw-semibold" style="color:var(--crm-primary-dark)">
        <i class="bi bi-person-lines-fill me-1" aria-hidden="true"></i>Odbiorca:
      </span>
      <?php foreach (['{imie}','{imie_nazwisko}','{email}','{telefon}','{organizacja}','{stanowisko}'] as $v): ?>
      <button type="button" class="var-chip" onclick="tplCopyVar(this)" data-var="<?= h($v) ?>"><?= h($v) ?></button>
      <?php endforeach; ?>
    </div>
    <div class="d-flex flex-wrap align-items-center gap-1 mt-2">
      <span class="fw-semibold" style="color:var(--crm-primary-dark)">
        <i class="bi bi-person-badge-fill me-1" aria-hidden="true"></i>Nadawca (Ty):
      </span>
      <?php foreach (['{nadawca_imie}','{nadawca_nazwisko}','{nadawca_imie_nazwisko}','{nadawca_email}','{nadawca_telefon}'] as $v): ?>
      <button type="button" class="var-chip" onclick="tplCopyVar(this)" data-var="<?= h($v) ?>"><?= h($v) ?></button>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<?php if (!$templates): ?>
<div class="cv-empty py-5">
  <i class="bi bi-file-earmark-text" aria-hidden="true"></i>
  <h2 class="h6 text-muted">Brak szablonów</h2>
  <p class="mb-3" style="font-size:.85rem">Szablony przyspieszają wysyłkę powtarzalnych wiadomości e-mail i SMS.</p>
  <?php if ($crm_can_write): ?>
  <button class="btn btn-crm-primary btn-sm" data-bs-toggle="modal" data-bs-target="#tplModal" onclick="tplNew()">
    <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Utwórz pierwszy szablon
  </button>
  <?php endif; ?>
</div>
<?php else: ?>

<div class="d-flex flex-column gap-2 mt-3">
  <?php foreach ($templates as $t):
    $ch     = $CHANNELS[$t['channel']] ?? $CHANNELS['email'];
    $off    = (int)$t['is_active'] !== 1;
    $locked = (int)($t['is_locked'] ?? 0) === 1;
    // Zastrzeżony szablon może modyfikować tylko administrator
    $can_mod_this = $crm_can_write  && (!$locked || $is_admin_user);
    $can_del_this = $crm_can_delete && (!$locked || $is_admin_user);
  ?>
  <div class="tpl-row<?= $off ? ' is-off' : '' ?>">
    <div class="tpl-icon" style="background:<?= $ch['color'] ?>" aria-hidden="true">
      <i class="bi <?= $ch['icon'] ?>"></i>
    </div>
    <div style="flex:1;min-width:0">
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <span class="tpl-name"><?= h($t['name']) ?></span>
        <span class="tpl-badge" style="background:<?= $ch['color'] ?>1a;color:<?= $ch['color'] ?>">
          <i class="bi <?= $ch['icon'] ?>" aria-hidden="true"></i><?= $ch['label'] ?>
        </span>
        <?php if ($locked): ?>
        <span class="tpl-badge" style="background:#FEF3E2;color:#92400E" title="Edytować i usuwać może tylko administrator">
          <i class="bi bi-shield-lock-fill" aria-hidden="true"></i>Zastrzeżony
        </span>
        <?php endif; ?>
        <?php if ($off): ?>
        <span class="tpl-badge" style="background:#F3F4F6;color:#5E6470">
          <i class="bi bi-pause-circle" aria-hidden="true"></i>Wyłączony
        </span>
        <?php endif; ?>
      </div>
      <?php if ($t['channel'] === 'email' && $t['subject']): ?>
      <div class="tpl-meta"><i class="bi bi-card-heading me-1" aria-hidden="true"></i><?= h($t['subject']) ?></div>
      <?php endif; ?>
      <div class="tpl-preview"><?= h(mb_substr($t['body'], 0, 240)) ?></div>
      <div class="tpl-meta mt-1">Zmieniono: <?= h(date('d.m.Y', strtotime($t['updated_at'] ?? $t['created_at'] ?? 'now'))) ?></div>
    </div>
    <?php if ($crm_can_write && $locked && !$is_admin_user): ?>
    <div class="tpl-actions align-items-center">
      <span class="cv-meta" style="white-space:nowrap" title="Edytować i usuwać może tylko administrator">
        <i class="bi bi-lock-fill me-1" aria-hidden="true"></i>tylko admin
      </span>
    </div>
    <?php elseif ($crm_can_write): ?>
    <div class="tpl-actions">
      <button class="btn btn-sm btn-outline-secondary py-0 px-2" title="Edytuj" aria-label="Edytuj szablon <?= h($t['name']) ?>"
              onclick='tplEdit(<?= json_encode([
                  'id' => (int)$t['id'], 'name' => $t['name'], 'channel' => $t['channel'],
                  'subject' => $t['subject'] ?? '', 'body' => $t['body'],
                  'is_active' => (int)$t['is_active'], 'is_locked' => (int)($t['is_locked'] ?? 0),
              ], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>)'>
        <i class="bi bi-pencil" aria-hidden="true"></i>
      </button>
      <form method="post" class="d-inline">
        <input type="hidden" name="_csrf"        value="<?= csrf_token() ?>">
        <input type="hidden" name="_action"      value="toggle">
        <input type="hidden" name="template_id"  value="<?= (int)$t['id'] ?>">
        <button class="btn btn-sm btn-outline-secondary py-0 px-2" type="submit"
                title="<?= $off ? 'Aktywuj' : 'Wyłącz' ?>"
                aria-label="<?= $off ? 'Aktywuj' : 'Wyłącz' ?> szablon <?= h($t['name']) ?>">
          <i class="bi <?= $off ? 'bi-play-circle' : 'bi-pause-circle' ?>" aria-hidden="true"></i>
        </button>
      </form>
      <?php if ($can_del_this): ?>
      <form method="post" class="d-inline"
            onsubmit="return confirm('Usunąć szablon „<?= h(addslashes($t['name'])) ?>”? Tej operacji nie można cofnąć.')">
        <input type="hidden" name="_csrf"       value="<?= csrf_token() ?>">
        <input type="hidden" name="_action"     value="delete">
        <input type="hidden" name="template_id" value="<?= (int)$t['id'] ?>">
        <button class="btn btn-sm btn-outline-danger py-0 px-2" type="submit"
                title="Usuń" aria-label="Usuń szablon <?= h($t['name']) ?>">
          <i class="bi bi-trash" aria-hidden="true"></i>
        </button>
      </form>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>

<?php endif; ?>

<!-- ═══ MODAL: Nowy / Edytuj szablon ═══════════════════════════════════════ -->
<?php if ($crm_can_write): ?>
<div class="modal fade" id="tplModal" tabindex="-1" aria-labelledby="tplModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <form method="post" id="tplForm">
        <input type="hidden" name="_csrf"       value="<?= csrf_token() ?>">
        <input type="hidden" name="_action"     id="tplAction"   value="create">
        <input type="hidden" name="template_id" id="tplId"       value="">
        <div class="modal-header">
          <h2 class="modal-title h6 fw-bold" id="tplModalLabel"><i class="bi bi-file-earmark-text me-2" aria-hidden="true"></i>Nowy szablon</h2>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
        </div>
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-sm-7">
              <label class="form-label fw-semibold small" for="tpl_name">Nazwa <span class="text-danger" aria-hidden="true">*</span></label>
              <input type="text" id="tpl_name" name="name" class="form-control form-control-sm"
                     required maxlength="120" placeholder="np. Powitanie wolontariusza">
            </div>
            <div class="col-sm-5">
              <label class="form-label fw-semibold small" for="tpl_channel">Kanał</label>
              <select id="tpl_channel" name="channel" class="form-select form-select-sm" onchange="tplToggleSubject()">
                <option value="email">E-mail</option>
                <option value="sms">SMS</option>
              </select>
            </div>
            <div class="col-12" id="tpl_subject_wrap">
              <label class="form-label fw-semibold small" for="tpl_subject">Temat (e-mail)</label>
              <input type="text" id="tpl_subject" name="subject" class="form-control form-control-sm"
                     maxlength="200" placeholder="Temat wiadomości">
            </div>
            <div class="col-12">
              <label class="form-label fw-semibold small" for="tpl_body">Treść <span class="text-danger" aria-hidden="true">*</span></label>
              <textarea id="tpl_body" name="body" class="form-control form-control-sm" rows="8" required
                        placeholder="Treść wiadomości. Możesz używać zmiennych, np. {imie}."></textarea>
              <div class="form-text" style="font-size:.74rem">Zmienne: {imie}, {imie_nazwisko}, {email}, {telefon}, {organizacja}, {stanowisko}.</div>
            </div>
            <div class="col-12">
              <div class="form-check">
                <input type="checkbox" id="tpl_active" name="is_active" value="1" class="form-check-input" checked>
                <label class="form-check-label small" for="tpl_active">Aktywny (dostępny przy wysyłce)</label>
              </div>
              <?php if ($is_admin_user): ?>
              <div class="form-check mt-1">
                <input type="checkbox" id="tpl_locked" name="is_locked" value="1" class="form-check-input">
                <label class="form-check-label small" for="tpl_locked">
                  <i class="bi bi-shield-lock-fill me-1" style="color:#92400E" aria-hidden="true"></i>Szablon zastrzeżony — edytować i usuwać może tylko administrator
                </label>
              </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-crm-primary btn-sm"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>Zapisz szablon</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
function tplToggleSubject() {
  var ch = document.getElementById('tpl_channel').value;
  document.getElementById('tpl_subject_wrap').style.display = (ch === 'email') ? '' : 'none';
}

function tplNew() {
  document.getElementById('tplForm').reset();
  document.getElementById('tplAction').value = 'create';
  document.getElementById('tplId').value     = '';
  document.getElementById('tplModalLabel').innerHTML = '<i class="bi bi-file-earmark-text me-2" aria-hidden="true"></i>Nowy szablon';
  document.getElementById('tpl_active').checked = true;
  var lk = document.getElementById('tpl_locked'); if (lk) lk.checked = false;
  tplToggleSubject();
}

function tplEdit(t) {
  document.getElementById('tplAction').value  = 'update';
  document.getElementById('tplId').value      = t.id;
  document.getElementById('tpl_name').value   = t.name || '';
  document.getElementById('tpl_channel').value= t.channel || 'email';
  document.getElementById('tpl_subject').value= t.subject || '';
  document.getElementById('tpl_body').value   = t.body || '';
  document.getElementById('tpl_active').checked = (t.is_active === 1 || t.is_active === '1');
  var lk = document.getElementById('tpl_locked'); if (lk) lk.checked = (t.is_locked === 1 || t.is_locked === '1');
  document.getElementById('tplModalLabel').innerHTML = '<i class="bi bi-pencil me-2" aria-hidden="true"></i>Edytuj szablon';
  tplToggleSubject();
  new bootstrap.Modal(document.getElementById('tplModal')).show();
}

function tplCopyVar(btn) {
  var v = btn.dataset.var;
  if (navigator.clipboard) navigator.clipboard.writeText(v).catch(function(){});
  var orig = btn.textContent;
  btn.textContent = 'skopiowano!';
  setTimeout(function(){ btn.textContent = orig; }, 900);
}
</script>

<?php include __DIR__ . '/includes/footer_crm.php'; ?>
