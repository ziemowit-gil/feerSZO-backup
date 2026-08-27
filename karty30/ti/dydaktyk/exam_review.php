<?php
/**
 * karty30/ti/dydaktyk/exam_review.php
 * Equi Exams — podejścia kursantów, ocena pytań opisowych i statystyka zestawu.
 *
 *   ?exam_id=N     — lista podejść + statystyka zestawu (liczona przez silnik),
 *   ?attempt=ID    — wgląd w pojedyncze podejście + panel oceny ręcznej.
 *
 * Punkty wpisane przez prowadzącego przelicza silnik Java
 * (POST /api/exam/manual-grade) — PHP zapisuje gotowy wynik.
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_exams.php';

karty30_migrate();
$me  = dyd_require();
$uid = (int)$me['user_id'];

$my_cids = array_map(fn($c) => (int)$c['id'], dyd_courses($uid));

$assert_exam = function (int $eid) use ($my_cids): array {
    $e = $eid ? ti_exam_get($eid) : null;
    if (!$e || !in_array((int)$e['course_id'], $my_cids, true)) {
        http_response_code(403); exit('Brak uprawnień do tego egzaminu.');
    }
    return $e;
};
$assert_attempt = function (int $aid) use ($assert_exam): array {
    $a = $aid ? ti_exam_attempt_get($aid) : null;
    if (!$a) { http_response_code(404); exit('Nie ma takiego podejścia.'); }
    $assert_exam((int)$a['exam_id']);
    return $a;
};

// ═══════════════════════════════ OBSŁUGA POST ═══════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    $op = (string)($_POST['_op'] ?? '');

    if ($op === 'grade_manual') {
        $a = $assert_attempt((int)($_POST['attempt_id'] ?? 0));
        $r = ti_exam_grade_manual(
            (int)$a['id'],
            (array)($_POST['pts']  ?? []),
            (array)($_POST['note'] ?? []),
            $uid
        );
        flash_set($r['ok'] ? 'success' : 'danger',
            $r['ok'] ? 'Ocena zapisana.' : ('Nie udało się zapisać oceny. ' . (string)$r['error']));
        header('Location: exam_review.php?attempt=' . (int)$a['id']); exit;
    }

    if ($op === 'regrade') {
        $a = $assert_attempt((int)($_POST['attempt_id'] ?? 0));
        $r = ti_exam_regrade((int)$a['id']);
        flash_set($r['ok'] ? 'success' : 'danger',
            $r['ok'] ? 'Podejście ocenione ponownie.' : ('Silnik nie odpowiedział. ' . (string)$r['error']));
        header('Location: exam_review.php?attempt=' . (int)$a['id']); exit;
    }

    if ($op === 'regrade_pending') {
        $e = $assert_exam((int)($_POST['exam_id'] ?? 0));
        $ok = $fail = 0;
        foreach (db_all("SELECT id FROM k30_ti_exam_attempts WHERE exam_id=? AND status='submitted'", [(int)$e['id']]) as $row) {
            $r = ti_exam_regrade((int)$row['id']);
            if ($r['ok']) $ok++; else $fail++;
        }
        flash_set($fail ? 'warning' : 'success',
            'Ocenione ponownie: ' . $ok . ($fail ? ', nieudane: ' . $fail . ' (silnik niedostępny).' : '.'));
        header('Location: exam_review.php?exam_id=' . (int)$e['id']); exit;
    }

    header('Location: exam_review.php'); exit;
}

// ═══════════════════════════════ WIDOK ══════════════════════════════════════
$attempt = isset($_GET['attempt']) ? $assert_attempt((int)$_GET['attempt']) : null;
$exam    = $attempt ? $assert_exam((int)$attempt['exam_id']) : $assert_exam((int)($_GET['exam_id'] ?? 0));

$KP_SKIP_TAB_MEMORY = true;
$KP_TITLE  = 'Podejścia: ' . (string)$exam['title'];
$KP_TOPBAR = ['brand' => EQUI_EXAMS_NAME . ' — ' . EQUI_EXAMS_STAFF_LABEL, 'icon' => 'clipboard-check',
              'user' => (string)($me['name'] ?? ''), 'logout' => 'logout.php'];
include dirname(__DIR__) . '/kursant/_layout_head.php';
?>
<main id="main" class="container-xl py-4">

  <nav aria-label="Ścieżka nawigacji" class="mb-2">
    <ol class="breadcrumb small mb-0">
      <li class="breadcrumb-item"><a href="exam_build.php?course_id=<?= (int)$exam['course_id'] ?>">Egzaminy</a></li>
      <li class="breadcrumb-item"><a href="exam_build.php?exam_id=<?= (int)$exam['id'] ?>"><?= h((string)$exam['title']) ?></a></li>
      <li class="breadcrumb-item active" aria-current="page"><?= $attempt ? 'Podejście' : 'Podejścia' ?></li>
    </ol>
  </nav>

  <?= flash_html() ?>

<?php if (!$attempt):
  $attempts = ti_exam_attempts_for_exam((int)$exam['id']);
  $stats_error = null;
  $stats = ti_exam_stats((int)$exam['id'], $stats_error);
?>
  <h1 class="h4 fw-bold mb-3">
    <i class="bi bi-clipboard-check text-primary me-2" aria-hidden="true"></i>Podejścia — <?= h((string)$exam['title']) ?>
  </h1>

  <?php
    $pending = 0;
    foreach ($attempts as $a) if ((int)$a['needs_review'] === 1) $pending++;
    if ($pending): ?>
  <div class="alert alert-warning d-flex align-items-center gap-2 flex-wrap" role="alert">
    <i class="bi bi-hourglass-split fs-5" aria-hidden="true"></i>
    <span><strong><?= $pending ?></strong> <?= $pending === 1 ? 'podejście czeka' : 'podejść czeka' ?> na ocenę.</span>
    <form method="post" class="ms-auto">
      <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
      <input type="hidden" name="_op" value="regrade_pending">
      <input type="hidden" name="exam_id" value="<?= (int)$exam['id'] ?>">
      <button class="btn btn-sm btn-outline-dark" type="submit">
        <i class="bi bi-arrow-clockwise me-1" aria-hidden="true"></i>Oceń ponownie automatem
      </button>
    </form>
  </div>
  <?php endif; ?>

  <?php if (!$attempts): ?>
    <p class="text-body-secondary">Nikt jeszcze nie podszedł do tego egzaminu.</p>
  <?php else: ?>
  <div class="table-responsive mb-4">
    <table class="table table-hover align-middle">
      <caption class="visually-hidden">Podejścia kursantów wraz z wynikiem i stanem oceny</caption>
      <thead><tr>
        <th scope="col">Kursant</th><th scope="col" class="text-end">Nr</th>
        <th scope="col" class="text-end">Wynik</th><th scope="col">Stan</th>
        <th scope="col">Oddano</th><th scope="col"><span class="visually-hidden">Akcje</span></th>
      </tr></thead>
      <tbody>
      <?php foreach ($attempts as $a):
        $pct    = ti_exam_pct($a);
        $passed = ti_exam_passed($exam, $a); ?>
        <tr>
          <th scope="row" class="fw-normal"><?= h((string)$a['client_name']) ?></th>
          <td class="text-end"><?= (int)$a['attempt_no'] ?></td>
          <td class="text-end">
            <?php if ($a['status'] === 'in_progress'): ?>
              <span class="text-body-secondary">—</span>
            <?php else: ?>
              <?= h(rtrim(rtrim(number_format((float)$a['score'], 2, ',', ' '), '0'), ',')) ?>
              / <?= h(rtrim(rtrim(number_format((float)$a['max_score'], 2, ',', ' '), '0'), ',')) ?>
              <span class="text-body-secondary">(<?= $pct ?>%)</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($a['status'] === 'in_progress'): ?>
              <span class="badge text-bg-info"><i class="bi bi-pencil me-1" aria-hidden="true"></i>w trakcie</span>
            <?php elseif ((int)$a['needs_review'] === 1): ?>
              <span class="badge text-bg-warning"><i class="bi bi-hourglass-split me-1" aria-hidden="true"></i>do oceny</span>
            <?php elseif ($passed): ?>
              <span class="badge text-bg-success"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>zaliczone</span>
            <?php else: ?>
              <span class="badge text-bg-secondary"><i class="bi bi-x-lg me-1" aria-hidden="true"></i>niezaliczone</span>
            <?php endif; ?>
            <?php if ((int)$a['is_late'] === 1): ?>
              <span class="badge text-bg-light text-dark border">po czasie</span>
            <?php endif; ?>
            <?php if ((string)$a['engine_status'] === 'pending'): ?>
              <span class="badge text-bg-danger">bez oceny automatu</span>
            <?php endif; ?>
          </td>
          <td class="small"><?= $a['submitted_at'] ? h(date('d.m.Y H:i', strtotime((string)$a['submitted_at']))) : '—' ?></td>
          <td class="text-end">
            <a class="btn btn-sm btn-outline-primary" href="exam_review.php?attempt=<?= (int)$a['id'] ?>">
              Otwórz<span class="visually-hidden"> podejście kursanta <?= h((string)$a['client_name']) ?></span>
            </a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <h2 class="h5 fw-semibold mb-2">Jak działa ten zestaw</h2>
  <?php if (!$stats): ?>
    <p class="text-body-secondary small">Statystyki są liczone przez silnik Equi Exams — teraz niedostępne.
      <?= h((string)$stats_error) ?></p>
  <?php else:
    $sum = (array)($stats['summary'] ?? []); ?>
    <div class="row g-3 mb-3">
      <?php foreach ([
          ['Ocenionych podejść', (string)(int)($sum['attempts'] ?? 0)],
          ['Średni wynik',       ((float)($sum['mean'] ?? 0)) . '%'],
          ['Mediana',            ((float)($sum['median'] ?? 0)) . '%'],
          ['Zdawalność',         ((float)($sum['passRate'] ?? 0)) . '%'],
      ] as $kpi): ?>
      <div class="col-6 col-lg-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body py-3">
          <div class="text-body-secondary small"><?= h($kpi[0]) ?></div>
          <div class="fs-4 fw-bold"><?= h($kpi[1]) ?></div>
        </div></div>
      </div>
      <?php endforeach; ?>
    </div>

    <?php if (!empty($stats['questions'])): ?>
    <div class="table-responsive">
      <table class="table table-sm align-middle">
        <caption class="visually-hidden">Łatwość i moc różnicująca poszczególnych pytań</caption>
        <thead><tr>
          <th scope="col">Pytanie</th><th scope="col">Typ</th>
          <th scope="col" class="text-end">Odpowiedzi</th>
          <th scope="col" class="text-end">Łatwość</th>
          <th scope="col" class="text-end">Moc różnicująca</th>
          <th scope="col">Uwaga</th>
        </tr></thead>
        <tbody>
        <?php
          $flags = [
            'ok'           => ['—', 'secondary'],
            'insufficient' => ['za mało danych', 'light'],
            'too_easy'     => ['zbyt łatwe', 'info'],
            'too_hard'     => ['zbyt trudne', 'warning'],
            'weak'         => ['słabo różnicuje', 'warning'],
            'inverted'     => ['sprawdź klucz odpowiedzi', 'danger'],
          ];
          foreach ((array)$stats['questions'] as $q):
            $fl = $flags[(string)($q['flag'] ?? 'ok')] ?? $flags['ok']; ?>
          <tr>
            <th scope="row" class="fw-normal"><?= h((string)($q['prompt'] ?? '')) ?></th>
            <td class="small"><?= h(ti_exam_type_label((string)($q['type'] ?? ''))) ?></td>
            <td class="text-end"><?= (int)($q['answered'] ?? 0) ?></td>
            <td class="text-end"><?= number_format((float)($q['ease'] ?? 0), 2, ',', ' ') ?></td>
            <td class="text-end"><?= number_format((float)($q['discrimination'] ?? 0), 2, ',', ' ') ?></td>
            <td>
              <?php if ($fl[0] === '—'): ?><span class="text-body-secondary">—</span>
              <?php else: ?><span class="badge text-bg-<?= h($fl[1]) ?>"><?= h($fl[0]) ?></span><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="form-text">
      Łatwość to średni ułamek zdobytych punktów (1,00 = wszyscy mają komplet). Moc różnicująca porównuje
      najlepszą i najsłabszą ćwiartkę podejść — wartość ujemna zwykle znaczy błąd w kluczu odpowiedzi.
    </p>
    <?php endif; ?>
  <?php endif; ?>

<?php else:
  /* ══════════════ WGLĄD W POJEDYNCZE PODEJŚCIE ══════════════ */
  $client    = db_one("SELECT * FROM k30_clients WHERE id=?", [(int)$attempt['client_id']]) ?: [];
  $questions = ti_exam_attempt_questions($attempt);
  $answers   = ti_exam_answers((int)$attempt['id']);
  $orderMap  = ti_exam_attempt_option_order($attempt);
  $pct       = ti_exam_pct($attempt);
