<?php
/**
 * modules/strategy_actions/view/_panel.php — panel szczegółów działania (z prawej) na ekranie listy.
 * Część komponentu dzApp() z index.php: dane z GET /dzialania/{id}/panel, szybka zmiana statusu,
 * wpisywanie wykonania wskaźników, etapy i historia (_journal.php), duplikowanie. Esc lub tło zamyka; fokus wraca na element wywołujący.
 */
?>
<div class="dz-overlay" x-show="panel.open" x-cloak @click="closePanel()" aria-hidden="true"></div>
<aside class="dz-panel" x-show="panel.open" x-cloak role="dialog" aria-modal="true" aria-labelledby="dz-p-title"
       @keydown.escape.window="panel.open && closePanel()">
  <div class="ph">
    <h2 id="dz-p-title" x-text="p.action ? p.action.nazwa : 'Działanie'"></h2>
    <button type="button" class="x" x-ref="pclose" @click="closePanel()" aria-label="Zamknij panel"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
  </div>
  <div class="pb">
    <p x-show="panel.loading" class="dz-muted">Wczytywanie…</p>
    <div x-show="panel.error" class="dz-alert dz-alert--danger" role="alert" x-text="panel.error"></div>
    <template x-if="p.action">
      <div>
        <label class="dz-lbl" for="dz-p-st">Status</label>
        <select id="dz-p-st" class="dz-in" :value="p.action.status" @change="setStatus($event.target.value)">
          <template x-for="st in statusKeys" :key="st"><option :value="st" x-text="statusLabel(st)" :selected="st === p.action.status"></option></template>
        </select>
        <div class="mt-2"><template x-for="fl in flagsOf(p.action)" :key="fl.key"><span class="dz-flag" :class="fl.key" x-text="fl.label"></span></template></div>

        <h3>Najważniejsze</h3>
        <dl class="dz-kv">
          <dt>Typ</dt><dd><i class="bi" :class="typeIcon(p.action.typ)" aria-hidden="true"></i> <span x-text="typeLabel(p.action.typ)"></span></dd>
          <dt>Termin</dt><dd x-text="period(p.action)"></dd>
          <dt>Koordynator</dt><dd x-text="p.action.koordynator || '—'"></dd>
          <dt>Forma</dt><dd><span x-text="p.forma || '—'"></span> <a x-show="p.link" :href="p.link" target="_blank" rel="noopener">link</a></dd>
          <dt>Miejsce</dt><dd x-text="p.miejsce || '—'"></dd>
          <dt>Cel §6</dt><dd><span x-text="p.cel || '—'"></span> <span class="dz-chip" x-show="p.jrwa" x-text="'JRWA ' + p.jrwa"></span></dd>
          <dt>Finansowanie</dt>
          <dd>
            <template x-for="g in p.action.grants" :key="g.id"><span class="dz-chip" x-text="g.nazwa + (g.kwota ? ' · ' + money(g.kwota) : '')"></span></template>
            <span x-show="!p.action.grants.length" x-text="p.action.wlasne ? 'środki własne' : 'brak'"></span>
          </dd>
        </dl>

        <template x-if="p.opis">
          <div><h3>Opis</h3><p class="dz-prose" style="max-height:9rem;overflow:auto" x-text="p.opis"></p></div>
        </template>

        <h3>Wskaźniki — wykonanie</h3>
        <p x-show="!p.indicators.length" class="dz-muted small">Brak wskaźników. Dodasz je w edycji działania.</p>
        <form x-show="p.indicators.length" @submit.prevent="saveIndicators()">
          <template x-for="i in p.indicators" :key="i.id">
            <div class="dz-ind">
              <label :for="'dz-ind-' + i.id"><span x-text="i.nazwa"></span><small x-text="i.plan !== null ? 'plan: ' + num(i.plan) : 'bez planu'"></small></label>
              <input :id="'dz-ind-' + i.id" type="number" step="any" min="0" class="dz-in" x-model="i.real">
            </div>
          </template>
          <button type="submit" class="dz-btn dz-btn--sm mt-2" :disabled="panel.saving"><i class="bi bi-check-lg" aria-hidden="true"></i>Zapisz wykonanie</button>
        </form>

        <?php $jTag = 'h3'; $jClass = ''; include __DIR__ . '/_journal.php'; ?>
      </div>
    </template>
  </div>
  <div class="pf" x-show="p.action">
    <a class="dz-btn dz-btn--sm" :href="p.action ? url(p.action.id) : '#'"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>Karta</a>
    <a class="dz-btn dz-btn--ghost dz-btn--sm" :href="p.action ? url(p.action.id, 'edycja') : '#'"><i class="bi bi-pencil" aria-hidden="true"></i>Edytuj</a>
    <form method="post" :action="p.action ? url(p.action.id, 'duplikuj') : '#'" class="d-inline">
      <input type="hidden" name="_csrf" :value="csrf">
      <button type="submit" class="dz-btn dz-btn--ghost dz-btn--sm"><i class="bi bi-copy" aria-hidden="true"></i>Duplikuj</button>
    </form>
  </div>
</aside>
