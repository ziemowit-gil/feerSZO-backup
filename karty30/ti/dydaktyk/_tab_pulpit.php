<?php
/**
 * _tab_pulpit.php — Pulpit prowadzącego (tab=pulpit).
 *
 * Przebudowany na układ rejestrowy: wszystko w tabelach o szerokości kolumny
 * treści. Od 2026-09-29 dwie kolumny (USOS): lewa — Do zrobienia, zaległe,
 * Dziś, Najbliższe; prawa — Komunikaty, Frekwencja, Trend. Kafle z liczbami
 * zastąpiła jedna linia podsumowania. Kierownik dostaje nad tym stan
 * instytucji (_tab_pulpit_kierownik.php). Poprzednia wersja (kafelki MD3, własna szerokość 920 px, wykresy SVG
 * o stałych wymiarach) rozjeżdżała się w panelu i nie mieściła na stronie —
 * trend miesięczny jest teraz tabelą, bo tabela skaluje się zawsze.
 *
 * Wymaga z index.php: $dash_today, $dash_upcoming, $dash_pending_cancel,
 * $dyd_wizard_sessions, $dyd_notices, $dyd_notices_unread, $dyd_msg_unread_total,
 * $course_ids, $my_avail, $courses.
 */

$_pulpit_status_label = static fn(string $s): string => match ($s) {
    'held'              => 'odbyta',
    'individual_change' => 'odbyta (ind.)',
    'remote_material'   => 'praca własna',
    'planned'           => 'zaplanowana',
    'cancelled'         => 'odwołana',
    'draft'             => 'szkic',
    default             => $s,
};
$_pulpit_status_badge = static fn(string $s): string => match ($s) {
    'held', 'individual_change' => 'success',
    'remote_material'           => 'primary',
    'cancelled'                 => 'danger',
    'draft'                     => 'light',
    default                     => 'secondary',
};
$_is_done = static fn(string $s): bool => in_array($s, ['held', 'individual_change', 'remote_material'], true);
$_dow_pl  = ['Sun'=>'Nd','Mon'=>'Pn','Tue'=>'Wt','Wed'=>'Śr','Thu'=>'Cz','Fri'=>'Pt','Sat'=>'Sb'];
$_day_pl  = ['Mon'=>'Poniedziałek','Tue'=>'Wtorek','Wed'=>'Środa','Thu'=>'Czwartek','Fri'=>'Piątek','Sat'=>'Sobota','Sun'=>'Niedziela'];

