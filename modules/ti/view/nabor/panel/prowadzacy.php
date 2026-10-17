<?php
/**
 * modules/ti/view/nabor/panel/prowadzacy.php — prowadzący: przyjęci z naboru uczestnicy własnych grup (dane zawężone).
 * Zmienne: $me, $courses, $byCourse, $staff. Kontroler: NaborPanelController::instructor.
 */
$title = 'Nowi uczestnicy z naboru';
$kierLabel = 'Nowi uczestnicy z naboru';
include __DIR__ . '/_head.php';
?>
<h4 class="mb-1"><i class="bi bi-person-video3 text-primary"></i> Nowi uczestnicy z naboru<?= $staff ? ' — wszystkie grupy' : '' ?></h4>
<p class="text-muted small mb-3">Uwagi medyczne i zasady odbioru podał opiekun w zgłoszeniu. Telefon opiekuna służy do kontaktu w nagłych sytuacjach.</p>
<?= flash_html() ?>
<?php if (!$courses): ?><div class="alert alert-info">W Twoich grupach nie ma jeszcze uczestników przyjętych z naboru.</div><?php endif; ?>
<?php foreach ($courses as $c): $list = $byCourse[(int)$c['id']] ?? []; ?>
<section class="card mb-4" aria-labelledby="g<?= (int)$c['id'] ?>">
  <div class="card-header d-flex justify-content-between flex-wrap gap-2">
    <h5 id="g<?= (int)$c['id'] ?>" class="mb-0"><?= h($c['title']) ?> <small class="text-muted"><?= h($c['schedule']) ?></small></h5>
    <span class="small text-muted"><?= count($list) ?> z naboru<?= $staff && $c['instructor_name'] ? ' · ' . h($c['instructor_name']) : '' ?></span>
  </div>
  <div class="table-responsive"><table class="table table-sm mb-0 align-middle">
    <thead><tr><th>#</th><th>Uczestnik</th><th>Szkoła / klasa</th><th>Uwagi medyczne / dostępność</th><th>Odbiór</th><th>Kontakt w nagłych sytuacjach</th></tr></thead>
    <tbody>
    <?php foreach ($list as $i => $r): ?>
      <tr>
        <td><?= $i + 1 ?></td>
        <td class="fw-semibold"><?= h($r['participant_name']) ?></td>
        <td><?= h($r['participant_school_class']) ?></td>
        <td class="small"><?= $r['participant_medical_notes'] !== '' ? '<span class="text-danger-emphasis">' . nl2br(h($r['participant_medical_notes'])) . '</span>' : '—' ?></td>
        <td class="small"><?= h(NABOR_PICKUP[$r['pickup_type']] ?? '') ?><?= $r['authorized_persons_details'] !== '' ? '<div class="text-muted">' . nl2br(h($r['authorized_persons_details'])) . '</div>' : '' ?></td>
        <td class="small text-nowrap"><?= h($r['guardian_name']) ?><br><a href="tel:+<?= h($r['guardian_phone']) ?>">+<?= h($r['guardian_phone']) ?></a></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>
<?php endforeach; ?>
<?php include __DIR__ . '/_foot.php'; ?>
