<?php
/**
 * modules/ti_dop/view/hub.php — strona startowa „Dokumenty rozliczeniowe uczestnika” (szata Metro): grupy kafli
 * Wnioski (DOP1, DOP2, DOP5), Stan konta (ROP), Rozliczenia (PRZ-P, PRZ, RKO) z licznikami „do zrobienia”,
 * kolejka spraw wymagających działania. Zmienne: $c (liczniki), $todo, $ropAuto.
 */
require dirname(__DIR__, 2) . '/ti_licencje_dydaktyczne/view/_parts.php';
$title = 'Dokumenty rozliczeniowe uczestnika';
include __DIR__ . '/_head.php';
$groups = [
  'Wnioski' => [
    ['DOP1', 'Zwrot nadpłaty', '#00aba9', $c['dop1'], route('dop.new', ['form' => 'DOP1']), route('dop.list', ['form' => 'DOP1'])],
    ['DOP2', 'Płatność z subkonta', '#6a00ff', $c['dop2'], route('dop.new', ['form' => 'DOP2']), route('dop.list', ['form' => 'DOP2'])],
    ['DOP5', 'Oświadczenie o stanie środków', '#1d1d1d', $c['dop5'], route('dop5.new'), route('dop5.index')],
  ],
  'Stan konta' => [
    ['ROP', 'Stan subkonta na dzień wpłaty', '#008a00', $c['rop'], route('rop.index'), route('rop.index')],
    ['RKS', SettleKind::Rks->title() . ' (kierownik)', '#60a917', $c['RKS'] ?? 0, route('settle.new', ['kind' => 'RKS']), route('settle.index', ['kind' => 'RKS'])],
  ],
  'Rozliczenia okresowe' => array_map(fn(SettleKind $k) => [$k->value, $k->title(), $k->color(), $c[$k->value] ?? 0, route('settle.new', ['kind' => $k->value]), route('settle.index', ['kind' => $k->value])], [SettleKind::Rm, SettleKind::Rmr, SettleKind::Rr, SettleKind::Rrr]),
  'Stan początkowy, przeliczenia i zamknięcie' => array_map(fn(SettleKind $k) => [$k->value, $k->title(), $k->color(), $c[$k->value] ?? 0, route('settle.new', ['kind' => $k->value]), route('settle.index', ['kind' => $k->value])], [SettleKind::Przp, SettleKind::Prz, SettleKind::Rko]),
];
?>
<h1 class="mb-1">Dokumenty rozliczeniowe uczestnika</h1>
<p class="text-secondary">Wnioski, stany konta i rozliczenia kursanta — z podpisem elektronicznym, realizacją w księdze i kopią w teczce „Dokumenty kursanta”. Termin realizacji: do <?= LD_REALIZATION_DAYS ?> dni.</p>
<style>
  .hub-g h2 { font-size: 1.1rem; text-transform: uppercase; letter-spacing: .06em; color: #647687; margin: 1.2rem 0 .5rem; }
  .hub-t { display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 8px; }
  .hub-c { color: #fff; padding: .9rem 1rem .7rem; min-height: 150px; display: flex; flex-direction: column; }
  .hub-c .sy { font-size: 2rem; font-weight: 300; line-height: 1; }
  .hub-c .ti { font-size: .95rem; margin-top: .25rem; }
  .hub-c .cn { margin-top: auto; font-size: .8rem; text-transform: uppercase; letter-spacing: .04em; opacity: .95; }
  .hub-c .ac { display: flex; gap: 6px; margin-top: .5rem; }
  .hub-c .ac a { color: #fff !important; text-decoration: none !important; border: 2px solid rgba(255,255,255,.85); padding: .15rem .6rem; font-size: .85rem; font-weight: 600; }
  .hub-c .ac a:hover, .hub-c .ac a:focus { background: rgba(255,255,255,.2); outline: 2px solid #fff; }
</style>
<?php foreach ($groups as $gName => $tiles): ?>
<section class="hub-g" aria-label="<?= h($gName) ?>"><h2><?= h($gName) ?></h2><div class="hub-t">
  <?php foreach ($tiles as [$sym, $t, $col, $n, $new, $list]): ?>
    <div class="hub-c" style="background:<?= h($col) ?>">
      <div class="sy"><?= h($sym) ?></div><div class="ti"><?= h($t) ?></div>
      <div class="cn"><?= $sym === 'ROP' ? (int)$n . ' w ostatnich 30 dniach' . ($ropAuto ? ' · automat włączony' : ' · automat wyłączony') : ((int)$n > 0 ? (int)$n . ' do zrobienia' : 'nic do zrobienia') ?></div>
      <div class="ac"><?php if ($sym !== 'ROP'): ?><a href="<?= h($new) ?>">Nowy</a><?php endif; ?><a href="<?= h($list) ?>"><?= $sym === 'ROP' ? 'Wystaw / lista' : 'Lista' ?></a></div>
    </div>
  <?php endforeach; ?>
</div></section>
<?php endforeach; ?>
<section class="panel mt-4"><h2 class="mt-0">Do zrobienia (<?= count($todo) ?>)</h2>
  <?php if (!$todo): ?><p class="text-secondary mb-0">Brak spraw wymagających działania.</p><?php else: ?>
  <div class="table-responsive"><table class="table table-sm mb-0"><thead><tr><th>Dokument</th><th>Uczestnik / podmiot</th><th>Co dalej</th><th>Termin</th></tr></thead><tbody>
  <?php foreach ($todo as $t): ?><tr><td class="text-nowrap"><span class="sym" style="background:<?= h($t['color']) ?>"><?= h($t['sym']) ?></span> <a href="<?= h($t['url']) ?>"><?= h($t['number']) ?></a></td><td><?= h($t['who']) ?></td><td><?= h($t['what']) ?></td>
    <td class="<?= $t['due'] !== '' && $t['due'] < ldToday() ? 'text-danger fw-semibold' : '' ?>"><?= h(ldDate($t['due']) ?: '—') ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?>
</section>
<?php include __DIR__ . '/_foot.php'; ?>
