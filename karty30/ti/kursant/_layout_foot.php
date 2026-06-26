<?php /** Wspólna stopka panelu kursanta/rodzica. */ ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Picker koloru tla — przycisk w navbarze otwiera ukryty input[type=color].
(function(){
  function applyBg(color){
    document.documentElement.style.setProperty('--kp-custom-bg', color || '');
    // sync oba pickery
    var p1 = document.getElementById('kp-bg-color-input');
    var p2 = document.getElementById('kp-a11y-bg-picker');
    if (p1 && color) p1.value = color;
    if (p2 && color) p2.value = color;
    try { color ? localStorage.setItem('kp-bg-color', color) : localStorage.removeItem('kp-bg-color'); } catch(e){}
  }
  // Inicjalizacja — odczyt z localStorage (moze byc null)
  var saved = null; try { saved = localStorage.getItem('kp-bg-color'); } catch(e){}
  if (saved) applyBg(saved);
  else {
    var p2 = document.getElementById('kp-a11y-bg-picker');
    if (p2) p2.value = '#ffffff';
  }

  // Przycisk palette w navbarze/fixed — otwiera ukryty input
  document.addEventListener('click', function(e){
    var btn = e.target.closest('#kp-bg-pick-btn');
    if (!btn) return;
    var inp = document.getElementById('kp-bg-color-input');
    if (inp) inp.click();
  });
  // Ukryty input (navbar/fixed)
  document.addEventListener('input', function(e){
    if (e.target.id === 'kp-bg-color-input') applyBg(e.target.value);
  });
  // Picker w panelu a11y
  document.addEventListener('input', function(e){
    if (e.target.id === 'kp-a11y-bg-picker') applyBg(e.target.value);
  });
  // Reset
  document.addEventListener('click', function(e){
    if (e.target.closest('#kp-bg-reset-btn')) applyBg(null);
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
</body>
</html>
