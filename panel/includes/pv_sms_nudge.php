<?php
/**
 * panel/includes/pv_sms_nudge.php — Baner „Zachęta do logowania SMS" jako wyspa React.
 *
 * Loader: pv_react_boot.php. React wzbogaca przycisk „Nie pokazuj ponownie":
 * optymistyczne zamknięcie (slide-out) + fetch POST do `/panel/index.php?_ajax=1`
 * (handler `_dismiss_sms_nudge` zwraca JSON gdy `_ajax`/`X-Requested-With`),
 * z komunikatem dla czytników ekranu. Bez przeładowania strony.
 *
 * Progressive enhancement: pełny fallback serwerowy = baner z formularzem POST
 * (pełne przeładowanie). Działa bez JS / przy awarii CDN.
 *
 * Wymaga w zasięgu: $_nudge_has_phone (bool), csrf_token()/csrf_field(), APP_URL, h().
 */
$_nudge_has_phone = $_nudge_has_phone ?? false;
$_sms_csrf = function_exists('csrf_token') ? csrf_token() : '';
?>
<style>
.pvp-sms-nudge{
  border-radius:12px;
  background:linear-gradient(135deg,#065f46 0%,#059669 100%);
  overflow:hidden;
  box-shadow:0 4px 16px rgba(5,150,105,.25);
  animation:pvDirSlide .35s ease;
}
@keyframes pvDirSlide{from{opacity:0;transform:translateY(-10px)}to{opacity:1;transform:none}}
@media(prefers-reduced-motion:reduce){.pvp-sms-nudge{animation:none}}
.pvp-sms-nudge.pvp-sms-leaving{opacity:0;transform:translateY(-10px);transition:opacity .25s ease,transform .25s ease}
@media(prefers-reduced-motion:reduce){.pvp-sms-nudge.pvp-sms-leaving{transition:none}}
.pvp-sms-nudge-inner  {display:flex;align-items:center;gap:1rem;padding:1rem 1.25rem;flex-wrap:wrap}
.pvp-sms-nudge-icon   {width:44px;height:44px;border-radius:10px;background:rgba(255,255,255,.18);display:flex;align-items:center;justify-content:center;font-size:1.35rem;color:#fff;flex-shrink:0}
.pvp-sms-nudge-content{flex:1;min-width:180px}
.pvp-sms-nudge-title  {font-weight:800;font-size:.94rem;color:#fff;margin-bottom:.15rem}
.pvp-sms-nudge-sub    {font-size:.8rem;color:rgba(255,255,255,.82);line-height:1.45}
.pvp-sms-nudge-actions{display:flex;align-items:center;gap:.5rem;flex-shrink:0}
.pvp-sms-nudge-btn    {display:inline-flex;align-items:center;gap:.35rem;padding:.45rem 1rem;border-radius:7px;background:rgba(255,255,255,.95);color:#059669;font-size:.83rem;font-weight:700;text-decoration:none;white-space:nowrap;transition:background .15s;border:2px solid transparent}
.pvp-sms-nudge-btn:hover,.pvp-sms-nudge-btn:focus-visible{background:#fff;color:#059669;outline-offset:2px}
.pvp-sms-nudge-dismiss{background:rgba(255,255,255,.15);border:1.5px solid rgba(255,255,255,.3);border-radius:7px;color:rgba(255,255,255,.85);padding:.4rem .5rem;cursor:pointer;font-size:.9rem;line-height:1;transition:background .12s}
.pvp-sms-nudge-dismiss:hover,.pvp-sms-nudge-dismiss:focus-visible{background:rgba(255,255,255,.28);color:#fff;outline-offset:2px}
@media(max-width:500px){.pvp-sms-nudge-inner{gap:.75rem}.pvp-sms-nudge-btn{font-size:.8rem;padding:.4rem .8rem}}
</style>

<div id="pvSmsNudge"
     data-base="<?= h(rtrim(APP_URL, '/')) ?>"
     data-csrf="<?= h($_sms_csrf) ?>"
     data-has-phone="<?= $_nudge_has_phone ? '1' : '0' ?>">
  <!-- ── Fallback serwerowy ───────────────────────────────────────────────── -->
  <div class="pvp-sms-nudge mb-3" role="complementary" aria-label="Zachęta do logowania SMS">
    <div class="pvp-sms-nudge-inner">
      <div class="pvp-sms-nudge-icon" aria-hidden="true">
        <i class="bi bi-<?= $_nudge_has_phone ? 'phone-fill' : 'phone' ?>"></i>
      </div>
      <div class="pvp-sms-nudge-content">
        <?php if ($_nudge_has_phone): ?>
        <div class="pvp-sms-nudge-title">Zaloguj się szybciej kodem SMS 📱</div>
        <div class="pvp-sms-nudge-sub">
          Zamiast hasła wpisz numer telefonu i zaloguj się jednorazowym kodem SMS — szybciej i bez zapamiętywania haseł.
        </div>
        <?php else: ?>
        <div class="pvp-sms-nudge-title">Uprość logowanie — dodaj numer telefonu 📱</div>
        <div class="pvp-sms-nudge-sub">
          Podaj swój numer w profilu, a będziesz mógł logować się kodem SMS zamiast hasłem.
        </div>
        <?php endif; ?>
      </div>
      <div class="pvp-sms-nudge-actions">
        <?php if ($_nudge_has_phone): ?>
        <a href="<?= APP_URL ?>/auth/login.php?tab=sms"
           class="pvp-sms-nudge-btn"
           aria-label="Wypróbuj logowanie kodem SMS przy następnym logowaniu">
          <i class="bi bi-phone me-1" aria-hidden="true"></i>Spróbuj
        </a>
        <?php else: ?>
        <a href="<?= APP_URL ?>/directory/profile_edit.php"
           class="pvp-sms-nudge-btn"
           aria-label="Dodaj numer telefonu w profilu">
          <i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Dodaj numer
        </a>
        <?php endif; ?>
        <form method="post" style="display:inline;margin:0">
          <?= csrf_field() ?>
          <input type="hidden" name="_dismiss_sms_nudge" value="1">
          <button type="submit"
                  class="pvp-sms-nudge-dismiss"
                  aria-label="Nie pokazuj ponownie">
            <i class="bi bi-x-lg" aria-hidden="true"></i>
          </button>
        </form>
      </div>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/pv_react_boot.php'; ?>
<script>
window.pvReact(function (React, ReactDOM, html) {
  var mount = document.getElementById('pvSmsNudge');
  if (!mount) return;

  var BASE = mount.getAttribute('data-base') || '';
  var CSRF = mount.getAttribute('data-csrf') || '';
  var HAS_PHONE = mount.getAttribute('data-has-phone') === '1';

  var useState = React.useState;

  function Nudge(){
    var ds = useState(false), dismissed = ds[0], setDismissed = ds[1]; // ukryty z DOM
    var ls = useState(false), leaving = ls[0], setLeaving = ls[1];     // klasa animacji
    var sr = useState(''),    srMsg = sr[0], setSr = sr[1];

    if (dismissed) {
      // Po zamknięciu zostaje tylko komunikat dla czytników ekranu.
      return html`<div aria-live="polite" aria-atomic="true" class="visually-hidden">${srMsg}</div>`;
    }

    function dismiss(){
      setLeaving(true);
      // Fetch w tle — optymistycznie chowamy niezależnie od wyniku.
      var body = new URLSearchParams();
      body.set('_dismiss_sms_nudge', '1');
      body.set('_ajax', '1');
      if (CSRF) body.set('_csrf', CSRF);
      fetch(BASE + '/panel/index.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'},
        body: body.toString()
      }).catch(function(){ /* nawet przy błędzie zostaje schowany w tej sesji */ });
      setTimeout(function(){ setSr('Zachęta do logowania SMS zamknięta.'); setDismissed(true); }, 260);
    }

    var btn = HAS_PHONE
      ? html`<a href=${BASE + '/auth/login.php?tab=sms'} class="pvp-sms-nudge-btn"
                aria-label="Wypróbuj logowanie kodem SMS przy następnym logowaniu">
                <i class="bi bi-phone me-1" aria-hidden="true"></i>Spróbuj</a>`
      : html`<a href=${BASE + '/directory/profile_edit.php'} class="pvp-sms-nudge-btn"
                aria-label="Dodaj numer telefonu w profilu">
                <i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Dodaj numer</a>`;

    return html`
      <div>
        <div class=${'pvp-sms-nudge mb-3' + (leaving ? ' pvp-sms-leaving' : '')}
             role="complementary" aria-label="Zachęta do logowania SMS">
          <div class="pvp-sms-nudge-inner">
            <div class="pvp-sms-nudge-icon" aria-hidden="true">
              <i class=${'bi bi-' + (HAS_PHONE ? 'phone-fill' : 'phone')}></i>
            </div>
            <div class="pvp-sms-nudge-content">
              ${HAS_PHONE
                ? html`<div class="pvp-sms-nudge-title">Zaloguj się szybciej kodem SMS 📱</div>
                       <div class="pvp-sms-nudge-sub">Zamiast hasła wpisz numer telefonu i zaloguj się jednorazowym kodem SMS — szybciej i bez zapamiętywania haseł.</div>`
                : html`<div class="pvp-sms-nudge-title">Uprość logowanie — dodaj numer telefonu 📱</div>
                       <div class="pvp-sms-nudge-sub">Podaj swój numer w profilu, a będziesz mógł logować się kodem SMS zamiast hasłem.</div>`}
            </div>
            <div class="pvp-sms-nudge-actions">
              ${btn}
              <button type="button" class="pvp-sms-nudge-dismiss" disabled=${leaving}
                      onClick=${dismiss} aria-label="Nie pokazuj ponownie">
                <i class="bi bi-x-lg" aria-hidden="true"></i>
              </button>
            </div>
          </div>
        </div>
        <div aria-live="polite" aria-atomic="true" class="visually-hidden">${srMsg}</div>
      </div>`;
  }

  try { ReactDOM.createRoot(mount).render(html`<${Nudge} />`); }
  catch (e) { /* fallback serwerowy zostaje */ }
});
</script>
