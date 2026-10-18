<?php
/**
 * modules/seeders/view/index.php — Admin › Seedery (/seeder-test), szata Metro.
 * Wejście: $registry (seedersRegistry()), $history (ostatnie uruchomienia z sesji), $last, $dbName.
 */
$groups = [];
foreach ($registry as $key => $e) $groups[$e['group']][$key] = $e;
$kindCls = ['preview' => 'sdr-btn', 'apply' => 'sdr-btn sdr-btn--apply', 'danger' => 'sdr-btn sdr-btn--danger'];
?>
<style>
.sdr{max-width:1280px;margin:0 auto;font-family:"Segoe UI",system-ui,-apple-system,Roboto,Arial,sans-serif;color:#1d1d1d}
.sdr *{border-radius:0!important}
.sdr-kicker{font-size:.7rem;font-weight:700;letter-spacing:.16em;text-transform:uppercase;color:#5f5f5f;margin:0 0 .35rem}
.sdr-h1{font-size:2.4rem;font-weight:300;letter-spacing:-.02em;line-height:1.1;margin:0 0 .5rem}
.sdr-lead{color:#3f3f3f;max-width:62rem;margin:0 0 1rem}
.sdr-warn{display:flex;gap:.75rem;align-items:flex-start;background:#f2f2f2;border-left:6px solid #c25100;padding:.8rem 1rem;margin:0 0 1.5rem}
.sdr-warn .bi{color:#c25100;font-size:1.2rem}
.sdr-warn code{background:#fff;padding:.05rem .3rem;color:#1d1d1d}
.sdr-nav{display:flex;flex-wrap:wrap;gap:0;border-bottom:1px solid #e1e1e1;margin:0 0 1.5rem}
.sdr-nav a{padding:.5rem .9rem .45rem;border-bottom:3px solid transparent;color:#5f5f5f;text-decoration:none;font-size:1.02rem;font-weight:300}
.sdr-nav a:hover{background:#f2f2f2;color:#1d1d1d}
.sdr-group{margin:0 0 2rem}
.sdr-gh{font-size:1.5rem;font-weight:300;letter-spacing:-.01em;margin:0 0 .75rem;padding:0 0 .4rem;border-bottom:2px solid #1d1d1d}
.sdr-grid{display:grid;gap:.75rem;grid-template-columns:repeat(auto-fill,minmax(360px,1fr))}
.sdr-card{display:flex;flex-direction:column;background:#fff;border:1px solid #e1e1e1;border-top:4px solid var(--k,#0050ef)}
.sdr-card-h{padding:.8rem 1rem .4rem}
.sdr-card-t{font-size:1.1rem;font-weight:600;margin:0 0 .25rem;display:flex;gap:.5rem;align-items:baseline}
.sdr-card-t code{font-size:.68rem;font-weight:400;color:#5f5f5f;background:#f2f2f2;padding:.05rem .35rem}
.sdr-card-d{font-size:.84rem;color:#3f3f3f;margin:0}
.sdr-form{padding:.4rem 1rem 1rem;display:flex;flex-direction:column;gap:.6rem;flex:1}
.sdr-fields{display:grid;gap:.5rem .75rem;grid-template-columns:repeat(auto-fill,minmax(150px,1fr))}
.sdr-f label{display:block;font-size:.66rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:#5f5f5f;margin:0 0 .2rem}
.sdr-f input,.sdr-f select{width:100%;border:2px solid #8a8a8a;padding:.35rem .5rem;font:inherit;font-size:.88rem;background:#fff;color:#1d1d1d}
.sdr-f input:focus,.sdr-f select:focus{border-color:#0050ef;outline:0}
.sdr-f small{display:block;font-size:.7rem;color:#5f5f5f;margin-top:.15rem}
.sdr-actions{display:flex;flex-wrap:wrap;gap:.35rem;margin-top:auto}
.sdr-btn{border:2px solid #1d1d1d;background:#fff;color:#1d1d1d;font-weight:600;font-size:.84rem;padding:.35rem .8rem;cursor:pointer}
.sdr-btn:hover{background:#1d1d1d;color:#fff}
.sdr-btn--apply{background:#0050ef;border-color:#0050ef;color:#fff}
.sdr-btn--apply:hover{background:#1d1d1d;border-color:#1d1d1d}
.sdr-btn--danger{border-color:#a20025;color:#a20025}
.sdr-btn--danger:hover{background:#a20025;color:#fff}
.sdr-btn:focus-visible{outline:3px solid #fbbf24;outline-offset:2px}
.sdr-cli{font-size:.74rem;color:#5f5f5f;background:#f2f2f2;padding:.4rem .6rem;border-left:4px solid #8a8a8a}
.sdr-out{margin:0 0 2rem}
.sdr-res{border:1px solid #e1e1e1;margin:0 0 .75rem}
.sdr-res-h{display:flex;flex-wrap:wrap;gap:.25rem 1rem;align-items:center;padding:.6rem .9rem;background:#f2f2f2;border-left:6px solid var(--k,#5f5f5f)}
.sdr-res-h strong{font-weight:600}
.sdr-res-h code{font-size:.78rem;color:#1d1d1d;background:#fff;padding:.1rem .4rem;word-break:break-all}
.sdr-res-st{margin-left:auto;font-size:.72rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#fff;background:var(--k,#5f5f5f);padding:.2rem .5rem}
.sdr-pre{margin:0;max-height:420px;overflow:auto;background:#1d1d1d;color:#e6e6e6;font-size:.78rem;line-height:1.45;padding:.8rem 1rem;white-space:pre-wrap;word-break:break-word}
.sdr details>summary{cursor:pointer;color:#003bb0;font-weight:600;margin:0 0 .5rem}
</style>

<div class="sdr">
  <p class="sdr-kicker">Admin · Narzędzia</p>
  <h1 class="sdr-h1">Seedery — dane testowe</h1>
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

  <?php $gColors = ['#0050ef', '#00807e', '#c0006a', '#6a00ff', '#c25100']; $gi = 0;
  foreach ($groups as $gName => $items): $gk = $gColors[$gi++ % count($gColors)]; ?>
  <section class="sdr-group" id="g-<?= h(md5($gName)) ?>" aria-labelledby="gh-<?= h(md5($gName)) ?>">
    <h2 class="sdr-gh" id="gh-<?= h(md5($gName)) ?>"><?= h($gName) ?></h2>
    <div class="sdr-grid">
      <?php foreach ($items as $key => $e): ?>
      <article class="sdr-card" id="s-<?= h($key) ?>" style="--k:<?= $gk ?>">
        <div class="sdr-card-h">
          <h3 class="sdr-card-t"><?= h($e['title']) ?> <code><?= h($e['script']) ?></code></h3>
          <p class="sdr-card-d"><?= h($e['desc']) ?></p>
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
</div>
<script>
// Przycisk wysłany → blokada formularza do odpowiedzi (seeder może trwać kilkanaście sekund)
document.querySelectorAll('.sdr-form').forEach(function (f) {
  f.addEventListener('submit', function (ev) {
    var b = ev.submitter; if (!b) return;
    setTimeout(function () { f.querySelectorAll('button').forEach(function (x) { x.disabled = true; }); b.textContent = 'Uruchamiam…'; }, 0);
  });
});
</script>
