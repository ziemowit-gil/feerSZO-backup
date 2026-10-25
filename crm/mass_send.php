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
crm_require('campaigns', 'write');

$PAGE_TITLE = 'Wysyłka masowa';

require_once dirname(__DIR__) . '/includes/mail_queue.php';
require_once dirname(__DIR__) . '/includes/sms.php';

$sms_chain = sms_provider_chain();            // domyślny + zapasowy dostawca SMS (Ustawienia SMS)
$sms_ok    = $sms_chain !== [];
$sms_label = $sms_ok ? sms_provider_label($sms_chain[0]) : '';
$sms_fb    = count($sms_chain) > 1 ? sms_provider_label($sms_chain[1]) : '';
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

// Cele zgód — podstawa wysyłki. Zob. includes/crm_consent.php.
require_once dirname(__DIR__) . '/includes/crm_consent.php';
$consent_purposes = crm_consent_purposes(true);
$consent_counts   = crm_consent_counts();

// Adresaci wg umów (typ × aktywne/wygasłe)
require_once dirname(__DIR__) . '/includes/crm_contract_segments.php';
$contract_counts = crm_contract_segment_counts();

// Wysyłka z konta M365 zalogowanego użytkownika (do wyboru)
$_cu_now        = current_user();
$can_send_as_me = $m365_ok && !empty($_cu_now['microsoft_id']) && !empty($_cu_now['email']);
$my_ms_email    = $can_send_as_me ? trim($_cu_now['email']) : '';
$sys_from_email = _mail_setting('m365_send_from_email');

include __DIR__ . '/includes/header_crm.php';
?>

