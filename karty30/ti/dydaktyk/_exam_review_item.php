<?php
/**
 * karty30/ti/dydaktyk/_exam_review_item.php
 * Jedna pozycja we wglądzie w podejście: treść pytania, odpowiedź kursanta,
 * rozstrzygnięcie silnika i pole na punkty prowadzącego.
 *
 * Zmienne wejściowe (z exam_review.php): $exam, $q, $qid, $ans, $payload,
 * $result, $review, $n, $orderMap.
 */
$details  = (array)($result['details'] ?? []);
$awarded  = $ans && $ans['points_awarded'] !== null ? (float)$ans['points_awarded'] : null;
$maxp     = (float)$q['points'];
$qtype    = (string)$q['type'];
$fmt      = fn(float $v): string => rtrim(rtrim(number_format($v, 2, ',', ' '), '0'), ',');
$qcfg     = ti_exam_config($q);
?>
<section class="card border-0 shadow-sm mb-3" aria-labelledby="rq<?= $qid ?>">
  <div class="card-header bg-transparent d-flex flex-wrap gap-2 align-items-center">
    <h2 class="h6 mb-0 fw-semibold" id="rq<?= $qid ?>">
      Pytanie <?= (int)$n + 1 ?>
      <span class="text-body-secondary fw-normal">· <?= h(ti_exam_type_label($qtype)) ?></span>
    </h2>
    <span class="ms-auto">
      <?php if ($review): ?>
        <span class="badge text-bg-warning"><i class="bi bi-hourglass-split me-1" aria-hidden="true"></i>do oceny</span>
      <?php elseif ($awarded !== null && $maxp > 0 && $awarded >= $maxp - 1e-9): ?>
        <span class="badge text-bg-success"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>pełne punkty</span>
      <?php elseif ($awarded !== null && $awarded > 0): ?>
        <span class="badge text-bg-info"><i class="bi bi-dash-lg me-1" aria-hidden="true"></i>częściowo</span>
      <?php else: ?>
        <span class="badge text-bg-secondary"><i class="bi bi-x-lg me-1" aria-hidden="true"></i>bez punktów</span>
      <?php endif; ?>
      <span class="badge text-bg-light text-dark border">
        <?= $awarded === null ? '—' : h($fmt($awarded)) ?> / <?= h($fmt($maxp)) ?> pkt
      </span>
    </span>
  </div>

  <div class="card-body">
    <p class="mb-3"><?= nl2br(h((string)$q['prompt'])) ?></p>

<?php if (in_array($qtype, ['single', 'multi'], true)):
    $picked = array_map('intval', (array)($payload['optionIds'] ?? [])); ?>
    <ul class="list-unstyled mb-3">
    <?php foreach (ti_exam_options_ordered($qid, $orderMap) as $o):
        $isPicked  = in_array((int)$o['id'], $picked, true);
        $isCorrect = (int)$o['is_correct'] === 1; ?>
      <li class="d-flex gap-2 align-items-start mb-1">
        <span class="badge text-bg-<?= $isPicked ? ($isCorrect ? 'success' : 'danger') : 'light' ?> <?= $isPicked ? '' : 'text-dark border' ?>">
          <?= $isPicked ? 'wybrane' : '—' ?>
        </span>
        <span><?= h((string)$o['label']) ?>
          <?php if ($isCorrect): ?><span class="text-success small">(poprawny)</span><?php endif; ?>
        </span>
      </li>
    <?php endforeach; ?>
    </ul>

<?php elseif ($qtype === 'truefalse'):
    $given = (array)($payload['statements'] ?? []); ?>
    <div class="table-responsive mb-3">
      <table class="table table-sm align-middle">
        <caption class="visually-hidden">Twierdzenia z odpowiedzią kursanta i wartością prawdziwą</caption>
        <thead><tr><th scope="col">Twierdzenie</th><th scope="col">Kursant</th><th scope="col">Prawidłowo</th></tr></thead>
        <tbody>
        <?php foreach (ti_exam_options_ordered($qid, $orderMap) as $o):
          $key = (string)(int)$o['id'];
          $has = array_key_exists($key, $given);
          $val = $has ? (bool)$given[$key] : null;
          $exp = (int)$o['is_correct'] === 1;
          $ok  = $has && $val === $exp; ?>
          <tr>
            <th scope="row" class="fw-normal"><?= h((string)$o['label']) ?></th>
            <td><span class="badge text-bg-<?= $has ? ($ok ? 'success' : 'danger') : 'light text-dark border' ?>">
              <?= $has ? ($val ? 'prawda' : 'fałsz') : 'brak odpowiedzi' ?></span></td>
            <td class="small"><?= $exp ? 'prawda' : 'fałsz' ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