?>
  <h1 class="h4 fw-bold mb-1">
    <i class="bi bi-person-badge text-primary me-2" aria-hidden="true"></i><?= h((string)($client['name'] ?? 'Kursant')) ?>
  </h1>
  <p class="text-body-secondary">
    <?= h((string)$exam['title']) ?> · podejście nr <?= (int)$attempt['attempt_no'] ?>
    <?php if ($attempt['submitted_at']): ?> · oddane <?= h(date('d.m.Y H:i', strtotime((string)$attempt['submitted_at']))) ?><?php endif; ?>
  </p>

  <div class="d-flex flex-wrap gap-2 align-items-center mb-3">
    <span class="badge fs-6 text-bg-<?= (int)$attempt['needs_review'] === 1 ? 'warning' : (ti_exam_passed($exam, $attempt) ? 'success' : 'secondary') ?>">
      <?= h(rtrim(rtrim(number_format((float)$attempt['score'], 2, ',', ' '), '0'), ',')) ?>
      / <?= h(rtrim(rtrim(number_format((float)$attempt['max_score'], 2, ',', ' '), '0'), ',')) ?>
      (<?= $pct ?>%)
    </span>
    <?php if ((int)$attempt['is_late'] === 1): ?>
      <span class="badge text-bg-light text-dark border">oddane po czasie</span>
    <?php endif; ?>
    <form method="post" class="ms-auto">
      <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
      <input type="hidden" name="_op" value="regrade">
      <input type="hidden" name="attempt_id" value="<?= (int)$attempt['id'] ?>">
      <button class="btn btn-sm btn-outline-secondary" type="submit">
        <i class="bi bi-arrow-clockwise me-1" aria-hidden="true"></i>Oceń ponownie automatem
      </button>
    </form>
  </div>

  <?php if ((string)$attempt['engine_status'] === 'pending'): ?>
  <div class="alert alert-danger" role="alert">
    <strong>Ta praca nie została oceniona automatycznie</strong> — silnik był niedostępny w chwili oddania.
    Uruchom ponowną ocenę powyżej albo wpisz punkty ręcznie.
    <div class="small mt-1"><?= h((string)$attempt['engine_error']) ?></div>
  </div>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
    <input type="hidden" name="_op" value="grade_manual">
    <input type="hidden" name="attempt_id" value="<?= (int)$attempt['id'] ?>">

    <?php foreach ($questions as $n => $q):
      $qid     = (int)$q['id'];
      $ans     = $answers[$qid] ?? null;
      $payload = ti_exam_answer_payload($ans);
      $result  = $ans && $ans['result'] ? json_decode((string)$ans['result'], true) : null;
      $review  = $ans ? (int)$ans['needs_review'] === 1 : true;
      require __DIR__ . '/_exam_review_item.php';
    endforeach; ?>

    <div class="d-flex gap-2 flex-wrap sticky-bottom bg-body py-3 border-top">
      <button class="btn btn-primary" type="submit">
        <i class="bi bi-save me-1" aria-hidden="true"></i>Zapisz ocenę
      </button>
      <a class="btn btn-outline-secondary" href="exam_review.php?exam_id=<?= (int)$exam['id'] ?>">Wróć do listy podejść</a>
    </div>
  </form>
<?php endif; ?>

  <?= equi_exams_footer_html() ?>
</main>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
