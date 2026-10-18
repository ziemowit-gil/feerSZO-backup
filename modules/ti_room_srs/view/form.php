<?php
/**
 * modules/ti_room_srs/view/form.php — formularz/wniosek rezerwacji sali (wzór operatora sali) wypełniony danymi z systemu.
 * Zmienne (prefiks tr — layout panelu nadpisuje m.in. $data): $me, $trMode ('course' — grupa TI, 'adhoc' — rezerwacja
 * przestrzeni), $trData, $trMeta, $trKey, $trAction, $trResStatus, $trRooms (adhoc: sale ze wzorem), $back.
 * Kontroler: RoomSrsController::form / adhoc.
 */
$title = $trMode === 'adhoc' ? 'Rezerwuj przestrzeń' : 'Formularz rezerwacji sali';
include __DIR__ . '/_head.php';
$room = $trMeta['room'];
$tplKey = (string)($room['template'] ?? '');
$tpl = TI_ROOM_SRS_TEMPLATES[$tplKey] ?? null;
$hasSrs = $room && (int)$room['resource_id'] && (int)($room['resource_active'] ?? 0);
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
        echo '<div class="form-check form-check-inline"><input class="form-check-input" type="radio" name="' . $k . '" id="' . $id . '" value="' . h((string)$v) . '"'
           . ((string)$trData[$k] === (string)$v ? ' checked' : '') . '><label class="form-check-label small" for="' . $id . '">' . h($l) . '</label></div>';
    }
    echo '</fieldset>';
};
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb small">
  <li class="breadcrumb-item"><a href="<?= h($backUrl) ?>">Formularze rezerwacji sal</a></li>
  <li class="breadcrumb-item active" aria-current="page"><?= $trMode === 'adhoc' ? 'Rezerwuj przestrzeń' : h((string)($trMeta['course']['display_name'] ?: $trMeta['course']['name'])) ?></li>
</ol></nav>
<h1 class="h4 fw-bold mb-1"><i class="bi bi-<?= $trMode === 'adhoc' ? 'door-open' : 'file-earmark-text' ?> text-primary me-2" aria-hidden="true"></i><?= h($title) ?></h1>

<?php if ($trMode === 'adhoc'): ?>
<p class="text-body-secondary small mb-2">Rezerwacja sali bez grupy TI — np. na konsultacje albo spotkanie. Wybierz salę, dzień i godziny; resztę wniosku operatora uzupełnimy z systemu.</p>
<form method="get" action="<?= h(route('trs.adhoc')) ?>" class="card card-body mb-3">
  <?php if (defined('TI_ROOM_SRS_PANEL')): ?><input type="hidden" name="_route" value="/nowa"><?php endif; ?>
  <?php if ((int)$trKey['formId']): ?><input type="hidden" name="id" value="<?= (int)$trKey['formId'] ?>"><?php endif; ?>
  <div class="row g-2 align-items-end">
    <div class="col-md-5"><label class="form-label small fw-semibold" for="trs_k_room">Sala</label>
      <select class="form-select form-select-sm" id="trs_k_room" name="room" required>
        <option value="">— wybierz —</option>
        <?php foreach ($trRooms as $r): $t = TI_ROOM_SRS_TEMPLATES[$r['template']]; ?>
        <option value="<?= (int)$r['id'] ?>" <?= $room && (int)$room['id'] === (int)$r['id'] ? 'selected' : '' ?>><?= h(($r['form_room'] !== '' ? $t['rooms'][$r['form_room']] : (string)($r['short_label'] ?: $r['name'])) . ' — ' . explode(' — ', $t['label'])[0]) ?></option>
        <?php endforeach; ?>
      </select></div>
    <div class="col-md-3"><label class="form-label small fw-semibold" for="trs_k_date">Dzień</label>
      <input class="form-control form-control-sm" type="date" id="trs_k_date" name="date" value="<?= h($trMeta['date']) ?>" required></div>
    <div class="col-md-2"><label class="form-label small fw-semibold" for="trs_k_tf">Od</label>
      <input class="form-control form-control-sm" type="time" id="trs_k_tf" name="tf" value="<?= h($trMeta['timeFrom']) ?>" required></div>
    <div class="col-md-2"><label class="form-label small fw-semibold" for="trs_k_tt">Do</label>
      <input class="form-control form-control-sm" type="time" id="trs_k_tt" name="tt" value="<?= h($trMeta['timeTo']) ?>" required></div>
    <div class="col-12"><button class="btn btn-sm btn-outline-primary"><?= $room ? 'Zmień salę / termin' : 'Dalej' ?></button>
      <?php if (!$trRooms): ?><span class="small text-danger ms-2">Żadna sala nie ma przypisanego wzoru operatora — ustawia to kierownik w „Sale ↔ SRS”.</span><?php endif; ?></div>
  </div>