<style>
.ms-varlbl { font-size:.74rem;color:#9CA3AF }
.ms-var { font-size:.7rem;font-family:ui-monospace,Menlo,monospace;line-height:1.6;
  padding:.05rem .35rem;margin:0 .15rem .2rem 0;border:1px solid #E5E7EB;border-radius:5px;
  background:#F9FAFB;color:#374151;cursor:pointer }
.ms-var:hover { background:#EFF6FF;border-color:#BFDBFE;color:#1D4ED8 }
.ms-summary { display:grid;grid-template-columns:auto 1fr;gap:.3rem 1rem;margin:0 0 1.1rem;
  font-size:.86rem;padding:.9rem 0;border-top:1px solid #F1F2F4;border-bottom:1px solid #F1F2F4 }
.ms-summary dt { color:#9CA3AF;font-weight:500 }
.ms-summary dd { margin:0;font-weight:700;color:#111827;text-align:right }

/* ══ Układ „clear" ═══════════════════════════════════════════════════════
   Kreator miał trzy warstwy oznaczania kroku naraz (stepper, kolorowe kółko,
   pasek postępu) i cień pod każdą kartą. Zostaje jedna: stepper u góry. */
.ms-section { background:#fff;border:1px solid #E5E7EB;border-radius:12px;padding:1.35rem 1.5rem;
  margin-bottom:1rem;box-shadow:none }
.ms-section-title { font-size:.82rem;font-weight:700;letter-spacing:.04em;text-transform:uppercase;
  color:#6B7280;padding-bottom:.5rem;border-bottom:1px solid #F3F4F6;margin-bottom:.9rem }

/* Pola: jedna wysokość i promień w całym kreatorze */
.ms-wizard .form-control, .ms-wizard .form-select {
  min-height:38px;font-size:.88rem;border:1px solid #E5E7EB;border-radius:9px;box-shadow:none }
.ms-wizard .form-control:focus, .ms-wizard .form-select:focus {
  border-color:var(--crm-primary);box-shadow:0 0 0 3px rgba(1,118,211,.12) }
.ms-wizard .form-label { font-size:.78rem;font-weight:600;color:#374151;margin-bottom:.3rem }
.ms-wizard .form-text { font-size:.75rem;color:#9CA3AF }
.ms-wizard textarea.form-control { min-height:150px }
/* Krok formularza (numer + tytuł + podpowiedź) */
.ms-step { display:flex; align-items:baseline; gap:.5rem; margin-bottom:1.1rem; padding-bottom:.7rem;
  border-bottom:1px solid #F1F2F4 }
.ms-step__num { width:auto;height:auto;border-radius:0;background:none;color:#C3C8D0;font-weight:700;
  font-size:.82rem;display:inline;flex-shrink:0;font-variant-numeric:tabular-nums }
.ms-step__num::after { content:'.' }
.ms-step__t { font-size:.95rem;font-weight:700;color:#111827;line-height:1.2 }
.ms-step__h { font-size:.77rem;color:#9CA3AF;margin-top:.1rem }
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
.ms-stepper-item { background:#fff }
.ms-stepper-item.active { border-color:var(--crm-primary); background:#fff; color:var(--crm-primary-dark); font-weight:600;
  box-shadow:inset 0 -2px 0 var(--crm-primary) }
.ms-stepper-item.active .ms-stepper-num { background:var(--crm-primary); color:#fff }
.ms-stepper-item.done .ms-stepper-num { background:var(--crm-primary); color:#fff }
.ms-stepper-item.done { color:var(--crm-primary-dark); }
.ms-stepper-item:focus-visible { outline:3px solid var(--crm-accent); outline-offset:2px }
.wiz-pane { display:none }
.wiz-pane.wiz-active { display:block }
.ms-wiznav { display:flex; align-items:center; gap:.6rem; margin:.25rem 0 1rem }
.ms-wiznav-spacer { flex:1 }
.ms-wizard, .ms-stepper, .crm-page-header { max-width:960px; margin-left:auto; margin-right:auto }
.ms-wizard ~ .ms-section { max-width:960px; margin-left:auto; margin-right:auto }
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

/* ══ Układ Dynamics 365 — sekcje formularza (kroki 1–4), kontrast ≥ 4,5:1 ════ */
.ms-section, .ms-wizard .card { border:1px solid #D1D5DB !important; border-radius:0 !important; background:#fff; box-shadow:none !important }
.ms-step { margin:-1.35rem -1.5rem 1.1rem; padding:.6rem 1.1rem; background:#F3F4F6; border-bottom:1px solid #D1D5DB; align-items:center; gap:.65rem }
.ms-step__num { display:inline-flex; align-items:center; justify-content:center; width:1.6rem; height:1.6rem; border-radius:0;
  background:#0F766E; color:#fff; font-size:.82rem; font-weight:700; letter-spacing:0 }
.ms-step__num::after { content:'' }
.ms-step__t { font-size:.95rem; font-weight:600; color:#111827 }
.ms-step__h { font-size:.78rem; color:#4B5563; margin-top:0 }
.ms-wizard .form-control, .ms-wizard .form-select { border-radius:0; border:1px solid #9CA3AF; min-height:36px }
.ms-wizard .form-control:focus, .ms-wizard .form-select:focus { border-color:#0F766E; box-shadow:inset 0 0 0 1px #0F766E }
.ms-wizard .form-label { color:#111827; font-weight:600 }
.ms-wizard .input-group-text { border-radius:0 !important; border-color:#9CA3AF }
.ms-wizard .btn { border-radius:0 }
.channel-btn, .comm-flags .badge { border-radius:0 }
.channel-btn { border:1px solid #9CA3AF; border-left-width:4px }

/* ── Krok 2 (Odbiorcy): proste krawędzie, skracane długie tagi, kontrast ── */
.ms-group-list{border-radius:0;border-color:#9CA3AF}
.ms-group-row.selected{background:#E6F4F1}
.ms-group-row .group-select-count,.group-select-item .group-select-count{color:#4B5563}
.group-pill,.contact-chip,.dw-chip{border-radius:0}
.group-pill{border:1px solid #9CA3AF;padding:.3rem .7rem}
.tag-chip{border-radius:0;border:1px solid #9CA3AF;max-width:100%;color:#1F2937}
.tag-chip > span:first-of-type,.tag-chip .tag-name{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.tag-chip{max-width:22rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:inline-block}
.tag-chip.selected{background:#E6F4F1;border-color:#0F766E;color:#0F4C47}
.contact-chip{background:#E6F4F1;border-color:#0F766E;color:#0F4C47}
.recipient-preview{border-radius:0;background:#fff;border:1px solid #D1D5DB;padding:0}
.recipient-preview > *{padding:.4rem .75rem;border-bottom:1px solid #E5E7EB}
.recipient-preview > *:nth-child(even){background:#F9FAFB}
.recipient-preview > *:last-child{border-bottom:0}

/* ── Zakładki wyboru adresatów + tabela umów ── */
.ms-rp-tabs{display:flex;flex-wrap:wrap;gap:0;border-bottom:2px solid #D1D5DB;margin-bottom:1rem}
.ms-rp-tab{background:none;border:0;border-bottom:3px solid transparent;margin-bottom:-2px;padding:.55rem 1rem;font-size:.86rem;font-weight:600;color:#374151;cursor:pointer;border-radius:0}
.ms-rp-tab:hover{background:#F3F4F6}
.ms-rp-tab:focus-visible{outline:2px solid #0F766E;outline-offset:-2px}
.ms-rp-tab.is-on{color:#0F4C47;border-bottom-color:#0F766E;background:#F9FAFB}
.ms-rp-n{display:inline-block;min-width:1.3rem;padding:0 .35rem;margin-left:.2rem;background:#0F766E;color:#fff;font-size:.72rem;line-height:1.35rem;text-align:center}
.ms-rp-panel[hidden]{display:none}
.ms-ct-table{width:100%;border-collapse:collapse;font-size:.86rem;border:1px solid #D1D5DB}
.ms-ct-table th,.ms-ct-table td{padding:.5rem .75rem;border-bottom:1px solid #E5E7EB;text-align:left;vertical-align:middle}
.ms-ct-table thead th{background:#F3F4F6;font-size:.76rem;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.04em}
.ms-ct-table tbody th{font-weight:600;color:#111827}
.ms-ct-opt{display:flex;align-items:center;gap:.5rem;margin:0;cursor:pointer}
.ms-ct-opt.is-empty{cursor:default;color:#6B7280}
.ms-ct-opt input{width:1.05rem;height:1.05rem;accent-color:#0F766E}
.ms-ct-n{font-weight:700;font-variant-numeric:tabular-nums;color:#111827}

.ms-aud{display:flex;flex-wrap:wrap;align-items:center;gap:.5rem;padding:.6rem .75rem;margin-bottom:1rem;background:#F3F4F6;border:1px solid #D1D5DB}
.ms-aud-l{font-size:.8rem;font-weight:700;color:#111827;margin:0}
.ms-aud .form-select{max-width:16rem}.ms-aud #msAudName{max-width:14rem}
.ms-aud-sep{width:1px;align-self:stretch;background:#D1D5DB;margin:0 .25rem}
.ms-aud-share{font-size:.78rem;color:#374151;margin:0;display:inline-flex;align-items:center;gap:.3rem}
.ms-aud-msg{font-size:.78rem;color:#0F4C47;font-weight:600}
.ms-aud-msg.is-err{color:#B91C1C}
.ms-retry{display:flex;flex-wrap:wrap;align-items:center;gap:.5rem}.ms-retry .btn{border-radius:0}.ms-track{display:flex;gap:.6rem;align-items:flex-start;margin:0 0 .75rem;padding:.6rem .75rem;border:1px solid #D1D5DB;background:#F9FAFB;font-size:.8rem;color:#374151;cursor:pointer}.ms-track input{margin-top:.2rem;width:1rem;height:1rem;accent-color:#0F766E}
.ms-test{display:flex;flex-wrap:wrap;align-items:center;gap:.6rem}.ms-test .btn{border-radius:0}.ms-test-msg{font-size:.76rem;color:#374151}
.ms-retry-msg{font-size:.76rem;color:#374151}
</style>

<div class="crm-page-header">
  <div>
    <div class="crm-page-title"><i class="bi bi-send-fill" style="color:#0176D3"></i> Wysyłka masowa</div>
    <div class="crm-page-subtitle">E-mail i SMS do grup, podgrup i tagów kontaktów</div>
  </div>
  <div class="crm-page-actions">
    <a href="<?= APP_URL ?>/crm/mass_stats.php" class="btn btn-crm-outline btn-sm"><i class="bi bi-bar-chart-line me-1" aria-hidden="true"></i>Statystyki wysyłek</a>
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
          <small style="color:#0078d4;font-size:.68rem;font-weight:600"><i class="bi bi-microsoft"></i> Microsoft 365<?= $smtp_ok ? ' · zapas: SMTP' : '' ?></small>
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
          <small style="color:#B45309;font-size:.68rem;font-weight:600"><i class="bi bi-broadcast"></i> <?= h($sms_label) ?><?= $sms_fb !== '' ? ' · zapas: ' . h($sms_fb) : '' ?></small>
        </div>
      </div>
      <?php else: ?>
      <div class="channel-btn" style="opacity:.4;cursor:default">
        <i class="bi bi-phone" style="color:#9CA3AF"></i>
        <div class="ch-text">
          <span style="color:#5E6470">SMS</span>
          <small style="font-size:.68rem;color:#5E6470">Brak dostawcy — <a href="<?= APP_URL ?>/admin/sms_settings.php">skonfiguruj</a></small>
        </div>
      </div>
      <?php endif; ?>
    </div>
    <input type="hidden" id="ms_channel" value="email">

    <div class="mt-3">
      <label class="form-label small fw-semibold mb-1 d-flex align-items-center gap-2" for="ms_purpose">
        <i class="bi bi-shield-check text-primary" aria-hidden="true"></i> Podstawa wysyłki
      </label>
      <select id="ms_purpose" class="form-select form-select-sm" style="max-width:520px"
              onchange="MS.purposeChanged()">
        <option value="0" data-channel="any">Komunikat operacyjny — bez zgody marketingowej</option>
        <?php foreach ($consent_purposes as $cp): ?>
        <option value="<?= (int)$cp['id'] ?>" data-channel="<?= h($cp['channel']) ?>">
          <?= h($cp['nazwa']) ?> — <?= (int)($consent_counts[(int)$cp['id']] ?? 0) ?> kontakt(ów) ze zgodą
        </option>
        <?php endforeach; ?>
      </select>
      <div class="form-text" style="font-size:.74rem" id="ms_purpose_hint">
        Wybierz cel, jeśli wysyłasz treść marketingową — odbiorcami będą tylko kontakty
        z aktualną zgodą na ten cel.
      </div>
      <?php /* Wiadomość do członków/wolontariuszy organizacji nie opiera się na zgodzie
               marketingowej, tylko na łączącej nas relacji (członkostwo, umowa,
               współpraca). Zaznaczenie tego pola zdejmuje wymóg wskazania celu zgody,
               ale zostaje w logu wysyłki — bo to deklaracja podstawy prawnej. */ ?>
      <div class="form-check mt-2">
        <input class="form-check-input" type="checkbox" id="ms_members" onchange="MS.membersChanged()">
        <label class="form-check-label small" for="ms_members">
          <strong>Wiadomość do członków organizacji</strong>
          <span class="text-muted">— komunikacja członkowska/wewnętrzna: podstawą jest relacja
          (członkostwo, umowa, wolontariat), nie zgoda marketingowa. Nie musisz wtedy wskazywać celu zgody.</span>
        </label>
      </div>

      <div class="form-check mt-2" id="ms_ignore_optout_row">
        <input class="form-check-input" type="checkbox" id="ms_ignore_optout" onchange="MS.preview()">
        <label class="form-check-label small" for="ms_ignore_optout">
          Uwzględnij także kontakty wypisane z wysyłek
          <span class="text-muted">— tylko dla wiadomości niemarketingowych (np. informacja do stron umowy)</span>
        </label>
      </div>
    </div>

    <?php if ($can_send_as_me): ?>
    <div id="ms_sender_row" class="mt-3">
      <label class="form-label small fw-semibold mb-1 d-flex align-items-center gap-2" for="ms_send_as">
        <i class="bi bi-person-badge text-primary" aria-hidden="true"></i> Konto nadawcy (e-mail)
      </label>
      <select id="ms_send_as" class="form-select form-select-sm" style="max-width:420px">
        <option value="system">Konto Fundacji<?= $sys_from_email ? ' (' . h($sys_from_email) . ')' : '' ?></option>
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
        <div class="ms-step__h">Grupy, tagi, umowy (aktywne / wygasłe) i pojedyncze kontakty — wybory z zakładek łączą się</div>
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

    <!-- ─── Zapisane listy adresatów (kryteria: grupy, tagi, umowy) ─── -->
    <div class="ms-aud" id="msAud">
      <label for="msAudSel" class="ms-aud-l"><i class="bi bi-bookmark-star" aria-hidden="true"></i> Zapisane listy</label>
      <select id="msAudSel" class="form-select form-select-sm" onchange="MS.audLoad(this.value)">
        <option value="">— wybierz zapisaną listę —</option>
      </select>
      <button type="button" class="btn btn-outline-secondary btn-sm" id="msAudDel" onclick="MS.audDelete()" hidden title="Usuń wybraną listę" aria-label="Usuń wybraną listę"><i class="bi bi-trash" aria-hidden="true"></i></button>
      <span class="ms-aud-sep" aria-hidden="true"></span>
      <input type="text" id="msAudName" class="form-control form-control-sm" maxlength="80" placeholder="Nazwa nowej listy" aria-label="Nazwa nowej listy">
      <label class="ms-aud-share"><input type="checkbox" id="msAudShared"> dla wszystkich</label>
      <button type="button" class="btn btn-outline-secondary btn-sm" onclick="MS.audSave()"><i class="bi bi-save" aria-hidden="true"></i> Zapisz bieżący wybór</button>
      <span class="ms-aud-msg" id="msAudMsg" role="status" aria-live="polite"></span>
    </div>

    <!-- ─── Wybór adresatów: zakładki (zaznaczenia ze wszystkich zakładek się sumują) ─── -->
    <div class="ms-rp-tabs" role="tablist" aria-label="Sposób wyboru adresatów">
      <button type="button" class="ms-rp-tab is-on" role="tab" aria-selected="true" data-rp-tab="groups"><i class="bi bi-people-fill" aria-hidden="true"></i> Grupy <span class="ms-rp-n" data-rp-n="groups" hidden></span></button>
      <button type="button" class="ms-rp-tab" role="tab" aria-selected="false" data-rp-tab="contracts"><i class="bi bi-file-earmark-text" aria-hidden="true"></i> Umowy <span class="ms-rp-n" data-rp-n="contracts" hidden></span></button>
      <button type="button" class="ms-rp-tab" role="tab" aria-selected="false" data-rp-tab="tags"><i class="bi bi-tags" aria-hidden="true"></i> Tagi <span class="ms-rp-n" data-rp-n="tags" hidden></span></button>
      <button type="button" class="ms-rp-tab" role="tab" aria-selected="false" data-rp-tab="contacts"><i class="bi bi-person-plus" aria-hidden="true"></i> Kontakty <span class="ms-rp-n" data-rp-n="contacts" hidden></span></button>
    </div>

    <div class="ms-rp-panel" data-rp="groups">
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

    </div>

    <!-- ─── Umowy: typ × stan ─── -->
    <div class="ms-rp-panel" data-rp="contracts" hidden>
      <div class="small mb-2" style="color:#4B5563">
        Adresaci z umów, dopasowani po adresie e-mail do kontaktu CRM.
        <strong>Aktywne</strong> — umowa obowiązująca, robocza lub zablokowana.
        <strong>Wygasłe</strong> — umowa zakończona lub anulowana, a osoba nie ma w tym typie umowy obowiązującej.
      </div>
      <table class="ms-ct-table">
        <thead><tr><th scope="col">Rodzaj umowy</th><th scope="col">Tylko aktywne</th><th scope="col">Tylko wygasłe</th></tr></thead>
        <tbody>
        <?php foreach ($contract_counts as $ck => $cv): ?>
          <tr>
            <th scope="row"><?= h($cv['label']) ?></th>
            <?php foreach (['active' => 'aktywne', 'expired' => 'wygasłe'] as $st => $stl): ?>
            <td>
              <label class="ms-ct-opt<?= $cv[$st] ? '' : ' is-empty' ?>">
                <input type="checkbox" data-cset="<?= h($ck . ':' . $st) ?>" onchange="MS.toggleContractSet(this)"<?= $cv[$st] ? '' : ' disabled' ?>
                       aria-label="<?= h($cv['label'] . ' — ' . $stl) ?>">
                <span class="ms-ct-n"><?= (int)$cv[$st] ?></span> <span class="ms-ct-l"><?= h($stl) ?></span>
              </label>
            </td>
            <?php endforeach; ?>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="ms-rp-panel" data-rp="tags" hidden>
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

    </div>

    <div class="ms-rp-panel" data-rp="contacts" hidden>
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
      <div id="recipientDropped" class="small mb-1" style="display:none"></div>
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
      <span class="ms-varlbl">Odbiorca:</span>
      <?php foreach (['{imie}','{imie_nazwisko}','{email}','{organizacja}','{data}'] as $v): ?>
      <button type="button" class="ms-var" onclick="MS.insertVar('<?= $v ?>')"
              title="Wstaw <?= h($v) ?> w treści"><?= h($v) ?></button>
      <?php endforeach; ?>
      <span class="ms-varlbl ms-2">Nadawca:</span>
      <?php foreach (['{nadawca_imie_nazwisko}','{nadawca_email}','{nadawca_telefon}'] as $v): ?>
      <button type="button" class="ms-var" onclick="MS.insertVar('<?= $v ?>')"
              title="Wstaw <?= h($v) ?> w treści"><?= h($v) ?></button>
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

    <dl class="ms-summary">
      <dt>Odbiorcy</dt><dd id="sendCount">0</dd>
      <dt>Kanał</dt><dd id="sendChannel">E-mail</dd>
    </dl>

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
    <label class="ms-track" id="msTrackRow">
      <input type="checkbox" id="ms_track">
      <span><strong>Śledź otwarcia i kliknięcia</strong> (tylko e-mail) — do wiadomości trafia niewidoczny piksel, a linki przechodzą przez przekierowanie z licznikiem. Wyniki: <a href="<?= APP_URL ?>/crm/mass_stats.php">Statystyki wysyłek</a>. Włączaj świadomie — nie dla wiadomości wrażliwych.</span>
    </label>
    <div class="ms-test mb-2">
      <button type="button" class="btn btn-outline-secondary btn-sm" onclick="MS.testSend(this)">
        <i class="bi bi-envelope-check" aria-hidden="true"></i> Wyślij test do mnie
      </button>
      <span class="ms-test-msg" id="msTestMsg" role="status" aria-live="polite">Zmienne zostaną podstawione danymi pierwszego odbiorcy; wiadomość trafi tylko do ciebie.</span>
    </div>
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
        <div class="ms-retry mt-2">
          <button type="button" class="btn btn-sm btn-outline-secondary" onclick="MS.retry(<?= (int)$hs['id'] ?>, '', this)">
            <i class="bi bi-arrow-repeat" aria-hidden="true"></i> Ponów do tych <?= count($failures) ?>
          </button>
          <?php if (($hs['channel'] ?? '') === 'email' && $sms_ok): ?>
          <button type="button" class="btn btn-sm btn-outline-secondary" onclick="MS.retry(<?= (int)$hs['id'] ?>, 'sms', this)">
            <i class="bi bi-phone" aria-hidden="true"></i> Wyślij im SMS-em
          </button>
          <?php endif; ?>
          <span class="ms-retry-msg" role="status" aria-live="polite"></span>
        </div>
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
  let _contract_sets = new Set(); // "typ:stan" (active|expired)
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

  // Cel wysyłki wpływa na dwie rzeczy: kto jest odbiorcą (filtr zgód po stronie
  // API) i czy pole „uwzględnij wypisanych" ma sens — przy zadeklarowanym celu
  // marketingowym nie ma, bo podstawą jest wtedy zgoda, nie brak sprzeciwu.
  /* Wiadomość członkowska: cel zgody przestaje być potrzebny (i mylący), więc
     wracamy do „komunikatu operacyjnego" i blokujemy wybór celu. */
  function membersChanged() {
    const on   = document.getElementById('ms_members').checked;
    const sel  = document.getElementById('ms_purpose');
    const hint = document.getElementById('ms_purpose_hint');
    if (on) {
      sel.value = '0';
      sel.disabled = true;
      hint.innerHTML = '<span class="text-success">Podstawą jest relacja z organizacją '
                     + '(członkostwo, umowa, wolontariat) — zgoda marketingowa nie jest wymagana. '
                     + 'Deklaracja zapisze się w logu wysyłki.</span>';
    } else {
      sel.disabled = false;
    }
    purposeChanged();
    preview();
  }

  function purposeChanged() {
    const sel  = document.getElementById('ms_purpose');
    const opt  = sel.options[sel.selectedIndex];
    const pid  = parseInt(sel.value, 10) || 0;
    const hint = document.getElementById('ms_purpose_hint');
    const row  = document.getElementById('ms_ignore_optout_row');
    const box  = document.getElementById('ms_ignore_optout');

    if (pid > 0) {
      row.style.display = 'none';
      box.checked = false;
      const ch = opt.dataset.channel || 'any';
      hint.innerHTML = 'Wyślemy tylko do kontaktów z aktualną zgodą na ten cel.'
        + ((ch !== 'any' && ch !== _channel)
            ? ' <span class="text-warning-emphasis"><i class="bi bi-exclamation-triangle"></i> '
              + 'Ten cel dotyczy innego kanału niż wybrany — sprawdź, czy zgoda obejmuje tę wysyłkę.</span>'
            : '');
    } else {
      row.style.display = '';
      hint.textContent = 'Bez celu wysyłka jest traktowana jako komunikat operacyjny — '
        + 'pomijamy kontakty wypisane z wysyłek, chyba że zaznaczysz pole poniżej.';
    }
    preview();
  }

  function purposePayload() {
    return {
      purpose_id:     parseInt(document.getElementById('ms_purpose')?.value, 10) || 0,
      ignore_opt_out: !!document.getElementById('ms_ignore_optout')?.checked,
      members_basis:  !!document.getElementById('ms_members')?.checked,
      contract_sets:  [..._contract_sets]
    };
  }

  // Ilu odbiorców odpadło i dlaczego. Milczące zawężenie listy jest gorsze niż
  // brak filtra — operator musi wiedzieć, że wysyłka nie objęła części grupy.
  function renderDropped(d) {
    const box = document.getElementById('recipientDropped');
    if (!box) return;
    const parts = [];
    if (d && d.no_consent > 0) parts.push(d.no_consent + ' bez zgody na wybrany cel');
    if (d && d.opt_out    > 0) parts.push(d.opt_out + ' wypisanych z wysyłek');
    if (!parts.length) { box.style.display = 'none'; box.innerHTML = ''; return; }
    box.style.display = '';
    box.innerHTML = '<span class="text-muted"><i class="bi bi-funnel me-1"></i>Pominięto: '
                  + parts.join(', ') + '.</span>';
  }

  // Zakładki wyboru adresatów + liczniki wybranych pozycji
  function rpTab(name) {
    document.querySelectorAll('.ms-rp-tab').forEach(b => {
      const on = b.dataset.rpTab === name;
      b.classList.toggle('is-on', on); b.setAttribute('aria-selected', on ? 'true' : 'false');
    });
    document.querySelectorAll('.ms-rp-panel').forEach(p => { p.hidden = p.dataset.rp !== name; });
  }
  function rpBadges() {
    const n = { groups: _group_ids.size, contracts: _contract_sets.size, tags: _tag_filters.size, contacts: _contact_ids.size };
    Object.keys(n).forEach(k => {
      const el = document.querySelector('[data-rp-n="' + k + '"]');
      if (el) { el.textContent = n[k]; el.hidden = !n[k]; }
    });
  }
  document.addEventListener('click', e => {
    const t = e.target.closest('.ms-rp-tab');
    if (t) rpTab(t.dataset.rpTab);
  });
  function toggleContractSet(cb) {
    if (cb.checked) _contract_sets.add(cb.dataset.cset); else _contract_sets.delete(cb.dataset.cset);
    preview();
  }



  // Ponów wysyłkę do niedostarczonych (opcjonalnie innym kanałem)
  async function retry(sendId, channel, btn) {
    const box = btn.closest('.ms-retry'), msg = box.querySelector('.ms-retry-msg');
    const label = channel === 'sms' ? 'SMS-em' : 'ponownie';
    if (!confirm('Wysłać wiadomość ' + label + ' do osób, które jej nie otrzymały?')) return;
    box.querySelectorAll('button').forEach(b => b.disabled = true);
    msg.textContent = 'Przygotowywanie…';
    try {
      const r = await audCall({ action:'retry', send_id: sendId, channel });
      if (!r.ok) throw new Error(r.error);
      msg.textContent = 'Wysyłanie do ' + r.data.recipients + ' osób…';
      const x = await audCall({ action:'execute', send_id: r.data.send_id });
      if (!x.ok) throw new Error(x.error);
      msg.textContent = 'Gotowe: wysłano ' + (x.data.sent_ok || 0) + ', błędy: ' + (x.data.sent_fail || 0) + '. Odśwież stronę, by zobaczyć historię.';
    } catch (e) {
      msg.textContent = 'Błąd: ' + e.message;
      box.querySelectorAll('button').forEach(b => b.disabled = false);
    }
  }


  // Wiadomość próbna do zalogowanego użytkownika
  async function testSend(btn) {
    const msg = document.getElementById('msTestMsg');
    const set = (t, err) => { msg.textContent = t; msg.style.color = err ? '#B91C1C' : '#0F4C47'; };
    const body = getBody();
    if (!body) { set('Wpisz treść wiadomości.', true); return; }
    btn.disabled = true; set('Wysyłanie testu…');
    try {
      const r = await audCall(Object.assign(purposePayload(), {
        action: 'test_send', channel: _channel, body,
        subject: document.getElementById('ms_subject').value.trim(),
        group_ids: [..._group_ids], tag_filter: [..._tag_filters].join(','), contact_ids: [..._contact_ids.keys()]
      }));
      if (!r.ok) throw new Error(r.error);
      set('Test wysłano na ' + r.data.to + (r.data.sample ? ' (dane wzorcowe: ' + r.data.sample + ')' : '') + '.');
    } catch (e) { set(e.message, true); }
    btn.disabled = false;
  }

  // ── Zapisane listy adresatów ──
  let _auds = [];
  function audMsg(t, err) { const m = document.getElementById('msAudMsg'); m.textContent = t || ''; m.classList.toggle('is-err', !!err); }
  function audRender(selectId) {
    const sel = document.getElementById('msAudSel');
    sel.innerHTML = '<option value="">— wybierz zapisaną listę —</option>' +
      _auds.map(a => `<option value="${a.id}">${esc(a.name)}${a.shared && !a.mine ? ' (wspólna)' : ''}</option>`).join('');
    if (selectId) sel.value = String(selectId);
    document.getElementById('msAudDel').hidden = !sel.value;
  }
  function audCall(payload) {
    return fetch(API, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(payload) }).then(r => r.json());
  }
  function audInit() { audCall({ action:'audience_list' }).then(r => { if (r.ok) { _auds = r.data; audRender(); } }); }
  function audLoad(id) {
    document.getElementById('msAudDel').hidden = !id;
    const a = _auds.find(x => String(x.id) === String(id));
    if (!a) return;
    const c = a.criteria || {};
    _group_ids = new Set((c.group_ids || []).map(Number));
    document.querySelectorAll('#groupPickerDropdown [data-id]').forEach(el => {
      const on = _group_ids.has(parseInt(el.dataset.id));
      el.classList.toggle('selected', on);
      const ic = el.querySelector('.gsi-check i'); if (ic) ic.style.display = on ? '' : 'none';
    });
    renderGroupPills();
    _tag_filters = new Set(c.tags || []);
    document.querySelectorAll('#tagChips .tag-chip').forEach(ch => ch.classList.toggle('selected', _tag_filters.has(ch.dataset.tag)));
    _contract_sets = new Set(c.contract_sets || []);
    document.querySelectorAll('[data-cset]').forEach(cb => { cb.checked = _contract_sets.has(cb.dataset.cset); });
    audMsg('Wczytano listę „' + a.name + '”.');
    preview();
  }
  function audSave() {
    const name = document.getElementById('msAudName').value.trim();
    if (!name) { audMsg('Podaj nazwę listy.', true); return; }
    audCall({ action:'audience_save', name, shared: document.getElementById('msAudShared').checked,
              group_ids: [..._group_ids], tags: [..._tag_filters], contract_sets: [..._contract_sets] }).then(r => {
      if (!r.ok) { audMsg(r.error, true); return; }
      _auds = r.data;
      const saved = _auds.find(x => x.name.toLowerCase() === name.toLowerCase() && x.mine);
      audRender(saved && saved.id);
      document.getElementById('msAudName').value = '';
      audMsg('Zapisano listę „' + name + '” — przy każdej wysyłce przeliczy się na nowo.');
    });
  }
  function audDelete() {
    const id = document.getElementById('msAudSel').value;
    const a = _auds.find(x => String(x.id) === String(id));
    if (!a || !confirm('Usunąć zapisaną listę „' + a.name + '”?')) return;
    audCall({ action:'audience_delete', id: parseInt(id) }).then(r => {
      if (!r.ok) { audMsg(r.error, true); return; }
      _auds = r.data; audRender(); audMsg('Usunięto listę.');
    });
  }
  document.addEventListener('DOMContentLoaded', audInit);
  if (document.readyState !== 'loading') audInit();

  function preview() {
    rpBadges();
    const groups  = [..._group_ids];
    const tags    = [..._tag_filters].join(',');
    const c_ids   = [..._contact_ids.keys()];
    if (!groups.length && !tags && !c_ids.length && !_contract_sets.size) {
      _total = 0;
      document.getElementById('recipientPreviewBox').style.display='none';
      document.getElementById('recipientCount').textContent='0';
      document.getElementById('sendCount').textContent='0';
      document.getElementById('sendBtnCount').textContent='0';
      document.getElementById('sendBtn').disabled = true;
      renderDropped(null);
      return;
    }
    document.getElementById('recipientPreviewBox').style.display='';

    const payload = Object.assign({
      action: 'preview',
      group_id: groups[0] || 0,
      tag_filter: tags,
      contact_ids: c_ids,
      channel: _channel,
      person_overrides: _person_over
    }, purposePayload());

    // Multiple groups: merge all
    if (groups.length > 1 || c_ids.length) {
      const allPayload = Object.assign({ action:'preview', group_id: groups[0]||0, tag_filter: tags, contact_ids: c_ids, channel: _channel, group_ids: groups, person_overrides: _person_over }, purposePayload());
      fetch(API, { method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(allPayload) })
        .then(r=>r.json()).then(res=>{
          if (!res.ok) return;
          _total = res.data.total;
          updateCounters();
          renderDropped(res.data.dropped);
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
        renderDropped(res.data.dropped);
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
    if (!groups.length&&!tags&&!c_ids.length&&!_contract_sets.size) { alert('Wybierz grupę, umowy, tag lub dodaj kontakty.'); return; }
    if (_total===0) { alert('Brak odbiorców dla wybranego kanału.'); return; }

    const dwNote = dw.length ? `\nDW: ${dw.join(', ')}` : '';
    if (!confirm(`Wysłać ${_channel.toUpperCase()} do ${_total} odbiorców?${dwNote}`)) return;

    document.getElementById('sendBtn').disabled=true;
    document.getElementById('progressSection').style.display='';
    document.getElementById('progressLabel').textContent='Przygotowywanie wysyłki…';
    document.getElementById('progressFill').style.width='5%';

    const tplText = document.getElementById('ms_tpl_select').options[document.getElementById('ms_tpl_select').selectedIndex]?.text||'';

    // Jedna wysyłka łącząca wszystkie grupy + indywidualne kontakty.
    // purposePayload() musi tu być ten sam co w podglądzie — serwer filtruje
    // odbiorców ponownie, więc rozjazd oznaczałby wysyłkę do innej listy.
    const startPayload = Object.assign(purposePayload(), {
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
      track: _channel === 'email' && !!document.getElementById('ms_track')?.checked,
      person_overrides: _person_over,
    });

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

  return { setChannel, toggleGroupPicker, filterGroupPicker, pickGroup, toggleTag, toggleContractSet, audLoad, audSave, audDelete, retry, testSend,
           searchContacts, addContact, removeContact,
           searchDw, dwKeydown, addDwManual, addDw, removeDw,
           preview, send, insertVar, loadTemplate, purposeChanged, membersChanged,
           wizNext, wizBack, wizGo };
})();
</script>

<?php include __DIR__ . '/includes/footer_crm.php'; ?>
