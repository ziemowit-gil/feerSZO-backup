<?php
/**
 * modules/contractIt/view/panel.php — panel „Konta i grupy IT” w formularzach umów (dodawanie i edycja). Zastępuje panel Microsoft 365.
 * Wywołanie: <?= ContractIt::render($type, $id, $row, $opt) ?> (logic/ContractIt.php). Zmienne: $cit (ContractIt::state), $citOpt:
 *   skin        — '' (karta z ramką), 'metro' (prawa kolumna edycji wolontariatu), 'bare' (bez ramki i tytułu; tytuł daje strona);
 *   unitField   — 'mirror' (domyślnie: lista w panelu bez name, zsynchronizowana z polem org_unit_id formularza) albo 'own' (panel ma pole org_unit_id);
 *   actionField — jak wyżej dla action_id (tylko wolontariat); 'none' = bez działania;
 *   unitWhere / actionWhere — gdzie w formularzu jest to samo pole (np. „w sekcji Wolontariusz”), do opisu; unitLabel — jego etykieta;
 *   mailCheckbox — przy dodawaniu: pole „Załóż skrzynkę pocztową” (name=dh_create, jak dotąd); mailChecked — domyślnie zaznaczone;
 *   emailField  — nazwa pola e-mail osoby (domyślnie email): po wpisaniu panel sprawdza konto (router.php?_route=/stan).
 * Jednostka i działanie wybrane w panelu trafiają do pól formularza, więc zapis umowy się nie zmienia. Style .cit-* są tu, zwykły CSS
 * (bez Tailwinda), żeby panel wyglądał tak samo w szacie Metro, Bootstrap i Tailwind.
 */
