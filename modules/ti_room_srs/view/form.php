<?php
/**
 * modules/ti_room_srs/view/form.php — formularz rezerwacji sali (wzór operatora) wypełniony danymi z systemu.
 * Zmienne (prefiks tr — layout panelu nadpisuje m.in. $data): $me, $trData (tiRoomSrsDefaults()['data']), $trMeta (course, room, sessions, participants, instructor, form),
 * $trKey (courseId, roomId, from, to), $resStatus (status rezerwacji SRS albo null), $back. Kontroler: RoomSrsController::form.
 */
$title = 'Formularz rezerwacji sali';
include __DIR__ . '/_head.php';
$room = $trMeta['room'];
$pp = $trMeta['participants'];
$hasSrs = (int)$room['resource_id'] && (int)($room['resource_active'] ?? 0);
$backUrl = str_starts_with($back, rtrim(APP_URL, '/') . '/') ? $back : route('trs.index');
$resId = (int)($trMeta['form']['srs_reservation_id'] ?? 0);
$fid = fn(string $k) => 'trs_' . $k;
$input = function (string $k, string $label, string $type = 'text', string $help = '') use ($trData, $fid) {
    $hid = $help !== '' ? ' aria-describedby="' . $fid($k) . '_h"' : '';
    echo '<div class="col-md-6"><label class="form-label small fw-semibold" for="' . $fid($k) . '">' . h($label) . '</label>'
       . '<input class="form-control form-control-sm" type="' . $type . '" id="' . $fid($k) . '" name="' . $k . '" value="' . h((string)$trData[$k]) . '"' . $hid . '>'
       . ($help !== '' ? '<div class="form-text" id="' . $fid($k) . '_h">' . h($help) . '</div>' : '') . '</div>';
};
$radios = function (string $k, string $legend, array $opts) use ($trData, $fid) {
    echo '<fieldset class="col-md-6"><legend class="form-label small fw-semibold mb-1" style="font-size:.875rem">' . h($legend) . '</legend>';
    foreach ($opts as $v => $l) {
        $id = $fid($k) . '_' . $v;
        echo '<div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="' . $k . '" id="' . $id . '" value="' . h($v) . '"'
           . ((string)$trData[$k] === (string)$v ? ' checked' : '') . '><label class="form-check-label small" for="' . $id . '">' . h($l) . '</label></div>';
    }
    echo '</fieldset>';
};
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb small">
  <li class="breadcrumb-item"><a href="<?= h($backUrl) ?>">Formularze rezerwacji sal</a></li>
  <li class="breadcrumb-item active" aria-current="page"><?= h((string)($trMeta['course']['display_name'] ?: $trMeta['course']['name'])) ?></li>
</ol></nav>
<h1 class="h4 fw-bold mb-1"><i class="bi bi-file-earmark-word text-primary me-2" aria-hidden="true"></i>Formularz rezerwacji sali</h1>
<p class="text-body-secondary small mb-3">
  <?= h((string)($trMeta['course']['display_name'] ?: $trMeta['course']['name'])) ?> · <?= h((string)($room['short_label'] ?: $room['name'])) ?> ·
  <?= h(date('d.m.Y', strtotime($trKey['from']))) ?> – <?= h(date('d.m.Y', strtotime($trKey['to']))) ?> · <?= count($trMeta['sessions']) ?> terminów.
  Pola uzupełnione z systemu możesz poprawić — dokument powstaje z oryginalnego wzoru operatora bez zmiany układu.
  <?php if ($resId): ?><br>Rezerwacja w SRS: <strong>#<?= $resId ?></strong> — <?= h(RES_STATUSES[$resStatus]['label'] ?? (string)$resStatus) ?>.<?php endif; ?>
</p>

