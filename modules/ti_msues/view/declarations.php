<?php
/**
 * modules/ti_msues/view/declarations.php — wszystkie oświadczenia kadry z filtrami (status, usługa, nazwisko).
 * Zmienne: $f, $rows, $counts, $services, $actor. Kontroler: MsuesController::declarations.
 */
require __DIR__ . '/_parts.php';
$title = 'Oświadczenia kadry (MSUES)';
$kierLabel = 'Certyfikacja MSUES';
include __DIR__ . '/_head.php';
?>
<p class="mb-2"><a href="<?= h(route('msues.index')) ?>">&larr; Certyfikacja MSUES</a></p>
<h1 class="h4">Oświadczenia o kwalifikacjach i kompetencjach</h1>
<form method="get" class="row g-2 align-items-end mb-3">
  <input type="hidden" name="_route" value="/oswiadczenia">
  <div class="col-md-3"><label class="form-label small mb-0" for="fS">Status</label><select id="fS" name="status" class="form-select form-select-sm"><option value="">w obiegu i zatwierdzone</option>
    <?php foreach (DeclarationStatus::cases() as $c): ?><option value="<?= h($c->value) ?>"<?= $f['status'] === $c->value ? ' selected' : '' ?>><?= h($c->label()) ?> (<?= (int)($counts[$c->value] ?? 0) ?>)</option><?php endforeach; ?></select></div>
  <div class="col-md-4"><label class="form-label small mb-0" for="fC">Usługa</label><select id="fC" name="course" class="form-select form-select-sm"><option value="">wszystkie</option>
    <?php foreach ($services as $s): ?><option value="<?= (int)$s['id'] ?>"<?= (int)$f['course'] === (int)$s['id'] ? ' selected' : '' ?>><?= h(MsuesSzoData::courseLabel($s)) ?></option><?php endforeach; ?></select></div>
  <div class="col-md-3"><label class="form-label small mb-0" for="fQ">Prowadzący</label><input id="fQ" name="q" value="<?= h($f['q']) ?>" class="form-control form-control-sm"></div>
  <div class="col-md-2"><button class="btn btn-sm btn-primary w-100">Filtruj</button></div>
</form>
<div class="table-responsive"><table class="table table-sm table-hover">
  <thead><tr><th>Prowadzący</th><th>Usługa</th><th>Wersja</th><th>Status</th><th>Skan</th><th>EZD</th><th>Zmiana</th><th></th></tr></thead>
  <tbody><?php foreach ($rows as $d): ?>
    <tr><td><a href="<?= h(route('msues.trainer', ['id' => $d['trainer_id']])) ?>"><?= h($d['trainer_name']) ?></a></td><td><?= h(msuesCourseName($d)) ?></td>
      <td>v<?= (int)$d['version'] ?></td><td><?= msuesBadge($d['statusEnum']) ?></td><td><?= $d['scan_path'] !== '' ? '<i class="bi bi-check-lg ok"></i>' : '—' ?></td>
      <td><?= $d['ezd_sprawa_id'] ? '<a href="' . h(MsuesEzdService::url((int)$d['ezd_sprawa_id'])) . '" target="_blank" rel="noopener">koszulka</a>' : '—' ?></td>
      <td class="small"><?= h(substr((string)$d['updated_at'], 0, 16)) ?></td>
      <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= h(route('msues.decl', ['id' => $d['id']])) ?>">Otwórz</a></td></tr>
  <?php endforeach; if (!$rows): ?><tr><td colspan="8" class="text-muted">Brak oświadczeń.</td></tr><?php endif; ?></tbody>
</table></div>
<?php include __DIR__ . '/_foot.php'; ?>
