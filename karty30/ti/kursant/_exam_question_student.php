<?php
/**
 * karty30/ti/kursant/_exam_question_student.php
 * Jedno pytanie na arkuszu kursanta.
 *
 * Zmienne wejściowe (z _exam_sheet.php): $exam, $attempt, $q, $qid, $n,
 * $payload, $qcfg, $orderMap, $immediate, $languages.
 *
 * Każde pytanie to <fieldset> z <legend> — czytnik ekranu ogłasza numer i treść
 * polecenia przed każdym wariantem. Warianty mają własne etykiety powiązane
 * przez `for`/`id`, a pola tekstowe opis pomocniczy przez aria-describedby.
 */
$type  = (string)$q['type'];
$meta  = K30_TI_EXAM_TYPES[$type] ?? K30_TI_EXAM_TYPES['single'];
$pts   = rtrim(rtrim(number_format((float)$q['points'], 2, ',', ''), '0'), ',');
$base  = 'a[' . $qid . ']';
$lang  = (string)($qcfg['language'] ?? 'python');
?>
<fieldset class="card border-0 shadow-sm mb-4">
  <div class="card-body">
    <legend class="h6 fw-semibold float-none w-auto mb-3">
      <span class="badge text-bg-primary me-1">Pytanie <?= (int)$n + 1 ?></span>
      <span class="visually-hidden">, <?= h((string)$meta['label']) ?>, za <?= h($pts) ?> punktów: </span>
      <span class="badge text-bg-light text-dark border" aria-hidden="true"><?= h($pts) ?> pkt</span>
      <span class="d-block mt-2 fw-normal">
        <?php if ($type === 'fill_blank'):
          // Znaczniki [[klucz]] zamieniamy na pola do wpisania wprost w zdaniu.
          $html = h((string)$q['prompt']);
          foreach ((array)($qcfg['blanks'] ?? []) as $i => $b) {
              $key   = (string)($b['key'] ?? ($i + 1));
              $val   = (string)(($payload['blanks'] ?? [])[$key] ?? '');
              $hint  = trim((string)($b['hint'] ?? ''));
              $id    = 'b' . $qid . '_' . preg_replace('/[^A-Za-z0-9_]/', '', $key);
              $field = '<label class="visually-hidden" for="' . $id . '">Luka ' . h($key)
                     . ($hint !== '' ? ' — ' . h($hint) : '') . '</label>'
                     . '<input class="form-control form-control-sm d-inline-block align-baseline mx-1'
                     . '" style="width:12ch" id="' . $id . '" type="text" autocomplete="off"'
                     . ' name="' . h($base) . '[blanks][' . h($key) . ']" value="' . h($val) . '"'
                     . ($hint !== '' ? ' placeholder="' . h($hint) . '"' : '') . '>';
              $html = str_replace('[[' . $key . ']]', $field, $html);
          }
          echo nl2br($html);
        else:
          echo nl2br(h((string)$q['prompt']));
        endif; ?>
      </span>
    </legend>

<?php /* ── Jednokrotny / wielokrotny wybór ─────────────────────────────── */ ?>
<?php if ($type === 'single' || $type === 'multi'):
  $picked = array_map('intval', (array)($payload['optionIds'] ?? [])); ?>
    <?php if ($type === 'multi'): ?>
      <p class="form-text mt-0" id="hint<?= $qid ?>">
        <i class="bi bi-info-circle me-1" aria-hidden="true"></i>Poprawnych odpowiedzi może być kilka.
        Błędne zaznaczenie obniża punktację, więc nie zaznaczaj wszystkiego „na wszelki wypadek".
      </p>
    <?php endif; ?>
    <?php foreach (ti_exam_options_ordered($qid, $orderMap) as $i => $o): ?>
    <div class="form-check py-1">
      <input class="form-check-input" type="<?= $type === 'single' ? 'radio' : 'checkbox' ?>"
             id="o<?= $qid ?>_<?= (int)$o['id'] ?>"
             name="<?= h($base) ?>[optionIds]<?= $type === 'multi' ? '[]' : '' ?>"
             value="<?= (int)$o['id'] ?>"
             <?= in_array((int)$o['id'], $picked, true) ? 'checked' : '' ?>
             <?= $type === 'multi' ? 'aria-describedby="hint' . $qid . '"' : '' ?>>
      <label class="form-check-label" for="o<?= $qid ?>_<?= (int)$o['id'] ?>"><?= h((string)$o['label']) ?></label>
    </div>
    <?php endforeach; ?>

