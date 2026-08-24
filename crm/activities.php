<?php
/**
 * crm/activities.php — jedna kolejka: planowane działania I zadania CRM.
 *
 * Działania (crm_activities: telefon, spotkanie) i zadania CRM (crm_tasks:
 * oddzwonić, wysłać ofertę) to z punktu widzenia pracującego to samo — rzeczy
 * do zrobienia z terminem. Trzymanie ich na dwóch ekranach znaczyło, że
 * „co mam dziś" trzeba było sprawdzać w dwóch miejscach, a zaległość
 * z jednego nie była widoczna w drugim. Dlatego jedna lista, z oznaczeniem
 * rodzaju przy każdej pozycji.
 *
 * Tabela `crm_activities` istniała od dawna i była zapisywana z kartoteki
 * kontaktu, ale nie miała GDZIE się pokazać: ani listy, ani kafelka na
 * dashboardzie, ani przypomnienia. „Zadzwonić za tydzień” zapisywało się
 * i przepadało. Ten ekran jest brakującym widokiem: co zaległe, co dziś,
 * co w tym tygodniu — z jednym kliknięciem „zrobione”.
 *
 * Domyślny zakres to WŁASNE działania. Cudze można obejrzeć świadomie
 * (przełącznik „wszystkich”), bo lista zespołu to inne zadanie niż własna
 * kolejka na dziś.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/crm.php';
require_once dirname(__DIR__) . '/includes/crm_tasks.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
crm_migrate();
crm_require('contacts', 'read');

$can_write = is_admin() || can_write('crm');
$uid       = (int)(current_user()['id'] ?? 0);

/** Rodzaje działań — etykieta i ikona. Wartości jak w crm/api/activities.php. */
const CRM_ACT_TYPES = [
    'call'    => ['label' => 'Telefon',   'icon' => 'bi-telephone'],
    'email'   => ['label' => 'E-mail',    'icon' => 'bi-envelope'],
    'meeting' => ['label' => 'Spotkanie', 'icon' => 'bi-people'],
    'task'    => ['label' => 'Zadanie',   'icon' => 'bi-check2-square'],
    'demo'    => ['label' => 'Prezentacja','icon' => 'bi-easel'],
    'lunch'   => ['label' => 'Lunch',     'icon' => 'bi-cup-hot'],
    'other'   => ['label' => 'Inne',      'icon' => 'bi-dot'],
];

/** Widoki listy — nazwa, opis i warunek SQL na crm_activities (alias `a`). */
function crm_act_views(): array
{
    return [
        'zalegle' => [
            'label' => 'Zaległe', 'icon' => 'bi-exclamation-triangle',
            'where' => "a.status='planned' AND a.scheduled_at IS NOT NULL AND date(a.scheduled_at) < date('now','localtime')",
            'empty' => 'Nic nie zalega. Tak ma być.',
        ],
        'dzis' => [
            'label' => 'Na dziś', 'icon' => 'bi-calendar-check',
            'where' => "a.status='planned' AND date(a.scheduled_at) = date('now','localtime')",
            'empty' => 'Na dziś nic nie zaplanowano.',
        ],
        'tydzien' => [
            'label' => 'Najbliższe 7 dni', 'icon' => 'bi-calendar-week',
            'where' => "a.status='planned' AND date(a.scheduled_at) > date('now','localtime')
                        AND date(a.scheduled_at) <= date('now','localtime','+7 days')",
            'empty' => 'W najbliższym tygodniu nic nie zaplanowano.',
        ],
        'bez_terminu' => [
            'label' => 'Bez terminu', 'icon' => 'bi-question-circle',
            'where' => "a.status='planned' AND a.scheduled_at IS NULL",
            'empty' => 'Wszystkie planowane działania mają termin.',
        ],
        'zrobione' => [
            'label' => 'Zrobione', 'icon' => 'bi-check2-all',
            'where' => "a.status='done' AND a.completed_at >= datetime('now','-30 days')",
            'empty' => 'W ostatnich 30 dniach nic nie odhaczono.',
        ],
    ];
}

/**
 * Ten sam podział widoków, ale dla crm_tasks (alias `t`).
 *
 * Zadania mają `due_date` (data) tam, gdzie działania mają `scheduled_at`
 * (data z godziną) — stąd osobna mapa zamiast jednego zapytania z UNION:
 * scalanie w SQL wymagałoby rzutowań, które i tak trzeba by rozplątywać
 * przy wyświetlaniu.
 */
