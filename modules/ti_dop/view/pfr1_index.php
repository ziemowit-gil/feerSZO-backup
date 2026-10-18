<?php
/**
 * modules/ti_dop/view/pfr1_index.php — PFR1 (informacja o szkoleniu / konsultacji ze środków PFRON): lista i nowy dokument.
 * Prowadzący widzi tylko wystawione przez siebie. Zmienne: $list.
 */
require dirname(__DIR__, 2) . '/ti_licencje_dydaktyczne/view/_parts.php';
$title = 'PFR1 — informacja o szkoleniu lub konsultacji ze środków PFRON';
include __DIR__ . '/_head.php';
$stL = ['pending' => ['czeka na zatwierdzenie', '#f0a30a'], 'approved' => ['zatwierdzony', '#1ba1e2'], 'applied' => ['wykonany', '#008a00'], 'rejected' => ['odrzucony', '#a20025']];
?>
<div class="d-flex flex-wrap align-items-center gap-2 mb-2">
  <h1 class="me-auto mb-0"><span class="sym" style="background:<?= h(SettleKind::Pfr1->color()) ?>">PFR1</span> Informacja o szkoleniu lub konsultacji ze środków PFRON</h1>
  <a class="btn btn-metro" href="<?= h(route('pfr1.new')) ?>">Wystaw PFR1</a>
</div>
<p class="text-secondary">Informacja dla uczestnika (i rodzica), że szkolenie albo konsultacja są finansowane ze środków PFRON — temat, okres, godziny i wykaz zajęć z grafiku. <?= dyd_is_staff() ? '' : 'Wystawiasz ją dla uczestników ze swoich grup; w trybie z akceptacją kierownik zatwierdza i system wysyła PDF.' ?></p>
<div class="table-responsive panel p-0"><table class="table table-hover mb-0">
  <thead><tr><th>Numer</th><th>Uczestnik</th><th>Temat</th><th>Wystawił(a)</th><th>Stan</th></tr></thead><tbody>
  <?php if (!$list): ?><tr><td colspan="5" class="text-secondary p-3">Brak dokumentów PFR1.</td></tr><?php endif; ?>
  <?php foreach ($list as $r): $d = json_decode((string)$r['data'], true) ?: []; [$sl, $sc] = $stL[$r['status']] ?? [$r['status'], '#647687']; ?>
    <tr><td><a href="<?= h(route('pfr1.show', ['id' => $r['id']])) ?>"><?= h($r['number']) ?></a></td><td><?= h($r['client_name']) ?></td><td class="small"><?= h($d['temat'] ?? '') ?></td>
      <td class="small"><?= h($r['created_name']) ?>, <?= h(ldDt($r['created_at'])) ?></td><td><?= ldChip($sl, $sc) ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
<?php include __DIR__ . '/_foot.php'; ?>