<?php /* ── Prawda / fałsz ──────────────────────────────────────────────── */ ?>
<?php elseif ($type === 'truefalse'):
  $given = (array)($payload['statements'] ?? []); ?>
    <div class="table-responsive">
      <table class="table table-sm align-middle mb-0">
        <caption class="visually-hidden">Oceń każde twierdzenie jako prawdziwe albo fałszywe</caption>
        <thead>
          <tr><th scope="col">Twierdzenie</th>
              <th scope="col" class="text-center">Prawda</th>
              <th scope="col" class="text-center">Fałsz</th></tr>
        </thead>
        <tbody>
        <?php foreach (ti_exam_options_ordered($qid, $orderMap) as $o):
          $oid = (int)$o['id'];
          $key = (string)$oid;
          $cur = array_key_exists($key, $given) ? ($given[$key] ? '1' : '0') : ''; ?>
          <tr>
            <th scope="row" class="fw-normal" id="st<?= $qid ?>_<?= $oid ?>"><?= h((string)$o['label']) ?></th>
            <td class="text-center">
              <input class="form-check-input" type="radio" value="1"
                     id="st<?= $qid ?>_<?= $oid ?>_t" name="<?= h($base) ?>[statements][<?= $oid ?>]"
                     <?= $cur === '1' ? 'checked' : '' ?>
                     aria-labelledby="st<?= $qid ?>_<?= $oid ?> st<?= $qid ?>_<?= $oid ?>_tl">
              <span class="visually-hidden" id="st<?= $qid ?>_<?= $oid ?>_tl">prawda</span>
            </td>
            <td class="text-center">
              <input class="form-check-input" type="radio" value="0"
                     id="st<?= $qid ?>_<?= $oid ?>_f" name="<?= h($base) ?>[statements][<?= $oid ?>]"
                     <?= $cur === '0' ? 'checked' : '' ?>
                     aria-labelledby="st<?= $qid ?>_<?= $oid ?> st<?= $qid ?>_<?= $oid ?>_fl">
              <span class="visually-hidden" id="st<?= $qid ?>_<?= $oid ?>_fl">fałsz</span>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

