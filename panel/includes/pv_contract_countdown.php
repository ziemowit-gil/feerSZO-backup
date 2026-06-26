<?php
/**
 * panel/includes/pv_contract_countdown.php — Żywe odliczanie do końca umowy.
 *
 * Karta umowy (hero) z tykającym licznikiem Dni/Godz/Min/Sek + paskiem postępu
 * aktualizowanym na żywo. Logika tykania w waniliowym JS ze wspólnego
 * pv_enhance.php ([data-pv-countdown]). Gdy JS zawiedzie, pasek i licznik
 * pokazują wartość początkową policzoną serwerowo.
 *
 * a11y: tykające cyfry są DEKORACYJNE (aria-hidden) — źródłem prawdy dla
 * czytników ekranu jest role="progressbar" z aria-valuetext.
 *
 * Wymaga w zasięgu: $contract_progress (pct,days_left,ended),
 *   $_active_row['data_zawarcia'], $_active_contract['data_zakonczenia'], h().
 */
if (empty($contract_progress)) return;

$_cd_start = strtotime($_active_row['data_zawarcia'] ?? '');
$_cd_end   = strtotime(($_active_contract['data_zakonczenia'] ?? '') . ' 23:59:59');
if (!$_cd_start || !$_cd_end) return;

$_cd_pct   = (int)$contract_progress['pct'];
$_cd_ended = !empty($contract_progress['ended']);
$_cd_days  = (int)$contract_progress['days_left'];
$_cd_vtext = $_cd_ended
    ? 'Umowa zakończona — 100%'
    : ('Postęp ' . $_cd_pct . '%, pozostało ' . $_cd_days . ' dni');

// Wartości początkowe kafelków (JS nadpisze co sekundę).
$_cd_now   = time();
$_cd_rem   = max(0, $_cd_end - $_cd_now);
$_cd_h     = (int)floor(($_cd_rem % 86400) / 3600);
$_cd_m     = (int)floor(($_cd_rem % 3600) / 60);
$_cd_s     = (int)($_cd_rem % 60);
$_pad      = fn($n) => str_pad((string)$n, 2, '0', STR_PAD_LEFT);
?>
<div id="pvContractCountdown"
     data-pv-countdown
     data-start="<?= (int)($_cd_start * 1000) ?>"
     data-end="<?= (int)($_cd_end * 1000) ?>">
  <?php if (!$_cd_ended): ?>
  <div class="pv-cd" data-cd-units aria-hidden="true">
    <div class="pv-cd-unit"><span class="pv-cd-num" data-cd="days"><?= $_cd_days ?></span><span class="pv-cd-lbl">dni</span></div>
    <div class="pv-cd-unit"><span class="pv-cd-num" data-cd="hours"><?= $_pad($_cd_h) ?></span><span class="pv-cd-lbl">godz</span></div>
    <div class="pv-cd-unit"><span class="pv-cd-num" data-cd="mins"><?= $_pad($_cd_m) ?></span><span class="pv-cd-lbl">min</span></div>
    <div class="pv-cd-unit"><span class="pv-cd-num" data-cd="secs"><?= $_pad($_cd_s) ?></span><span class="pv-cd-lbl">sek</span></div>
  </div>
  <?php endif; ?>
  <div class="vol-progress-wrap"
       data-cd-bar
       role="progressbar"
       aria-valuenow="<?= $_cd_pct ?>"
       aria-valuemin="0"
       aria-valuemax="100"
       aria-valuetext="<?= h($_cd_vtext) ?>"
       aria-label="Czas trwania umowy">
    <div class="vol-progress-fill" data-cd-fill style="width:<?= $_cd_pct ?>%"></div>
  </div>
  <div class="vol-progress-label" aria-hidden="true">
    <span data-cd-label>
      <?php if ($_cd_ended): ?><strong>Umowa zakończona</strong>
      <?php else: ?>Pozostało <strong><?= $_cd_days ?></strong> dni<?php endif; ?>
    </span>
    <span data-cd-pct><?= $_cd_pct ?>%</span>
  </div>
</div>

<?php require_once __DIR__ . '/pv_enhance.php'; ?>
