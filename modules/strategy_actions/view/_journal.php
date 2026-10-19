<?php
/**
 * modules/strategy_actions/view/_journal.php — etapy (kamienie milowe) i historia działania.
 * Wspólne dla panelu listy i karty działania; komponent nadrzędny łączy dzJournalMixin() (_journalScript.php)
 * i dostarcza jid(). $jTag/$jClass — znacznik i klasa nagłówków sekcji (h3 w panelu, h2.dz-head na karcie).
 */
$jTag   = $jTag ?? 'h3';
$jClass = $jClass ?? '';
?>
<<?= $jTag ?> class="<?= h($jClass) ?>">Etapy <small class="dz-muted" x-text="msSummary()"></small></<?= $jTag ?>>
<ul class="dz-ms" x-show="j.milestones.length">
  <template x-for="m in j.milestones" :key="m.id">
    <li :class="{done: m.done, late: msLate(m)}">
      <label class="dz-check">
        <input type="checkbox" :checked="m.done" @change="msToggle(m)" :disabled="jBusy">
        <span class="t" x-text="m.title"></span>
      </label>
      <small class="dz-muted d-block">
        <span x-show="m.due" :class="msLate(m) ? 'late-tx' : ''" x-text="'termin ' + jDate(m.due) + (msLate(m) ? ' — po terminie' : '')"></span>
        <span x-show="m.owner" x-text="(m.due ? ' · ' : '') + m.owner"></span>
        <span x-show="m.done" x-text="' · zakończono ' + jDateTime(m.doneAt) + (m.doneBy ? ' (' + m.doneBy + ')' : '')"></span>
      </small>
      <button type="button" class="del" @click="msDelete(m)" :aria-label="'Usuń etap: ' + m.title" :disabled="jBusy"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
    </li>
  </template>
</ul>
<p class="dz-muted small" x-show="!j.milestones.length">Brak etapów. Rozpisz działanie na kroki z terminami i osobami odpowiedzialnymi.</p>
<form class="dz-ms-add" @submit.prevent="msAdd()">
  <div class="full">
    <label class="dz-lbl" :for="'ms-t-' + jid()">Nowy etap</label>
    <input :id="'ms-t-' + jid()" class="dz-in" x-model="msNew.title" maxlength="200" placeholder="np. rezerwacja sali, zaproszenia, rozliczenie">
  </div>
  <div>
    <label class="dz-lbl" :for="'ms-d-' + jid()">Termin</label>
    <input :id="'ms-d-' + jid()" type="date" class="dz-in" x-model="msNew.due">
  </div>
  <div>
    <label class="dz-lbl" :for="'ms-o-' + jid()">Odpowiada</label>
    <select :id="'ms-o-' + jid()" class="dz-in" x-model="msNew.owner">
      <option value="">— nikt —</option>
      <template x-for="u in jUsers" :key="u.id"><option :value="u.id" x-text="u.name"></option></template>
    </select>
  </div>
  <div class="full"><button type="submit" class="dz-btn dz-btn--sm" :disabled="jBusy"><i class="bi bi-flag" aria-hidden="true"></i>Dodaj etap</button></div>
</form>

<<?= $jTag ?> class="<?= h($jClass) ?>">Historia i komentarze</<?= $jTag ?>>
<form @submit.prevent="commentAdd()" class="mb-2">
  <label class="dz-lbl" :for="'cm-' + jid()">Komentarz dla zespołu</label>
  <textarea :id="'cm-' + jid()" class="dz-in" rows="2" x-model="comment" maxlength="2000" @keydown.ctrl.enter="commentAdd()" @keydown.meta.enter="commentAdd()"></textarea>
  <button type="submit" class="dz-btn dz-btn--ghost dz-btn--sm mt-1" :disabled="jBusy || !comment.trim()"><i class="bi bi-chat-left-text" aria-hidden="true"></i>Dodaj komentarz</button>
</form>
<ol class="dz-log" x-show="j.log.length">
  <template x-for="e in j.log" :key="e.id">
    <li :class="e.kind === 'comment' ? 'cm' : ''">
      <i class="bi" :class="e.icon" aria-hidden="true"></i>
      <div><span class="msg" x-text="e.message"></span>
        <small class="dz-muted d-block" x-text="jDateTime(e.at) + (e.user ? ' · ' + e.user : '')"></small></div>
    </li>
  </template>
</ol>
<p class="dz-muted small" x-show="!j.log.length">Historia jest pusta — zmiany zapisane od teraz pojawią się tutaj.</p>
