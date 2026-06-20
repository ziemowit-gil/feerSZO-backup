<?php
/**
 * panel/includes/pv_canva_card.php — Karta „Canva Pro" jako wyspa React.
 *
 * Loader: pv_react_boot.php. Trzy stany: zaproszony / prośba w trakcie / formularz.
 * React wzbogaca tylko stan formularza: optymistyczne przejście do „w trakcie"
 * po wysłaniu prośby przez fetch (?_ajax=1 → JSON), z rollbackiem przy błędzie
 * i komunikatem dla czytników ekranu (aria-live).
 *
 * Progressive enhancement: pełny, dostępny fallback serwerowy = formularz POST
 * z pełnym przeładowaniem strony (działa bez JS / przy awarii CDN).
 *
 * Wymaga w zasięgu: $_canva_invited, $_canva_requested, $_canva_contract_id (int),
 *   csrf_token() (opcjonalnie), APP_URL, h().
 */
$_canva_invited   = $_canva_invited   ?? false;
$_canva_requested = $_canva_requested ?? false;
$_canva_cid       = (int)($_canva_contract_id ?? 0);

// Ręczne konto Canva (login/hasło) — wpisane przez admina lub wolontariusza.
$_canva_acc = null;
if ($_canva_cid && function_exists('db_one')) {
    try { $_canva_acc = db_one("SELECT canva_login, canva_haslo, canva_konto_zrodlo, canva_konto_at FROM umowy_wolontariat WHERE id=?", [$_canva_cid]); }
    catch (\Throwable $e) { $_canva_acc = null; }
}
$_canva_by_admin = ($_canva_acc['canva_konto_zrodlo'] ?? '') === 'admin';
$_canva_login    = trim((string)($_canva_acc['canva_login'] ?? ''));
$_canva_haslo    = (string)($_canva_acc['canva_haslo'] ?? '');

// Pokazujemy kartę tylko gdy jest co pokazać.
if (!$_canva_invited && !$_canva_requested && !$_canva_cid) return;

$_canva_csrf = function_exists('csrf_token') ? csrf_token() : '';

// URL logowania jednokrotnego (IdP-initiated SSO) — null, gdy SP Canvy
// nie jest zarejestrowany; wtedy fallback do logowania Microsoft na canva.com.
$_canva_sso_url = null;
require_once dirname(__DIR__, 2) . '/includes/canva.php';
if (function_exists('canva_sso_url')) {
    try { $_canva_sso_url = canva_sso_url(); } catch (\Throwable $e) { $_canva_sso_url = null; }
}
$_canva_login_href = $_canva_sso_url ?: 'https://www.canva.com';
$_canva_login_sub  = $_canva_sso_url
    ? 'Logowanie jednokrotne (SSO) — konto utworzy się automatycznie'
    : 'Zaloguj się przez Microsoft na canva.com';
?>
<?php if ($_canva_invited): ?>
<!-- Dostęp przyznany → przycisk logowania (stan stabilny, bez JS) -->
<a href="<?= h($_canva_login_href) ?>" target="_blank" rel="noopener"
   class="d-flex align-items-center gap-3 mb-3 px-3 py-2 rounded-3 text-decoration-none"
   style="background:linear-gradient(135deg,#7c3aed,#a855f7);color:#fff;transition:opacity .15s"
   onmouseover="this.style.opacity='.88'" onmouseout="this.style.opacity='1'">
  <span style="width:38px;height:38px;background:rgba(255,255,255,.18);border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:1.1rem" aria-hidden="true">🎨</span>
  <div style="flex:1">
    <div style="font-size:.82rem;font-weight:700;line-height:1.2">Zaloguj do Canva</div>
    <div style="font-size:.76rem;opacity:.85"><?= h($_canva_login_sub) ?></div>
  </div>
  <i class="bi bi-box-arrow-up-right" style="font-size:1.05rem;opacity:.8" aria-hidden="true"></i>
</a>

<?php if ($_canva_by_admin && $_canva_login !== ''): ?>
<!-- Dane konta wpisane przez administratora — wolontariusz je widzi -->
<div class="mb-3 px-3 py-2 rounded-3" style="background:#faf5ff;border:1.5px solid #e9d5ff;font-size:.82rem">
  <div style="font-weight:700;color:#6d28d9;margin-bottom:.35rem"><i class="bi bi-key me-1"></i>Dane logowania do Canva</div>
  <div class="d-flex align-items-center gap-2 mb-1">
    <span class="text-muted" style="min-width:54px">Login:</span>
    <code id="cvLogin" style="font-size:.82rem"><?= h($_canva_login) ?></code>
    <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1" onclick="navigator.clipboard&&navigator.clipboard.writeText(document.getElementById('cvLogin').textContent)" title="Kopiuj"><i class="bi bi-clipboard"></i></button>
  </div>
  <?php if ($_canva_haslo !== ''): ?>
  <div class="d-flex align-items-center gap-2">
    <span class="text-muted" style="min-width:54px">Hasło:</span>
    <code id="cvPass" data-p="<?= h($_canva_haslo) ?>" style="font-size:.82rem">••••••••</code>
    <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1" onclick="var c=document.getElementById('cvPass');var s=c.textContent==='••••••••';c.textContent=s?c.dataset.p:'••••••••';this.querySelector('i').className=s?'bi bi-eye-slash':'bi bi-eye'" title="Pokaż/ukryj"><i class="bi bi-eye"></i></button>
    <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-1" onclick="navigator.clipboard&&navigator.clipboard.writeText(document.getElementById('cvPass').dataset.p)" title="Kopiuj"><i class="bi bi-clipboard"></i></button>
  </div>
  <div class="text-muted mt-1" style="font-size:.72rem">Dane przekazane przez administratora. Zmień hasło po pierwszym logowaniu.</div>
  <?php endif; ?>
