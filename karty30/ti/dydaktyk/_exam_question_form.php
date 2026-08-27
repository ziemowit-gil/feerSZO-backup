<?php
/**
 * karty30/ti/dydaktyk/_exam_question_form.php
 * Edytor pytania Equi Exams. Formularz jest renderowany serwerowo dla JEDNEGO
 * typu pytania — nie ma pól chowanych JavaScriptem, więc czytnik ekranu
 * i nawigacja klawiaturą widzą dokładnie to, co jest do wypełnienia.
 *
 * Walidacji tu nie ma: pola jadą w całości do silnika Java, który buduje
 * definicję pytania albo zwraca listę błędów (pl.feer.exam.authoring.QuestionAuthoring).
 *
 * Zmienne wejściowe z _exam_build_view.php:
 *   $exam, $edit_q, $q_type, $q_meta, $cfg, $cv, $cl, $editing, $languages
 */

$existing_options = $editing ? ti_exam_options((int)$edit_q['id']) : [];
$existing_cases   = $editing ? ti_exam_cases((int)$edit_q['id'])   : [];
$existing_blanks  = (array)($cfg['blanks']   ?? []);
$existing_crit    = (array)($cfg['keywords'] ?? []);

/** Ile pustych wierszy dorzucić w repeaterze (praca bez JavaScriptu ma być możliwa). */
$spare = 3;
$is_tf = $q_type === 'truefalse';
?>
<form method="post" class="card border-0 shadow-sm">
  <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
  <input type="hidden" name="_op" value="save_question">
  <input type="hidden" name="exam_id" value="<?= (int)$exam['id'] ?>">
  <input type="hidden" name="type" value="<?= h($q_type) ?>">
  <?php if ($editing): ?><input type="hidden" name="question_id" value="<?= (int)$edit_q['id'] ?>"><?php endif; ?>

  <div class="card-header bg-transparent d-flex align-items-center gap-2 flex-wrap">
    <span class="fw-semibold">
      <i class="bi bi-<?= h((string)$q_meta['icon']) ?> me-1" aria-hidden="true"></i><?= h((string)$q_meta['label']) ?>
    </span>
    <a class="btn btn-sm btn-outline-secondary ms-auto" href="exam_build.php?exam_id=<?= (int)$exam['id'] ?>">
      <?= $editing ? 'Zamknij edycję' : 'Zmień typ pytania' ?>
    </a>
  </div>

  <div class="card-body">
    <p class="text-body-secondary small"><?= h((string)$q_meta['hint']) ?></p>

    <div class="mb-3">
      <label class="form-label" for="qPrompt">Polecenie <span class="text-danger" aria-hidden="true">*</span></label>
      <textarea class="form-control" id="qPrompt" name="prompt" rows="3" required
                aria-describedby="qPromptHelp"><?= h($editing ? (string)$edit_q['prompt'] : '') ?></textarea>
      <div class="form-text" id="qPromptHelp">
        <?php if ($q_type === 'fill_blank'): ?>
          Miejsca do uzupełnienia oznacz znacznikami <code>[[1]]</code>, <code>[[2]]</code> — kursant zobaczy tam pola do wpisania.
        <?php else: ?>
          Treść widoczna dla kursanta.
        <?php endif; ?>
      </div>
    </div>

    <div class="row g-2 mb-3">
      <div class="col-6 col-md-3">
        <label class="form-label" for="qPoints">Punkty</label>
        <input class="form-control" id="qPoints" name="points" type="text" inputmode="decimal"
               value="<?= h($editing ? rtrim(rtrim(number_format((float)$edit_q['points'], 2, ',', ''), '0'), ',') : '1') ?>">
      </div>
      <div class="col-6 col-md-9 d-flex align-items-end">
        <div class="form-check">
          <input class="form-check-input" type="checkbox" id="qBank" name="in_bank"
                 <?= $editing && (int)$edit_q['in_bank'] === 1 ? 'checked' : '' ?>
                 aria-describedby="qBankHelp">
          <label class="form-check-label" for="qBank">Pytanie z banku (losowane)</label>
          <div class="form-text mt-0" id="qBankHelp">Nie wchodzi do każdego zestawu — trafia do puli losowania.</div>
        </div>
      </div>
    </div>

