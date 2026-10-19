<?php
/**
 * modules/strategy_actions/view/_journalScript.php — dzJournalMixin(): stan i akcje sekcji etapów i historii
 * (_journal.php). Komponent, który go włącza, definiuje jid() (id działania), opcjonalnie replace() i say().
 */
?>
<script>
function dzJournalMixin(cfg) {
  return {
    j: {milestones: [], log: []},
    msNew: {title: '', due: '', owner: ''},
    comment: '',
    jBusy: false,
    jUsers: cfg.users || [],
    async jSend(sub, data) {
      const fd = new FormData();
      fd.append('_csrf', cfg.csrf);
      Object.entries(data || {}).forEach(([k, v]) => fd.append(k, v));
      this.jBusy = true;
      let res;
      try {
        const r = await fetch(cfg.base + '/' + this.jid() + '/' + sub, {method: 'POST', body: fd, credentials: 'same-origin', headers: {'Accept': 'application/json'}});
        const ct = r.headers.get('content-type') || '';
        res = ct.includes('json') ? await r.json() : {ok: false, error: (await r.text()).slice(0, 200) || ('Błąd ' + r.status)};
      } catch (e) { res = {ok: false, error: 'Brak połączenia z serwerem.'}; }
      this.jBusy = false;
      if (res.ok && res.journal) this.j = res.journal;
      if (res.ok && res.action && this.replace) {
        this.replace(res.action);
        if (this.p && this.p.action && this.p.action.id === res.action.id) this.p.action = res.action;
      }
      if (this.say) this.say(res.message || res.error || '');
      return res;
    },
    async msAdd() {
      if (!this.msNew.title.trim()) { if (this.say) this.say('Podaj nazwę etapu.'); return; }
      const r = await this.jSend('etapy', {title: this.msNew.title, due: this.msNew.due, owner: this.msNew.owner});
      if (r.ok) this.msNew = {title: '', due: '', owner: ''};
    },
    msToggle(m) { return this.jSend('etapy/' + m.id + '/przelacz', {}); },
    msDelete(m) { if (confirm('Usunąć etap „' + m.title + '”?')) return this.jSend('etapy/' + m.id + '/usun', {}); },
    async commentAdd() {
      if (!this.comment.trim() || this.jBusy) return;
      const r = await this.jSend('komentarz', {text: this.comment});
      if (r.ok) this.comment = '';
    },
    msLate(m) { return !m.done && !!m.due && m.due < cfg.today; },
    msSummary() {
      const t = this.j.milestones.length, d = this.j.milestones.filter(m => m.done).length;
      return t ? d + ' z ' + t + ' zakończonych' : '';
    },
    jDate(d) { return d ? d.slice(8, 10) + '.' + d.slice(5, 7) + '.' + d.slice(0, 4) : ''; },
    jDateTime(s) { return s ? this.jDate(s.slice(0, 10)) + ' ' + s.slice(11, 16) : ''; },
  };
}
</script>
