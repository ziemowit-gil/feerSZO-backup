<?php /* ═══════════════════ TAB: FORMALNOŚCI IS ═══════════════════
 * Wymaga: $row (umowa_zlecenie), $id, $TYPE='zlecenie'.
 * Renderowany tylko gdy $row['w_ramach_is'] = 1.
 */ ?>
<div class="tab-pane fade<?php if($_tab==='formalnosci') echo ' show active'; ?>" id="tab-formalnosci" role="tabpanel">
<div class="cv-section">

  <h2 class="h5 fw-bold mb-3"><i class="bi bi-building-check text-primary me-2" aria-hidden="true"></i>Formalności — Instytucja Szkoleniowa</h2>

  <!-- ── Dane IS ── -->
  <div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-transparent fw-semibold">
      <i class="bi bi-building text-primary me-2" aria-hidden="true"></i>Dane Instytucji Szkoleniowej
    </div>
    <div class="card-body">
      <dl class="row row-cols-1 row-cols-sm-2 g-2 mb-0" style="font-size:.9rem">
        <div class="col">
          <dt class="text-body-secondary fw-normal small">Nazwa IS</dt>
          <dd class="mb-0 fw-semibold"><?= !empty($row['is_nazwa']) ? h($row['is_nazwa']) : '<span class="text-muted">—</span>' ?></dd>
        </div>
        <div class="col">
          <dt class="text-body-secondary fw-normal small">Nr umowy z IS</dt>
          <dd class="mb-0"><?= !empty($row['is_numer_umowy']) ? h($row['is_numer_umowy']) : '<span class="text-muted">—</span>' ?></dd>
        </div>
        <div class="col">
          <dt class="text-body-secondary fw-normal small">Numer projektu</dt>
          <dd class="mb-0"><?= !empty($row['numer_projektu']) ? h($row['numer_projektu']) : '<span class="text-muted">—</span>' ?></dd>
        </div>
        <?php if (!empty($row['is_adres'])): ?>
        <div class="col col-sm-12">
          <dt class="text-body-secondary fw-normal small">Adres IS</dt>
          <dd class="mb-0" style="white-space:pre-wrap"><?= h($row['is_adres']) ?></dd>
        </div>
        <?php endif; ?>
      </dl>
    </div>
  </div>

  <!-- ── Umowa ── -->
  <div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-transparent fw-semibold">
      <i class="bi bi-file-earmark-text text-primary me-2" aria-hidden="true"></i>Dane umowy zlecenie
    </div>
    <div class="card-body">
      <dl class="row row-cols-1 row-cols-sm-2 row-cols-md-3 g-2 mb-0" style="font-size:.9rem">
        <div class="col">
          <dt class="text-body-secondary fw-normal small">Numer umowy</dt>
          <dd class="mb-0 fw-semibold"><?= h($row['numer_umowy'] ?: '—') ?></dd>
        </div>
        <?php if (!empty($row['imie_nazwisko'])): ?>
        <div class="col">
          <dt class="text-body-secondary fw-normal small">Zleceniobiorca</dt>
          <dd class="mb-0"><?= h($row['imie_nazwisko']) ?></dd>
        </div>
        <?php endif; ?>
        <?php if (!empty($row['data_zawarcia'])): ?>
        <div class="col">
          <dt class="text-body-secondary fw-normal small">Data zawarcia</dt>
          <dd class="mb-0"><?= date('d.m.Y', strtotime($row['data_zawarcia'])) ?></dd>
        </div>
        <?php endif; ?>
        <?php if (!empty($row['data_zakonczenia'])): ?>
        <div class="col">
          <dt class="text-body-secondary fw-normal small">Ważna do</dt>
          <dd class="mb-0 <?= $row['data_zakonczenia'] < date('Y-m-d') ? 'text-danger fw-semibold' : '' ?>">
            <?= date('d.m.Y', strtotime($row['data_zakonczenia'])) ?>
          </dd>
        </div>
        <?php endif; ?>
        <?php if (!empty($row['wynagrodzenie_brutto'])): ?>
        <div class="col">
          <dt class="text-body-secondary fw-normal small">Wynagrodzenie brutto</dt>
          <dd class="mb-0"><?= number_format((float)$row['wynagrodzenie_brutto'], 2, ',', ' ') ?> zł</dd>
        </div>
        <?php endif; ?>
        <?php if (!empty($row['przedmiot_zlecenia'])): ?>
        <div class="col col-sm-12">
          <dt class="text-body-secondary fw-normal small">Przedmiot zlecenia</dt>
          <dd class="mb-0" style="white-space:pre-wrap"><?= h(mb_substr($row['przedmiot_zlecenia'], 0, 500)) ?></dd>
        </div>
        <?php endif; ?>
      </dl>
    </div>
  </div>

  <!-- ── Kwalifikacje zleceniobiorcy ── -->
  <?php if (!empty($row['is_uprawnienia_nr']) || !empty($row['is_dyplom_nr']) || !empty($row['is_dopuszczenie'])): ?>
  <div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-transparent fw-semibold">
      <i class="bi bi-patch-check text-primary me-2" aria-hidden="true"></i>Kwalifikacje zleceniobiorcy
    </div>
    <div class="card-body">
      <dl class="row row-cols-1 row-cols-sm-3 g-2 mb-0" style="font-size:.9rem">
        <?php if (!empty($row['is_uprawnienia_nr'])): ?>
        <div class="col">
          <dt class="text-body-secondary fw-normal small">Nr uprawnień</dt>
          <dd class="mb-0 fw-semibold"><?= h($row['is_uprawnienia_nr']) ?></dd>
        </div>
        <?php endif; ?>
        <?php if (!empty($row['is_dyplom_nr'])): ?>
        <div class="col">
          <dt class="text-body-secondary fw-normal small">Nr dyplomu</dt>
          <dd class="mb-0"><?= h($row['is_dyplom_nr']) ?></dd>
        </div>
        <?php endif; ?>
        <?php if (!empty($row['is_dopuszczenie'])): ?>
        <div class="col">
          <dt class="text-body-secondary fw-normal small">Dopuszczenie / uwagi</dt>
          <dd class="mb-0"><?= h($row['is_dopuszczenie']) ?></dd>
        </div>
        <?php endif; ?>
      </dl>
    </div>
  </div>
  <?php endif; ?>

  <!-- ── Klauzule / zgody ── -->
  <div class="card border-0 shadow-sm mb-3">
    <div class="card-header bg-transparent fw-semibold">
      <i class="bi bi-shield-check text-primary me-2" aria-hidden="true"></i>Klauzule i zgody
    </div>
    <div class="card-body">
      <ul class="list-unstyled mb-0" style="font-size:.9rem">
        <?php
          $checks = [
              'klauzula_rodo' => 'Klauzula informacyjna RODO',
          ];
          foreach ($checks as $field => $label):
            $ok = !empty($row[$field]);
        ?>
        <li class="d-flex align-items-center gap-2 py-1">
          <i class="bi bi-<?= $ok ? 'check-circle-fill text-success' : 'x-circle text-danger' ?>" aria-hidden="true"></i>
          <span><?= h($label) ?></span>
          <?php if (!$ok): ?><span class="badge bg-warning text-dark ms-1" style="font-size:.7rem">Brak</span><?php endif; ?>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>

  <?php if (can_write('contracts') || is_admin()): ?>
  <div class="text-end no-print">
    <a href="edit.php?id=<?= $id ?>" class="btn btn-sm btn-outline-primary">
      <i class="bi bi-pencil me-1" aria-hidden="true"></i>Edytuj dane IS
    </a>
  </div>
  <?php endif; ?>

</div>
</div><!-- /tab-formalnosci -->
