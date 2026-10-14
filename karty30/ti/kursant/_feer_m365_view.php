<?php
/**
 * Partial: konto Microsoft 365 kursanta na tenancie organizacji FEER (modules/ti_m365_feer; zakłada je kierownik
 * w panelu dydaktyka → Microsoft 365 → Tenant FEER). Wymaga: $account (wiersz k30_ti_student_accounts).
 * Bez konta — nic nie rysuje. Używany w zakładkach „Szkolenia online” i „Licencje i usługi”.
 */
$_fupn = trim((string)($account['feer_ms_upn'] ?? ''));
if ($_fupn === '') return;
$_fhint = rawurlencode($_fupn);
?>
<section class="card border-0 shadow-sm mt-3 mb-3" aria-labelledby="kp-feer-h">
  <div class="card-body">
    <h2 class="h6 fw-bold d-flex align-items-center mb-2" id="kp-feer-h"><i class="bi bi-microsoft me-2 text-primary" aria-hidden="true"></i>Konto Microsoft 365 — <?= h(defined('ORG_NAME') ? ORG_NAME : 'FEER') ?></h2>
    <p class="small mb-2">Twój login:<br><span class="font-monospace fw-semibold"><?= h($_fupn) ?></span>
      <button type="button" class="btn btn-sm btn-outline-secondary py-0 ms-1" onclick="navigator.clipboard.writeText(<?= h(json_encode($_fupn)) ?>);this.textContent='skopiowano'">Kopiuj</button></p>
    <div class="d-flex flex-wrap gap-2 mb-2">
      <a class="btn btn-sm btn-primary" href="https://www.office.com/?login_hint=<?= h($_fhint) ?>" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Otwórz Office 365</a>
      <a class="btn btn-sm btn-outline-primary" href="https://outlook.office.com/mail/?login_hint=<?= h($_fhint) ?>" target="_blank" rel="noopener">Poczta Outlook</a>
      <a class="btn btn-sm btn-outline-primary" href="https://teams.microsoft.com/?login_hint=<?= h($_fhint) ?>" target="_blank" rel="noopener">Teams</a>
    </div>
    <p class="small text-body-secondary mb-0">Hasło startowe dostałeś e-mailem lub SMS-em — przy pierwszym logowaniu Microsoft poprosi o nowe. Nie pamiętasz hasła? Napisz do prowadzącego w Wiadomościach.</p>
  </div>
</section>
