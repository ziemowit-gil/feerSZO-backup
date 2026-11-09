<?php
/**
 * modules/edok_bulk/view/bar.php — pasek akcji zbiorczych listy EODoK (szata Metro: ciemny app-bar, płaskie
 * przyciski, zero zaokrągleń). Przyklejony do dołu ekranu; pojawia się po zaznaczeniu dokumentów (.edok-sel).
 * Okna akcji i skrypt zaznaczania są w view/modals.php (modale muszą leżeć poza tabelą).
 */
require_once dirname(__DIR__) . '/logic/edokBulk.php';
?>
<style>
html body .edb-bar{position:sticky;bottom:0;z-index:20;display:flex;flex-wrap:wrap;align-items:stretch;background:#1d1d1d;color:#fff;margin:0 0 .5rem}
html body .edb-bar[hidden]{display:none}
html body .edb-count{display:flex;flex-direction:column;justify-content:center;background:#0f6fa8;padding:.45rem 1rem;min-width:7.5rem;line-height:1.1}
html body .edb-count strong{font-size:1.5rem;font-weight:300}
html body .edb-count span{font-size:.68rem;text-transform:uppercase;letter-spacing:.08em}
html body .edb-actions{display:flex;flex-wrap:wrap;flex:1;align-items:stretch}
html body .edb-act{display:inline-flex;flex-direction:column;align-items:center;justify-content:center;gap:.2rem;min-width:6.2rem;padding:.5rem .7rem;background:transparent;color:#fff;border:0;border-right:1px solid rgba(255,255,255,.12);border-radius:0;font-size:.72rem;font-weight:600;cursor:pointer;text-decoration:none}
html body .edb-act i{font-size:1.1rem}
html body .edb-act:hover,html body .edb-act:focus-visible{background:rgba(255,255,255,.14);color:#fff}
html body .edb-act:focus-visible{outline:3px solid #ffd54a;outline-offset:-3px}
html body .edb-act--ok i{color:#7ee08a}
html body .edb-act--end{margin-left:auto;border-right:0;border-left:1px solid rgba(255,255,255,.12)}
html body .edb-modal .modal-content{border-radius:0;border:0}
html body .edb-modal .edb-sum{background:#f2f2f2;border-left:6px solid #0f6fa8;padding:.5rem .75rem;font-size:.82rem;margin-bottom:.9rem}
html body .edb-modal .edb-sum--warn{border-left-color:#c25100}
html body .edb-modal .form-control,html body .edb-modal .form-select{border:2px solid #8a8a8a;border-radius:0}
html body .edb-modal .form-label{font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:#5f5f5f}
html body .edb-modal .btn{border-radius:0}
</style>

<div class="edb-bar" id="edbBar" role="region" aria-label="Akcje na zaznaczonych dokumentach" hidden>
  <div class="edb-count" aria-live="polite"><strong id="edbCount">0</strong><span>zaznaczonych</span></div>
  <div class="edb-actions">
    <button type="button" class="edb-act edb-act--ok" data-bs-toggle="modal" data-bs-target="#edbSignModal"><i class="bi bi-check2-all" aria-hidden="true"></i>Akceptuj (PIN)</button>
    <button type="submit" class="edb-act" form="edbZipForm"><i class="bi bi-file-earmark-zip" aria-hidden="true"></i>Pobierz PDF-y (ZIP)</button>
    <?php if (edokBulkCanPay()): ?>
    <button type="button" class="edb-act" data-bs-toggle="modal" data-bs-target="#edbPaidModal"><i class="bi bi-cash-coin" aria-hidden="true"></i>Oznacz jako opłacone</button>
    <?php endif; ?>
    <button type="button" class="edb-act edb-act--end" id="edbClear"><i class="bi bi-x-lg" aria-hidden="true"></i>Odznacz</button>
  </div>
</div>