<?php /* ══════════════ WARIANTY / TWIERDZENIA ══════════════ */ ?>
<?php if (!empty($q_meta['options'])): ?>
    <fieldset class="mb-3" id="optionsBlock">
      <legend class="fs-6 fw-semibold">
        <?= $is_tf ? 'Twierdzenia do oceny' : 'Warianty odpowiedzi' ?>
      </legend>
      <p class="form-text mt-0" id="optHelp">
        <?php if ($is_tf): ?>
          Zaznacz pole „prawda" przy twierdzeniach prawdziwych. Kursant oceni każde osobno.
        <?php elseif ($q_type === 'multi'): ?>
          Zaznacz wszystkie poprawne warianty. Punktacja karze za błędne zaznaczenia, więc zaznaczenie wszystkiego daje zero.
        <?php else: ?>
          Zaznacz dokładnie jeden poprawny wariant.
        <?php endif; ?>
      </p>
      <div class="vstack gap-2" data-repeat="options">
      <?php
        $rows = $existing_options;
        for ($i = 0; $i < $spare; $i++) $rows[] = ['label' => '', 'is_correct' => 0, 'feedback' => ''];
        foreach ($rows as $i => $o): ?>
        <div class="border rounded p-2" data-row>
          <div class="row g-2 align-items-center">
            <div class="col-12 col-md-7">
              <label class="form-label small mb-1" for="opt<?= $i ?>"><?= $is_tf ? 'Twierdzenie' : 'Treść wariantu' ?> <?= $i + 1 ?></label>
              <input class="form-control form-control-sm" id="opt<?= $i ?>" name="opt_label[<?= $i ?>]"
                     value="<?= h((string)$o['label']) ?>" aria-describedby="optHelp">
            </div>
            <div class="col-7 col-md-2 d-flex align-items-end pb-1">
              <div class="form-check mb-0">
                <?php /* Jednokrotny wybór to JEDNA grupa radiów (wspólna nazwa), inaczej
                         przeglądarka nie wymuszałaby wzajemnego wykluczania wariantów. */ ?>
                <?php if ($q_type === 'single'): ?>
                <input class="form-check-input" type="radio"
                       id="optc<?= $i ?>" name="opt_correct_single" value="<?= $i ?>"
                       <?= !empty($o['is_correct']) ? 'checked' : '' ?>>
                <?php else: ?>
                <input class="form-check-input" type="checkbox"
                       id="optc<?= $i ?>" name="opt_correct[<?= $i ?>]" value="1"
                       <?= !empty($o['is_correct']) ? 'checked' : '' ?>>
                <?php endif; ?>
                <label class="form-check-label small" for="optc<?= $i ?>"><?= $is_tf ? 'prawda' : 'poprawny' ?></label>
              </div>
            </div>
            <div class="col-5 col-md-3">
              <label class="form-label small mb-1" for="optf<?= $i ?>">Komentarz</label>
              <input class="form-control form-control-sm" id="optf<?= $i ?>" name="opt_feedback[<?= $i ?>]"
                     value="<?= h((string)($o['feedback'] ?? '')) ?>">
            </div>
          </div>
        </div>
      <?php endforeach; ?>
      </div>
      <button class="btn btn-sm btn-outline-secondary mt-2" type="button" data-add="options">
        <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Dodaj wiersz
      </button>
    </fieldset>

  <?php if ($q_type === 'multi' || $is_tf): ?>
    <div class="mb-3">
      <label class="form-label" for="qNeg">Punktacja tego pytania</label>
      <select class="form-select" id="qNeg" name="q_neg_marking" aria-describedby="qNegHelp">
        <option value="">Jak w ustawieniach egzaminu</option>
        <?php foreach (K30_TI_EXAM_NEG as $key => $label): ?>
        <option value="<?= h($key) ?>" <?= (string)$cv('negMarking', '') === $key ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
      <div class="form-text" id="qNegHelp">Ustawienie indywidualne nadpisuje regułę całego egzaminu.</div>
    </div>
  <?php endif; ?>
