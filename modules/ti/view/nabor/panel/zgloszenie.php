<?php
/**
 * modules/ti/view/nabor/panel/zgloszenie.php — karta zgłoszenia.
 * Zmienne: $s, $cons, $hist, $courses, $ezdZnak. Kontroler: NaborPanelController::show / update.
 */
$list = fn(string $json, array $map) => implode(', ', array_map(fn($k) => $map[$k] ?? $k, json_decode($json ?: '[]', true) ?: [])) ?: '—';
$yn   = fn($v) => !empty($v) ? '<span class="text-success fw-bold">tak</span>' : '<span class="text-muted">nie</span>';
$dl   = function (array $pairs): string {
    $o = '<dl class="row mb-0 small">';
    foreach ($pairs as $k => $v) $o .= '<dt class="col-sm-4 text-muted fw-normal">' . h($k) . '</dt><dd class="col-sm-8">' . ($v === '' ? '—' : $v) . '</dd>';
    return $o . '</dl>';
};

$id = (int)$s['id'];
$upd = h(route('nabor.update', ['id' => $id]));
$title = 'Zgłoszenie ' . $s['registration_number'];
$kierLabel = 'Nabór do grup';
include __DIR__ . '/_head.php';
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb small"><li class="breadcrumb-item"><a href="<?= h(route('nabor.index')) ?>">Nabór do grup</a></li><li class="breadcrumb-item active"><?= h($s['registration_number']) ?></li></ol></nav>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h4 class="mb-0"><?= h($s['participant_name']) ?> <small class="text-muted"><?= h($s['registration_number']) ?></small> <?= naborStatusBadge($s['status']) ?><?= $s['is_test'] ? ' <span class="badge text-bg-warning">TEST</span>' : '' ?></h4>
  <div class="d-flex gap-2">
    <a class="btn btn-sm btn-outline-secondary" href="<?= h(route('nabor.pdf', ['id' => $id])) ?>" target="_blank"><i class="bi bi-filetype-pdf"></i> PDF zgłoszenia</a>
    <?php if ($s['ezd_case_id']): ?>
      <a class="btn btn-sm btn-outline-danger" href="<?= h(naborEzdUrl((int)$s['ezd_case_id'])) ?>"><i class="bi bi-folder2-open"></i> Sprawa EZD <?= h($ezdZnak ?? '') ?></a>
    <?php elseif ($s['status'] !== 'unconfirmed' && !$s['is_test']): ?>
      <form method="post" action="<?= $upd ?>"><?= Router::csrfField() ?><input type="hidden" name="_op" value="ezd"><button class="btn btn-sm btn-outline-danger"><i class="bi bi-folder-plus"></i> Zarejestruj w EZD</button></form>
    <?php endif; ?>
    <?php if ($s['crm_contact_id']): ?>
      <a class="btn btn-sm btn-outline-primary" href="<?= h(rtrim(APP_URL, '/') . '/crm/contact/view.php?id=' . (int)$s['crm_contact_id']) ?>"><i class="bi bi-person-lines-fill"></i> Kontakt CRM</a>
    <?php elseif ($s['status'] !== 'unconfirmed' && !$s['is_test']): ?>
      <form method="post" action="<?= $upd ?>"><?= Router::csrfField() ?><input type="hidden" name="_op" value="crm"><button class="btn btn-sm btn-outline-primary"><i class="bi bi-person-plus"></i> Dodaj do CRM</button></form>
    <?php endif; ?>
  </div>
</div>
<?= flash_html() ?>
<?php if (!empty($creds)): ?>
  <div class="alert alert-success" role="status">
    <strong>Założono dostęp do panelu</strong> — hasła jednorazowe widać tylko teraz (opiekun dostał je SMS-em, jeśli zaznaczono powiadomienie):
    <ul class="mb-1 mt-1">
      <?php if ($creds['child']): ?><li>Panel uczestnika: login <code><?= h($creds['child']['login']) ?></code>, hasło <code><?= h($creds['child']['password']) ?></code></li><?php endif; ?>
      <?php if ($creds['parent']): ?><li>Panel rodzica: login <code><?= h($creds['parent']['login']) ?></code>, hasło <code><?= h($creds['parent']['password']) ?></code></li><?php endif; ?>
    </ul>
    <span class="small">Reset haseł i dalsze zarządzanie: <a href="konta.php">Ewidencja Uczestników</a>.</span>
  </div>
