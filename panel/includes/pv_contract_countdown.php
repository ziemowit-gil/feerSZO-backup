<?php
/**
 * panel/includes/pv_contract_countdown.php — Żywe odliczanie do końca umowy.
 *
 * Wyspa React (loader: pv_react_boot.php) wzbogacająca kartę umowy (hero) o
 * tykający licznik Dni/Godz/Min/Sek + pasek postępu aktualizowany na żywo.
 *
 * Progressive enhancement: w węźle montującym jest pełny pasek postępu
 * renderowany serwerowo (działa bez JS / przy awarii CDN).
 *
 * a11y: tykające cyfry są DEKORACYJNE (aria-hidden) — źródłem prawdy dla
 * czytników ekranu jest role="progressbar" z aria-valuetext „Postęp X%,
 * pozostało Y dni". Dzięki temu sekundy nie spamują komunikatów SR.
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
?>
<style>
/* ── Odliczanie do końca umowy (na tle hero) ────────────────────────────── */
.pv-cd{display:flex;gap:.4rem;margin-top:.85rem;flex-wrap:wrap}
.pv-cd-unit{background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.22);border-radius:9px;padding:.35rem .55rem;min-width:48px;text-align:center;line-height:1.05}
.pv-cd-num{display:block;font-size:1.05rem;font-weight:900;font-variant-numeric:tabular-nums;letter-spacing:.02em}
.pv-cd-lbl{display:block;font-size:.6rem;text-transform:uppercase;letter-spacing:.08em;opacity:.82;margin-top:.1rem}
</style>
<div id="pvContractCountdown"
     data-start="<?= (int)($_cd_start * 1000) ?>"
     data-end="<?= (int)($_cd_end * 1000) ?>"
     data-ended="<?= $_cd_ended ? '1' : '0' ?>">
  <!-- ── Fallback serwerowy (działa bez JS / przy awarii CDN) ─────────────── -->
  <div class="vol-progress-wrap"
       role="progressbar"
       aria-valuenow="<?= $_cd_pct ?>"
       aria-valuemin="0"
       aria-valuemax="100"
       aria-valuetext="<?= h($_cd_vtext) ?>"
       aria-label="Czas trwania umowy">
    <div class="vol-progress-fill" style="width:<?= $_cd_pct ?>%"></div>
  </div>
  <div class="vol-progress-label" aria-hidden="true">
    <span>
      <?php if ($_cd_ended): ?><strong>Umowa zakończona</strong>
      <?php else: ?>Pozostało <strong><?= $_cd_days ?></strong> dni<?php endif; ?>
    </span>
    <span><?= $_cd_pct ?>%</span>
  </div>
</div>

<?php require_once __DIR__ . '/pv_react_boot.php'; ?>
<script>
window.pvReact(function (React, ReactDOM, html) {
  var mount = document.getElementById('pvContractCountdown');
  if (!mount) return; // fallback serwerowy zostaje

  var START = parseInt(mount.getAttribute('data-start'), 10);
  var END   = parseInt(mount.getAttribute('data-end'), 10);
  if (!START || !END) return;

  var useState = React.useState, useEffect = React.useEffect;

  function pad(n){ return (n < 10 ? '0' : '') + n; }
  function plDni(n){ return n === 1 ? 'dzień' : 'dni'; } // odmiana dla czytnika ekranu

  function Countdown(){
    var s = useState(Date.now()), now = s[0], setNow = s[1];
    useEffect(function(){
      var id = setInterval(function(){ setNow(Date.now()); }, 1000);
      return function(){ clearInterval(id); };
    }, []);

    var remain = Math.max(0, END - now);
    var ended  = remain <= 0;
    var pct    = END > START ? Math.min(100, Math.max(0, Math.round((now - START) / (END - START) * 100))) : 100;

    var totalSec = Math.floor(remain / 1000);
    var days  = Math.floor(totalSec / 86400);
    var hours = Math.floor((totalSec % 86400) / 3600);
    var mins  = Math.floor((totalSec % 3600) / 60);
    var secs  = totalSec % 60;

    var vtext = ended
      ? 'Umowa zakończona — 100%'
      : ('Postęp ' + pct + '%, pozostało ' + days + ' ' + plDni(days));

    var units = ended ? null : html`
      <div class="pv-cd" aria-hidden="true">
        <div class="pv-cd-unit"><span class="pv-cd-num">${days}</span><span class="pv-cd-lbl">dni</span></div>
        <div class="pv-cd-unit"><span class="pv-cd-num">${pad(hours)}</span><span class="pv-cd-lbl">godz</span></div>
        <div class="pv-cd-unit"><span class="pv-cd-num">${pad(mins)}</span><span class="pv-cd-lbl">min</span></div>
        <div class="pv-cd-unit"><span class="pv-cd-num">${pad(secs)}</span><span class="pv-cd-lbl">sek</span></div>
      </div>`;

    return html`
      <div>
        ${units}
        <div class="vol-progress-wrap" role="progressbar"
             aria-valuenow=${pct} aria-valuemin="0" aria-valuemax="100"
             aria-valuetext=${vtext} aria-label="Czas trwania umowy">
          <div class="vol-progress-fill" style=${{width: pct + '%'}}></div>
        </div>
        <div class="vol-progress-label" aria-hidden="true">
          <span>${ended ? html`<strong>Umowa zakończona</strong>` : html`Pozostało <strong>${days}</strong> dni`}</span>
          <span>${pct}%</span>
        </div>
      </div>`;
  }

  try {
    ReactDOM.createRoot(mount).render(html`<${Countdown} />`);
  } catch (e) { /* fallback serwerowy zostaje */ }
});
</script>
