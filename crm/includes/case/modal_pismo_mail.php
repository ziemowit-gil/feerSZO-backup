<?php
/**
 * crm/includes/case/modal_pismo_mail.php — okno „Wyślij pismo e-mailem” przy sprawie CRM: dowolne adresy Do/DW,
 * temat, treść, nadawca (konto Fundacji albo skrzynka imienna — crm_sender_accounts), plik pisma jako załącznik.
 * Włączany przez include w zakresie crm/cases/view.php ($case, $id, $can_write). Obsługa: akcja POST mail_pismo
 * → crm_case_letter_mail() w includes/crm_case_extras.php.
 */
if (!isset($case)) { http_response_code(400); exit; }
if (!$can_write) return;
require_once dirname(__DIR__, 3) . '/includes/crm_sender.php';
$_pm_accounts = crm_sender_accounts(current_user() ?: null);
$_pm_default  = crm_sender_default($_pm_accounts);
$_pm_contact  = (int)($case['contact_id'] ?? 0) ? (string)(db_one("SELECT email FROM crm_contacts WHERE id = ?", [(int)$case['contact_id']])['email'] ?? '') : '';
?>
<!-- ══ MODAL: Wyślij pismo e-mailem ══════════════════════════════════════════ -->
<div class="modal fade" id="modalPismoMail" tabindex="-1" aria-labelledby="modalPismoMailLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <form method="post" id="pismoMailForm">
        <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
        <input type="hidden" name="_action" value="mail_pismo">
        <input type="hidden" name="letter_id" id="pmLetterId" value="">
        <div class="modal-header py-2 px-3" style="background:#15803D;color:#fff">
          <h5 class="modal-title fw-bold" id="modalPismoMailLabel" style="font-size:.92rem"><i class="bi bi-send me-2" aria-hidden="true"></i>Wyślij pismo e-mailem</h5>
          <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Zamknij"></button>
        </div>
        <div class="modal-body">
          <p class="small mb-2">Pismo: <b id="pmTitle"></b><?= !empty($case['case_number']) ? ' · sprawa <span class="font-monospace">' . h($case['case_number']) . '</span>' : '' ?></p>
          <div class="row g-2">
            <div class="col-md-7">
              <label class="form-label small fw-semibold mb-1" for="pmTo">Do <span class="text-danger" aria-hidden="true">*</span></label>
              <input type="text" class="form-control form-control-sm" id="pmTo" name="mail_to" required autocomplete="off" aria-describedby="pmToHelp" placeholder="adres@domena.pl">
              <div id="pmToHelp" class="form-text">Dowolne adresy, kilka oddziel przecinkiem — każdy dostanie osobną wiadomość.</div>
            </div>
            <div class="col-md-5">
              <label class="form-label small fw-semibold mb-1" for="pmCc">DW (kopia)</label>
              <input type="text" class="form-control form-control-sm" id="pmCc" name="mail_cc" autocomplete="off" placeholder="opcjonalnie">
            </div>
            <div class="col-md-7">
              <label class="form-label small fw-semibold mb-1" for="pmSubject">Temat</label>
              <input type="text" class="form-control form-control-sm" id="pmSubject" name="mail_subject" maxlength="250">
            </div>
            <div class="col-md-5">
              <label class="form-label small fw-semibold mb-1" for="pmSender">Wyślij jako</label>
              <select class="form-select form-select-sm" id="pmSender" name="mail_sender">
                <?php foreach ($_pm_accounts as $a): ?><option value="<?= h($a['key']) ?>"<?= $a['key'] === $_pm_default ? ' selected' : '' ?>><?= h($a['label']) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label small fw-semibold mb-1" for="pmBody">Treść wiadomości</label>
              <textarea class="form-control form-control-sm" id="pmBody" name="mail_body" rows="6"></textarea>
            </div>
            <div class="col-12 d-flex flex-wrap gap-3">
              <div class="form-check" id="pmAttachWrap"><input class="form-check-input" type="checkbox" id="pmAttach" name="mail_attach" value="1" checked><label class="form-check-label small" for="pmAttach">Dołącz plik pisma (<span id="pmFile"></span>)</label></div>
              <div class="form-check" id="pmTextWrap"><input class="form-check-input" type="checkbox" id="pmText" name="mail_text" value="1"><label class="form-check-label small" for="pmText">Wklej treść pisma pod wiadomością</label></div>
            </div>
            <div class="col-12 small text-secondary">Wysyłka zostanie zapisana w notatkach i wiadomościach sprawy oraz przy piśmie.</div>
          </div>
        </div>
        <div class="modal-footer py-2">
          <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-sm btn-success"><i class="bi bi-send me-1" aria-hidden="true"></i>Wyślij</button>
        </div>
      </form>
    </div>
  </div>
</div>
<script>
function pismoMail(l) {
  var $ = function (id) { return document.getElementById(id); };
  var caseNo = <?= json_encode((string)($case['case_number'] ?? ''), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
  var contactMail = <?= json_encode($_pm_contact, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
  $('pmLetterId').value = l.id;
  $('pmTitle').textContent = l.tytul || 'pismo';
  $('pmTo').value = l.odbiorca_email || contactMail || '';
  $('pmCc').value = '';
  $('pmSubject').value = (l.tytul || 'Pismo') + (caseNo ? ' — sprawa ' + caseNo : '');
  $('pmBody').value = 'Dzień dobry,\n\nw załączeniu przesyłamy pismo „' + (l.tytul || '') + '”.\n\nZ poważaniem';
  var hasFile = !!l.plik;
  $('pmAttachWrap').style.display = hasFile ? '' : 'none';
  $('pmAttach').checked = hasFile;
  $('pmFile').textContent = hasFile ? String(l.plik).split('/').pop() : '';
  var hasText = !!(l.tresc && String(l.tresc).trim());
  $('pmTextWrap').style.display = hasText ? '' : 'none';
  $('pmText').checked = hasText && !hasFile;
  bootstrap.Modal.getOrCreateInstance($('modalPismoMail')).show();
  setTimeout(function () { $('pmTo').focus(); }, 300);
}
</script>