function crm_task_views_sql(): array
{
    return [
        'zalegle'     => "t.status='open' AND t.due_date IS NOT NULL AND date(t.due_date) < date('now','localtime')",
        'dzis'        => "t.status='open' AND date(t.due_date) = date('now','localtime')",
        'tydzien'     => "t.status='open' AND date(t.due_date) > date('now','localtime')
                          AND date(t.due_date) <= date('now','localtime','+7 days')",
        'bez_terminu' => "t.status='open' AND t.due_date IS NULL",
        'zrobione'    => "t.status='done' AND t.done_at >= datetime('now','-30 days')",
    ];
}

$views = crm_act_views();
$view  = isset($_GET['view'], $views[$_GET['view']]) ? (string)$_GET['view'] : 'dzis';
$scope = ($_GET['scope'] ?? 'moje') === 'wszystkie' ? 'wszystkie' : 'moje';

// ── POST: odhacz / przywróć ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$can_write) { flash_set('error', 'Brak uprawnień.'); }
    else {
        $op  = (string)($_POST['_op'] ?? '');
        $aid = (int)($_POST['id'] ?? 0);

        // Zadania CRM mają własne operacje — ta sama lista, inna tabela
        if (str_starts_with($op, 'task_')) {
            if ($op === 'task_done')    crm_task_set_done($aid, true);
            if ($op === 'task_reopen')  crm_task_set_done($aid, false);
            if ($op === 'task_snooze')  crm_task_update($aid, ['due_date' => date('Y-m-d', strtotime('tomorrow'))]);
            flash_set('success', match ($op) {
                'task_done'   => 'Odhaczone.',
                'task_reopen' => 'Wróciło do otwartych.',
                default       => 'Przesunięte na jutro.',
            });
            header('Location: ?view=' . urlencode($view) . '&scope=' . urlencode($scope)); exit;
        }

        $act = $aid ? db_one("SELECT * FROM crm_activities WHERE id=?", [$aid]) : null;
        if (!$act) {
            flash_set('error', 'Nie znaleziono działania.');
        } elseif ($op === 'done') {
            db()->prepare("UPDATE crm_activities SET status='done', outcome=?, completed_at=?, updated_at=? WHERE id=?")
                ->execute([trim((string)($_POST['outcome'] ?? '')) ?: null, date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $aid]);
            flash_set('success', 'Odhaczone.');
        } elseif ($op === 'reopen') {
            db()->prepare("UPDATE crm_activities SET status='planned', completed_at=NULL, updated_at=? WHERE id=?")
                ->execute([date('Y-m-d H:i:s'), $aid]);
            flash_set('success', 'Wróciło do planowanych.');
        } elseif ($op === 'snooze') {
            // Przesunięcie na jutro — najczęstsza reakcja na zaległość, więc jedno kliknięcie.
            db()->prepare("UPDATE crm_activities SET scheduled_at=?, updated_at=? WHERE id=?")
                ->execute([date('Y-m-d H:i:s', strtotime('tomorrow 09:00')), date('Y-m-d H:i:s'), $aid]);
            flash_set('success', 'Przesunięte na jutro rano.');
        }
    }
    header('Location: ?view=' . urlencode($view) . '&scope=' . urlencode($scope)); exit;
}

// ── Dane ───────────────────────────────────────────────────────────────────
$scope_sql    = $scope === 'moje' ? " AND a.assigned_to = ?" : '';
$scope_params = $scope === 'moje' ? [$uid] : [];

$task_sql     = crm_task_views_sql();
$t_scope_sql  = $scope === 'moje' ? " AND t.owner_id = ?" : '';

$counts = [];
foreach ($views as $vk => $vv) {
    $r = db_one(
        "SELECT COUNT(*) AS n FROM crm_activities a
           JOIN crm_contacts c ON c.id = a.contact_id AND c.crm_active = 1
          WHERE {$vv['where']}{$scope_sql}",
        $scope_params
    );
    $n = (int)($r['n'] ?? 0);

    // Zadania CRM liczą się do tych samych pigułek — inaczej „Na dziś: 0"
    // wisiałoby nad listą, na której coś jest
    try {
        $rt = db_one("SELECT COUNT(*) AS n FROM crm_tasks t WHERE {$task_sql[$vk]}{$t_scope_sql}", $scope_params);
        $n += (int)($rt['n'] ?? 0);
    } catch (\Throwable $e) {}

    $counts[$vk] = $n;
}

