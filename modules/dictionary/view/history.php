<?php
/**
 * modules/dictionary/view/history.php — historia zmian słownika albo jednego wpisu. Zmienne: $dict, $rows, $entryId.
 * Wiersz: action, before_json, after_json, user_name, created_at, entry_code. Pokazujemy tylko pola, które się zmieniły.
 */
$PAGE_TITLE = 'Historia: ' . $dict->name;
$crumbs = ['Administracja' => APP_URL . '/admin/index.php', 'Słownik Centralny' => route('dict.index'),
           $dict->name => route('dict.show', ['code' => $dict->code]), 'Historia' => null];
include __DIR__ . '/_head.php';
$actionLabels = [
    'dict.create' => 'Utworzono słownik', 'dict.update' => 'Zmieniono słownik', 'dict.delete' => 'Słownik do kosza',
    'dict.restore' => 'Przywrócono słownik', 'dict.protect' => 'Zabezpieczono słownik', 'dict.unprotect' => 'Zdjęto zabezpieczenie',
    'entry.create' => 'Dodano wpis', 'entry.update' => 'Zmieniono wpis', 'entry.delete' => 'Wpis do kosza', 'entry.restore' => 'Przywrócono wpis',
];
$fmt = fn($x) => $x === null ? '—' : (is_bool($x) ? ($x ? 'tak' : 'nie') : (is_scalar($x) ? (string)$x : json_encode($x, JSON_UNESCAPED_UNICODE)));
?>
<?php if ($entryId): ?><p class="small">Pokazano zmiany jednego wpisu. <a href="<?= h(route('dict.history', ['code' => $dict->code])) ?>">Cała historia słownika</a></p><?php endif; ?>
<?php if (!$rows): ?>
  <div class="alert alert-light border">Brak zapisanych zmian.</div>
<?php else: ?>
<div class="table-responsive">
<table class="table table-sm align-middle">
  <caption class="visually-hidden">Historia zmian</caption>
  <thead><tr><th scope="col">Kiedy</th><th scope="col">Kto</th><th scope="col">Zdarzenie</th><th scope="col">Wpis</th><th scope="col">Zmiany</th></tr></thead>
  <tbody>
  <?php foreach ($rows as $r):
      $before = json_decode((string)$r['before_json'], true) ?: [];
      $after  = json_decode((string)$r['after_json'], true) ?: [];
      $diff = [];
      foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $k) {
          if ($k === 'version') continue;
          $b = $before[$k] ?? null; $a = $after[$k] ?? null;
          if ($b === [] ) $b = null; if ($a === []) $a = null;
          if ($fmt($b) !== $fmt($a)) $diff[$k] = [$b, $a];
      } ?>
    <tr>
      <td class="small text-nowrap"><?= h(date('d.m.Y H:i', strtotime((string)$r['created_at']))) ?></td>
      <td class="small"><?= h($r['user_name']) ?></td>
      <td class="small"><?= h($actionLabels[$r['action']] ?? $r['action']) ?></td>
      <td><span class="dict-code"><?= h((string)($r['entry_code'] ?? '')) ?></span><?= isset($after['version']) ? ' <span class="small text-body-secondary">v' . (int)$after['version'] . '</span>' : '' ?></td>
      <td class="small">
        <?php if ($before && $after): foreach ($diff as $k => [$b, $a]): ?>
          <div><span class="text-body-secondary"><?= h($k) ?>:</span> <del><?= h(mb_strimwidth($fmt($b), 0, 120, '…')) ?></del> → <ins><?= h(mb_strimwidth($fmt($a), 0, 120, '…')) ?></ins></div>
        <?php endforeach; if (!$diff): ?><span class="text-body-secondary">bez zmian treści</span><?php endif;
        elseif ($after): ?>
          <span class="text-body-secondary"><?= h(mb_strimwidth(($after['label'] ?? $after['name'] ?? '') . ' ' . $fmt($after['attributes'] ?? null), 0, 160, '…')) ?></span>
        <?php endif; ?>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>
<?php endif; ?>
<?php include __DIR__ . '/_foot.php'; ?>
