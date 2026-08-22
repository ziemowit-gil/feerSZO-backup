<?php
/**
 * crm/mass_send.php — Masowa wysyłka e-mail / SMS do grup i tagów.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/crm.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
if (!can_write('crm_mailing') && !is_admin()) {
    flash_set('danger', 'Brak uprawnień do wysyłki masowej.');
    header('Location: ' . APP_URL . '/crm/dashboard.php'); exit;
}
crm_migrate();

$PAGE_TITLE = 'Wysyłka masowa';

require_once dirname(__DIR__) . '/includes/mail_queue.php';
require_once dirname(__DIR__) . '/includes/sms.php';

$sms_ok   = sms_is_enabled();
$m365_ok  = _mail_m365_configured();
$smtp_ok  = (bool)_mail_setting('smtp_host');

// Diagnostyka poczty — sprawdź czy jakakolwiek metoda jest dostępna
$mail_method = 'php_mail'; // fallback zawsze dostępny
if ($m365_ok)  $mail_method = 'm365';
elseif ($smtp_ok) $mail_method = 'smtp';

// Ostrzeżenie gdy ani M365 ani SMTP nie skonfigurowane
$mail_warning = !$m365_ok && !$smtp_ok;
$email_ok     = true; // email zawsze "możliwy" (PHP mail() jako fallback)

// Grupy z liczbą członków
$groups = db_all(
    "SELECT g.*,
            (SELECT COUNT(DISTINCT gm.contact_id) FROM crm_group_members gm WHERE gm.group_id=g.id) AS member_count,
            pg.name AS parent_name
     FROM crm_groups g
     LEFT JOIN crm_groups pg ON pg.id=g.parent_id
     ORDER BY g.parent_id IS NOT NULL, g.parent_id, g.sort_order, g.name"
);

// Tagi z liczbą użyć
$tags = db_all("SELECT tag, COUNT(*) AS cnt FROM crm_tags GROUP BY tag ORDER BY cnt DESC LIMIT 100");

// Historia wysyłek
$history = db_all(
    "SELECT ms.*, g.name AS group_name, u.name AS user_name
     FROM crm_mass_sends ms
     LEFT JOIN crm_groups g ON g.id=ms.group_id
     LEFT JOIN users u ON u.id=ms.created_by
     ORDER BY ms.created_at DESC LIMIT 20"
);

// Załaduj dane kontaktów dla nieotrzymanych (dla każdej wysyłki z błędami)
$history_failures = [];
foreach ($history as $hs) {
    if ($hs['sent_fail'] > 0 && $hs['failed_ids']) {
        $fids = json_decode($hs['failed_ids'], true) ?: [];
        if ($fids) {
            $placeholders = implode(',', array_fill(0, count($fids), '?'));
            $contacts = db_all(
                "SELECT id, imie_nazwisko, email, telefon FROM crm_contacts WHERE id IN ($placeholders)",
                $fids
            );
            $history_failures[$hs['id']] = $contacts;
        }
    }
}

$templates = db_all("SELECT * FROM crm_templates WHERE is_active=1 ORDER BY channel, name");

// Wysyłka z konta M365 zalogowanego użytkownika (do wyboru)
$_cu_now        = current_user();
$can_send_as_me = $m365_ok && !empty($_cu_now['microsoft_id']) && !empty($_cu_now['email']);
$my_ms_email    = $can_send_as_me ? trim($_cu_now['email']) : '';
$sys_from_email = _mail_setting('m365_send_from_email');

include __DIR__ . '/includes/header_crm.php';
?>

<style>
.ms-section { background:#fff;border:1px solid #E5E7EB;border-radius:12px;padding:1.25rem;margin-bottom:1rem;box-shadow:0 1px 3px rgba(0,0,0,.04) }
.ms-section-title { font-size:.9rem;font-weight:700;color:#181818;padding-bottom:.5rem;border-bottom:1px solid #F3F4F6;margin-bottom:.85rem }
/* Krok formularza (numer + tytuł + podpowiedź) */
.ms-step { display:flex; align-items:center; gap:.6rem; margin-bottom:1rem; padding-bottom:.6rem; border-bottom:1px solid #F3F4F6 }
.ms-step__num { width:28px;height:28px;border-radius:50%;background:var(--crm-primary);color:#fff;font-weight:700;font-size:.85rem;display:flex;align-items:center;justify-content:center;flex-shrink:0 }
.ms-step__t { font-size:1rem;font-weight:700;color:#181818;line-height:1.15 }
.ms-step__h { font-size:.77rem;color:#5E6470;margin-top:.05rem }
.ms-step__main { flex:1;min-width:0 }
.group-pill { display:inline-flex;align-items:center;gap:.35rem;padding:.3rem .75rem;border-radius:2rem;border:1.5px solid #E5E7EB;cursor:pointer;font-size:.78rem;font-weight:500;color:#374151;background:#fff;transition:all .12s;margin:.15rem }
.group-pill:hover { border-color:#9CA3AF }
.group-pill.selected { color:#fff;border-color:transparent;font-weight:600 }
.tag-chip { display:inline-flex;align-items:center;gap:.25rem;padding:.2rem .6rem;border-radius:2rem;border:1.5px solid #E5E7EB;cursor:pointer;font-size:.73rem;color:#374151;background:#fff;transition:all .12s;margin:.1rem }
.tag-chip.selected { background:#EFF7ED;border-color:#2E844A;color:#2E844A;font-weight:600 }
.ms-progress-bar { height:8px;background:#E5E7EB;border-radius:4px;overflow:hidden }
.ms-progress-fill { height:100%;border-radius:4px;background:linear-gradient(90deg,#2E844A,#16A34A);transition:width .4s }
.recipient-preview { background:#F9FAFB;border-radius:8px;padding:.75rem;max-height:200px;overflow-y:auto;font-size:.8rem }
/* Kontakt chip */
.contact-chip { display:inline-flex;align-items:center;gap:.3rem;background:#EFF7ED;border:1px solid #A7F3D0;border-radius:2rem;padding:.2rem .5rem .2rem .6rem;font-size:.76rem;color:#065F46;margin:.15rem }
.contact-chip button { background:none;border:none;color:#6B7280;padding:0 .1rem;line-height:1;font-size:.9rem;cursor:pointer }
.contact-chip button:hover { color:#DC2626 }
/* DW chip */
.dw-chip { display:inline-flex;align-items:center;gap:.3rem;background:#EEF4FF;border:1px solid #BFDBFE;border-radius:2rem;padding:.2rem .5rem .2rem .6rem;font-size:.76rem;color:#1E40AF;margin:.15rem }
.dw-chip button { background:none;border:none;color:#6B7280;padding:0 .1rem;line-height:1;font-size:.9rem;cursor:pointer }
.dw-chip button:hover { color:#DC2626 }
/* Dropdown kontaktów */
.contact-dropdown { position:absolute;z-index:1050;background:#fff;border:1px solid #E5E7EB;border-radius:8px;box-shadow:0 4px 16px rgba(0,0,0,.12);max-height:240px;overflow-y:auto;min-width:320px;left:0;top:calc(100% + 4px) }
.contact-dropdown-item { display:flex;flex-direction:column;padding:.5rem .75rem;cursor:pointer;border-bottom:1px solid #F3F4F6;transition:background .1s }
.contact-dropdown-item:last-child { border-bottom:none }
.contact-dropdown-item:hover { background:#F3F4F6 }
.contact-dropdown-item.ms-disabled { background:#FEF3E2;cursor:not-allowed;opacity:.7; }
.contact-dropdown-item.ms-disabled:hover { background:#FEF3E2; }
.contact-dropdown-item .ci-name { font-size:.82rem;font-weight:600;color:#111827 }
.contact-dropdown-item .ci-sub { font-size:.72rem;color:#6B7280 }
/* Wybierz grupę dropdown */
.group-select-dropdown { position:absolute;z-index:1050;background:#fff;border:1px solid #E5E7EB;border-radius:10px;box-shadow:0 6px 20px rgba(0,0,0,.13);padding:.5rem 0;min-width:260px;max-height:320px;overflow-y:auto }
.group-select-item { display:flex;align-items:center;gap:.5rem;padding:.45rem .85rem;cursor:pointer;font-size:.82rem;transition:background .1s }
.group-select-item:hover { background:#F9FAFB }
.group-select-item.is-child { padding-left:2rem;font-size:.78rem;color:#4B5563 }
.group-select-item .gsi-check { width:14px;height:14px;border:2px solid #D1D5DB;border-radius:3px;flex-shrink:0;transition:all .12s;display:flex;align-items:center;justify-content:center }
.group-select-item.selected .gsi-check { background:#0176D3;border-color:#0176D3 }
.group-select-count { font-size:.68rem;color:#9CA3AF;margin-left:auto }
.channel-btn { display:flex;flex-direction:row;align-items:center;gap:.55rem;padding:.55rem .9rem;border-radius:9px;border:1.5px solid #E5E7EB;cursor:pointer;transition:all .12s;flex:1;text-align:left }
.channel-btn.active { border-color:var(--ch-color);background:var(--ch-bg);color:var(--ch-color) }
.channel-btn i { font-size:1.15rem }
.channel-btn span { font-size:.82rem;font-weight:600 }
.channel-btn .ch-text { display:flex;flex-direction:column;line-height:1.15;text-align:left }

/* ── Kreator (wizard) ─────────────────────────────────────────────────── */
.ms-stepper { display:flex; gap:.4rem; margin-bottom:1rem; flex-wrap:wrap; }
.ms-stepper-item { display:flex; align-items:center; gap:.45rem; padding:.4rem .7rem; border-radius:8px;
  background:#fff; border:1px solid #E5E7EB; font-size:.82rem; color:#5E6470; cursor:pointer; flex:1; min-width:120px; }
.ms-stepper-item .ms-stepper-num { width:22px;height:22px;border-radius:50%;background:#E5E7EB;color:#5E6470;
  font-weight:700;font-size:.76rem;display:flex;align-items:center;justify-content:center;flex-shrink:0 }
.ms-stepper-item.active { border-color:var(--crm-primary); background:var(--crm-primary-bg); color:var(--crm-primary-dark); font-weight:600 }
.ms-stepper-item.active .ms-stepper-num { background:var(--crm-primary); color:#fff }
.ms-stepper-item.done .ms-stepper-num { background:var(--crm-primary); color:#fff }
.ms-stepper-item.done { color:var(--crm-primary-dark); }
.ms-stepper-item:focus-visible { outline:3px solid var(--crm-accent); outline-offset:2px }
.wiz-pane { display:none }
.wiz-pane.wiz-active { display:block }
.ms-wiznav { display:flex; align-items:center; gap:.6rem; margin:.25rem 0 1rem }
.ms-wiznav-spacer { flex:1 }
.ms-wizard, .ms-stepper, .crm-page-header { max-width:820px; margin-left:auto; margin-right:auto }
.ms-wizard ~ .ms-section { max-width:820px; margin-left:auto; margin-right:auto }
/* Grupy — kompaktowa lista zamiast dużych kafli */
.ms-group-list { border:1px solid var(--crm-border); border-radius:9px; max-height:230px; overflow-y:auto; }
.ms-group-row { display:flex; align-items:center; gap:.55rem; padding:.45rem .7rem; cursor:pointer;
  border-bottom:1px solid #F3F4F6; font-size:.84rem; color:#374151; }
.ms-group-row:last-child { border-bottom:none }
.ms-group-row:hover { background:#F9FAFB }
.ms-group-row.is-child { padding-left:1.9rem; font-size:.8rem }
.ms-group-row .gsi-check { width:17px;height:17px;border:2px solid #CBD5E1;border-radius:4px;flex-shrink:0;
  display:flex;align-items:center;justify-content:center }
.ms-group-row.selected { background:var(--crm-primary-bg) }
.ms-group-row.selected .gsi-check { background:var(--crm-primary);border-color:var(--crm-primary) }
.ms-group-row .group-select-count { font-size:.72rem;color:#5E6470;margin-left:auto }
.hist-row { display:flex;align-items:center;gap:.75rem;padding:.55rem 0;border-bottom:1px solid #F3F4F6;font-size:.82rem }
.hist-row:last-child { border-bottom:none }
</style>

<div class="crm-page-header">
  <div>
    <div class="crm-page-title"><i class="bi bi-send-fill" style="color:#0176D3"></i> Wysyłka masowa</div>
    <div class="crm-page-subtitle">E-mail i SMS do grup, podgrup i tagów kontaktów</div>
  </div>
</div>

<!-- ══ KREATOR: stepper ══════════════════════════════════════════════════════ -->
<div class="ms-stepper" id="msStepper" role="list" aria-label="Kroki wysyłki masowej">
  <?php foreach (['Kanał','Odbiorcy','Treść','Wyślij'] as $i => $lbl): $n = $i + 1; ?>
  <div class="ms-stepper-item<?= $n === 1 ? ' active' : '' ?>" data-step="<?= $n ?>" role="listitem"
       tabindex="0" onclick="MS.wizGo(<?= $n ?>)" onkeydown="if(event.key==='Enter'||event.key===' '){event.preventDefault();MS.wizGo(<?= $n ?>)}">
    <span class="ms-stepper-num"><?= $n ?></span>
    <span class="ms-stepper-label"><?= h($lbl) ?></span>
  </div>
  <?php endforeach; ?>
</div>

<div class="ms-wizard" id="msWizard">

  <!-- KROK 1: Kanał -->
  <div class="ms-section wiz-pane wiz-active" data-step="1">
    <div class="ms-step">
      <span class="ms-step__num" aria-hidden="true">1</span>
      <div class="ms-step__main">
        <div class="ms-step__t">Kanał wysyłki</div>
        <div class="ms-step__h">Wybierz, czym wyślesz wiadomość</div>
      </div>
    </div>

    <?php if ($mail_warning): ?>
    <div class="alert alert-warning py-2 px-3 small mb-3 d-flex align-items-center gap-2">
      <i class="bi bi-exclamation-triangle-fill flex-shrink-0"></i>
      <div>
        <strong>Brak skonfigurowanego serwera poczty.</strong>
        E-maile będą wysyłane przez PHP mail() (zawodne).
        Skonfiguruj <a href="<?= APP_URL ?>/admin/m365_settings.php">Microsoft 365</a>
        lub <a href="<?= APP_URL ?>/admin/settings.php">SMTP</a> dla pewnej dostarczalności.
      </div>
    </div>
    <?php endif; ?>

    <div class="d-flex gap-2">
      <div class="channel-btn active" id="ch-email" data-channel="email"
           style="--ch-color:#0176D3;--ch-bg:#EEF4FF" onclick="MS.setChannel('email')">
        <i class="bi bi-envelope-fill" style="color:#0176D3"></i>
        <div class="ch-text">
          <span>E-mail</span>
          <?php if ($m365_ok): ?>
          <small style="color:#0078d4;font-size:.68rem;font-weight:600"><i class="bi bi-microsoft"></i> Microsoft 365</small>
          <?php elseif ($smtp_ok): ?>
          <small style="color:#059669;font-size:.68rem;font-weight:600"><i class="bi bi-server"></i> SMTP</small>
          <?php else: ?>
          <small class="text-warning" style="font-size:.68rem"><i class="bi bi-exclamation-triangle"></i> PHP mail()</small>
          <?php endif; ?>
        </div>
      </div>
      <?php if ($sms_ok): ?>
      <div class="channel-btn" id="ch-sms" data-channel="sms"
           style="--ch-color:#D97706;--ch-bg:#FEF3E2" onclick="MS.setChannel('sms')">
        <i class="bi bi-phone-fill" style="color:#D97706"></i>
        <div class="ch-text">
          <span>SMS</span>
          <small class="text-muted" style="font-size:.68rem">SMSAPI.pl</small>
        </div>
      </div>
      <?php else: ?>
      <div class="channel-btn" style="opacity:.4;cursor:default">
        <i class="bi bi-phone" style="color:#9CA3AF"></i>
        <div class="ch-text">
          <span style="color:#5E6470">SMS</span>
          <small style="font-size:.68rem;color:#5E6470">Niekonfigurowany</small>
        </div>
      </div>
      <?php endif; ?>
    </div>
    <input type="hidden" id="ms_channel" value="email">

    <?php if ($can_send_as_me): ?>
    <div id="ms_sender_row" class="mt-3">
      <label class="form-label small fw-semibold mb-1 d-flex align-items-center gap-2" for="ms_send_as">
        <i class="bi bi-person-badge text-primary" aria-hidden="true"></i> Konto nadawcy (e-mail)
      </label>
      <select id="ms_send_as" class="form-select form-select-sm" style="max-width:420px">
        <option value="system">Konto systemowe<?= $sys_from_email ? ' (' . h($sys_from_email) . ')' : '' ?></option>
        <option value="me">Moje konto Microsoft — <?= h($my_ms_email) ?></option>
      </select>
      <div class="form-text" style="font-size:.74rem">
        Wybierając swoje konto, wiadomości wyjdą z Twojej skrzynki M365 i trafią do „Elementów wysłanych".
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- Odbiorcy -->
  <div class="ms-section wiz-pane" data-step="2">
    <div class="ms-step">
      <span class="ms-step__num" aria-hidden="true">2</span>
      <div class="ms-step__main">
        <div class="ms-step__t">Odbiorcy</div>
        <div class="ms-step__h">Grupy, tagi oraz pojedyncze kontakty</div>
      </div>
    </div>

    <?php
    $parent_groups = array_filter($groups, fn($g) => !$g['parent_id']);
    $child_groups  = [];
    foreach ($groups as $g) {
      if ($g['parent_id']) $child_groups[$g['parent_id']][] = $g;
    }
    $groups_json = json_encode(array_values($groups), JSON_UNESCAPED_UNICODE);
    ?>

    <!-- ─── Grupy: kompaktowa, zawsze widoczna lista ─── -->
    <div class="mb-3">
      <label class="form-label small fw-semibold mb-1 d-flex align-items-center gap-2">
        <i class="bi bi-people-fill text-primary"></i> Grupy
        <span id="groupPickerLabel" class="cv-muted fw-normal" style="font-size:.74rem"></span>
      </label>
      <?php if ($groups): ?>
      <input type="text" id="groupPickerSearch" class="form-control form-control-sm mb-1"
             placeholder="Szukaj grupy…" oninput="MS.filterGroupPicker(this.value)">
      <?php endif; ?>
      <div id="groupPickerDropdown" class="ms-group-list">
        <?php if (!$groups): ?>
        <div class="px-3 py-2 text-muted small">Brak grup. <a href="<?= APP_URL ?>/crm/groups.php">Utwórz →</a></div>
        <?php endif; ?>
        <?php foreach ($parent_groups as $g): ?>
        <div class="group-select-item" data-id="<?= (int)$g['id'] ?>"
             data-name="<?= h($g['name']) ?>"
             data-color="<?= h($g['color']) ?>"
             data-icon="<?= h($g['icon']) ?>"
             data-count="<?= (int)$g['member_count'] ?>"
             onclick="MS.pickGroup(this)">
          <div class="gsi-check"><i class="bi bi-check" style="font-size:.7rem;color:#fff;display:none"></i></div>
          <i class="bi <?= h($g['icon']) ?>" style="color:<?= h($g['color']) ?>"></i>
          <span><?= h($g['name']) ?></span>
          <span class="group-select-count"><?= (int)$g['member_count'] ?></span>
        </div>
        <?php foreach (($child_groups[$g['id']] ?? []) as $cg): ?>
        <div class="group-select-item is-child" data-id="<?= (int)$cg['id'] ?>"
             data-name="<?= h($cg['name']) ?>"
             data-color="<?= h($cg['color']) ?>"
             data-icon="<?= h($cg['icon']) ?>"
             data-count="<?= (int)$cg['member_count'] ?>"
             onclick="MS.pickGroup(this)">
          <div class="gsi-check"><i class="bi bi-check" style="font-size:.7rem;color:#fff;display:none"></i></div>
          <i class="bi <?= h($cg['icon']) ?>" style="color:<?= h($cg['color']) ?>;font-size:.85rem"></i>
          <span><?= h($cg['name']) ?></span>
          <span class="group-select-count"><?= (int)$cg['member_count'] ?></span>
        </div>
        <?php endforeach; ?>
        <?php endforeach; ?>
      </div>
      <!-- Wybrane grupy jako pills -->
      <div id="selectedGroupPills" class="mt-2 d-flex flex-wrap gap-1"></div>
    </div>

    <!-- ─── Filtry tagów ─── -->
    <?php if ($tags): ?>
    <div class="mb-3">
      <label class="form-label small fw-semibold mb-1 d-flex align-items-center gap-2">
        <i class="bi bi-tags text-secondary"></i> Tagi
      </label>
      <div id="tagChips">
        <?php foreach ($tags as $t): ?>
        <span class="tag-chip" data-tag="<?= h($t['tag']) ?>" onclick="MS.toggleTag(this)">
          <i class="bi bi-tag" style="font-size:.65rem"></i><?= h($t['tag']) ?>
          <span class="text-muted" style="font-size:.65rem">(<?= (int)$t['cnt'] ?>)</span>
        </span>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

    <!-- ─── Dodaj kontakt ─── -->
    <div class="mb-3">
      <label class="form-label small fw-semibold mb-1 d-flex align-items-center gap-2">
        <i class="bi bi-person-plus text-success"></i> Dodaj kontakt indywidualnie
      </label>
      <div class="position-relative">
        <div class="input-group input-group-sm">
          <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
          <input type="text" id="contactSearchInput" class="form-control"
                 placeholder="Szukaj po imieniu, e-mailu lub organizacji…"
                 autocomplete="off" oninput="MS.searchContacts(this.value)">
        </div>
        <div id="contactDropdown" class="contact-dropdown" style="display:none"></div>
      </div>
      <div id="selectedContactChips" class="mt-2 d-flex flex-wrap"></div>
    </div>

    <!-- ─── Podgląd odbiorców ─── -->
    <div id="recipientPreviewBox" style="display:none">
      <div class="d-flex align-items-center justify-content-between mb-1">
        <span class="fw-semibold" style="font-size:.82rem">
          Podgląd: <span id="recipientCount" class="text-primary fw-bold">0</span> odbiorców
        </span>
        <button type="button" class="btn btn-outline-secondary btn-sm py-0 px-2" style="font-size:.72rem"
                onclick="MS.preview()"><i class="bi bi-arrow-repeat me-1"></i>Odśwież</button>
      </div>
      <div class="recipient-preview" id="recipientList">
        <div class="text-muted">Wybierz grupę, tag lub kontakt.</div>
      </div>
    </div>
  </div>

  <!-- ─── DW (Do Wiadomości / CC) ─── -->
  <div class="ms-section wiz-pane" data-step="2">
    <div class="ms-section-title d-flex align-items-center gap-2">
      <i class="bi bi-person-check" style="font-size:.9rem"></i> DW — Do Wiadomości
      <span class="text-muted fw-normal" style="text-transform:none;letter-spacing:0;font-size:.72rem">
        osoby otrzymają podsumowanie wysyłki (jeden mail)
      </span>
    </div>
    <div class="position-relative">
      <div class="input-group input-group-sm">
        <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
        <input type="text" id="dwSearchInput" class="form-control"
               placeholder="Szukaj kontaktu lub wpisz e-mail ręcznie…"
               autocomplete="off" oninput="MS.searchDw(this.value)"
               onkeydown="MS.dwKeydown(event)">
        <button class="btn btn-outline-secondary" type="button" onclick="MS.addDwManual()">
          <i class="bi bi-plus"></i>
        </button>
      </div>
      <div id="dwDropdown" class="contact-dropdown" style="display:none"></div>
    </div>
    <div id="dwChips" class="mt-2 d-flex flex-wrap"></div>
    <div class="form-text">Wpisz Enter lub kliknij + aby dodać adres ręcznie. Kontakty bez e-maila są pomijane.</div>
  </div>

  <!-- Temat i treść -->
  <div class="ms-section wiz-pane" data-step="3">
    <div class="ms-step">
      <span class="ms-step__num" aria-hidden="true">3</span>
      <div class="ms-step__main">
        <div class="ms-step__t">Treść wiadomości</div>
        <div class="ms-step__h">Wpisz treść lub wybierz szablon</div>
      </div>
      <select id="ms_tpl_select" class="form-select form-select-sm" style="width:auto;max-width:200px;font-size:.78rem" onchange="MS.loadTemplate()">
        <option value="">— Wybierz szablon —</option>
        <?php foreach ($templates as $t): ?>
        <option value="<?= (int)$t['id'] ?>"
                data-channel="<?= h($t['channel']) ?>"
                data-subject="<?= h($t['subject']??'') ?>"
                data-body="<?= h($t['body']) ?>">
          [<?= strtoupper(h($t['channel'])) ?>] <?= h($t['name']) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div id="emailSubjectRow" class="mb-2">
      <label class="form-label fw-semibold small">Temat e-maila <span class="text-danger">*</span></label>
      <input id="ms_subject" type="text" class="form-control form-control-sm" placeholder="Temat wiadomości…">
    </div>

    <div class="mb-2">
      <span class="cv-muted" style="font-size:.74rem">Odbiorca: </span>
      <?php foreach (['{imie}','{imie_nazwisko}','{email}','{organizacja}','{data}'] as $v): ?>
      <button type="button" class="btn btn-outline-secondary py-0 px-1 me-1"
              style="font-size:.68rem;font-family:monospace;line-height:1.6"
              onclick="MS.insertVar('<?= $v ?>')"><?= h($v) ?></button>
      <?php endforeach; ?>
      <span class="cv-muted ms-2" style="font-size:.74rem">Nadawca: </span>
      <?php foreach (['{nadawca_imie_nazwisko}','{nadawca_email}','{nadawca_telefon}'] as $v): ?>
      <button type="button" class="btn btn-outline-secondary py-0 px-1 me-1"
              style="font-size:.68rem;font-family:monospace;line-height:1.6"
              onclick="MS.insertVar('<?= $v ?>')"><?= h($v) ?></button>
      <?php endforeach; ?>
    </div>

    <!-- Quill dla email -->
    <div id="ms_quill_wrap">
      <div id="ms_quill" style="min-height:180px;font-size:.9rem"></div>
    </div>
    <!-- Textarea dla SMS -->
    <div id="ms_plain_wrap" style="display:none">
      <textarea id="ms_plain_body" class="form-control form-control-sm" rows="6"
                placeholder="Treść SMS (max 160 znaków)…"></textarea>
      <div id="ms_sms_count" class="text-muted mt-1" style="font-size:.73rem"></div>
    </div>
  </div>

  <!-- KROK 4: Wyślij -->
  <div class="ms-section wiz-pane" data-step="4">
    <div class="ms-step">
      <span class="ms-step__num" aria-hidden="true">4</span>
      <div class="ms-step__main">
        <div class="ms-step__t">Wyślij</div>
        <div class="ms-step__h">Sprawdź podsumowanie i wyślij</div>
      </div>
    </div>

    <div class="mb-3 p-3 rounded" style="background:#F9FAFB;border:1px solid #E5E7EB">
      <div class="d-flex justify-content-between mb-1" style="font-size:.82rem">
        <span class="text-muted">Odbiorcy</span>
        <strong id="sendCount">0</strong>
      </div>
      <div class="d-flex justify-content-between" style="font-size:.82rem">
        <span class="text-muted">Kanał</span>
        <strong id="sendChannel">E-mail</strong>
      </div>
    </div>

    <!-- Pasek postępu -->
    <div id="progressSection" style="display:none" class="mb-3">
      <div class="d-flex justify-content-between mb-1" style="font-size:.78rem">
        <span id="progressLabel">Wysyłanie…</span>
        <span id="progressNum"></span>
      </div>
      <div class="ms-progress-bar"><div class="ms-progress-fill" id="progressFill" style="width:0%"></div></div>
      <div id="progressResult" class="mt-2" style="font-size:.78rem"></div>
    </div>

    <button type="button" id="previewBtn" class="btn btn-outline-primary btn-sm w-100 mb-2" onclick="MS.preview()">
      <i class="bi bi-eye me-1"></i>Podgląd odbiorców
    </button>
    <button type="button" id="sendBtn" class="btn btn-success w-100" onclick="MS.send()" disabled>
      <i class="bi bi-send-fill me-1"></i>Wyślij do <span id="sendBtnCount">0</span> odbiorców
    </button>
    <div class="form-text text-center mt-1" style="font-size:.72rem">
      Wysyłka uruchamiana synchronicznie — nie zamykaj okna.
    </div>
  </div><!-- /krok 4 -->

  <!-- Nawigacja kreatora -->
  <div class="ms-wiznav">
    <button type="button" class="btn btn-outline-secondary" id="wizBackBtn" onclick="MS.wizBack()" disabled>
      <i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Wstecz
    </button>
    <div class="ms-wiznav-spacer"></div>
    <button type="button" class="btn btn-crm-primary" id="wizNextBtn" onclick="MS.wizNext()">
      Dalej<i class="bi bi-arrow-right ms-1" aria-hidden="true"></i>
    </button>
  </div>

</div><!-- /ms-wizard -->

<!-- Historia -->
<?php if ($history): ?>
<div class="ms-section">
    <div class="ms-section-title">Ostatnie wysyłki</div>
    <?php foreach ($history as $hs):
        $failures = $history_failures[$hs['id']] ?? [];
        $has_fail = $hs['sent_fail'] > 0;
    ?>
    <div class="hist-row flex-column align-items-stretch" style="gap:.35rem">
      <div class="d-flex align-items-center gap-2">
        <div style="flex:1;min-width:0">
          <div style="font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
            <?= h($hs['group_name'] ?? 'Bez grupy') ?>
          </div>
          <div class="text-muted" style="font-size:.72rem">
            <?= strtoupper(h($hs['channel'])) ?> · <?= h($hs['subject'] ?? '(SMS)') ?> · <?= date('d.m H:i', strtotime($hs['created_at'])) ?>
          </div>
        </div>
        <div class="text-end flex-shrink-0" style="font-size:.75rem">
          <div class="text-success fw-semibold"><?= (int)$hs['sent_ok'] ?> ✓</div>
          <?php if ($has_fail): ?>
          <button type="button"
                  class="btn btn-link btn-sm text-danger p-0 fw-semibold"
                  style="font-size:.75rem"
                  onclick="this.closest('.hist-row').querySelector('.ms-fail-detail').classList.toggle('d-none')">
            <?= (int)$hs['sent_fail'] ?> ✗ <i class="bi bi-chevron-down" style="font-size:.6rem"></i>
          </button>
          <?php endif; ?>
        </div>
      </div>
      <?php if ($has_fail && $failures): ?>
      <div class="ms-fail-detail d-none mt-1 p-2 rounded" style="background:#fef2f2;border:1px solid #fecaca">
        <div class="fw-semibold mb-1" style="font-size:.74rem;color:#991b1b">
          <i class="bi bi-exclamation-circle-fill me-1"></i>Nie otrzymali mailingu:
        </div>
        <?php foreach ($failures as $fc): ?>
        <div class="d-flex gap-2 align-items-center py-1 border-bottom border-danger border-opacity-25" style="font-size:.78rem">
          <a href="<?= APP_URL ?>/crm/contact.php?id=<?= (int)$fc['id'] ?>" class="text-decoration-none fw-semibold text-dark">
            <?= h($fc['imie_nazwisko']) ?>
          </a>
          <span class="text-muted"><?= h($fc['email'] ?: $fc['telefon'] ?: '—') ?></span>
        </div>
        <?php endforeach; ?>
        <?php if ((int)$hs['sent_fail'] > count($failures)): ?>
        <div class="text-muted mt-1" style="font-size:.72rem">… i <?= (int)$hs['sent_fail'] - count($failures) ?> więcej (bez zapisanego kontaktu)</div>
        <?php endif; ?>
      </div>
      <?php elseif ($has_fail): ?>
      <div class="ms-fail-detail d-none mt-1 p-2 rounded text-muted" style="background:#fef2f2;font-size:.78rem">
        Brak szczegółów — dane o błędach dostępne tylko dla nowszych wysyłek.
      </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

<!-- Quill -->
<link href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js"></script>
<style>
#ms_quill_wrap .ql-toolbar.ql-snow { border:1px solid #E5E7EB;border-bottom:none;border-radius:.375rem .375rem 0 0;background:#F9FAFB;padding:.3rem .5rem }
#ms_quill_wrap .ql-container.ql-snow { border:1px solid #E5E7EB;border-radius:0 0 .375rem .375rem;font-size:.9rem }
#ms_quill_wrap .ql-editor { min-height:180px }
</style>

<script>
const MS = (function() {
  'use strict';
  const API        = '<?= APP_URL ?>/crm/api/mass_send.php';
  const SEARCH_API = '<?= APP_URL ?>/crm/api/contacts_search.php';

  let _channel      = 'email';
  let _group_ids    = new Set();
  let _tag_filters  = new Set();
  let _contact_ids  = new Map(); // id → {name, email}
  // Wybór osoby kontaktowej per podmiot: { contact_id: person_id }. Pusty = adresat
  // domyślny (osoba oznaczona jako adresat → osoba główna → adres podmiotu).
  let _person_over  = {};
  let _dw_emails    = new Map(); // email → label
  let _quill        = null;
  let _total        = 0;
  let _searchTimer  = null;
  let _dwTimer      = null;

  function initQuill() {
    _quill = new Quill('#ms_quill', {
      theme: 'snow',
      placeholder: 'Treść e-maila… Użyj zmiennych {imie}, {imie_nazwisko}, {email}',
      modules: { toolbar: [
        [{ header: [1,2,false] }],
        ['bold','italic','underline'],
        [{ color: [] }],
        [{ list: 'ordered' },{ list: 'bullet' }],
        ['link','clean']
      ]}
    });
  }
  initQuill();

  // ── Group picker ────────────────────────────────────────────────────────────
  function toggleGroupPicker(e) {
    e.stopPropagation();
    const dd = document.getElementById('groupPickerDropdown');
    const open = dd.style.display !== 'none';
    dd.style.display = open ? 'none' : 'block';
    if (!open) document.getElementById('groupPickerSearch').focus();
  }

  function filterGroupPicker(q) {
    const items = document.querySelectorAll('#groupPickerDropdown .group-select-item');
    q = q.toLowerCase();
    items.forEach(el => {
      el.style.display = el.dataset.name.toLowerCase().includes(q) ? '' : 'none';
    });
  }

  function pickGroup(el) {
    const id = parseInt(el.dataset.id);
    if (_group_ids.has(id)) {
      _group_ids.delete(id);
      el.classList.remove('selected');
      el.querySelector('.gsi-check i').style.display = 'none';
    } else {
      _group_ids.add(id);
      el.classList.add('selected');
      el.querySelector('.gsi-check i').style.display = '';
    }
    renderGroupPills();
    preview();
  }

  function renderGroupPills() {
    const box = document.getElementById('selectedGroupPills');
    box.innerHTML = '';
    _group_ids.forEach(id => {
      const el = document.querySelector(`#groupPickerDropdown [data-id="${id}"]`);
      if (!el) return;
      const pill = document.createElement('span');
      pill.className = 'group-pill selected';
      pill.style.cssText = `background:${el.dataset.color};color:#fff;border-color:transparent`;
      pill.innerHTML = `<i class="bi ${el.dataset.icon}"></i>${esc(el.dataset.name)}<span class="badge ms-1" style="background:rgba(255,255,255,.3);color:#fff;font-size:.62rem">${el.dataset.count}</span>`;
      const rm = document.createElement('button');
      rm.type = 'button';
      rm.innerHTML = '<i class="bi bi-x"></i>';
      rm.style.cssText = 'background:none;border:none;color:rgba(255,255,255,.8);padding:0 0 0 .25rem;cursor:pointer;line-height:1;font-size:.9rem';
      rm.onclick = (e) => { e.stopPropagation(); _group_ids.delete(id); el.classList.remove('selected'); el.querySelector('.gsi-check i').style.display='none'; renderGroupPills(); preview(); };
      pill.appendChild(rm);
      box.appendChild(pill);
    });
    const label = document.getElementById('groupPickerLabel');
    label.textContent = _group_ids.size ? `${_group_ids.size} grup(y) wybrano` : 'Wybierz grupę…';
  }

  // ── Contact search ───────────────────────────────────────────────────────────
  // Sprawdź czy kontakt ma pole wymagane przez aktualny kanał
  // O osiągalności decyduje adres ADRESATA (to_email/to_telefon z API), a nie
  // wyłącznie adres podmiotu — firma może mieć e-mail tylko przy osobie kontaktowej.
  function contactValidForChannel(c) {
    if (_channel === 'email') return !!(c.to_email   || c.email);
    if (_channel === 'sms')   return !!(c.to_telefon || c.telefon);
    return true;
  }
  function channelMissingLabel() {
    return _channel === 'email' ? 'brak e-mail' : (_channel === 'sms' ? 'brak telefonu' : '');
  }

  function searchContacts(q) {
    clearTimeout(_searchTimer);
    const dd = document.getElementById('contactDropdown');
    if (!q || q.length < 1) { dd.style.display='none'; return; }
    _searchTimer = setTimeout(() => {
      const excl = [..._contact_ids.keys()].join(',');
      fetch(`${SEARCH_API}?q=${encodeURIComponent(q)}&exclude=${excl}`)
        .then(r=>r.json()).then(rows=>{
          if (!rows.length) { dd.style.display='none'; return; }
          dd.innerHTML = rows.map(c=>{
            const ok      = contactValidForChannel(c);
            const missing = channelMissingLabel();
            return `
            <div class="contact-dropdown-item${ok?'':' ms-disabled'}"
                 data-id="${c.id}" data-name="${esc(c.name)}"
                 data-email="${esc(c.email||'')}" data-telefon="${esc(c.telefon||'')}"
                 ${ok?`onclick="MS.addContact(${c.id},'${esc(c.name)}','${esc(c.to_email||c.email||'')}','${esc(c.to_telefon||c.telefon||'')}')"`:``}
                 title="${ok?'':('Nie można wybrać — '+missing)}">
              <div class="ci-name">
                ${esc(c.name)}${c.organizacja?` <span style="font-weight:400;color:#9CA3AF">· ${esc(c.organizacja)}</span>`:''}
                <span class="badge bg-light text-dark border ms-1" style="font-size:.62rem">${c.type==='organizacja'?'org':'os.'}</span>
                ${!ok?`<span class="badge bg-warning text-dark ms-1" style="font-size:.62rem"><i class="bi bi-exclamation-triangle-fill"></i> ${esc(missing)}</span>`:''}
              </div>
              <div class="ci-sub" style="${ok?'':'color:#D97706'}">
                ${ok ? (esc(c.to_email||c.email||'')+( (c.to_telefon||c.telefon)?' · '+esc(c.to_telefon||c.telefon):'')+(c.to_name?' · '+esc(c.to_name):'')) : '<i>'+esc(missing)+'</i>'}
              </div>
            </div>`;
          }).join('');
          dd.style.display = 'block';
        });
    }, 220);
  }

  function addContact(id, name, email, telefon) {
    if (_contact_ids.has(id)) return;
    if (!contactValidForChannel({email, telefon})) return; // guard
    _contact_ids.set(id, {name, email, telefon});
    document.getElementById('contactSearchInput').value = '';
    document.getElementById('contactDropdown').style.display = 'none';
    renderContactChips();
    preview();
  }

  function removeContact(id) {
    _contact_ids.delete(id);
    renderContactChips();
    preview();
  }

  function renderContactChips() {
    const box = document.getElementById('selectedContactChips');
    box.innerHTML = '';
    _contact_ids.forEach(({name, email, telefon}, id) => {
      const ok   = contactValidForChannel({email, telefon});
      const chip = document.createElement('span');
      chip.className = 'contact-chip' + (ok ? '' : ' opacity-50');
      chip.title = ok ? '' : 'Ten kontakt nie ma wymaganego pola dla wybranego kanału';
      const contact_field = _channel === 'email' ? email : telefon;
      chip.innerHTML = `<i class="bi bi-person-fill" style="font-size:.75rem"></i><span>${esc(name)}</span>`
        + (contact_field ? `<span class="text-muted" style="font-size:.7rem">${esc(contact_field)}</span>` : '')
        + (!ok ? `<i class="bi bi-exclamation-triangle-fill text-warning ms-1" style="font-size:.7rem"></i>` : '');
      const rm = document.createElement('button');
      rm.type='button'; rm.innerHTML='<i class="bi bi-x"></i>';
      rm.onclick = () => removeContact(id);
      chip.appendChild(rm);
      box.appendChild(chip);
    });
  }

  // ── DW (Do Wiadomości) ───────────────────────────────────────────────────────
  function searchDw(q) {
    clearTimeout(_dwTimer);
    const dd = document.getElementById('dwDropdown');
    if (!q || q.length < 2) { dd.style.display='none'; return; }
    _dwTimer = setTimeout(() => {
      fetch(`${SEARCH_API}?q=${encodeURIComponent(q)}&limit=10`)
        .then(r=>r.json()).then(rows=>{
          const withEmail = rows.filter(c=>c.email);
          if (!withEmail.length) { dd.style.display='none'; return; }
          dd.innerHTML = withEmail.map(c=>`
            <div class="contact-dropdown-item"
                 onclick="MS.addDw('${esc(c.email)}','${esc(c.name)}')">
              <div class="ci-name">${esc(c.name)}</div>
              <div class="ci-sub">${esc(c.email)}</div>
            </div>`).join('');
          dd.style.display = 'block';
        });
    }, 220);
  }

  function dwKeydown(e) {
    if (e.key === 'Enter') { e.preventDefault(); addDwManual(); }
  }

  function addDwManual() {
    const inp = document.getElementById('dwSearchInput');
    const val = inp.value.trim();
    if (!val) return;
    // Może być surowy email lub wybrany kontakt
    const emails = val.split(/[,;\s]+/).filter(v=>v.includes('@'));
    emails.forEach(email => addDw(email, email));
    inp.value = '';
    document.getElementById('dwDropdown').style.display = 'none';
  }

  function addDw(email, label) {
    if (!email || _dw_emails.has(email)) return;
    _dw_emails.set(email, label || email);
    document.getElementById('dwSearchInput').value = '';
    document.getElementById('dwDropdown').style.display = 'none';
    renderDwChips();
  }

  function removeDw(email) {
    _dw_emails.delete(email);
    renderDwChips();
  }

  function renderDwChips() {
    const box = document.getElementById('dwChips');
    box.innerHTML = '';
    _dw_emails.forEach((label, email) => {
      const chip = document.createElement('span');
      chip.className = 'dw-chip';
      chip.innerHTML = `<i class="bi bi-person-check-fill" style="font-size:.75rem"></i><span>${esc(label !== email ? label : email)}</span>${label !== email ? `<span class="text-muted" style="font-size:.7rem">&lt;${esc(email)}&gt;</span>` : ''}`;
      const rm = document.createElement('button');
      rm.type='button'; rm.innerHTML='<i class="bi bi-x"></i>';
      rm.onclick = () => removeDw(email);
      chip.appendChild(rm);
      box.appendChild(chip);
    });
  }

  // Zamknij dropdowny po kliknięciu poza
  document.addEventListener('click', (e) => {
    if (!e.target.closest('#contactSearchInput') && !e.target.closest('#contactDropdown')) {
      document.getElementById('contactDropdown').style.display = 'none';
    }
    if (!e.target.closest('#dwSearchInput') && !e.target.closest('#dwDropdown')) {
      document.getElementById('dwDropdown').style.display = 'none';
    }
  });

  function setChannel(ch) {
    _channel = ch;
    ['email','sms'].forEach(c => {
      document.getElementById('ch-'+c)?.classList.toggle('active', c===ch);
    });
    document.getElementById('ms_quill_wrap').style.display = ch==='email' ? '' : 'none';
    document.getElementById('ms_plain_wrap').style.display  = ch==='sms'   ? '' : 'none';
    document.getElementById('emailSubjectRow').style.display= ch==='email' ? '' : 'none';
    var senderRow = document.getElementById('ms_sender_row');
    if (senderRow) senderRow.style.display = ch==='email' ? '' : 'none';
    document.getElementById('sendChannel').textContent = ch==='email' ? 'E-mail' : 'SMS';
    updateSmsCount();
    renderContactChips(); // odśwież chipy — wyszarz bez wymaganego pola
    preview();
  }

  function toggleGroup(btn) {
    const id = parseInt(btn.dataset.id);
    if (_group_ids.has(id)) {
      _group_ids.delete(id);
      btn.classList.remove('selected');
      btn.style.background = btn.style.background.replace(/[^#]+$/, btn.dataset.origBg||'#fff');
    } else {
      _group_ids.add(id);
      btn.classList.add('selected');
      btn.dataset.origColor = btn.style.color;
      btn.style.background = btn.style.borderColor.replace('55','');
      btn.style.color = '#fff';
      btn.style.borderColor = 'transparent';
    }
    preview();
  }

  function toggleTag(chip) {
    const tag = chip.dataset.tag;
    if (_tag_filters.has(tag)) {
      _tag_filters.delete(tag);
      chip.classList.remove('selected');
    } else {
      _tag_filters.add(tag);
      chip.classList.add('selected');
    }
    preview();
  }

  // Wiersz podglądu odbiorcy. Gdy podmiot ma więcej niż jedną osobę z adresem,
  // dokładamy listę wyboru — domyślnie zaznaczony adresat wyliczony na serwerze.
  function recipientRow(c) {
    const addr  = _channel === 'sms' ? (c.telefon || '—brak tel.') : (c.email || '—brak e-mail');
    const many  = (c.persons || []).length > 1;
    const right = many
      ? `<select class="form-select form-select-sm" style="max-width:230px;font-size:.72rem"
                 data-person-pick="${c.id}" aria-label="Osoba kontaktowa dla ${esc(c.name)}">
           <option value="0">Adres podmiotu / domyślny — ${esc(addr)}</option>
           ${c.persons.map(p => `<option value="${p.id}"${p.id === c.person_id ? ' selected' : ''}>${esc(p.name)}${p.role ? ' · ' + esc(p.role) : ''} — ${esc(p.address)}</option>`).join('')}
         </select>`
      : `<span class="text-muted">${esc(addr)}${c.to_source && c.to_source !== 'podmiot' ? ' · ' + esc(c.to_name) : ''}</span>`;

    return `<div class="d-flex gap-2 py-1 border-bottom align-items-center" style="border-color:#F3F4F6">
      <span style="flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;font-weight:500">${esc(c.name)}</span>
      ${right}
    </div>`;
  }

  // Zmiana osoby nie wymaga ponownego zapytania — zapisujemy wybór i odświeżamy
  // podgląd tylko po to, żeby zsynchronizować liczniki i pozostałe wiersze.
  document.addEventListener('change', function (ev) {
    const sel = ev.target.closest('[data-person-pick]');
    if (!sel) return;
    const cid = parseInt(sel.dataset.personPick, 10);
    const pid = parseInt(sel.value, 10) || 0;
    if (pid) _person_over[cid] = pid; else delete _person_over[cid];
    preview();
  });

  function preview() {
    const groups  = [..._group_ids];
    const tags    = [..._tag_filters].join(',');
    const c_ids   = [..._contact_ids.keys()];
    if (!groups.length && !tags && !c_ids.length) {
      _total = 0;
      document.getElementById('recipientPreviewBox').style.display='none';
      document.getElementById('recipientCount').textContent='0';
      document.getElementById('sendCount').textContent='0';
      document.getElementById('sendBtnCount').textContent='0';
      document.getElementById('sendBtn').disabled = true;
      return;
    }
    document.getElementById('recipientPreviewBox').style.display='';

    const payload = {
      action: 'preview',
      group_id: groups[0] || 0,
      tag_filter: tags,
      contact_ids: c_ids,
      channel: _channel,
      person_overrides: _person_over
    };

    // Multiple groups: merge all
    if (groups.length > 1 || c_ids.length) {
      const allPayload = { action:'preview', group_id: groups[0]||0, tag_filter: tags, contact_ids: c_ids, channel: _channel, group_ids: groups, person_overrides: _person_over };
      fetch(API, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(allPayload) })
        .then(r=>r.json()).then(res=>{
          if (!res.ok) return;
          _total = res.data.total;
          updateCounters();
          const list = res.data.contacts.slice(0,15).map(c=>
recipientRow(c)
          ).join('');
          document.getElementById('recipientList').innerHTML = list +
            (res.data.total > 15 ? `<div class="text-muted mt-1" style="font-size:.72rem">… i ${res.data.total-15} więcej</div>` : '');
          document.getElementById('recipientCount').textContent = res.data.total;
        });
      return;
    }

    fetch(API, { method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(payload) })
      .then(r=>r.json())
      .then(res=>{
        if (!res.ok) return;
        _total = res.data.total;
        updateCounters();
        const list = res.data.contacts.slice(0,15).map(c=>
recipientRow(c)
        ).join('');
        document.getElementById('recipientList').innerHTML = list +
          (res.data.total > 15 ? `<div class="text-muted mt-1" style="font-size:.72rem">… i ${res.data.total-15} więcej</div>` : '');
        document.getElementById('recipientCount').textContent = res.data.total;
      });
  }

  function updateCounters() {
    document.getElementById('recipientCount').textContent = _total;
    document.getElementById('sendCount').textContent      = _total;
    document.getElementById('sendBtnCount').textContent   = _total;
    document.getElementById('sendBtn').disabled = (_total === 0);
  }

  function updateSmsCount() {
    if (_channel !== 'sms') return;
    const ta = document.getElementById('ms_plain_body');
    const cc = document.getElementById('ms_sms_count');
    if (!ta||!cc) return;
    const len = ta.value.length, msgs = Math.ceil(len/160)||1;
    cc.textContent = len+' znaków ('+msgs+' SMS'+(msgs>1?'-y':'')+')';
    cc.style.color = len>160?'#D97706':'#9CA3AF';
  }

  document.getElementById('ms_plain_body')?.addEventListener('input', updateSmsCount);

  function getBody() {
    if (_channel === 'sms') return document.getElementById('ms_plain_body').value.trim();
    return _quill ? _quill.root.innerHTML : '';
  }

  function insertVar(v) {
    if (_channel==='sms') {
      const ta=document.getElementById('ms_plain_body');
      const s=ta.selectionStart,e=ta.selectionEnd;
      ta.value=ta.value.slice(0,s)+v+ta.value.slice(e);
      ta.selectionStart=ta.selectionEnd=s+v.length;
    } else if (_quill) {
      const r=_quill.getSelection(true);
      _quill.insertText(r?r.index:_quill.getLength(),v,'user');
    }
  }

  function loadTemplate() {
    const sel = document.getElementById('ms_tpl_select');
    const opt = sel.options[sel.selectedIndex];
    if (!opt.value) return;
    const ch=opt.dataset.channel||'email';
    setChannel(ch);
    const subj=opt.dataset.subject||'';
    const body=opt.dataset.body||'';
    if (ch==='email') {
      document.getElementById('ms_subject').value=subj;
      if (_quill) {
        if (/<[a-z]/i.test(body)) _quill.root.innerHTML=body;
        else _quill.setText(body);
      }
    } else {
      document.getElementById('ms_plain_body').value=body;
      updateSmsCount();
    }
  }

  function esc(s){return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}

  async function send() {
    const msgBody = getBody();
    const subject = document.getElementById('ms_subject').value.trim();
    const groups  = [..._group_ids];
    const tags    = [..._tag_filters].join(',');
    const c_ids   = [..._contact_ids.keys()];
    const dw      = [..._dw_emails.keys()];

    if (!msgBody) { alert('Wpisz treść wiadomości.'); return; }
    if (_channel==='email'&&!subject) { alert('Podaj temat e-maila.'); return; }
    if (!groups.length&&!tags&&!c_ids.length) { alert('Wybierz grupę, tag lub dodaj kontakty.'); return; }
    if (_total===0) { alert('Brak odbiorców dla wybranego kanału.'); return; }

    const dwNote = dw.length ? `\nDW: ${dw.join(', ')}` : '';
    if (!confirm(`Wysłać ${_channel.toUpperCase()} do ${_total} odbiorców?${dwNote}`)) return;

    document.getElementById('sendBtn').disabled=true;
    document.getElementById('progressSection').style.display='';
    document.getElementById('progressLabel').textContent='Przygotowywanie wysyłki…';
    document.getElementById('progressFill').style.width='5%';

    const tplText = document.getElementById('ms_tpl_select').options[document.getElementById('ms_tpl_select').selectedIndex]?.text||'';

    // Jedna wysyłka łącząca wszystkie grupy + indywidualne kontakty
    const startPayload = {
      action: 'start',
      group_ids: groups,
      group_id:  groups[0] || 0,
      tag_filter: tags,
      contact_ids: c_ids,
      channel: _channel,
      subject,
      body: msgBody,
      template_name: tplText,
      dw: dw,
      send_as: (document.getElementById('ms_send_as')?.value) || 'system',
      person_overrides: _person_over,
    };

    const startRes = await fetch(API,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(startPayload)}).then(r=>r.json());
    if (!startRes.ok) { alert('Błąd: '+startRes.error); document.getElementById('sendBtn').disabled=false; return; }

    document.getElementById('progressLabel').textContent='Wysyłanie…';
    document.getElementById('progressFill').style.width='30%';

    const execRes = await fetch(API,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'execute',send_id:startRes.data.send_id})}).then(r=>r.json());
    const totalOk   = execRes.data?.sent_ok   || 0;
    const totalFail = execRes.data?.sent_fail || 0;

    document.getElementById('progressFill').style.width='100%';
    document.getElementById('progressLabel').textContent='Zakończono!';
    document.getElementById('progressResult').innerHTML =
      `<span class="text-success fw-semibold"><i class="bi bi-check-circle me-1"></i>${totalOk} wysłano</span>` +
      (totalFail ? ` <span class="text-danger ms-2"><i class="bi bi-x-circle me-1"></i>${totalFail} błędów</span>` : '') +
      (dw.length ? ` <span class="text-info ms-2"><i class="bi bi-person-check me-1"></i>DW wysłane do ${dw.length}</span>` : '');

    setTimeout(()=>location.reload(), 2500);
  }

  // ── Kreator (wizard) ─────────────────────────────────────────────────────────
  let _step = 1;
  const WIZ_STEPS = 4;

  function wizShow(n) {
    _step = Math.max(1, Math.min(WIZ_STEPS, n));
    document.querySelectorAll('.wiz-pane').forEach(p => {
      p.classList.toggle('wiz-active', parseInt(p.dataset.step) === _step);
    });
    document.querySelectorAll('#msStepper .ms-stepper-item').forEach(it => {
      const s = parseInt(it.dataset.step);
      it.classList.toggle('active', s === _step);
      it.classList.toggle('done',   s <  _step);
    });
    const back = document.getElementById('wizBackBtn');
    const next = document.getElementById('wizNextBtn');
    if (back) back.disabled = (_step === 1);
    if (next) next.style.display = (_step === WIZ_STEPS) ? 'none' : '';
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }

  function wizValidate(step) {
    if (step === 2 && _total === 0) {
      alert('Wybierz co najmniej jednego odbiorcę (grupa, tag lub kontakt).');
      return false;
    }
    if (step === 3) {
      if (!getBody()) { alert('Wpisz treść wiadomości.'); return false; }
      if (_channel === 'email' && !document.getElementById('ms_subject').value.trim()) {
        alert('Podaj temat e-maila.'); return false;
      }
    }
    return true;
  }

  function wizNext() { if (wizValidate(_step)) wizShow(_step + 1); }
  function wizBack() { wizShow(_step - 1); }
  function wizGo(n) {
    if (n > _step) { // wchcąc iść do przodu — waliduj kolejne kroki
      for (let s = _step; s < n; s++) { if (!wizValidate(s)) { wizShow(s); return; } }
    }
    wizShow(n);
  }

  wizShow(1);

  return { setChannel, toggleGroupPicker, filterGroupPicker, pickGroup, toggleTag,
           searchContacts, addContact, removeContact,
           searchDw, dwKeydown, addDwManual, addDw, removeDw,
           preview, send, insertVar, loadTemplate,
           wizNext, wizBack, wizGo };
})();
</script>

<?php include __DIR__ . '/includes/footer_crm.php'; ?>