// ── Do zrobienia: liczniki z danych już wczytanych + dwa tanie zapytania ─────
$_av_drafts = 0;
foreach (($my_avail ?? []) as $_w) {
    if (($_w['status'] ?? 'approved') === 'draft') $_av_drafts++;
}
$_prot_open = 0;
if (!empty($course_ids)) {
    try {
        $_ph = implode(',', array_fill(0, count($course_ids), '?'));
        $_prot_open = (int)(db_one(
            "SELECT COUNT(*) AS n FROM k30_ti_protocols WHERE status='open' AND course_id IN ($_ph)",
            $course_ids
        )['n'] ?? 0);
    } catch (\Throwable $e) { $_prot_open = 0; }
}
$_todo = [];
if (!empty($dyd_wizard_sessions)) {
    $_n = count($dyd_wizard_sessions);
    $_todo[] = ['warning', 'magic', $_n . ' ' . ($_n === 1 ? 'dzisiejsza lekcja' : 'dzisiejszych lekcji') . ' do uzupełnienia (obecność i temat)', null, 'Uruchom kreator'];
}
// Zaległe (sprzed dziś) — zbiorczy przycisk zamiast każenia wchodzić w każdą
// z osobna; "wszyscy obecni" jest domyślnym, najczęstszym przypadkiem (patrz
// bulk_complete_overdue / ti_session_bulk_mark_present()). Temat trzeba i tak
// dopisać osobno — to zbiorcze działanie dotyczy tylko frekwencji/statusu.
if (!empty($dash_overdue)) {
    $_n = count($dash_overdue);
    $_todo[] = ['danger', 'clock-history',
        $_n . ' ' . ($_n === 1 ? 'zaległa lekcja (sprzed dziś) do uzupełnienia' : 'zaległych lekcji (sprzed dziś) do uzupełnienia'),
        null, 'Wszyscy obecni', "dydBulkOverdue({$_n})"];
}
if (!empty($dash_pending_cancel)) {
    $_todo[] = ['warning', 'person-dash', $dash_pending_cancel . ' ' . ((int)$dash_pending_cancel === 1 ? 'prośba' : 'prośby') . ' o odwołanie udziału do rozpatrzenia', 'index.php?tab=nieobecnosci', 'Nieobecności'];
}
if (!empty($_prot_open)) {
    $_todo[] = ['secondary', 'card-checklist', $_prot_open . ' ' . ($_prot_open === 1 ? 'protokół' : 'protokoły') . ' bez zatwierdzenia', $cur_course ? 'index.php?course=' . (int)$cur_course . '&tab=protokol' : null, 'Protokoły'];
}
// Protokoły miesięczne (patrz includes/ti_protocols.php) — osobny tor od
// protokołów per-okres wyżej. Zaległe (miesiąc już się skończył) są pilne;
// między 1. a 5. dniem miesiąca to niemal zawsze protokół za miesiąc, który
// właśnie minął — stąd czerwony akcent zamiast zwykłego "secondary".
if (!empty($_my_pending_protocols)) {
    $_prot_overdue = array_filter($_my_pending_protocols, fn($p) => !empty($p['is_overdue']));
    if ($_prot_overdue) {
        $_n = count($_prot_overdue);
        $_todo[] = ['danger', 'journal-check', $_n . ' ' . ($_n === 1 ? 'protokół miesięczny zaległy' : 'protokoły miesięczne zaległe'), 'protokoly_moje.php', 'Zamknij protokoły'];
    }
}
if (!empty($_av_drafts)) {
    $_todo[] = ['secondary', 'clock-history', $_av_drafts . ' ' . ($_av_drafts === 1 ? 'okno dostępności' : 'okien dostępności') . ' w stanie szkicu', 'index.php?tab=dostepnosc', 'Dostępność'];
}
if (!empty($dyd_notices_unread)) {
    $_todo[] = ['info', 'megaphone', $dyd_notices_unread . ' ' . ((int)$dyd_notices_unread === 1 ? 'nieprzeczytany komunikat' : 'nieprzeczytanych komunikatów'), 'index.php?tab=komunikaty', 'Komunikaty'];
}
if (!empty($dyd_msg_unread_total)) {
    $_todo[] = ['info', 'envelope', $dyd_msg_unread_total . ' ' . ((int)$dyd_msg_unread_total === 1 ? 'nieprzeczytana wiadomość' : 'nieprzeczytanych wiadomości'), 'index.php?tab=wiadomosci', 'Wiadomości'];
}

// Obecności do zaznaczenia — lista kursantów per dzisiejsza lekcja (do okien modalnych)
$_att_by_session = [];
foreach ($dash_today as $_s) {
    if ($_is_done((string)$_s['status']) || (string)$_s['status'] === 'cancelled') continue;
    $_rows = db_all(
        "SELECT a.client_id AS id, COALESCE(cl.name,'') AS name, COALESCE(a.attended,0) AS attended
           FROM k30_ti_attendance a
           LEFT JOIN k30_clients cl ON cl.id = a.client_id
          WHERE a.session_id = ? AND COALESCE(a.cancelled,0) = 0 AND COALESCE(a.cancel_pending,0) = 0
          ORDER BY cl.name COLLATE NOCASE",
        [(int)$_s['id']]
    );
    if ($_rows) $_att_by_session[(int)$_s['id']] = $_rows;
}

// ── Frekwencja: bieżący miesiąc (liczone tu, żeby pasek statystyk u góry mógł
//    pokazać zbiorczy % zanim wyrenderujemy pełną tabelę per grupa niżej) ────
$_att_rows = [];
if (!empty($course_ids)) {
    $_ph2 = implode(',', array_fill(0, count($course_ids), '?'));
    $_att_rows = db_all(
        "SELECT c.id AS course_id, c.name AS course_name,
                COUNT(DISTINCT s.id) AS lessons,
                SUM(CASE WHEN COALESCE(a.cancelled,0)=0 AND COALESCE(a.no_show,0)=0 AND a.attended=1 THEN 1 ELSE 0 END) AS present,
                SUM(CASE WHEN COALESCE(a.cancelled,0)=0 AND COALESCE(a.no_show,0)=0 AND a.attended=0 THEN 1 ELSE 0 END) AS absent
           FROM k30_ti_sessions s
           JOIN k30_ti_courses c ON c.id = s.course_id
           LEFT JOIN k30_ti_attendance a ON a.session_id = s.id
          WHERE s.course_id IN ($_ph2)
            AND s.status IN ('held','individual_change')
            AND strftime('%Y-%m', s.lesson_date) = strftime('%Y-%m','now','localtime')
          GROUP BY c.id
          ORDER BY c.name COLLATE NOCASE",
        $course_ids
    );
}
?>