<?php endif; ?>

<?php /* ══════════════ LUKI (tekst i kod) ══════════════ */ ?>
<?php if ($q_type === 'fill_blank' || $q_type === 'code_completion'): ?>
  <?php if ($q_type === 'code_completion'): ?>
    <div class="row g-2 mb-3">
      <div class="col-md-5">
        <label class="form-label" for="qLang">Język</label>
        <select class="form-select" id="qLang" name="language">
          <?php foreach ($languages as $id => $label): ?>
          <option value="<?= h($id) ?>" <?= (string)$cv('language', 'python') === $id ? 'selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="mb-3">
      <label class="form-label" for="qTemplate">Szablon kodu <span class="text-danger" aria-hidden="true">*</span></label>
      <textarea class="form-control font-monospace" id="qTemplate" name="template" rows="8" spellcheck="false"
                aria-describedby="qTemplateHelp"><?= h((string)$cv('template', '')) ?></textarea>
      <div class="form-text" id="qTemplateHelp">
        Miejsca do uzupełnienia oznacz jako <code>___1___</code>, <code>___2___</code> — klucz musi zgadzać się z listą luk poniżej.
      </div>
    </div>
    <div class="form-check mb-3">
      <input class="form-check-input" type="checkbox" id="qRunFill" name="run_after_fill"
             <?= !empty($cv('runAfterFill')) ? 'checked' : '' ?> aria-describedby="qRunFillHelp">
      <label class="form-check-label" for="qRunFill">Sprawdzaj uruchomieniowo złożony kod</label>
      <div class="form-text mt-0" id="qRunFillHelp">
        Zamiast porównywać wpisane słowa, silnik podstawi je do szablonu i uruchomi kod na przypadkach testowych.
      </div>
    </div>
  <?php endif; ?>

    <fieldset class="mb-3">
      <legend class="fs-6 fw-semibold">Luki i akceptowane odpowiedzi</legend>
      <div class="vstack gap-2" data-repeat="blanks">
      <?php
        $rows = $existing_blanks;
        for ($i = 0; $i < $spare; $i++) $rows[] = ['key' => '', 'accept' => [], 'hint' => '', 'points' => 1];
        foreach ($rows as $i => $b):
          $accept = is_array($b['accept'] ?? null) ? implode("\n", $b['accept']) : (string)($b['accept'] ?? ''); ?>
        <div class="border rounded p-2" data-row>
          <div class="row g-2">
            <div class="col-4 col-md-2">
              <label class="form-label small mb-1" for="bk<?= $i ?>">Klucz</label>
              <input class="form-control form-control-sm" id="bk<?= $i ?>" name="blank_key[<?= $i ?>]"
                     value="<?= h((string)($b['key'] ?? ($i + 1))) ?>">
            </div>
            <div class="col-8 col-md-4">
              <label class="form-label small mb-1" for="ba<?= $i ?>">Akceptowane odpowiedzi</label>
              <textarea class="form-control form-control-sm font-monospace" id="ba<?= $i ?>" rows="2"
                        name="blank_accept[<?= $i ?>]" aria-describedby="baHelp"><?= h($accept) ?></textarea>
            </div>
            <div class="col-8 col-md-4">
              <label class="form-label small mb-1" for="bh<?= $i ?>">Podpowiedź dla kursanta</label>
              <input class="form-control form-control-sm" id="bh<?= $i ?>" name="blank_hint[<?= $i ?>]"
                     value="<?= h((string)($b['hint'] ?? '')) ?>">
            </div>
            <div class="col-4 col-md-2">
              <label class="form-label small mb-1" for="bp<?= $i ?>">Punkty</label>
              <input class="form-control form-control-sm" id="bp<?= $i ?>" name="blank_points[<?= $i ?>]"
                     type="text" inputmode="decimal" value="<?= h((string)($b['points'] ?? 1)) ?>">
            </div>
          </div>
        </div>
      <?php endforeach; ?>
      </div>
      <div class="form-text" id="baHelp">Każdy wariant w osobnej linii — wystarczy, że kursant trafi w jeden z nich.</div>
      <button class="btn btn-sm btn-outline-secondary mt-2" type="button" data-add="blanks">
        <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Dodaj lukę
      </button>
    </fieldset>

    <fieldset class="mb-3">
      <legend class="fs-6 fw-semibold">Porównywanie odpowiedzi</legend>
      <div class="form-check">
        <input class="form-check-input" type="checkbox" id="qCase" name="case_sensitive"
               <?= !empty($cv('caseSensitive')) ? 'checked' : '' ?>>
        <label class="form-check-label" for="qCase">Rozróżniaj wielkość liter</label>
      </div>
      <div class="form-check">
        <input class="form-check-input" type="checkbox" id="qAcc" name="ignore_accents"
               <?= !empty($cv('ignoreAccents')) ? 'checked' : '' ?>>
        <label class="form-check-label" for="qAcc">Pomijaj polskie znaki diakrytyczne</label>
      </div>
      <div class="form-check">
        <input class="form-check-input" type="checkbox" id="qRegex" name="blank_regex"
               <?= !empty($cv('regex')) ? 'checked' : '' ?>>
        <label class="form-check-label" for="qRegex">Traktuj wzorce jak wyrażenia regularne</label>
      </div>
      <div class="form-check">
        <input class="form-check-input" type="checkbox" id="qAllNone" name="all_or_nothing"
               <?= !empty($cv('allOrNothing')) ? 'checked' : '' ?>>
        <label class="form-check-label" for="qAllNone">Punkty tylko za komplet luk</label>
      </div>
    </fieldset>