<?php elseif (in_array($qtype, ['fill_blank', 'code_completion'], true)):
    $given = (array)($payload['blanks'] ?? []);
    $rows  = (array)($details['blanks'] ?? []); ?>
    <?php if ($qtype === 'code_completion' && ($qcfg['template'] ?? '') !== ''): ?>
      <pre class="bg-body-tertiary border rounded p-2 small"><code><?= h((string)$qcfg['template']) ?></code></pre>
    <?php endif; ?>
    <div class="table-responsive mb-3">
      <table class="table table-sm align-middle">
        <caption class="visually-hidden">Luki i odpowiedzi kursanta</caption>
        <thead><tr><th scope="col">Luka</th><th scope="col">Odpowiedź kursanta</th><th scope="col">Ocena</th></tr></thead>
        <tbody>
        <?php foreach ((array)($qcfg['blanks'] ?? []) as $i => $b):
          $key = (string)($b['key'] ?? ($i + 1));
          $val = (string)($given[$key] ?? '');
          $ok  = null;
          foreach ($rows as $r) if ((string)($r['key'] ?? '') === $key) $ok = !empty($r['correct']); ?>
          <tr>
            <th scope="row" class="fw-normal font-monospace"><?= h($key) ?></th>
            <td class="font-monospace"><?= $val === '' ? '<span class="text-body-secondary">—</span>' : h($val) ?></td>
            <td><span class="badge text-bg-<?= $ok === null ? 'light text-dark border' : ($ok ? 'success' : 'danger') ?>">
              <?= $ok === null ? 'nieoceniona' : ($ok ? 'poprawna' : 'błędna') ?></span>
              <span class="small text-body-secondary d-block">akceptowane: <?= h(implode(', ', (array)($b['accept'] ?? []))) ?></span>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

<?php elseif ($qtype === 'short_answer'): ?>
    <div class="border rounded p-2 bg-body-tertiary mb-3">
      <?= nl2br(h((string)($payload['text'] ?? ''))) ?: '<span class="text-body-secondary">Brak odpowiedzi.</span>' ?>
    </div>
    <?php if (!empty($details['criteria'])): ?>
    <ul class="list-inline small mb-3">
      <?php foreach ((array)$details['criteria'] as $c): ?>
      <li class="list-inline-item">
        <span class="badge text-bg-<?= !empty($c['met']) ? 'success' : 'secondary' ?>">
          <?= !empty($c['met']) ? '✓' : '✗' ?> <?= h((string)($c['label'] ?? '')) ?>
        </span>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <?php if (isset($details['suggestedPoints'])): ?>
      <p class="small text-body-secondary">Sugestia automatu: <?= h($fmt((float)$details['suggestedPoints'])) ?> pkt.</p>
    <?php endif; ?>

<?php elseif ($qtype === 'code_fix'): ?>
    <?php if (($qcfg['snippet'] ?? '') !== ''): ?>
    <pre class="bg-body-tertiary border rounded p-2 small"><code><?php
      foreach (preg_split('/\R/', (string)$qcfg['snippet']) as $i => $line) {
          printf("%3d  %s\n", $i + 1, h($line));
      }
    ?></code></pre>
    <?php endif; ?>
    <?php if (($qcfg['answerMode'] ?? 'line') !== 'rewrite'): ?>
      <p class="mb-2">
        Wskazana linia: <strong><?= isset($payload['line']) ? (int)$payload['line'] : '—' ?></strong>,
        oczekiwana: <strong><?= h(implode(', ', array_map('strval', (array)($qcfg['acceptLines'] ?? [])))) ?></strong>
      </p>
      <?php if (trim((string)($payload['text'] ?? '')) !== ''): ?>
      <div class="border rounded p-2 bg-body-tertiary mb-3">
        <div class="small text-body-secondary mb-1">Uzasadnienie kursanta</div>
        <?= nl2br(h((string)$payload['text'])) ?>
      </div>
      <?php endif; ?>
    <?php else: ?>
      <pre class="bg-body-tertiary border rounded p-2 small"><code><?= h((string)($payload['code'] ?? '')) ?></code></pre>
    <?php endif; ?>