</div>
<?php else: ?>
<!-- Wolontariusz sam zakłada konto i podaje login (zachowane w panelu) -->
<form method="post" class="mb-3 px-3 py-2 rounded-3" style="background:#faf5ff;border:1.5px solid #e9d5ff">
  <?php if ($_canva_csrf !== ''): ?><input type="hidden" name="_csrf" value="<?= h($_canva_csrf) ?>"><?php endif; ?>
  <input type="hidden" name="_save_canva_account" value="1">
  <input type="hidden" name="canva_contract_id" value="<?= $_canva_cid ?>">
  <label style="font-size:.78rem;font-weight:700;color:#6d28d9;display:block;margin-bottom:.3rem">
    <i class="bi bi-person-badge me-1"></i>Założyłeś/aś konto Canva samodzielnie? Podaj login
  </label>
  <div class="d-flex gap-2">
    <input type="text" name="canva_login" value="<?= h($_canva_login) ?>" placeholder="e-mail / login w Canva"
           class="form-control form-control-sm" style="font-size:.82rem">
    <button type="submit" class="btn btn-sm" style="background:#7c3aed;color:#fff;white-space:nowrap"><i class="bi bi-save me-1"></i>Zapisz</button>
  </div>
  <div class="text-muted mt-1" style="font-size:.72rem">Login zobaczy administrator — ułatwi to dodanie Cię do zespołu Canva.</div>
</form>
<?php endif; ?>

<?php else: ?>
<div id="pvCanvaCard"
     data-base="<?= h(rtrim(APP_URL, '/')) ?>"
     data-csrf="<?= h($_canva_csrf) ?>"
     data-cid="<?= $_canva_cid ?>"
     data-state="<?= $_canva_requested ? 'requested' : 'form' ?>">
  <!-- ── Fallback serwerowy ───────────────────────────────────────────────── -->
  <?php if ($_canva_requested): ?>
  <!-- Prośba złożona → czeka na realizację -->
  <div class="d-flex align-items-center gap-3 mb-3 px-3 py-2 rounded-3"
       style="background:linear-gradient(135deg,#fdf4ff,#f5f3ff);border:1.5px solid #e9d5ff">
    <span style="width:38px;height:38px;background:#f3e8ff;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:1.1rem" aria-hidden="true">⏳</span>
    <div style="flex:1">
      <div style="font-size:.82rem;font-weight:700;color:#6d28d9;line-height:1.2">Prośba o Canva — w trakcie</div>
      <div style="font-size:.76rem;color:#7c3aed;opacity:.85">Administrator wkrótce wyśle zaproszenie na Twój adres e-mail.</div>
    </div>
  </div>
  <?php else: ?>
  <!-- Nie ma dostępu, nie złożono prośby → formularz -->
  <div class="mb-3 px-3 py-3 rounded-3" style="background:#fdf4ff;border:1.5px solid #e9d5ff">
    <div class="d-flex align-items-start gap-3">
      <span style="width:40px;height:40px;background:#f3e8ff;border-radius:50%;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:1.2rem;margin-top:.1rem" aria-hidden="true">🎨</span>
      <div style="flex:1">
        <div style="font-size:.88rem;font-weight:700;color:#6d28d9;margin-bottom:.2rem">
          Chcesz tworzyć materiały w Canva?
        </div>
        <div id="canva-desc" style="font-size:.8rem;color:#7c3aed;line-height:1.5;margin-bottom:.75rem">
          Organizacja korzysta z <strong>Canva Pro</strong>. Złóż prośbę, a administrator
          wyśle Ci zaproszenie do wspólnej przestrzeni z szablonami i brandingiem.
        </div>
        <form method="post" id="canvaRequestForm">
          <?php if ($_canva_csrf !== ''): ?>
          <input type="hidden" name="_csrf" value="<?= h($_canva_csrf) ?>">
          <?php endif; ?>
          <input type="hidden" name="_request_canva" value="1">
          <input type="hidden" name="canva_contract_id" value="<?= $_canva_cid ?>">
          <button type="submit"
                  class="btn"
                  style="background:#7c3aed;color:#fff;font-size:.83rem;font-weight:600;border-radius:8px"
                  aria-describedby="canva-desc">
            <i class="bi bi-send-fill me-1" aria-hidden="true"></i>Poproś o dostęp do Canva
          </button>
        </form>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php require_once __DIR__ . '/pv_react_boot.php'; ?>