</form>
<?php if (!empty($trMeta['conflicts'])): ?>
<div class="alert alert-warning py-2 small">W tym czasie sala jest zajęta: <?= h(implode('; ', $trMeta['conflicts'])) ?>.</div>
<?php endif; ?>
<?php else: ?>
<p class="text-body-secondary small mb-3">
  <?= h((string)($trMeta['course']['display_name'] ?: $trMeta['course']['name'])) ?> · <?= h((string)($room['short_label'] ?: $room['name'])) ?> ·
  <?= h(date('d.m.Y', strtotime($trKey['from']))) ?> – <?= h(date('d.m.Y', strtotime($trKey['to']))) ?> · <?= count($trMeta['sessions']) ?> <?= h(tiRoomSrsTermWord(count($trMeta['sessions']))) ?>.
</p>
<?php endif; ?>

<?php $trForm = $trMeta['form']; $trApproved = !$trForm || tiRoomSrsIsApproved($trForm); ?>
<?php if ($trForm && !$trApproved): ?>
<div class="alert alert-warning d-flex flex-wrap align-items-center gap-2 py-2">
  <i class="bi bi-hourglass-split" aria-hidden="true"></i>
  <span class="me-auto">Formularz czeka na akceptację kierownika — wydruk będzie możliwy po zatwierdzeniu.</span>
  <?php if ($me['staff']): ?>
  <form method="post" action="<?= h(route('trs.approve', ['id' => (int)$trForm['id']])) ?>" class="d-inline">
    <?= Router::csrfField() ?><input type="hidden" name="back" value="<?= h((string)($_SERVER['REQUEST_SCHEME'] ?? 'https') . '://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '')) ?>">
    <button class="btn btn-sm btn-success"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Zatwierdź</button>
  </form>
  <?php endif; ?>
</div>
<?php elseif ($trForm && !empty($trForm['approved_at'])): ?>
<p class="small text-success mb-2"><i class="bi bi-check2-circle" aria-hidden="true"></i> Zatwierdzony <?= h(date('d.m.Y H:i', strtotime((string)$trForm['approved_at']))) ?>.</p>
<?php endif; ?>
<?php if ($room): ?>
<p class="text-body-secondary small mb-3">
  <?= $tpl ? 'Wzór: <strong>' . h($tpl['label']) . '</strong> (' . strtoupper($tpl['format']) . ') — dokument powstaje z oryginału operatora bez zmiany układu; pola z systemu możesz poprawić.'
           : '<span class="text-danger">Sala nie ma przypisanego wzoru operatora — formularz zapiszesz, ale pliku nie wygenerujesz.</span>' ?>
  <?php if ($resId): ?><br>Rezerwacja w SRS: <strong>#<?= $resId ?></strong> — <?= h(RES_STATUSES[$trResStatus]['label'] ?? (string)$trResStatus) ?>.<?php endif; ?>
</p>