<?php endif; ?>

<?php /* ══════════════ KRÓTKA ODPOWIEDŹ ══════════════ */ ?>
<?php if ($q_type === 'short_answer'): ?>
    <fieldset class="mb-3">
      <legend class="fs-6 fw-semibold">Kryteria oceny</legend>
      <div class="vstack gap-2" data-repeat="criteria">
      <?php
        $rows = $existing_crit;
        for ($i = 0; $i < $spare; $i++) $rows[] = ['label' => '', 'any' => [], 'points' => 1, 'required' => false];
        foreach ($rows as $i => $c):
          $any = is_array($c['any'] ?? null) ? implode("\n", $c['any']) : (string)($c['any'] ?? ''); ?>
        <div class="border rounded p-2" data-row>
          <div class="row g-2">
            <div class="col-md-4">
              <label class="form-label small mb-1" for="cl<?= $i ?>">Nazwa kryterium</label>
              <input class="form-control form-control-sm" id="cl<?= $i ?>" name="crit_label[<?= $i ?>]"
                     value="<?= h((string)($c['label'] ?? '')) ?>">
            </div>
            <div class="col-md-5">
              <label class="form-label small mb-1" for="ca<?= $i ?>">Słowa/frazy — dowolna z nich</label>
              <textarea class="form-control form-control-sm" id="ca<?= $i ?>" rows="2"
                        name="crit_any[<?= $i ?>]"><?= h($any) ?></textarea>
            </div>
            <div class="col-6 col-md-2">
              <label class="form-label small mb-1" for="cp<?= $i ?>">Punkty</label>
              <input class="form-control form-control-sm" id="cp<?= $i ?>" name="crit_points[<?= $i ?>]"
                     type="text" inputmode="decimal" value="<?= h((string)($c['points'] ?? 1)) ?>">
            </div>
            <div class="col-6 col-md-1 d-flex align-items-end pb-2">
              <div class="form-check mb-0">
                <input class="form-check-input" type="checkbox" id="cr<?= $i ?>" name="crit_required[<?= $i ?>]" value="1"
                       <?= !empty($c['required']) ? 'checked' : '' ?>>
                <label class="form-check-label small" for="cr<?= $i ?>">wym.</label>
              </div>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
      </div>
      <button class="btn btn-sm btn-outline-secondary mt-2" type="button" data-add="criteria">
        <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Dodaj kryterium
      </button>
    </fieldset>

    <div class="row g-2 mb-3">
      <div class="col-md-4">
        <label class="form-label" for="qMinChars">Minimalna długość odpowiedzi</label>
        <input class="form-control" id="qMinChars" name="min_chars" type="number" min="0" max="10000"
               value="<?= (int)$cv('minChars', 0) ?>">
      </div>
      <div class="col-md-8 d-flex align-items-end">
        <div class="form-check mb-2">
          <input class="form-check-input" type="checkbox" id="qManual" name="manual_review"
                 <?= !empty($cv('manual')) ? 'checked' : '' ?> aria-describedby="qManualHelp">
          <label class="form-check-label" for="qManual">Zawsze do oceny prowadzącego</label>
          <div class="form-text mt-0" id="qManualHelp">
            Automat policzy kryteria jako podpowiedź, ale ostateczną punktację wpisujesz sam.
          </div>
        </div>
      </div>
    </div>

    <fieldset class="mb-3">
      <legend class="fs-6 fw-semibold">Porównywanie tekstu</legend>
      <div class="form-check">
        <input class="form-check-input" type="checkbox" id="qCase2" name="case_sensitive"
               <?= !empty($cv('caseSensitive')) ? 'checked' : '' ?>>
        <label class="form-check-label" for="qCase2">Rozróżniaj wielkość liter</label>
      </div>
      <div class="form-check">
        <input class="form-check-input" type="checkbox" id="qAcc2" name="ignore_accents"
               <?= !empty($cv('ignoreAccents')) ? 'checked' : '' ?>>
        <label class="form-check-label" for="qAcc2">Pomijaj polskie znaki diakrytyczne</label>
      </div>
    </fieldset>
