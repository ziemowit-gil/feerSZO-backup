<?php /* ═══════════════════════ TAB: PROTOKOŁY OCEN (widok USOS) ═══════════════════════ */ ?>
<?php
/**
 * Protokół = oceny końcowe wszystkich uczestników kursu za okres nauczania,
 * wpisywane zbiorczo w jednej tabeli i zatwierdzane ze śladem (kto, kiedy).
 * Po zatwierdzeniu edycja jest zamknięta — odblokować może pracownik D3 / admin,
 * podając powód. Protokół jest też warunkiem zamknięcia okresu nauczania.
 *
 * Zmienne z index.php: $cur_course, $course, $uid, $me.
 */
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_periods.php';

$pr_list  = ti_protocols_for_course($cur_course);
$pr_id    = (int)($_GET['protocol'] ?? 0);
$pr       = $pr_id ? ti_protocol_get($pr_id) : null;
if ($pr && (int)$pr['course_id'] !== $cur_course) $pr = null;
if (!$pr && $pr_list) $pr = ti_protocol_get((int)$pr_list[0]['id']);

// Protokół da się założyć tylko za okres otwarty do rozliczenia przez administrację
$pr_periods = ti_periods_with_protocols_open();
$pr_used = array_map(fn($r) => (int)($r['period_id'] ?? 0), $pr_list);
$pr_free = array_values(array_filter($pr_periods, fn($p) => !in_array((int)$p['id'], $pr_used, true)));

$pr_parts   = $pr ? ti_protocol_participants_for($pr) : [];
$pr_entries = $pr ? ti_protocol_entries((int)$pr['id']) : [];
$pr_avgs    = $pr ? ti_protocol_averages_for($pr) : [];
$pr_stats   = $pr ? ti_protocol_stats((int)$pr['id'], $cur_course) : ['total'=>0,'filled'=>0,'pct'=>0];
$pr_locked  = $pr ? ti_protocol_is_locked($pr) : false;
$pr_empty   = $pr ? ti_protocol_is_empty($pr_stats) : false;

// Pusty protokół wolno zatwierdzić — wydruk dostaje wtedy adnotację o braku ocen
$pr_confirm = $pr_empty
    ? 'W protokole nie ma ani jednej oceny. Zatwierdzić go jako PUSTY? Wydruk będzie zawierał adnotację, że nie wystawiono żadnej oceny. Po zatwierdzeniu zmiana wymaga pracownika D3 lub administratora.'
    : 'Zatwierdzić protokół? Po zatwierdzeniu nie będzie można zmieniać ocen — odblokowanie wymaga pracownika D3 lub administratora.';
?>

<div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
  <h1 class="h5 fw-bold mb-0"><i class="bi bi-card-checklist me-2" aria-hidden="true"></i>Protokoły zajęć kursu — <?= h($course['name']) ?></h1>
  <span class="badge bg-secondary"><?= count($pr_list) ?></span>
</div>

