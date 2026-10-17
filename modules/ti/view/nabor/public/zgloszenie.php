<?php
/**
 * modules/ti/view/nabor/public/zgloszenie.php — karta zgłoszenia w koncie kandydata.
 * Sekcje: po przyjęciu (potwierdzenie miejsca, warunki, płatność) → oś czasu → dokumenty → wiadomości → dane (edycja do rozpatrzenia).
 * Zmienne: $s, $timeline, $docs, $msgs, $canEdit, $agreement, $pay, $org, $acc, $form. Kontroler: NaborCandidateController::show.
 */
$title = 'Zgłoszenie ' . $s['registration_number'];
include __DIR__ . '/_top.php';
$upd = h(naborUrl('/konto/zgloszenia/' . (int)$s['id']));
$F = $form['fields'];
$docBadge = ['requested' => 'tw-bg-amber-100 tw-text-amber-900', 'uploaded' => 'tw-bg-sky-100 tw-text-sky-900',
             'accepted' => 'tw-bg-green-100 tw-text-green-900', 'rejected' => 'tw-bg-red-100 tw-text-red-900'];
?>
<p class="tw-text-sm tw-mb-2"><a href="<?= h(naborUrl('/konto')) ?>">← Moje zgłoszenia</a></p>
<div class="k-card">
  <div class="tw-flex tw-flex-wrap tw-items-center tw-gap-2">
    <h1 class="tw-text-2xl tw-font-extrabold tw-mr-auto"><?= h($s['participant_name']) ?></h1>
    <span class="k-badge tw-bg-feer tw-text-white tw-text-sm"><?= h(naborStatusLabel($s['status'])) ?></span>
  </div>
  <p class="tw-text-gray-600 tw-mt-1"><?= h($s['registration_number']) ?> · <?= h($s['course_name'] ?: 'termin do ustalenia') ?><?= $s['course_schedule'] ? ' · ' . h($s['course_schedule']) : '' ?> · zajęcia online</p>
</div>

<?php if ($s['status'] === 'accepted'): ?>
<section class="k-card tw-border-2 tw-border-green-500" aria-labelledby="pl-h">
  <h2 id="pl-h" class="tw-text-lg tw-font-bold tw-mb-2">Zgłoszenie przyjęte 🎉</h2>
  <?php if (!$s['place_confirmed_at']): ?>
    <p class="tw-mb-3">Potwierdź, że uczestnik weźmie udział w zajęciach — po potwierdzeniu miejsce jest zarezerwowane.</p>
    <form method="post" action="<?= $upd ?>" class="tw-grid tw-gap-3">
      <?= Router::csrfField() ?><input type="hidden" name="_op" value="confirm_place">
      <?php if ($agreement !== ''): ?>
        <div class="tw-max-h-64 tw-overflow-y-auto tw-border tw-border-feer-line tw-rounded-xl tw-p-3 tw-text-sm tw-whitespace-pre-line tw-bg-gray-50" tabindex="0" aria-label="Warunki uczestnictwa"><?= h($agreement) ?></div>
        <label class="tw-flex tw-gap-2 tw-items-start"><input type="checkbox" name="agreement" value="1" required class="tw-mt-1 tw-h-5 tw-w-5"><span>Akceptuję warunki uczestnictwa (akceptacja zostanie zapisana z datą i adresem IP).</span></label>
      <?php endif; ?>
      <div><button class="k-btn tw-bg-green-600 hover:tw-bg-green-700">Potwierdzam miejsce</button></div>
    </form>
  <?php else: ?>
    <p class="tw-text-green-800 tw-font-bold">✓ Miejsce potwierdzone <?= h(naborTime($s['place_confirmed_at'])) ?><?= $s['agreement_accepted_at'] ? ' · warunki zaakceptowane' : '' ?>.</p>
    <?php if (trim((string)($form['texts']['payment_info'] ?? '')) !== '' || $pay): ?>
      <h3 class="tw-font-bold tw-mt-4 tw-mb-1">Opłaty</h3>
      <?php if (trim((string)($form['texts']['payment_info'] ?? '')) !== ''): ?><p class="tw-whitespace-pre-line tw-text-sm tw-mb-2"><?= h($form['texts']['payment_info']) ?></p><?php endif; ?>
      <?php if (!empty($pay['nrb'])): ?><p class="tw-text-sm">Indywidualny numer rachunku do przelewów: <strong class="tw-font-mono"><?= h(function_exists('pp_nrb_format') ? pp_nrb_format($pay['nrb']) : $pay['nrb']) ?></strong></p><?php endif; ?>
      <form method="post" action="<?= $upd ?>" class="tw-mt-2"><?= Router::csrfField() ?><input type="hidden" name="_op" value="pay"><button class="k-btn">Przejdź do płatności online</button></form>
    <?php endif; ?>
  <?php endif; ?>