<?php endif; ?>

<?php /* ══════════════ ANALIZA / POPRAWA KODU ══════════════ */ ?>
<?php if ($q_type === 'code_fix'): ?>
    <div class="row g-2 mb-3">
      <div class="col-md-5">
        <label class="form-label" for="qLangF">Język</label>
        <select class="form-select" id="qLangF" name="language">
          <?php foreach ($languages as $id => $label): ?>
          <option value="<?= h($id) ?>" <?= (string)$cv('language', 'python') === $id ? 'selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="mb-3">
      <label class="form-label" for="qSnippet">Fragment kodu z błędem <span class="text-danger" aria-hidden="true">*</span></label>
      <textarea class="form-control font-monospace" id="qSnippet" name="snippet" rows="10" spellcheck="false"
                aria-describedby="qSnippetHelp"><?= h((string)$cv('snippet', '')) ?></textarea>
      <div class="form-text" id="qSnippetHelp">Kursant zobaczy ten kod z numeracją linii.</div>
    </div>

    <fieldset class="mb-3">
      <legend class="fs-6 fw-semibold">Czego oczekujesz od kursanta</legend>
      <div class="form-check">
        <input class="form-check-input" type="radio" name="answer_mode" id="qModeLine" value="line"
               <?= (string)$cv('answerMode', 'line') !== 'rewrite' ? 'checked' : '' ?>>
        <label class="form-check-label" for="qModeLine">Wskazanie błędnej linii</label>
      </div>
      <div class="form-check">
        <input class="form-check-input" type="radio" name="answer_mode" id="qModeRewrite" value="rewrite"
               <?= (string)$cv('answerMode', 'line') === 'rewrite' ? 'checked' : '' ?>>
        <label class="form-check-label" for="qModeRewrite">Przepisanie kodu poprawnie (uruchamiane na przypadkach testowych)</label>
      </div>
    </fieldset>

    <div class="row g-2 mb-3">
      <div class="col-md-5">
        <label class="form-label" for="qLines">Numery linii z błędem</label>
        <input class="form-control" id="qLines" name="accept_lines"
               value="<?= h(implode(', ', array_map('strval', (array)$cv('acceptLines', [])))) ?>"
               aria-describedby="qLinesHelp">
        <div class="form-text" id="qLinesHelp">Kilka numerów oddziel przecinkami. Dotyczy trybu wskazania linii.</div>
      </div>
      <div class="col-md-7 d-flex align-items-end">
        <div class="form-check mb-3">
          <input class="form-check-input" type="checkbox" id="qExpl" name="require_explanation"
                 <?= !empty($cv('requireExplanation')) ? 'checked' : '' ?> aria-describedby="qExplHelp">
          <label class="form-check-label" for="qExpl">Wymagaj uzasadnienia</label>
          <div class="form-text mt-0" id="qExplHelp">Uzasadnienie ocenia prowadzący; trafienie linii jest podpowiedzią.</div>
        </div>
      </div>
    </div>
