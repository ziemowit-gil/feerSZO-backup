<?php
/** Rejestr naruszeń (art. 33 ust. 5). Zmienne: $rows. */
$PAGE_TITLE = 'Naruszenia ochrony danych'; $active = 'rodo.breaches';
include __DIR__ . '/_head.php';
?>
<div class="d-flex justify-content-between mb-2"><p class="text-muted mb-0">Każde naruszenie trzeba odnotować (także niezgłaszane do UODO). Zgłoszenie — w ciągu 72 godzin od stwierdzenia, jeśli istnieje ryzyko naruszenia praw osób.</p>
  <a class="btn btn-danger btn-sm" href="<?= h(route('rodo.breach', ['id' => 0])) ?>"><i class="bi bi-plus-lg" aria-hidden="true"></i> Zgłoś naruszenie</a></div>
<div class="table-responsive"><table class="table table-sm align-middle"><caption class="visually-hidden">Naruszenia</caption>
<thead><tr><th scope="col">Nr</th><th scope="col">Wykryto</th><th scope="col">Ryzyko</th><th scope="col">Zgłoszenie do UODO do</th><th scope="col">Status</th></tr></thead><tbody>
<?php foreach ($rows as $b): ?><tr><td><a href="<?= h(route('rodo.breach', ['id' => $b['id']])) ?>">#<?= (int)$b['id'] ?></a></td><td><?= h($b['wykryto_at']) ?></td><td><?= h($b['ryzyko']) ?></td>
  <td><?= h($b['zgloszono_uodo_at'] ?: $b['uodo_do']) ?> <?= $b['uodo_po_terminie'] ? '<span class="badge text-bg-danger">po terminie</span>' : '' ?></td>
  <td><span class="badge <?= h(RODO_BREACH_STATUS[$b['status']][1]) ?>"><?= h(RODO_BREACH_STATUS[$b['status']][0]) ?></span></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="5" class="text-muted">Brak zarejestrowanych naruszeń.</td></tr><?php endif; ?>
</tbody></table></div>
<?php include __DIR__ . '/_foot.php'; ?>
