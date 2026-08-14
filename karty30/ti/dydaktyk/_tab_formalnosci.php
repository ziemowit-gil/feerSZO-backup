<?php /* ═══════════════════ TAB: FORMALNOŚCI ═══════════════════ */ ?>
<?php
  $ct_labels = ['wolontariat'=>'Porozumienie wolontariackie','zlecenie'=>'Umowa zlecenie','dzielo'=>'Umowa o dzieło','praca'=>'Umowa o pracę'];
  $st_labels = ['projekt'=>'Projekt','podpisana'=>'Podpisana','w realizacji'=>'W realizacji','zakończona'=>'Zakończona','rozwiązana'=>'Rozwiązana','anulowana'=>'Anulowana'];
  $st_colors = ['podpisana'=>'success','w realizacji'=>'primary','projekt'=>'secondary','zakończona'=>'dark','rozwiązana'=>'warning','anulowana'=>'danger'];
?>
<div class="mt-3">
  <h2 class="h5 fw-bold mb-3"><i class="bi bi-file-earmark-text text-primary me-2" aria-hidden="true"></i>Twoje formalności</h2>
  <?php if (!$dyd_contracts): ?>
  <div class="card border-0 shadow-sm"><div class="card-body text-body-secondary py-4 text-center">
    <i class="bi bi-file-earmark-x fs-2 d-block mb-2" aria-hidden="true"></i>
    Brak informacji z rejestru umów.
  </div></div>
  <?php endif; ?>
  <?php foreach ($dyd_contracts as $dc):
    $st    = $dc['status'] ?? '';
    $stc   = $st_colors[$st] ?? 'secondary';
    $is_active = in_array($st, ['podpisana','w realizacji'], true);
  ?>
  <div class="card border-0 shadow-sm mb-3 <?= $is_active ? '' : 'opacity-75' ?>">
    <div class="card-body">
      <div class="d-flex flex-wrap align-items-start gap-2 mb-3">
        <div>
          <div class="fw-bold fs-6"><?= h($dc['numer_umowy'] ?: '(brak numeru)') ?></div>
          <div class="text-body-secondary small"><?= h($ct_labels[$dc['contract_type']] ?? $dc['contract_type']) ?></div>
        </div>
        <span class="badge bg-<?= $stc ?> ms-auto align-self-start" style="font-size:.78rem"><?= h($st_labels[$st] ?? $st) ?></span>
      </div>
      <dl class="row row-cols-1 row-cols-sm-2 row-cols-md-3 g-2 mb-0" style="font-size:.88rem">
        <?php if (!empty($dc['imie_nazwisko'])): ?>
        <div class="col"><dt class="text-body-secondary fw-normal small">Imię i nazwisko</dt><dd class="mb-0 fw-semibold"><?= h($dc['imie_nazwisko']) ?></dd></div>
        <?php endif; ?>
        <?php if (!empty($dc['data_zawarcia'])): ?>
        <div class="col"><dt class="text-body-secondary fw-normal small">Data zawarcia</dt><dd class="mb-0"><?= date('d.m.Y', strtotime($dc['data_zawarcia'])) ?></dd></div>
        <?php endif; ?>
        <?php if (!empty($dc['data_zakonczenia'])): ?>
        <div class="col"><dt class="text-body-secondary fw-normal small">Ważna do</dt>
          <dd class="mb-0 <?= ($dc['data_zakonczenia'] < date('Y-m-d') && $is_active) ? 'text-danger fw-semibold' : '' ?>"><?= date('d.m.Y', strtotime($dc['data_zakonczenia'])) ?></dd></div>
        <?php endif; ?>
        <?php if (!empty($dc['stanowisko'])): ?>
        <div class="col"><dt class="text-body-secondary fw-normal small">Stanowisko / rola</dt><dd class="mb-0"><?= h($dc['stanowisko']) ?></dd></div>
        <?php endif; ?>
        <?php if (!empty($dc['miejsce_wolontariatu'])): ?>
        <div class="col"><dt class="text-body-secondary fw-normal small">Miejsce</dt><dd class="mb-0"><?= h($dc['miejsce_wolontariatu']) ?></dd></div>
        <?php endif; ?>
        <?php if (!empty($dc['przedmiot_porozumienia'])): ?>
        <div class="col col-12"><dt class="text-body-secondary fw-normal small">Zakres działania</dt><dd class="mb-0" style="white-space:pre-wrap"><?= h(mb_substr($dc['przedmiot_porozumienia'], 0, 400)) ?></dd></div>
        <?php endif; ?>
      </dl>
    </div>
  </div>
  <?php endforeach; ?>
  <p class="text-body-secondary small mt-2 mb-0">
    <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
    W razie pytań dotyczących umów skontaktuj się z koordynatorem.
  </p>
</div>