<?php endif; ?>

<?php /* ══════════════ ZADANIE PROGRAMISTYCZNE ══════════════ */ ?>
<?php if ($q_type === 'code_run'): ?>
    <div class="row g-2 mb-3">
      <div class="col-md-5">
        <label class="form-label" for="qLangR">Język</label>
        <select class="form-select" id="qLangR" name="language">
          <?php foreach ($languages as $id => $label): ?>
          <option value="<?= h($id) ?>" <?= (string)$cv('language', 'python') === $id ? 'selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-6 col-md-3">
        <label class="form-label" for="qTime">Limit czasu (ms)</label>
        <input class="form-control" id="qTime" name="time_limit_ms" type="number" min="200" max="15000" step="100"
               value="<?= (int)$cv('timeLimitMs', 3000) ?>">
      </div>
      <div class="col-6 col-md-4">
        <label class="form-label" for="qMem">Limit pamięci (MB)</label>
        <input class="form-control" id="qMem" name="memory_mb" type="number" min="32" max="512" step="32"
               value="<?= (int)$cv('memoryMb', 128) ?>">
      </div>
    </div>
    <div class="mb-3">
      <label class="form-label" for="qStarter">Kod startowy dla kursanta</label>
      <textarea class="form-control font-monospace" id="qStarter" name="starter" rows="6" spellcheck="false"
                aria-describedby="qStarterHelp"><?= h((string)$cv('starter', '')) ?></textarea>
      <div class="form-text" id="qStarterHelp">Nieobowiązkowe — np. nagłówek funkcji albo wczytanie danych wejściowych.</div>
    </div>
<?php endif; ?>

<?php /* ══════════════ LIMITY URUCHOMIENIA (typy z uruchamianiem kodu) ══════════════ */ ?>
<?php if ($q_type === 'code_fix' || $q_type === 'code_completion'): ?>
    <fieldset class="mb-3">
      <legend class="fs-6 fw-semibold">Limity uruchomienia w piaskownicy</legend>
      <div class="row g-2">
        <div class="col-6 col-md-4">
          <label class="form-label small mb-1" for="qTimeX">Limit czasu (ms)</label>
          <input class="form-control form-control-sm" id="qTimeX" name="time_limit_ms" type="number"
                 min="200" max="15000" step="100" value="<?= (int)$cv('timeLimitMs', 3000) ?>">
        </div>
        <div class="col-6 col-md-4">
          <label class="form-label small mb-1" for="qMemX">Limit pamięci (MB)</label>
          <input class="form-control form-control-sm" id="qMemX" name="memory_mb" type="number"
                 min="32" max="512" step="32" value="<?= (int)$cv('memoryMb', 128) ?>">
        </div>
      </div>
      <div class="form-text">Dotyczy trybów, w których kod kursanta jest uruchamiany.</div>
    </fieldset>
