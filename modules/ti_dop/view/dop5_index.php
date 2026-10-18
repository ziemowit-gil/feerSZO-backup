<?php
/** modules/ti_dop/view/dop5_index.php — lista oświadczeń DOP5 (stan środków przed transzą DOP2). Zmienne: $list. */
require dirname(__DIR__, 2) . '/ti_licencje_dydaktyczne/view/_parts.php';
$title = 'DOP5 — oświadczenia o stanie środków';
include __DIR__ . '/_head.php';
?>
<div class="pivot"><a href="<?= h(route('dop.index')) ?>">Dokumenty rozliczeniowe</a><a href="#" aria-current="page">DOP5</a></div>
<div class="d-flex flex-wrap align-items-center gap-2 mb-2"><h1 class="me-auto mb-0"><span class="sym" style="background:#1d1d1d">DOP5</span> Oświadczenia o stanie środków finansowych</h1>
  <a class="btn btn-metro" href="<?= h(route('dop5.new')) ?>">Nowe oświadczenie DOP5</a><a class="btn btn-outline-secondary" href="<?= h(route('dop5.blank')) ?>" target="_blank" rel="noopener">Wzór do druku</a></div>
<div class="table-responsive panel p-0"><table class="table table-hover mb-0"><thead><tr><th>Numer</th><th>Umowa / projekt</th><th class="text-end">Kwota</th><th>Na dzień</th><th>Stan</th></tr></thead><tbody>
<?php if (!$list): ?><tr><td colspan="5" class="text-secondary p-3">Brak oświadczeń DOP5.</td></tr><?php endif; ?>
<?php foreach ($list as $r): $d = json_decode((string)$r['data'], true) ?: []; ?><tr><td><a href="<?= h(route('dop5.show', ['id' => $r['id']])) ?>"><?= h($r['number']) ?></a></td><td class="small"><?= h(($d['umowa_nr'] ?? '') . ' — ' . ($d['tytul'] ?? '')) ?></td>
  <td class="text-end text-nowrap"><?= h(number_format((float)($d['kwota'] ?? 0), 2, ',', ' ')) ?> zł</td><td><?= h(ldDate($d['as_of'] ?? '')) ?></td>
  <td><?= ldChip(['signing' => 'czeka na podpisy', 'signed' => 'podpisane', 'cancelled' => 'wycofane'][$r['status']] ?? $r['status'], ['signing' => '#f0a30a', 'signed' => '#008a00', 'cancelled' => '#647687'][$r['status']] ?? '#647687') ?></td></tr><?php endforeach; ?>
</tbody></table></div>
<?php include __DIR__ . '/_foot.php'; ?>
