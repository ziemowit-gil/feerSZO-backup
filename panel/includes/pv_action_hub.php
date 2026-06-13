<?php
/**
 * panel/includes/pv_action_hub.php — „Centrum akcji" panelu wolontariusza.
 *
 * Wyspa React (ładowana z CDN, bez kroku budowania) wzbogacająca siatkę
 * „Szybkich akcji": filtrowanie tekstowe + przełącznik „Wymaga uwagi" +
 * nawigacja klawiaturą i komunikaty dla czytników ekranu.
 *
 * Progressive enhancement: w <div id="pvActionHub"> renderujemy pełną,
 * dostępną wersję serwerową (zwykłe linki). React montuje się do tego węzła
 * i podmienia zawartość — gdy JS lub CDN zawiedzie, działa wersja serwerowa.
 *
 * Wymaga w zasięgu: $_pv_actions (array), APP_URL, h(). Reużywa klas
 * .vol-action* zdefiniowanych w panel/index.php.
 */
if (empty($_pv_actions) || !is_array($_pv_actions)) return;

// Tylko akcje z poprawnym href + etykietą
$_pv_actions = array_values(array_filter($_pv_actions, fn($a) => !empty($a['href']) && !empty($a['label'])));
if (!$_pv_actions) return;

$_pv_attention = count(array_filter($_pv_actions, fn($a) => (int)($a['badge'] ?? 0) > 0));
?>
<style>
/* ── Centrum akcji (pv-hub) ─────────────────────────────────────────────── */
.pv-hub-bar{display:flex;align-items:center;gap:.6rem;flex-wrap:wrap;margin-bottom:.85rem}
.pv-hub-title{font-size:.95rem;font-weight:800;color:#111827;margin:0;line-height:1.2;display:flex;align-items:center;gap:.4rem}
.pv-hub-title i{color:var(--vol-color)}
.pv-hub-search{position:relative;flex:1;min-width:180px;max-width:340px}
.pv-hub-search i{position:absolute;left:.7rem;top:50%;transform:translateY(-50%);color:#9CA3AF;font-size:.9rem;pointer-events:none}
.pv-hub-search input{width:100%;border:1.5px solid #E5E7EB;border-radius:9px;padding:.45rem .7rem .45rem 2rem;font-size:.85rem;background:#fff;color:#111827;transition:border-color .12s,box-shadow .12s}
.pv-hub-search input:focus{outline:none;border-color:var(--vol-color);box-shadow:0 0 0 3px rgba(var(--vol-rgb,29,78,216),.15)}
.pv-hub-chips{display:inline-flex;gap:.35rem}
.pv-hub-chip{border:1.5px solid #E5E7EB;background:#fff;border-radius:2rem;padding:.32rem .8rem;font-size:.78rem;font-weight:600;color:#374151;cursor:pointer;transition:all .12s;display:inline-flex;align-items:center;gap:.3rem}
.pv-hub-chip:hover{border-color:var(--vol-color);color:var(--vol-color)}
.pv-hub-chip:focus-visible{outline:2px solid var(--vol-color);outline-offset:2px}
.pv-hub-chip[aria-pressed="true"]{background:var(--vol-color);border-color:var(--vol-color);color:#fff}
.pv-hub-chip .pv-hub-chip-num{background:rgba(0,0,0,.12);border-radius:1rem;padding:0 .4rem;font-size:.7rem;font-weight:700}
.pv-hub-chip[aria-pressed="true"] .pv-hub-chip-num{background:rgba(255,255,255,.28)}
.pv-hub-empty{background:#fff;border:2px dashed #E5E7EB;border-radius:12px;text-align:center;padding:1.75rem 1rem;color:#6B7280}
.pv-hub-empty i{font-size:1.6rem;color:#D1D5DB;display:block;margin-bottom:.4rem}
.pv-hub-clear{background:none;border:none;color:var(--vol-color);font-size:.82rem;font-weight:600;cursor:pointer;text-decoration:underline;padding:.2rem .4rem}
.pv-hub-clear:focus-visible{outline:2px solid var(--vol-color);outline-offset:2px;border-radius:4px}
@media(max-width:560px){.pv-hub-search{max-width:none;width:100%;order:3}}
</style>

<section class="pv-hub mb-2" aria-labelledby="pv-hub-heading"<?= isset($_vol_rgb) ? ' style="--vol-rgb:'.h($_vol_rgb).'"' : '' ?>>
  <h2 id="pv-hub-heading" class="visually-hidden">Szybkie akcje</h2>
  <div id="pvActionHub"
       data-actions='<?= h(json_encode($_pv_actions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>'
       data-attention="<?= (int)$_pv_attention ?>">
    <!-- ── Fallback serwerowy (działa bez JS / przy awarii CDN) ───────────── -->
    <nav aria-label="Szybkie akcje">
      <ul class="vol-actions">
        <?php foreach ($_pv_actions as $a):
          $_badge = (int)($a['badge'] ?? 0);
          $_bcls  = $a['badgeClass'] ?? 'danger';
          $_aria  = $a['label'] . ($_badge ? " — {$_badge} wymaga uwagi" : '');
        ?>
        <li><a href="<?= h($a['href']) ?>" class="vol-action-btn" aria-label="<?= h($_aria) ?>">
          <?php if ($_badge): ?>
          <span class="vol-action-badge badge rounded-pill bg-<?= h($_bcls) ?><?= $_bcls === 'warning' ? ' text-dark' : '' ?>" aria-hidden="true"><?= $_badge ?></span>
          <?php endif; ?>
          <div class="vol-action-icon-wrap" aria-hidden="true"<?= !empty($a['iconBg']) ? ' style="background:'.h($a['iconBg']).'"' : '' ?>>
            <i class="bi <?= h($a['icon']) ?> vol-action-icon"<?= !empty($a['iconColor']) ? ' style="color:'.h($a['iconColor']).'"' : '' ?>></i>
          </div>
          <div>
            <?php if (isset($a['count']) && $a['count'] !== ''): ?>
            <div class="vol-action-count" aria-hidden="true"<?= !empty($a['iconColor']) ? ' style="color:'.h($a['iconColor']).'"' : '' ?>><?= h((string)$a['count']) ?></div>
            <?php endif; ?>
            <div class="vol-action-label"><?= h($a['label']) ?></div>
            <?php if (!empty($a['sub'])): ?>
            <div class="vol-action-sub"><?= h($a['sub']) ?></div>
            <?php endif; ?>
          </div>
        </a></li>
        <?php endforeach; ?>
      </ul>
    </nav>
  </div>
</section>

<!-- Wspólny loader React (raz na stronę, niezależny od kolejności). -->
<?php require_once __DIR__ . '/pv_react_boot.php'; ?>
<script>
window.pvReact(function (React, ReactDOM, html) {
  var mount = document.getElementById('pvActionHub');
  if (!mount) return; // fallback serwerowy zostaje

  var actions;
  try { actions = JSON.parse(mount.getAttribute('data-actions') || '[]'); }
  catch (e) { return; }
  if (!Array.isArray(actions) || !actions.length) return;

  var useState = React.useState, useMemo = React.useMemo;

  function norm(s){ return (s || '').toString().toLowerCase()
    .normalize('NFD').replace(/[̀-ͯ]/g,''); }

  function Card(props){
    var a = props.a;
    var badge = parseInt(a.badge, 10) || 0;
    var bcls  = a.badgeClass || 'danger';
    var aria  = a.label + (badge ? (' — ' + badge + ' wymaga uwagi') : '');
    return html`
      <li>
        <a href=${a.href} class="vol-action-btn" aria-label=${aria}>
          ${badge ? html`<span class=${'vol-action-badge badge rounded-pill bg-' + bcls + (bcls==='warning'?' text-dark':'')} aria-hidden="true">${badge}</span>` : null}
          <div class="vol-action-icon-wrap" aria-hidden="true" style=${a.iconBg ? {background:a.iconBg} : null}>
            <i class=${'bi ' + a.icon + ' vol-action-icon'} style=${a.iconColor ? {color:a.iconColor} : null}></i>
          </div>
          <div>
            ${(a.count !== undefined && a.count !== '') ? html`<div class="vol-action-count" aria-hidden="true" style=${a.iconColor ? {color:a.iconColor} : null}>${a.count}</div>` : null}
            <div class="vol-action-label">${a.label}</div>
            ${a.sub ? html`<div class="vol-action-sub">${a.sub}</div>` : null}
          </div>
        </a>
      </li>`;
  }

  function Hub(){
    var qState = useState(''),      q = qState[0],      setQ = qState[1];
    var fState = useState('all'),   filter = fState[0], setFilter = fState[1];

    var attention = useMemo(function(){
      return actions.filter(function(a){ return (parseInt(a.badge,10)||0) > 0; }).length;
    }, []);

    var shown = useMemo(function(){
      var nq = norm(q.trim());
      return actions.filter(function(a){
        if (filter === 'attention' && !((parseInt(a.badge,10)||0) > 0)) return false;
        if (!nq) return true;
        return norm(a.label).indexOf(nq) !== -1 || norm(a.sub).indexOf(nq) !== -1;
      });
    }, [q, filter]);

    return html`
      <div>
        <div class="pv-hub-bar">
          <p class="pv-hub-title" id="pv-hub-vis-title">
            <i class="bi bi-grid-3x3-gap-fill" aria-hidden="true"></i>Szybkie akcje
          </p>
          <div class="pv-hub-search">
            <i class="bi bi-search" aria-hidden="true"></i>
            <label class="visually-hidden" for="pvHubSearch">Szukaj akcji</label>
            <input id="pvHubSearch" type="search" autocomplete="off"
                   placeholder="Szukaj akcji…" value=${q}
                   aria-controls="pvHubGrid"
                   onInput=${function(e){ setQ(e.target.value); }} />
          </div>
          <div class="pv-hub-chips" role="group" aria-label="Filtruj akcje">
            <button type="button" class="pv-hub-chip"
                    aria-pressed=${filter === 'all'}
                    onClick=${function(){ setFilter('all'); }}>Wszystkie</button>
            <button type="button" class="pv-hub-chip"
                    aria-pressed=${filter === 'attention'}
                    disabled=${attention === 0}
                    onClick=${function(){ setFilter('attention'); }}>
              Wymaga uwagi
              ${attention ? html`<span class="pv-hub-chip-num">${attention}</span>` : null}
            </button>
          </div>
        </div>

        <div aria-live="polite" class="visually-hidden">
          ${'Pokazano ' + shown.length + (shown.length === 1 ? ' akcję' : (shown.length >= 2 && shown.length <= 4 ? ' akcje' : ' akcji'))}
        </div>

        ${shown.length ? html`
          <nav aria-labelledby="pv-hub-vis-title">
            <ul class="vol-actions" id="pvHubGrid">
              ${shown.map(function(a, i){ return html`<${Card} key=${a.href + i} a=${a} />`; })}
            </ul>
          </nav>
        ` : html`
          <div class="pv-hub-empty" role="status">
            <i class="bi bi-search" aria-hidden="true"></i>
            <div>Brak akcji pasujących do „${q}".</div>
            <button type="button" class="pv-hub-clear"
                    onClick=${function(){ setQ(''); setFilter('all'); }}>Wyczyść filtry</button>
          </div>
        `}
      </div>`;
  }

  try {
    ReactDOM.createRoot(mount).render(html`<${Hub} />`);
  } catch (e) { /* fallback serwerowy zostaje */ }
});
</script>
