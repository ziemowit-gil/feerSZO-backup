<?php
/**
 * modules/seeders/view/manage.php — Admin › Seedery › Zarządzanie (/seeder-test/zarzadzanie).
 * Wejście: $registry (seedersApplyMeta(…, true) — z ukrytymi), $groups (nazwy grup do podpowiedzi).
 * Każdy seeder = osobny formularz: nazwa, grupa, kolejność, ukrycie, opis administratora.
 */
?>
<?php include __DIR__ . '/styles.php'; ?>

<div class="sdr">
  <div class="sdr-head">
    <div>
      <p class="sdr-kicker">Admin · Narzędzia · Seedery</p>
      <h1 class="sdr-h1">Zarządzanie seederami</h1>
    </div>
    <a class="sdr-btn" href="<?= h(route('seeders.index')) ?>"><i class="bi bi-arrow-left" aria-hidden="true"></i>Wróć do seederów</a>
  </div>
  <p class="sdr-lead">Nazwa, grupa i kolejność nadpisują wartości z kodu (<code>modules/seeders/lib/seedersRegistry.php</code>) — puste pole = wartość domyślna.
    Opis administratora pojawia się na karcie seedera: napisz, do czego służy, kiedy go używać i jak posprzątać.
    Ukryty seeder znika z listy i nie da się go uruchomić. Skrypty, pola i akcje zmienia się w kodzie.</p>

  <?= flash_html() ?>

  <datalist id="sdr-groups"><?php foreach ($groups as $g): ?><option value="<?= h($g) ?>"><?php endforeach; ?></datalist>

  <?php $gColors = ['#0050ef', '#00807e', '#c0006a', '#6a00ff', '#c25100']; $gIdx = array_flip($groups);
  foreach ($registry as $key => $e): $m = $e['meta']; $k = $gColors[($gIdx[$e['group']] ?? 0) % count($gColors)]; ?>
  <form method="post" action="<?= h(route('seeders.save')) ?>" class="sdr-mrow<?= $e['hidden'] ? ' is-hidden-s' : '' ?>" id="m-<?= h($key) ?>" style="--k:<?= $k ?>">
    <?= Router::csrfField() ?>
    <input type="hidden" name="seeder" value="<?= h($key) ?>">
    <div>
      <h3><?= h($e['title']) ?> <?php if ($e['hidden']): ?><span class="sdr-tag">ukryty</span><?php endif; ?></h3>
      <p class="sdr-card-d"><code><?= h($e['script']) ?></code> · <?= h($e['group']) ?></p>
      <p class="sdr-card-d" style="margin-top:.35rem"><?= h($e['desc']) ?></p>
      <p class="sdr-run">
        <?php if (!empty($m['last_run_at'])): ?>
        Uruchomiono <?= (int)$m['run_count'] ?>× · ostatnio <?= h(date('d.m.Y H:i', strtotime($m['last_run_at']))) ?><?= !empty($m['last_run_by_name']) ? ' · ' . h($m['last_run_by_name']) : '' ?>
        <?= (int)$m['last_run_code'] === 0 ? '' : '· <span class="is-err">błąd, kod ' . (int)$m['last_run_code'] . '</span>' ?>
        <?php else: ?>Jeszcze nie uruchamiany ze strony.<?php endif; ?>
        <?php if (!empty($m['updated_at'])): ?><br>Ostatnia zmiana ustawień: <?= h(date('d.m.Y H:i', strtotime($m['updated_at']))) ?><?= !empty($m['updated_by_name']) ? ' · ' . h($m['updated_by_name']) : '' ?><?php endif; ?>
      </p>
    </div>
    <div class="sdr-form" style="padding:0">
      <div class="sdr-fields">
        <div class="sdr-f">
          <label for="m-<?= h($key) ?>-t">Nazwa</label>
          <input id="m-<?= h($key) ?>-t" name="m[title]" maxlength="120" value="<?= h((string)($m['title'] ?? '')) ?>" placeholder="<?= h($e['reg']['title']) ?>">
        </div>
        <div class="sdr-f">
          <label for="m-<?= h($key) ?>-g">Grupa</label>
          <input id="m-<?= h($key) ?>-g" name="m[group_name]" maxlength="60" list="sdr-groups" value="<?= h((string)($m['group_name'] ?? '')) ?>" placeholder="<?= h($e['reg']['group']) ?>">
        </div>
        <div class="sdr-f">
          <label for="m-<?= h($key) ?>-s">Kolejność w grupie</label>
          <input id="m-<?= h($key) ?>-s" name="m[sort]" type="number" min="0" max="999" value="<?= h((string)($m['sort'] ?? '')) ?>" placeholder="jak w kodzie">
        </div>
        <div class="sdr-f">
          <label for="m-<?= h($key) ?>-h">Widoczność</label>
          <select id="m-<?= h($key) ?>-h" name="m[hidden]">
            <option value="0"<?= $e['hidden'] ? '' : ' selected' ?>>widoczny</option>
            <option value="1"<?= $e['hidden'] ? ' selected' : '' ?>>ukryty</option>
          </select>
        </div>
      </div>
      <div class="sdr-f">
        <label for="m-<?= h($key) ?>-n">Opis administratora</label>
        <textarea id="m-<?= h($key) ?>-n" name="m[note]" maxlength="4000"
                  placeholder="Do czego, kiedy używać, na czym uważać, jak posprzątać…"><?= h($e['note']) ?></textarea>
      </div>
      <div class="sdr-actions">
        <button type="submit" name="op" value="save" class="sdr-btn sdr-btn--apply">Zapisz</button>
        <button type="submit" name="op" value="reset" class="sdr-btn" title="Nazwa, grupa, kolejność i widoczność wracają do wartości z kodu; opis zostaje">Przywróć domyślne</button>
      </div>
    </div>
  </form>
  <?php endforeach; ?>
</div>
