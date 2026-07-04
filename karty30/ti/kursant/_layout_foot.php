<?php /** Wspólna stopka panelu kursanta/rodzica. */ ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Schematy kolorow — przyciski swatch w panelu dostepnosci + przycisk palety w navbarze.
(function(){
  var SCHEMES = ['classic','mint','violet','warm','slate','metro'];

  function applyScheme(s){
    if (!s || !SCHEMES.includes(s)) s = 'classic';
    document.documentElement.setAttribute('data-kp-scheme', s);
    try { localStorage.setItem('kp-scheme', s); } catch(e){}
    // Zaktualizuj obramowanie aktywnego swatch
    document.querySelectorAll('.kp-scheme-btn').forEach(function(b){
      b.style.outline = b.dataset.scheme === s ? '3px solid #000' : 'none';
      b.style.outlineOffset = '2px';
      b.setAttribute('aria-pressed', b.dataset.scheme === s ? 'true' : 'false');
    });
  }

  // Inicjalizacja
  var saved = null; try { saved = localStorage.getItem('kp-scheme'); } catch(e){}
  applyScheme(saved || 'classic');

  // Klik swatch w panelu a11y
  document.addEventListener('click', function(e){
    var btn = e.target.closest('.kp-scheme-btn');
    if (btn) applyScheme(btn.dataset.scheme);
  });

  // Klik przycisku palety w navbarze/fixed — otwiera panel a11y
  document.addEventListener('click', function(e){
    var btn = e.target.closest('#kp-bg-pick-btn');
    if (!btn) return;
    var panel = document.getElementById('kp-a11y-panel');
    var toggle = document.getElementById('kp-a11y-toggle');
    if (panel && panel.hidden) {
      panel.hidden = false;
      if (toggle) toggle.setAttribute('aria-expanded','true');
      var first = panel.querySelector('.kp-scheme-btn');
      if (first) first.focus();
    }
  });
})();
</script>
<script>
// Menu dostępności: kontrast, wielkość tekstu, schowaj/pokaż menu (zapamiętywane).
(function(){
  var d = document.documentElement;
  var toggle = document.getElementById('kp-a11y-toggle');
  var panel  = document.getElementById('kp-a11y-panel');
  if (!toggle || !panel) return;
  var closeB = document.getElementById('kp-a11y-close');
  var status = document.getElementById('kp-a11y-status');
  var store  = function(k,v){ try{ v===null?localStorage.removeItem(k):localStorage.setItem(k,v); }catch(e){} };
  var say    = function(m){ if(status) status.textContent = m; };

  function open(){ panel.hidden=false; toggle.setAttribute('aria-expanded','true'); var f=panel.querySelector('button,[href],input'); if(f)f.focus(); }
  function close(focusBtn){ panel.hidden=true; toggle.setAttribute('aria-expanded','false'); if(focusBtn)toggle.focus(); }
  toggle.addEventListener('click', function(){ panel.hidden ? open() : close(false); });
  if (closeB) closeB.addEventListener('click', function(){ close(true); });
  document.addEventListener('keydown', function(e){ if(e.key==='Escape' && !panel.hidden) close(true); });

  // Wielkość tekstu (0–3)
  var FONT_MAX = 3;
  function font(){ return parseInt(localStorage.getItem('kp-fontscale')||'0',10) || 0; }
  function setFont(n){
    n = Math.max(0, Math.min(FONT_MAX, n));
    if (n===0){ d.removeAttribute('data-kp-font'); store('kp-fontscale',null); }
    else { d.setAttribute('data-kp-font', String(n)); store('kp-fontscale', String(n)); }
    say('Wielkość tekstu: ' + (n===0?'domyślna':'poziom '+n+' z '+FONT_MAX));
  }
  document.getElementById('kp-font-inc').addEventListener('click', function(){ setFont(font()+1); });
  document.getElementById('kp-font-dec').addEventListener('click', function(){ setFont(font()-1); });
  document.getElementById('kp-font-reset').addEventListener('click', function(){ setFont(0); });

  // Wysoki kontrast
  var cb = document.getElementById('kp-contrast-btn');
  function syncContrast(){ cb.setAttribute('aria-pressed', d.classList.contains('kp-contrast')?'true':'false'); }
  syncContrast();
  cb.addEventListener('click', function(){
    var on = !d.classList.contains('kp-contrast');
    d.classList.toggle('kp-contrast', on); store('kp-contrast', on?'1':null); syncContrast();
    say(on?'Wysoki kontrast włączony':'Wysoki kontrast wyłączony');
  });

  // Schowaj / pokaż menu sekcji
  var hb = document.getElementById('kp-hidemenu-btn');
  var hl = document.getElementById('kp-hidemenu-label');
  var hasMenu = !!document.querySelector('nav[aria-label="Sekcje panelu"]');
  if (!hasMenu) { hb.hidden = true; }   // ukryj opcję tam, gdzie nie ma menu (np. logowanie, test)
  function syncHide(){
    var on = d.classList.contains('kp-hidemenu');
    hb.setAttribute('aria-pressed', on?'true':'false');
    hl.textContent = on ? 'Pokaż menu' : 'Schowaj menu';
  }
  syncHide();
  hb.addEventListener('click', function(){
    var on = !d.classList.contains('kp-hidemenu');
    d.classList.toggle('kp-hidemenu', on); store('kp-hidemenu', on?'1':null); syncHide();
    say(on?'Menu schowane':'Menu widoczne');
  });
})();
</script>
<script>
// Baner „zmieniliśmy wygląd" (motyw Metro) — odrzucenie zapamiętane na stałe.
(function(){
  var btn = document.getElementById('kp-metro-notice-close');
  if (!btn) return;
  btn.addEventListener('click', function(){
    document.documentElement.classList.add('kp-metro-notice-dismissed');
    try { localStorage.setItem('kp-metro-notice-dismissed', '1'); } catch(e){}
  });
})();
</script>
<script>
// Miernik siły hasła — czysto informacyjny (nie blokuje wysyłki), wołany z oninput na polu "nowe hasło".
// prefix identyfikuje parę elementów: #<prefix>-bar (pasek) i #<prefix>-text (opis dla czytnika ekranu).
function kpPwMeter(input, prefix) {
  var bar  = document.getElementById(prefix + '-bar');
  var text = document.getElementById(prefix + '-text');
  if (!bar || !text) return;
  var pw = input.value;
  if (!pw) { bar.style.width = '0%'; bar.className = 'progress-bar'; text.textContent = ''; return; }
  var score = 0;
  if (pw.length >= 8)  score++;
  if (pw.length >= 12) score++;
  if (/[a-z]/.test(pw) && /[A-Z]/.test(pw)) score++;
  if (/[0-9]/.test(pw)) score++;
  if (/[^A-Za-z0-9]/.test(pw)) score++;
  score = Math.min(score, 4);
  var levels = [
    { pct: 20,  cls: 'bg-danger',  label: 'Bardzo słabe' },
    { pct: 40,  cls: 'bg-danger',  label: 'Słabe' },
    { pct: 60,  cls: 'bg-warning', label: 'Średnie' },
    { pct: 80,  cls: 'bg-info',    label: 'Dobre' },
    { pct: 100, cls: 'bg-success', label: 'Bardzo dobre' }
  ][score];
  bar.style.width = levels.pct + '%';
  bar.className = 'progress-bar ' + levels.cls;
  text.textContent = 'Siła hasła: ' + levels.label;
}
</script>
</body>
</html>