<?php /* ── Dopasowywanie par ───────────────────────────────────────────── */ ?>
<?php elseif ($type === 'matching'):
  // Lewe elementy w kolejności autora, prawa kolumna w kolejności wylosowanej
  // dla podejścia. Wartości opcji to klucze z silnika — nie zdradzają pary.
  $pairsL = ex_matching_pairs($qcfg);
  $poolR  = ti_exam_items_ordered(ex_matching_right_pool($qcfg), $orderMap[$qid] ?? []);
  $given  = (array)($payload['pairs'] ?? []); ?>
    <p class="form-text mt-0" id="mh<?= $qid ?>">
      <i class="bi bi-info-circle me-1" aria-hidden="true"></i>Do każdego elementu wybierz z listy pasujący element.
      <?php if (count($poolR) > count($pairsL)): ?>Na liście są też elementy, które nie pasują do niczego.<?php endif; ?>
    </p>
    <?php foreach ($pairsL as $i => $p):
      $fid = 'm' . $qid . '_' . $p['key'];
      $cur = (string)($given[$p['key']] ?? ''); ?>
    <div class="row g-2 align-items-center py-1">
      <div class="col-sm-5">
        <label class="form-label mb-0" for="<?= h($fid) ?>"><?= h($p['left']) ?><span class="visually-hidden"> — wybierz pasujący element</span></label>
      </div>
      <div class="col-sm-7">
        <select class="form-select" id="<?= h($fid) ?>" name="<?= h($base) ?>[pairs][<?= h($p['key']) ?>]"
                aria-describedby="mh<?= $qid ?>">
          <option value="">— wybierz —</option>
          <?php foreach ($poolR as $r): ?>
          <option value="<?= h($r['key']) ?>" <?= $cur === $r['key'] ? 'selected' : '' ?>><?= h($r['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <?php endforeach; ?>

<?php /* ── Ustaw w kolejności ──────────────────────────────────────────── */ ?>
<?php elseif ($type === 'ordering'):
  // Elementy w kolejności wylosowanej (nigdy poprawnej); przy każdym numer pozycji.
  // Bez przeciągania — zwykłe listy wyboru działają z klawiatury, czytnikiem i bez JS.
  $itemsO = ti_exam_items_ordered(ex_ordering_items($qcfg), $orderMap[$qid] ?? []);
  $cntO   = count($itemsO);
  $given  = (array)($payload['order'] ?? []);
  $issue  = ti_exam_answer_issue($q, $payload); ?>
    <p class="form-text mt-0" id="oh<?= $qid ?>">
      <i class="bi bi-info-circle me-1" aria-hidden="true"></i>Przy każdym elemencie wybierz jego miejsce w kolejności:
      1 to pierwszy, <?= $cntO ?> to ostatni. Każdy numer wybierz tylko raz.
    </p>
    <?php if ($issue !== null): ?>
    <div class="alert alert-warning d-flex gap-2 py-2" id="oi<?= $qid ?>">
      <i class="bi bi-exclamation-triangle flex-shrink-0" aria-hidden="true"></i>
      <span><span class="fw-semibold">Uwaga:</span> <?= h($issue) ?></span>
    </div>
    <?php endif; ?>
    <?php foreach ($itemsO as $it):
      $fid = 'ord' . $qid . '_' . $it['key'];
      $cur = (int)($given[$it['key']] ?? 0); ?>
    <div class="row g-2 align-items-center py-1">
      <div class="col-sm-8">
        <label class="form-label mb-0" for="<?= h($fid) ?>"><?= h($it['label']) ?><span class="visually-hidden"> — pozycja w kolejności</span></label>
      </div>
      <div class="col-sm-4">
        <select class="form-select" id="<?= h($fid) ?>" name="<?= h($base) ?>[order][<?= h($it['key']) ?>]"
                aria-describedby="oh<?= $qid ?><?= $issue !== null ? ' oi' . $qid : '' ?>">
          <option value="">— pozycja —</option>
          <?php for ($pos = 1; $pos <= $cntO; $pos++): ?>
          <option value="<?= $pos ?>" <?= $cur === $pos ? 'selected' : '' ?>><?= $pos ?><?= $pos === 1 ? ' (pierwszy)' : ($pos === $cntO ? ' (ostatni)' : '') ?></option>
          <?php endfor; ?>
        </select>
      </div>
    </div>
    <?php endforeach; ?>

<?php /* ── Krótka odpowiedź ────────────────────────────────────────────── */ ?>
<?php elseif ($type === 'short_answer'):
  $min = (int)($qcfg['minChars'] ?? 0); ?>
    <label class="visually-hidden" for="t<?= $qid ?>">Twoja odpowiedź na pytanie <?= (int)$n + 1 ?></label>
    <textarea class="form-control" id="t<?= $qid ?>" name="<?= h($base) ?>[text]" rows="5"
              <?= $min > 0 ? 'aria-describedby="tm' . $qid . '"' : '' ?>><?= h((string)($payload['text'] ?? '')) ?></textarea>
    <?php if ($min > 0): ?>
      <div class="form-text" id="tm<?= $qid ?>">Odpowiedź powinna mieć co najmniej <?= $min ?> znaków.</div>
    <?php endif; ?>

<?php /* ── Analiza / poprawa kodu ──────────────────────────────────────── */ ?>
<?php elseif ($type === 'code_fix'):
  $snippet = (string)($qcfg['snippet'] ?? '');
  $lines   = $snippet === '' ? [] : preg_split('/\R/', $snippet);
  $rewrite = (string)($qcfg['answerMode'] ?? 'line') === 'rewrite'; ?>
    <div class="table-responsive mb-3">
      <table class="table table-sm mb-0 font-monospace" style="font-size:.85rem">
        <caption class="visually-hidden">Fragment kodu w języku <?= h($languages[$lang] ?? $lang) ?> z numeracją linii</caption>
        <thead class="visually-hidden"><tr><th scope="col">Numer linii</th><th scope="col">Kod</th></tr></thead>
        <tbody>
        <?php foreach ($lines as $i => $line): ?>
          <tr>
            <th scope="row" class="text-end text-body-secondary" style="width:3rem"><?= $i + 1 ?></th>
            <td><pre class="mb-0"><code><?= h($line) ?></code></pre></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if (!$rewrite): ?>
      <div class="row g-2">
        <div class="col-sm-4">
          <label class="form-label" for="ln<?= $qid ?>">Numer linii z błędem</label>
          <input class="form-control" id="ln<?= $qid ?>" type="number" min="1" max="<?= max(1, count($lines)) ?>"
                 name="<?= h($base) ?>[line]" value="<?= isset($payload['line']) ? (int)$payload['line'] : '' ?>">
        </div>
        <?php if (!empty($qcfg['requireExplanation'])): ?>
        <div class="col-sm-8">
          <label class="form-label" for="ex<?= $qid ?>">Na czym polega błąd</label>
          <textarea class="form-control" id="ex<?= $qid ?>" rows="3"
                    name="<?= h($base) ?>[text]"><?= h((string)($payload['text'] ?? '')) ?></textarea>
        </div>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <label class="form-label" for="c<?= $qid ?>">Poprawiony kod</label>
      <textarea class="form-control font-monospace" id="c<?= $qid ?>" name="<?= h($base) ?>[code]" rows="12"
                spellcheck="false" autocapitalize="off" autocorrect="off"
                aria-describedby="cd<?= $qid ?>"><?= h((string)($payload['code'] ?? $snippet)) ?></textarea>
      <div class="form-text" id="cd<?= $qid ?>">Język: <?= h($languages[$lang] ?? $lang) ?>. Kod zostanie uruchomiony na danych testowych.</div>
    <?php endif; ?>

<?php /* ── Luki w kodzie ───────────────────────────────────────────────── */ ?>
<?php elseif ($type === 'code_completion'):
  $tpl    = (string)($qcfg['template'] ?? '');
  $blanks = (array)($qcfg['blanks'] ?? []); ?>
    <pre class="bg-body-tertiary border rounded p-2 mb-3"><code><?= h($tpl) ?></code></pre>
    <div class="row g-2">
      <?php foreach ($blanks as $i => $b):
        $key  = (string)($b['key'] ?? ($i + 1));
        $hint = trim((string)($b['hint'] ?? ''));
        $val  = (string)(($payload['blanks'] ?? [])[$key] ?? '');
        $id   = 'cb' . $qid . '_' . preg_replace('/[^A-Za-z0-9_]/', '', $key); ?>
      <div class="col-md-6">
        <label class="form-label" for="<?= $id ?>">
          Luka <code>___<?= h($key) ?>___</code><?= $hint !== '' ? ' — ' . h($hint) : '' ?>
        </label>
        <input class="form-control font-monospace" id="<?= $id ?>" type="text" autocomplete="off"
               name="<?= h($base) ?>[blanks][<?= h($key) ?>]" value="<?= h($val) ?>">
      </div>
      <?php endforeach; ?>
    </div>

<?php /* ── Zadanie programistyczne ─────────────────────────────────────── */ ?>
<?php elseif ($type === 'code_run'):
  $cases = ti_exam_cases($qid); ?>
    <?php
      $visible = array_values(array_filter($cases, fn($c) => (int)$c['is_hidden'] === 0));
      if ($visible): ?>
    <div class="mb-3">
      <h3 class="h6 fw-semibold">Przykładowe dane</h3>
      <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
          <caption class="visually-hidden">Przykładowe wejście i oczekiwane wyjście</caption>
          <thead><tr><th scope="col">Wejście</th><th scope="col">Oczekiwane wyjście</th></tr></thead>
          <tbody>
          <?php foreach ($visible as $c): ?>
            <tr>
              <td><pre class="mb-0 small"><code><?= h((string)$c['stdin']) ?></code></pre></td>
              <td><pre class="mb-0 small"><code><?= h((string)$c['expected']) ?></code></pre></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>

    <label class="form-label" for="c<?= $qid ?>">Twoje rozwiązanie</label>
    <textarea class="form-control font-monospace" id="c<?= $qid ?>" name="<?= h($base) ?>[code]" rows="14"
              spellcheck="false" autocapitalize="off" autocorrect="off"
              aria-describedby="cd<?= $qid ?>"><?= h((string)($payload['code'] ?? ($qcfg['starter'] ?? ''))) ?></textarea>
    <div class="form-text" id="cd<?= $qid ?>">
      Język: <?= h($languages[$lang] ?? $lang) ?>. Program czyta dane z wejścia standardowego i wypisuje wynik na wyjście.
    </div>
<?php endif; ?>

<?php if ($immediate): ?>
    <button class="btn btn-outline-primary btn-sm mt-3" type="button" data-check="<?= $qid ?>">
      <i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Sprawdź tę odpowiedź
    </button>
    <div id="fb<?= $qid ?>" hidden role="status" aria-live="polite"></div>
<?php endif; ?>
  </div>
</fieldset>