<style>
/* Mini paski frekwencji — szerokość zawsze w % rodzica (komórki tabeli), bez
   stałych pikselowych wymiarów; to jest DOKŁADNIE to, czego unikamy po
   poprzedniej, rozjeżdżającej się wersji (patrz komentarz na górze pliku). */
.dyd-bar { background: var(--bs-secondary-bg, #e9ecef); border-radius: 99px; height: 6px; overflow: hidden; min-width: 3rem; }
.dyd-bar-fill { height: 100%; border-radius: 99px; }
/* Dwie kolumny od lg; karty w kolumnie bez podwójnego odstępu na dole */
.dyd-pulpit-cols > [class*="col-"] > .card:last-child { margin-bottom: 0; }
.dyd-card-accent { border-left: 4px solid transparent; }
</style>

<div class="d-flex align-items-center mb-3 gap-2 flex-wrap">
  <h1 class="h5 fw-bold mb-0"><i class="bi bi-house me-2" aria-hidden="true"></i>Pulpit</h1>
  <span class="badge text-bg-light border text-dark">
    <?= h($_day_pl[date('D')] ?? '') ?>, <?= h(date('j.m.Y')) ?>
  </span>
  <?php if (!empty($courses)): ?>
  <span class="badge bg-secondary"><?= count($courses) ?> <?= count($courses) === 1 ? 'grupa' : 'grup' ?></span>
  <?php endif; ?>
</div>

<?php
// Przypomnienie o protokole za POPRZEDNI miesiąc — tylko między 1. a 5. dniem
// bieżącego miesiąca (wtedy zwykle trzeba go domknąć), patrz includes/ti_protocols.php.
$_prev_ym = date('Y-m', strtotime('first day of last month'));
$_prot_prev = ((int)date('j') <= 5)
    ? array_values(array_filter($_my_pending_protocols ?? [], fn($p) => $p['year_month'] === $_prev_ym))
    : [];
if ($_prot_prev):
?>
<div class="alert alert-warning d-flex align-items-start gap-2 flex-wrap" role="alert">
  <i class="bi bi-journal-check flex-shrink-0 mt-1" aria-hidden="true"></i>
  <div class="flex-grow-1">
    <strong>Zamknij protokół za poprzedni miesiąc</strong> —
    <?= implode(', ', array_map(fn($p) => h($p['course_name']), $_prot_prev)) ?>.
  </div>
  <a href="protokoly_moje.php" class="btn btn-sm btn-warning text-nowrap">
    <i class="bi bi-journal-check me-1" aria-hidden="true"></i>Otwórz kreator protokołów
  </a>
</div>
<?php endif; ?>

<?php // Kierownik (rola „Kierownik Instytucji”): stan instytucji nad częścią wspólną
if (function_exists('dyd_is_staff') && dyd_is_staff()) include __DIR__ . '/_tab_pulpit_kierownik.php'; ?>

<?php /* ── Pasek szybkich statystyk: fluid Bootstrap grid, bez stałych szerokości ── */
  $_stat_pct_all = null;
  if (!empty($_att_rows)) {
      $_sp = 0; $_sa = 0;
      foreach ($_att_rows as $_ar0) { $_sp += (int)$_ar0['present']; $_sa += (int)$_ar0['absent']; }
      if ($_sp + $_sa > 0) $_stat_pct_all = (int)round($_sp / ($_sp + $_sa) * 100);
  }
?>
<?php /* Zamiast kafli z dużymi liczbami — jedna linia podsumowania (styl USOS) */ ?>
<p class="dyd-pulpit-sum small mb-2" aria-label="Podsumowanie">
  <span class="<?= $_todo ? 'fw-semibold text-warning-emphasis' : 'text-body-secondary' ?>"><i class="bi bi-list-check me-1" aria-hidden="true"></i>Do zrobienia: <?= count($_todo) ?></span>
  <span class="text-body-secondary"> · </span>
  <span><i class="bi bi-calendar-day me-1" aria-hidden="true"></i>Dziś: <?= count($dash_today) ?></span>
  <span class="text-body-secondary"> · </span>
  <span><i class="bi bi-calendar-week me-1" aria-hidden="true"></i>Następne 7 dni: <?= count($dash_upcoming) ?></span>
  <span class="text-body-secondary"> · </span>
  <span><i class="bi bi-graph-up me-1" aria-hidden="true"></i>Frekwencja <?= h(date('m.Y')) ?>: <?= $_stat_pct_all === null ? '—' : $_stat_pct_all . '%' ?></span>
</p>

<div class="row g-3 dyd-pulpit-cols">
<div class="col-lg-7">
<?php /* ── Do zrobienia ──────────────────────────────────────────────────── */ ?>
<div class="card dyd-card-accent <?= $_todo ? 'border-warning' : '' ?>">
  <div class="card-header d-flex align-items-center gap-2">
    <i class="bi bi-list-check text-warning" aria-hidden="true"></i>Do zrobienia
  </div>
  <?php if (!$_todo): ?>
  <div class="card-body small text-body-secondary">
    <i class="bi bi-check2-circle me-1 text-success" aria-hidden="true"></i>Nic nie czeka — wszystko uzupełnione.
  </div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <caption class="visually-hidden">Zadania czekające na prowadzącego</caption>
      <tbody>
        <?php foreach ($_todo as $_row): [$_v, $_ico, $_txt, $_href, $_lbl] = $_row; $_onclick = $_row[5] ?? null; ?>
        <tr>
          <td style="width:2rem"><i class="bi bi-<?= h($_ico) ?> text-<?= h($_v) ?>" aria-hidden="true"></i></td>
          <td class="small"><?= h($_txt) ?></td>
          <td class="text-end text-nowrap" style="width:11rem">
            <?php if ($_href): ?>
              <a href="<?= h($_href) ?>" class="btn btn-sm btn-outline-primary"><?= h($_lbl) ?></a>
            <?php elseif ($_onclick): ?>
              <button type="button" class="btn btn-sm btn-danger" onclick="<?= h($_onclick) ?>">
                <i class="bi bi-check2-all me-1" aria-hidden="true"></i><?= h($_lbl) ?>
              </button>
            <?php else: ?>
              <button type="button" class="btn btn-sm btn-primary" aria-haspopup="dialog"
                      onclick="wizOpen(<?= count($dyd_wizard_sessions) === 1 ? (int)$dyd_wizard_sessions[0]['id'] : 'null' ?>)">
                <i class="bi bi-magic me-1" aria-hidden="true"></i><?= h($_lbl) ?>
              </button>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php if (!empty($dash_overdue)): ?>
<form method="post" id="bulkOverdueForm" class="d-none">
  <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
  <input type="hidden" name="_op" value="bulk_complete_overdue">
  <?php foreach ($dash_overdue as $_ov): ?>
  <input type="hidden" name="session_ids[]" value="<?= (int)$_ov['id'] ?>">
  <?php endforeach; ?>
</form>
<script>
function dydBulkOverdue(n) {
  if (confirm('Uzupełnić ' + n + ' zaległ' + (n === 1 ? 'ą lekcję' : 'ych lekcji') + ' — wszyscy obecni? Temat trzeba będzie dopisać osobno.')) {
    document.getElementById('bulkOverdueForm').submit();
  }
}
</script>
<?php endif; ?>

<?php /* ── Dziś ──────────────────────────────────────────────────────────── */ ?>
<div class="card">
  <div class="card-header"><i class="bi bi-calendar-day text-primary me-1" aria-hidden="true"></i>Dziś — <?= h($_day_pl[date('D')] ?? '') ?>, <?= h(date('j.m.Y')) ?></div>
  <?php if (!$dash_today): ?>
  <div class="card-body small text-body-secondary">Brak zaplanowanych zajęć na dziś.</div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle mb-0">
      <caption class="visually-hidden">Dzisiejsze zajęcia z godzinami, tematem, statusem i akcjami</caption>
      <thead><tr>
        <th scope="col" style="width:8rem">Godziny</th>
        <th scope="col">Grupa</th>
        <th scope="col">Temat</th>
        <th scope="col" style="width:6rem" class="text-end">Osób</th>
        <th scope="col" style="width:8rem">Status</th>
        <th scope="col" class="text-end" style="width:14rem">Akcje</th>
      </tr></thead>
      <tbody>
        <?php
        $_now = date('H:i');
        foreach ($dash_today as $_s):
          $tf   = substr((string)($_s['time_from'] ?? ''), 0, 5) ?: '—';
          $tt   = substr((string)($_s['time_to']   ?? ''), 0, 5);
          $done = $_is_done((string)$_s['status']);
          $canc = (string)$_s['status'] === 'cancelled';
          $now  = !$done && !$canc && $tf !== '—' && $tf <= $_now && ($tt === '' || $tt >= $_now);
          $curl = 'index.php?course=' . (int)$_s['course_id'] . '&tab=lekcje';
          $meet = trim((string)($_s['meeting_url'] ?: ($_s['default_meeting_url'] ?? '')));
          $att  = $_att_by_session[(int)$_s['id']] ?? [];
        ?>
        <tr<?= $now ? ' class="table-warning"' : '' ?>>
          <td class="text-nowrap"><strong><?= h($tf) ?><?= $tt !== '' ? '–' . h($tt) : '' ?></strong>
            <?php if ($now): ?><div class="small text-danger fw-semibold">trwa</div><?php endif; ?>
          </td>
          <td class="small"><a href="<?= h($curl) ?>"><?= h($_s['course_name']) ?></a></td>
          <td class="small"><?= trim((string)($_s['topic'] ?? '')) !== '' ? h($_s['topic']) : '<span class="text-muted">—</span>' ?></td>
          <td class="text-end small"><?= (int)($_s['enrolled'] ?? 0) ?: '<span class="text-muted">—</span>' ?></td>
          <td class="small"><span class="badge text-bg-<?= h($_pulpit_status_badge((string)$_s['status'])) ?>"><?= h($_pulpit_status_label((string)$_s['status'])) ?></span></td>
          <td class="text-end text-nowrap">
            <?php if ((string)$_s['status'] === 'planned'): ?>
            <button type="button" class="btn btn-sm btn-success" aria-haspopup="dialog"
                    onclick="wizOpen(<?= (int)$_s['id'] ?>)"
                    aria-label="Uzupełnij obecność i temat: <?= h($_s['course_name']) ?>">
              <i class="bi bi-journal-text me-1" aria-hidden="true"></i>Uzupełnij dane lekcji
            </button>
            <?php endif; ?>
            <?php if (!$done && !$canc && $att): ?>
            <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal"
                    data-bs-target="#dyd-att-<?= (int)$_s['id'] ?>" aria-haspopup="dialog"
                    aria-label="Oznacz obecność: <?= h($_s['course_name']) ?>">
              <i class="bi bi-check2-all me-1" aria-hidden="true"></i>Obecność
            </button>
            <?php endif; ?>
            <?php if ($meet !== ''): ?>
            <a href="<?= h($meet) ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-outline-secondary"
               aria-label="Otwórz spotkanie online (nowa karta)"><i class="bi bi-camera-video" aria-hidden="true"></i></a>
            <?php endif; ?>
            <a href="<?= h($curl) ?>" class="btn btn-sm btn-outline-secondary">Zajęcia</a>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php /* ── Najbliższe 7 dni ──────────────────────────────────────────────── */ ?>
<div class="card">
  <div class="card-header"><i class="bi bi-calendar-week text-secondary me-1" aria-hidden="true"></i>Najbliższe zajęcia <span class="badge bg-secondary ms-1"><?= count($dash_upcoming) ?></span>
    <span class="ms-2 small fw-normal text-body-secondary">następne 7 dni</span></div>
  <?php if (!$dash_upcoming): ?>
  <div class="card-body small text-body-secondary">Brak zajęć zaplanowanych w najbliższych 7 dniach.</div>
  <?php else: ?>
  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle mb-0">
      <caption class="visually-hidden">Zajęcia zaplanowane na najbliższe siedem dni</caption>
      <thead><tr>
        <th scope="col" style="width:9rem">Data</th>
        <th scope="col" style="width:8rem">Godziny</th>
        <th scope="col">Grupa</th>
        <th scope="col">Temat</th>
        <th scope="col" class="text-end" style="width:7rem"></th>
      </tr></thead>
      <tbody>
        <?php foreach ($dash_upcoming as $_s):
          $tf   = substr((string)($_s['time_from'] ?? ''), 0, 5) ?: '—';
          $tt   = substr((string)($_s['time_to']   ?? ''), 0, 5);
          $curl = 'index.php?course=' . (int)$_s['course_id'] . '&tab=lekcje';
        ?>
        <tr>
          <td class="text-nowrap small">
            <?= h(date('d.m.Y', strtotime((string)$_s['lesson_date']))) ?>
            <span class="text-body-secondary"><?= h($_dow_pl[date('D', strtotime((string)$_s['lesson_date']))] ?? '') ?></span>
          </td>
          <td class="text-nowrap small"><?= h($tf) ?><?= $tt !== '' ? '–' . h($tt) : '' ?></td>
          <td class="small"><a href="<?= h($curl) ?>"><?= h($_s['course_name']) ?></a></td>
          <td class="small"><?= trim((string)($_s['topic'] ?? '')) !== '' ? h($_s['topic']) : '<span class="text-muted">—</span>' ?></td>
          <td class="text-end"><a href="<?= h($curl) ?>" class="btn btn-sm btn-outline-secondary">Zajęcia</a></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

</div><?php /* /lewa kolumna */ ?>
<div class="col-lg-5">

<?php /* ── Ostatnie komunikaty ───────────────────────────────────────────── */ ?>
<?php $_notices = array_slice($dyd_notices ?? [], 0, 5); ?>
<?php if ($_notices): ?>
<div class="card">
  <div class="card-header d-flex align-items-center">
    <span><i class="bi bi-megaphone text-warning me-1" aria-hidden="true"></i>Komunikaty placówki</span>
    <a href="index.php?tab=komunikaty" class="btn btn-sm btn-outline-secondary ms-auto">Wszystkie</a>
  </div>
  <div class="table-responsive">
    <table class="table table-sm table-hover align-middle mb-0">
      <caption class="visually-hidden">Ostatnie komunikaty placówki</caption>
      <thead><tr>
        <th scope="col" style="width:7rem">Data</th>
        <th scope="col">Tytuł</th>
        <th scope="col" style="width:10rem">Autor</th>
        <th scope="col" style="width:7rem">Stan</th>
      </tr></thead>
      <tbody>
        <?php foreach ($_notices as $_n): $_unread = empty($_n['is_read']); ?>
        <tr>
          <td class="text-nowrap small"><?= h(date('d.m.Y', strtotime((string)$_n['created_at']))) ?></td>
          <td class="small">
            <a href="index.php?tab=komunikaty" class="<?= $_unread ? 'fw-semibold' : '' ?>"><?= h($_n['title']) ?></a>
            <?php if (!empty($_n['is_pinned'])): ?><span class="badge text-bg-warning ms-1">przypięty</span><?php endif; ?>
          </td>
          <td class="small text-body-secondary"><?= h($_n['author_name'] ?? '') ?></td>
          <td class="small">
            <?php if ($_unread): ?><span class="badge text-bg-primary">nowy</span>
            <?php else: ?><span class="text-body-secondary">przeczytany</span><?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php /* ── Frekwencja: bieżący miesiąc ($_att_rows policzone wyżej) ───────── */ ?>
<?php if ($_att_rows): ?>
<div class="card">
  <div class="card-header"><i class="bi bi-graph-up text-success me-1" aria-hidden="true"></i>Frekwencja — <?= h(date('m.Y')) ?></div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <caption class="visually-hidden">Frekwencja w bieżącym miesiącu per grupa</caption>
      <thead><tr>
        <th scope="col">Grupa</th>
        <th scope="col" class="text-end" style="width:6rem">Zajęć</th>
        <th scope="col" class="text-end" style="width:7rem">Obecni</th>
        <th scope="col" class="text-end" style="width:7rem">Nieobecni</th>
        <th scope="col" class="text-end" style="width:8rem">Frekwencja</th>
      </tr></thead>
      <tbody>
        <?php foreach ($_att_rows as $_ar):
          $_tot = (int)$_ar['present'] + (int)$_ar['absent'];
          $_pct = $_tot > 0 ? (int)round((int)$_ar['present'] / $_tot * 100) : null;
        ?>
        <tr>
          <td class="small"><a href="index.php?course=<?= (int)$_ar['course_id'] ?>&tab=nieobecnosci"><?= h($_ar['course_name']) ?></a></td>
          <td class="text-end small"><?= (int)$_ar['lessons'] ?></td>
          <td class="text-end small"><?= (int)$_ar['present'] ?></td>
          <td class="text-end small<?= (int)$_ar['absent'] > 0 ? ' text-danger' : '' ?>"><?= (int)$_ar['absent'] ?></td>
          <td class="text-end small">
            <?php if ($_pct === null): ?><span class="text-muted">—</span>
            <?php else: $_barc = $_pct >= 80 ? '#198754' : ($_pct >= 60 ? '#ffc107' : '#dc3545'); ?>
              <div class="d-flex align-items-center justify-content-end gap-2">
                <div class="dyd-bar flex-grow-1"><div class="dyd-bar-fill" style="width:<?= $_pct ?>%;background:<?= $_barc ?>"></div></div>
                <span class="badge <?= $_pct >= 80 ? 'text-bg-success' : ($_pct >= 60 ? 'text-bg-warning' : 'text-bg-danger') ?>"><?= $_pct ?>%</span>
              </div>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php /* ── Trend: ostatnie 6 miesięcy (tabela zamiast wykresów SVG) ──────── */ ?>
<?php
$_ch_mon = [1=>'styczeń',2=>'luty',3=>'marzec',4=>'kwiecień',5=>'maj',6=>'czerwiec',
            7=>'lipiec',8=>'sierpień',9=>'wrzesień',10=>'październik',11=>'listopad',12=>'grudzień'];
$_ch_rows = [];
if (!empty($course_ids)) {
    $_ch_ph = implode(',', array_fill(0, count($course_ids), '?'));
    $_ch_rows = db_all(
        "SELECT strftime('%Y-%m', s.lesson_date) AS ym,
                SUM(CASE WHEN s.status='remote_material' THEN 1 ELSE 0 END) AS spr,
                SUM(CASE WHEN s.status IN ('held','individual_change') THEN 1 ELSE 0 END) AS reg,
                SUM(CASE WHEN s.status IN ('held','individual_change')
                         AND COALESCE(a.cancelled,0)=0 AND COALESCE(a.no_show,0)=0 AND a.attended=1 THEN 1 ELSE 0 END) AS present,
                SUM(CASE WHEN s.status IN ('held','individual_change')
                         AND COALESCE(a.cancelled,0)=0 AND COALESCE(a.no_show,0)=0 AND a.attended=0 THEN 1 ELSE 0 END) AS absent
           FROM k30_ti_sessions s
           LEFT JOIN k30_ti_attendance a ON a.session_id = s.id
          WHERE s.course_id IN ($_ch_ph)
            AND s.status IN ('held','individual_change','remote_material')
            AND s.lesson_date >= date('now','-6 months','localtime')
          GROUP BY ym ORDER BY ym DESC",
        $course_ids
    );
}
?>
<?php if ($_ch_rows): ?>
<div class="card">
  <div class="card-header"><i class="bi bi-bar-chart-line text-info me-1" aria-hidden="true"></i>Trend — ostatnie 6 miesięcy</div>
  <div class="table-responsive">
    <table class="table table-sm align-middle mb-0">
      <caption class="visually-hidden">Zajęcia i frekwencja w kolejnych miesiącach</caption>
      <thead><tr>
        <th scope="col">Miesiąc</th>
        <th scope="col" class="text-end" style="width:7rem">Zajęcia</th>
        <th scope="col" class="text-end" style="width:8rem">Praca własna</th>
        <th scope="col" class="text-end" style="width:7rem">Obecni</th>
        <th scope="col" class="text-end" style="width:7rem">Nieobecni</th>
        <th scope="col" class="text-end" style="width:8rem">Frekwencja</th>
      </tr></thead>
      <tbody>
        <?php foreach ($_ch_rows as $_cr):
          [$_y, $_m] = array_pad(explode('-', (string)$_cr['ym']), 2, '1');
          $_tot = (int)$_cr['present'] + (int)$_cr['absent'];
          $_pct = $_tot > 0 ? (int)round((int)$_cr['present'] / $_tot * 100) : null;
        ?>
        <tr>
          <th scope="row" class="small fw-semibold"><?= h(($_ch_mon[(int)$_m] ?? $_m) . ' ' . $_y) ?></th>
          <td class="text-end small"><?= (int)$_cr['reg'] ?></td>
          <td class="text-end small"><?= (int)$_cr['spr'] ?></td>
          <td class="text-end small"><?= (int)$_cr['present'] ?></td>
          <td class="text-end small<?= (int)$_cr['absent'] > 0 ? ' text-danger' : '' ?>"><?= (int)$_cr['absent'] ?></td>
          <td class="text-end small" style="min-width:8rem">
            <?php if ($_pct === null): ?><span class="text-muted">—</span>
            <?php else: $_barc2 = $_pct >= 80 ? '#198754' : ($_pct >= 60 ? '#ffc107' : '#dc3545'); ?>
              <div class="d-flex align-items-center justify-content-end gap-2">
                <div class="dyd-bar flex-grow-1"><div class="dyd-bar-fill" style="width:<?= $_pct ?>%;background:<?= $_barc2 ?>"></div></div>
                <span class="small"><?= $_pct ?>%</span>
              </div>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

</div><?php /* /prawa kolumna */ ?>
</div><?php /* /row */ ?>

<?php /* ── Okna obecności — POZA tabelami, żeby overflow ich nie ucinał ──── */ ?>
<?php foreach ($dash_today as $_s):
  $att = $_att_by_session[(int)$_s['id']] ?? [];
  if (!$att || $_is_done((string)$_s['status']) || (string)$_s['status'] === 'cancelled') continue;
  $tf = substr((string)($_s['time_from'] ?? ''), 0, 5);
  $tt = substr((string)($_s['time_to']   ?? ''), 0, 5);
?>
<div class="modal fade" id="dyd-att-<?= (int)$_s['id'] ?>" tabindex="-1" role="dialog"
     aria-labelledby="dyd-att-lbl-<?= (int)$_s['id'] ?>" aria-modal="true">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h2 class="modal-title fs-6 fw-semibold" id="dyd-att-lbl-<?= (int)$_s['id'] ?>">
          <i class="bi bi-check2-all me-2" aria-hidden="true"></i>Oznacz obecność
        </h2>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <form method="post" action="index.php?course=<?= (int)$_s['course_id'] ?>&tab=lekcje">
        <input type="hidden" name="_token"     value="<?= h(dyd_token()) ?>">
        <input type="hidden" name="_op"        value="save_attendance">
        <input type="hidden" name="session_id" value="<?= (int)$_s['id'] ?>">
        <input type="hidden" name="course_id"  value="<?= (int)$_s['course_id'] ?>">
        <input type="hidden" name="_tab"       value="lekcje">
        <div class="modal-body">
          <p class="small text-body-secondary mb-2">
            <?= h($_s['course_name']) ?> · <?= h($tf) ?><?= $tt !== '' ? '–' . h($tt) : '' ?> ·
            <?= count($att) ?> <?= count($att) === 1 ? 'kursant' : 'kursantów' ?>
          </p>
          <button type="button" class="btn btn-sm btn-outline-secondary mb-2" onclick="dydPulpitToggleAll(this)">
            Zaznacz wszystkich
          </button>
          <div role="list" aria-label="Lista kursantów — zaznacz obecnych">
            <?php foreach ($att as $_a): ?>
            <label class="d-flex align-items-center gap-2 py-1 border-bottom" role="listitem">
              <input type="checkbox" name="attended[]" value="<?= (int)$_a['id'] ?>"
                     class="dyd-att-cb form-check-input m-0 flex-shrink-0"
                     <?= $_a['attended'] ? 'checked' : '' ?>
                     aria-label="Obecność: <?= h($_a['name']) ?>">
              <span class="small"><?= h($_a['name']) ?></span>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
          <button type="submit" class="btn btn-primary"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Zapisz obecność</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endforeach; ?>

<script>
function dydPulpitToggleAll(btn) {
  var form   = btn.closest('form');
  var boxes  = form.querySelectorAll('.dyd-att-cb');
  var allChk = Array.from(boxes).every(function (b) { return b.checked; });
  boxes.forEach(function (b) { b.checked = !allChk; });
  btn.textContent = allChk ? 'Zaznacz wszystkich' : 'Odznacz wszystkich';
}
</script>
