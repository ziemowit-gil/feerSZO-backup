<?php
/**
 * Partial: „Moje konsultacje” — panel beneficjenta konsultacji (tryb 4, konto „k1234”).
 * Terminy konsultacji (rezerwacje k30_schedules: data, konsultant, miejsce, status, kwota) i karty konsultacji
 * (numer KON.XXX.MM.RRRR, etap Robocza → Podpisana → Zatwierdzona przez kierownika, PDF). Wymaga: $student.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/modules/ti_consultations/logic/consultations.php';
require_once dirname(dirname(dirname(__DIR__))) . '/modules/srs/logic/srs.php';
resources_migrate();   // sale (resources) — nazwa miejsca terminu
$kv_cid = (int)$student['client_id'];
$kv_terms = db_all(
    "SELECT s.start_time, s.duration_minutes, s.status, s.is_remote, s.amount_due, s.billing_type, u.name AS consultant, r.name AS room
       FROM k30_schedules s LEFT JOIN users u ON u.id = s.assigned_to LEFT JOIN resources r ON r.id = s.resource_id
      WHERE s.client_id = ? ORDER BY s.start_time DESC LIMIT 100", [$kv_cid]);
$kv_cards = db_all("SELECT co.*, u.name AS consultant_name FROM k30_consultations co LEFT JOIN users u ON u.id = co.consultant_id
                     WHERE co.client_id = ? AND co.status <> 'cancelled' ORDER BY co.consultation_datetime DESC", [$kv_cid]);
$kv_now = date('Y-m-d H:i:s');
?>
<h2 class="h5 fw-bold d-flex align-items-center gap-2 mb-3"><i class="bi bi-clipboard2-check text-primary" aria-hidden="true"></i>Moje konsultacje</h2>

<section class="mb-4" aria-labelledby="kv-t-h">
  <h3 class="h6 fw-bold" id="kv-t-h">Terminy</h3>
  <?php if (!$kv_terms): ?><p class="text-body-secondary small">Nie masz jeszcze zarezerwowanych terminów.</p><?php else: ?>
  <div class="table-responsive"><table class="table table-sm align-middle">
    <caption class="visually-hidden">Terminy konsultacji</caption>
    <thead><tr><th scope="col">Termin</th><th scope="col">Konsultant</th><th scope="col">Miejsce</th><th scope="col">Status</th><th scope="col" class="text-end">Kwota</th></tr></thead>
    <tbody><?php foreach ($kv_terms as $t): $st = K30_SCHEDULE_STATUSES[$t['status']] ?? ['label' => $t['status']]; ?>
      <tr<?= (string)$t['start_time'] >= $kv_now ? ' class="fw-semibold"' : '' ?>>
        <th scope="row" class="fw-normal text-nowrap"><?= h(date('d.m.Y H:i', strtotime((string)$t['start_time']))) ?> <span class="small text-body-secondary">(<?= (int)$t['duration_minutes'] ?> min)</span></th>
        <td><?= h($t['consultant'] ?: '—') ?></td>
        <td><?= (int)$t['is_remote'] || !$t['room'] ? 'zdalnie' : h($t['room']) ?></td>
        <td><?= h($st['label']) ?></td>
        <td class="text-end"><?= (float)$t['amount_due'] > 0 ? h(number_format((float)$t['amount_due'], 2, ',', ' ')) . ' zł' : (($t['billing_type'] ?? '') === 'pfron' ? 'PFRON' : 'bezpłatnie') ?></td>
      </tr>
    <?php endforeach; ?></tbody>
  </table></div>
  <?php endif; ?>
</section>

<section class="mb-4" aria-labelledby="kv-c-h">
  <h3 class="h6 fw-bold" id="kv-c-h">Karty konsultacji</h3>
  <p class="small text-body-secondary">Etapy karty: <strong>Robocza</strong> (konsultant ją wypełnia) → <strong>Podpisana</strong> (przez konsultanta) → <strong>Zatwierdzona przez kierownika</strong>.</p>
  <?php if (!$kv_cards): ?><p class="text-body-secondary small">Nie ma jeszcze kart konsultacji.</p><?php else: ?>
  <ul class="list-group">
    <?php foreach ($kv_cards as $k): ?>
    <li class="list-group-item d-flex flex-wrap align-items-center gap-2">
      <span class="font-monospace fw-semibold"><?= h($k['card_no']) ?></span>
      <span class="small"><?= h(date('d.m.Y H:i', strtotime((string)$k['consultation_datetime']))) ?><?= $k['consultant_name'] ? ' · ' . h($k['consultant_name']) : '' ?></span>
      <?= TiConsultations::stageBadge($k) ?>
      <a class="btn btn-sm btn-outline-primary ms-auto" href="konsultacjaPdf.php?id=<?= (int)$k['id'] ?>" target="_blank" rel="noopener"><i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>PDF<span class="visually-hidden"> karty <?= h($k['card_no']) ?> (nowa karta)</span></a>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
</section>
<p class="small"><a href="index.php?tab=rozliczenia"><i class="bi bi-receipt me-1" aria-hidden="true"></i>Rozliczenia i płatności</a></p>
