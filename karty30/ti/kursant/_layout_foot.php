<?php /** Wspólna stopka panelu kursanta/rodzica. */ ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
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
<?php if (empty($KP_SKIP_TAB_MEMORY)): ?>
<script>
// Powrót do ostatniej zakładki (poza 'dane') po wejściu na stronę bez ?tab=
(function(){
  var TAB_KEY = 'kp_last_tab';
  var SKIP = ['dane'];
  // Odczytaj aktualny tab z URL
  var urlTab = (new URLSearchParams(window.location.search)).get('tab') || 'dane';
  if (urlTab === 'dane' || urlTab === '') {
    // Sprawdź zapisaną zakładkę
    try {
      var saved = localStorage.getItem(TAB_KEY);
      if (saved && SKIP.indexOf(saved) === -1) {
        location.replace('?tab=' + encodeURIComponent(saved));
      }
    } catch(e) {}
  }
  // Zapisuj przy kliknięciu linków zakładek
  document.addEventListener('click', function(e) {
    var a = e.target.closest('a[href*="?tab="]');
    if (!a) return;
    var m = a.getAttribute('href').match(/[?&]tab=([^&]+)/);
    if (m && SKIP.indexOf(decodeURIComponent(m[1])) === -1) {
      try { localStorage.setItem(TAB_KEY, decodeURIComponent(m[1])); } catch(e) {}
    }
  });
})();
</script>
<?php endif; ?>
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
<script>
// ── Web Push — rejestracja Service Workera i obsługa przycisku ───────────────
(function(){
  if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
    // Przeglądarka nie obsługuje push — ukryj przycisk, pokaż info
    document.querySelectorAll('.kp-push-unsupported').forEach(function(el){ el.style.display = ''; });
    return;
  }
  navigator.serviceWorker.register('push_sw.js').then(function(reg) {
    window._kp_sw_reg = reg;
    return reg.pushManager.getSubscription();
  }).then(function(sub) {
    if (sub) {
      document.querySelectorAll('.kp-push-enabled').forEach(function(el){ el.style.display = ''; });
      document.querySelectorAll('.kp-push-enable-btn').forEach(function(btn){ btn.style.display = 'none'; });
    } else {
      document.querySelectorAll('.kp-push-enable-btn').forEach(function(btn){ btn.style.display = ''; });
    }
  }).catch(function(){
    document.querySelectorAll('.kp-push-enable-btn').forEach(function(btn){ btn.style.display = ''; });
  });
})();

function kpEnablePush() {
  if (!window._kp_sw_reg) { alert('Service Worker nie jest jeszcze załadowany. Odśwież stronę.'); return; }
  var vapidKey = document.body.getAttribute('data-vapid-key') || '';
  if (!vapidKey) { alert('Klucz VAPID nie jest skonfigurowany — skontaktuj się z administratorem.'); return; }
  window._kp_sw_reg.pushManager.subscribe({
    userVisibleOnly: true,
    applicationServerKey: urlBase64ToUint8Array(vapidKey)
  }).then(function(sub) {
    return fetch('push_subscribe.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({action: 'subscribe', subscription: sub.toJSON()})
    });
  }).then(function(r){ return r.json(); })
  .then(function(data) {
    if (data.ok) {
      document.querySelectorAll('.kp-push-enable-btn').forEach(function(b){ b.style.display = 'none'; });
      document.querySelectorAll('.kp-push-enabled').forEach(function(e){ e.style.display = ''; });
    } else {
      alert('Nie udało się włączyć powiadomień: ' + (data.error || 'nieznany błąd'));
    }
  }).catch(function(e){
    console.warn('Push subscribe failed', e);
    alert('Nie udało się włączyć powiadomień. Sprawdź, czy Twoja przeglądarka ma zezwolenie na powiadomienia dla tej strony.');
  });
}

function urlBase64ToUint8Array(base64String) {
  var padding = '='.repeat((4 - base64String.length % 4) % 4);
  var base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
  var rawData = window.atob(base64);
  var outputArray = new Uint8Array(rawData.length);
  for (var i = 0; i < rawData.length; ++i) outputArray[i] = rawData.charCodeAt(i);
  return outputArray;
}
</script>
</body>
</html>
