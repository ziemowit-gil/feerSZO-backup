<?php
/**
 * modules/seeders/view/index.php — Admin › Seedery (/seeder-test), szata Metro.
 * Wejście: $registry (seedersApplyMeta(seedersRegistry()) — bez ukrytych), $history (ostatnie uruchomienia z sesji),
 *         $last, $dbName, $hiddenN (ile ukrytych przez administratora).
 */
$groups = [];
foreach ($registry as $key => $e) $groups[$e['group']][$key] = $e;
$kindCls = ['preview' => 'sdr-btn', 'apply' => 'sdr-btn sdr-btn--apply', 'danger' => 'sdr-btn sdr-btn--danger'];
?>
<?php include __DIR__ . '/styles.php'; ?>

<div class="sdr">
  <div class="sdr-head">
    <div>
      <p class="sdr-kicker">Admin · Narzędzia</p>
      <h1 class="sdr-h1">Seedery — dane testowe</h1>
    </div>
    <a class="sdr-btn" href="<?= h(route('seeders.manage')) ?>"><i class="bi bi-sliders" aria-hidden="true"></i>Zarządzaj seederami<?= $hiddenN ? ' (ukryte: ' . (int)$hiddenN . ')' : '' ?></a>
  </div>
  <p class="sdr-lead">Skrypty z katalogu <code>cli/</code> zakładające (i sprzątające) dane do testów. Uruchamiają się w procesie strony —
    tak jak z terminala, z tymi samymi argumentami. Polecenie każdego uruchomienia widać przy wyniku.</p>

  <div class="sdr-warn" role="note">
    <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
    <div>Dane trafiają do <strong>bieżącej bazy</strong> (<code><?= h($dbName ?: '—') ?></code>, <code><?= h(APP_URL) ?></code>).
      Na produkcji zakładaj tylko to, co da się potem usunąć — przy każdym seederze jest akcja sprzątająca albo opis, jak usunąć dane.</div>
  </div>

  <?= flash_html() ?>

  <nav class="sdr-nav" aria-label="Grupy seederów">
    <?php if ($last): ?><a href="#wynik">Ostatni wynik</a><?php endif; ?>
    <?php foreach (array_keys($groups) as $g): ?><a href="#g-<?= h(md5($g)) ?>"><?= h($g) ?></a><?php endforeach; ?>
  </nav>

  <?php $gColors = ['#0050ef', '#00807e', '#c0006a', '#6a00ff', '#c25100']; ?>
  <div class="sdr-layout">
  <aside class="sdr-side" aria-label="Menu seederów">
    <label class="visually-hidden" for="sdr-q">Szukaj seedera</label>
    <input type="search" id="sdr-q" class="sdr-side-f" placeholder="Szukaj seedera…" autocomplete="off">
    <?php if ($last): ?><a class="sdr-side-res" href="#wynik"><i class="bi bi-terminal" aria-hidden="true"></i> Ostatni wynik</a><?php endif; ?>
    <?php $gi = 0; foreach ($groups as $gName => $items): $gk = $gColors[$gi++ % count($gColors)]; ?>
    <section style="--k:<?= $gk ?>" data-sdr-menu-group>
      <h2><a href="#g-<?= h(md5($gName)) ?>" style="padding:0;border:0;color:inherit;display:inline"><?= h($gName) ?></a></h2>
      <ul>
        <?php foreach ($items as $key => $e): ?>
        <li data-sdr-find="<?= h(mb_strtolower($e['title'] . ' ' . $e['script'] . ' ' . $e['desc'] . ' ' . $gName, 'UTF-8')) ?>"><a href="#s-<?= h($key) ?>" data-sdr-link="s-<?= h($key) ?>"><?= h($e['title']) ?></a></li>
        <?php endforeach; ?>
      </ul>
    </section>
    <?php endforeach; ?>
    <p class="sdr-none" id="sdr-none" role="status">Brak pasujących seederów.</p>
  </aside>
  <div class="sdr-main">

  <?php if ($history): ?>
  <section class="sdr-out" id="wynik" aria-labelledby="sdr-wynik-h">
    <h2 class="sdr-gh" id="sdr-wynik-h">Wynik</h2>
    <?php foreach ($history as $i => $hRow):
      $ok = $hRow['code'] === 0; $cliOnly = $hRow['code'] === null;
      $k = $cliOnly ? '#5f5f5f' : ($ok ? '#007a00' : '#a20025'); ?>
    <?php if ($i === 1): ?><details><summary>Wcześniejsze uruchomienia (<?= count($history) - 1 ?>)</summary><?php endif; ?>
    <div class="sdr-res" style="--k:<?= $k ?>">
      <div class="sdr-res-h">
        <strong><?= h($hRow['title']) ?> — <?= h($hRow['action']) ?></strong>
        <code><?= h($hRow['cmd']) ?></code>
        <span class="text-body-secondary small"><?= h($hRow['at']) ?><?= $hRow['ms'] ? ' · ' . number_format($hRow['ms'] / 1000, 1, ',', '') . ' s' : '' ?></span>
        <span class="sdr-res-st"><?= $cliOnly ? 'tylko terminal' : ($ok ? 'OK' : 'błąd · kod ' . (int)$hRow['code']) ?></span>
      </div>
      <pre class="sdr-pre"><?= h(trim($hRow['out']) !== '' ? $hRow['out'] : '(brak wyjścia)') ?></pre>
    </div>
    <?php endforeach; ?>
    <?php if (count($history) > 1): ?></details><?php endif; ?>
  </section>
  <?php endif; ?>

  <?php $gi = 0;
  foreach ($groups as $gName => $items): $gk = $gColors[$gi++ % count($gColors)]; ?>
  <section class="sdr-group" id="g-<?= h(md5($gName)) ?>" aria-labelledby="gh-<?= h(md5($gName)) ?>">
    <h2 class="sdr-gh" id="gh-<?= h(md5($gName)) ?>"><?= h($gName) ?></h2>
    <div class="sdr-grid">
      <?php foreach ($items as $key => $e): ?>
      <article class="sdr-card" id="s-<?= h($key) ?>" style="--k:<?= $gk ?>">
        <div class="sdr-card-h">
          <h3 class="sdr-card-t"><?= h($e['title']) ?> <code><?= h($e['script']) ?></code></h3>
          <p class="sdr-card-d"><?= h($e['desc']) ?></p>
          <?php if ($e['note'] !== ''): ?>
          <div class="sdr-note"><b>Opis administratora</b><?= nl2br(h($e['note'])) ?></div>
          <?php endif; ?>
          <?php if (!empty($e['meta']['last_run_at'])): $m = $e['meta']; ?>
          <p class="sdr-run">Uruchomiono <?= (int)$m['run_count'] ?>× · ostatnio <?= h(date('d.m.Y H:i', strtotime($m['last_run_at']))) ?>
            <?= !empty($m['last_run_by_name']) ? '· ' . h($m['last_run_by_name']) : '' ?> · <?= h((string)$m['last_run_action']) ?>
            <?= (int)$m['last_run_code'] === 0 ? '' : '· <span class="is-err">błąd, kod ' . (int)$m['last_run_code'] . '</span>' ?></p>
          <?php endif; ?>
        </div>
        <form class="sdr-form" method="post" action="<?= h(route('seeders.run')) ?>" autocomplete="off">
          <?= Router::csrfField() ?>
          <input type="hidden" name="seeder" value="<?= h($key) ?>">
          <?php if ($e['fields']): ?>
          <div class="sdr-fields">
            <?php foreach ($e['fields'] as $fn => $f): $id = 'f-' . $key . '-' . $fn; $t = $f['type'] ?? 'text'; ?>
            <div class="sdr-f">
              <label for="<?= h($id) ?>"><?= h($f['label']) ?></label>
              <?php if ($t === 'select'): ?>
              <select id="<?= h($id) ?>" name="f[<?= h($fn) ?>]">
                <?php foreach ($f['options'] as $ov => $ol): ?><option value="<?= h($ov) ?>"<?= (string)($f['default'] ?? '') === (string)$ov ? ' selected' : '' ?>><?= h($ol) ?></option><?php endforeach; ?>
              </select>
              <?php elseif ($t === 'check'): ?>
              <input type="checkbox" id="<?= h($id) ?>" name="f[<?= h($fn) ?>]" value="1" style="width:auto">
              <?php else: ?>
              <input id="<?= h($id) ?>" name="f[<?= h($fn) ?>]" type="<?= h($t) ?>" value="<?= h((string)($f['default'] ?? '')) ?>"<?= $t === 'password' ? ' autocomplete="new-password"' : '' ?>>
              <?php endif; ?>
              <?php if (!empty($f['help'])): ?><small><?= h($f['help']) ?></small><?php endif; ?>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
          <?php if (!empty($e['cli_only'])): ?>
          <div class="sdr-cli"><i class="bi bi-terminal" aria-hidden="true"></i> Uruchamia osobny proces PHP — przycisk pokaże gotowe polecenie do terminala.</div>
          <?php endif; ?>
          <div class="sdr-actions">
            <?php foreach ($e['actions'] as $a): $kind = $a['kind'] ?? 'apply'; ?>
            <button type="submit" name="action" value="<?= h($a['id']) ?>" class="<?= $kindCls[$kind] ?? 'sdr-btn' ?>"
              <?php if ($kind === 'danger' && empty($e['cli_only'])): ?>onclick="return confirm(<?= h(json_encode($e['title'] . ' — ' . $a['label'] . ': na pewno?', JSON_UNESCAPED_UNICODE)) ?>)"<?php endif; ?>>
              <?= h($a['label']) ?>
            </button>
            <?php endforeach; ?>
          </div>
        </form>
      </article>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endforeach; ?>
  </div><!-- /.sdr-main -->
  </div><!-- /.sdr-layout -->