// Oba źródła sprowadzamy do jednego kształtu wiersza: kind, id, title, when,
// done_at, kontakt, osoba. Sortowanie i wyświetlanie nie muszą wtedy wiedzieć,
// skąd pozycja pochodzi — poza plakietką rodzaju.
$rows = [];

foreach (db_all(
    "SELECT a.*, c.imie_nazwisko, c.type AS c_type, c.email AS c_email,
            CASE WHEN u.first_name<>'' AND u.last_name<>'' THEN u.first_name||' '||u.last_name ELSE u.name END AS assignee
       FROM crm_activities a
       JOIN crm_contacts c ON c.id = a.contact_id AND c.crm_active = 1
       LEFT JOIN users u ON u.id = a.assigned_to
      WHERE {$views[$view]['where']}{$scope_sql}
      ORDER BY a.scheduled_at IS NULL, a.scheduled_at, a.id DESC
      LIMIT 300",
    $scope_params
) as $a) {
    $rows[] = [
        'kind'       => 'act',
        'id'         => (int)$a['id'],
        'title'      => (string)$a['title'],
        'type'       => (string)$a['type'],
        'when'       => $a['scheduled_at'] ?: null,
        'done'       => $a['status'] === 'done',
        'done_at'    => $a['completed_at'] ?? null,
        'outcome'    => $a['outcome'] ?? null,
        'contact_id' => (int)$a['contact_id'],
        'contact'    => (string)$a['imie_nazwisko'],
        'assignee'   => (string)($a['assignee'] ?? ''),
        'case_id'    => 0,
        'case_title' => '',
        'priority'   => '',
    ];
}

try {
    foreach (db_all(
        "SELECT t.*, ct.imie_nazwisko AS contact_name, cs.title AS case_title,
                CASE WHEN u.first_name<>'' AND u.last_name<>'' THEN u.first_name||' '||u.last_name ELSE u.name END AS assignee
           FROM crm_tasks t
      LEFT JOIN crm_contacts ct ON ct.id = t.contact_id AND ct.crm_active = 1
      LEFT JOIN crm_cases    cs ON cs.id = t.case_id
      LEFT JOIN users        u  ON u.id  = t.owner_id
          WHERE {$task_sql[$view]}{$t_scope_sql}
       ORDER BY t.due_date IS NULL, t.due_date, t.id DESC
          LIMIT 300", $scope_params
    ) as $t) {
        $rows[] = [
            'kind'       => 'task',
            'id'         => (int)$t['id'],
            'title'      => (string)$t['title'],
            'type'       => 'task',
            'when'       => $t['due_date'] ?: null,
            'done'       => ($t['status'] ?? '') === 'done',
            'done_at'    => $t['done_at'] ?? null,
            'outcome'    => null,
            'contact_id' => (int)($t['contact_id'] ?? 0),
            'contact'    => (string)($t['contact_name'] ?? ''),
            'assignee'   => (string)($t['assignee'] ?? ''),
            'case_id'    => (int)($t['case_id'] ?? 0),
            'case_title' => (string)($t['case_title'] ?? ''),
            'priority'   => (string)($t['priority'] ?? ''),
        ];
    }
} catch (\Throwable $e) {}

// Bez terminu na koniec; w „Zrobione" najświeższe na górze
usort($rows, static function (array $x, array $y) use ($view): int {
    $kx = $view === 'zrobione' ? ($x['done_at'] ?: '') : ($x['when'] ?: '');
    $ky = $view === 'zrobione' ? ($y['done_at'] ?: '') : ($y['when'] ?: '');
    if ($kx === '' || $ky === '') return ($kx === '' ? 1 : 0) <=> ($ky === '' ? 1 : 0);
    return $view === 'zrobione' ? strcmp($ky, $kx) : strcmp($kx, $ky);
});

$PAGE_TITLE = 'Moja kolejka';
$qs = fn(array $o = []) => '?' . http_build_query(array_merge(['view' => $view, 'scope' => $scope], $o));