<?php elseif ($qtype === 'code_run'): ?>
    <pre class="bg-body-tertiary border rounded p-2 small"><code><?= h((string)($payload['code'] ?? '')) ?></code></pre>
<?php endif; ?>

<?php /* Wyniki uruchomienia — wspólne dla wszystkich typów z piaskownicą */ ?>
<?php if (!empty($details['cases'])): ?>
    <div class="table-responsive mb-3">
      <table class="table table-sm align-middle">
        <caption class="visually-hidden">Wyniki przypadków testowych</caption>
        <thead><tr>
          <th scope="col">Przypadek</th><th scope="col">Wynik</th>
          <th scope="col">Wyjście programu</th><th scope="col" class="text-end">Czas</th>
        </tr></thead>
        <tbody>
        <?php foreach ((array)$details['cases'] as $c): ?>
          <tr>
            <th scope="row" class="fw-normal">
              <?= h((string)($c['name'] ?? '')) ?>
              <?php if (!empty($c['hidden'])): ?><span class="badge text-bg-light text-dark border">ukryty</span><?php endif; ?>
            </th>
            <td><span class="badge text-bg-<?= !empty($c['passed']) ? 'success' : 'danger' ?>">
              <?= !empty($c['passed']) ? 'zdany' : (!empty($c['timedOut']) ? 'przekroczony czas' : 'niezdany') ?></span></td>
            <td><pre class="mb-0 small"><code><?= h((string)($c['stdout'] ?? '')) ?></code></pre>
              <?php if (trim((string)($c['stderr'] ?? '')) !== ''): ?>
              <details><summary class="small">Błędy wykonania</summary>
                <pre class="mb-0 small text-danger"><code><?= h((string)$c['stderr']) ?></code></pre></details>
              <?php endif; ?>
            </td>
            <td class="text-end small"><?= (int)($c['timeMs'] ?? 0) ?> ms</td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
<?php endif; ?>

<?php if (!empty($details['compileError'])): ?>
    <div class="alert alert-danger py-2 small" role="alert">
      <strong>Kod się nie kompiluje.</strong>
      <pre class="mb-0 mt-1"><code><?= h((string)$details['compileError']) ?></code></pre>
    </div>
<?php endif; ?>

    <?php if (trim((string)($ans['feedback'] ?? '')) !== ''): ?>
    <p class="small"><span class="fw-semibold">Automat:</span> <?= h((string)$ans['feedback']) ?></p>
    <?php endif; ?>

    <div class="row g-2 align-items-end border-top pt-3">
      <div class="col-6 col-md-3">
        <label class="form-label small mb-1" for="pts<?= $qid ?>">Punkty prowadzącego</label>
        <input class="form-control form-control-sm" id="pts<?= $qid ?>" name="pts[<?= $qid ?>]"
               type="text" inputmode="decimal" placeholder="0 – <?= h($fmt($maxp)) ?>"
               value="" aria-describedby="pts<?= $qid ?>Help">
        <div class="form-text" id="pts<?= $qid ?>Help">Puste = zostaw ocenę automatu.</div>
      </div>
      <div class="col-12 col-md-9">
        <label class="form-label small mb-1" for="note<?= $qid ?>">Komentarz dla kursanta</label>
        <input class="form-control form-control-sm" id="note<?= $qid ?>" name="note[<?= $qid ?>]"
               value="<?= h((string)($ans['teacher_note'] ?? '')) ?>">
      </div>
    </div>
  </div>
</section>