<?php endif; ?>
<?php if ($s['is_test']): ?>
  <div class="alert alert-warning small">Zgłoszenie testowe: zmiany statusu działają jak zwykle, ale bez SMS/e-maili, EZD i CRM, a „Przyjęte” nie zakłada uczestnika ani zapisu do grupy.</div>
<?php endif; ?>
<?php if ($s['status'] === 'unconfirmed'): ?>
  <div class="alert alert-secondary small">Opiekun nie potwierdził jeszcze adresu e-mail. Zgłoszenie wygaśnie po <?= NABOR_CONFIRM_HOURS ?> godz. od złożenia (<?= h(naborTime($s['created_at'])) ?>).</div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-8">
    <div class="card mb-3"><div class="card-header fw-bold">Zajęcia</div><div class="card-body">
      <?= $dl($s['is_custom_schedule'] ? [
            'Termin' => '<span class="text-warning-emphasis fw-bold">do ustalenia</span>',
            'Preferowane dni' => h($list($s['preferred_days'], NABOR_DAYS)),
            'Preferowane pory' => h($list($s['preferred_time_slots'], NABOR_SLOTS)),
            'Dyspozycyjność' => nl2br(h($s['notes_availability'])),
            'Przypisana grupa' => h((string)$s['course_name']),
          ] : [
            'Grupa' => h((string)$s['course_name']), 'Harmonogram' => h((string)$s['course_schedule']),
            'Uczestnik TI' => $s['client_id'] ? '#' . (int)$s['client_id'] . ' — zapisany do grupy' : '',
            'Konto panelu' => $account ? 'uczestnik: <code>' . h($account['login']) . '</code>' . (!empty($account['parent_login']) ? ' · rodzic: <code>' . h($account['parent_login']) . '</code>' : '') : '',
            'Płatność (deklaracja)' => h(NABOR_PAYMENT[$s['payment_method']] ?? ''),
          ]) ?>
      <form method="post" action="<?= $upd ?>" class="d-flex gap-2 mt-3"><?= Router::csrfField() ?><input type="hidden" name="_op" value="course">
        <label class="visually-hidden" for="zp-ac">Grupa</label>
        <select id="zp-ac" name="course_id" class="form-select form-select-sm w-auto">
          <option value="">— bez grupy —</option>
          <?php foreach ($courses as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= (int)$s['course_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['title']) ?><?= $c['is_open'] ? '' : ' [poza naborem]' ?> (<?= $c['free'] === null ? 'bez limitu' : 'wolne: ' . $c['free'] ?>)</option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-sm btn-outline-primary">Przypisz do grupy</button>
      </form>
    </div></div>

    <div class="card mb-3"><div class="card-header fw-bold">Uczestnik</div><div class="card-body">
      <?= $dl([
        'Imię i nazwisko' => h($s['participant_name']), 'PESEL / data urodzenia' => h($s['participant_birth_date_or_pesel']),
        'Szkoła / klasa' => h($s['participant_school_class']), 'Uwagi medyczne i dostępnościowe' => nl2br(h($s['participant_medical_notes'])),
      ]) ?>
    </div></div>

    <div class="card mb-3"><div class="card-header fw-bold">Rodzic / opiekun</div><div class="card-body">
      <?= $dl([
        'Imię i nazwisko' => h($s['guardian_name']),
        'Telefon' => '<a href="tel:+' . h($s['guardian_phone']) . '">+' . h($s['guardian_phone']) . '</a>' . ($s['phone_verified_at'] ? ' <span class="badge text-bg-success">SMS ✓ ' . h(naborTime($s['phone_verified_at'])) . '</span>' : ' <span class="badge text-bg-secondary">niezweryfikowany</span>'),
        'E-mail' => '<a href="mailto:' . h($s['guardian_email']) . '">' . h($s['guardian_email']) . '</a>' . ($s['email_confirmed_at'] ? ' <span class="badge text-bg-success">e-mail ✓ ' . h(naborTime($s['email_confirmed_at'])) . '</span>' : ' <span class="badge text-bg-secondary">niepotwierdzony</span>'),
        'Adres' => h($s['guardian_street'] . ', ' . $s['guardian_postal_code'] . ' ' . $s['guardian_city']),
        'Sprzęt do zajęć online' => h(naborEquipmentLabel($s['equipment'] ?? '[]')) . (($s['equipment_notes'] ?? '') !== '' ? '<div class="text-muted">' . h($s['equipment_notes']) . '</div>' : ''),
        'Odbiór (starsze zgłoszenie)' => ($s['pickup_type'] ?? '') !== '' ? h(NABOR_PICKUP[$s['pickup_type']] ?? '') . ($s['authorized_persons_details'] !== '' ? ' — ' . h($s['authorized_persons_details']) : '') : '',
      ]) ?>
    </div></div>

    <details class="card mb-3" id="edycja">
      <summary class="card-header fw-bold" style="cursor:pointer"><i class="bi bi-pencil"></i> Edytuj dane zgłoszenia</summary>
      <form method="post" action="<?= $upd ?>" class="card-body row g-2">
        <?= Router::csrfField() ?><input type="hidden" name="_op" value="edit">
        <?php $ev = fn($k) => h((string)$s[$k]); ?>
        <div class="col-12 fw-semibold small text-muted">Uczestnik</div>
        <div class="col-md-6"><label class="form-label small" for="e-pn">Imię i nazwisko</label><input id="e-pn" class="form-control form-control-sm" name="participant_name" value="<?= $ev('participant_name') ?>" required></div>
        <div class="col-md-3"><label class="form-label small" for="e-pp">PESEL / data ur.</label><input id="e-pp" class="form-control form-control-sm" name="participant_birth_date_or_pesel" value="<?= $ev('participant_birth_date_or_pesel') ?>" required></div>
        <div class="col-md-3"><label class="form-label small" for="e-pc">Szkoła / klasa</label><input id="e-pc" class="form-control form-control-sm" name="participant_school_class" value="<?= $ev('participant_school_class') ?>"></div>
        <div class="col-12"><label class="form-label small" for="e-pm">Uwagi medyczne i dostępnościowe</label><textarea id="e-pm" class="form-control form-control-sm" name="participant_medical_notes" rows="2"><?= $ev('participant_medical_notes') ?></textarea></div>
        <div class="col-12 fw-semibold small text-muted mt-2">Rodzic / opiekun</div>
        <div class="col-md-4"><label class="form-label small" for="e-gn">Imię i nazwisko</label><input id="e-gn" class="form-control form-control-sm" name="guardian_name" value="<?= $ev('guardian_name') ?>" required></div>
        <div class="col-md-4"><label class="form-label small" for="e-gp">Telefon</label><input id="e-gp" class="form-control form-control-sm" name="guardian_phone" value="<?= $ev('guardian_phone') ?>" inputmode="tel" required></div>
        <div class="col-md-4"><label class="form-label small" for="e-ge">E-mail</label><input id="e-ge" type="email" class="form-control form-control-sm" name="guardian_email" value="<?= $ev('guardian_email') ?>" required></div>
        <div class="col-md-6"><label class="form-label small" for="e-gs">Ulica i numer</label><input id="e-gs" class="form-control form-control-sm" name="guardian_street" value="<?= $ev('guardian_street') ?>"></div>
        <div class="col-md-2"><label class="form-label small" for="e-gz">Kod</label><input id="e-gz" class="form-control form-control-sm" name="guardian_postal_code" value="<?= $ev('guardian_postal_code') ?>" placeholder="00-000"></div>
        <div class="col-md-4"><label class="form-label small" for="e-gc">Miejscowość</label><input id="e-gc" class="form-control form-control-sm" name="guardian_city" value="<?= $ev('guardian_city') ?>"></div>
        <div class="col-md-6"><fieldset><legend class="form-label small mb-1">Sprzęt do zajęć online</legend>
          <?php $eq = json_decode($s['equipment'] ?? '[]', true) ?: []; foreach (NABOR_EQUIPMENT as $k => $l): ?>
            <div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="equipment[]" value="<?= $k ?>" id="eq-<?= $k ?>" <?= in_array($k, $eq, true) ? 'checked' : '' ?>><label class="form-check-label small" for="eq-<?= $k ?>"><?= h($l) ?></label></div>
          <?php endforeach; ?></fieldset></div>
        <div class="col-md-6"><label class="form-label small" for="e-pay">Płatność (deklaracja)</label>
          <select id="e-pay" class="form-select form-select-sm" name="payment_method"><option value="">—</option><?php foreach (NABOR_PAYMENT as $k => $l): ?><option value="<?= $k ?>" <?= $s['payment_method'] === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></div>
        <div class="col-12"><label class="form-label small" for="e-eqn">Uwagi o sprzęcie</label><input id="e-eqn" class="form-control form-control-sm" name="equipment_notes" value="<?= $ev('equipment_notes') ?>"></div>
        <div class="col-12"><label class="form-label small" for="e-na">Dyspozycyjność (termin do ustalenia)</label><textarea id="e-na" class="form-control form-control-sm" name="notes_availability" rows="2"><?= $ev('notes_availability') ?></textarea></div>
        <div class="col-12 small text-muted">Zmiana telefonu albo e-maila zdejmuje znacznik weryfikacji. Zmiana trafia do historii obiegu.<?= $s['client_id'] ? ' Uczestnik TI jest już założony — jego dane popraw w Ewidencji Uczestników.' : '' ?></div>
        <div class="col-12"><button class="btn btn-sm btn-primary">Zapisz zmiany</button></div>
      </form>
    </details>

    <div class="card mb-3"><div class="card-header fw-bold">Zgody RODO</div><div class="card-body">
      <?= $dl([
        'Regulamin' => $yn($cons['terms_accepted'] ?? 0), 'Przetwarzanie danych' => $yn($cons['gdpr_accepted'] ?? 0),
        'Wizerunek' => $yn($cons['image_consent'] ?? 0), 'Marketing' => $yn($cons['marketing_consent'] ?? 0),
        'Adres IP' => h((string)($cons['ip_address'] ?? '')), 'Czas' => h(naborTime($cons['created_at'] ?? null, true)),
        'Przeglądarka' => '<span class="text-muted">' . h((string)($cons['user_agent'] ?? '')) . '</span>',
      ]) ?>
    </div></div>
  </div>

  <div class="col-lg-4">
    <div class="card mb-3"><div class="card-header fw-bold">Zmień status</div><div class="card-body">
      <form method="post" action="<?= $upd ?>"><?= Router::csrfField() ?><input type="hidden" name="_op" value="status">
        <label class="form-label small" for="zp-st">Nowy status</label>
        <select id="zp-st" name="to_status" class="form-select form-select-sm mb-2">
          <?php foreach (NABOR_MANUAL_STATUSES as $k): ?><option value="<?= $k ?>" <?= $s['status'] === $k ? 'selected' : '' ?>><?= h(naborStatusLabel($k)) ?></option><?php endforeach; ?>
        </select>
        <label class="form-label small" for="zp-cm">Notatka</label>
        <textarea id="zp-cm" name="comment" rows="3" class="form-control form-control-sm mb-2" placeholder="Trafi do historii i do wiadomości dla opiekuna"></textarea>
        <div class="form-check mb-2"><input class="form-check-input" type="checkbox" name="notify" value="1" id="zp-nt" checked><label class="form-check-label small" for="zp-nt">Powiadom opiekuna (e-mail + SMS)</label></div>
        <button class="btn btn-sm btn-primary w-100" <?= $s['status'] === 'unconfirmed' ? 'disabled' : '' ?>>Zapisz</button>
      </form>
    </div></div>

    <div class="card"><div class="card-header fw-bold">Historia obiegu</div>
      <ul class="list-group list-group-flush small">
      <?php foreach (array_reverse($hist) as $hrow): ?>
        <li class="list-group-item">
          <div class="d-flex justify-content-between"><span class="text-muted"><?= h(naborTime($hrow['created_at'])) ?></span><span><?= h($hrow['user_name'] ?? 'system / opiekun') ?></span></div>
          <?php if ($hrow['from_status'] !== $hrow['to_status']): ?><div><?= $hrow['from_status'] !== '' ? naborStatusBadge($hrow['from_status']) . ' → ' : '' ?><?= naborStatusBadge($hrow['to_status']) ?></div><?php endif; ?>
          <?php if ($hrow['comment'] !== ''): ?><div class="mt-1"><?= nl2br(h($hrow['comment'])) ?></div><?php endif; ?>
        </li>
      <?php endforeach; ?>
      </ul>
      <div class="card-body"><form method="post" action="<?= $upd ?>" class="d-flex gap-2"><?= Router::csrfField() ?><input type="hidden" name="_op" value="note">
        <label class="visually-hidden" for="zp-nn">Notatka wewnętrzna</label>
        <input id="zp-nn" name="note" class="form-control form-control-sm" placeholder="Notatka wewnętrzna"><button class="btn btn-sm btn-outline-secondary">Dodaj</button></form></div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/_foot.php'; ?>
