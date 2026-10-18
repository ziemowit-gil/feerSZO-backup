<?php
/**
 * modules/ti_dop/view/settle_index.php — dokumenty rozliczeniowe: PRZ-P (stan początkowy), PRZ (ręczne przeliczenie godzin), RKO (rozliczenie końcowe).
 * Zmienne: $list, $f.
 */
require dirname(__DIR__, 2) . '/ti_licencje_dydaktyczne/view/_parts.php';
$title = 'Rozliczenia — PRZ-P, PRZ, RKO';
include __DIR__ . '/_head.php';
$stL = ['pending' => ['czeka na zatwierdzenie', '#f0a30a'], 'approved' => ['zatwierdzony', '#1ba1e2'], 'applied' => ['wykonany', '#008a00'], 'rejected' => ['odrzucony', '#a20025']];
?>
<div class="pivot"><a href="<?= h(route('dop.index')) ?>">Dokumenty rozliczeniowe</a><a href="<?= h(route('rop.index')) ?>">ROP</a><a href="<?= h(route('settle.index')) ?>" aria-current="page">Rozliczenia</a></div>
<div class="d-flex flex-wrap align-items-center gap-2 mb-2">
  <h1 class="me-auto mb-0">Dokumenty rozliczeniowe uczestnika</h1>
  <?php foreach (SettleKind::cases() as $k): ?><a class="btn btn-metro" style="background:<?= h($k->color()) ?>" href="<?= h(route('settle.new', ['kind' => $k->value] + ($f['client'] ? ['klient' => $f['client']] : []))) ?>"><?= h($k->value) ?> — <?= h($k->title()) ?></a><?php endforeach; ?>
</div>
<p class="text-secondary"><b>PRZ-P</b> ustala stan początkowy (godziny i saldo) — wymagany przed <b>PRZ</b> (ręczne przeliczenie godzin per miesiąc i grupa, z faktycznym rozliczeniem należności).
  <b>RKO</b> — rozliczenie końcowe grupy albo całego konta. Obieg do wyboru: z akceptacją kierownika i wysyłką do kursanta i rodzica albo bez akceptacji — tylko do akt.</p>
<div class="table-responsive panel p-0"><table class="table table-hover mb-0">
  <thead><tr><th>Numer</th><th>Uczestnik</th><th>Zakres</th><th>Obieg</th><th>Wystawiono</th><th>Stan</th></tr></thead><tbody>
  <?php if (!$list): ?><tr><td colspan="6" class="text-secondary p-3">Brak dokumentów.</td></tr><?php endif; ?>
  <?php foreach ($list as $r): [$sl, $sc] = $stL[$r['status']] ?? [$r['status'], '#647687']; ?>
    <tr><td class="text-nowrap"><span class="sym" style="background:<?= h(SettleKind::from($r['kind'])->color()) ?>"><?= h($r['kind']) ?></span> <a href="<?= h(route('settle.show', ['id' => $r['id']])) ?>"><?= h($r['number']) ?></a></td>
      <td><?= h($r['client_name']) ?></td><td class="small"><?= h(SettlementDocs::courseName((int)$r['course_id'])) ?></td><td class="small"><?= $r['mode'] === 'direct' ? 'bez akceptacji (akta)' : 'akceptacja + wysyłka' ?></td>
      <td class="small text-nowrap"><?= h(ldDt($r['created_at'])) ?></td><td><?= ldChip($sl, $sc) ?></td></tr>
  <?php endforeach; ?></tbody></table></div>
<?php include __DIR__ . '/_foot.php'; ?>