<?php endif; ?>

<?php /* ══════════════ PRZYPADKI TESTOWE ══════════════ */ ?>
<?php if (!empty($q_meta['cases'])): ?>
    <fieldset class="mb-3">
      <legend class="fs-6 fw-semibold">Przypadki testowe wejście / wyjście</legend>
      <p class="form-text mt-0" id="caseHelp">
        Przypadki ukryte nie pokazują kursantowi ani danych wejściowych, ani oczekiwanego wyniku —
        służą do wykrywania rozwiązań pisanych „pod testy". Zostaw przynajmniej jeden jawny.
      </p>
      <div class="vstack gap-2" data-repeat="cases">
      <?php
        $rows = $existing_cases;
        for ($i = 0; $i < 2; $i++) {
            $rows[] = ['name' => '', 'stdin' => '', 'expected' => '', 'match_mode' => 'trim',
                       'weight' => 1, 'is_hidden' => 0, 'tolerance' => '0.000001'];
        }
        foreach ($rows as $i => $c): ?>
        <div class="border rounded p-2" data-row>
          <div class="row g-2">
            <div class="col-md-4">
              <label class="form-label small mb-1" for="cn<?= $i ?>">Nazwa</label>
              <input class="form-control form-control-sm" id="cn<?= $i ?>" name="case_name[<?= $i ?>]"
                     value="<?= h((string)($c['name'] ?? '')) ?>">
            </div>
            <div class="col-md-4">
              <label class="form-label small mb-1" for="cm<?= $i ?>">Porównanie</label>
              <select class="form-select form-select-sm" id="cm<?= $i ?>" name="case_match[<?= $i ?>]">
                <?php foreach (K30_TI_EXAM_MATCH as $k => $lbl): ?>
                <option value="<?= h($k) ?>" <?= (string)($c['match_mode'] ?? 'trim') === $k ? 'selected' : '' ?>><?= h($lbl) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6 col-md-2">
              <label class="form-label small mb-1" for="cw<?= $i ?>">Waga</label>
              <input class="form-control form-control-sm" id="cw<?= $i ?>" name="case_weight[<?= $i ?>]"
                     type="text" inputmode="decimal" value="<?= h((string)($c['weight'] ?? 1)) ?>">
            </div>
            <div class="col-6 col-md-2 d-flex align-items-end pb-2">
              <div class="form-check mb-0">
                <input class="form-check-input" type="checkbox" id="ch<?= $i ?>" name="case_hidden[<?= $i ?>]" value="1"
                       <?= !empty($c['is_hidden']) ? 'checked' : '' ?>>
                <label class="form-check-label small" for="ch<?= $i ?>">ukryty</label>
              </div>
            </div>
            <div class="col-md-6">
              <label class="form-label small mb-1" for="ci<?= $i ?>">Wejście standardowe</label>
              <textarea class="form-control form-control-sm font-monospace" id="ci<?= $i ?>" rows="3"
                        name="case_stdin[<?= $i ?>]" spellcheck="false"><?= h((string)($c['stdin'] ?? '')) ?></textarea>
            </div>
            <div class="col-md-6">
              <label class="form-label small mb-1" for="ce<?= $i ?>">Oczekiwane wyjście</label>
              <textarea class="form-control form-control-sm font-monospace" id="ce<?= $i ?>" rows="3"
                        name="case_expected[<?= $i ?>]" spellcheck="false"
                        aria-describedby="caseHelp"><?= h((string)($c['expected'] ?? '')) ?></textarea>
            </div>
            <input type="hidden" name="case_tol[<?= $i ?>]" value="<?= h((string)($c['tolerance'] ?? '0.000001')) ?>">
          </div>
        </div>
      <?php endforeach; ?>
      </div>
      <button class="btn btn-sm btn-outline-secondary mt-2" type="button" data-add="cases">
        <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Dodaj przypadek
      </button>
    </fieldset>

    <fieldset class="mb-3">
      <legend class="fs-6 fw-semibold">Reguły treści rozwiązania</legend>
      <div class="row g-2">
        <div class="col-md-6">
          <label class="form-label small mb-1" for="qForbidden">Konstrukcje zakazane</label>
          <textarea class="form-control form-control-sm font-monospace" id="qForbidden" name="forbidden" rows="3"
                    spellcheck="false" aria-describedby="qPatHelp"><?= h($cl('forbidden')) ?></textarea>
        </div>
        <div class="col-md-6">
          <label class="form-label small mb-1" for="qRequired">Konstrukcje wymagane</label>
          <textarea class="form-control form-control-sm font-monospace" id="qRequired" name="required_patterns" rows="3"
                    spellcheck="false" aria-describedby="qPatHelp"><?= h($cl('required')) ?></textarea>
        </div>
      </div>
      <div class="form-text" id="qPatHelp">
        Po jednym wzorcu w linii (wyrażenie regularne). Przykłady: <code>import\s+socket</code>, <code>while\s</code>.
      </div>
      <div class="form-check mt-2">
        <input class="form-check-input" type="checkbox" id="qAllNone2" name="all_or_nothing"
               <?= !empty($cv('allOrNothing')) ? 'checked' : '' ?>>
        <label class="form-check-label" for="qAllNone2">Punkty tylko za komplet zdanych przypadków</label>
      </div>
    </fieldset>
