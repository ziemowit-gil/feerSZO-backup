<?php
/**
 * admin/tasks_cleanup.php
 * Uprawnienie administratorskie: Wyczyść moduł Zadania.
 *
 * Usuwa (twardo): zadania, przypisania, komentarze, podzadania,
 * pliki, historię, powiadomienia, wiadomości wewnętrzne task.
 * Zachowuje: obszary robocze, listy, tagi, ustawienia, preferencje.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';

require_role('admin');   // tylko admin systemu

$csrf = csrf_token();

// ── Zebranie statystyk ────────────────────────────────────────────────────
function _tc_count(string $sql, array $p = []): int {
    try { return (int)(db_one($sql, $p)['n'] ?? 0); }
    catch (\Throwable $e) { return 0; }
}

$stats = [
    'tasks'         => _tc_count("SELECT COUNT(*) AS n FROM tasks WHERE deleted_at IS NULL"),
    'tasks_deleted' => _tc_count("SELECT COUNT(*) AS n FROM tasks WHERE deleted_at IS NOT NULL"),
    'assignments'   => _tc_count("SELECT COUNT(*) AS n FROM task_assignments"),
    'comments'      => _tc_count("SELECT COUNT(*) AS n FROM task_comments WHERE deleted_at IS NULL"),
    'subtasks'      => _tc_count("SELECT COUNT(*) AS n FROM task_subtasks"),
    'files'         => _tc_count("SELECT COUNT(*) AS n FROM task_files"),
    'history'       => _tc_count("SELECT COUNT(*) AS n FROM task_history"),
    'problems'      => _tc_count("SELECT COUNT(*) AS n FROM task_history WHERE event_type='leader_notified'"),
    'notifications' => _tc_count("SELECT COUNT(*) AS n FROM task_notification_log"),
    'messages'      => _tc_count("SELECT COUNT(*) AS n FROM messages WHERE context_type='task'"),
];
$total = $stats['tasks'] + $stats['tasks_deleted'] + $stats['assignments']
       + $stats['comments'] + $stats['subtasks'] + $stats['files']
       + $stats['history'] + $stats['notifications'] + $stats['messages'];

// ── Opcje czyszczenia ─────────────────────────────────────────────────────
$scopes = [
    'tasks'    => [
        'label'   => 'Zadania i wszystkie powiązane dane',
        'desc'    => 'Usuwa wszystkie zadania (aktywne i soft-deleted), przypisania, komentarze, podzadania, pliki i historię.',
        'tables'  => ['tasks','task_assignments','task_comments','task_subtasks','task_files','task_history','task_list_time','task_task_tags'],
        'count'   => $stats['tasks'] + $stats['tasks_deleted'],
        'icon'    => 'bi-table',
        'color'   => '#dc2626',
    ],
    'problems' => [
        'label'   => 'Tylko zgłoszone problemy (historia)',
        'desc'    => 'Usuwa wyłącznie zdarzenia leader_notified i problem_resolved z historii zadań.',
        'tables'  => [],   // obsługa specjalna
        'count'   => $stats['problems'],
        'icon'    => 'bi-megaphone',
        'color'   => '#d97706',
    ],
    'assignments' => [
        'label'   => 'Tylko przypisania wolontariuszy',
        'desc'    => 'Odpina wszystkich wolontariuszy od zadań. Zadania pozostają.',
        'tables'  => ['task_assignments'],
        'count'   => $stats['assignments'],
        'icon'    => 'bi-person-dash',
        'color'   => '#7c3aed',
    ],
    'messages' => [
        'label'   => 'Wiadomości wewnętrzne zadaniowe',
        'desc'    => 'Usuwa wszystkie wiadomości z context_type=task ze skrzynek.',
        'tables'  => [],   // obsługa specjalna
        'count'   => $stats['messages'],
        'icon'    => 'bi-chat-square-x',
        'color'   => '#0891b2',
    ],
    'notifications' => [
        'label'   => 'Log powiadomień e-mail',
        'desc'    => 'Czyści log wysłanych powiadomień. Nie usuwa zadań ani przypisań.',
        'tables'  => ['task_notification_log'],
        'count'   => $stats['notifications'],
        'icon'    => 'bi-bell-slash',
        'color'   => '#64748b',
    ],
];

// ── Wykonanie czyszczenia (POST) ──────────────────────────────────────────
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $scope   = $_POST['scope']   ?? '';
    $confirm = trim($_POST['confirm_word'] ?? '');

    if ($confirm !== 'USUŃ') {
        flash_set('error', 'Wpisz słowo potwierdzające: USUŃ');
        header('Location: tasks_cleanup.php'); exit;
    }
    if (!isset($scopes[$scope])) {
        flash_set('error', 'Nieznany zakres czyszczenia.');
        header('Location: tasks_cleanup.php'); exit;
    }

    $actor    = current_user();
    $now      = date('Y-m-d H:i:s');
    $affected = 0;

    $pdo = db();
    $pdo->beginTransaction();

    try {
        if ($scope === 'tasks') {
            // Usuń wszystkie pliki fizyczne najpierw (jeśli istnieją)
            try {
                $files = db_all("SELECT stored_name FROM task_files");
                foreach ($files as $f) {
                    $path = dirname(__DIR__) . '/uploads/tasks/' . $f['stored_name'];
                    if (file_exists($path)) @unlink($path);
                }
            } catch (\Throwable $e) {}

            // Poprawna kolejność usuwania — respektuje FK:
            // children zadań → twarde usunięcie zadań (zwalnia FK list_id→task_lists)
            // → task_lists (zwalnia FK workspace_id→task_workspaces)
            foreach ([
                'task_task_tags',    // FK → tasks (CASCADE)
                'task_subtasks',     // FK → tasks (CASCADE)
                'task_comments',     // FK → tasks (CASCADE)
                'task_files',        // FK → tasks (CASCADE)
                'task_list_time',    // FK → tasks (CASCADE)
                'task_history',      // FK → tasks (CASCADE)
                'task_assignments',  // FK → tasks (CASCADE)
                'tasks',             // zwalnia FK: list_id→task_lists, workspace_id→task_workspaces
                'task_lists',        // zwalnia FK: workspace_id→task_workspaces
            ] as $tbl) {
                $stmt = $pdo->prepare("DELETE FROM {$tbl}");
                $stmt->execute();
                $affected += $stmt->rowCount();
            }

            // Wiadomości task i log powiadomień
            $stmt = $pdo->prepare("DELETE FROM messages WHERE context_type='task'");
            $stmt->execute(); $affected += $stmt->rowCount();
            $stmt = $pdo->prepare("DELETE FROM task_notification_log");
            $stmt->execute(); $affected += $stmt->rowCount();

        } elseif ($scope === 'problems') {
            $stmt = $pdo->prepare(
                "DELETE FROM task_history WHERE event_type IN ('leader_notified','problem_resolved')"
            );
            $stmt->execute();
            $affected = $stmt->rowCount();
            // Wyczyść powiązane wiadomości task (subject LIKE 'Problem%' lub 'Re: Problem%')
            $stmt2 = $pdo->prepare(
                "DELETE FROM messages WHERE context_type='task' AND (subject LIKE 'Problem:%' OR subject LIKE 'Re: Problem%')"
            );
            $stmt2->execute();
            $affected += $stmt2->rowCount();

        } elseif ($scope === 'messages') {
            $stmt = $pdo->prepare("DELETE FROM messages WHERE context_type='task'");
            $stmt->execute();
            $affected = $stmt->rowCount();

        } else {
            // Standardowe tabele
            foreach ($scopes[$scope]['tables'] as $tbl) {
                $stmt = $pdo->prepare("DELETE FROM {$tbl}");
                $stmt->execute();
                $affected += $stmt->rowCount();
            }
        }

        $pdo->commit();

        // Zapisz w logu systemowym (jeśli istnieje tabela audit)
        try {
            db_insert('audit_log', [
                'user_id'    => (int)$actor['id'],
                'action'     => 'tasks_cleanup',
                'details'    => "Scope: {$scope}, affected: {$affected}",
                'created_at' => $now,
            ]);
        } catch (\Throwable $e) { /* tabela może nie istnieć */ }

        $result = [
            'ok'       => true,
            'scope'    => $scopes[$scope]['label'],
            'affected' => $affected,
            'actor'    => $actor['name'],
            'at'       => $now,
        ];

    } catch (\Throwable $e) {
        $pdo->rollBack();
        flash_set('error', 'Błąd podczas czyszczenia: ' . $e->getMessage());
        header('Location: tasks_cleanup.php'); exit;
    }
}