$o       = $citOpt ?? [];
$skin    = (string)($o['skin'] ?? '');
$isWol   = $cit['type'] === 'wolontariat';
$unitF   = (string)($o['unitField'] ?? 'mirror');
$actF    = $isWol ? (string)($o['actionField'] ?? 'mirror') : 'none';
$acc     = $cit['account'];
$mail    = $cit['mail'];
$links   = $cit['links'];
$emailF  = (string)($o['emailField'] ?? 'email');
$date    = fn(string $d) => $d !== '' ? date('d.m.Y', strtotime($d)) : '';
$code    = fn(string $s) => '<code class="cit-code">' . h($s) . '</code>';
$unitName   = $cit['unitId'] ? (string)$cit['units'][$cit['unitId']] : '';
$actionName = $cit['actionId'] ? (string)$cit['actions'][$cit['actionId']] : '';
static $citCss = false;
if (!$citCss): $citCss = true; ?>
<style>
.cit{--cit-acc:#1d4ed8;--cit-ink:#1f2937;--cit-muted:#4b5563;--cit-line:#cbd5e1;--cit-soft:#f1f5f9;
  background:#fff;border:1px solid var(--cit-line);border-radius:8px;padding:1rem;color:var(--cit-ink);font-size:.875rem;line-height:1.45}
.cit--metro{background:var(--el,#f2f2f2);border:0;border-radius:0;padding:.75rem 1rem 1rem;margin-bottom:.4rem}
.cit--bare{background:transparent;border:0;padding:0}
.cit .cit-h{display:flex;align-items:center;gap:.45rem;margin:0 0 .25rem;font-size:1rem;font-weight:600;color:var(--cit-ink)}
.cit--metro .cit-h{font-size:.62rem;font-weight:700;letter-spacing:.16em;text-transform:uppercase;color:var(--cit-muted)}
.cit .cit-lead{margin:0 0 .75rem;color:var(--cit-muted);font-size:.8rem}
.cit .cit-sub{margin:.9rem 0 .4rem;font-size:.8rem;font-weight:700;color:var(--cit-ink);display:flex;align-items:center;gap:.4rem}
.cit .cit-sub:first-of-type{margin-top:.25rem}
.cit .cit-dl{margin:0}
.cit .cit-row{display:flex;flex-wrap:wrap;align-items:baseline;gap:.15rem .5rem;padding:.3rem 0;border-bottom:1px solid var(--cit-line)}
.cit .cit-row:last-child{border-bottom:0}
.cit .cit-row dt{flex:0 0 9.5rem;font-weight:400;color:var(--cit-muted)}
.cit--metro .cit-row dt{flex-basis:100%}
.cit .cit-row dd{flex:1 1 10rem;margin:0;min-width:0;word-break:break-word}
.cit .cit-code{font-family:ui-monospace,Menlo,monospace;font-size:.8rem;color:var(--cit-ink);background:var(--cit-soft);padding:0 .25rem;border-radius:3px}
.cit .cit-badge{display:inline-block;font-size:.7rem;font-weight:700;padding:.05rem .4rem;border-radius:999px;margin-right:.3rem;vertical-align:1px}
.cit .cit-ok{background:#d1fae5;color:#065f46}.cit .cit-off{background:#e5e7eb;color:#374151}.cit .cit-warn{background:#ffedd5;color:#7c2d12}
.cit .cit-note{margin:.4rem 0 0;color:var(--cit-muted);font-size:.8rem}
.cit .cit-alert{margin:.5rem 0 0;padding:.45rem .6rem;border-left:4px solid #c2410c;background:#fff7ed;color:#7c2d12;font-size:.8rem}
.cit .cit-links{margin:.5rem 0 0;display:flex;flex-wrap:wrap;gap:.25rem .9rem;font-size:.8rem}
.cit a{color:var(--cit-acc);text-decoration:underline;text-underline-offset:2px}
.cit a:hover{color:#1e3a8a}
.cit .cit-field{margin:.25rem 0 .7rem}
.cit .cit-label{display:block;font-weight:600;font-size:.8rem;margin-bottom:.2rem;color:var(--cit-ink)}
.cit .cit-select{display:block;width:100%;min-height:38px;padding:.3rem .5rem;font-size:.875rem;color:var(--cit-ink);background:#fff;border:1px solid #6b7280;border-radius:6px}
.cit--metro .cit-select{border-radius:0}
.cit .cit-help{margin:.25rem 0 0;color:var(--cit-muted);font-size:.78rem}
.cit .cit-help b{color:var(--cit-ink)}
.cit .cit-sug{margin:.35rem 0 0;padding:.4rem .55rem;background:#eff6ff;border-left:4px solid var(--cit-acc);font-size:.8rem;color:var(--cit-ink)}
.cit .cit-btn{display:inline-flex;align-items:center;min-height:32px;margin-left:.35rem;padding:.15rem .65rem;font-size:.8rem;font-weight:600;color:#fff;background:#1d4ed8;border:0;border-radius:4px;cursor:pointer}
.cit .cit-btn:hover{background:#1e3a8a}
.cit .cit-check{display:flex;gap:.5rem;align-items:flex-start;margin:.2rem 0 0}
.cit .cit-check input{width:1.1rem;height:1.1rem;margin-top:.15rem;flex-shrink:0}
.cit .cit-check label{font-weight:600}
.cit :focus-visible,.cit-legacy>summary:focus-visible{outline:3px solid #1d4ed8;outline-offset:2px}
.cit .cit-sr{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
/* Zwinięte pola Microsoft 365 (formularze umów) */
.cit-legacy{border:1px dashed #94a3b8;border-radius:6px;background:#f8fafc;margin:.75rem 0;color:#1f2937}
.cit-legacy>summary{cursor:pointer;padding:.55rem .8rem;font-weight:600;font-size:.85rem;color:#334155;list-style-position:inside}
.cit-legacy>summary .cit-legacy-sub{font-weight:400;color:#4b5563;font-size:.78rem;margin-left:.35rem}
.cit-legacy[open]>summary{border-bottom:1px dashed #94a3b8}
.cit-legacy>.cit-legacy-body{padding:.75rem .8rem}
</style>
<?php endif; ?>
<section class="cit<?= $skin !== '' ? ' cit--' . h($skin) : '' ?>" id="contractIt" aria-labelledby="citH"
         data-type="<?= h($cit['type']) ?>" data-add="<?= $cit['add'] ? '1' : '0' ?>" data-state-url="<?= h($links['state']) ?>" data-email-field="<?= h($emailF) ?>">
  <h2 class="cit-h<?= $skin === 'bare' ? ' cit-sr' : '' ?>" id="citH"><i class="bi bi-shield-lock" aria-hidden="true"></i> Konta i grupy IT</h2>
  <?php if ($skin !== 'bare'): ?>
  <p class="cit-lead">Logowanie jednokrotne, chmura plików i nowa poczta. Microsoft 365 wyłączamy <?= h($cit['msOff']) ?>.</p>
  <?php endif; ?>

  <h3 class="cit-sub"><i class="bi bi-person-badge" aria-hidden="true"></i> Konto osoby</h3>
  <dl class="cit-dl" id="citAcc" aria-live="polite">
    <div class="cit-row"><dt>Logowanie jednokrotne</dt>
      <dd data-cit="logto"><?php if ($acc['logto']): ?><span class="cit-badge cit-ok">jest</span><?= $acc['logtoUser'] !== '' ? 'login ' . $code($acc['logtoUser']) : '' ?>
        <?php elseif (!$acc['logtoReady']): ?><span class="cit-badge cit-off">nieskonfigurowane</span>
        <?php else: ?><span class="cit-badge cit-off">brak konta</span><?php endif; ?></dd></div>
    <div class="cit-row"><dt>Chmura plików</dt>
      <dd data-cit="cloud"><?php if ($acc['cloud']): ?><span class="cit-badge cit-ok">jest</span>login <?= $code($acc['cloudUid']) ?>
        <?php elseif (!$acc['cloudReady']): ?><span class="cit-badge cit-off">nieskonfigurowana</span>
        <?php else: ?><span class="cit-badge cit-off">brak konta</span><?php endif; ?></dd></div>
  </dl>
  <p class="<?= $cit['add'] ? 'cit-note' : 'cit-alert' ?>" data-cit="noemail"<?= $acc['email'] !== '' ? ' hidden' : '' ?>>
    <?= $cit['add'] ? 'Wpisz e-mail osoby. Konto zakładamy na ten adres, a panel sprawdzi, czy osoba ma już konto.'
                    : 'Umowa nie ma poprawnego adresu e-mail. Konto zakładamy na e-mail z umowy, więc najpierw go uzupełnij.' ?>
  </p>
  <?php if ($cit['add']): ?>
  <p class="cit-note">Konto założysz po zapisie umowy w Zarządzaniu dostępem. Osoba może też założyć je sama: SZO poprosi o to przy pierwszym logowaniu. Jedno hasło działa w logowaniu jednokrotnym i w chmurze.</p>
  <?php elseif ($links['access'] !== ''): ?>
  <p class="cit-links">
    <a href="<?= h($links['access']) ?>">Zarządzaj kontem<span class="cit-sr"> w Zarządzaniu dostępem</span></a>
    <a href="<?= h($links['card']) ?>">Hasło i konto na stronie umowy</a>
  </p>
  <?php endif; ?>

  <?php if ($mail !== null): ?>
  <h3 class="cit-sub"><i class="bi bi-envelope-at" aria-hidden="true"></i> Poczta</h3>
  <?php if ($cit['add']): ?>
    <?php if ($mail['on'] && !empty($o['mailCheckbox'])): ?>
    <div class="cit-check">
      <input type="checkbox" name="dh_create" id="dh_create" value="1"<?= ($o['mailChecked'] ?? true) ? ' checked' : '' ?> aria-describedby="dh_create_help">
      <div><label for="dh_create">Załóż skrzynkę pocztową</label>
        <p class="cit-help" id="dh_create_help">Adres imie.nazwisko@<?= h($mail['domain']) ?>. Gdy ta osoba (PESEL) miała już skrzynkę, wróci do niej.
          Hasło pójdzie SMS-em na numer z umowy i pokaże się raz na stronie umowy. Skrzynkę zawieszamy <?= (int)$mail['graceDays'] ?> dni po końcu umowy, bez powiadomienia.
          Hasło do poczty jest inne niż hasło logowania jednokrotnego.</p></div>
    </div>
    <?php elseif (!$mail['on']): ?>
    <p class="cit-note">Zakładanie skrzynek przy umowach jest wyłączone.<?php if ($links['migration'] !== ''): ?> <a href="<?= h($links['migration']) ?>">Migracja poczty</a><?php endif; ?></p>
    <?php else: ?>
    <p class="cit-note">Skrzynkę imie.nazwisko@<?= h($mail['domain']) ?> założysz po zapisie umowy, na jej stronie w karcie „Skrzynka pocztowa”.</p>
    <?php endif; ?>
  <?php else: ?>
  <dl class="cit-dl">
    <div class="cit-row"><dt>Skrzynka</dt>
      <dd><?php if ($mail['box']): $susp = ($mail['box']['status'] ?? '') === 'suspended'; ?>
        <span class="cit-badge <?= $susp ? 'cit-warn' : 'cit-ok' ?>"><?= $susp ? 'zawieszona' : 'aktywna' ?></span><?= $code((string)$mail['box']['mailbox']) ?>
        <?= $susp && !empty($mail['box']['suspended_at']) ? ' od ' . h($date((string)$mail['box']['suspended_at'])) : '' ?>
      <?php else: ?><span class="cit-badge cit-off">brak skrzynki</span><?php endif; ?></dd></div>
    <?php if ($mail['m365Box'] !== ''): ?>
    <div class="cit-row"><dt>Poczta z Microsoft 365</dt>
      <dd><?php if ($mail['copy']): ?>przenoszenie: <?= h($mail['copy']['label']) ?><?= $mail['copy']['status'] !== 'done' && $mail['copy']['percent'] > 0 ? ', ' . (int)$mail['copy']['percent'] . '%' : '' ?><?= $mail['copy']['status'] === 'done' && $mail['copy']['finished'] !== '' ? ' ' . h($date($mail['copy']['finished'])) : '' ?>
        <?php else: ?>jeszcze nie przeniesiona<?php endif; ?></dd></div>
    <?php endif; ?>
  </dl>
  <p class="cit-links">
    <?php if ($links['mailbox'] !== ''): ?><a href="<?= h($links['mailbox']) ?>"><?= $mail['box'] ? 'Skrzynka na stronie umowy' : ($mail['on'] ? 'Załóż skrzynkę na stronie umowy' : 'Skrzynka na stronie umowy') ?></a><?php endif; ?>
    <?php if ($links['migration'] !== '' && $mail['m365Box'] !== ''): ?><a href="<?= h($links['migration']) ?>">Migracja poczty</a><?php endif; ?>
  </p>
  <?php endif; ?>
  <?php endif; ?>

  <h3 class="cit-sub"><i class="bi bi-people" aria-hidden="true"></i> Grupy w chmurze</h3>
  <div class="cit-field">
    <label class="cit-label" for="citUnit">Jednostka organizacyjna</label>
    <select class="cit-select" id="citUnit"<?= $unitF === 'own' ? ' name="org_unit_id"' : ' data-cit-mirror="org_unit_id"' ?> data-cit-kind="unit" aria-describedby="citUnitHelp citUnitSug">
      <option value="">nie wybrano</option>
      <?php foreach ($cit['units'] as $uid => $un): ?>
      <option value="<?= (int)$uid ?>"<?= (int)$uid === (int)$cit['unitId'] ? ' selected' : '' ?>><?= h((string)$un) ?></option>
      <?php endforeach; ?>
    </select>
    <p class="cit-help" id="citUnitHelp">Grupa: <b data-cit-group="unit"><?= $unitName !== '' ? h('Jednostka: ' . $unitName) : 'brak, wybierz jednostkę' ?></b>.
      <?= !empty($o['unitWhere']) ? 'To samo pole co „' . h((string)($o['unitLabel'] ?? 'Komórka organizacyjna')) . '” ' . h((string)$o['unitWhere']) . '.' : '' ?></p>
    <?php $s = $cit['unitSuggest']; ?>
    <p class="cit-sug" id="citUnitSug" data-cit-sug="unit" data-value="<?= $s ? (int)$s['id'] : '' ?>"<?= $s ? '' : ' hidden' ?>>
      <span data-cit-sugtext><?= $s ? 'Propozycja: ' . h($s['name']) . ' (' . h($s['why']) . ').' : '' ?></span>
      <button type="button" class="cit-btn" data-cit-set="unit">Ustaw</button></p>
  </div>
  <?php if ($actF !== 'none'): ?>
  <div class="cit-field">
    <label class="cit-label" for="citAction">Działanie</label>
    <select class="cit-select" id="citAction"<?= $actF === 'own' ? ' name="action_id"' : ' data-cit-mirror="action_id"' ?> data-cit-kind="action" aria-describedby="citActionHelp citActionSug">
      <option value="">nie wybrano</option>
      <?php foreach ($cit['actions'] as $aid => $an): ?>
      <option value="<?= (int)$aid ?>"<?= (int)$aid === (int)$cit['actionId'] ? ' selected' : '' ?>><?= h((string)$an) ?></option>
      <?php endforeach; ?>
    </select>
    <p class="cit-help" id="citActionHelp">Grupa: <b data-cit-group="action"><?= $actionName !== '' ? h('Działanie: ' . $actionName) : 'brak, wybierz działanie' ?></b>.
      <?= !empty($o['actionWhere']) ? 'To samo pole co „Działanie” ' . h((string)$o['actionWhere']) . '.' : '' ?></p>
    <?php $s = $cit['actionSuggest']; ?>
    <p class="cit-sug" id="citActionSug" data-cit-sug="action" data-value="<?= $s ? (int)$s['id'] : '' ?>"<?= $s ? '' : ' hidden' ?>>
      <span data-cit-sugtext><?= $s ? 'Propozycja: ' . h($s['name']) . ' (' . h($s['why']) . ').' : '' ?></span>
      <button type="button" class="cit-btn" data-cit-set="action">Ustaw</button></p>
  </div>
  <?php endif; ?>
  <p class="cit-note">Osoba trafia do tych grup, gdy umowa jest aktywna i osoba ma konto w chmurze. SZO odświeża grupy co kilka minut.</p>
  <?php if ($links['groups'] !== ''): ?><p class="cit-links"><a href="<?= h($links['groups']) ?>">Grupy w chmurze i problemy z przypisaniami</a></p><?php endif; ?>
  <p class="cit-sr" id="citLive" aria-live="polite"></p>
</section>
<script>
(function () {
  var root = document.getElementById('contractIt');
  if (!root || root.dataset.ready) return;
  root.dataset.ready = '1';
  var scope = root.closest('form') || document;
  var live = document.getElementById('citLive');
  function esc(t) { var d = document.createElement('div'); d.textContent = t == null ? '' : String(t); return d.innerHTML; }
  function byName(n) {   // pole formularza poza panelem (panel ma listy bez name albo własne pole przy 'own')
    var list = scope.querySelectorAll('[name="' + n + '"]');
    for (var i = 0; i < list.length; i++) if (!root.contains(list[i])) return list[i];
    return null;
  }
  function text(sel) { var o = sel.options[sel.selectedIndex]; return sel.value && o ? o.text.trim() : ''; }
  var pairs = {};
  root.querySelectorAll('select[data-cit-kind]').forEach(function (px) {
    var kind = px.dataset.citKind;
    var real = px.dataset.citMirror ? byName(px.dataset.citMirror) : null;
    var grp = root.querySelector('[data-cit-group="' + kind + '"]');
    var sug = root.querySelector('[data-cit-sug="' + kind + '"]');
    function paint() {
      var t = text(px);
      grp.textContent = t ? (kind === 'unit' ? 'Jednostka: ' : 'Działanie: ') + t : (kind === 'unit' ? 'brak, wybierz jednostkę' : 'brak, wybierz działanie');
      if (sug) sug.hidden = !!px.value || !sug.dataset.value;
    }
    function setReal(v) {
      if (!real) return;
      if (real.tomselect) { real.tomselect.setValue(v); return; }
      real.value = v;
      real.dispatchEvent(new Event('input', { bubbles: true }));
      real.dispatchEvent(new Event('change', { bubbles: true }));
    }
    px.addEventListener('change', function () { setReal(px.value); paint(); if (kind === 'unit') refresh(true); });
    if (real) {
      if (real.value !== px.value) px.value = real.value;
      real.addEventListener('change', function () { if (px.value !== real.value) { px.value = real.value; if (kind === 'unit') refresh(true); } paint(); });
    }
    pairs[kind] = { px: px, sug: sug, paint: paint, set: function (v) { px.value = v; setReal(v); paint(); } };
    paint();
  });
  root.addEventListener('click', function (e) {
    var b = e.target.closest('[data-cit-set]');
    if (!b) return;
    var p = pairs[b.dataset.citSet];
    if (!p || !p.sug || !p.sug.dataset.value) return;
    p.set(p.sug.dataset.value);
    if (b.dataset.citSet === 'unit') refresh(true);
    p.px.focus();
    if (live) live.textContent = 'Ustawiono: ' + text(p.px) + '.';
  });
  function paintSug(kind, s) {
    var p = pairs[kind];
    if (!p || !p.sug) return;
    p.sug.dataset.value = s ? s.id : '';
    p.sug.querySelector('[data-cit-sugtext]').textContent = s ? 'Propozycja: ' + s.name + ' (' + s.why + ').' : '';
    p.paint();
  }
  function badge(cls, t) { return '<span class="cit-badge ' + cls + '">' + esc(t) + '</span>'; }
  function paintAcc(d) {
    var l = root.querySelector('[data-cit="logto"]'), c = root.querySelector('[data-cit="cloud"]'), n = root.querySelector('[data-cit="noemail"]');
    if (l) l.innerHTML = d.logto ? badge('cit-ok', 'jest') + (d.logtoUser ? 'login <code class="cit-code">' + esc(d.logtoUser) + '</code>' : '') : badge('cit-off', 'brak konta');
    if (c) c.innerHTML = d.cloud ? badge('cit-ok', 'jest') + 'login <code class="cit-code">' + esc(d.cloudUid) + '</code>' : badge('cit-off', 'brak konta');
    if (n) n.hidden = !!d.email;
  }
  // Sprawdzenie konta po e-mailu (dodawanie) i odświeżenie propozycji po zmianie jednostki.
  var url = root.dataset.stateUrl, em = byName(root.dataset.emailField || 'email'), last = null, timer = null;
  function refresh(force) {
    if (!url) return;
    var mail = em ? (em.value || '').trim().toLowerCase() : '';
    var unit = pairs.unit ? pairs.unit.px.value : '';
    var key = mail + '|' + unit;
    if (!force && key === last) return;
    last = key;
    fetch(url + '&type=' + encodeURIComponent(root.dataset.type) + '&email=' + encodeURIComponent(mail) + '&unit=' + encodeURIComponent(unit),
          { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (d) {
        if (!d || typeof d !== 'object') return;
        if (root.dataset.add === '1') paintAcc(d);
        if (pairs.unit && !pairs.unit.px.value) paintSug('unit', d.unit);
        if ('action' in d && pairs.action && !pairs.action.px.value) paintSug('action', d.action);
      })
      .catch(function () {});
  }
  if (em && root.dataset.add === '1') {
    em.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(function () { refresh(false); }, 700); });
    em.addEventListener('change', function () { refresh(false); });
    if (em.value) refresh(false);
  }
})();
</script>