<?php endif; ?>

    <div class="mb-3">
      <label class="form-label" for="qExplanation">Wyjaśnienie po odpowiedzi</label>
      <textarea class="form-control" id="qExplanation" name="explanation" rows="2"
                aria-describedby="qExplanationHelp"><?= h($editing ? (string)$edit_q['explanation'] : '') ?></textarea>
      <div class="form-text" id="qExplanationHelp">
        Pokazywane w trybie treningowym i po oddaniu pracy — zależnie od ustawień egzaminu.
      </div>
    </div>
  </div>

  <div class="card-footer bg-transparent d-flex gap-2 flex-wrap">
    <button class="btn btn-primary" type="submit">
      <i class="bi bi-save me-1" aria-hidden="true"></i><?= $editing ? 'Zapisz pytanie' : 'Dodaj pytanie' ?>
    </button>
    <?php if ($editing): ?>
    <a class="btn btn-outline-secondary" href="exam_build.php?exam_id=<?= (int)$exam['id'] ?>&amp;new=<?= h($q_type) ?>">
      <i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Kolejne pytanie tego typu
    </a>
    <?php endif; ?>
  </div>
</form>

<script>
// Repeatery: klonowanie ostatniego wiersza. Bez JavaScriptu formularz też
// działa — każda sekcja ma z góry kilka pustych wierszy zapasowych.
(function () {
  document.querySelectorAll('[data-add]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var name = btn.getAttribute('data-add');
      var box  = document.querySelector('[data-repeat="' + name + '"]');
      if (!box) return;
      var rows = box.querySelectorAll('[data-row]');
      var last = rows[rows.length - 1];
      if (!last) return;
      var idx   = rows.length;
      var clone = last.cloneNode(true);
      clone.querySelectorAll('input, textarea, select').forEach(function (el) {
        if (el.name) el.name = el.name.replace(/\[\d+\]$/, '[' + idx + ']');
        if (el.id) {
          var oldId = el.id;
          el.id = oldId.replace(/\d+$/, '') + idx;
          var lbl = clone.querySelector('label[for="' + oldId + '"]');
          if (lbl) lbl.setAttribute('for', el.id);
        }
        if (el.type === 'checkbox' || el.type === 'radio') el.checked = false;
        else if (el.tagName !== 'SELECT') el.value = '';
      });
      box.appendChild(clone);
      var first = clone.querySelector('input, textarea, select');
      if (first) first.focus();
    });
  });
})();
</script>
