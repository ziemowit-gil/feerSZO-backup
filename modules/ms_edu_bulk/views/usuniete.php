<?php
/** Podgląd kont EDU usuniętych z Entra ID (tylko odczyt). Wejście: $delRows, $zaQ, $self. */
?>
<form method="get" class="d-flex gap-2 mb-3 flex-wrap">
  <input type="hidden" name="widok" value="usuniete">
  <input name="q" value="<?= h($zaQ) ?>" class="form-control form-control-sm" style="max-width:20rem" placeholder="Szukaj: login, imię, klasa, kto usunął…" aria-label="Szukaj">
  <button class="btn btn-sm btn-outline-secondary">Filtruj</button>
</form>
<div class="table-responsive"><table class="table table-sm align-middle" style="font-size:.85rem">
  <thead class="table-light"><tr><th>Osoba</th><th>Login</th><th>Entra ID</th><th>Rola</th><th>Klasa</th><th>Grupa (w chwili usunięcia)</th><th>Utworzono</th><th>Usunięto</th><th>Usunął</th></tr></thead>
  <tbody>
  <?php if (!$delRows): ?><tr><td colspan="9" class="text-center text-muted py-4">Brak usuniętych kont w rejestrze (rejestr zapełnia się od momentu wdrożenia — konta usunięte wcześniej nie są w nim ujęte).</td></tr><?php endif; ?>
  <?php foreach ($delRows as $r): ?>
    <tr>
      <td><?= h(trim($r['imie'] . ' ' . $r['nazwisko'])) ?><?= $r['anonim'] ? ' <span class="badge bg-secondary">anonim</span>' : '' ?></td>
      <td class="font-monospace"><?= h($r['upn']) ?></td>
      <td class="small font-monospace" title="<?= h($r['ms_user_id']) ?>"><?= $r['ms_user_id'] !== '' ? h(substr($r['ms_user_id'], 0, 8)) . '…' : '—' ?></td>
      <td class="small"><?= h($r['rola'] ?: '—') ?></td>
      <td class="small"><?= h($r['klasa'] ?: '—') ?></td>
      <td class="small"><?= h($r['grupa'] ?: '—') ?></td>
      <td class="text-muted small"><?= h(substr((string)$r['created_at'], 0, 16) ?: '—') ?></td>
      <td class="small"><span class="badge bg-danger">usunięte</span> <?= h(substr((string)$r['deleted_at'], 0, 16)) ?></td>
      <td class="small"><?= h($r['deleted_by'] ?: '—') ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