<div class="card">
  <div class="card-header">Jak wypełnić protokół — krok po kroku</div>
  <div class="card-body">
    <ol class="small mb-3">
      <li class="mb-1"><strong>Otwórz protokół za okres</strong> — wybierz okres nauczania w formularzu obok i kliknij „Otwórz protokół”. Na kurs i okres przypada jeden protokół.</li>
      <li class="mb-1"><strong>Wpisz oceny końcowe</strong> — w tabeli, przy każdym uczestniku. Dozwolone wpisy: <strong>1–6</strong> (można z „+” lub „-”), albo <?= h(implode(', ', array_keys(TI_PROTOCOL_SPECIAL))) ?>. Puste pole = brak oceny. Kolumna „Śr. z dziennika” to podpowiedź — nie wpisuje się sama.</li>
      <li class="mb-1"><strong>Zapisz protokół</strong> — możesz wracać i poprawiać do woli.</li>
      <li class="mb-1"><strong>Zatwierdź protokół</strong> — dopiero to jest deklaracja, że oceny są kompletne. Po zatwierdzeniu ocen nie da się zmienić; odblokowanie wymaga pracownika D3 lub administratora i zostaje w protokole ze śladem. Protokół bez ani jednej oceny też można zatwierdzić — wydruk dostanie wtedy adnotację o braku ocen.</li>
      <li><strong>Wydrukuj i podpisz</strong> — przycisk „PDF” (przy protokole albo w kolumnie „Wydruk” na liście).</li>
    </ol>
    <div class="small">
      <div class="fw-semibold mb-1">Co jest na wydruku i skąd się bierze</div>
      <ul class="mb-2">
        <li><strong>Oceny końcowe</strong> — to, co wpiszesz w tej tabeli. Nie wchodzą do średniej ważonej e-dziennika: oceny bieżące zostają w zakładce „Oceny”.</li>
        <li><strong>Ewidencja godzin</strong> — zajęcia <em>odbyte</em> w okresie protokołu (odbyte, zmiana indywidualna, praca własna). Odwołane i szkice się nie liczą. Czas brany z godzin lekcji, z sumą za cały okres.</li>
        <li><strong>Naliczenie wypłaty</strong> — stawka za zajęcie ustawiona na kursie razy liczba zajęć z ewidencji, rozbita na brutto-brutto, ZUS, składki, PIT i netto. Praca własna liczy się bezskładkowo. Gdy kurs nie ma stawki, wydruk mówi wprost, że wypłaty się nie nalicza.</li>
        <li><strong>Oświadczenie</strong> — podpisując, potwierdzasz zgodność ewidencji godzin i naliczenia ze stanem faktycznym.</li>
      </ul>
      <div class="text-body-secondary">
        Zatwierdzony protokół każdej grupy, która miała zajęcia w okresie, jest warunkiem
        <strong>zamknięcia okresu nauczania</strong> przez administrację.
      </div>
    </div>
  </div>
</div>

<?php
  // Kryteria oceniania przy wpisywaniu ocen — zwinięte, żeby nie zabierały miejsca
  $syl_ref_kinds     = ['criterion', 'requirement'];
  $syl_ref_collapsed = true;
  $syl_ref_title     = 'Kryteria oceniania i wymagania';
  include __DIR__ . '/_syllabus_ref.php';
  unset($syl_ref_kinds, $syl_ref_collapsed, $syl_ref_title);
?>