</section>
<?php endif; ?>

<section class="k-card" aria-labelledby="tl-h">
  <h2 id="tl-h" class="tw-text-lg tw-font-bold tw-mb-3">Przebieg zgłoszenia</h2>
  <ol class="tw-border-l-2 tw-border-feer-line tw-pl-4 tw-grid tw-gap-3 tw-list-none">
    <li><span class="tw-text-xs tw-text-gray-500"><?= h(naborTime($s['created_at'])) ?></span><div class="tw-font-semibold">Zgłoszenie złożone</div></li>
    <?php foreach ($timeline as $t): if ($t['from_status'] === '') continue; ?>
      <li><span class="tw-text-xs tw-text-gray-500"><?= h(naborTime($t['created_at'])) ?></span>
        <div class="tw-font-semibold"><?= h(naborStatusLabel($t['to_status'])) ?></div>
        <?php if (trim($t['comment']) !== '' && !preg_match('/^(Zmiana w kanbanie|Adres e-mail potwierdzony|Zgłoszenie złożone z konta)/u', $t['comment'])): ?><div class="tw-text-sm tw-text-gray-700"><?= nl2br(h($t['comment'])) ?></div><?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ol>
</section>

<section class="k-card" id="dokumenty" aria-labelledby="dc-h">
  <h2 id="dc-h" class="tw-text-lg tw-font-bold tw-mb-3">Dokumenty</h2>
  <?php if (!$docs): ?><p class="tw-text-sm tw-text-gray-600 tw-mb-3">Organizator nie prosi o dokumenty. Możesz dołączyć własny (np. orzeczenie, opinię).</p><?php endif; ?>
  <ul class="tw-grid tw-gap-3 tw-list-none tw-p-0">
  <?php foreach ($docs as $d): ?>
    <li class="tw-border tw-border-feer-line tw-rounded-xl tw-p-3">
      <div class="tw-flex tw-flex-wrap tw-gap-2 tw-items-center">
        <strong class="tw-mr-auto"><?= h($d['name']) ?></strong>
        <span class="k-badge <?= $docBadge[$d['status']] ?? '' ?>"><?= h(NABOR_DOC_STATUSES[$d['status']][0] ?? $d['status']) ?></span>
      </div>
      <?php if ($d['note'] !== ''): ?><p class="tw-text-sm tw-text-gray-700"><?= nl2br(h($d['note'])) ?></p><?php endif; ?>
      <?php if ($d['review_note'] !== '' && $d['status'] === 'rejected'): ?><p class="tw-text-sm tw-text-red-800">Uwaga organizatora: <?= nl2br(h($d['review_note'])) ?></p><?php endif; ?>
      <?php if ($d['file_path']): ?><p class="tw-text-sm">Plik: <a href="<?= h(naborUrl('/konto/zgloszenia/' . (int)$s['id'] . '/dokument/' . (int)$d['id'])) ?>"><?= h($d['file_name']) ?></a> · <?= h(naborTime($d['uploaded_at'])) ?></p><?php endif; ?>
      <?php if (in_array($d['status'], ['requested', 'rejected', 'uploaded'], true)): ?>
        <form method="post" action="<?= $upd ?>" enctype="multipart/form-data" class="tw-flex tw-flex-wrap tw-gap-2 tw-items-center tw-mt-2">
          <?= Router::csrfField() ?><input type="hidden" name="_op" value="upload"><input type="hidden" name="doc_id" value="<?= (int)$d['id'] ?>">
          <label class="tw-sr-only" for="df<?= (int)$d['id'] ?>">Plik: <?= h($d['name']) ?></label>
          <input id="df<?= (int)$d['id'] ?>" type="file" name="file" accept=".pdf,.jpg,.jpeg,.png" required class="tw-text-sm">
          <button class="k-btn-ghost tw-text-sm"><?= $d['file_path'] ? 'Wgraj nowszą wersję' : 'Wgraj' ?></button>
        </form>
      <?php endif; ?>
    </li>
  <?php endforeach; ?>
  </ul>
  <details class="tw-mt-3">
    <summary class="tw-cursor-pointer tw-text-sm tw-font-bold tw-text-feer">+ Dołącz inny dokument</summary>
    <form method="post" action="<?= $upd ?>" enctype="multipart/form-data" class="tw-grid tw-gap-2 tw-mt-2 tw-max-w-md">
      <?= Router::csrfField() ?><input type="hidden" name="_op" value="upload"><input type="hidden" name="doc_id" value="0">
      <div><label class="k-label" for="do-n">Nazwa dokumentu</label><input id="do-n" name="doc_name" class="k-input" placeholder="np. Orzeczenie o niepełnosprawności" required></div>
      <div><label class="k-label" for="do-f">Plik (PDF, JPG, PNG, do 10 MB)</label><input id="do-f" type="file" name="file" accept=".pdf,.jpg,.jpeg,.png" required class="tw-text-sm"></div>
      <div><button class="k-btn-ghost tw-text-sm">Wgraj</button></div>
    </form>
  </details>
