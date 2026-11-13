// Wspólny generator stopki (strona admina i publiczny /pomocne/generatorstopki).
// Odpowiednik PHP: stopkaRenderHtml() w logic/stopka.php — zmieniając jedno, zmień drugie (cli/stopkaParity.php).
function stopkaGen(d) {
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
  return {
    me: d.me, org: d.org, design: d.design, defaults: d.defaults, fonts: d.fonts, dark: false, copied: false, codeCopied: false, fail: false,
    shows: [['title','Stanowisko'],['phone','Telefon'],['email','E-mail'],['org','Nazwa organizacji'],['address','Adres'],['nip','NIP'],['krs','KRS'],['website','Strona WWW'],['social','Linki społecznościowe'],['promo','Treść promocyjna']],
    // Odpowiednik PHP: stopkaRenderHtml() w logic/stopka.php — zmieniając jedno, zmień drugie.
    html() {
      const m = this.me, o = this.org, d = this.design, sh = d.show, sm = d.size - 1;
      const row = (t, st) => t ? '<div style="' + st + '">' + t + '</div>' : '';
      const link = (href, label) => '<a href="' + href + '" style="color:' + d.accent + ';text-decoration:none;">' + label + '</a>';
      let h = '<div style="font-family:' + d.font + ',Arial,Helvetica,sans-serif;color:' + d.color + ';font-size:' + d.size + 'px;line-height:1.5;">';
      if (/^https:\/\/[^\s"'<>]+$/i.test(d.logoUrl || '')) {
        h += '<div style="margin-bottom:' + d.gap + 'px;"><img src="' + esc(d.logoUrl) + '" width="' + d.logoWidth + '" alt="' + esc(o.org_name) + '" style="display:block;border:0;"></div>';
      }
      h += row(esc((m.first_name + ' ' + m.last_name).trim()), 'font-size:' + d.nameSize + 'px;font-weight:bold;line-height:1.4;color:' + (d.nameAccent ? d.accent : d.color) + ';');
      if (sh.title) h += row(esc(m.job_title), 'font-size:' + d.size + 'px;');
      const parts = [];
      if (sh.phone && m.phone) parts.push('tel. ' + esc(m.phone));
      if (sh.email && m.email) parts.push(link('mailto:' + esc(m.email), esc(m.email)));
      h += row(parts.join(' &nbsp;|&nbsp; '), 'font-size:' + d.size + 'px;');
      if (sh.org) h += row(esc(o.org_name), 'margin-top:' + d.gap + 'px;font-size:' + d.size + 'px;font-weight:bold;');
      if (sh.address) h += row(esc(o.address), 'font-size:' + sm + 'px;');
      if (sh.nip && (o.nip || '').trim()) h += row('NIP: ' + esc(o.nip), 'font-size:' + sm + 'px;');
      if (sh.krs && (o.krs || '').trim()) h += row('KRS: ' + esc(o.krs), 'font-size:' + sm + 'px;');
      const url = (o.website || '').trim();
      if (sh.website && url) h += row(link(esc(/^https?:\/\//i.test(url) ? url : 'https://' + url), esc(url)), 'font-size:' + sm + 'px;');
      if (sh.social) {
        const links = [['facebook','Facebook'],['instagram','Instagram'],['linkedin','LinkedIn']]
          .filter(([k]) => /^https:\/\/[^\s"'<>]+$/i.test((d.social || {})[k] || ''))
          .map(([k, label]) => link(esc(d.social[k]), label));
        h += row(links.join(' &nbsp;|&nbsp; '), 'margin-top:4px;font-size:' + sm + 'px;');
      }
      const promo = (o.promo_html || '').trim();
      if (sh.promo && promo) {
        const bd = d.sepStyle === 'none' ? '' : 'border-top:1px ' + d.sepStyle + ' ' + d.sepColor + ';padding-top:8px;';
        h += '<div style="margin-top:' + (d.gap + 2) + 'px;' + bd + 'font-size:' + sm + 'px;line-height:1.5;">' + this.safePromo(promo) + '</div>';
      }
      return h + '</div>';
    },
    safePromo(html) {
      const doc = new DOMParser().parseFromString('<div id="r">' + html + '</div>', 'text/html');
      const ok = {A:['href'],B:[],STRONG:[],I:[],EM:[],U:[],BR:[],P:[],SPAN:[],IMG:['src','alt','width','height']};
      const walk = n => [...n.children].forEach(c => {
        if (!ok[c.tagName]) { c.remove(); return; }
        [...c.attributes].forEach(a => {
          if (!ok[c.tagName].includes(a.name) || (['href','src'].includes(a.name) && !/^(https?:|mailto:)/i.test(a.value.trim()))) c.removeAttribute(a.name);
        });
        walk(c);
      });
      walk(doc.getElementById('r'));
      return doc.getElementById('r').innerHTML;
    },
    // Thunderbird przyjmuje podpis jako KOD HTML (pole „Sygnaturka” + „Używaj HTML”), więc kopiujemy go jako zwykły tekst.
    async copyCode() {
      const code = this.html();
      this.fail = false;
      try { await navigator.clipboard.writeText(code); }
      catch (e) {
        const ta = document.createElement('textarea'); ta.value = code; ta.style.cssText = 'position:fixed;left:-9999px;';
        document.body.appendChild(ta); ta.select();
        const ok = document.execCommand('copy'); ta.remove();
        if (!ok) { this.fail = true; return; }
      }
      this.codeCopied = true; setTimeout(() => this.codeCopied = false, 2500);
    },
    // Plik .html do opcji „dołącz sygnaturkę z pliku” w Thunderbirdzie.
    download() {
      const doc = '<!DOCTYPE html><html><head><meta charset="utf-8"></head><body>' + this.html() + '</body></html>';
      const a = document.createElement('a');
      a.href = URL.createObjectURL(new Blob([doc], {type: 'text/html'}));
      a.download = 'stopka.html';
      document.body.appendChild(a); a.click(); a.remove();
      setTimeout(() => URL.revokeObjectURL(a.href), 1000);
    },
    async copy() {
      const html = this.html(), text = new DOMParser().parseFromString(html.replace(/<\/div>/g, '\n'), 'text/html').body.textContent.trim();
      this.fail = false;
      try {
        await navigator.clipboard.write([new ClipboardItem({
          'text/html': new Blob([html], {type: 'text/html'}), 'text/plain': new Blob([text], {type: 'text/plain'})})]);
      } catch (e) {
        try {
          const box = document.createElement('div'); box.innerHTML = html; box.style.cssText = 'position:fixed;left:-9999px;';
          document.body.appendChild(box);
          const r = document.createRange(); r.selectNodeContents(box);
          const s = getSelection(); s.removeAllRanges(); s.addRange(r);
          const ok = document.execCommand('copy'); s.removeAllRanges(); box.remove();
          if (!ok) throw e;
        } catch (e2) { this.fail = true; return; }
      }
      this.copied = true; setTimeout(() => this.copied = false, 2500);
    }
  };
}