</div>
<script>
// Menu boczne: filtr (karty + pozycje menu) i podświetlenie seedera widocznego na ekranie
(function () {
  var q = document.getElementById('sdr-q'), none = document.getElementById('sdr-none');
  var items = document.querySelectorAll('.sdr-side li[data-sdr-find]');
  if (q) q.addEventListener('input', function () {
    var t = q.value.trim().toLowerCase(), shown = 0;
    items.forEach(function (li) {
      var hit = !t || li.getAttribute('data-sdr-find').indexOf(t) !== -1;
      li.classList.toggle('is-hidden', !hit);
      var card = document.getElementById(li.querySelector('a').getAttribute('data-sdr-link'));
      if (card) card.classList.toggle('is-hidden', !hit);
      if (hit) shown++;
    });
    document.querySelectorAll('[data-sdr-menu-group]').forEach(function (g) {
      g.classList.toggle('is-hidden', !g.querySelector('li:not(.is-hidden)'));
    });
    document.querySelectorAll('.sdr-group').forEach(function (g) {
      g.classList.toggle('is-hidden', !g.querySelector('.sdr-card:not(.is-hidden)'));
    });
    none.style.display = shown ? 'none' : 'block';
  });
  if (!('IntersectionObserver' in window)) return;
  var links = {};
  document.querySelectorAll('[data-sdr-link]').forEach(function (a) { links[a.getAttribute('data-sdr-link')] = a; });
  var io = new IntersectionObserver(function (ents) {
    ents.forEach(function (en) {
      if (!en.isIntersecting) return;
      Object.keys(links).forEach(function (k) { links[k].removeAttribute('aria-current'); });
      var a = links[en.target.id]; if (a) a.setAttribute('aria-current', 'true');
    });
  }, { rootMargin: '-20% 0px -70% 0px' });
  document.querySelectorAll('.sdr-card[id]').forEach(function (c) { io.observe(c); });
})();

// Przycisk wysłany → blokada formularza do odpowiedzi (seeder może trwać kilkanaście sekund)
document.querySelectorAll('.sdr-form').forEach(function (f) {
  f.addEventListener('submit', function (ev) {
    var b = ev.submitter; if (!b) return;
    setTimeout(function () { f.querySelectorAll('button').forEach(function (x) { x.disabled = true; }); b.textContent = 'Uruchamiam…'; }, 0);
  });
});
</script>
