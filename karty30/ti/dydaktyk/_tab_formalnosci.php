<?php /* ═══════════════════ TAB: FORMALNOŚCI ═══════════════════ */ ?>
<?php
  $ct_labels = ['wolontariat'=>'Porozumienie wolontariackie','zlecenie'=>'Umowa zlecenie','dzielo'=>'Umowa o dzieło','praca'=>'Umowa o pracę'];
  $st_labels = ['projekt'=>'Projekt','podpisana'=>'Podpisana','w realizacji'=>'W realizacji','zakończona'=>'Zakończona','rozwiązana'=>'Rozwiązana','anulowana'=>'Anulowana'];
  $st_colors = ['podpisana'=>'success','w realizacji'=>'primary','projekt'=>'secondary','zakończona'=>'dark','rozwiązana'=>'warning','anulowana'=>'danger'];
?>
<div class="mt-3">

  <?php /* ── Dane kontaktowe prowadzącego ───────────────────────────────── */ ?>
  <?= flash_html() ?>
  <div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-transparent fw-semibold">
      <i class="bi bi-person-lines-fill text-primary me-2" aria-hidden="true"></i>Dane kontaktowe do prowadzącego
    </div>
    <div class="card-body">
      <form method="post">
        <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
        <input type="hidden" name="_op" value="dyd_update_contact">
        <div class="mb-3">
          <label class="form-label small text-body-secondary">Adres systemowy (login)</label>
          <input type="text" class="form-control form-control-sm" value="<?= h($dyd_email) ?>" disabled>
          <div class="form-text">Adres powiązany z Twoim kontem — nie można go zmienić tutaj.</div>
        </div>
        <div class="row g-3 mb-3">
          <div class="col-sm-6">
            <label class="form-label" for="dyd_contact_email">E-mail kontaktowy</label>
            <input type="email" class="form-control form-control-sm" id="dyd_contact_email" name="alt_email"
                   value="<?= h($dyd_alt_email ?: $dyd_email) ?>"
                   placeholder="<?= h($dyd_email) ?>">
            <div class="form-text">Widoczny dla kursantów i administracji.</div>
          </div>
          <div class="col-sm-6">
            <label class="form-label" for="dyd_contact_phone">Telefon kontaktowy</label>
            <input type="tel" class="form-control form-control-sm" id="dyd_contact_phone" name="phone_number"
                   value="<?= h($dyd_phone) ?>" placeholder="+48 000 000 000">
          </div>
        </div>
        <div class="mb-3 form-check form-switch">
          <input class="form-check-input" type="checkbox" role="switch" id="dyd_share_contact"
                 name="share_contact" value="1" <?= !empty($dyd_share_contact) ? 'checked' : '' ?>>
          <label class="form-check-label" for="dyd_share_contact">
            Udostępnij dane kontaktowe kursantom (telefon i e-mail kontaktowy)
          </label>
        </div>
        <button type="submit" class="btn btn-primary btn-sm">
          <i class="bi bi-floppy me-1" aria-hidden="true"></i>Zapisz dane kontaktowe
        </button>
      </form>
    </div>
  </div>

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
    $is_is = $dc['contract_type'] === 'zlecenie' && !empty($dc['w_ramach_is']);
  ?>
  <div class="card border-0 shadow-sm mb-3 <?= $is_active ? '' : 'opacity-75' ?>">
    <div class="card-body">
      <div class="d-flex flex-wrap align-items-start gap-2 mb-3">
        <div>
          <div class="fw-bold fs-6"><?= h($dc['numer_umowy'] ?: '(brak numeru)') ?></div>
          <div class="text-body-secondary small">
            <?= h($ct_labels[$dc['contract_type']] ?? $dc['contract_type']) ?>
            <?php if ($is_is): ?>
            <span class="badge bg-primary-subtle text-primary ms-1" style="font-size:.7rem">
              <i class="bi bi-building-check me-1" aria-hidden="true"></i>IS
            </span>
            <?php endif; ?>
          </div>
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

      <?php if ($is_is): ?>
      <hr class="my-3">
      <div class="small text-primary fw-semibold mb-2"><i class="bi bi-building-check me-1"></i>Instytucja Szkoleniowa</div>
      <dl class="row row-cols-1 row-cols-sm-2 row-cols-md-3 g-2 mb-0" style="font-size:.85rem">
        <?php if (!empty($dc['is_nazwa'])): ?>
        <div class="col"><dt class="text-body-secondary fw-normal small">Nazwa IS</dt><dd class="mb-0 fw-semibold"><?= h($dc['is_nazwa']) ?></dd></div>
        <?php endif; ?>
        <?php if (!empty($dc['is_numer_umowy'])): ?>
        <div class="col"><dt class="text-body-secondary fw-normal small">Nr umowy z IS</dt><dd class="mb-0"><?= h($dc['is_numer_umowy']) ?></dd></div>
        <?php endif; ?>
        <?php if (!empty($dc['numer_projektu'])): ?>
        <div class="col"><dt class="text-body-secondary fw-normal small">Nr projektu</dt><dd class="mb-0"><?= h($dc['numer_projektu']) ?></dd></div>
        <?php endif; ?>
        <?php if (!empty($dc['is_uprawnienia_nr'])): ?>
        <div class="col"><dt class="text-body-secondary fw-normal small">Nr uprawnień</dt><dd class="mb-0"><?= h($dc['is_uprawnienia_nr']) ?></dd></div>
        <?php endif; ?>
        <?php if (!empty($dc['is_dyplom_nr'])): ?>
        <div class="col"><dt class="text-body-secondary fw-normal small">Nr dyplomu</dt><dd class="mb-0"><?= h($dc['is_dyplom_nr']) ?></dd></div>
        <?php endif; ?>
        <?php if (!empty($dc['is_dopuszczenie'])): ?>
        <div class="col"><dt class="text-body-secondary fw-normal small">Dopuszczenie</dt><dd class="mb-0"><?= h($dc['is_dopuszczenie']) ?></dd></div>
        <?php endif; ?>
        <?php if (!empty($dc['is_adres'])): ?>
        <div class="col col-sm-12"><dt class="text-body-secondary fw-normal small">Adres IS</dt><dd class="mb-0"><?= h($dc['is_adres']) ?></dd></div>
        <?php endif; ?>
        <div class="col col-sm-12 mt-1">
          <?php
            $rodo = !empty($dc['klauzula_rodo']);
          ?>
          <span class="d-inline-flex align-items-center gap-1 me-3 <?= $rodo ? 'text-success' : 'text-danger' ?>" style="font-size:.8rem">
            <i class="bi bi-<?= $rodo ? 'check-circle-fill' : 'x-circle' ?>"></i>
            Klauzula RODO
          </span>
        </div>
      </dl>
      <?php endif; ?>

    </div>
  </div>
  <?php endforeach; ?>
  <p class="text-body-secondary small mt-2 mb-0">
    <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
    W razie pytań dotyczących umów skontaktuj się z koordynatorem.
  </p>
</div>
