<?php
/**
 * modules/org_moves/view/index.php — Przenoszenie w strukturze (osoby i działy), szata Metro
 * w powłoce Katalogu. Wejście: $units (id => jednostka), $unitId, $userId, $q, $members.
 */
require_once dirname(__DIR__, 2) . '/katalog_metro/lib/katalogMetro.php';
katalogMetroAssets();

// Opcje celu: Jednostka, pod nią jej działy; działy bez jednostki na końcu
$tree = ['jednostki' => [], 'luzem' => []];
foreach ($units as $u) {
    if (orgLevelOf($u) === 'jednostka') { $tree['jednostki'][(int)$u['id']] = ['unit' => $u, 'dzialy' => []]; }
}
foreach ($units as $u) {
    if (orgLevelOf($u) !== 'dzial') continue;
    $p = orgLevelParentUnit($u, $units);
    if ($p && isset($tree['jednostki'][(int)$p['id']])) $tree['jednostki'][(int)$p['id']]['dzialy'][] = $u;
    else $tree['luzem'][] = $u;
}
$targetOptions = function () use ($tree): string {
    $h = '<option value="">— wybierz cel —</option>';
    foreach ($tree['jednostki'] as $g) {
        $h .= '<optgroup label="' . h('Jednostka: ' . $g['unit']['name']) . '">';
        $h .= '<option value="' . (int)$g['unit']['id'] . '">' . h($g['unit']['name']) . ' (sama jednostka)</option>';
        foreach ($g['dzialy'] as $d) $h .= '<option value="' . (int)$d['id'] . '">Dział: ' . h($d['name']) . '</option>';
        $h .= '</optgroup>';
    }
    if ($tree['luzem']) {
        $h .= '<optgroup label="Działy bez jednostki">';
        foreach ($tree['luzem'] as $d) $h .= '<option value="' . (int)$d['id'] . '">Dział: ' . h($d['name']) . '</option>';
        $h .= '</optgroup>';
    }
    return $h;
};
$dzialy = array_values(array_filter($units, fn($u) => orgLevelOf($u) === 'dzial'));
?>
<style>
.pvm .mv-filter{display:flex;flex-wrap:wrap;gap:8px;align-items:flex-end;margin-bottom:1rem}
.pvm .mv-filter label{display:block;font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.1em;color:var(--pvm-muted);margin-bottom:.2rem}
.pvm .mv-in{border:2px solid #8a8a8a;border-radius:0;padding:.45rem .6rem;font:inherit;background:var(--pvm-bg);color:var(--pvm-ink);min-width:220px}
.pvm .mv-in:focus{border-color:var(--pvm-acc);outline:0}
.pvm .mv-btn{border:2px solid var(--pvm-acc);background:var(--pvm-acc);color:#fff;font-weight:600;padding:.45rem 1rem;cursor:pointer}
.pvm .mv-btn:hover{background:#1d1d1d;border-color:#1d1d1d}
.pvm .mv-btn--ghost{background:transparent;color:var(--pvm-ink);border-color:var(--pvm-ink)}
.pvm .mv-btn--ghost:hover{background:var(--pvm-ink);color:#fff}
.pvm .mv-table{width:100%;border-collapse:collapse;font-size:.92rem}
.pvm .mv-table th{text-align:left;font-size:.68rem;text-transform:uppercase;letter-spacing:.1em;color:var(--pvm-muted);border-bottom:2px solid var(--pvm-ink);padding:.45rem .5rem}
.pvm .mv-table td{border-bottom:1px solid var(--pvm-line);padding:.5rem;vertical-align:middle}
.pvm .mv-table tr:hover td{background:var(--pvm-light)}
.pvm .mv-table input[type=checkbox]{width:1.15rem;height:1.15rem}
.pvm .mv-bar{position:sticky;bottom:0;z-index:5;display:flex;flex-wrap:wrap;gap:8px;align-items:center;background:#1d1d1d;color:#fff;padding:.75rem 1rem;margin-top:1rem}
.pvm .mv-bar .mv-in{min-width:280px;border-color:#fff}
.pvm .mv-bar b{font-weight:600}
.pvm .mv-tag{display:inline-block;font-size:.66rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;background:#c25100;color:#fff;padding:1px 6px;margin-left:.3rem}
.pvm .mv-sub{color:var(--pvm-muted);font-size:.82rem}
</style>
<div class="pvm ktm">
  <div class="mt-head">
    <div>
      <div class="mt-kicker">Katalog współpracowników · administrator i zarząd</div>
      <h1 class="mt-title">Przenoszenie w strukturze</h1>
      <p class="mt-lead">Przenieś osoby do innej jednostki lub działu — jedną albo wiele naraz — i przypinaj działy do jednostek. Każda zmiana trafia do historii struktury.</p>
    </div>
  </div>

  <nav class="mt-pivot" role="tablist" aria-label="Co przenosisz">
    <button type="button" role="tab" id="mv-tab-osoby" aria-controls="mv-osoby" aria-selected="true" data-mv-tab="osoby">Osoby</button>
    <button type="button" role="tab" id="mv-tab-dzialy" aria-controls="mv-dzialy" aria-selected="false" data-mv-tab="dzialy" tabindex="-1">Działy</button>
  </nav>

  <!-- ═══ OSOBY ═══ -->
  <section id="mv-osoby" role="tabpanel" aria-labelledby="mv-tab-osoby" tabindex="-1">
    <form method="get" class="mv-filter" role="search" aria-label="Filtruj osoby">
      <div><label for="mv-unit">Z jednostki / działu</label>
        <select id="mv-unit" name="unit" class="mv-in" onchange="this.form.submit()">
          <option value="">— wszystkie —</option>
          <?php foreach ($units as $u): ?><option value="<?= (int)$u['id'] ?>" <?= $unitId === (int)$u['id'] ? 'selected' : '' ?>><?= h(orgMovesUnitLabel($u, $units)) ?></option><?php endforeach; ?>
        </select></div>
      <div><label for="mv-q">Szukaj</label><input id="mv-q" name="q" type="search" class="mv-in" value="<?= h($q) ?>" placeholder="imię, nazwisko, stanowisko…"></div>
      <button type="submit" class="mv-btn mv-btn--ghost">Filtruj</button>
      <?php if ($unitId || $q || $userId): ?><a class="ktm-chip" href="<?= h(route('moves.index')) ?>"><i class="bi bi-x-lg" aria-hidden="true"></i>Wyczyść</a><?php endif; ?>
    </form>

    <?php if (!$members): ?>
    <div class="ktm-empty" role="status">Brak aktywnych przypisań dla tego filtra.</div>
    <?php else: ?>
    <form method="post" action="<?= h(route('moves.members')) ?>" id="mv-form-osoby">
      <?= Router::csrfField() ?>
      <input type="hidden" name="f_unit" value="<?= (int)$unitId ?>"><input type="hidden" name="f_q" value="<?= h($q) ?>">
      <table class="mv-table">
        <caption class="visually-hidden">Aktywne przypisania osób do jednostek i działów</caption>
        <thead><tr>
          <th scope="col" style="width:2.5rem"><input type="checkbox" id="mv-all" aria-label="Zaznacz wszystkie osoby na liście"></th>
          <th scope="col">Osoba</th><th scope="col">Stanowisko</th><th scope="col">Obecnie w</th>
        </tr></thead>
        <tbody>
        <?php foreach ($members as $m):
            $nm = directory_display_name($m) ?: $m['user_name'];
            $cur = $units[(int)$m['unit_id']] ?? null; ?>
          <tr>
            <td><input type="checkbox" name="members[]" value="<?= (int)$m['id'] ?>" class="mv-cb" id="mv-m<?= (int)$m['id'] ?>"<?= $userId ? ' checked' : '' ?>></td>
            <td><label for="mv-m<?= (int)$m['id'] ?>" style="font-weight:600;cursor:pointer"><?= h($nm) ?></label><div class="mv-sub"><?= h($m['email']) ?></div></td>
            <td><?= h($m['position_name'] ?: '—') ?><?php if ($m['is_head']): ?><span class="mv-tag">kierownik</span><?php endif; ?><?php if ($m['is_primary']): ?><span class="mv-sub"> · główne</span><?php endif; ?></td>
            <td><?= h($cur ? orgMovesUnitLabel($cur, $units) : $m['unit_name']) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <div class="mv-bar">
        <span aria-live="polite"><b id="mv-count">0</b> zaznaczonych</span>
        <label for="mv-target" class="visually-hidden">Przenieś do</label>
        <select id="mv-target" name="target" class="mv-in" required><?= $targetOptions() ?></select>
        <button type="submit" class="mv-btn">Przenieś zaznaczone</button>
        <span class="mv-sub" style="color:#ccc">Funkcja kierownika nie przechodzi; stare przypisanie zostaje w historii.</span>
      </div>
    </form>
    <?php endif; ?>
  </section>

  <!-- ═══ DZIAŁY ═══ -->
  <section id="mv-dzialy" role="tabpanel" aria-labelledby="mv-tab-dzialy" tabindex="-1" hidden>
    <?php if (!$tree['jednostki']): ?>
    <div class="ktm-empty" role="status">Nie ma jeszcze żadnej Jednostki. Utwórz ją w <a href="<?= APP_URL ?>/org/units/add.php?level=jednostka">strukturze organizacyjnej</a>, a potem przypnij do niej działy.</div>
    <?php elseif (!$dzialy): ?>
    <div class="ktm-empty" role="status">Brak działów do przeniesienia.</div>
    <?php else: ?>
    <form method="post" action="<?= h(route('moves.units')) ?>">
      <?= Router::csrfField() ?>
      <table class="mv-table">
        <caption class="visually-hidden">Działy i jednostki, do których należą</caption>
        <thead><tr><th scope="col" style="width:2.5rem"><input type="checkbox" id="mv-all-d" aria-label="Zaznacz wszystkie działy"></th><th scope="col">Dział</th><th scope="col">Należy do jednostki</th><th scope="col">Osób</th></tr></thead>
        <tbody>
        <?php foreach ($dzialy as $d): $p = orgLevelParentUnit($d, $units); ?>
          <tr>
            <td><input type="checkbox" name="units[]" value="<?= (int)$d['id'] ?>" class="mv-cb-d" id="mv-d<?= (int)$d['id'] ?>"></td>
            <td><label for="mv-d<?= (int)$d['id'] ?>" style="font-weight:600;cursor:pointer"><?= h($d['name']) ?></label> <span class="mv-sub"><?= h($d['code']) ?></span></td>
            <td><?= $p ? h($p['name']) : '<span class="mv-sub">— bez jednostki —</span>' ?></td>
            <td><?= (int)($d['member_count'] ?? 0) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <div class="mv-bar">
        <label for="mv-target-u" class="visually-hidden">Przypnij do jednostki</label>
        <select id="mv-target-u" name="target_unit" class="mv-in" required>
          <option value="">— wybierz jednostkę —</option>
          <?php foreach ($tree['jednostki'] as $g): ?><option value="<?= (int)$g['unit']['id'] ?>"><?= h($g['unit']['name']) ?></option><?php endforeach; ?>
        </select>
        <button type="submit" class="mv-btn">Przenieś zaznaczone działy</button>
      </div>
    </form>
    <?php endif; ?>
  </section>
</div>

<script>
(function () {
  var tabs = document.querySelectorAll('[data-mv-tab]');
  function show(k, focus) {
    tabs.forEach(function (t) {
      var on = t.getAttribute('data-mv-tab') === k;
      t.setAttribute('aria-selected', on ? 'true' : 'false'); t.tabIndex = on ? 0 : -1;
      document.getElementById('mv-' + t.getAttribute('data-mv-tab')).hidden = !on;
      if (on && focus) t.focus();
    });
  }
  tabs.forEach(function (t, i) {
    t.addEventListener('click', function () { show(t.getAttribute('data-mv-tab')); history.replaceState(null, '', t.getAttribute('data-mv-tab') === 'dzialy' ? '#dzialy' : location.pathname + location.search); });
    t.addEventListener('keydown', function (e) {
      if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
      var n = tabs[(i + (e.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length]; show(n.getAttribute('data-mv-tab'), true);
    });
  });
  if (location.hash === '#dzialy') show('dzialy');
  function wire(allId, cls, countId) {
    var all = document.getElementById(allId), cbs = document.querySelectorAll('.' + cls), out = countId && document.getElementById(countId);
    function upd() { var n = 0; cbs.forEach(function (c) { if (c.checked) n++; }); if (out) out.textContent = n; if (all) { all.checked = n && n === cbs.length; all.indeterminate = n > 0 && n < cbs.length; } }
    if (all) all.addEventListener('change', function () { cbs.forEach(function (c) { c.checked = all.checked; }); upd(); });
    cbs.forEach(function (c) { c.addEventListener('change', upd); }); upd();
  }
  wire('mv-all', 'mv-cb', 'mv-count'); wire('mv-all-d', 'mv-cb-d', null);
})();
</script>
