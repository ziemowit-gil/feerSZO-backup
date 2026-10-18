<?php
/**
 * modules/ezd_bulk/view/bar.php — pasek akcji masowych listy koszulek (szata Metro: ciemny app-bar, płaskie
 * przyciski, zero zaokrągleń). Przyklejony do dołu ekranu; pojawia się po zaznaczeniu koszulek (.bulk-cb).
 * Okna akcji i skrypt są w view/modals.php (osobno — modale muszą leżeć poza kartą listy).
 */
?>
<style>
html body .ezb-bar{position:sticky;bottom:0;z-index:20;display:flex;flex-wrap:wrap;align-items:stretch;background:var(--em-dark,#1d1d1d);color:#fff;border-top:0;margin:0}
html body .ezb-bar[hidden]{display:none}
html body .ezb-count{display:flex;flex-direction:column;justify-content:center;background:var(--em-acc,#0f6fa8);padding:.45rem 1rem;min-width:7.5rem;line-height:1.1}
html body .ezb-count strong{font-size:1.5rem;font-weight:300}
html body .ezb-count span{font-size:.68rem;text-transform:uppercase;letter-spacing:.08em}
html body .ezb-actions{display:flex;flex-wrap:wrap;flex:1;align-items:stretch}
html body .ezb-act{display:inline-flex;flex-direction:column;align-items:center;justify-content:center;gap:.2rem;min-width:5.6rem;padding:.5rem .7rem;background:transparent;color:#fff;border:0;border-right:1px solid rgba(255,255,255,.12);font-size:.72rem;font-weight:600;cursor:pointer;text-decoration:none}
html body .ezb-act i{font-size:1.1rem}
html body .ezb-act:hover{background:rgba(255,255,255,.14);color:#fff}
html body .ezb-act--danger i{color:#ff8a9e}
html body .ezb-act--end{margin-left:auto;border-right:0;border-left:1px solid rgba(255,255,255,.12)}
html body .ezb-modal .ezb-sum{background:var(--em-light,#f2f2f2);border-left:6px solid var(--em-acc,#0f6fa8);padding:.5rem .75rem;font-size:.82rem;margin-bottom:.9rem}
html body .ezb-modal .ezb-sum--warn{border-left-color:var(--m-orange,#c25100)}
html body .ezb-modal .form-control,html body .ezb-modal .form-select{border:2px solid var(--em-field,#8a8a8a)}
html body .ezb-modal .form-label{font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--em-muted,#5f5f5f)}
</style>

<div class="ezb-bar" id="ezbBar" role="region" aria-label="Akcje na zaznaczonych koszulkach" hidden>
  <div class="ezb-count" aria-live="polite"><strong id="ezbCount">0</strong><span>zaznaczonych</span></div>
  <div class="ezb-actions">
    <button type="button" class="ezb-act ezb-act--danger" data-bs-toggle="modal" data-bs-target="#ezbCloseModal"><i class="bi bi-lock" aria-hidden="true"></i>Zamknij</button>
    <button type="button" class="ezb-act" data-bs-toggle="modal" data-bs-target="#bulkModal"><i class="bi bi-arrow-left-right" aria-hidden="true"></i>Przerejestruj</button>
    <button type="button" class="ezb-act" data-bs-toggle="modal" data-bs-target="#ezbOwnerModal"><i class="bi bi-person-gear" aria-hidden="true"></i>Prowadzący</button>
    <button type="button" class="ezb-act" data-bs-toggle="modal" data-bs-target="#ezbDeadlineModal"><i class="bi bi-calendar-event" aria-hidden="true"></i>Termin</button>
    <button type="button" class="ezb-act" data-bs-toggle="modal" data-bs-target="#ezbUrgentModal"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i>Pilne</button>
    <button type="submit" class="ezb-act" form="ezbCsvForm"><i class="bi bi-filetype-csv" aria-hidden="true"></i>Eksport CSV</button>
    <button type="button" class="ezb-act ezb-act--end" id="ezbClear"><i class="bi bi-x-lg" aria-hidden="true"></i>Odznacz</button>
  </div>
</div>