<form method="post" action="<?= h(route('trs.generate')) ?>">
  <?= Router::csrfField() ?>
  <input type="hidden" name="k_course" value="<?= (int)$trKey['courseId'] ?>">
  <input type="hidden" name="k_room" value="<?= (int)$trKey['roomId'] ?>">
  <input type="hidden" name="k_from" value="<?= h($trKey['from']) ?>">
  <input type="hidden" name="k_to" value="<?= h($trKey['to']) ?>">

  <div class="card mb-3"><div class="card-header fw-semibold">Sala i termin</div><div class="card-body row g-3">
    <?php $radios('room', 'Sala', TI_ROOM_SRS_FORM_ROOMS); ?>
    <?php if ($trData['room'] === ''): ?><div class="col-md-6 small text-warning-emphasis">Sala TI nie ma przypisanej opcji wzoru — wybierz ją albo ustaw w <a href="<?= h(route('trs.rooms')) ?>">przypisaniu sal</a>.</div><?php endif; ?>
    <div class="col-12">
      <label class="form-label small fw-semibold" for="trs_terms">Termin rezerwacji wraz ze wskazaniem godzin</label>
      <textarea class="form-control form-control-sm" id="trs_terms" name="terms" rows="<?= max(2, substr_count($trData['terms'], "\n") + 2) ?>" aria-describedby="trs_terms_h"><?= h($trData['terms']) ?></textarea>
      <div class="form-text" id="trs_terms_h">Z planu lekcji grupy w tej sali (bez odwołanych); każda linia to osobny wiersz w dokumencie.</div>
    </div>
  </div></div>

  <div class="card mb-3"><div class="card-header fw-semibold">Podmiot dokonujący rezerwacji</div><div class="card-body row g-3">
    <?php $input('orgName', 'Nazwa podmiotu'); $input('orgAddress', 'Adres siedziby'); ?>
    <?php $input('orgPhone', 'Telefon', 'tel', $trData['orgPhone'] === '' ? 'Brak telefonu organizacji w ustawieniach (org_telefon).' : ''); ?>
    <?php $input('orgEmail', 'E-mail', 'email', $trData['orgEmail'] === '' ? 'Brak e-maila organizacji w ustawieniach (org_email).' : ''); ?>
  </div></div>

  <div class="card mb-3"><div class="card-header fw-semibold">Wydarzenie</div><div class="card-body row g-3">
    <?php $radios('purpose', 'Cel rezerwacji', ['coworking' => 'coworking', 'wydarzenie' => 'organizacja wydarzenia']); ?>
    <?php $input('participants', 'Liczba uczestników zajęć', 'text', 'Aktywni uczestnicy grupy: ' . (int)$pp['count'] . '.'); ?>
    <div class="col-12">
      <label class="form-label small fw-semibold" for="trs_topic">Temat spotkania i krótki opis wydarzenia</label>
      <textarea class="form-control form-control-sm" id="trs_topic" name="topic" rows="3"><?= h($trData['topic']) ?></textarea>
    </div>
    <?php $radios('minors', 'Spotkanie z udziałem dzieci lub młodzieży', ['tak' => 'tak', 'nie' => 'nie']); ?>
    <?php $radios('statement', 'Oświadczenie o ochronie małoletnich (ustawa z 13 maja 2016 r.)', ['tak' => 'tak', 'nie' => 'nie', 'nie_dotyczy' => 'nie dotyczy']); ?>
    <div class="col-12 small text-body-secondary">
      Ustalone z wieku uczestników w dniu pierwszego terminu: małoletnich <?= (int)$pp['minors'] ?>
      <?php if ((int)$pp['unknown']): ?>, <strong>bez daty urodzenia i PESEL: <?= (int)$pp['unknown'] ?></strong> — sprawdź ręcznie<?php endif; ?>.
    </div>
  </div></div>

  <div class="card mb-3"><div class="card-header fw-semibold">Osoba odpowiedzialna za użytkowanie sali</div><div class="card-body row g-3">
    <?php if (!$trMeta['instructor']): ?><div class="col-12 small text-warning-emphasis">Terminy nie mają przypisanego prowadzącego — uzupełnij dane ręcznie.</div><?php endif; ?>
    <?php $input('firstName', 'Imię'); $input('lastName', 'Nazwisko'); ?>
    <?php $input('address', 'Adres zamieszkania', 'text', 'Wpisz ręcznie — trafia tylko do pobieranego pliku, nie jest zapisywany.'); ?>
    <div class="col-md-6"><label class="form-label small fw-semibold" for="trs_pesel">PESEL</label>
      <input class="form-control form-control-sm" id="trs_pesel" name="pesel" inputmode="numeric" maxlength="11" pattern="\d{11}" autocomplete="off" value="" aria-describedby="trs_pesel_h">
      <div class="form-text" id="trs_pesel_h">Wpisz ręcznie — trafia tylko do pobieranego pliku, nie jest zapisywany.</div></div>
    <?php $input('phone', 'Telefon', 'tel'); $input('email', 'E-mail', 'email'); ?>
    <div class="col-12">
      <label class="form-label small fw-semibold" for="trs_equipment">Oczekiwana pomoc sprzętowa (laptop, rzutnik multimedialny, flipchart, notebook)</label>
      <textarea class="form-control form-control-sm" id="trs_equipment" name="equipment" rows="2"><?= h($trData['equipment']) ?></textarea>
    </div>
  </div></div>

  <div class="d-flex flex-wrap align-items-center gap-3 mb-4">
    <div class="form-check">
      <input class="form-check-input" type="checkbox" id="trs_srs" name="srs" value="1" <?= $hasSrs ? 'checked' : 'disabled' ?> aria-describedby="trs_srs_h">
      <label class="form-check-label" for="trs_srs"><?= $resId ? 'Zaktualizuj rezerwację w SRS' : 'Złóż rezerwację w SRS' ?></label>
      <div class="form-text" id="trs_srs_h"><?= $hasSrs ? 'Zasób SRS: ' . h((string)$room['resource_name']) . '. Akceptacja w SRS oznaczy terminy jako „Potwierdzone”.'
          : 'Sala nie ma zasobu SRS — <a href="' . h(route('trs.rooms')) . '">przypisz go</a>, żeby składać rezerwacje.' ?></div>
    </div>
    <div class="ms-auto d-flex gap-2">
      <button class="btn btn-outline-primary" name="_op" value="save"><i class="bi bi-save me-1" aria-hidden="true"></i>Zapisz bez pobierania</button>
      <button class="btn btn-primary" name="_op" value="docx"><i class="bi bi-download me-1" aria-hidden="true"></i>Zapisz i pobierz formularz (DOCX)</button>
    </div>
  </div>
</form>
<?php include __DIR__ . '/_foot.php'; ?>