</section>

<section class="k-card" id="wiadomosci" aria-labelledby="ms-h">
  <h2 id="ms-h" class="tw-text-lg tw-font-bold tw-mb-3">Wiadomości z organizatorem</h2>
  <div class="tw-grid tw-gap-2 tw-mb-3" role="log" aria-live="polite">
    <?php if (!$msgs): ?><p class="tw-text-sm tw-text-gray-600">Brak wiadomości. Masz pytanie? Napisz poniżej.</p><?php endif; ?>
    <?php foreach ($msgs as $m): $mine = $m['author'] === 'candidate'; ?>
      <div class="tw-rounded-xl tw-p-3 tw-max-w-[85%] <?= $mine ? 'tw-bg-feer-bg tw-ml-auto' : 'tw-bg-gray-100' ?>">
        <div class="tw-text-xs tw-text-gray-500"><?= $mine ? 'Ty' : 'Organizator' . ($m['author_name'] ? ' (' . h($m['author_name']) . ')' : '') ?> · <?= h(naborTime($m['created_at'])) ?></div>
        <div class="tw-text-sm tw-whitespace-pre-line"><?= h($m['body']) ?></div>
      </div>
    <?php endforeach; ?>
  </div>
  <form method="post" action="<?= $upd ?>" class="tw-grid tw-gap-2">
    <?= Router::csrfField() ?><input type="hidden" name="_op" value="message">
    <label class="k-label" for="ms-b">Nowa wiadomość</label>
    <textarea id="ms-b" name="body" rows="3" class="k-input" required maxlength="5000"></textarea>
    <div><button class="k-btn">Wyślij</button></div>
  </form>
</section>

