<?php
/**
 * modules/strategy_actions/view/_export.php — okno „Eksport i kalendarz ICS” ekranu listy (część dzApp()).
 * Eksport to zwykły POST formularza (plik do pobrania) z działaniami widocznymi po filtrach i wybranymi kolumnami;
 * wybór kolumn i formatu zapamiętany w przeglądarce. Adres ICS wydaje POST /dzialania/ics/link.
 */
?>
<div class="dz-overlay" x-show="exp.open" x-cloak @click="closeExport()" aria-hidden="true"></div>
<div class="dz-dialog" x-show="exp.open" x-cloak role="dialog" aria-modal="true" aria-labelledby="dz-exp-h"
     @keydown.escape.window="exp.open && closeExport()">
  <div class="ph">
    <h2 id="dz-exp-h">Eksport i kalendarz</h2>
    <button type="button" class="x" x-ref="expClose" @click="closeExport()" aria-label="Zamknij okno"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
  </div>
  <div class="pb">
    <form method="post" action="<?= h(ActionsService::url('eksport')) ?>" @submit="expSave()">
      <?= Router::csrfField() ?>
      <template x-for="a in rows" :key="a.id"><input type="hidden" name="ids[]" :value="a.id"></template>
      <h3 style="margin-top:0">Plik z listą działań</h3>
      <fieldset class="dz-cols">
        <legend class="dz-lbl">Kolumny</legend>
        <template x-for="c in expCols" :key="c.key">
          <label class="dz-check"><input type="checkbox" name="cols[]" :value="c.key" x-model="exp.cols"> <span x-text="c.label"></span></label>
        </template>
      </fieldset>
      <div class="d-flex gap-2 mt-2 flex-wrap">
        <button type="button" class="dz-btn dz-btn--ghost dz-btn--sm" @click="expPreset('def')">Domyślne kolumny</button>
        <button type="button" class="dz-btn dz-btn--ghost dz-btn--sm" @click="expPreset('all')">Wszystkie kolumny</button>
      </div>
      <fieldset class="mt-3 d-flex gap-3 flex-wrap" style="border:0;padding:0">
        <legend class="dz-lbl">Format</legend>
        <label class="dz-check"><input type="radio" name="format" value="xlsx" x-model="exp.format"> Excel (.xlsx)</label>
        <label class="dz-check"><input type="radio" name="format" value="csv" x-model="exp.format"> CSV (średnik, UTF-8)</label>
      </fieldset>
      <p class="dz-muted small mt-2 mb-2">Plik obejmie <b x-text="rows.length"></b> działań — tyle, ile widać teraz po filtrach, w tej samej kolejności.</p>
      <button type="submit" class="dz-btn" :disabled="!exp.cols.length || !rows.length"><i class="bi bi-download" aria-hidden="true"></i>Pobierz plik</button>
    </form>

    <h3>Kalendarz do subskrypcji (ICS)</h3>
    <p class="small mb-2">Działania z datami i otwarte etapy z terminem w Twoim kalendarzu. W Outlooku: <i>Dodaj kalendarz → Z internetu</i>; w Kalendarzu Google: <i>Inne kalendarze → Z adresu URL</i>. Kalendarz odświeża się sam. Adres jest osobisty — nie udostępniaj go.</p>
    <button type="button" class="dz-btn dz-btn--ghost dz-btn--sm" x-show="!ics.url" @click="icsGet(false)" :disabled="ics.busy"><i class="bi bi-calendar-plus" aria-hidden="true"></i>Pokaż mój adres kalendarza</button>
    <div x-show="ics.url" x-cloak>
      <label class="dz-lbl" for="dz-ics">Adres kalendarza</label>
      <input id="dz-ics" class="dz-in" readonly x-ref="icsUrl" :value="ics.url" @focus="$event.target.select()">
      <div class="d-flex gap-2 mt-2 flex-wrap">
        <button type="button" class="dz-btn dz-btn--sm" @click="icsCopy()"><i class="bi bi-clipboard" aria-hidden="true"></i>Kopiuj adres</button>
        <button type="button" class="dz-btn dz-btn--danger dz-btn--sm" @click="icsGet(true)" :disabled="ics.busy"><i class="bi bi-arrow-repeat" aria-hidden="true"></i>Wygeneruj nowy adres</button>
      </div>
    </div>
  </div>
</div>