<div class="row g-3">
  <!-- Lista protokołów -->
  <div class="col-lg-4">
    <div class="card">
      <div class="card-header">Protokoły kursu</div>
      <div class="table-responsive">
        <table class="table table-sm table-hover mb-0">
          <caption class="visually-hidden">Protokoły zajęć kursu <?= h($course['name']) ?> za poszczególne okresy, ze stanem zatwierdzenia</caption>
          <thead><tr><th scope="col">Okres</th><th scope="col">Stan</th><th scope="col" class="text-end usos-noprint">Wydruk</th></tr></thead>
          <tbody>
            <?php if (!$pr_list): ?>
            <tr><td colspan="3" class="text-center text-muted py-3">Brak protokołów — otwórz pierwszy poniżej.</td></tr>
            <?php endif; ?>
            <?php foreach ($pr_list as $row):
              $st  = TI_PROTOCOL_STATUSES[$row['status']] ?? ['label'=>$row['status'],'badge'=>'secondary'];
              $rst = ti_protocol_stats((int)$row['id'], $cur_course);
              $sel = $pr && (int)$pr['id'] === (int)$row['id'];
            ?>
            <tr<?= $sel ? ' class="table-active"' : '' ?>>
              <td class="small">
                <a href="index.php?course=<?= (int)$cur_course ?>&tab=protokol&protocol=<?= (int)$row['id'] ?>">
                  <?= h($row['period_name'] ?: 'bez okresu') ?>
                </a>
                <div class="text-body-secondary"><?= h(ti_protocol_fill_text($rst)) ?></div>
              </td>
              <td class="small text-nowrap"><span class="badge text-bg-<?= h($st['badge']) ?>"><?= h($st['label']) ?></span></td>
              <td class="text-end text-nowrap usos-noprint">
                <a href="protokol_pdf.php?id=<?= (int)$row['id'] ?>" class="btn btn-sm btn-outline-secondary py-0 px-2"
                   title="Pobierz protokół w PDF" aria-label="Pobierz PDF protokołu: <?= h($row['period_name'] ?: 'bez okresu') ?>">
                  <i class="bi bi-file-earmark-pdf" aria-hidden="true"></i>
                </a>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="card-body usos-noprint">
        <?php if (!$pr_periods): ?>
        <div class="alert alert-info py-2 small mb-0" role="status">
          <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
          <?= h(ti_period_protocols_closed_msg()) ?>
        </div>
        <?php elseif (!$pr_free): ?>
        <div class="alert alert-secondary py-2 small mb-0" role="status">
          Za każdy otwarty okres protokół już istnieje — wybierz go z listy powyżej.
        </div>
        <?php else: ?>
        <form method="post" class="d-flex flex-wrap gap-2 align-items-end">
          <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
          <input type="hidden" name="_op" value="protocol_create">
          <input type="hidden" name="course_id" value="<?= (int)$cur_course ?>">
          <div class="flex-grow-1">
            <label class="form-label" for="pr-period">Nowy protokół za okres</label>
            <select class="form-select form-select-sm" id="pr-period" name="period_id" required>
              <?php foreach ($pr_free as $p): ?>
              <option value="<?= (int)$p['id'] ?>">
                <?= h($p['name']) ?> (<?= h(date('d.m.Y', strtotime($p['date_from']))) ?>–<?= h(date('d.m.Y', strtotime($p['date_to']))) ?>)
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <button class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Otwórz protokół</button>
        </form>
        <div class="form-text">
          Na kurs i okres przypada jeden protokół. Lista zawiera tylko okresy otwarte
          do rozliczenia przez administrację.
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- Wybrany protokół -->
  <div class="col-lg-8">
    <?php if (!$pr): ?>
    <div class="card"><div class="card-body text-body-secondary small">
      Wybierz protokół z listy albo otwórz nowy dla okresu nauczania.
    </div></div>
    <?php else: ?>
    <?php $st = TI_PROTOCOL_STATUSES[$pr['status']] ?? ['label'=>$pr['status'],'badge'=>'secondary']; ?>
    <div class="card">
      <div class="card-header d-flex align-items-center flex-wrap gap-2">
        <span><?= h($pr['title']) ?></span>
        <span class="badge text-bg-<?= h($st['badge']) ?>"><?= h($st['label']) ?></span>
        <span class="badge text-bg-light border text-dark"><?= h(ti_protocol_fill_text($pr_stats)) ?></span>
        <a href="protokol_pdf.php?id=<?= (int)$pr['id'] ?>" class="btn btn-sm btn-outline-secondary ms-auto usos-noprint">
          <i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>PDF
        </a>
      </div>

      <?php
        $pr_period_open = !$pr['period_id'] || ti_period_protocols_open((int)$pr['period_id']);
      ?>
      <?php if (!$pr_period_open): ?>
      <div class="card-body border-bottom py-2 small">
        <div class="alert alert-warning py-2 mb-0" role="status">
          <i class="bi bi-lock me-1" aria-hidden="true"></i>
          <?= h(ti_period_protocols_closed_msg(ti_period_get((int)$pr['period_id']))) ?>
          Podgląd i wydruk działają normalnie.
        </div>
      </div>
      <?php endif; ?>

      <?php if ($pr_empty): ?>
      <div class="card-body border-bottom py-2 small">
        <i class="bi bi-exclamation-square me-1" aria-hidden="true"></i>
        <?= h(ti_protocol_empty_note($pr_stats)) ?>
        <?php if (!$pr_locked): ?>
        <span class="text-body-secondary">Taki protokół można zatwierdzić — na wydruku znajdzie się ta adnotacja.</span>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <?php if ($pr_locked): ?>
      <div class="card-body border-bottom py-2 small">
        <i class="bi bi-lock-fill me-1" aria-hidden="true"></i>
        Zatwierdził: <strong><?= h($pr['approved_name'] ?: '—') ?></strong>,
        <?= h($pr['approved_at'] ? date('d.m.Y H:i', strtotime((string)$pr['approved_at'])) : '—') ?>.
        Ocen nie można zmieniać.
      </div>
      <?php endif; ?>

      <?php if (!empty($pr['unlocked_at'])): ?>
      <div class="card-body border-bottom py-2 small text-body-secondary">
        <i class="bi bi-unlock me-1" aria-hidden="true"></i>
        Odblokowany: <strong><?= h($pr['unlocked_name'] ?: '—') ?></strong>,
        <?= h(date('d.m.Y H:i', strtotime((string)$pr['unlocked_at']))) ?> — powód: <?= h($pr['unlock_reason']) ?>.
      </div>
      <?php endif; ?>

      <form method="post">
        <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
        <input type="hidden" name="course_id" value="<?= (int)$cur_course ?>">
        <input type="hidden" name="protocol_id" value="<?= (int)$pr['id'] ?>">
        <div class="table-responsive">
          <table class="table table-sm align-middle mb-0 usos-sticky">
            <caption class="visually-hidden">Oceny końcowe uczestników w protokole <?= h($pr['title']) ?></caption>
            <thead><tr>
              <th scope="col" style="width:2.5rem">#</th>
              <th scope="col">Uczestnik</th>
              <th scope="col" style="width:9rem">Ocena końcowa</th>
              <th scope="col" style="width:7rem" class="text-nowrap">Śr. z dziennika</th>
              <th scope="col">Uwagi</th>
            </tr></thead>
            <tbody>
              <?php if (!$pr_parts): ?>
              <tr><td colspan="5" class="text-center text-muted py-3">Do tej grupy nikt nie jest zapisany.</td></tr>
              <?php endif; ?>
              <?php foreach ($pr_parts as $i => $p):
                $cid = (int)$p['client_id'];
                $e   = $pr_entries[$cid] ?? null;
              ?>
              <tr>
                <td class="small"><?= $i + 1 ?></td>
                <td class="small fw-semibold"><?= h($p['name']) ?></td>
                <td>
                  <?php if ($pr_locked || !$pr_period_open): ?>
                    <strong><?= h($e['value_text'] ?? '—') ?></strong>
                  <?php else: ?>
                    <label class="visually-hidden" for="g-<?= $cid ?>">Ocena końcowa: <?= h($p['name']) ?></label>
                    <input type="text" class="form-control form-control-sm" id="g-<?= $cid ?>"
                           name="grade[<?= $cid ?>]" value="<?= h($e['value_text'] ?? '') ?>"
                           maxlength="3" inputmode="text" autocomplete="off" placeholder="np. 4+">
                  <?php endif; ?>
                </td>
                <td class="small text-nowrap"><?= isset($pr_avgs[$cid]) ? number_format($pr_avgs[$cid], 2, ',', '') : '<span class="text-muted">—</span>' ?></td>
                <td>
                  <?php if ($pr_locked || !$pr_period_open): ?>
                    <span class="small"><?= h($e['note'] ?? '') ?></span>
                  <?php else: ?>
                    <label class="visually-hidden" for="n-<?= $cid ?>">Uwagi: <?= h($p['name']) ?></label>
                    <input type="text" class="form-control form-control-sm" id="n-<?= $cid ?>"
                           name="note[<?= $cid ?>]" value="<?= h($e['note'] ?? '') ?>" maxlength="255">
                  <?php endif; ?>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <div class="card-body d-flex flex-wrap gap-2 align-items-center usos-noprint">
          <?php if (!$pr_locked && $pr_period_open): ?>
          <button class="btn btn-primary btn-sm" name="_op" value="protocol_save">
            <i class="bi bi-floppy me-1" aria-hidden="true"></i>Zapisz protokół
          </button>
          <?php if (ti_protocol_can_approve($uid, (int)$cur_course, dyd_is_staff())): ?>
          <button class="btn <?= $pr_empty ? 'btn-outline-success' : 'btn-success' ?> btn-sm" name="_op" value="protocol_approve"
                  onclick="return confirm('<?= h(addslashes($pr_confirm)) ?>')">
            <i class="bi bi-check2-square me-1" aria-hidden="true"></i><?= $pr_empty ? 'Zatwierdź pusty protokół' : 'Zatwierdź protokół' ?>
          </button>
          <?php else: ?>
          <span class="small text-body-secondary"><i class="bi bi-lock me-1" aria-hidden="true"></i>Zatwierdza główny prowadzący kursu albo kierownik.</span>
          <?php endif; ?>
          <span class="form-text mb-0">
            Dozwolone wpisy: <strong>1–6</strong> (można z „+” lub „-”), albo
            <?= h(implode(', ', array_keys(TI_PROTOCOL_SPECIAL))) ?>. Puste pole = brak oceny.
          </span>
          <?php elseif ($pr_locked): ?>
          <span class="form-text mb-0"><i class="bi bi-lock me-1" aria-hidden="true"></i>Protokół zamknięty do edycji.</span>
          <?php else: ?>
          <span class="form-text mb-0"><i class="bi bi-lock me-1" aria-hidden="true"></i>Protokoły za ten okres są zamknięte przez administrację.</span>
          <?php endif; ?>
        </div>
      </form>

      <?php $pr_acked = ti_protocol_hours_acked($pr); $pr_hp = ti_protocol_hours_and_payout($pr); ?>
      <div class="card-header border-top">Oświadczenie o zgodności ewidencji godzin i wypłaty</div>
      <div class="card-body">
        <p class="small mb-2">
          Za okres protokołu wykazano <strong><?= (int)$pr_hp['lessons'] ?></strong>
          <?= $pr_hp['lessons'] === 1 ? 'zajęcie' : 'zajęć' ?>
          (<?= h(ti_protocol_hm((int)$pr_hp['total_min'])) ?>)<?php
            if ($pr_hp['has_rate']): ?>, do wypłaty netto
            <strong><?= h(ti_protocol_money((float)$pr_hp['payout']['netto'])) ?></strong><?php
            endif; ?>.
          Szczegóły są na wydruku PDF.
        </p>
        <?php if (!empty($pr_hp['subs'])): ?>
        <p class="small text-body-secondary mb-2">
          <i class="bi bi-person-fill-gear me-1" aria-hidden="true"></i>Zastępstwa (wypłata dla zastępcy, poza kwotą powyżej):
          <?php foreach ($pr_hp['subs'] as $_i => $_sb): ?><?= $_i ? '; ' : '' ?><strong><?= h($_sb['name'] ?: '—') ?></strong> — <?= (int)$_sb['lessons'] ?> <?= (int)$_sb['lessons'] === 1 ? 'zajęcie' : 'zajęć' ?>, netto <?= h(ti_protocol_money((float)$_sb['netto'])) ?><?php endforeach; ?>.
        </p>
        <?php endif; ?>
        <?php if (ti_protocol_snapshot_drift($pr)): ?>
        <div class="alert alert-warning py-2 small" role="status">
          <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
          Po zatwierdzeniu zmieniono lekcje z zakresu tego protokołu — protokół pokazuje dane utrwalone przy
          zatwierdzeniu. Aby je zaktualizować, protokół trzeba odblokować i zatwierdzić ponownie.
        </div>
        <?php endif; ?>
        <?php if ($pr_acked): ?>
        <div class="alert alert-success py-2 small mb-0" role="status">
          <i class="bi bi-check2-circle me-1" aria-hidden="true"></i>
          Potwierdzone elektronicznie: <strong><?= h(ti_protocol_hours_ack_label($pr) ?: '—') ?></strong>,
          <?= h(date('d.m.Y H:i', strtotime((string)$pr['hours_ack_at']))) ?><?php
            if (trim((string)$pr['hours_ack_ip']) !== ''): ?>, IP <?= h($pr['hours_ack_ip']) ?><?php endif; ?>.
          Na wydruku zamiast miejsca na podpis widnieje ten ślad.
        </div>
        <?php elseif (!$pr_locked): ?>
        <p class="small text-body-secondary mb-0">
          <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
          Potwierdza się ewidencję zamkniętego dokumentu — najpierw zatwierdź protokół, potem pojawi się tu przycisk.
        </p>
        <?php else: ?>
        <p class="small text-body-secondary">
          Potwierdzam, że ewidencja godzin oraz naliczenie wypłaty są zgodne ze stanem faktycznym —
          zajęcia w wykazanych terminach odbyły się w podanym wymiarze, a wykazane kwoty nie budzą
          moich zastrzeżeń. Potwierdzenie zapisuje kto, kiedy i z jakiego adresu IP je złożył;
          odblokowanie protokołu je unieważnia.
        </p>
        <form method="post" class="usos-noprint" id="prHoursAckForm"
              onsubmit="return confirm(document.getElementById('pr_on_behalf') && document.getElementById('pr_on_behalf').checked
                ? 'Uzupełnić ewidencję godzin i naliczenie wypłaty w zastępstwie prowadzącego?'
                : 'Potwierdzić zgodność ewidencji godzin i naliczenia wypłaty?')">
          <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
          <input type="hidden" name="_op" value="protocol_hours_ack">
          <input type="hidden" name="course_id" value="<?= (int)$cur_course ?>">
          <input type="hidden" name="protocol_id" value="<?= (int)$pr['id'] ?>">
          <?php if (dyd_is_staff()): ?>
          <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" id="pr_on_behalf" name="on_behalf" value="1">
            <label class="form-check-label small" for="pr_on_behalf">
              Uzupełniam w zastępstwie prowadzącego — zapisze się jako
              „Uzupełnienie w/z <?= h($me['name'] ?? '') ?>" zamiast podpisu prowadzącego.
              Wypłata nadal naliczy się prowadzącemu przypisanemu do kursu/lekcji.
            </label>
          </div>
          <?php endif; ?>
          <button class="btn btn-sm btn-success">
            <i class="bi bi-pen me-1" aria-hidden="true"></i>Potwierdzam zgodność
          </button>
        </form>
        <?php endif; ?>
      </div>

      <?php /* ── Kontrasygnata kierownika ────────────────────────────────────
           Drugi podpis na tym samym dokumencie: prowadzący potwierdza ewidencję,
           kierownik podpisuje za organizatora. Backend (ti_protocol_org_ack)
           wymaga protokołu ZATWIERDZONEGO — podpisuje się dokument zamknięty —
           i odkłada ślad kto, kiedy oraz z jakiego adresu IP. Odblokowanie
           protokołu kasuje oba podpisy, bo po korekcie nie odpowiadają już
           treści dokumentu. */ ?>
      <?php $pr_org_acked = ti_protocol_org_acked($pr); ?>
      <div class="card-header border-top">Podpis za organizatora</div>
      <div class="card-body">
        <?php if ($pr_org_acked): ?>
        <div class="alert alert-success py-2 small mb-0" role="status">
          <i class="bi bi-patch-check me-1" aria-hidden="true"></i>
          Podpisane elektronicznie za organizatora: <strong><?= h($pr['org_ack_name'] ?: '—') ?></strong>,
          <?= h(date('d.m.Y H:i', strtotime((string)$pr['org_ack_at']))) ?><?php
            if (trim((string)$pr['org_ack_ip']) !== ''): ?>, IP <?= h($pr['org_ack_ip']) ?><?php endif; ?>.
          Na wydruku zamiast miejsca na podpis widnieje ten ślad.
        </div>

        <?php elseif (!dyd_is_staff()): ?>
        <p class="small text-body-secondary mb-0">
          <i class="bi bi-hourglass-split me-1" aria-hidden="true"></i>
          Dokument czeka na podpis kierownika. Do tego czasu wydruk ma w tym miejscu puste pole na podpis.
        </p>

        <?php elseif (!$pr_locked): ?>
        <p class="small text-body-secondary mb-0">
          <i class="bi bi-info-circle me-1" aria-hidden="true"></i>
          Podpisuje się dokument zamknięty — najpierw zatwierdź protokół, potem pojawi się tu przycisk podpisu.
        </p>

        <?php else: ?>
          <?php if (!$pr_acked): ?>
          <div class="alert alert-warning py-2 small" role="alert">
            <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
            Prowadzący nie potwierdził jeszcze ewidencji godzin i naliczenia wypłaty.
            Podpis jest możliwy, ale kontrasygnujesz dokument bez jego oświadczenia.
          </div>
          <?php endif; ?>
        <p class="small text-body-secondary">
          Jako przedstawiciel organizatora potwierdzam, że protokół został sporządzony prawidłowo,
          a wykazane godziny i naliczenie wypłaty przyjmuję do rozliczenia. Podpis zapisuje kto, kiedy
          i z jakiego adresu IP go złożył; odblokowanie protokołu go unieważnia.
        </p>
        <form method="post" class="usos-noprint"
              onsubmit="return confirm('Podpisać protokół za organizatora? Podpisu nie można cofnąć bez odblokowania protokołu.')">
          <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
          <input type="hidden" name="_op" value="protocol_org_ack">
          <input type="hidden" name="course_id" value="<?= (int)$cur_course ?>">
          <input type="hidden" name="protocol_id" value="<?= (int)$pr['id'] ?>">
          <button class="btn btn-sm btn-primary">
            <i class="bi bi-pen me-1" aria-hidden="true"></i>Podpisuję za organizatora
          </button>
        </form>
        <?php endif; ?>
      </div>

      <?php if ($pr_locked && dyd_is_staff()): ?>
      <div class="card-body border-top usos-noprint">
        <form method="post" class="d-flex flex-wrap gap-2 align-items-end"
              onsubmit="return confirm('Odblokować zatwierdzony protokół? Powód zostanie zapisany.')">
          <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
          <input type="hidden" name="_op" value="protocol_unlock">
          <input type="hidden" name="course_id" value="<?= (int)$cur_course ?>">
          <input type="hidden" name="protocol_id" value="<?= (int)$pr['id'] ?>">
          <div class="flex-grow-1">
            <label class="form-label" for="pr-reason">Powód odblokowania <span class="text-danger" aria-hidden="true">*</span></label>
            <input type="text" class="form-control form-control-sm" id="pr-reason" name="reason" required maxlength="255"
                   placeholder="np. korekta oceny po odwołaniu uczestnika">
          </div>
          <button class="btn btn-sm btn-outline-danger"><i class="bi bi-unlock me-1" aria-hidden="true"></i>Odblokuj protokół</button>
        </form>
      </div>
      <?php endif; ?>
    </div>

    <p class="small text-body-secondary mt-2">
      Ocena z protokołu jest oceną <strong>końcową</strong> i nie wchodzi do średniej ważonej
      e-dziennika — kolumna „Śr. z dziennika” pokazuje ją tylko pomocniczo.
      Zatwierdzony protokół jest warunkiem zamknięcia okresu nauczania.
    </p>
    <?php endif; ?>
  </div>
</div>