<form method="post" action="<?= h($trAction) ?>">
  <?= Router::csrfField() ?>
  <?php if ($trMode === 'adhoc'): ?>
  <input type="hidden" name="k_form" value="<?= (int)$trKey['formId'] ?>">
  <input type="hidden" name="k_room" value="<?= (int)$room['id'] ?>">
  <input type="hidden" name="k_date" value="<?= h($trMeta['date']) ?>">
  <input type="hidden" name="k_tf" value="<?= h($trMeta['timeFrom']) ?>">
  <input type="hidden" name="k_tt" value="<?= h($trMeta['timeTo']) ?>">
  <?php else: ?>
  <input type="hidden" name="k_course" value="<?= (int)$trKey['courseId'] ?>">
  <input type="hidden" name="k_room" value="<?= (int)$trKey['roomId'] ?>">
  <input type="hidden" name="k_from" value="<?= h($trKey['from']) ?>">
  <input type="hidden" name="k_to" value="<?= h($trKey['to']) ?>">
  <?php endif; ?>

  <div class="card mb-3"><div class="card-header fw-semibold">Sala i termin</div><div class="card-body row g-3">
    <?php if ($tpl): $radios('room', 'Sala we wzorze', $tpl['rooms']); endif; ?>
    <?php if ($tpl && $trData['room'] === ''): ?><div class="col-md-6 small text-warning-emphasis">Sala TI nie ma przypisanej opcji wzoru — wybierz ją<?= $me['staff'] ? ' albo ustaw w <a href="' . h(route('trs.rooms')) . '">przypisaniu sal</a>' : '' ?>.</div><?php endif; ?>
    <div class="col-12">
      <label class="form-label small fw-semibold" for="trs_terms">Termin wraz ze wskazaniem godzin</label>
      <textarea class="form-control form-control-sm" id="trs_terms" name="terms" rows="<?= max(2, substr_count($trData['terms'], "\n") + 2) ?>" aria-describedby="trs_terms_h"><?= h($trData['terms']) ?></textarea>
      <div class="form-text" id="trs_terms_h"><?= $trMode === 'adhoc' ? 'Z wybranego dnia i godzin.' : 'Z planu lekcji grupy w tej sali (bez odwołanych); każda linia to osobny wiersz w dokumencie.' ?></div>
    </div>
  </div></div>

  <div class="card mb-3"><div class="card-header fw-semibold">Podmiot dokonujący rezerwacji</div><div class="card-body row g-3">
    <?php if ($tplKey === 'c10') $radios('entityType', 'Podmiot', TI_ROOM_SRS_ENTITY_TYPES); ?>
    <?php $input('orgName', 'Nazwa podmiotu'); ?>
    <?php if ($tplKey === 'c10') $input('orgKrs', 'Numer KRS lub rodzaj i numer innej ewidencji'); ?>
    <?php $input('orgAddress', 'Adres siedziby'); ?>
    <?php $input('orgPhone', 'Telefon', 'tel', $trData['orgPhone'] === '' ? 'Brak telefonu organizacji w ustawieniach (org_telefon).' : ''); ?>
    <?php $input('orgEmail', 'E-mail', 'email', $trData['orgEmail'] === '' ? 'Brak e-maila organizacji w ustawieniach (org_email).' : ''); ?>
  </div></div>

  <div class="card mb-3"><div class="card-header fw-semibold">Spotkanie</div><div class="card-body row g-3">
    <?php if ($tplKey !== 'c10') $radios('purpose', 'Cel rezerwacji', ['coworking' => 'coworking', 'wydarzenie' => 'organizacja wydarzenia']); ?>
    <?php $input('participants', 'Liczba uczestników', 'text', $trMode === 'course' ? 'Aktywni uczestnicy grupy: ' . (int)$trMeta['participants']['count'] . '.' : ''); ?>
    <div class="col-12">
      <label class="form-label small fw-semibold" for="trs_topic"><?= $tplKey === 'c10' ? 'Cel rezerwacji i temat spotkania' : 'Temat spotkania i krótki opis wydarzenia' ?></label>
      <textarea class="form-control form-control-sm" id="trs_topic" name="topic" rows="3"><?= h($trData['topic']) ?></textarea>
    </div>
    <?php $radios('minors', 'Spotkanie z udziałem dzieci lub młodzieży', ['tak' => 'tak', 'nie' => 'nie']); ?>
    <?php $radios('statement', 'Oświadczenie o ochronie małoletnich (ustawa z 13 maja 2016 r.)', ['tak' => 'tak', 'nie' => 'nie', 'nie_dotyczy' => 'nie dotyczy']); ?>
    <?php if ($trMode === 'course'): $pp = $trMeta['participants']; ?>
    <div class="col-12 small text-body-secondary">
      Ustalone z wieku uczestników w dniu pierwszego terminu: małoletnich <?= (int)$pp['minors'] ?><?php if ((int)$pp['unknown']): ?>,
      <strong>bez daty urodzenia i PESEL: <?= (int)$pp['unknown'] ?></strong> — sprawdź ręcznie<?php endif; ?>.
    </div>
    <?php endif; ?>
    <div class="col-12">
      <label class="form-label small fw-semibold" for="trs_equipment">Oczekiwany sprzęt (laptop, rzutnik multimedialny, flipchart, inne)</label>
      <textarea class="form-control form-control-sm" id="trs_equipment" name="equipment" rows="2"><?= h($trData['equipment']) ?></textarea>
    </div>
  </div></div>

  <div class="card mb-3"><div class="card-header fw-semibold">Osoba odpowiedzialna za spotkanie i użytkowanie sali</div><div class="card-body row g-3">
    <?php if ($trMode === 'course' && !$trMeta['instructor']): ?><div class="col-12 small text-warning-emphasis">Terminy nie mają przypisanego prowadzącego — uzupełnij dane ręcznie.</div><?php endif; ?>
    <?php $input('firstName', 'Imię'); $input('lastName', 'Nazwisko'); ?>
    <?php $input('address', 'Adres zamieszkania', 'text', 'Wpisz ręcznie — trafia tylko do pobieranego pliku, nie jest zapisywany.'); ?>
    <div class="col-md-6"><label class="form-label small fw-semibold" for="trs_pesel">PESEL</label>
      <input class="form-control form-control-sm" id="trs_pesel" name="pesel" inputmode="numeric" maxlength="11" pattern="\d{11}" autocomplete="off" value="" aria-describedby="trs_pesel_h">
      <div class="form-text" id="trs_pesel_h">Wpisz ręcznie — trafia tylko do pobieranego pliku, nie jest zapisywany.</div></div>
    <?php $input('phone', 'Telefon', 'tel'); $input('email', 'E-mail', 'email'); ?>
    <?php if ($tplKey === 'c10'): ?>
    <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" id="trs_newsletter" name="newsletter" value="1" <?= $trData['newsletter'] === '1' ? 'checked' : '' ?>>
      <label class="form-check-label small" for="trs_newsletter">Zgoda na newslettery Centrum Obywatelskiego Centrum C10 (dobrowolna)</label></div></div>
    <?php endif; ?>
  </div></div>

  <div class="d-flex flex-wrap align-items-center gap-3 mb-4">
    <div class="form-check">
      <input class="form-check-input" type="checkbox" id="trs_srs" name="srs" value="1" <?= $hasSrs ? 'checked' : 'disabled' ?> aria-describedby="trs_srs_h">
      <label class="form-check-label" for="trs_srs"><?= $resId ? 'Zaktualizuj rezerwację w SRS' : 'Złóż rezerwację w SRS' ?></label>
      <div class="form-text" id="trs_srs_h"><?= $hasSrs ? 'Zasób SRS: ' . h((string)$room['resource_name']) . ($trMode === 'course' ? '. Akceptacja w SRS oznaczy terminy jako „Potwierdzone”.' : '.')
          : 'Sala nie ma zasobu SRS' . ($me['staff'] ? ' — <a href="' . h(route('trs.rooms')) . '">przypisz go</a>' : ' — przypisuje go kierownik') . ', żeby składać rezerwacje.' ?></div>
    </div>
    <div class="ms-auto d-flex gap-2">
      <button class="btn btn-outline-primary" name="_op" value="save"><i class="bi bi-save me-1" aria-hidden="true"></i>Zapisz bez pobierania</button>
      <?php if ($me['staff'] || ($trForm && $trApproved)): ?>
      <button class="btn btn-primary" name="_op" value="file" <?= $tpl ? '' : 'disabled' ?>><i class="bi bi-download me-1" aria-hidden="true"></i>Zapisz i pobierz <?= $tpl ? strtoupper($tpl['format']) : 'plik' ?></button>
      <?php else: ?>
      <button class="btn btn-primary" name="_op" value="save"><i class="bi bi-send me-1" aria-hidden="true"></i>Wyślij do akceptacji kierownika</button>
      <?php endif; ?>
    </div>
  </div>
</form>
<?php endif; ?>
<?php include __DIR__ . '/_foot.php'; ?>