<script>
window.pvReact(function (React, ReactDOM, html) {
  var mount = document.getElementById('pvCanvaCard');
  if (!mount) return;

  var BASE  = mount.getAttribute('data-base') || '';
  var CSRF  = mount.getAttribute('data-csrf') || '';
  var CID   = parseInt(mount.getAttribute('data-cid'), 10) || 0;
  var STATE0 = mount.getAttribute('data-state') || 'form';
  if (!CID) return;

  var useState = React.useState;

  function announce(setSr, msg){ setSr(''); setTimeout(function(){ setSr(msg); }, 50); }

  function Pending(){
    return html`
      <div class="d-flex align-items-center gap-3 mb-3 px-3 py-2 rounded-3"
           style=${{background:'linear-gradient(135deg,#fdf4ff,#f5f3ff)',border:'1.5px solid #e9d5ff'}}>
        <span style=${{width:'38px',height:'38px',background:'#f3e8ff',borderRadius:'50%',display:'flex',alignItems:'center',justifyContent:'center',flexShrink:0,fontSize:'1.1rem'}} aria-hidden="true">⏳</span>
        <div style=${{flex:1}}>
          <div style=${{fontSize:'.82rem',fontWeight:700,color:'#6d28d9',lineHeight:1.2}}>Prośba o Canva — w trakcie</div>
          <div style=${{fontSize:'.76rem',color:'#7c3aed',opacity:.85}}>Administrator wkrótce wyśle zaproszenie na Twój adres e-mail.</div>
        </div>
      </div>`;
  }

  function Card(){
    var ss = useState(STATE0), state = ss[0], setState = ss[1];   // 'form' | 'requested'
    var bs = useState(false),  busy = bs[0], setBusy = bs[1];
    var es = useState(''),     err  = es[0], setErr  = es[1];
    var sr = useState(''),     srMsg = sr[0], setSr  = sr[1];

    var live = html`<div aria-live="polite" aria-atomic="true" class="visually-hidden">${srMsg}</div>`;

    if (state === 'requested') {
      return html`<div>${live}<${Pending} /></div>`;
    }

    function submit(e){
      if (e && e.preventDefault) e.preventDefault();
      setBusy(true); setErr('');
      var body = new URLSearchParams();
      body.set('_request_canva', '1');
      body.set('canva_contract_id', String(CID));
      body.set('_ajax', '1');
      if (CSRF) body.set('_csrf', CSRF);
      fetch(BASE + '/panel/index.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'},
        body: body.toString()
      })
        .then(function(r){ return r.json(); })
        .then(function(r){
          if (!r || !r.ok) throw new Error((r && r.message) || 'err');
          announce(setSr, r.message || 'Prośba o dostęp do Canva została złożona.');
          setState('requested');
        })
        .catch(function(){
          setErr('Nie udało się złożyć prośby. Spróbuj ponownie lub odśwież stronę.');
          announce(setSr, 'Nie udało się złożyć prośby o Canva.');
        })
        .then(function(){ setBusy(false); });
    }

    return html`
      <div>
        ${live}
        <div class="mb-3 px-3 py-3 rounded-3" style=${{background:'#fdf4ff',border:'1.5px solid #e9d5ff'}}>
          <div class="d-flex align-items-start gap-3">
            <span style=${{width:'40px',height:'40px',background:'#f3e8ff',borderRadius:'50%',display:'flex',alignItems:'center',justifyContent:'center',flexShrink:0,fontSize:'1.2rem',marginTop:'.1rem'}} aria-hidden="true">🎨</span>
            <div style=${{flex:1}}>
              <div style=${{fontSize:'.88rem',fontWeight:700,color:'#6d28d9',marginBottom:'.2rem'}}>
                Chcesz tworzyć materiały w Canva?
              </div>
              <div id="canva-desc" style=${{fontSize:'.8rem',color:'#7c3aed',lineHeight:1.5,marginBottom:'.75rem'}}>
                Organizacja korzysta z <strong>Canva Pro</strong>. Złóż prośbę, a administrator
                wyśle Ci zaproszenie do wspólnej przestrzeni z szablonami i brandingiem.
              </div>
              <form method="post" onSubmit=${submit}>
                <button type="submit" class="btn" disabled=${busy}
                        style=${{background:'#7c3aed',color:'#fff',fontSize:'.83rem',fontWeight:600,borderRadius:'8px',opacity: busy ? .7 : 1}}
                        aria-describedby="canva-desc">
                  ${busy
                    ? html`<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span>Wysyłanie…`
                    : html`<span><i class="bi bi-send-fill me-1" aria-hidden="true"></i>Poproś o dostęp do Canva</span>`}
                </button>
              </form>
              ${err ? html`<div class="text-danger mt-2" style=${{fontSize:'.78rem'}} role="alert">${err}</div>` : null}
            </div>
          </div>
        </div>
      </div>`;
  }

  try { ReactDOM.createRoot(mount).render(html`<${Card} />`); }
  catch (e) { /* fallback serwerowy zostaje */ }
});
</script>
<?php endif; ?>