$PAGE_TITLE = 'Czyszczenie modułu Zadania';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<style>
.tc-card {
  background: #fff; border: 1.5px solid #e2e8f0;
  border-radius: .65rem; padding: 1.25rem 1.4rem;
  margin-bottom: .85rem;
}
.tc-scope-item {
  display: flex; align-items: flex-start; gap: .75rem;
  padding: .85rem 1rem;
  border: 1.5px solid #e2e8f0; border-radius: .55rem;
  cursor: pointer; transition: border-color .12s, background .12s;
  margin-bottom: .45rem;
}
.tc-scope-item:hover { background: #f8fafc; }
.tc-scope-item input[type=radio]:checked ~ * { font-weight: 600; }
.tc-scope-item:has(input:checked) { border-color: #dc2626; background: #fef2f2; }
.tc-scope-icon {
  width: 36px; height: 36px; border-radius: .45rem;
  display: flex; align-items: center; justify-content: center;
  font-size: 1rem; flex-shrink: 0;
}
.tc-stat {
  display: flex; align-items: center; justify-content: space-between;
  padding: .45rem 0; border-bottom: 1px solid #f1f5f9; font-size: .85rem;
}
.tc-stat:last-child { border-bottom: none; }
.tc-stat-val {
  font-weight: 700; font-size: .9rem;
  min-width: 2.5rem; text-align: right;
}
.tc-confirm-word {
  font-family: monospace; font-size: 1rem; letter-spacing: .1em;
  font-weight: 700; color: #dc2626;
  background: #fef2f2; border: 1.5px solid #fecaca;
  border-radius: .35rem; padding: .1rem .5rem;
  user-select: all;
}
.tc-input-confirm {
  font-family: monospace; font-size: .95rem; letter-spacing: .05em;
  text-align: center;
}
/* Wynik */
.tc-result {
  border-left: 4px solid #16a34a;
  background: #f0fdf4; border-radius: .55rem;
  padding: 1.25rem 1.4rem;
}
</style>

<!-- Breadcrumb -->
<nav aria-label="Nawigacja" class="mb-3">
  <ol class="breadcrumb small">
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/admin/index.php">Admin</a></li>
    <li class="breadcrumb-item"><a href="<?= APP_URL ?>/tasks/dashboard.php">Zadania</a></li>
    <li class="breadcrumb-item active">Czyszczenie modułu</li>
  </ol>
</nav>

<?php if ($result): ?>
<!-- ── Wynik czyszczenia ─────────────────────────────────────────────────── -->
<div class="tc-result mb-4" role="status">
  <div class="d-flex align-items-center gap-3 mb-3">
    <span style="width:44px;height:44px;background:#dcfce7;border-radius:50%;
                 display:flex;align-items:center;justify-content:center;flex-shrink:0">
      <i class="bi bi-check2-circle text-success" style="font-size:1.3rem" aria-hidden="true"></i>
    </span>
    <div>
      <h1 class="h5 fw-bold mb-0 text-success">Czyszczenie zakończone pomyślnie</h1>
      <p class="text-muted small mb-0"><?= h($result['at']) ?> · <?= h($result['actor']) ?></p>
    </div>
  </div>
  <dl class="row g-2 small mb-3">
    <dt class="col-sm-4 text-muted">Zakres</dt>
    <dd class="col-sm-8 fw-semibold"><?= h($result['scope']) ?></dd>
    <dt class="col-sm-4 text-muted">Usuniętych rekordów</dt>
    <dd class="col-sm-8 fw-bold text-success" style="font-size:1.1rem"><?= number_format($result['affected']) ?></dd>
  </dl>
  <div class="d-flex gap-2">
    <a href="tasks_cleanup.php" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-arrow-clockwise me-1"></i>Uruchom ponownie
    </a>
    <a href="<?= APP_URL ?>/tasks/dashboard.php" class="btn btn-primary btn-sm">
      <i class="bi bi-table me-1"></i>Przejdź do modułu Zadania
    </a>
  </div>
</div>
<?php else: ?>

<?= flash_html() ?>

<div class="row g-3">

  <!-- Lewa: formularz ──────────────────────────────────────────────────── -->
  <div class="col-lg-7">

    <div class="tc-card">
      <div class="d-flex align-items-center gap-3 mb-3">
        <span style="width:44px;height:44px;background:#fef2f2;border-radius:50%;
                     display:flex;align-items:center;justify-content:center;flex-shrink:0"
              aria-hidden="true">
          <i class="bi bi-trash3-fill text-danger" style="font-size:1.2rem"></i>
        </span>
        <div>
          <h1 class="h5 fw-bold mb-0">Czyszczenie modułu Zadania</h1>
          <p class="text-muted small mb-0">
            Operacja nieodwracalna — wybierz zakres i potwierdź.
          </p>
        </div>
      </div>

      <form method="post" id="cleanup-form" novalidate
            onsubmit="return tcConfirmSubmit(event)">
        <input type="hidden" name="_csrf" value="<?= h($csrf) ?>">

        <!-- Wybór zakresu -->
        <fieldset>
          <legend class="fw-semibold small text-muted text-uppercase mb-2"
                  style="letter-spacing:.07em;font-size:.72rem">
            Zakres czyszczenia
          </legend>
          <?php foreach ($scopes as $key => $sc): ?>
          <label class="tc-scope-item" for="scope-<?= $key ?>">
            <input type="radio"
                   name="scope"
                   id="scope-<?= $key ?>"
                   value="<?= $key ?>"
                   class="form-check-input flex-shrink-0 mt-1"
                   required
                   onchange="tcUpdateCount(<?= (int)$sc['count'] ?>)">
            <span class="tc-scope-icon"
                  style="background:<?= h($sc['color']) ?>22;color:<?= h($sc['color']) ?>"
                  aria-hidden="true">
              <i class="bi <?= h($sc['icon']) ?>"></i>
            </span>
            <span class="flex-grow-1">
              <span class="d-block fw-semibold" style="font-size:.88rem">
                <?= h($sc['label']) ?>
                <span class="badge ms-1 fw-normal"
                      style="background:<?= h($sc['color']) ?>22;color:<?= h($sc['color']) ?>;font-size:.7rem">
                  <?= number_format($sc['count']) ?> rek.
                </span>
              </span>
              <span class="d-block text-muted" style="font-size:.77rem"><?= h($sc['desc']) ?></span>
            </span>
          </label>
          <?php endforeach; ?>
        </fieldset>

        <hr class="my-3">

        <!-- Potwierdzenie -->
        <div class="mb-3">
          <label class="form-label fw-semibold small" for="confirm-word">
            Wpisz <span class="tc-confirm-word" aria-label="słowo USUŃ">USUŃ</span>
            aby potwierdzić operację
          </label>
          <input type="text"
                 id="confirm-word"
                 name="confirm_word"
                 class="form-control tc-input-confirm"
                 autocomplete="off"
                 autocorrect="off"
                 spellcheck="false"
                 maxlength="10"
                 placeholder="wpisz USUŃ"
                 aria-required="true"
                 aria-describedby="confirm-hint">
          <p id="confirm-hint" class="text-muted small mt-1 mb-0">
            Operacja usunie <strong id="tc-count-display">—</strong> rekordów i nie może być cofnięta.
          </p>
        </div>

        <!-- Przycisk submit -->
        <button type="submit"
                class="btn btn-danger w-100 fw-bold"
                id="tc-submit-btn"
                aria-describedby="confirm-hint">
          <i class="bi bi-trash3 me-2" aria-hidden="true"></i>
          Wykonaj czyszczenie
        </button>

      </form>
    </div>

  </div>

  <!-- Prawa: statystyki ───────────────────────────────────────────────── -->
  <div class="col-lg-5">
    <div class="tc-card">
      <h2 class="h6 fw-bold mb-3">
        <i class="bi bi-bar-chart me-1 text-muted" aria-hidden="true"></i>
        Aktualny stan modułu
      </h2>

      <?php
      $stat_rows = [
        ['Zadania aktywne',      $stats['tasks'],         '#16a34a'],
        ['Zadania usunięte',     $stats['tasks_deleted'], '#64748b'],
        ['Przypisania',          $stats['assignments'],   '#7c3aed'],
        ['Komentarze',           $stats['comments'],      '#2563eb'],
        ['Podzadania',           $stats['subtasks'],      '#0891b2'],
        ['Pliki załączników',    $stats['files'],         '#d97706'],
        ['Historia zdarzeń',     $stats['history'],       '#475569'],
        ['↳ Zgłoszone problemy', $stats['problems'],      '#dc2626'],
        ['Log powiadomień',      $stats['notifications'], '#64748b'],
        ['Wiadomości wewnętrzne',$stats['messages'],      '#059669'],
      ];
      ?>
      <?php foreach ($stat_rows as [$lbl, $val, $col]): ?>
      <div class="tc-stat">
        <span style="color:#475569;<?= str_starts_with($lbl,'↳')?'padding-left:.75rem':'' ?>">
          <?= h($lbl) ?>
        </span>
        <span class="tc-stat-val" style="color:<?= $val > 0 ? $col : '#94a3b8' ?>">
          <?= number_format($val) ?>
        </span>
      </div>
      <?php endforeach; ?>

      <div class="tc-stat border-top mt-2 pt-2">
        <span class="fw-bold">Łącznie do usunięcia</span>
        <span class="tc-stat-val fw-bold" style="font-size:1rem;color:#dc2626">
          <?= number_format($total) ?>
        </span>
      </div>
    </div>

    <!-- Info -->
    <div class="card border-warning bg-warning bg-opacity-10 border-opacity-50">
      <div class="card-body py-2 px-3">
        <p class="small mb-1 fw-semibold text-warning">
          <i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>
          Co NIE zostanie usunięte:
        </p>
        <ul class="small mb-0 text-muted ps-3">
          <li>Obszary robocze i listy (kolumny)</li>
          <li>Tagi zadaniowe</li>
          <li>Preferencje powiadomień użytkowników</li>
          <li>Ustawienia modułu</li>
          <li>Uprawnienia członków obszarów</li>
        </ul>
      </div>
    </div>
  </div>

</div>
<?php endif; ?>

<script>
function tcUpdateCount(n) {
    const el = document.getElementById('tc-count-display');
    if (el) el.textContent = n.toLocaleString('pl-PL');
}

function tcConfirmSubmit(e) {
    const word  = document.getElementById('confirm-word')?.value?.trim();
    const scope = document.querySelector('input[name="scope"]:checked');

    if (!scope) {
        e.preventDefault();
        alert('Wybierz zakres czyszczenia.');
        return false;
    }
    if (word !== 'USUŃ') {
        e.preventDefault();
        const inp = document.getElementById('confirm-word');
        inp.classList.add('is-invalid');
        inp.focus();
        inp.setCustomValidity('Wpisz dokładnie: USUŃ');
        inp.reportValidity();
        return false;
    }

    const label = scope.closest('label')?.querySelector('.fw-semibold')?.textContent?.trim() || scope.value;
    return confirm(
        'Ostatnie potwierdzenie:\n\n'
        + 'Zakres: ' + label + '\n\n'
        + 'Ta operacja jest NIEODWRACALNA.\nKontynuować?'
    );
}

document.getElementById('confirm-word')?.addEventListener('input', function() {
    this.classList.remove('is-invalid');
    this.setCustomValidity('');
});
</script>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