<section class="k-card" aria-labelledby="dt-h">
  <h2 id="dt-h" class="tw-text-lg tw-font-bold tw-mb-1">Dane zgłoszenia</h2>
  <?php if ($canEdit): ?>
    <p class="tw-text-sm tw-text-gray-600 tw-mb-3">Możesz poprawić dane do czasu rozpatrzenia zgłoszenia. E-mail i telefon są zweryfikowane — ich zmianę zgłoś w wiadomości.</p>
    <form method="post" action="<?= $upd ?>" class="tw-grid tw-gap-3">
      <?= Router::csrfField() ?><input type="hidden" name="_op" value="edit">
      <div><label class="k-label" for="ed-pn"><?= h($F['participant_name']['label']) ?></label><input id="ed-pn" name="participant_name" class="k-input" value="<?= h($s['participant_name']) ?>" required></div>
      <?php if ($F['participant_birth_date_or_pesel']['visible']): ?><div><label class="k-label" for="ed-pp"><?= h($F['participant_birth_date_or_pesel']['label']) ?></label><input id="ed-pp" name="participant_birth_date_or_pesel" class="k-input" value="<?= h($s['participant_birth_date_or_pesel']) ?>"></div><?php endif; ?>
      <?php if ($F['participant_school_class']['visible']): ?><div><label class="k-label" for="ed-pc"><?= h($F['participant_school_class']['label']) ?></label><input id="ed-pc" name="participant_school_class" class="k-input" value="<?= h($s['participant_school_class']) ?>"></div><?php endif; ?>
      <?php if ($F['participant_medical_notes']['visible']): ?><div><label class="k-label" for="ed-pm"><?= h($F['participant_medical_notes']['label']) ?></label><textarea id="ed-pm" name="participant_medical_notes" rows="3" class="k-input"><?= h($s['participant_medical_notes']) ?></textarea></div><?php endif; ?>
      <?php if ($F['equipment']['visible']): $eq = json_decode($s['equipment'] ?? '[]', true) ?: []; ?>
        <fieldset><legend class="k-label"><?= h($F['equipment']['label']) ?></legend>
          <div class="tw-grid sm:tw-grid-cols-2 tw-gap-x-4"><?php foreach (naborEquipmentOptions() as $k => $l): ?>
            <label class="tw-flex tw-gap-2 tw-py-1"><input type="checkbox" name="equipment[]" value="<?= h($k) ?>" <?= in_array($k, $eq, true) ? 'checked' : '' ?> class="tw-h-5 tw-w-5"><span><?= h($l) ?></span></label>
          <?php endforeach; ?></div></fieldset>
      <?php endif; ?>
      <?php if ($F['equipment_notes']['visible']): ?><div><label class="k-label" for="ed-en"><?= h($F['equipment_notes']['label']) ?></label><input id="ed-en" name="equipment_notes" class="k-input" value="<?= h($s['equipment_notes'] ?? '') ?>"></div><?php endif; ?>
      <div><label class="k-label" for="ed-gn"><?= h($F['guardian_name']['label']) ?> (opiekun)</label><input id="ed-gn" name="guardian_name" class="k-input" value="<?= h($s['guardian_name']) ?>" required></div>
      <?php if ($F['guardian_address']['visible']): ?>
        <div class="tw-grid sm:tw-grid-cols-3 tw-gap-3">
          <div class="sm:tw-col-span-3"><label class="k-label" for="ed-gs">Ulica i numer</label><input id="ed-gs" name="guardian_street" class="k-input" value="<?= h($s['guardian_street']) ?>"></div>
          <div><label class="k-label" for="ed-gz">Kod pocztowy</label><input id="ed-gz" name="guardian_postal_code" class="k-input" value="<?= h($s['guardian_postal_code']) ?>" placeholder="00-000"></div>
          <div class="sm:tw-col-span-2"><label class="k-label" for="ed-gc">Miejscowość</label><input id="ed-gc" name="guardian_city" class="k-input" value="<?= h($s['guardian_city']) ?>"></div>
        </div>
      <?php endif; ?>
      <div><button class="k-btn">Zapisz zmiany</button></div>
    </form>
  <?php else: ?>
    <dl class="tw-grid sm:tw-grid-cols-2 tw-gap-x-6 tw-gap-y-2 tw-text-sm">
      <div><dt class="tw-text-gray-500">Uczestnik</dt><dd><?= h($s['participant_name']) ?></dd></div>
      <div><dt class="tw-text-gray-500">Opiekun</dt><dd><?= h($s['guardian_name']) ?>, <?= h($s['guardian_email']) ?>, +<?= h($s['guardian_phone']) ?></dd></div>
      <?php if (($s['equipment'] ?? '[]') !== '[]'): ?><div><dt class="tw-text-gray-500"><?= h($F['equipment']['label']) ?></dt><dd><?= h(naborEquipmentLabel($s['equipment'])) ?></dd></div><?php endif; ?>
    </dl>
    <p class="tw-text-sm tw-text-gray-600 tw-mt-2">Zgłoszenie jest rozpatrzone — zmiany danych zgłoś w wiadomości do organizatora.</p>
  <?php endif; ?>
</section>
<?php include __DIR__ . '/_bottom.php'; ?>