include __DIR__ . '/includes/header_crm.php';
?>
<style>
.act-row      { display:grid; grid-template-columns:2rem 1fr auto; gap:.6rem; align-items:start;
                padding:.6rem .9rem; border-bottom:1px solid #F1F2F4 }
.act-row:last-child { border-bottom:none }
.act-ico      { width:2rem; height:2rem; border-radius:8px; display:inline-flex; align-items:center;
                justify-content:center; background:#F3F4F6; color:#374151; font-size:.9rem }
.act-title    { font-weight:600; font-size:.9rem; color:#111827 }
.act-meta     { font-size:.76rem; color:#6B7280 }
.act-when     { font-size:.78rem; white-space:nowrap }
.act-when.late{ color:#B42318; font-weight:600 }
.act-acts     { display:flex; gap:.3rem; align-items:center }
.act-ico--task{ background:var(--crm-primary-bg); color:var(--crm-primary) }
.act-kind     { display:inline-block; font-size:.7rem; font-weight:600; text-transform:uppercase;
                letter-spacing:.03em; color:#6B7280 }
.act-kind--task { color:var(--crm-primary) }
.act-pill     { display:inline-flex; align-items:center; gap:.3rem; padding:.2rem .5rem; border-radius:2rem;
                font-size:.78rem; font-weight:600; text-decoration:none; border:2px solid transparent }
</style>

<div class="container-fluid px-0" style="max-width:1000px">

  <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
    <a href="<?= APP_URL ?>/crm/dashboard.php" class="btn btn-sm btn-crm-ghost" aria-label="Wróć do dashboardu"><i class="bi bi-arrow-left"></i></a>
    <h1 class="h5 mb-0 me-auto"><i class="bi bi-list-check me-2"></i>Moja kolejka</h1>
    <div class="btn-group btn-group-sm" role="group" aria-label="Zakres">
      <a href="<?= h($qs(['scope' => 'moje'])) ?>" class="btn btn-sm <?= $scope === 'moje' ? 'btn-crm-primary' : 'btn-crm-outline' ?>">Moje</a>
      <a href="<?= h($qs(['scope' => 'wszystkie'])) ?>" class="btn btn-sm <?= $scope === 'wszystkie' ? 'btn-crm-primary' : 'btn-crm-outline' ?>">Wszystkich</a>
    </div>
  </div>

  <p class="text-muted small mb-3" style="max-width:78ch">
    Wszystko z terminem w jednym miejscu: <strong>działania</strong> planowane w kartotece
    (telefon, spotkanie) i <strong>zadania CRM</strong> zlecone przy kartotece albo sprawie.
    Rozdzielone na dwa ekrany znaczyły, że „co mam dziś" trzeba sprawdzać dwa razy.
  </p>

  <div class="d-flex flex-wrap gap-1 mb-3">
    <?php foreach ($views as $vk => $vv): $n = $counts[$vk]; $on = $vk === $view;
          $late = $vk === 'zalegle' && $n > 0; ?>
    <a href="<?= h($qs(['view' => $vk])) ?>" class="act-pill"
       style="background:<?= $on ? 'var(--crm-primary-bg)' : ($late ? '#FEF2F2' : '#F3F4F6') ?>;
              color:<?= $on ? 'var(--crm-primary)' : ($late ? '#B42318' : '#374151') ?>;
              border-color:<?= $on ? 'var(--crm-primary)' : 'transparent' ?>"
       <?= $on ? 'aria-current="page"' : '' ?>>
      <i class="bi <?= h($vv['icon']) ?>" aria-hidden="true"></i><?= h($vv['label']) ?>
      <span class="badge <?= $n ? 'bg-secondary' : 'bg-light text-muted' ?>"><?= $n ?></span>
    </a>
    <?php endforeach; ?>
  </div>

  <div class="card">
    <?php if (!$rows): ?>
    <div class="card-body text-center text-muted py-5"><?= h($views[$view]['empty']) ?></div>
    <?php else: foreach ($rows as $r):
      $is_task = $r['kind'] === 'task';
      $t    = CRM_ACT_TYPES[$r['type']] ?? CRM_ACT_TYPES['other'];
      $late = !$r['done'] && $r['when'] && strtotime((string)$r['when']) < strtotime('today');
      $prio = $is_task ? (CRM_TASK_PRIORITIES[$r['priority']] ?? null) : null;
    ?>
    <div class="act-row">
      <span class="act-ico<?= $is_task ? ' act-ico--task' : '' ?>" aria-hidden="true">
        <i class="bi <?= $is_task ? 'bi-check2-square' : h($t['icon']) ?>"></i>
      </span>
      <div style="min-width:0">
        <div class="act-title">
          <?= h($r['title']) ?>
          <?php if ($is_task && ($r['priority'] ?? '') === 'high' && !$r['done']): ?>
          <span class="badge" style="background:<?= h($prio['color']) ?>;font-size:.62rem;vertical-align:middle">pilne</span>
          <?php endif; ?>
        </div>
        <div class="act-meta">
          <?php /* Rodzaj pozycji musi być widoczny: zadanie i działanie odhacza się
                   tak samo, ale wpisuje w innych miejscach modułu. */ ?>
          <span class="act-kind<?= $is_task ? ' act-kind--task' : '' ?>">
            <?= $is_task ? 'zadanie' : h($t['label']) ?>
          </span>
          <?php if ($r['contact_id']): ?>
          · <a href="<?= APP_URL ?>/crm/contact/view.php?id=<?= (int)$r['contact_id'] ?>" class="text-decoration-none">
            <?= h($r['contact'] ?: 'kartoteka') ?>
          </a>
          <?php endif; ?>
          <?php if ($r['case_id']): ?>
          · <a href="<?= APP_URL ?>/crm/cases/view.php?id=<?= (int)$r['case_id'] ?>" class="text-decoration-none">
            <i class="bi bi-briefcase" aria-hidden="true"></i> <?= h(mb_strimwidth((string)$r['case_title'], 0, 34, '…')) ?>
          </a>
          <?php endif; ?>
          <?php if ($scope === 'wszystkie' && $r['assignee']): ?> · <?= h($r['assignee']) ?><?php endif; ?>
          <?php if ($r['done'] && !empty($r['outcome'])): ?>
          <div class="mt-1" style="color:#374151">Wynik: <?= h($r['outcome']) ?></div>
          <?php endif; ?>
        </div>
      </div>
      <div class="text-end">
        <div class="act-when<?= $late ? ' late' : '' ?>">
          <?php if ($r['done']): ?>
            <?= $r['done_at'] ? h(date('d.m.Y', strtotime((string)$r['done_at']))) : '' ?>
          <?php elseif ($r['when']): ?>
            <?= h(date(strlen((string)$r['when']) > 10 ? 'd.m.Y H:i' : 'd.m.Y', strtotime((string)$r['when']))) ?>
          <?php else: ?>
            <span class="text-muted">bez terminu</span>
          <?php endif; ?>
        </div>
        <?php if ($can_write): ?>
        <?php $op = static fn(string $base): string => ($is_task ? 'task_' : '') . $base; ?>
        <div class="act-acts justify-content-end mt-1">
          <?php if (!$r['done']): ?>
          <form method="post" class="d-inline">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <input type="hidden" name="_op" value="<?= h($op('done')) ?>">
            <button class="btn btn-sm btn-crm-outline py-0 px-2" title="Oznacz jako wykonane"
                    aria-label="Odhacz: <?= h($r['title']) ?>">
              <i class="bi bi-check-lg" aria-hidden="true"></i>
            </button>
          </form>
          <?php if ($late): ?>
          <form method="post" class="d-inline">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <input type="hidden" name="_op" value="<?= h($op('snooze')) ?>">
            <button class="btn btn-sm btn-crm-outline py-0 px-2" title="Przesuń na jutro"
                    aria-label="Przesuń na jutro: <?= h($r['title']) ?>">
              <i class="bi bi-arrow-right" aria-hidden="true"></i>
            </button>
          </form>
          <?php endif; ?>
          <?php else: ?>
          <form method="post" class="d-inline">
            <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
            <input type="hidden" name="_op" value="<?= h($op('reopen')) ?>">
            <button class="btn btn-sm btn-crm-outline py-0 px-2" title="Przywróć do planowanych"
                    aria-label="Przywróć: <?= h($r['title']) ?>">
              <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>
            </button>
          </form>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; endif; ?>
  </div>

</div>

<?php include __DIR__ . '/includes/footer_crm.php'; ?>
