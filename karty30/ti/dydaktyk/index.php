<?php
/**
 * karty30/ti/dydaktyk/index.php — Panel dydaktyka (prowadzącego) TI.
 *
 * Logowanie jak do SZO; dostęp dla doradców TyfloKonsultacji (k30_consultant)
 * oraz pracowników K30/admina. Dydaktyk widzi WYŁĄCZNIE swoje kursy i może w nich:
 *   • dodawać / edytować / usuwać lekcje,
 *   • dodawać / edytować / usuwać zadania domowe,
 *   • dodawać / edytować / usuwać materiały (eLearning).
 * Wzorowane na panelu kursanta (wspólny layout, ciemny motyw, WCAG).
 */
require_once __DIR__ . '/auth.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_leaves.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_messages.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_reschedule.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/sms_templates.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_notices.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_periods.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_protocols.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_planner_ext.php';

// Bufor całej strony: zakładki _tab_kursy / _tab_uczestnicy / _tab_billing /
// _tab_rozliczenia obsługują POST dopiero w miejscu dołączenia — PO nagłówku
// strony — i kończą header('Location'). Bez bufora, gdy hosting ma wyłączone
// output_buffering, leci „headers already sent” i przekierowanie ginie
// (operacja i tak się wykonała). Treść wysłana razem z 302 jest ignorowana.
ob_start();

karty30_migrate();
k30_ti_reschedule_migrate();
ti_notices_migrate();
ti_planner_ext_migrate();
$me  = dyd_require();
$uid = (int)$me['user_id'];

// ── Bramka przerwy technicznej (tylko dla prowadzących, nie dla staff/admin) ──
if (!dyd_panel_is_enabled() && empty($me['is_staff'])) {
    $KP_TITLE  = 'Przerwa techniczna';
    $KP_TOPBAR = ['brand'=>'Panel dydaktyka','icon'=>'easel2','user'=>$me['name']??'','logout'=>'logout.php'];
    include dirname(__DIR__) . '/kursant/_layout_head.php';
    $_dyd_msg    = dyd_panel_message();
    $_dyd_resume = dyd_panel_resume();
    ?>
<style>
  .dyd-maintenance-wrap{min-height:70vh;display:flex;align-items:center;justify-content:center;padding:2rem 1rem;}
  .dyd-maintenance-card{max-width:520px;width:100%;text-align:center;}
  .dyd-maintenance-icon{font-size:4rem;line-height:1;margin-bottom:1rem;color:#f59e0b;}
  .dyd-maintenance-title{font-size:1.5rem;font-weight:700;margin-bottom:.5rem;}
  .dyd-maintenance-msg{color:var(--bs-secondary-color);font-size:1.05rem;margin-bottom:1.5rem;}
  .dyd-maintenance-resume{display:inline-flex;align-items:center;gap:.5rem;font-size:.9rem;
    background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.35);
    color:#b45309;border-radius:.5rem;padding:.4rem .9rem;margin-bottom:1.5rem;}
  [data-bs-theme=dark] .dyd-maintenance-resume{color:#fcd34d;background:rgba(245,158,11,.08);border-color:rgba(245,158,11,.25);}
</style>
<div class="dyd-maintenance-wrap">
  <div class="dyd-maintenance-card">
    <div class="dyd-maintenance-icon" aria-hidden="true"><i class="bi bi-cone-striped"></i></div>
    <div class="dyd-maintenance-title">Przerwa techniczna</div>
    <p class="dyd-maintenance-msg"><?= h($_dyd_msg) ?></p>
    <?php if ($_dyd_resume !== ''): ?>
    <div class="d-flex justify-content-center mb-3">
      <span class="dyd-maintenance-resume">
        <i class="bi bi-clock me-1"></i>
        Planowane wznowienie:
        <strong><?= h(date('j.m.Y, G:i', strtotime($_dyd_resume))) ?></strong>
      </span>
    </div>
    <?php endif ?>
    <a href="logout.php" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-box-arrow-right me-1"></i>Wyloguj
    </a>
  </div>
</div>
    <?php
    $KP_SKIP_TAB_MEMORY = true;
    include dirname(__DIR__) . '/kursant/_layout_foot.php';
    exit;
}

// Widok panelu: klasyczny albo USOS (skórka — inny chrome i typografia, ta sama treść)
$DYD_UI = dyd_ui($uid);

// Podpowiedź dopisywana do komunikatów o kolizji z zajętością Zoom
const ZOOM_BUSY_HINT = ' Wolne terminy i wyjaśnienie pokazuje zakładka „Zajętość Zoom” w menu Zasoby.';

// Okno wyłączenia dziennika ocen — staff/admin prowadzą prace, więc ich nie dotyczy
$dziennik_off = empty($me['is_staff']) ? dyd_dziennik_blackout() : null;

$courses   = dyd_courses($uid);
// Dla admina/staff: zestaw ID kursów gdzie sam jest prowadzącym lub co-prowadzącym
$my_course_ids_set = dyd_is_staff()
    ? array_flip(array_column(k30_ti_instructor_courses($uid, false), 'id'))
    : [];
$my_leaves = ti_leaves_for_instructor($uid);   // własne urlopy: trwające + nadchodzące
$my_avail  = ti_instructor_availability($uid);  // własne okna dostępności w tygodniu
$_my_pending_protocols = ti_protocol_pending_months_for_instructor($uid); // protokoły miesięczne do zamknięcia
$dyd_notices        = ti_notices_list_active_for_instructor($uid);
$dyd_notices_unread = ti_notices_unread_count_instructor($uid);
$dyd_notices_admin  = dyd_is_staff() ? ti_notices_list_admin() : null;

// ── Pobieranie załączników (zadania / materiały) — tylko z własnych kursów ────
if (isset($_GET['dl'])) {
    $kind = $_GET['dl'];
    if ($kind === 'hw') {
        $hw = k30_ti_homework_get((int)($_GET['id'] ?? 0));
        if ($hw && dyd_owns_course($uid, (int)$hw['course_id']) && $hw['attach_path'] !== '')
            k30_ti_homework_send_file($hw['attach_path'], $hw['attach_name']);
    } elseif ($kind === 'mat') {
        $m = k30_ti_material_get((int)($_GET['id'] ?? 0));
        if ($m && dyd_owns_course($uid, (int)$m['course_id']) && $m['attach_path'] !== '')
            k30_ti_homework_send_file($m['attach_path'], $m['attach_name']);
    } elseif ($kind === 'sub') {
        // Plik oddany przez kursanta — tylko dla prowadzącego kursu
        $sub = db_one(
            "SELECT s.file_path, s.file_name, h.course_id
             FROM k30_ti_homework_submissions s JOIN k30_ti_homework h ON h.id=s.homework_id
             WHERE s.id=?", [(int)($_GET['id'] ?? 0)]);
        if ($sub && dyd_owns_course($uid, (int)$sub['course_id']) && $sub['file_path'] !== '')
            k30_ti_homework_send_file($sub['file_path'], $sub['file_name']);
    }
    http_response_code(404); exit('Plik nie istnieje.');
}

// ── Bieżący kurs i zakładka ───────────────────────────────────────────────────
$course_ids = array_map(fn($c) => (int)$c['id'], $courses);
// Grupy archiwalne (status 'archived', także „Zamknij i archiwizuj”): do OTWARCIA
// z selektora, ale poza $courses/$course_ids — Pulpit, zaległości i liczniki ich
// nie liczą. Kierownik: k30_ti_courses() je pomija, więc dobieramy osobno;
// prowadzący ma je w k30_ti_instructor_courses() — tylko je wydzielamy.
ti_course_close_migrate();
$courses_archived = dyd_is_staff()
    ? array_values(array_filter(k30_ti_courses(false, true), fn($c) => ($c['status'] ?? '') === 'archived'))
    : array_values(array_filter($courses, fn($c) => ($c['status'] ?? '') === 'archived'));
$_open_ids = array_values(array_unique(array_merge($course_ids, array_map(fn($c) => (int)$c['id'], $courses_archived))));
$_sess_key  = 'dyd_course_' . $uid;
$_pref_key  = 'dyd_last_course';

// Czy mamy już JAKIKOLWIEK zapamiętany wybór (ta sesja przeglądarki lub poprzednie
// logowanie) — decyduje, czy przy wejściu na pulpit bez ?course= pytamy o grupę.
$_pref_course     = (int)dyd_pref($uid, $_pref_key);
$_had_remembered  = !empty($_SESSION[$_sess_key]) || ($_pref_course && in_array($_pref_course, $course_ids, true));

// Pobierz kurs: URL → sesja → zapamiętana preferencja (poprzednie logowanie) → pierwszy z listy
if (isset($_GET['course'])) {
    $cur_course = (int)$_GET['course'];
    if (in_array($cur_course, $_open_ids, true)) {
        $_SESSION[$_sess_key] = $cur_course;                    // zapamiętaj wybór (ta sesja)
        dyd_pref_set($uid, $_pref_key, (string)$cur_course);    // i na przyszłe logowania
    }
} elseif (!empty($_SESSION[$_sess_key]) && in_array((int)$_SESSION[$_sess_key], $_open_ids, true)) {
    $cur_course = (int)$_SESSION[$_sess_key];
} elseif ($_pref_course && in_array($_pref_course, $_open_ids, true)) {
    $cur_course = $_pref_course;
    $_SESSION[$_sess_key] = $cur_course;
} else {
    $cur_course = 0;  // nie ustawiony — pokaż picker (jeśli >1 kurs) lub wybierz jedyny
}
if (!in_array($cur_course, $_open_ids, true)) $cur_course = $course_ids[0] ?? 0;

// Pytaj o grupę TYLKO przy wejściu na pulpit bez wcześniejszego wyboru (pierwsze
// logowanie / brak zapamiętanej preferencji) — na innych zakładkach cicho używamy
// pierwszego kursu z listy, żeby nie blokować bezpośrednich linków (np. z powiadomień).
$_show_course_picker = !$_had_remembered && !isset($_GET['course'])
    && count($courses) > 1 && ($_GET['tab'] ?? 'pulpit') === 'pulpit';

$tab = $_GET['tab'] ?? 'pulpit';
// „Program zajęć" nazywa się teraz „Sylabus" — adres ?tab=sylabus prowadzi tam,
// a stare linki i zakładki na ?tab=program nadal działają.
if ($tab === 'sylabus') $tab = 'program';
// Testy i egzaminy to jeden moduł: Equi Exams. Stare wejście „Testy" przekierowuje
// na egzaminy, żeby nie było dwóch miejsc o tym samym zadaniu — archiwum starszych
// quizów zostaje dostępne pod ?tab=testy&legacy=1 (link w zakładce Egzaminy).
if ($tab === 'testy' && empty($_GET['legacy'])) {
    header('Location: index.php?' . ($cur_course ? 'course=' . (int)$cur_course . '&' : '') . 'tab=egzaminy');
    exit;
}
if (!in_array($tab, ['pulpit', 'lekcje', 'zadania', 'materialy', 'nieobecnosci', 'program', 'oceny', 'dostepnosc', 'testy', 'egzaminy', 'wiadomosci', 'formalnosci', 'komunikaty', 'komunikacja', 'dysk', 'cykliczne', 'wydruki', 'rozliczenia', 'wypłaty', 'praca_wlasna', 'grupy', 'billing', 'kursy', 'frekwencja_grup', 'zoom', 'uczestnicy', 'plan', 'protokol', 'pomoc'], true)) $tab = 'pulpit';
if (in_array($tab, ['rozliczenia', 'wypłaty', 'praca_wlasna', 'grupy', 'billing', 'kursy', 'komunikacja'], true) && !dyd_is_staff()) $tab = 'pulpit';
if ($tab === 'cykliczne' && !dyd_plan_cykliczny_enabled()) $tab = 'pulpit';   // plan cykliczny wyłączony (auth.php)

// Picker pełnoekranowy — pyta o grupę tylko przy pierwszym wejściu na pulpit
// (brak zapamiętanego wyboru); poza tym wybór grupy przez dropdown w topbarze.
if ($_show_course_picker) {
    // Najbliższa zaplanowana lekcja per kurs
    $next_lessons = [];
    if ($course_ids) {
        $ph = implode(',', array_fill(0, count($course_ids), '?'));
        $nl = db_all(
            "SELECT course_id, lesson_date, time_from, topic
             FROM k30_ti_sessions
             WHERE course_id IN ($ph) AND lesson_date >= date('now') AND status='planned'
             GROUP BY course_id HAVING lesson_date=MIN(lesson_date)
             ORDER BY lesson_date, time_from",
            $course_ids
        );
        foreach ($nl as $r) $next_lessons[(int)$r['course_id']] = $r;
    }
    $KP_TITLE  = 'Wybierz grupę';
    $KP_TOPBAR = ['brand'=>'Panel dydaktyka','icon'=>'easel2','user'=>$me['name']??'','logout'=>'logout.php'];
    include dirname(__DIR__) . '/kursant/_layout_head.php';
    ?>
<style>
  .dyd-picker-wrap{min-height:70vh;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:2rem 1rem;}
  .dyd-picker-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(270px,1fr));gap:1.1rem;width:100%;max-width:860px;}
  .dyd-picker-card{display:flex;flex-direction:column;text-decoration:none;color:inherit;
    border:1.5px solid var(--bs-border-color);border-radius:.75rem;padding:1.25rem 1.4rem;
    background:var(--bs-body-bg);transition:border-color .15s,box-shadow .15s,transform .12s;}
  .dyd-picker-card:hover,.dyd-picker-card:focus{
    border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.15);
    transform:translateY(-2px);color:inherit;text-decoration:none;}
  .dyd-picker-icon{font-size:1.75rem;width:2.75rem;height:2.75rem;border-radius:.6rem;
    display:flex;align-items:center;justify-content:center;
    background:rgba(37,99,235,.1);color:#2563eb;flex-shrink:0;margin-bottom:.9rem;}
  [data-bs-theme=dark] .dyd-picker-icon{background:rgba(96,165,250,.12);color:#60a5fa;}
  .dyd-picker-name{font-size:1.05rem;font-weight:700;line-height:1.3;margin-bottom:.25rem;}
  .dyd-picker-sub{font-size:.82rem;color:var(--bs-secondary-color);}
  .dyd-picker-meta{margin-top:.85rem;padding-top:.75rem;border-top:1px solid var(--bs-border-color);
    display:flex;flex-wrap:wrap;gap:.35rem .9rem;}
  .dyd-picker-badge{font-size:.78rem;display:inline-flex;align-items:center;gap:.3rem;
    color:var(--bs-secondary-color);}
  .dyd-picker-next{margin-top:.5rem;font-size:.8rem;
    background:rgba(22,163,74,.08);border:1px solid rgba(22,163,74,.2);
    border-radius:.4rem;padding:.25rem .6rem;color:#15803d;display:inline-flex;align-items:center;gap:.35rem;}
  [data-bs-theme=dark] .dyd-picker-next{background:rgba(22,163,74,.12);border-color:rgba(22,163,74,.25);color:#4ade80;}
  .dyd-picker-inactive{opacity:.65;}
  .dyd-picker-inactive .dyd-picker-icon{background:rgba(100,116,139,.1);color:var(--bs-secondary-color);}
</style>
<div class="dyd-picker-wrap">
  <div style="text-align:center;margin-bottom:2rem;max-width:860px;width:100%">
    <div style="font-size:1.5rem;font-weight:700;margin-bottom:.3rem">
      <i class="bi bi-easel2 me-2 text-primary" aria-hidden="true"></i>Z którą grupą pracujesz dziś?
    </div>
    <div style="color:var(--bs-secondary-color);font-size:.95rem">
      Witaj, <strong><?= h($me['name'] ?? '') ?></strong>. Masz przypisanych kilka grup — wybierz, którą chcesz otworzyć.
      Grupę możesz też zmienić w każdej chwili z menu w prawym górnym rogu.
    </div>
  </div>

  <div class="dyd-picker-grid" role="list">
    <?php foreach ($courses as $c):
      $cid      = (int)$c['id'];
      $active   = ($c['status'] ?? '') !== 'cancelled' && !empty($c['is_active']);
      $enrolled = (int)($c['enrolled_count'] ?? 0);
      $nl       = $next_lessons[$cid] ?? null;
      $subj     = $c['subject_name'] ?? ($c['subject_abbr'] ?? '');
    ?>
    <a class="dyd-picker-card <?= $active ? '' : 'dyd-picker-inactive' ?>"
       href="index.php?course=<?= $cid ?>&tab=lekcje"
       role="listitem"
       aria-label="<?= h($c['name']) ?>, <?= $enrolled ?> kursantów<?= $nl ? ', najbliższa lekcja '.date('j.m.Y',strtotime($nl['lesson_date'])) : '' ?>">
      <div class="dyd-picker-icon" aria-hidden="true">
        <i class="bi bi-<?= $active ? 'pc-display-horizontal' : 'archive' ?>"></i>
      </div>
      <div class="dyd-picker-name"><?= h($c['name']) ?></div>
      <?php if ($subj): ?>
      <div class="dyd-picker-sub"><?= h($subj) ?></div>
      <?php endif; ?>
      <?php if (!$active): ?>
      <div class="dyd-picker-sub mt-1"><i class="bi bi-archive me-1"></i>nieaktywna</div>
      <?php endif; ?>
      <div class="dyd-picker-meta">
        <span class="dyd-picker-badge">
          <i class="bi bi-people" aria-hidden="true"></i><?= $enrolled ?> <?= $enrolled === 1 ? 'kursant' : ($enrolled < 5 ? 'kursantów' : 'kursantów') ?>
        </span>
        <?php if (!empty($c['is_co_instructor'])): ?>
        <span class="dyd-picker-badge">
          <i class="bi bi-person-badge" aria-hidden="true"></i>współprowadzący
        </span>
        <?php endif; ?>
      </div>
      <?php if ($nl): ?>
      <div class="dyd-picker-next">
        <i class="bi bi-calendar-check" aria-hidden="true"></i>
        <?= date('j.m.Y', strtotime($nl['lesson_date'])) ?>
        <?php if ($nl['time_from']): ?><span style="opacity:.8"><?= h(substr($nl['time_from'],0,5)) ?></span><?php endif; ?>
        <?php if ($nl['topic']): ?><span style="opacity:.7;max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($nl['topic']) ?></span><?php endif; ?>
      </div>
      <?php endif; ?>
    </a>
    <?php endforeach; ?>
  </div>

  <?php if (!empty($me['is_staff'])): ?>
  <div style="margin-top:1.5rem;font-size:.82rem;color:var(--bs-secondary-color)">
    <i class="bi bi-info-circle me-1"></i>Widzisz wszystkie grupy jako pracownik D3.
  </div>
  <?php endif; ?>
</div>
    <?php
    include dirname(__DIR__) . '/kursant/_layout_foot.php';
    exit;
}

// ── Umowy powiązane z kontem dydaktyka ───────────────────────────────────────
$dyd_contracts = [];
$dyd_user_row     = db_one("SELECT email, microsoft_id, phone_number, alt_email, share_contact FROM users WHERE id=?", [$uid]);
$dyd_email        = trim((string)($dyd_user_row['email'] ?? ''));
$dyd_ms_id        = trim((string)($dyd_user_row['microsoft_id'] ?? ''));
$dyd_phone        = trim((string)($dyd_user_row['phone_number'] ?? ''));
$dyd_alt_email    = trim((string)($dyd_user_row['alt_email'] ?? ''));
$dyd_share_contact = (int)($dyd_user_row['share_contact'] ?? 0);
foreach ([
    ['zlecenie',    'data_zakonczenia'],
    ['wolontariat', 'data_zakonczenia'],
    ['dzielo',      'termin_oddania'],
    ['praca',       'data_zakonczenia'],
] as [$ctype, $end_col]) {
    $table = "umowy_{$ctype}";
    try {
        $conds  = [];
        $params = [];
        if ($dyd_email) { $conds[] = 'email=?'; $params[] = $dyd_email; }
        if ($dyd_ms_id) { $conds[] = 'm365_user_id=?'; $params[] = $dyd_ms_id; }
        if (!$conds) continue;
        $extra_cols = $ctype === 'zlecenie'
            ? ", COALESCE(w_ramach_is,0) AS w_ramach_is, is_nazwa, is_adres, is_numer_umowy,
               numer_projektu, klauzula_rodo, is_uprawnienia_nr, is_dyplom_nr, is_dopuszczenie"
            : "";
        $rows = db_all(
            "SELECT id, '{$ctype}' AS contract_type, numer_umowy, status, data_zawarcia,
                    {$end_col} AS data_zakonczenia, imie_nazwisko,
                    stanowisko, wynagrodzenie_brutto, miejsce_wolontariatu, przedmiot_porozumienia
                    {$extra_cols}
             FROM {$table} WHERE (" . implode(' OR ', $conds) . ") ORDER BY data_zawarcia DESC",
            $params
        );
        foreach ($rows as $r) $dyd_contracts[] = $r;
    } catch (\Throwable $e) {}
}
// sortuj: aktywne na górze
usort($dyd_contracts, function($a, $b) {
    $active = fn($s) => in_array($s, ['podpisana','w realizacji'], true) ? 0 : 1;
    return $active($a['status']) <=> $active($b['status']) ?: strcmp((string)($b['data_zawarcia'] ?? ''), (string)($a['data_zawarcia'] ?? ''));
});

/** Adres powrotu zachowujący kurs i zakładkę. */
function dyd_back(int $course, string $tab): string {
    return 'index.php?course=' . $course . '&tab=' . $tab;
}
$dt_in = fn($k) => ($v = trim($_POST[$k] ?? '')) !== '' ? str_replace('T', ' ', $v) . (strlen($v) === 16 ? ':00' : '') : null;

// Komunikacja — e-mail / SMS (stan podglądu, ustawiany w bloku POST przy komm_preview)
$komm_did_preview  = false;
$komm_recipients   = [];
$komm_filter_label = '';
$komm_mode         = 'grupa';
$komm_course_ids   = [];
$komm_instr_id     = 0;
$komm_date         = '';
$komm_ch_email     = false;
$komm_ch_sms       = false;
$komm_ch_guard     = false;
$komm_subject      = '';
$komm_body         = '';
$komm_body_html    = '';

// ── Operacje zapisu ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    $op        = $_POST['_op'] ?? '';
    $course_id = (int)($_POST['course_id'] ?? 0);

    // Grupa zamknięta („Zamknij i archiwizuj"): żadnych zmian ani protokołów.
    // Wyjątek — przywrócenie z archiwum, które zdejmuje blokadę.
    if ($op !== 'unarchive_course' && ($_cc_msg = ti_course_closed_guard($_POST))) {
        flash_set('danger', $_cc_msg);
        header('Location: ' . ($course_id ? dyd_back($course_id, $back_tab) : 'index.php?tab=kursy')); exit;
    }
    $back_tab  = in_array($_POST['_tab'] ?? '', ['lekcje','zadania','materialy'], true) ? $_POST['_tab'] : 'lekcje';

    // Zamknięty okres nauczania: operacje na ISTNIEJĄCEJ lekcji z tego okresu
    // (edycja, przeniesienie, usunięcie, obecność, odwołania, no-show) są
    // zablokowane — okres jest rozliczony protokołami. Sprawdzamy obecną datę
    // lekcji; data docelowa jest sprawdzana osobno w save_lesson/reschedule.
    $_closed_ops = ['save_lesson', 'delete_lesson', 'wizard_save', 'save_attendance',
                    'confirm_cancel', 'reject_cancel', 'cancel_attendee', 'restore_attendee',
                    'mark_no_show', 'cancel_session', 'uncancel_session', 'reschedule_session',
                    'reschedule_accept', 'excuse_absence', 'unexcuse_absence', 'confirm_reservation'];
    if (in_array($op, $_closed_ops, true) && ($_pc_s = ti_period_closed_for_session((int)($_POST['session_id'] ?? 0)))) {
        flash_set('danger', ti_period_closed_msg($_pc_s));
        header('Location: ' . dyd_back($course_id, 'lekcje')); exit;
    }

    // ── Dostępność prowadzącego (własna, niezależna od kursu) ───────────────────
    if ($op === 'avail_add') {
        $dw       = (int)($_POST['day_of_week'] ?? -1);
        $av_st    = in_array($_POST['status'] ?? '', ['draft','approved'], true) ? $_POST['status'] : 'approved';
        $vf       = trim($_POST['valid_from'] ?? '');
        $vt       = trim($_POST['valid_to'] ?? '');
        // Ważność jednego dnia = dostępność jednorazowa; dzień tygodnia musi
        // zgadzać się z datą, więc wyliczamy go z niej — mniej pomyłek.
        if ($vf !== '' && $vf === $vt && preg_match('/^\d{4}-\d{2}-\d{2}$/', $vf)) {
            $dw = (int)date('w', strtotime($vf));
        }
        if (!ti_avail_add($uid, $dw, $_POST['time_from'] ?? '', $_POST['time_to'] ?? '', $av_st, $vf, $vt)) {
            flash_set('danger', 'Podaj poprawny dzień, godziny od–do (od < do) i zakres dat (od ≤ do).');
        } else {
            flash_set('success', 'Dodano okno dostępności.');
        }
        header('Location: index.php?tab=dostepnosc'); exit;
    }
    if ($op === 'avail_delete') {
        ti_avail_delete((int)($_POST['avail_id'] ?? 0), $uid);
        flash_set('success', 'Usunięto okno dostępności.');
        header('Location: index.php?tab=dostepnosc'); exit;
    }
    if ($op === 'avail_status') {
        $status = in_array($_POST['status'] ?? '', ['draft','approved'], true) ? $_POST['status'] : 'approved';
        ti_avail_set_status((int)($_POST['avail_id'] ?? 0), $uid, $status);
        flash_set('success', TI_AVAIL_STATUS[$status]['label'] . ' — status dostępności zmieniony.');
        header('Location: index.php?tab=dostepnosc'); exit;
    }
    if ($op === 'weekly_autoassign') {
        if (!dyd_plan_cykliczny_enabled()) { flash_set('warning', 'Plan cykliczny jest wyłączony.'); header('Location: index.php?tab=pulpit'); exit; }
        $n = ti_weekly_autoassign($uid);
        flash_set($n ? 'success' : 'info', $n ? "Auto-rozkład: przypisano $n kursów." : 'Brak kursów do przypisania lub brak wolnych okien.');
        header('Location: index.php?tab=cykliczne'); exit;
    }

    // ── Reset prywatnego adresu kanału iCal (subskrypcja kalendarza lekcji) ─────
    if ($op === 'cal_token_reset') {
        k30_ti_instructor_cal_token_reset($uid);
        flash_set('success', 'Wygenerowano nowy adres kalendarza. Poprzedni link przestał działać.');
        header('Location: ' . dyd_back((int)($_POST['course_id'] ?? 0), 'lekcje')); exit;
    }

    // ── Dane kontaktowe prowadzącego ─────────────────────────────────────────────
    if ($op === 'dyd_update_contact') {
        $new_phone     = trim($_POST['phone_number'] ?? '');
        $new_alt_email = trim($_POST['alt_email'] ?? '');
        if ($new_alt_email !== '' && !filter_var($new_alt_email, FILTER_VALIDATE_EMAIL)) {
            flash_set('danger', 'Podaj poprawny adres e-mail kontaktowy.');
            header('Location: index.php?tab=formalnosci'); exit;
        }
        $new_share = isset($_POST['share_contact']) ? 1 : 0;
        db()->prepare("UPDATE users SET phone_number=?, alt_email=?, share_contact=? WHERE id=?")->execute([
            $new_phone, $new_alt_email ?: null, $new_share, $uid,
        ]);
        flash_set('success', 'Dane kontaktowe zostały zapisane.');
        header('Location: index.php?tab=formalnosci'); exit;
    }

    // ── Samoobsługowe konto ownCloud prowadzącego (zakładka „dysk") ─────────────
    if ($op === 'owncloud_create') {
        $r = owncloud_create_instructor_account($uid);
        if ($r['ok']) { $_SESSION['owncloud_reveal'] = $r; } else { flash_set('danger', $r['msg']); }
        header('Location: index.php?tab=dysk'); exit;
    }
    if ($op === 'owncloud_reset') {
        $r = owncloud_reset_instructor_password($uid);
        if ($r['ok']) { $_SESSION['owncloud_reveal'] = $r; } else { flash_set('danger', $r['msg']); }
        header('Location: index.php?tab=dysk'); exit;
    }
    // Usunięcie i ponowne założenie konta ownCloud — kasuje WSZYSTKIE pliki
    // prowadzącego na starym koncie. Ostrzeżenie pokazuje formularz (confirm()).
    if ($op === 'owncloud_recreate') {
        $r = owncloud_recreate_instructor_account($uid);
        if ($r['ok']) { $_SESSION['owncloud_reveal'] = $r; } else { flash_set('danger', $r['msg']); }
        header('Location: index.php?tab=dysk'); exit;
    }

    // ── WIADOMOŚCI — nie wymagają konkretnego course_id ─────────────────────────
    if ($op === 'dyd_msg_send') {
        $acc_id  = (int)($_POST['account_id'] ?? 0);
        $subject = trim($_POST['subject'] ?? '');
        $body    = trim($_POST['body'] ?? '');
        // Sprawdź, czy kursant jest zapisany do kursu prowadzącego
        $myAccId = $acc_id ? db_one(
            "SELECT a.id FROM k30_ti_student_accounts a
             JOIN k30_ti_enrollments e ON e.client_id=a.client_id
             WHERE a.id=? AND e.course_id IN (" . implode(',', array_map('intval', $course_ids ?: [0])) . ") AND e.status='active'
             LIMIT 1", [$acc_id]) : null;
        if (!$myAccId || $body === '') {
            flash_set('danger', $body === '' ? 'Treść wiadomości jest wymagana.' : 'Nie możesz pisać do tego kursanta.');
            header('Location: index.php?tab=wiadomosci'); exit;
        }
        $senderName = (string)($me['name'] ?? $me['username'] ?? 'Prowadzący');
        $_msg_id = ti_msg_post_to_student($acc_id, $subject, $body, $uid, $senderName, false);
        if (!empty($_FILES['attachments']['name'][0])) ti_msg_save_attachments($_msg_id, $_FILES['attachments']);
        flash_set('success', 'Wiadomość wysłana.');
        header('Location: index.php?tab=wiadomosci&student=' . $acc_id); exit;
    }

    if ($op === 'dyd_msg_reply') {
        $acc_id = (int)($_POST['student_id'] ?? 0);
        $body   = trim($_POST['body'] ?? '');
        $myAccId = $acc_id ? db_one(
            "SELECT a.id FROM k30_ti_student_accounts a
             JOIN k30_ti_enrollments e ON e.client_id=a.client_id
             WHERE a.id=? AND e.course_id IN (" . implode(',', array_map('intval', $course_ids ?: [0])) . ") AND e.status='active'
             LIMIT 1", [$acc_id]) : null;
        if ($myAccId && $body !== '') {
            $senderName = (string)($me['name'] ?? $me['username'] ?? 'Prowadzący');
            ti_msg_post_to_student($acc_id, '', $body, $uid, $senderName, true);
            ti_account_log($acc_id, 'msg_sent_by_staff', mb_substr($body, 0, 100), $uid, $senderName);
            flash_set('success', 'Odpowiedź wysłana.');
        }
        header('Location: index.php?tab=wiadomosci&student=' . $acc_id); exit;
    }

    if ($op === 'dyd_msg_block' || $op === 'dyd_msg_unblock') {
        $acc_id  = (int)($_POST['student_id'] ?? 0);
        $myAccId = $acc_id ? db_one(
            "SELECT a.id FROM k30_ti_student_accounts a
             JOIN k30_ti_enrollments e ON e.client_id=a.client_id
             WHERE a.id=? AND e.course_id IN (" . implode(',', array_map('intval', $course_ids ?: [0])) . ") AND e.status='active'
             LIMIT 1", [$acc_id]) : null;
        if ($myAccId) {
            $senderName = (string)($me['name'] ?? $me['username'] ?? 'Prowadzący');
            ti_msg_set_blocked($acc_id, $op === 'dyd_msg_block', $uid, $senderName);
            flash_set('success', $op === 'dyd_msg_block' ? 'Wiadomości od kursanta zablokowane.' : 'Blokada zdjęta.');
        }
        header('Location: index.php?tab=wiadomosci&student=' . $acc_id); exit;
    }

    if ($op === 'dyd_msg_archive') {
        $msg_id = (int)($_POST['msg_id'] ?? 0);
        $msgRow = $msg_id ? db_one(
            "SELECT m.id, m.student_id FROM k30_ti_messages m
             JOIN k30_ti_student_accounts a ON a.id=m.student_id
             JOIN k30_ti_enrollments e ON e.client_id=a.client_id
             WHERE m.id=? AND e.course_id IN (" . implode(',', array_map('intval', $course_ids ?: [0])) . ") AND e.status='active'
             LIMIT 1", [$msg_id]) : null;
        $acc_id = (int)($_POST['student_id'] ?? 0);
        if ($msgRow) {
            $senderName = (string)($me['name'] ?? $me['username'] ?? 'Prowadzący');
            ti_msg_archive((int)$msgRow['id'], $uid, $senderName);
            flash_set('success', 'Wiadomość zarchiwizowana.');
        }
        header('Location: index.php?tab=wiadomosci&student=' . $acc_id); exit;
    }

    if ($op === 'dyd_msg_admin_send') {
        $subject     = trim((string)($_POST['subject'] ?? ''));
        $body        = trim((string)($_POST['body'] ?? ''));
        $toAdminRaw  = (string)($_POST['to_admin_id'] ?? '0');
        $toAdminId   = ($toAdminRaw === '-1') ? -1 : (int)$toAdminRaw;
        if ($body !== '') {
            $senderName = (string)($me['name'] ?? $me['username'] ?? 'Prowadzący');
            $_msg_id = ti_admin_msg_send($uid, $senderName, $subject, $body, $toAdminId);
            if (!empty($_FILES['attachments']['name'][0])) ti_msg_save_attachments($_msg_id, $_FILES['attachments'], 'admin');
            flash_set('success', 'Wiadomość wysłana.');
        }
        header('Location: index.php?tab=wiadomosci&thread=admin&to_admin=' . $toAdminId); exit;
    }

    // "Nowa wiadomość" → adresat "Helpdesk IT": zamiast wątku wewnętrznego
    // zakłada zgłoszenie w module Helpdesk (patrz includes/helpdesk.php,
    // karty30/ti/dydaktyk/api_helpdesk.php — ten sam wzorzec API+fallback
    // co protokoły miesięczne).
    if ($op === 'dyd_msg_helpdesk_send') {
        require_once dirname(dirname(dirname(__DIR__))) . '/includes/helpdesk.php';
        $body_html = trim((string)($_POST['body'] ?? ''));
        // Helpdesk renderuje opis jako czysty tekst — edytor daje HTML, więc
        // zamieniamy na tekst z zachowaniem akapitów/łamania wierszy (ten sam
        // wzorzec co crm_offer_plain(), bez dociągania całego pliku CRM).
        $body = preg_replace('#<br\s*/?>#i', "\n", $body_html) ?? $body_html;
        $body = preg_replace('#</(p|div|li|h[1-6])>#i', "\n", $body) ?? $body;
        $body = trim(html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $subject = trim((string)($_POST['subject'] ?? ''));
        if ($subject === '') $subject = mb_strimwidth($body, 0, 60, '…');
        if ($body !== '') {
            $requester = ['id' => $uid, 'name' => (string)($me['name'] ?? ''), 'email' => (string)($me['email'] ?? '')];
            $api = hd_dyd_api_call('create', [
                'title' => $subject, 'description' => $body, 'category' => 'it_inne', 'priority' => 'normalny',
            ], 'POST');
            $ticket_id = $api['data']['ticket_id']
                ?? hd_ticket_quick_create($requester, $subject, $body, 'it_inne', 'normalny', 'dydaktyk');
            // Załączniki idą zawsze wprost na bazę — przesyłanie plików przez
            // wywołanie API (JSON) nie ma dziś sensu, to zwykły multipart POST.
            if (!empty($_FILES['attachments']['name'][0])) hd_save_attachments((int)$ticket_id, $_FILES['attachments'], $uid);
            flash_set('success', 'Zgłoszenie wysłane do Helpdesku IT.');
        }
        header('Location: index.php?tab=wiadomosci'); exit;
    }

    // Komunikaty placówki — nie wymagają course_id
    if ($op === 'mark_notice') {
        $nid = (int)($_POST['notice_id'] ?? 0);
        if ($nid) ti_notices_mark_read_instructor($nid, $uid);
        header('Location: index.php?tab=komunikaty'); exit;
    }
    if ($op === 'mark_all_notices') {
        ti_notices_mark_all_read_instructor($uid);
        flash_set('success', 'Wszystkie komunikaty oznaczone jako przeczytane.');
        // Po potwierdzeniu z pełnoekranowej bramki wracamy tam, gdzie użytkownik szedł
        $_bt = trim((string)($_POST['back_tab'] ?? ''));
        header('Location: index.php?tab=' . urlencode($_bt !== '' ? $_bt : 'komunikaty')); exit;
    }

    // Komunikaty placówki — CRUD kierownika (dyd_is_staff() zastępuje is_admin()
    // ze starego karty30/ti/notices.php — kierownik nie ma konta SZO).
    if (in_array($op, ['notice_add', 'notice_edit'], true) && dyd_is_staff()) {
        $nid = (int)($_POST['id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        if ($title === '') {
            flash_set('danger', 'Podaj tytuł komunikatu.');
            header('Location: index.php?tab=komunikaty'); exit;
        }
        $data = [
            'title'       => $title,
            'body'        => trim($_POST['body'] ?? ''),
            'audience'    => 'all',
            'is_pinned'   => !empty($_POST['is_pinned']) ? 1 : 0,
            'is_active'   => !empty($_POST['is_active']) ? 1 : 0,
            'expires_at'  => trim($_POST['expires_at'] ?? ''),
            'author_id'   => $uid,
            'author_name' => $me['name'] ?? '',
        ];
        if ($nid) {
            ti_notices_update($nid, $data);
            flash_set('success', 'Komunikat zaktualizowany.');
        } else {
            ti_notices_save($data);
            flash_set('success', 'Komunikat opublikowany.');
        }
        header('Location: index.php?tab=komunikaty'); exit;
    }
    if ($op === 'notice_delete' && dyd_is_staff()) {
        $nid = (int)($_POST['id'] ?? 0);
        if ($nid) {
            db()->prepare("DELETE FROM k30_ti_notice_reads WHERE notice_id=?")->execute([$nid]);
            db()->prepare("DELETE FROM k30_ti_notices WHERE id=?")->execute([$nid]);
            flash_set('success', 'Komunikat usunięty.');
        }
        header('Location: index.php?tab=komunikaty'); exit;
    }
    if ($op === 'notice_toggle_active' && dyd_is_staff()) {
        $nid = (int)($_POST['id'] ?? 0);
        $val = (int)($_POST['val'] ?? 0);
        if ($nid) db()->prepare("UPDATE k30_ti_notices SET is_active=?, updated_at=datetime('now') WHERE id=?")->execute([$val, $nid]);
        flash_set('success', $val ? 'Komunikat aktywowany.' : 'Komunikat dezaktywowany.');
        header('Location: index.php?tab=komunikaty'); exit;
    }
    if ($op === 'notice_toggle_pin' && dyd_is_staff()) {
        $nid = (int)($_POST['id'] ?? 0);
        $val = (int)($_POST['val'] ?? 0);
        if ($nid) db()->prepare("UPDATE k30_ti_notices SET is_pinned=?, updated_at=datetime('now') WHERE id=?")->execute([$val, $nid]);
        flash_set('success', $val ? 'Komunikat przypięty.' : 'Odepnięto komunikat.');
        header('Location: index.php?tab=komunikaty'); exit;
    }

    // Zbiorcze uzupełnienie zaległych lekcji z widżetu "Do zrobienia" — obejmuje
    // sesje z różnych kursów naraz, więc nie ma jednego course_id w POST; każdą
    // sesję autoryzujemy osobno (dyd_owns_session), stąd handler PRZED bramką
    // course_id niżej.
    if ($op === 'bulk_complete_overdue') {
        $ids  = array_unique(array_map('intval', (array)($_POST['session_ids'] ?? [])));
        $done = 0;
        foreach ($ids as $sid) {
            if ($sid && dyd_owns_session($uid, $sid) && !ti_period_closed_for_session($sid) && ti_session_bulk_mark_present($sid)) {
                ti_session_note_on_behalf($sid, $uid, (string)($me['name'] ?? ''));
                $done++;
            }
        }
        flash_set($done ? 'success' : 'warning', $done
            ? 'Uzupełniono ' . $done . ' ' . ($done === 1 ? 'zaległą lekcję' : 'zaległych lekcji') . ' — wszyscy obecni.'
            : 'Nie uzupełniono żadnej lekcji — sprawdź, czy nadal są zaplanowane.');
        header('Location: index.php?tab=pulpit'); exit;
    }

    // Pozostałe operacje wymagają własności kursu. Kierownik (staff) przechodzi
    // zawsze — jego operacje z zakładek (np. create_course, move_student) nie
    // niosą course_id w POST, a dyd_owns_course() dla course_id=0 zwraca false,
    // co ucinało tworzenie kursu komunikatem „Brak uprawnień do tego kursu”.
    if (!dyd_is_staff() && !dyd_owns_course($uid, $course_id)) { http_response_code(403); exit('Brak uprawnień do tego kursu.'); }

    // ── LEKCJE ────────────────────────────────────────────────────────────────
    if ($op === 'save_lesson') {
        $sid   = (int)($_POST['session_id'] ?? 0);
        $date  = trim($_POST['lesson_date'] ?? '');
        $tf    = trim($_POST['time_from'] ?? '');
        $tt    = trim($_POST['time_to'] ?? '');
        $topic = trim($_POST['topic'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        $hw    = !empty($_POST['has_homework']) ? 1 : 0;
        $spr   = !empty($_POST['self_prep_remote']) ? 1 : 0;
        $lm    = in_array($_POST['lesson_method'] ?? '', ['stacjonarna','zdalna_zoom','zdalna_inne'], true) ? $_POST['lesson_method'] : '';
        $dflag = in_array($_POST['date_flag'] ?? '', ['tentative','change_possible'], true) ? $_POST['date_flag'] : '';
        $meet_url = in_array($lm, ['zdalna_zoom','zdalna_inne'], true) ? trim($_POST['meeting_url'] ?? '') : '';
        $room_id = max(0, (int)($_POST['room_id'] ?? 0));
        // Rezerwacja: termin trzymany naprawdę (te same blokady Zoom/sali/dostępności
        // co zwykła lekcja — patrz sprawdzenia niżej), ale niewidoczny dla kursanta
        // i nieliczony do frekwencji/wypłat, dopóki ktoś jej nie potwierdzi.
        $is_reservation = !empty($_POST['is_reservation']);
        $is_draft       = !$is_reservation && !empty($_POST['is_draft']);
        // Rezerwacja „na PESEL" — blokada terminu dla beneficjenta PFRON jeszcze
        // bez zapisu w systemie (bez zakładania kursanta/k30_clients). Temat
        // dostaje etykietę PFRON-XXX (3 ostatnie cyfry), pełny PESEL do notatek.
        if ($is_reservation && trim($_POST['pfron_pesel'] ?? '') !== '') {
            $pfron_pesel = preg_replace('/\D/', '', trim($_POST['pfron_pesel']));
            if (!pesel_valid($pfron_pesel)) {
                flash_set('danger', 'Nieprawidłowy PESEL — sprawdź cyfry.');
                header('Location: ' . dyd_back($course_id, 'lekcje')); exit;
            }
            $topic = 'PFRON-' . substr($pfron_pesel, -3);
            $notes = preg_replace('/^PESEL:\s*\d{11}\s*\|\s*/', '', $notes); // bez duplikatu przy edycji
            $notes = trim('PESEL: ' . $pfron_pesel . ($notes !== '' ? " | {$notes}" : ''));
        }
        $dur   = 60;
        if ($tf && $tt) {
            $m = (strtotime('1970-01-01 ' . $tt) - strtotime('1970-01-01 ' . $tf)) / 60;
            if ($m > 0) $dur = (int)$m;
        }
        if ($date === '') { flash_set('danger', 'Data lekcji jest wymagana.'); header('Location: ' . dyd_back($course_id, 'lekcje')); exit; }

        // Prowadzący TEJ lekcji — tylko kierownik może wskazać zastępstwo (inaczej
        // dziedziczy z kursu). Wpływa też na sprawdzenie dostępności i na wypłatę
        // (k30_ti_payouts_by_instructor via COALESCE(instructor_id, kurs)).
        $sess_instr = dyd_is_staff() ? max(0, (int)($_POST['instructor_id'] ?? 0)) : 0;
        $eff_instr  = $sess_instr ?: ti_course_instructor_id($course_id);

        // Zajęcia tylko w dostępności prowadzącego (gdy zdefiniowana) — kierownik
        // może to świadomie ominąć (np. pilne zastępstwo poza zwykłymi godzinami)
        $skip_avail = dyd_is_staff() && !empty($_POST['skip_availability']);
        if (!$skip_avail) {
            $av = ti_instructor_available_at($eff_instr, $date, $tf, $tt);
            if (!$av['ok']) { flash_set('danger', $av['reason']); header('Location: ' . dyd_back($course_id, 'lekcje')); exit; }
        }

        // Zamknięty okres nauczania — rozliczony protokołami, nic już w nim nie ruszamy
        if ($_pc = ti_period_closed_for_date($date)) {
            flash_set('danger', ti_period_closed_msg($_pc));
            header('Location: ' . dyd_back($course_id, 'lekcje')); exit;
        }

        // Zajętość konta Zoom (jeden host = jedno spotkanie naraz) — twarda blokada
        $zc = ti_zoom_slot_check($course_id, $lm, $date, $tf, $tt, $sid);
        if (!$zc['ok']) { flash_set('danger', $zc['reason'] . ZOOM_BUSY_HINT); header('Location: ' . dyd_back($course_id, 'lekcje')); exit; }
        $zw = $zc['warning'] !== '' ? ' ' . $zc['warning'] : '';

        // Sala zajęta w tym oknie czasowym — twarda blokada (patrz pl_check_conflicts).
        if ($room_id) {
            $rc = pl_check_conflicts(['lesson_date' => $date, 'time_from' => $tf, 'time_to' => $tt, 'room_id' => $room_id, 'skip_id' => $sid]);
            if ($rc['hard']) { flash_set('danger', $rc['hard'][0]['msg'] ?? 'Sala zajęta w tym terminie.'); header('Location: ' . dyd_back($course_id, 'lekcje')); exit; }
        }

        if ($sid && dyd_owns_session($uid, $sid)) {
            // Status lekcji zmienia ręcznie tylko administrator — pozostali zachowują
            // bieżący (odbyta/zaplanowana wynika z zapisu obecności, nie z edycji).
            $_cur_st = (string)(db_one("SELECT status FROM k30_ti_sessions WHERE id=?", [$sid])['status'] ?? 'planned');
            $st      = $is_reservation ? 'reserved'
                     : ($is_draft ? 'draft'
                     : (!dyd_is_admin() ? (in_array($_cur_st, ['reserved','draft'], true) ? 'planned' : $_cur_st)
                     : (in_array($_POST['status'] ?? '', ['planned','held','individual_change','remote_material'], true) ? $_POST['status'] : 'planned')));
            $mat_url = trim($_POST['material_url'] ?? '');
            db()->prepare(
                "UPDATE k30_ti_sessions
                 SET lesson_date=?, time_from=?, time_to=?, duration_min=?, topic=?, notes=?, has_homework=?, self_prep_remote=?, status=?, material_url=?, lesson_method=?, meeting_url=?, room_id=?, date_flag=?, updated_at=datetime('now')
                 WHERE id=?"
            )->execute([$date, $tf, $tt, $dur, $topic, $notes, $hw, $spr, $st, $mat_url, $lm, $meet_url, $room_id ?: null, $dflag, $sid]);
            // Prowadzący edytowalny tylko przez kierownika — nie dotykamy pola,
            // gdy edytuje zwykły prowadzący (formularz mu go nawet nie pokazuje).
            if (dyd_is_staff()) {
                db()->prepare("UPDATE k30_ti_sessions SET instructor_id=? WHERE id=?")->execute([$sess_instr ?: null, $sid]);
            }
            k30_ti_session_set_curriculum($sid, (array)($_POST['curriculum_ids'] ?? []));
            if ($st === 'remote_material') {
                // Praca własna prowadzącego = wszyscy obecni bez ręcznego sprawdzania
                db()->prepare("UPDATE k30_ti_attendance SET attended=1 WHERE session_id=? AND COALESCE(cancelled,0)=0 AND COALESCE(no_show,0)=0")->execute([$sid]);
            }
            flash_set('success', ($is_reservation ? 'Rezerwacja zaktualizowana.' : ($is_draft ? 'Wersja robocza zaktualizowana.' : 'Lekcja zaktualizowana.')) . $zw);
        } else {
            $sid = db_insert('k30_ti_sessions', [
                'course_id'       => $course_id, 'lesson_date' => $date,
                'time_from'       => $tf, 'time_to' => $tt, 'duration_min' => $dur,
                'status'          => $is_reservation ? 'reserved' : ($is_draft ? 'draft' : 'planned'), 'topic' => $topic, 'notes' => $notes,
                'has_homework'    => $hw, 'self_prep_remote' => $spr,
                'lesson_method'   => $lm, 'meeting_url' => $meet_url, 'instructor_id' => $sess_instr ?: null,
                'room_id'         => $room_id ?: null, 'date_flag' => $dflag,
                'created_by'      => $uid, 'created_at' => date('Y-m-d H:i:s'),
            ]);
            k30_ti_session_set_curriculum($sid, (array)($_POST['curriculum_ids'] ?? []));
            // Wstępna obecność dla aktywnych uczestników
            foreach (db_all("SELECT client_id FROM k30_ti_enrollments WHERE course_id=? AND status='active'", [$course_id]) as $e) {
                try { db_insert('k30_ti_attendance', ['session_id'=>$sid, 'client_id'=>(int)$e['client_id'], 'attended'=>0]); }
                catch (\Throwable $ex) {}
            }
            if ($is_reservation) {
                flash_set('success', 'Rezerwacja terminu utworzona — termin jest zablokowany, ale kursanci jej nie widzą, dopóki nie zostanie potwierdzona.' . $zw);
            } elseif ($is_draft) {
                flash_set('success', 'Wersja robocza zapisana — niewidoczna dla kursantów, nie liczy się do frekwencji ani rozliczeń.' . $zw);
            } else {
                $msg = 'Lekcja dodana.' . $zw;
                if (isset($_POST['notify']) && !$spr) {
                    $cn = db_one("SELECT name FROM k30_ti_courses WHERE id=?", [$course_id]);
                    $when = $date . ($tf !== '' ? ' o ' . $tf : '');
                    $n = ti_lesson_sms_notify($course_id, 'Nowe zajecia: ' . ($cn['name'] ?? '') . ' — ' . $when . '. Szczegoly w panelu kursanta.');
                    if ($n) $msg .= " Wysłano SMS: {$n}.";
                }
                flash_set('success', $msg);
            }
        }
        header('Location: ' . dyd_back($course_id, 'lekcje')); exit;
    }

    // Potwierdzenie rezerwacji — zamienia ją w zwykłą, zaplanowaną lekcję (widoczną
    // dla kursanta). Termin był zablokowany od chwili rezerwacji, więc tu już nie
    // trzeba ponownie sprawdzać konfliktów.
    if ($op === 'confirm_reservation') {
        $sid = (int)($_POST['session_id'] ?? 0);
        if (dyd_owns_session($uid, $sid)) {
            $r = db()->prepare("UPDATE k30_ti_sessions SET status='planned', updated_at=datetime('now') WHERE id=? AND status='reserved'");
            $r->execute([$sid]);
            flash_set($r->rowCount() ? 'success' : 'danger', $r->rowCount() ? 'Rezerwacja potwierdzona — lekcja jest teraz widoczna dla kursantów.' : 'Ta rezerwacja już nie istnieje albo została wcześniej potwierdzona.');
        } else {
            flash_set('danger', 'Brak uprawnień do tej lekcji.');
        }
        header('Location: ' . dyd_back($course_id, 'lekcje')); exit;
    }

    if ($op === 'delete_lesson') {
        $sid = (int)($_POST['session_id'] ?? 0);
        if (dyd_owns_session($uid, $sid)) {
            db()->prepare("DELETE FROM k30_ti_sessions WHERE id=?")->execute([$sid]);
            flash_set('success', 'Lekcja usunięta.');
        }
        header('Location: ' . dyd_back($course_id, 'lekcje')); exit;
    }

    // Ręczna flaga "dokumentacja uzupełniona" — widoczna i przełączalna zarówno
    // przez kierownika (staff), jak i prowadzącego kursu (ten sam warunek co edycja lekcji).
    if ($op === 'toggle_docs_complete') {
        $sid = (int)($_POST['session_id'] ?? 0);
        if (dyd_is_staff() && dyd_owns_session($uid, $sid)) {
            db()->prepare("UPDATE k30_ti_sessions SET docs_complete = 1 - COALESCE(docs_complete,0) WHERE id=?")->execute([$sid]);
        }
        // Zachowaj otwartą kartę lekcji (?lesson=), gdy przełącznik wywołano stamtąd.
        $back_lesson = (int)($_POST['lesson'] ?? 0);
        header('Location: ' . dyd_back($course_id, 'lekcje') . ($back_lesson ? '&lesson=' . $back_lesson : '')); exit;
    }

    if ($op === 'sms_week_group') {
        require_once dirname(dirname(dirname(__DIR__))) . '/includes/sms.php';
        if (!sms_channel_ready()) {
            flash_set('danger', 'SMS jest wyłączony. Skonfiguruj w Administracja → Ustawienia SMS.');
            header('Location: ' . dyd_back($course_id, 'lekcje')); exit;
        }
        $mon = date('Y-m-d', strtotime('monday this week'));
        $sun = date('Y-m-d', strtotime('sunday this week'));
        $week_sessions = db_all(
            "SELECT s.lesson_date, s.time_from, s.time_to, c.name AS course_name
             FROM k30_ti_sessions s
             JOIN k30_ti_courses c ON c.id = s.course_id
             WHERE s.course_id = ? AND s.lesson_date BETWEEN ? AND ?
             ORDER BY s.lesson_date, s.time_from",
            [$course_id, $mon, $sun]
        );
        if (empty($week_sessions)) {
            flash_set('info', 'Brak lekcji w bieżącym tygodniu dla tego kursu.');
            header('Location: ' . dyd_back($course_id, 'lekcje')); exit;
        }
        $sms_text = ti_build_week_sms($week_sessions);
        $n = ti_lesson_sms_notify($course_id, $sms_text);
        flash_set(
            $n > 0 ? 'success' : 'info',
            $n > 0
                ? "SMS z planem tygodnia wysłany do {$n} " . ($n === 1 ? 'kursanta.' : 'kursantów.')
                : 'Żaden kursant nie ma włączonych powiadomień SMS.'
        );
        header('Location: ' . dyd_back($course_id, 'lekcje')); exit;
    }

    // Seria lekcji — powtarzalne co N tygodni
    if ($op === 'save_lesson_series') {
        $date  = trim($_POST['lesson_date'] ?? '');
        $tf    = trim($_POST['time_from'] ?? '');
        $tt    = trim($_POST['time_to'] ?? '');
        $topic = trim($_POST['topic'] ?? '');
        $ser_lm       = in_array($_POST['lesson_method'] ?? '', ['stacjonarna','zdalna_zoom','zdalna_inne'], true) ? $_POST['lesson_method'] : '';
        $ser_meet_url = in_array($ser_lm, ['zdalna_zoom','zdalna_inne'], true) ? trim($_POST['meeting_url'] ?? '') : '';
        $ser_room_id  = max(0, (int)($_POST['room_id'] ?? 0));
        $ser_dflag    = in_array($_POST['date_flag'] ?? '', ['tentative','change_possible'], true) ? $_POST['date_flag'] : '';
        $ser_reservation = !empty($_POST['is_reservation']);
        $ser_draft       = !$ser_reservation && !empty($_POST['is_draft']);
        $ser_notes = '';
        if ($ser_reservation && trim($_POST['pfron_pesel'] ?? '') !== '') {
            $ser_pesel = preg_replace('/\D/', '', trim($_POST['pfron_pesel']));
            if (!pesel_valid($ser_pesel)) {
                flash_set('danger', 'Nieprawidłowy PESEL — sprawdź cyfry.'); header('Location: ' . dyd_back($course_id, 'lekcje')); exit;
            }
            $topic = 'PFRON-' . substr($ser_pesel, -3);
            $ser_notes = 'PESEL: ' . $ser_pesel;
        }
        $ser_status = $ser_reservation ? 'reserved' : ($ser_draft ? 'draft' : 'planned');
        if ($date === '' || !DateTime::createFromFormat('Y-m-d', $date)) {
            flash_set('danger', 'Podaj poprawną datę startową serii.'); header('Location: ' . dyd_back($course_id, 'lekcje')); exit;
        }
        $dur = 60;
        if ($tf && $tt) { $m = (strtotime('1970-01-01 ' . $tt) - strtotime('1970-01-01 ' . $tf)) / 60; if ($m > 0) $dur = (int)$m; }
        // Wzorzec: co N tygodni (domyślnie), albo N-ty/ostatni dzień tygodnia miesiąca.
        // Koniec: po liczbie lekcji (domyślnie), do wskazanej daty (włącznie), albo do
        // osiągnięcia zadanej liczby godzin (przeliczane na liczbę lekcji tej długości —
        // ti_recurrence_dates zna tylko count/until, „hours" to tylko sposób policzenia count).
        $ser_end_mode = in_array($_POST['end_mode'] ?? '', ['until', 'hours'], true) ? $_POST['end_mode'] : 'count';
        $ser_until    = trim($_POST['until'] ?? '');
        $ser_count    = max(1, min(104, (int)($_POST['count'] ?? 1)));
        if ($ser_end_mode === 'until' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ser_until) || $ser_until < $date)) {
            flash_set('danger', 'Podaj poprawną datę końcową (nie wcześniejszą niż data startowa).');
            header('Location: ' . dyd_back($course_id, 'lekcje')); exit;
        }
        $ser_target_hours = null;
        if ($ser_end_mode === 'hours') {
            $ser_target_hours = max(0.5, (float)str_replace(',', '.', (string)($_POST['target_hours'] ?? '0')));
            $ser_count = max(1, min(104, (int)ceil($ser_target_hours * 60 / $dur)));
        }
        $ser_dates = ti_recurrence_dates([
            'mode'     => ($_POST['recur_mode'] ?? '') === 'monthly' ? 'monthly' : 'weekly',
            'start'    => $date,
            'every'    => max(1, min(8, (int)($_POST['weeks'] ?? 1))),
            'dow'      => max(0, min(6, (int)($_POST['recur_dow'] ?? 1))),
            'position' => (string)($_POST['recur_position'] ?? '1'),
            'end_mode' => $ser_end_mode === 'until' ? 'until' : 'count',
            'count'    => $ser_count,
            'until'    => $ser_until,
        ]);
        if (!$ser_dates) {
            flash_set('danger', 'Wzorzec nie wygenerował żadnego terminu — sprawdź datę startową i warunek zakończenia.');
            header('Location: ' . dyd_back($course_id, 'lekcje')); exit;
        }
        // Prowadzący CAŁEJ serii — tylko kierownik może wskazać zastępstwo (patrz save_lesson).
        $sess_instr = dyd_is_staff() ? max(0, (int)($_POST['instructor_id'] ?? 0)) : 0;
        $eff_instr  = $sess_instr ?: ti_course_instructor_id($course_id);
        // Cała seria ma tę samą godzinę — dostępność sprawdzamy na pierwszym wystąpieniu,
        // chyba że kierownik świadomie ją pomija (np. pilne zastępstwo poza zwykłymi godzinami)
        $ser_skip_avail = dyd_is_staff() && !empty($_POST['skip_availability']);
        if (!$ser_skip_avail) {
            $av = ti_instructor_available_at($eff_instr, $ser_dates[0], $tf, $tt);
            if (!$av['ok']) { flash_set('danger', $av['reason'] . ' Seria nie została utworzona.'); header('Location: ' . dyd_back($course_id, 'lekcje')); exit; }
        }
        // Zajętość konta Zoom — sprawdzana per termin (różne dni, ten sam host)
        foreach ($ser_dates as $_d) {
            if ($_pc = ti_period_closed_for_date($_d)) {
                flash_set('danger', ti_period_closed_msg($_pc) . ' Seria nie została utworzona.');
                header('Location: ' . dyd_back($course_id, 'lekcje')); exit;
            }
        }
        $zs = ti_zoom_dates_check($course_id, $ser_lm, $ser_dates, $tf, $tt);
        if (!$zs['ok']) {
            flash_set('danger', ti_zoom_conflicts_msg($zs['conflicts']) . ' Seria nie została utworzona.' . ZOOM_BUSY_HINT);
            header('Location: ' . dyd_back($course_id, 'lekcje')); exit;
        }
        $zw = $zs['warning'] !== '' ? ' ' . $zs['warning'] : '';
        if ($ser_room_id) {
            foreach ($ser_dates as $_d) {
                $rc = pl_check_conflicts(['lesson_date' => $_d, 'time_from' => $tf, 'time_to' => $tt, 'room_id' => $ser_room_id]);
                if ($rc['hard']) {
                    flash_set('danger', ($rc['hard'][0]['msg'] ?? 'Sala zajęta.') . " ({$_d}) Seria nie została utworzona.");
                    header('Location: ' . dyd_back($course_id, 'lekcje')); exit;
                }
            }
        }
        $enrollees = db_all("SELECT client_id FROM k30_ti_enrollments WHERE course_id=? AND status='active'", [$course_id]);
        $created = 0;
        foreach ($ser_dates as $d) {
            $sid = db_insert('k30_ti_sessions', [
                'course_id' => $course_id, 'lesson_date' => $d, 'time_from' => $tf, 'time_to' => $tt,
                'duration_min' => $dur, 'status' => $ser_status, 'topic' => $topic, 'notes' => $ser_notes,
                'lesson_method' => $ser_lm, 'meeting_url' => $ser_meet_url, 'instructor_id' => $sess_instr ?: null,
                'room_id' => $ser_room_id ?: null, 'date_flag' => $ser_dflag,
                'created_by' => $uid, 'created_at' => date('Y-m-d H:i:s'),
            ]);
            foreach ($enrollees as $e) {
                try { db_insert('k30_ti_attendance', ['session_id' => $sid, 'client_id' => (int)$e['client_id'], 'attended' => 0]); }
                catch (\Throwable $ex) {}
            }
            $created++;
        }
        $ser_kind = $ser_reservation ? ' (rezerwacja terminu)' : ($ser_draft ? ' (wersja robocza)' : '');
        $ser_pattern_label = ($_POST['recur_mode'] ?? '') === 'monthly'
            ? 'wzorzec miesięczny'
            : ('co ' . max(1, min(8, (int)($_POST['weeks'] ?? 1))) . ' tyg.');
        $ser_hours_note = $ser_target_hours !== null
            ? ' — łącznie ' . number_format($created * $dur / 60, 1, ',', '') . ' godz. (cel: ' . number_format($ser_target_hours, 1, ',', '') . ')'
            : '';
        flash_set('success', "Utworzono serię: {$created} lekcji ({$ser_pattern_label}){$ser_kind}{$ser_hours_note}." . $zw);
        header('Location: ' . dyd_back($course_id, 'lekcje')); exit;
    }

    // Zajęcia stałe — nowa reguła cykliczna (z zapisem wzorca)
    if ($op === 'save_recurring_rule') {
        dyd_token_check();
        $date_from  = trim($_POST['date_from'] ?? '');
        $date_to    = trim($_POST['date_to'] ?? '');
        $tf         = trim($_POST['time_from'] ?? '');
        $tt         = trim($_POST['time_to'] ?? '');
        $topic      = trim($_POST['topic'] ?? '');
        $rec_mode   = ($_POST['recur_mode'] ?? '') === 'monthly' ? 'monthly' : 'weekly';
        $every      = max(1, min(8, (int)($_POST['interval_weeks'] ?? 1)));
        $rec_dow    = max(0, min(6, (int)($_POST['recur_dow'] ?? 1)));
        $rec_pos    = in_array((string)($_POST['recur_position'] ?? '1'), ['1','2','3','4','last'], true) ? (string)$_POST['recur_position'] : '1';
        $rec_room_id = max(0, (int)($_POST['room_id'] ?? 0));
        $rec_draft   = !empty($_POST['is_draft']);
        $rec_status  = $rec_draft ? 'draft' : 'planned';
        if (!$date_from || !$date_to || $date_to < $date_from) {
            flash_set('danger', 'Podaj poprawny zakres dat.');
            header('Location: ' . dyd_back($course_id, 'lekcje')); exit;
        }
        $dur = 60;
        if ($tf && $tt) { $m = (strtotime('1970-01-01 '.$tt) - strtotime('1970-01-01 '.$tf)) / 60; if ($m > 0) $dur = (int)$m; }
        // Terminy reguły — potrzebne przed zapisem, żeby sprawdzić zajętość Zoom
        $rule_dates = ti_recurrence_dates([
            'mode' => $rec_mode, 'start' => $date_from, 'every' => $every,
            'dow' => $rec_dow, 'position' => $rec_pos,
            'end_mode' => 'until', 'until' => $date_to,
        ]);
        if (!$rule_dates) {
            flash_set('danger', 'Wzorzec nie wygenerował żadnego terminu w podanym zakresie dat.');
            header('Location: ' . dyd_back($course_id, 'lekcje')); exit;
        }
        foreach ($rule_dates as $_d) {
            if ($_pc = ti_period_closed_for_date($_d)) {
                flash_set('danger', ti_period_closed_msg($_pc) . ' Zajęcia stałe nie zostały dodane.');
                header('Location: ' . dyd_back($course_id, 'lekcje')); exit;
            }
        }
        // Zajęcia stałe nie mają wybranej metody — Zoom obciążają, gdy kurs ma stały link
        $zs = ti_zoom_dates_check($course_id, '', $rule_dates, $tf, $tt);
        if (!$zs['ok']) {
            flash_set('danger', ti_zoom_conflicts_msg($zs['conflicts']) . ' Zajęcia stałe nie zostały dodane.' . ZOOM_BUSY_HINT);
            header('Location: ' . dyd_back($course_id, 'lekcje')); exit;
        }
        $zw = $zs['warning'] !== '' ? ' ' . $zs['warning'] : '';
        if ($rec_room_id) {
            foreach ($rule_dates as $_d) {
                $rc = pl_check_conflicts(['lesson_date' => $_d, 'time_from' => $tf, 'time_to' => $tt, 'room_id' => $rec_room_id]);
                if ($rc['hard']) {
                    flash_set('danger', ($rc['hard'][0]['msg'] ?? 'Sala zajęta.') . " ({$_d}) Zajęcia stałe nie zostały dodane.");
                    header('Location: ' . dyd_back($course_id, 'lekcje')); exit;
                }
            }
        }
        $rule_id = db_insert('k30_ti_series', [
            'course_id'      => $course_id,
            'time_from'      => $tf,
            'time_to'        => $tt,
            'interval_weeks' => $every,
            'recur_mode'     => $rec_mode,
            'recur_dow'      => $rec_mode === 'monthly' ? $rec_dow : null,
            'recur_position' => $rec_mode === 'monthly' ? $rec_pos : '',
            'date_from'      => $date_from,
            'date_to'        => $date_to,
            'topic'          => $topic,
            'room_id'        => $rec_room_id ?: null,
            'created_by'     => $uid,
            'created_at'     => date('Y-m-d H:i:s'),
        ]);
        $enrollees = db_all("SELECT client_id FROM k30_ti_enrollments WHERE course_id=? AND status='active'", [$course_id]);
        $created = 0;
        foreach ($rule_dates as $d) {
            $sid = db_insert('k30_ti_sessions', [
                'course_id' => $course_id, 'lesson_date' => $d, 'time_from' => $tf, 'time_to' => $tt,
                'duration_min' => $dur, 'status' => $rec_status, 'topic' => $topic, 'notes' => '',
                'room_id' => $rec_room_id ?: null,
                'created_by' => $uid, 'created_at' => date('Y-m-d H:i:s'), 'series_id' => $rule_id,
            ]);
            foreach ($enrollees as $e) {
                try { db_insert('k30_ti_attendance', ['session_id' => $sid, 'client_id' => (int)$e['client_id'], 'attended' => 0]); }
                catch (\Throwable $ex) {}
            }
            $created++;
        }
        $rec_pattern_label = $rec_mode === 'monthly' ? 'wzorzec miesięczny' : "co {$every} tyg.";
        $rec_kind = $rec_draft ? ' (wersja robocza)' : '';
        flash_set('success', "Zajęcia stałe dodane: {$created} lekcji ({$rec_pattern_label}){$rec_kind}." . $zw);
        header('Location: ' . dyd_back($course_id, 'lekcje')); exit;
    }

    // Usunięcie reguły zajęć stałych
    if ($op === 'delete_recurring_rule') {
        dyd_token_check();
        $rule_id = (int)($_POST['rule_id'] ?? 0);
        $rule = db_one("SELECT id FROM k30_ti_series WHERE id=? AND course_id=?", [$rule_id, $course_id]);
        if ($rule) {
            if (!empty($_POST['del_future'])) {
                db_exec("DELETE FROM k30_ti_sessions WHERE series_id=? AND lesson_date >= date('now') AND status='planned'", [$rule_id]);
                db_exec("DELETE FROM k30_ti_series WHERE id=?", [$rule_id]);
                flash_set('success', 'Usunięto reguły zajęć stałych i nadchodzące lekcje.');
            } else {
                db_exec("UPDATE k30_ti_sessions SET series_id=NULL WHERE series_id=?", [$rule_id]);
                db_exec("DELETE FROM k30_ti_series WHERE id=?", [$rule_id]);
                flash_set('success', 'Usunięto reguły zajęć stałych (istniejące lekcje zachowane).');
            }
        }
        header('Location: ' . dyd_back($course_id, 'lekcje')); exit;
    }

    // Masowe czyszczenie terminów grupy — usuwa zaplanowane (jeszcze nieodbyte)
    // lekcje naraz, np. przed regeneracją harmonogramu, opcjonalnie razem z
    // odwołanymi. Zajęcia ODBYTE (held, individual_change, remote_material)
    // i ich obecności/rozliczenia NIGDY nie są ruszane.
    if ($op === 'clear_group_sessions') {
        dyd_token_check();
        $scope = in_array($_POST['scope'] ?? '', ['future', 'all', 'day', 'weekday'], true) ? $_POST['scope'] : 'future';
        $clear_cancelled = !empty($_POST['clear_cancelled']);
        $statuses = $clear_cancelled ? "('planned','cancelled')" : "('planned')";
        $params = [$course_id];
        $where  = "course_id=? AND status IN $statuses";
        $detail_scope = '';
        if ($scope === 'future') {
            $where .= " AND lesson_date >= date('now')";
        } elseif ($scope === 'day') {
            $clear_date = trim($_POST['clear_date'] ?? '');
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $clear_date)) {
                flash_set('danger', 'Wybierz poprawną datę dnia do wyczyszczenia.');
                header('Location: ' . dyd_back($course_id, 'lekcje')); exit;
            }
            $where .= " AND lesson_date = ?";
            $params[] = $clear_date;
            $detail_scope = ' z dnia ' . $clear_date;
        } elseif ($scope === 'weekday') {
            $clear_weekday = (int)($_POST['clear_weekday'] ?? -1);
            if ($clear_weekday < 0 || $clear_weekday > 6) {
                flash_set('danger', 'Wybierz poprawny dzień tygodnia.');
                header('Location: ' . dyd_back($course_id, 'lekcje')); exit;
            }
            $where .= " AND lesson_date >= date('now') AND CAST(strftime('%w', lesson_date) AS INTEGER) = CAST(? AS INTEGER)";
            $params[] = $clear_weekday;
            $detail_scope = ' — ' . mb_strtolower(K30_TI_DAYS[$clear_weekday]) . ' (nadchodzące)';
        } else {
            $detail_scope = ' (w tym zaległe)';
        }
        $cnt = (int)(db_one("SELECT COUNT(*) AS n FROM k30_ti_sessions WHERE $where", $params)['n'] ?? 0);
        if ($cnt > 0) {
            db_exec("DELETE FROM k30_ti_sessions WHERE $where", $params);
            $detail = 'Usunięto ' . $cnt . ' terminów' . $detail_scope
                    . ($clear_cancelled ? ', w tym odwołane' : '') . '.';
            ti_course_log($course_id, 'clear_sessions', $detail, $uid, (string)($me['name'] ?? ''));
            flash_set('success', "Wyczyszczono terminy grupy: usunięto {$cnt} terminów.");
        } else {
            flash_set('info', 'Brak terminów do usunięcia.');
        }
        header('Location: ' . dyd_back($course_id, 'lekcje')); exit;
    }

    // ── Kreator: obecność + temat/notatki w jednym kroku ─────────────────────
    if ($op === 'wizard_save') {
        $sid   = (int)($_POST['session_id'] ?? 0);
        $back  = 'index.php?course=' . $course_id . '&tab=pulpit';
        if ($sid && dyd_owns_session($uid, $sid)) {
            $_sess_date = (string)(db_one("SELECT lesson_date FROM k30_ti_sessions WHERE id=?", [$sid])['lesson_date'] ?? '');
            if ($_sess_date > date('Y-m-d')) {
                flash_set('danger', 'Nie można oznaczyć jako odbytej lekcji z przyszłości.');
                header('Location: ' . $back); exit;
            }
            // Krok 1: obecność
            $att = array_map('intval', (array)($_POST['attended'] ?? []));
            k30_ti_save_attendance($sid, $att);
            $any_absent = !empty(array_filter(
                db_all("SELECT attended FROM k30_ti_attendance WHERE session_id=? AND COALESCE(cancelled,0)=0", [$sid]),
                fn($r) => !$r['attended']
            ));
            $_s_course   = db_one("SELECT course_id FROM k30_ti_sessions WHERE id=?", [$sid]);
            $_cid        = (int)($_s_course['course_id'] ?? 0);
            $_course_row = db_one("SELECT is_subgroup FROM k30_ti_courses WHERE id=?", [$_cid]);
            $_is_sub     = !empty($_course_row['is_subgroup']);
            $_enrolled   = (int)(db_one("SELECT COUNT(*) AS n FROM k30_ti_enrollments WHERE course_id=? AND status='active'", [$_cid])['n'] ?? 0);
            $new_st = ($_is_sub || $any_absent || $_enrolled <= 1) ? 'individual_change' : 'held';
            db()->prepare("UPDATE k30_ti_sessions SET status=?, updated_at=datetime('now') WHERE id=? AND status='planned'")->execute([$new_st, $sid]);
            foreach (db_all("SELECT client_id FROM k30_ti_enrollments WHERE course_id=? AND status='active'", [$_cid]) as $er) {
                try { k30_ti_check_low_attendance($_cid, (int)$er['client_id']); } catch (\Throwable $ex) {}
            }
            // Krok 2: temat i notatki (opcjonalne)
            $topic = trim($_POST['topic'] ?? '');
            $notes = trim($_POST['notes'] ?? '');
            if ($topic !== '' || $notes !== '') {
                db()->prepare("UPDATE k30_ti_sessions SET topic=?, notes=?, updated_at=datetime('now') WHERE id=?")
                    ->execute([$topic, $notes, $sid]);
            }
            ti_session_note_on_behalf($sid, $uid, (string)($me['name'] ?? ''));
            flash_set('success', 'Zajęcia uzupełnione — obecność i temat zapisane.');
        }
        header('Location: ' . $back); exit;
    }

    if ($op === 'save_attendance') {
        $sid = (int)($_POST['session_id'] ?? 0);
        if (dyd_owns_session($uid, $sid)) {
            $_sess_row2 = db_one("SELECT lesson_date, status FROM k30_ti_sessions WHERE id=?", [$sid]);
            $_sess_date2 = (string)($_sess_row2['lesson_date'] ?? '');
            if ($_sess_date2 > date('Y-m-d')) {
                flash_set('danger', 'Nie można oznaczyć jako odbytej lekcji z przyszłości.');
                header('Location: ' . dyd_back($course_id, 'lekcje')); exit;
            }
            if (($_sess_row2['status'] ?? '') === 'remote_material') {
                // Praca własna prowadzącego — wszyscy automatycznie obecni
                db()->prepare("UPDATE k30_ti_attendance SET attended=1 WHERE session_id=? AND COALESCE(cancelled,0)=0 AND COALESCE(no_show,0)=0")->execute([$sid]);
                ti_session_note_on_behalf($sid, $uid, (string)($me['name'] ?? ''));
                flash_set('info', 'Praca prowadzącego — wszyscy kursanci oznaczeni jako obecni.');
                header('Location: ' . dyd_back($course_id, 'lekcje')); exit;
            }
            $att = array_map('intval', (array)($_POST['attended'] ?? []));
            k30_ti_save_attendance($sid, $att);
            // Sprawdzenie obecności oznacza, że lekcja się odbyła (gdy była zaplanowana).
            // Zmiana indywidualna gdy: podgrupa LUB ≥1 nieobecny LUB kurs jednosobowy.
            $any_absent = !empty(array_filter(
                db_all("SELECT attended FROM k30_ti_attendance WHERE session_id=? AND COALESCE(cancelled,0)=0", [$sid]),
                fn($r) => !$r['attended']
            ));
            $_s_course   = db_one("SELECT course_id FROM k30_ti_sessions WHERE id=?", [$sid]);
            $_cid        = (int)($_s_course['course_id'] ?? 0);
            $_course_row = db_one("SELECT is_subgroup FROM k30_ti_courses WHERE id=?", [$_cid]);
            $_is_sub     = !empty($_course_row['is_subgroup']);
            $_enrolled   = (int)(db_one("SELECT COUNT(*) AS n FROM k30_ti_enrollments WHERE course_id=? AND status='active'", [$_cid])['n'] ?? 0);
            $new_st = ($_is_sub || $any_absent || $_enrolled <= 1) ? 'individual_change' : 'held';
            db()->prepare("UPDATE k30_ti_sessions SET status=?, updated_at=datetime('now') WHERE id=? AND status='planned'")->execute([$new_st, $sid]);
            // Alert niskiej frekwencji — sprawdź wszystkich aktywnych kursantów kursu
            foreach (db_all("SELECT client_id FROM k30_ti_enrollments WHERE course_id=? AND status='active'", [$_cid]) as $er) {
                try { k30_ti_check_low_attendance($_cid, (int)$er['client_id']); } catch (\Throwable $ex) {}
            }
            ti_session_note_on_behalf($sid, $uid, (string)($me['name'] ?? ''));
            flash_set('success', 'Obecność zapisana.');
        }
        header('Location: ' . dyd_back($course_id, 'lekcje')); exit;
    }

    // Potwierdzenie / odrzucenie prośby kursanta o odwołanie udziału w lekcji
    if ($op === 'confirm_cancel' || $op === 'reject_cancel') {
        $sid = (int)($_POST['session_id'] ?? 0);
        $cid = (int)($_POST['client_id'] ?? 0);
        if ($sid && $cid && dyd_owns_session($uid, $sid)) {
            if ($op === 'confirm_cancel') {
                k30_ti_confirm_cancel_attendance($sid, $cid);
                k30_ti_notify_student_cancel_decision($sid, $cid, true);
                flash_set('success', 'Odwołanie potwierdzone — udział nie będzie liczony do ceny. Kursant został powiadomiony.');
            } else {
                k30_ti_uncancel_attendance($sid, $cid);
                k30_ti_notify_student_cancel_decision($sid, $cid, false);
                flash_set('success', 'Prośba o odwołanie odrzucona — udział przywrócony. Kursant został powiadomiony.');
            }
        }
        header('Location: ' . dyd_back($course_id, 'lekcje')); exit;
    }

    // Bezpośrednie odwołanie / przywrócenie udziału kursanta (prowadzący / admin)
    if ($op === 'cancel_attendee' || $op === 'restore_attendee') {
        $sid = (int)($_POST['session_id'] ?? 0);
        $cid = (int)($_POST['client_id'] ?? 0);
        if ($sid && $cid && dyd_owns_session($uid, $sid)) {
            if ($op === 'cancel_attendee') {
                $reason = trim($_POST['reason'] ?? '');
                $role   = (($me['role'] ?? '') === 'admin') ? 'admin' : 'doradca';
                k30_ti_cancel_attendance($sid, $cid, $reason !== '' ? $reason : 'Odwołane przez prowadzącego', $role, (string)($me['name'] ?? ''));
                flash_set('success', 'Udział kursanta odwołany — nie będzie liczony do ceny.');
            } else {
                k30_ti_uncancel_attendance($sid, $cid);
                flash_set('success', 'Udział kursanta przywrócony.');
            }
        }
        header('Location: ' . dyd_back($course_id, 'lekcje')); exit;
    }

    // Oznaczenie kursanta jako „nie pojawił się" (no_show)
    if ($op === 'mark_no_show') {
        $sid     = (int)($_POST['session_id'] ?? 0);
        $cid     = (int)($_POST['client_id'] ?? 0);
        $billing = trim($_POST['no_show_billing'] ?? 'full');
        $reason  = trim($_POST['no_show_reason'] ?? '');
        $attachment = [];
        if (!empty($_FILES['no_show_screenshot']['name'])) {
            if (!function_exists('mail_queue_save_attachment')) @require_once dirname(dirname(dirname(__DIR__))) . '/includes/mail_queue.php';
            if (function_exists('mail_queue_save_attachment')) {
                $att = mail_queue_save_attachment($_FILES['no_show_screenshot']);
                if ($att) $attachment = $att;
            }
        }
        if ($sid && $cid && dyd_owns_session($uid, $sid)) {
            $role = (($me['role'] ?? '') === 'admin') ? 'admin' : 'doradca';
            k30_ti_mark_no_show($sid, $cid, $billing, $role, (string)($me['name'] ?? ''), $reason, $attachment);
            flash_set('success', 'Oznaczono jako „nie pojawił się" — rozliczenie: ' . ($billing === '1h' ? '1 godzina' : 'cała lekcja') . '.');
        }
        header('Location: ' . dyd_back($course_id, 'lekcje')); exit;
    }

    // Odwołanie / przywrócenie całej lekcji (prowadzący / admin)
    if ($op === 'cancel_session' || $op === 'uncancel_session') {
        $sid = (int)($_POST['session_id'] ?? 0);
        if ($sid && dyd_owns_session($uid, $sid)) {
            if ($op === 'cancel_session') {
                // Blokada odwoływania lekcji z przeszłości
                $_sess_date = (string)(db_one("SELECT lesson_date FROM k30_ti_sessions WHERE id=?", [$sid])['lesson_date'] ?? '');
                if ($_sess_date && $_sess_date < date('Y-m-d')) {
                    flash_set('danger', 'Nie można odwołać lekcji z przeszłości.');
                    header('Location: ' . dyd_back($course_id, 'lekcje')); exit;
                }
                $reason = trim($_POST['reason'] ?? '');
                if ($reason === '') { flash_set('danger', 'Podaj powód odwołania lekcji.'); header('Location: ' . dyd_back($course_id, 'lekcje')); exit; }
                $role = (($me['role'] ?? '') === 'admin') ? 'admin' : 'doradca';
                $sms_sent = k30_ti_cancel_session($sid, $reason, $role, (string)($me['name'] ?? ''));
                flash_set('success', 'Lekcja odwołana — nie zostanie policzona do ceny.'
                    . ($sms_sent ? " Wysłano SMS: {$sms_sent}." : ''));
            } else {
                db()->prepare(
                    "UPDATE k30_ti_sessions SET status='planned', cancel_reason='', cancelled_by_role='', cancelled_by='', cancelled_at=NULL, updated_at=datetime('now') WHERE id=?"
                )->execute([$sid]);
                flash_set('success', 'Lekcja przywrócona (zaplanowana).');
            }
        }
        header('Location: ' . dyd_back($course_id, 'lekcje')); exit;
    }

    // Zmiana terminu lekcji (prowadzący / admin) — bezpośrednio, z opcjonalnym powiadomieniem
    if ($op === 'reschedule_session') {
        $sid  = (int)($_POST['session_id'] ?? 0);
        $date = trim($_POST['lesson_date'] ?? '');
        $tf   = trim($_POST['time_from'] ?? '');
        $tt   = trim($_POST['time_to'] ?? '');
        if ($sid && dyd_owns_session($uid, $sid)) {
            if ($date === '') { flash_set('danger', 'Podaj nowy termin lekcji.'); header('Location: ' . dyd_back($course_id, 'lekcje')); exit; }
            $v = ti_validate_reschedule($sid, $course_id, $date, $tf, $tt);
            if (!$v['ok']) {
                flash_set('danger', $v['reason'] . ($v['code'] === 'zoom' ? ZOOM_BUSY_HINT : ''));
                header('Location: ' . dyd_back($course_id, 'lekcje')); exit;
            }
            $old = k30_ti_do_reschedule($sid, $date, $tf, $tt);
            if ($old !== null && isset($_POST['notify'])) {
                k30_ti_reschedule_notify_parties($sid, $old, isset($_POST['notify_sms']));
                flash_set('success', 'Termin lekcji zmieniony. Powiadomiono uczestników.');
            } else {
                flash_set('success', 'Termin lekcji zmieniony.');
            }
        }
        header('Location: ' . dyd_back($course_id, 'lekcje')); exit;
    }

    // Decyzja ws. propozycji nowego terminu od kursanta / opiekuna
    if ($op === 'reschedule_accept' || $op === 'reschedule_reject') {
        $rid = (int)($_POST['request_id'] ?? 0);
        $req = $rid ? k30_ti_reschedule_get($rid) : null;
        if ($req && ($_pc_r = ti_period_closed_for_session((int)$req['session_id']))) {
            flash_set('danger', ti_period_closed_msg($_pc_r));
            header('Location: ' . dyd_back($course_id, 'lekcje')); exit;
        }
        if ($req && dyd_owns_session($uid, (int)$req['session_id'])) {
            $accept = $op === 'reschedule_accept';
            if ($accept) {
                $av = ti_instructor_available_at(ti_course_instructor_id($course_id), (string)$req['proposed_date'], (string)$req['proposed_from'], (string)$req['proposed_to']);
                if (!$av['ok']) { flash_set('danger', 'Nie można zaakceptować: ' . $av['reason']); header('Location: ' . dyd_back($course_id, 'lekcje')); exit; }
                if ($_pc = ti_period_closed_for_date((string)$req['proposed_date'])) {
                    flash_set('danger', 'Nie można zaakceptować: ' . ti_period_closed_msg($_pc));
                    header('Location: ' . dyd_back($course_id, 'lekcje')); exit;
                }
                $_lm = (string)(db_one("SELECT lesson_method FROM k30_ti_sessions WHERE id=?", [(int)$req['session_id']])['lesson_method'] ?? '');
                $zc  = ti_zoom_slot_check($course_id, $_lm, (string)$req['proposed_date'], (string)$req['proposed_from'], (string)$req['proposed_to'], (int)$req['session_id']);
                if (!$zc['ok']) { flash_set('danger', 'Nie można zaakceptować: ' . $zc['reason'] . ZOOM_BUSY_HINT); header('Location: ' . dyd_back($course_id, 'lekcje')); exit; }
            }
            k30_ti_reschedule_decide($rid, $accept, (string)($me['name'] ?? ''), trim($_POST['note'] ?? ''));
            flash_set('success', $accept
                ? 'Propozycja zaakceptowana — termin lekcji zmieniony, kursant powiadomiony.'
                : 'Propozycja odrzucona — kursant powiadomiony.');
        }
        header('Location: ' . dyd_back($course_id, 'lekcje')); exit;
    }

    // ── NIEOBECNOŚCI: usprawiedliwianie (= odwołanie udziału, nie liczone do ceny) ─
    if ($op === 'excuse_absence' || $op === 'unexcuse_absence') {
        $sid = (int)($_POST['session_id'] ?? 0);
        $cid = (int)($_POST['client_id'] ?? 0);
        if ($sid && $cid && dyd_owns_session($uid, $sid)) {
            if ($op === 'excuse_absence') {
                $reason = trim($_POST['reason'] ?? '');
                $role   = (($me['role'] ?? '') === 'admin') ? 'admin' : 'doradca';
                k30_ti_cancel_attendance($sid, $cid, $reason !== '' ? $reason : 'Nieobecność usprawiedliwiona', $role, (string)($me['name'] ?? ''));
                flash_set('success', 'Nieobecność usprawiedliwiona — nie będzie liczona do ceny.');
            } else {
                k30_ti_uncancel_attendance($sid, $cid);
                flash_set('success', 'Cofnięto usprawiedliwienie — nieobecność nieusprawiedliwiona.');
            }
        }
        header('Location: ' . dyd_back($course_id, 'nieobecnosci')); exit;
    }

    // ── PROGRAM ZAJĘĆ (plan nauczania / sylabus kursu) ──────────────────────────
    if ($op === 'curr_save') {
        $iid   = (int)($_POST['item_id'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        if ($title === '') { flash_set('danger', 'Podaj temat pozycji planu.'); header('Location: ' . dyd_back($course_id, 'program')); exit; }
        k30_ti_curriculum_save([
            'course_id'   => $course_id,
            'section'     => $_POST['section'] ?? '',
            'title'       => $title,
            'description' => $_POST['description'] ?? '',
            'est_minutes' => $_POST['est_minutes'] ?? 0,
            'is_active'   => isset($_POST['is_active']) ? 1 : 0,
        ], $iid ?: null, $uid);
        flash_set('success', $iid ? 'Pozycja planu zaktualizowana.' : 'Dodano pozycję planu.');
        header('Location: ' . dyd_back($course_id, 'program')); exit;
    }
    if ($op === 'curr_delete') {
        $iid = (int)($_POST['item_id'] ?? 0);
        $it  = $iid ? k30_ti_curriculum_get($iid) : null;
        if ($it && (int)$it['course_id'] === $course_id) {
            k30_ti_curriculum_delete($iid);
            flash_set('success', 'Pozycja planu usunięta.');
        }
        header('Location: ' . dyd_back($course_id, 'program')); exit;
    }
    if ($op === 'curr_move') {
        $iid = (int)($_POST['item_id'] ?? 0);
        $dir = ($_POST['dir'] ?? '') === 'up' ? 'up' : 'down';
        $items = k30_ti_curriculum_list($course_id);
        $ids   = array_map(fn($r) => (int)$r['id'], $items);
        $pos   = array_search($iid, $ids, true);
        if ($pos !== false) {
            $swap = $dir === 'up' ? $pos - 1 : $pos + 1;
            if ($swap >= 0 && $swap < count($ids)) {
                [$ids[$pos], $ids[$swap]] = [$ids[$swap], $ids[$pos]];
                k30_ti_curriculum_reorder($course_id, $ids);
            }
        }
        header('Location: ' . dyd_back($course_id, 'program')); exit;
    }
    if ($op === 'curr_import') {
        // Prowadzący może wgrać sylabus PLIKIEM (CSV) albo wkleić treść — plik ma
        // pierwszeństwo, bo to jego jawny wybór.
        $raw = (string)($_POST['csv'] ?? '');
        if (!empty($_FILES['csv_file']['tmp_name']) && is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
            if ((int)($_FILES['csv_file']['size'] ?? 0) > 2 * 1024 * 1024) {
                flash_set('danger', 'Plik jest za duży — sylabus w CSV nie powinien przekraczać 2 MB.');
                header('Location: ' . dyd_back($course_id, 'program')); exit;
            }
            $raw = (string)file_get_contents($_FILES['csv_file']['tmp_name']);
            // Arkusze zapisują CSV w Windows-1250 albo z BOM — normalizujemy do UTF-8
            $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
            if (!mb_check_encoding($raw, 'UTF-8')) {
                $conv = @iconv('WINDOWS-1250', 'UTF-8//TRANSLIT', $raw);
                if ($conv !== false) $raw = $conv;
            }
        }
        if (trim($raw) === '') { flash_set('danger', 'Wgraj plik CSV albo wklej dane do zaimportowania.'); header('Location: ' . dyd_back($course_id, 'program')); exit; }
        $res = k30_ti_curriculum_import_csv($course_id, $raw, $uid);
        $msg = 'Zaimportowano pozycji: ' . (int)($res['added'] ?? 0) . '.';
        if (!empty($res['errors'])) $msg .= ' Błędów: ' . count($res['errors']) . '.';
        flash_set(!empty($res['errors']) ? 'warning' : 'success', $msg);
        header('Location: ' . dyd_back($course_id, 'program')); exit;
    }

    // Dodanie wielu tematów sylabusa jednym formularzem (bez pliku CSV)
    if ($op === 'curr_bulk') {
        $titles  = (array)($_POST['b_title']   ?? []);
        $secs    = (array)($_POST['b_section'] ?? []);
        $descs   = (array)($_POST['b_desc']    ?? []);
        $mins    = (array)($_POST['b_min']     ?? []);
        $added = 0;
        foreach ($titles as $i => $t) {
            $t = trim((string)$t);
            if ($t === '') continue;                     // puste wiersze pomijamy
            k30_ti_curriculum_save([
                'course_id'   => $course_id,
                'section'     => (string)($secs[$i]  ?? ''),
                'title'       => $t,
                'description' => (string)($descs[$i] ?? ''),
                'est_minutes' => (int)($mins[$i] ?? 0),
                'is_active'   => 1,
            ], null, $uid);
            $added++;
        }
        flash_set($added ? 'success' : 'warning', $added
            ? 'Dodano tematów: ' . $added . '.'
            : 'Nie dodano nic — wpisz przynajmniej jeden temat.');
        header('Location: ' . dyd_back($course_id, 'program')); exit;
    }

    // ── PROTOKOŁY OCEN ──────────────────────────────────────────────────────────
    if (in_array($op, ['protocol_create', 'protocol_save', 'protocol_approve', 'protocol_unlock', 'protocol_hours_ack', 'protocol_org_ack'], true)) {
        dyd_token_check();
        $back = dyd_back($course_id, 'protokol');
        if ($dziennik_off) {
            flash_set('danger', 'Dziennik ocen jest wyłączony ' . ti_blackout_range_text($dziennik_off) . ' — protokoły też.');
            header('Location: ' . $back); exit;
        }
        $pid   = (int)($_POST['protocol_id'] ?? 0);
        $prot  = $pid ? ti_protocol_get($pid) : null;
        if ($prot && (int)$prot['course_id'] !== $course_id) { http_response_code(403); exit('Protokół z innego kursu.'); }
        $me_name = (string)($me['name'] ?? '');

        try {
            if ($op === 'protocol_create') {
                $new = ti_protocol_ensure($course_id, (int)($_POST['period_id'] ?? 0), $uid);
                flash_set('success', 'Protokół otwarty — wpisz oceny końcowe.');
                header('Location: ' . $back . '&protocol=' . $new); exit;
            }
            if (!$prot) { flash_set('danger', 'Protokół nie istnieje.'); header('Location: ' . $back); exit; }

            if ($op === 'protocol_save') {
                $r = ti_protocol_save_entries(
                    $pid,
                    (array)($_POST['grade'] ?? []),
                    (array)($_POST['note'] ?? []),
                    $uid
                );
                $msg = 'Protokół zapisany: ' . $r['saved'] . ' wpisów'
                     . ($r['cleared'] ? ', wyczyszczono ' . $r['cleared'] : '') . '.';
                if ($r['errors']) $msg .= ' Pominięto: ' . implode(' ', array_slice($r['errors'], 0, 3));
                flash_set($r['errors'] ? 'warning' : 'success', $msg);
            } elseif ($op === 'protocol_approve') {
                if (!ti_protocol_can_approve($uid, (int)$prot['course_id'], dyd_is_staff())) throw new \RuntimeException(TI_PROTOCOL_APPROVE_DENIED);
                ti_protocol_approve($pid, $uid, $me_name);
                flash_set('success', 'Protokół zatwierdzony — ocen nie można już zmieniać.');
            } elseif ($op === 'protocol_hours_ack') {
                $hours_on_behalf = dyd_is_staff() && !empty($_POST['on_behalf']);
                ti_protocol_hours_ack($pid, $uid, $me_name, (string)($_SERVER['REMOTE_ADDR'] ?? ''), $hours_on_behalf);
                flash_set('success', $hours_on_behalf
                    ? 'Ewidencja godzin uzupełniona w zastępstwie prowadzącego — ślad zapisany w protokole.'
                    : 'Ewidencja godzin i naliczenie wypłaty potwierdzone — ślad zapisany w protokole.');
            } elseif ($op === 'protocol_org_ack') {
                if (!dyd_is_staff()) { http_response_code(403); exit('Podpisać za organizatora może pracownik D3 lub administrator.'); }
                ti_protocol_org_ack($pid, $uid, $me_name, (string)($_SERVER['REMOTE_ADDR'] ?? ''));
                flash_set('success', 'Protokół podpisany za organizatora — ślad zapisany w dokumencie.');
            } elseif ($op === 'protocol_unlock') {
                if (!dyd_is_staff()) { http_response_code(403); exit('Odblokować protokół może pracownik D3 lub administrator.'); }
                ti_protocol_unlock($pid, $uid, $me_name, (string)($_POST['reason'] ?? ''));
                flash_set('success', 'Protokół odblokowany — powód zapisany w śladzie.');
            }
        } catch (\Throwable $e) {
            flash_set('danger', $e->getMessage());
        }
        header('Location: ' . $back . '&protocol=' . $pid); exit;
    }

    // ── OCENY (e-dziennik) ──────────────────────────────────────────────────────
    // Dziennik wyłączony na czas okna prac — żadnego wpisu ani zmiany oceny
    if ($dziennik_off && in_array($op, ['grade_save', 'grade_delete', 'grade_toggle_course', 'hw_grade'], true)) {
        flash_set('danger', 'Dziennik ocen jest wyłączony ' . ti_blackout_range_text($dziennik_off) . '. ' . ti_blackout_message($dziennik_off));
        header('Location: ' . dyd_back($course_id, 'pulpit')); exit;
    }

    if ($op === 'grade_save') {
        $gid        = (int)($_POST['grade_id'] ?? 0);
        $client_id  = (int)($_POST['client_id'] ?? 0);
        $session_id = (int)($_POST['session_id'] ?? 0) ?: null;
        $CATS = k30_ti_grade_categories();
        $cat  = trim($_POST['category'] ?? 'inne'); if (!isset($CATS[$cat])) $cat = 'inne';
        $vtext  = trim($_POST['value_text'] ?? '');
        $weight = (float)str_replace(',', '.', $_POST['weight'] ?? '1'); if ($weight <= 0) $weight = 1;
        $desc   = trim($_POST['description'] ?? '');
        $ok = $client_id && db_one("SELECT 1 FROM k30_ti_enrollments WHERE course_id=? AND client_id=?", [$course_id, $client_id]);
        if (!$ok || $vtext === '') { flash_set('danger', 'Wybierz kursanta i wpisz ocenę.'); header('Location: ' . dyd_back($course_id, 'oceny')); exit; }
        if (!k30_ti_grades_allowed($course_id, $client_id)) { flash_set('danger', 'Oceny są wyłączone dla tego kursu lub tej osoby.'); header('Location: ' . dyd_back($course_id, 'oceny')); exit; }
        if ($session_id && !db_one("SELECT 1 FROM k30_ti_sessions WHERE id=? AND course_id=?", [$session_id, $course_id])) $session_id = null;
        $vnum = k30_ti_grade_parse_num($vtext);
        $g = $gid ? k30_ti_grade_get($gid) : null;
        if ($g && (int)$g['course_id'] === $course_id) {
            db()->prepare("UPDATE k30_ti_grades SET client_id=?, session_id=?, category=?, value_text=?, value_num=?, weight=?, description=? WHERE id=?")
               ->execute([$client_id, $session_id, $cat, $vtext, $vnum, $weight, $desc, $gid]);
            flash_set('success', 'Ocena zaktualizowana.');
        } else {
            db_insert('k30_ti_grades', [
                'course_id'=>$course_id, 'client_id'=>$client_id, 'session_id'=>$session_id,
                'category'=>$cat, 'value_text'=>$vtext, 'value_num'=>$vnum, 'weight'=>$weight,
                'description'=>$desc, 'graded_by'=>$uid,
            ]);
            flash_set('success', 'Ocena wystawiona.');
        }
        if (isset($_POST['notify'])) k30_ti_notify_grade($course_id, $client_id, $vtext, $CATS[$cat]['label'] ?? $cat, $desc);
        header('Location: ' . dyd_back($course_id, 'oceny')); exit;
    }
    if ($op === 'grade_delete') {
        $gid = (int)($_POST['grade_id'] ?? 0);
        $g   = $gid ? k30_ti_grade_get($gid) : null;
        if ($g && (int)$g['course_id'] === $course_id) {
            db()->prepare("DELETE FROM k30_ti_grades WHERE id=?")->execute([$gid]);
            flash_set('success', 'Ocena usunięta.');
        }
        header('Location: ' . dyd_back($course_id, 'oceny')); exit;
    }
    if ($op === 'grade_toggle_course') {
        $c = k30_ti_course_get($course_id);
        if ($c) {
            $new = empty($c['grades_enabled']) ? 1 : 0;
            db()->prepare("UPDATE k30_ti_courses SET grades_enabled=? WHERE id=?")->execute([$new, $course_id]);
            flash_set('success', $new ? 'Oceny w tym kursie włączone.' : 'Oceny w tym kursie wyłączone.');
        }
        header('Location: ' . dyd_back($course_id, 'oceny')); exit;
    }

    // ── ZADANIA: ocena oddanej pracy domowej ────────────────────────────────────
    if ($op === 'hw_grade') {
        $sid  = (int)($_POST['submission_id'] ?? 0);
        $hwid = (int)($_POST['homework_id'] ?? 0);
        $sub  = $sid ? db_one(
            "SELECT s.id, h.course_id FROM k30_ti_homework_submissions s
             JOIN k30_ti_homework h ON h.id=s.homework_id WHERE s.id=?", [$sid]) : null;
        if ($sub && dyd_owns_course($uid, (int)$sub['course_id'])) {
            $grade = trim($_POST['grade'] ?? '');
            $fb    = trim($_POST['feedback'] ?? '');
            db()->prepare(
                "UPDATE k30_ti_homework_submissions
                 SET grade=?, feedback=?, status=?, graded_by=?, graded_at=datetime('now'), updated_at=datetime('now')
                 WHERE id=?"
            )->execute([$grade, $fb, ($grade !== '' || $fb !== '') ? 'graded' : 'submitted', $uid, $sid]);
            k30_ti_grade_sync_from_homework($sid, $uid);
            flash_set('success', 'Ocena zapisana' . ($grade !== '' ? ' i dodana do dziennika ocen.' : '.'));
        }
        header('Location: ' . dyd_back($course_id, 'zadania') . '&hw=' . $hwid); exit;
    }

    // ── ZADANIA DOMOWE ──────────────────────────────────────────────────────────
    if ($op === 'save_homework') {
        $hid       = (int)($_POST['homework_id'] ?? 0);
        $session_id= (int)($_POST['session_id'] ?? 0) ?: null;
        $title     = trim($_POST['title'] ?? '');
        $desc      = trim($_POST['description'] ?? '');
        $hint      = trim($_POST['hint'] ?? '');
        $due       = trim($_POST['due_at'] ?? '');
        $due_sql   = $due !== '' ? str_replace('T', ' ', $due) . (strlen($due) === 16 ? ':00' : '') : null;
        $open_at   = $dt_in('open_at');
        $close_at  = $dt_in('close_at');
        if ($title === '') { flash_set('danger', 'Podaj tytuł zadania.'); header('Location: ' . dyd_back($course_id, 'zadania')); exit; }
        if ($session_id && !db_one("SELECT 1 FROM k30_ti_sessions WHERE id=? AND course_id=?", [$session_id, $course_id])) $session_id = null;

        try { $up = k30_ti_homework_upload('attach', 'hw'); }
        catch (\Throwable $e) { flash_set('danger', $e->getMessage()); header('Location: ' . dyd_back($course_id, 'zadania')); exit; }

        if ($hid) {
            $hw = k30_ti_homework_get($hid);
            if (!$hw || !dyd_owns_course($uid, (int)$hw['course_id'])) { http_response_code(403); exit('Brak uprawnień.'); }
            $set = ['course_id'=>$course_id, 'session_id'=>$session_id, 'title'=>$title, 'description'=>$desc,
                    'hint'=>$hint, 'due_at'=>$due_sql, 'open_at'=>$open_at, 'close_at'=>$close_at,
                    'is_active'=>isset($_POST['is_active'])?1:0];
            if ($up) {
                if ($hw['attach_path'] !== '') k30_ti_homework_delete_file($hw['attach_path']);
                $set['attach_name'] = $up['name']; $set['attach_path'] = $up['stored'];
            }
            $cols=[];$p=[]; foreach ($set as $k=>$v){$cols[]="$k=?";$p[]=$v;} $p[]=$hid;
            db()->prepare("UPDATE k30_ti_homework SET ".implode(',',$cols)." WHERE id=?")->execute($p);
            if (isset($_POST['notify'])) {
                k30_ti_notify_dydaktyka($course_id, 'Zmiana w zadaniu: ' . $title,
                    'Prowadzący zaktualizował zadanie domowe „' . htmlspecialchars($title, ENT_QUOTES) . '".',
                    rtrim(APP_URL,'/') . '/karty30/ti/kursant/index.php?tab=zadania',
                    (defined('ORG_NAME')?ORG_NAME:'TI') . ': zmiana w zadaniu "' . $title . '".');
            }
            flash_set('success', 'Zadanie zaktualizowane.');
        } else {
            db_insert('k30_ti_homework', [
                'course_id'=>$course_id, 'session_id'=>$session_id, 'title'=>$title, 'description'=>$desc,
                'hint'=>$hint, 'due_at'=>$due_sql, 'open_at'=>$open_at, 'close_at'=>$close_at,
                'attach_name'=>$up['name']??'', 'attach_path'=>$up['stored']??'',
                'is_active'=>1, 'created_by'=>$uid,
            ]);
            if (isset($_POST['notify'])) {
                k30_ti_notify_dydaktyka($course_id, 'Nowe zadanie: ' . $title,
                    'Prowadzący dodał nowe zadanie domowe „' . htmlspecialchars($title, ENT_QUOTES) . '"'
                        . ($due_sql ? ' (termin: ' . substr($due_sql,0,16) . ')' : '') . '.',
                    rtrim(APP_URL,'/') . '/karty30/ti/kursant/index.php?tab=zadania',
                    (defined('ORG_NAME')?ORG_NAME:'TI') . ': nowe zadanie "' . $title . '"' . ($due_sql ? ', termin ' . substr($due_sql,0,16) : '') . '.');
            }
            flash_set('success', 'Zadanie dodane.');
        }
        header('Location: ' . dyd_back($course_id, 'zadania')); exit;
    }

    if ($op === 'delete_homework') {
        $hid = (int)($_POST['homework_id'] ?? 0);
        $hw  = $hid ? k30_ti_homework_get($hid) : null;
        if ($hw && dyd_owns_course($uid, (int)$hw['course_id'])) {
            foreach (db_all("SELECT file_path FROM k30_ti_homework_submissions WHERE homework_id=?", [$hid]) as $s)
                k30_ti_homework_delete_file($s['file_path']);
            k30_ti_homework_delete_file($hw['attach_path']);
            db()->prepare("DELETE FROM k30_ti_homework WHERE id=?")->execute([$hid]);
            flash_set('success', 'Zadanie usunięte.');
        }
        header('Location: ' . dyd_back($course_id, 'zadania')); exit;
    }

    // ── MATERIAŁY / eLEARNING ───────────────────────────────────────────────────
    if ($op === 'save_material') {
        $TYPES     = k30_ti_material_types();
        $mid       = (int)($_POST['material_id'] ?? 0);
        $session_id= (int)($_POST['session_id'] ?? 0) ?: null;
        $type      = trim($_POST['type'] ?? 'inne');
        if (!isset($TYPES[$type])) $type = 'inne';
        $title     = trim($_POST['title'] ?? '');
        $desc      = trim($_POST['description'] ?? '');
        $url       = trim($_POST['url'] ?? '');
        $open_at   = $dt_in('open_at');
        $close_at  = $dt_in('close_at');
        if ($title === '') { flash_set('danger', 'Podaj tytuł materiału.'); header('Location: ' . dyd_back($course_id, 'materialy')); exit; }
        if ($session_id && !db_one("SELECT 1 FROM k30_ti_sessions WHERE id=? AND course_id=?", [$session_id, $course_id])) $session_id = null;

        $cloud_stored = basename(trim($_POST['cloud_stored'] ?? ''));
        $cloud_name   = trim($_POST['cloud_name'] ?? '');
        $valid_cloud  = $cloud_stored !== '' && $cloud_name !== ''
            && preg_match('/^mat_\d{8}_\d{6}_[0-9a-f]+\.[a-z0-9]+$/i', $cloud_stored);
        if ($valid_cloud) {
            $up = ['stored' => $cloud_stored, 'name' => $cloud_name];
        } else {
            try { $up = k30_ti_homework_upload('attach', 'mat'); }
            catch (\Throwable $e) { flash_set('danger', $e->getMessage()); header('Location: ' . dyd_back($course_id, 'materialy')); exit; }
        }

        if ($mid) {
            $m = k30_ti_material_get($mid);
            if (!$m || !dyd_owns_course($uid, (int)$m['course_id'])) { http_response_code(403); exit('Brak uprawnień.'); }
            $set = ['course_id'=>$course_id, 'session_id'=>$session_id, 'type'=>$type,
                    'title'=>$title, 'description'=>$desc, 'url'=>$url,
                    'open_at'=>$open_at, 'close_at'=>$close_at,
                    'is_active'=>isset($_POST['is_active'])?1:0];
            if ($up) {
                if ($m['attach_path'] !== '') k30_ti_homework_delete_file($m['attach_path']);
                $set['attach_name'] = $up['name']; $set['attach_path'] = $up['stored'];
            }
            $cols=[];$p=[]; foreach ($set as $k=>$v){$cols[]="$k=?";$p[]=$v;} $p[]=$mid;
            db()->prepare("UPDATE k30_ti_materials SET ".implode(',',$cols)." WHERE id=?")->execute($p);
            if (isset($_POST['notify'])) {
                k30_ti_notify_dydaktyka($course_id, 'Zmiana w materiale: ' . $title,
                    'Prowadzący zaktualizował materiał „' . htmlspecialchars($title, ENT_QUOTES) . '" w sekcji Dydaktyka / eLearning.',
                    rtrim(APP_URL,'/') . '/karty30/ti/kursant/index.php?tab=zadania',
                    (defined('ORG_NAME')?ORG_NAME:'TI') . ': zaktualizowano material "' . $title . '".');
            }
            flash_set('success', 'Materiał zaktualizowany.');
        } else {
            db_insert('k30_ti_materials', [
                'course_id'=>$course_id, 'session_id'=>$session_id, 'type'=>$type,
                'title'=>$title, 'description'=>$desc, 'url'=>$url,
                'open_at'=>$open_at, 'close_at'=>$close_at,
                'attach_name'=>$up['name']??'', 'attach_path'=>$up['stored']??'',
                'is_active'=>1, 'created_by'=>$uid,
            ]);
            if (isset($_POST['notify'])) {
                k30_ti_notify_dydaktyka($course_id, 'Nowy materiał: ' . $title,
                    'Prowadzący dodał nowy materiał „' . htmlspecialchars($title, ENT_QUOTES) . '" w sekcji Dydaktyka / eLearning.',
                    rtrim(APP_URL,'/') . '/karty30/ti/kursant/index.php?tab=zadania',
                    (defined('ORG_NAME')?ORG_NAME:'TI') . ': nowy material "' . $title . '" w panelu kursanta.');
            }
            flash_set('success', 'Materiał dodany.');
        }
        header('Location: ' . dyd_back($course_id, 'materialy')); exit;
    }

    if ($op === 'delete_material') {
        $mid = (int)($_POST['material_id'] ?? 0);
        $m   = $mid ? k30_ti_material_get($mid) : null;
        if ($m && dyd_owns_course($uid, (int)$m['course_id'])) {
            k30_ti_homework_delete_file($m['attach_path']);
            db()->prepare("DELETE FROM k30_ti_materials WHERE id=?")->execute([$mid]);
            flash_set('success', 'Materiał usunięty.');
        }
        header('Location: ' . dyd_back($course_id, 'materialy')); exit;
    }

    // ── Komunikacja — e-mail / SMS ────────────────────────────────────────────
    if ($op === 'komm_preview' && dyd_is_staff()) {
        $komm_mode       = in_array($_POST['mode'] ?? '', ['grupa','prowadzacy','dzien'], true) ? $_POST['mode'] : 'grupa';
        $komm_course_ids = array_values(array_filter(array_map('intval', (array)($_POST['course_ids'] ?? []))));
        $komm_instr_id   = (int)($_POST['instructor_id'] ?? 0);
        $komm_date       = trim($_POST['date'] ?? '');
        $komm_ch_email   = !empty($_POST['ch_email']);
        $komm_ch_sms     = !empty($_POST['ch_sms']);
        $komm_ch_guard   = !empty($_POST['ch_guardians']);
        $komm_subject    = trim($_POST['subject'] ?? '');
        $komm_body       = trim($_POST['body'] ?? '');
        $komm_body_html  = trim($_POST['body_html'] ?? '');
        $cids = k30_ti_comm_course_ids($komm_mode, [
            'course_ids'=>$komm_course_ids, 'instructor_id'=>$komm_instr_id, 'date'=>$komm_date,
        ]);
        $komm_recipients = k30_ti_comm_recipients($cids);
        if ($komm_ch_guard) {
            $komm_recipients = array_merge($komm_recipients, k30_ti_comm_guardian_recipients($cids));
            $seen = [];
            $komm_recipients = array_values(array_filter($komm_recipients, function($r) use (&$seen) {
                $key = trim(mb_strtolower((string)$r['email'])) ?: trim((string)$r['phone']);
                if ($key === '' || isset($seen[$key])) return $key === '';
                $seen[$key] = true;
                return true;
            }));
        }
        if ($komm_mode === 'grupa') {
            $names = $cids ? array_column(db_all("SELECT name FROM k30_ti_courses WHERE id IN (".implode(',',array_fill(0,count($cids),'?')).")", $cids), 'name') : [];
            $komm_filter_label = 'Grupy: ' . (implode(', ', $names) ?: '—');
        } elseif ($komm_mode === 'prowadzacy') {
            $in = $komm_instr_id ? db_one("SELECT name FROM users WHERE id=?", [$komm_instr_id]) : null;
            $komm_filter_label = 'Prowadzący: ' . ($in['name'] ?? '—');
        } else {
            $komm_filter_label = 'Dzień: ' . ($komm_date ?: '—');
        }
        if ($komm_ch_guard) $komm_filter_label .= ' + rodzice/opiekunowie';
        $komm_did_preview = true;
        // Nie redirectuje — renderuje stronę z zakładką komunikacja
    }

    if ($op === 'komm_send' && dyd_is_staff()) {
        require_once dirname(dirname(dirname(__DIR__))) . '/includes/mail_queue.php';
        require_once dirname(dirname(dirname(__DIR__))) . '/includes/sms.php';
        $k_mode       = in_array($_POST['mode'] ?? '', ['grupa','prowadzacy','dzien'], true) ? $_POST['mode'] : 'grupa';
        $k_course_ids = array_values(array_filter(array_map('intval', (array)($_POST['course_ids'] ?? []))));
        $k_instr_id   = (int)($_POST['instructor_id'] ?? 0);
        $k_date       = trim($_POST['date'] ?? '');
        $k_ch_email   = !empty($_POST['ch_email']);
        $k_ch_sms     = !empty($_POST['ch_sms']);
        $k_ch_guard   = !empty($_POST['ch_guardians']);
        $k_subject    = trim($_POST['subject'] ?? '');
        $k_body       = trim($_POST['body'] ?? '');
        $k_body_html  = trim($_POST['body_html'] ?? '');
        $k_sms_on     = function_exists('sms_channel_ready') ? sms_channel_ready() : false;
        $errs = [];
        if (!$k_ch_email && !$k_ch_sms) $errs[] = 'Wybierz kanał: e-mail i/lub SMS.';
        if ($k_ch_sms && !$k_sms_on)    $errs[] = 'SMS jest wyłączony w ustawieniach systemu.';
        if ($k_body === '')              $errs[] = 'Wpisz treść wiadomości.';
        if ($k_ch_email && $k_subject === '') $errs[] = 'Podaj temat wiadomości e-mail.';
        $cids = k30_ti_comm_course_ids($k_mode, [
            'course_ids'=>$k_course_ids, 'instructor_id'=>$k_instr_id, 'date'=>$k_date,
        ]);
        $k_recipients = k30_ti_comm_recipients($cids);
        if ($k_ch_guard) {
            $k_recipients = array_merge($k_recipients, k30_ti_comm_guardian_recipients($cids));
            $seen = [];
            $k_recipients = array_values(array_filter($k_recipients, function($r) use (&$seen) {
                $key = trim(mb_strtolower((string)$r['email'])) ?: trim((string)$r['phone']);
                if ($key === '' || isset($seen[$key])) return $key === '';
                $seen[$key] = true;
                return true;
            }));
        }
        if (!$k_recipients) $errs[] = 'Brak odbiorców dla wybranego filtra.';
        if ($errs) {
            foreach ($errs as $e) flash_set('danger', $e);
            header('Location: index.php?tab=komunikacja'); exit;
        }
        $ok = 0; $fail = 0;
        $email_inner = $k_body_html !== ''
            ? $k_body_html
            : '<div>' . nl2br(h($k_body)) . '</div>';
        $html = '<div style="font-family:system-ui,-apple-system,Segoe UI,sans-serif;font-size:15px;line-height:1.6;color:#0f172a">'
              . $email_inner . '</div>';
        foreach ($k_recipients as $r) {
            if ($k_ch_email && trim((string)$r['email']) !== '') {
                try { mail_queue_add(trim($r['email']), $r['name'] ?? '', $k_subject, $html, $k_body, 'ti_komunikacja', null, '', false); $ok++; }
                catch (\Throwable $ex) { $fail++; }
            }
            if ($k_ch_sms && $k_sms_on && trim((string)$r['phone']) !== '') {
                try { sms_send(trim($r['phone']), $k_body); $ok++; }
                catch (\Throwable $ex) { $fail++; }
            }
        }
        if ($k_mode === 'grupa') {
            $names = $cids ? array_column(db_all("SELECT name FROM k30_ti_courses WHERE id IN (".implode(',',array_fill(0,count($cids),'?')).")", $cids), 'name') : [];
            $k_filter_label = 'Grupy: ' . (implode(', ', $names) ?: '—');
        } elseif ($k_mode === 'prowadzacy') {
            $in2 = $k_instr_id ? db_one("SELECT name FROM users WHERE id=?", [$k_instr_id]) : null;
            $k_filter_label = 'Prowadzący: ' . ($in2['name'] ?? '—');
        } else {
            $k_filter_label = 'Dzień: ' . ($k_date ?: '—');
        }
        if ($k_ch_guard) $k_filter_label .= ' + rodzice/opiekunowie';
        $k_ch = trim(($k_ch_email ? 'email' : '') . ($k_ch_email && $k_ch_sms ? '+' : '') . ($k_ch_sms ? 'sms' : ''));
        db_insert('k30_ti_comm_log', [
            'channel'=>$k_ch, 'filter_type'=>$k_mode, 'filter_label'=>$k_filter_label,
            'subject'=>$k_subject, 'body'=>$k_body, 'recipients'=>count($k_recipients),
            'sent_ok'=>$ok, 'sent_fail'=>$fail, 'created_by'=>$uid,
        ]);
        flash_set($fail ? 'warning' : 'success',
            'Wysłano: ' . $ok . ($fail ? (', błędów: ' . $fail) : '') . ' (odbiorców: ' . count($k_recipients) . ').');
        header('Location: index.php?tab=komunikacja'); exit;
    }
}

// ── Dane do widoku ──────────────────────────────────────────────────────────
$course   = null;
foreach (array_merge($courses, $courses_archived) as $c) { if ((int)$c['id'] === $cur_course) { $course = $c; break; } }
$dtv      = fn($v) => $v ? h(str_replace(' ', 'T', substr($v, 0, 16))) : '';

$sessions = $materials = $homeworks = [];
$all_sessions = [];
if ($cur_course) {
    $sessions     = k30_ti_sessions($cur_course);
    usort($sessions, fn($a, $b) => strcmp((string)$b['lesson_date'], (string)$a['lesson_date'])
        ?: strcmp((string)($b['time_from'] ?? ''), (string)($a['time_from'] ?? ''))); // najnowsze na górze
    $homeworks    = k30_ti_homework_list($cur_course);
    $materials    = k30_ti_materials_list($cur_course);
    $all_sessions = db_all("SELECT id, lesson_date, topic FROM k30_ti_sessions WHERE course_id=? ORDER BY lesson_date DESC, id DESC", [$cur_course]);
}

$recurring_rules = $cur_course ? db_all(
    "SELECT * FROM k30_ti_series WHERE course_id=? ORDER BY date_from",
    [$cur_course]
) : [];

// Liczba oczekujących próśb o odwołanie udziału w kursie (do licznika)
$pending_cancel_total = $cur_course ? (int)(db_one(
    "SELECT COUNT(*) n FROM k30_ti_attendance a JOIN k30_ti_sessions s ON s.id=a.session_id
     WHERE s.course_id=? AND a.cancel_pending=1", [$cur_course])['n'] ?? 0) : 0;

$TYPES = k30_ti_material_types();
$STATUS = K30_TI_SESSION_STATUSES;

// ── Dashboard (pulpit) ────────────────────────────────────────────────────────
$dash_today          = [];
$dash_upcoming       = [];
$dash_pending_cancel = 0;
if ($course_ids) {
    $ph = implode(',', array_fill(0, count($course_ids), '?'));
    $dash_today = db_all(
        "SELECT s.id, s.lesson_date, s.time_from, s.time_to, s.duration_min, s.status, s.topic,
                s.meeting_url, c.name AS course_name, c.id AS course_id, c.default_meeting_url,
                (SELECT COUNT(*) FROM k30_ti_attendance a
                 WHERE a.session_id=s.id AND COALESCE(a.cancelled,0)=0 AND COALESCE(a.cancel_pending,0)=0) AS enrolled
         FROM k30_ti_sessions s JOIN k30_ti_courses c ON c.id=s.course_id
         WHERE s.course_id IN ($ph) AND s.lesson_date = date('now','localtime')
         ORDER BY s.time_from",
        $course_ids
    );
    $dash_upcoming = db_all(
        "SELECT s.id, s.lesson_date, s.time_from, s.time_to, s.duration_min, s.status, s.topic,
                s.meeting_url, s.lesson_method, c.name AS course_name, c.id AS course_id, c.default_meeting_url
         FROM k30_ti_sessions s JOIN k30_ti_courses c ON c.id=s.course_id
         WHERE s.course_id IN ($ph) AND s.lesson_date > date('now','localtime')
           AND s.lesson_date <= date('now','localtime','+7 days') AND s.status != 'cancelled'
         ORDER BY s.lesson_date, s.time_from LIMIT 10",
        $course_ids
    );
    $dash_pending_cancel = (int)(db_one(
        "SELECT COUNT(*) n FROM k30_ti_attendance a
         JOIN k30_ti_sessions s ON s.id=a.session_id
         WHERE s.course_id IN ($ph) AND a.cancel_pending=1",
        $course_ids
    )['n'] ?? 0);
    // Zaległe (nie dzisiejsze) zaplanowane lekcje — osobno od $dash_today, żeby
    // "Do zrobienia" mogło zaproponować jedno zbiorcze uzupełnienie zamiast
    // każenia wchodzić w każdą z osobna (patrz bulk_complete_overdue).
    $dash_overdue = db_all(
        "SELECT s.id, s.lesson_date, c.name AS course_name
           FROM k30_ti_sessions s JOIN k30_ti_courses c ON c.id=s.course_id
          WHERE s.course_id IN ($ph) AND s.status='planned' AND s.lesson_date < date('now','localtime')
          ORDER BY s.lesson_date",
        $course_ids
    );
} else {
    $dash_overdue = [];
}

// ── Kreator zajęć: dzisiejsze zaplanowane lekcje ─────────────────────────────
$dyd_wizard_sessions = array_values(array_filter($dash_today, fn($s) => $s['status'] === 'planned'));

// ── Dane dla zakładki Wiadomości ─────────────────────────────────────────────
$dyd_msg_student_id = (int)($_GET['student'] ?? 0);
// Wątki: tylko kursanci z kursów tego prowadzącego
$dyd_msg_threads = $course_ids ? db_all(
    "SELECT a.id, COALESCE(cl.name, a.login) AS name, a.login,
            MAX(m.created_at) AS last_at,
            SUM(CASE WHEN m.sender='student' AND m.is_read=0 THEN 1 ELSE 0 END) AS unread
     FROM k30_ti_messages m
     JOIN k30_ti_student_accounts a ON a.id=m.student_id
     LEFT JOIN k30_clients cl ON cl.id=a.client_id
     WHERE a.id IN (
         SELECT DISTINCT sa.id FROM k30_ti_student_accounts sa
         JOIN k30_ti_enrollments e ON e.client_id=sa.client_id
         WHERE e.course_id IN (" . implode(',', array_map('intval', $course_ids)) . ") AND e.status='active'
     )
     GROUP BY a.id ORDER BY last_at DESC"
) : [];
$dyd_msg_unread_total = array_sum(array_column($dyd_msg_threads, 'unread'));
// Kursanci tego prowadzącego (do selecta nowej wiadomości)
$dyd_msg_accounts = $course_ids ? db_all(
    "SELECT DISTINCT a.id, COALESCE(cl.name, a.login) AS name, a.login, c.name AS course_name
     FROM k30_ti_student_accounts a
     JOIN k30_ti_enrollments e ON e.client_id=a.client_id
     JOIN k30_ti_courses c ON c.id=e.course_id
     LEFT JOIN k30_clients cl ON cl.id=a.client_id
     WHERE e.course_id IN (" . implode(',', array_map('intval', $course_ids)) . ") AND e.status='active' AND a.is_active=1
     ORDER BY name"
) : [];
$dyd_msg_student = $dyd_msg_student_id
    ? db_one("SELECT a.*, COALESCE(cl.name, a.login) AS client_name
              FROM k30_ti_student_accounts a LEFT JOIN k30_clients cl ON cl.id=a.client_id
              WHERE a.id=?", [$dyd_msg_student_id])
    : null;
if ($dyd_msg_student) ti_msg_mark_read_for_staff((int)$dyd_msg_student['id']);
$dyd_msg_thread      = $dyd_msg_student ? ti_msg_list_for_student((int)$dyd_msg_student['id']) : [];
$dyd_msg_is_blocked  = $dyd_msg_student ? ti_msg_is_blocked((int)$dyd_msg_student['id']) : false;
$dyd_msg_log         = $dyd_msg_student ? ti_account_log_list((int)$dyd_msg_student['id'], 50) : [];
// Wątki kierownictwo
$dyd_thread_is_admin  = (($_GET['thread'] ?? '') === 'admin');
$dyd_admin_to_raw     = (string)($_GET['to_admin'] ?? '');
$dyd_admin_active_id  = ($dyd_admin_to_raw === '-1') ? -1 : (int)$dyd_admin_to_raw; // aktywny wątek
$dyd_admin_users      = ti_admin_users();
$dyd_admin_threads    = ti_admin_msg_thread_list($uid);  // istniejące wątki (po jednym na to_admin_id)
$dyd_admin_unseen     = ti_admin_msg_unseen_total($uid); // łączna liczba niewidzianych
$dyd_admin_thread     = ($dyd_thread_is_admin)
    ? ti_admin_msg_list_for_thread($uid, $dyd_admin_active_id) : [];
if ($dyd_thread_is_admin) ti_admin_msg_mark_instructor_seen($uid, $dyd_admin_active_id);
$dyd_msg_unread_total += $dyd_admin_unseen;

// Lekcje do wyszukiwarki „Powiązana lekcja" (etykiety unikalne — do mapowania w JS)
$session_opts = []; $session_label_by_id = []; $_lbl_seen = [];
foreach ($all_sessions as $s) {
    $lbl = date('d.m.Y', strtotime($s['lesson_date'])) . ($s['topic'] !== '' && $s['topic'] !== null ? ' · ' . mb_substr($s['topic'], 0, 40) : '');
    if (isset($_lbl_seen[$lbl])) { $_lbl_seen[$lbl]++; $lbl .= ' (' . $_lbl_seen[$lbl] . ')'; } else { $_lbl_seen[$lbl] = 1; }
    $session_opts[] = ['id' => (int)$s['id'], 'label' => $lbl];
    $session_label_by_id[(int)$s['id']] = $lbl;
}

// Dzisiejsza lekcja (pierwsza pasująca do dnia dzisiejszego)
$_today = date('Y-m-d');
$_today_sid = 0; $_today_slbl = '';
foreach ($all_sessions as $_ts) {
    if ($_ts['lesson_date'] === $_today) {
        $_today_sid = (int)$_ts['id'];
        $_today_slbl = $session_label_by_id[$_today_sid] ?? '';
        break;
    }
}

/** Wyszukiwarka lekcji (pole tekstowe + lista + przycisk "Dzisiejsza lekcja"). */
$sessionPicker = function (string $pfx, int $selId) use ($session_label_by_id, $_today_sid) { ?>
  <div class="input-group">
    <input type="text" class="form-control dyd-lesson-combo" id="<?= $pfx ?>_session_txt"
           list="dyd-session-list" data-target="<?= $pfx ?>_session" autocomplete="off"
           value="<?= h($session_label_by_id[$selId] ?? '') ?>"
           placeholder="Wpisz datę lub temat i wybierz z listy…" aria-describedby="<?= $pfx ?>_session_help">
    <button type="button" class="btn btn-outline-secondary" title="Wybierz dzisiejszą lekcję"
            onclick="dydFillToday('<?= $pfx ?>')"
            <?= $_today_sid ? '' : 'disabled' ?>>
      <i class="bi bi-calendar-check"></i> Dzisiaj
    </button>
  </div>
  <input type="hidden" name="session_id" id="<?= $pfx ?>_session" value="<?= $selId ?: '' ?>">
  <div class="form-text" id="<?= $pfx ?>_session_help">Zacznij pisać, aby wyszukać lekcję. Puste pole = bez powiązania.</div>
<?php };

// ── Formularze renderowane w wyskakujących okienkach (dodawanie + edycja) ─────
// $r = wiersz do edycji lub null (dodawanie). $pfx = unikalny prefiks id pól/modalu.

$lessonFormHtml = function(?array $r, string $pfx) use ($cur_course, $course) {
    $isEdit  = (bool)$r;
    $isPast  = $isEdit && isset($r['lesson_date']) && $r['lesson_date'] < date('Y-m-d');
    // Plan nauczania kursu (= sylabus zrealizowany w tym kursie, patrz curriculum.php)
    // — źródło podpowiedzi dla tematu lekcji, żeby nie wpisywać go ręcznie za każdym razem.
    $_curr = k30_ti_curriculum_list($cur_course, true);
    ?>
  <form method="post">
    <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
    <input type="hidden" name="_op" value="save_lesson">
    <input type="hidden" name="_tab" value="lekcje">
    <input type="hidden" name="course_id" value="<?= $cur_course ?>">
    <input type="hidden" name="session_id" value="<?= (int)($r['id'] ?? 0) ?>">
    <div class="modal-header">
      <h5 class="modal-title" id="<?= $pfx ?>_t"><i class="bi bi-<?= $isEdit?'pencil':'calendar-plus' ?> me-2"></i><?= $isEdit?'Edytuj lekcję':'Nowa lekcja' ?></h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
    </div>
    <div class="modal-body">
      <?php if ($isPast): ?>
      <div class="alert alert-secondary py-2 mb-3 small d-flex align-items-center gap-2">
        <i class="bi bi-lock-fill"></i>
        <span>Lekcja z przeszłości — zmiana terminu niedostępna.</span>
        <input type="hidden" name="lesson_date" value="<?= h($r['lesson_date']) ?>">
        <input type="hidden" name="time_from" value="<?= h($r['time_from'] ?? '') ?>">
        <input type="hidden" name="time_to" value="<?= h($r['time_to'] ?? '') ?>">
      </div>
      <?php else: ?>
      <div class="mb-2">
        <label class="form-label fw-semibold" for="<?= $pfx ?>_date">Data <span class="text-danger">*</span></label>
        <input type="date" class="form-control" id="<?= $pfx ?>_date" name="lesson_date" required value="<?= h($r['lesson_date'] ?? date('Y-m-d')) ?>">
      </div>
      <div class="row g-2">
        <div class="col-6 mb-2">
          <label class="form-label" for="<?= $pfx ?>_from">Od</label>
          <select class="form-select" id="<?= $pfx ?>_from" name="time_from"><?= ti_time_options($r['time_from'] ?? '') ?></select>
        </div>
        <div class="col-6 mb-2">
          <label class="form-label" for="<?= $pfx ?>_to">Do</label>
          <select class="form-select" id="<?= $pfx ?>_to" name="time_to"><?= ti_time_options($r['time_to'] ?? '') ?></select>
        </div>
      </div>
      <?php endif; ?>
      <?php if (!$isPast): ?>
      <div class="mb-2">
        <label class="form-label" for="<?= $pfx ?>_dflag">Pewność terminu</label>
        <select class="form-select" id="<?= $pfx ?>_dflag" name="date_flag">
          <?php foreach (K30_TI_DATE_FLAGS as $_dfk => $_dfv): ?>
          <option value="<?= h($_dfk) ?>" <?= (string)($r['date_flag'] ?? '') === $_dfk ? 'selected' : '' ?>><?= h($_dfv['label']) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="form-text">Widoczne w Planie zajęć obok terminu — niezależne od statusu lekcji.</div>
      </div>
      <?php endif; ?>
      <?php if (!$isPast): $isReserved = $isEdit && ($r['status'] ?? '') === 'reserved'; ?>
      <div class="form-check form-switch mb-2 p-2 rounded" style="background:#EFF6FF">
        <input class="form-check-input" type="checkbox" role="switch"
               id="<?= $pfx ?>_reservation" name="is_reservation" value="1" <?= $isReserved ? 'checked' : '' ?>
               onchange="document.getElementById('<?= $pfx ?>_pesel_wrap').style.display=this.checked?'':'none'; if(this.checked){var d=document.getElementById('<?= $pfx ?>_draft'); if(d) d.checked=false;}">
        <label class="form-check-label" for="<?= $pfx ?>_reservation">
          <i class="bi bi-bookmark-star me-1" aria-hidden="true"></i>To jest rezerwacja terminu (nie ostateczna lekcja)
        </label>
        <div class="form-text mb-0">
          Termin zostaje zablokowany tak samo jak zwykła lekcja (nikt inny go nie zajmie), ale kursanci jej
          nie zobaczą, dopóki nie zostanie potwierdzona przyciskiem „Potwierdź rezerwację” na liście lekcji.
        </div>
        <div class="mt-2" id="<?= $pfx ?>_pesel_wrap" style="display:<?= $isReserved ? '' : 'none' ?>">
          <label class="form-label small mb-1" for="<?= $pfx ?>_pesel">PESEL beneficjenta PFRON (opcjonalnie)</label>
          <?php $_pesel_val = ''; if (preg_match('/PESEL:\s*(\d{11})/', (string)($r['notes'] ?? ''), $_pm)) $_pesel_val = $_pm[1]; ?>
          <input type="text" class="form-control form-control-sm" id="<?= $pfx ?>_pesel" name="pfron_pesel"
                 maxlength="11" inputmode="numeric" placeholder="11 cyfr" value="<?= h($_pesel_val) ?>">
          <div class="form-text mb-0">
            Blokada terminu dla osoby jeszcze bez zapisu w systemie — bez zakładania kursanta. Temat lekcji
            ustawi się automatycznie jako „PFRON-XXX” (3 ostatnie cyfry PESEL), pełny PESEL trafi do notatek.
          </div>
        </div>
      </div>
      <?php $isDraft = $isEdit && ($r['status'] ?? '') === 'draft'; ?>
      <div class="form-check form-switch mb-2 p-2 rounded" style="background:#F9FAFB">
        <input class="form-check-input" type="checkbox" role="switch"
               id="<?= $pfx ?>_draft" name="is_draft" value="1" <?= $isDraft ? 'checked' : '' ?>
               onchange="if(this.checked){var rv=document.getElementById('<?= $pfx ?>_reservation'); if(rv && rv.checked){rv.checked=false; document.getElementById('<?= $pfx ?>_pesel_wrap').style.display='none';}}">
        <label class="form-check-label" for="<?= $pfx ?>_draft">
          <i class="bi bi-pencil-square me-1" aria-hidden="true"></i>Zapisz jako wersję roboczą (szkic)
        </label>
        <div class="form-text mb-0">
          Termin widoczny tylko w panelu, nie liczy się do frekwencji ani rozliczeń — do czasu, aż ktoś
          zmieni status na „Zaplanowana".
        </div>
      </div>
      <?php endif; ?>
      <div class="mb-2">
        <label class="form-label fw-semibold" for="<?= $pfx ?>_topic">Temat lekcji</label>
        <input type="text" class="form-control" id="<?= $pfx ?>_topic" name="topic" list="ti_topic_options"
               value="<?= h($r['topic'] ?? '') ?>" placeholder="np. Podstawy HTML — zacznij pisać, aby wybrać z planu nauczania">
        <?php if ($_curr): ?>
        <div class="form-text">Podpowiedzi z <a href="index.php?course=<?= $cur_course ?>&tab=program" target="_blank">planu nauczania</a> kursu — można też wpisać własny temat.</div>
        <?php endif; ?>
      </div>
      <div class="mb-2">
        <label class="form-label" for="<?= $pfx ?>_notes">Notatki</label>
        <textarea class="form-control" id="<?= $pfx ?>_notes" name="notes" rows="2"><?= h($r['notes'] ?? '') ?></textarea>
      </div>
      <?php $_lm_val = $r['lesson_method'] ?? ''; $_lm_zdalna = in_array($_lm_val, ['zdalna_zoom','zdalna_inne'], true); ?>
      <div class="mb-2">
        <label class="form-label" for="<?= $pfx ?>_method">Metoda lekcji</label>
        <select class="form-select" id="<?= $pfx ?>_method" name="lesson_method"
                onchange="(function(v){var w=document.getElementById('<?= $pfx ?>_meeturl_wrap');w.style.display=(v==='zdalna_zoom'||v==='zdalna_inne')?'':'none';})(this.value)">
          <option value="" <?= $_lm_val===''?'selected':'' ?>>— nie wybrano —</option>
          <option value="stacjonarna" <?= $_lm_val==='stacjonarna'?'selected':'' ?>>Stacjonarna</option>
          <option value="zdalna_zoom" <?= $_lm_val==='zdalna_zoom'?'selected':'' ?>>Zdalna — Zoom</option>
          <option value="zdalna_inne" <?= $_lm_val==='zdalna_inne'?'selected':'' ?>>Zdalna — Inne</option>
        </select>
      </div>
      <div class="mb-2" id="<?= $pfx ?>_meeturl_wrap" style="display:<?= $_lm_zdalna?'':'none' ?>">
        <label class="form-label" for="<?= $pfx ?>_meeturl">
          <i class="bi bi-camera-video me-1 text-primary" aria-hidden="true"></i>Link do spotkania
        </label>
        <input type="url" class="form-control" id="<?= $pfx ?>_meeturl" name="meeting_url"
               value="<?= h($r['meeting_url'] ?? '') ?>" placeholder="https://zoom.us/j/…">
        <div class="form-text">Link widoczny kursantom — pojawi się przycisk „Dołącz" przed lekcją.</div>
      </div>
      <div class="mb-2">
        <label class="form-label" for="<?= $pfx ?>_room">
          <i class="bi bi-geo-alt me-1" aria-hidden="true"></i>Sala / lokalizacja
        </label>
        <select class="form-select" id="<?= $pfx ?>_room" name="room_id">
          <option value="0">— nie wybrano —</option>
          <?php foreach (pl_rooms_list(['is_active' => 1]) as $_room): ?>
          <option value="<?= (int)$_room['id'] ?>" <?= (int)($r['room_id'] ?? 0) === (int)$_room['id'] ? 'selected' : '' ?>>
            <?= h($_room['name']) ?><?= trim((string)$_room['location']) !== '' ? ' — ' . h($_room['location']) : '' ?>
          </option>
          <?php endforeach; ?>
        </select>
        <div class="form-text">Sale zarządzane w <a href="sale.php" target="_blank">wykazie sal</a>.</div>
      </div>
      <div class="border rounded p-2 mb-2 bg-body-tertiary">
        <div class="form-check form-switch mb-1">
          <input class="form-check-input" type="checkbox" role="switch"
                 id="<?= $pfx ?>_hw" name="has_homework" value="1"
                 <?= !empty($r['has_homework']) ? 'checked' : '' ?>>
          <label class="form-check-label" for="<?= $pfx ?>_hw">
            <i class="bi bi-pencil-square me-1 text-warning" aria-hidden="true"></i>Zadano zadanie domowe
          </label>
        </div>
        <div class="form-check form-switch mb-0">
          <input class="form-check-input" type="checkbox" role="switch"
                 id="<?= $pfx ?>_spr" name="self_prep_remote" value="1"
                 <?= !empty($r['self_prep_remote']) ? 'checked' : '' ?>>
          <label class="form-check-label" for="<?= $pfx ?>_spr">
            <i class="bi bi-laptop me-1 text-info" aria-hidden="true"></i>Praca prowadzącego — przygotowanie materiałów
          </label>
        </div>
      </div>
      <?php if (dyd_is_staff()): $_les_instrs = k30_ti_instructors(); ?>
      <div class="mb-2">
        <label class="form-label" for="<?= $pfx ?>_instr">
          Prowadzący <span class="text-body-secondary fw-normal small">(zastępstwo — opcjonalnie, wpływa na wypłatę)</span>
        </label>
        <select class="form-select" id="<?= $pfx ?>_instr" name="instructor_id">
          <option value="0">— domyślny: <?= h($course['instructor_name'] ?? '') ?: 'brak przypisania' ?> —</option>
          <?php foreach ($_les_instrs as $ins): ?>
          <option value="<?= (int)$ins['id'] ?>" <?= (int)($r['instructor_id'] ?? 0) === (int)$ins['id'] ? 'selected' : '' ?>><?= h($ins['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-check mb-2">
        <input class="form-check-input" type="checkbox" id="<?= $pfx ?>_skipavail" name="skip_availability" value="1">
        <label class="form-check-label" for="<?= $pfx ?>_skipavail">
          Nie sprawdzaj dostępności prowadzącego
        </label>
        <div class="form-text mb-0">
          Zapisz mimo zdefiniowanych okien dostępności prowadzącego dla tego terminu — np. wyjątkowa zgoda
          albo pilne zastępstwo poza zwykłymi godzinami. Pozostałe blokady (Zoom, sala, zamknięty okres) działają normalnie.
        </div>
      </div>
      <?php endif; ?>
      <?php
        // Realizowane punkty planu nauczania — progressive disclosure (rozwijane),
        // natywny multi-select dla pełnej obsługi klawiaturą i czytnikiem ekranu.
        // ($_curr pobrane wcześniej — patrz podpowiedzi tematu lekcji powyżej.)
        $_sel = $isEdit ? k30_ti_session_curriculum_ids((int)$r['id']) : [];
      ?>
      <div class="mb-2">
        <?php if ($_curr): ?>
        <details<?= $_sel ? ' open' : '' ?>>
          <summary class="form-label fw-semibold mb-1" style="cursor:pointer">
            Realizowane punkty planu <span class="badge bg-secondary"><?= count($_sel) ?></span>
          </summary>
          <div class="form-text mb-1" id="<?= $pfx ?>_curr_hint">Zaznacz punkty planu realizowane na tej lekcji. Klawiatura: strzałki + spacja (wielokrotny wybór).</div>
          <?php $_bySec = []; foreach ($_curr as $ci) { $_bySec[(string)$ci['section']][] = $ci; } ?>
          <select class="form-select" name="curriculum_ids[]" id="<?= $pfx ?>_curr" multiple
                  size="<?= min(10, max(4, count($_curr))) ?>"
                  aria-describedby="<?= $pfx ?>_curr_hint" aria-label="Realizowane punkty planu nauczania">
            <?php foreach ($_bySec as $sec=>$list): ?>
            <optgroup label="<?= h($sec !== '' ? $sec : 'Bez działu') ?>">
              <?php foreach ($list as $ci): ?>
              <option value="<?= (int)$ci['id'] ?>" <?= in_array((int)$ci['id'],$_sel,true)?'selected':'' ?>><?= h($ci['title']) ?></option>
              <?php endforeach; ?>
            </optgroup>
            <?php endforeach; ?>
          </select>
        </details>
        <?php else: ?>
        <div class="form-text"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Brak zdefiniowanego planu nauczania dla tego kursu — punkty planu doda administrator.</div>
        <?php endif; ?>
      </div>
      <?php if ($isEdit && !dyd_is_admin()): ?>
      <div class="mb-1 small text-body-secondary">
        <i class="bi bi-lock me-1" aria-hidden="true"></i>Status lekcji zmienia tylko administrator — lekcja staje się odbyta po zapisaniu obecności.
      </div>
      <?php endif; ?>
      <?php if ($isEdit && dyd_is_admin()): ?>
      <div class="mb-1">
        <label class="form-label" for="<?= $pfx ?>_status">Status</label>
        <select class="form-select" id="<?= $pfx ?>_status" name="status"
                onchange="document.getElementById('<?= $pfx ?>_maturl_wrap').style.display=(this.value==='remote_material')?'':'none'">
          <option value="planned" <?= ($r['status']??'')==='planned'?'selected':'' ?>>Zaplanowana</option>
          <option value="held" <?= ($r['status']??'')==='held'?'selected':'' ?>>Odbyła się</option>
          <option value="individual_change" <?= ($r['status']??'')==='individual_change'?'selected':'' ?>>Odbyła się (zmieniony skład / indywidualnie)</option>
          <option value="remote_material" <?= ($r['status']??'')==='remote_material'?'selected':'' ?>>Praca prowadzącego (materiał zdalny)</option>
        </select>
        <?php if (($r['status']??'')==='cancelled'): ?><div class="form-text text-warning">Lekcja odwołana — zapis zmieni status.</div><?php endif; ?>
      </div>
      <div class="mb-1" id="<?= $pfx ?>_maturl_wrap" style="display:<?= ($r['status']??'')==='remote_material'?'':'none' ?>">
        <label class="form-label" for="<?= $pfx ?>_maturl">
          <i class="bi bi-link-45deg me-1 text-info" aria-hidden="true"></i>Link do materiału
        </label>
        <input type="url" class="form-control" id="<?= $pfx ?>_maturl" name="material_url"
               value="<?= h($r['material_url'] ?? '') ?>" placeholder="https://…">
        <div class="form-text">Link do dokumentu, pliku lub zasobu online — widoczny na liście lekcji.</div>
      </div>
      <?php elseif (!$isEdit): ?>
      <div class="form-check form-switch mb-1">
        <input class="form-check-input" type="checkbox" name="notify" id="<?= $pfx ?>_notify" value="1">
        <label class="form-check-label" for="<?= $pfx ?>_notify">Powiadom kursantów SMS o nowych zajęciach</label>
      </div>
      <?php endif; ?>
    </div>
    <div class="modal-footer">
      <?php if (!$isEdit): ?>
      <button type="button" class="btn btn-outline-secondary me-auto"
              data-bs-dismiss="modal"
              data-bs-toggle="modal" data-bs-target="#addRecurring"
              title="Dodaj zajęcia stałe (cykliczne)">
        <i class="bi bi-arrow-repeat me-1"></i>Cykliczne
      </button>
      <?php endif; ?>
      <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
      <button type="submit" class="btn btn-primary"><?= $isEdit?'Zapisz zmiany':'Dodaj lekcję' ?></button>
    </div>
  </form>
<?php };

// Sprawdzanie obecności na lekcji — lista zapisanych kursantów z polami wyboru.
$attFormHtml = function(array $s, array $rows, string $pfx) use ($cur_course) {
    $total   = count($rows);
    $present = 0;
    $active  = 0; // kursanci bez odwołania/no-show — mogą być zaznaczani
    foreach ($rows as $r) {
        if ((int)$r['attended'] === 1) $present++;
        $canc = (int)($r['cancelled'] ?? 0) === 1;
        $ns   = !$canc && (int)($r['no_show'] ?? 0) === 1;
        if (!$canc && !$ns) $active++;
    }
    $time_str = '';
    if (!empty($s['time_from'])) {
        $time_str = substr((string)$s['time_from'], 0, 5);
        if (!empty($s['time_to'])) $time_str .= '–' . substr((string)$s['time_to'], 0, 5);
    }
    $pct = $active > 0 ? round($present / $active * 100) : 0;
    ?>
<style>
  .att-row{border-radius:.5rem;transition:background .12s;}
  .att-row-present{background:rgba(22,163,74,.10)!important;}
  .att-row-noshow{background:rgba(234,179,8,.08)!important;opacity:.85;}
  .att-row-cancelled{background:rgba(100,116,139,.07)!important;opacity:.75;}
  [data-bs-theme=dark] .att-row-present{background:rgba(22,163,74,.14)!important;}
  [data-bs-theme=dark] .att-row-noshow{background:rgba(234,179,8,.10)!important;}
  .att-cb{width:1.35rem;height:1.35rem;cursor:pointer;flex-shrink:0;}
  .att-cb:checked{accent-color:#16a34a;}
  .att-name{font-size:.95rem;line-height:1.2;}
  .att-progress-bar{height:6px;border-radius:3px;background:rgba(100,116,139,.18);}
  .att-progress-fill{height:6px;border-radius:3px;background:#16a34a;transition:width .2s;}
  .att-hint{font-size:.78rem;color:var(--bs-secondary-color);}
</style>
  <form method="post" id="<?= $pfx ?>_form">
    <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
    <input type="hidden" name="_op" value="save_attendance">
    <input type="hidden" name="_tab" value="lekcje">
    <input type="hidden" name="course_id" value="<?= $cur_course ?>">
    <input type="hidden" name="session_id" value="<?= (int)$s['id'] ?>">
    <div class="modal-header pb-2">
      <div class="flex-grow-1 me-3">
        <h5 class="modal-title mb-0" id="<?= $pfx ?>_t">
          <i class="bi bi-people-fill me-2 text-primary" aria-hidden="true"></i><?= date('j.m.Y', strtotime($s['lesson_date'])) ?>
          <?php if ($time_str): ?><span class="text-body-secondary fw-normal ms-1 small"><?= h($time_str) ?></span><?php endif; ?>
        </h5>
        <?php if (!empty($s['topic'])): ?>
        <div class="text-body-secondary small mt-1 text-truncate" style="max-width:340px" title="<?= h($s['topic']) ?>"><?= h($s['topic']) ?></div>
        <?php endif; ?>
      </div>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
    </div>

    <div class="modal-body pt-3 pb-2">

      <?php if (($s['status'] ?? '') === 'remote_material'): ?>
      <div class="alert alert-info d-flex align-items-start gap-2 mb-0" role="alert">
        <i class="bi bi-person-workspace fs-5 flex-shrink-0 mt-1" aria-hidden="true"></i>
        <div>
          <strong>Praca prowadzącego</strong><br>
          Wszyscy zapisani kursanci są automatycznie traktowani jako obecni — nie jest wymagane ręczne sprawdzanie listy.
        </div>
      </div>

      <?php elseif (!$rows): ?>
      <div class="text-center py-4 text-body-secondary">
        <i class="bi bi-person-x fs-2 d-block mb-2"></i>
        Brak zapisanych kursantów w tym kursie.
      </div>

      <?php else: ?>

      <!-- Pasek postępu -->
      <div class="mb-3">
        <div class="d-flex justify-content-between align-items-center mb-1">
          <span class="small fw-semibold">
            <i class="bi bi-check2-circle text-success me-1" aria-hidden="true"></i>
            Obecni: <span id="<?= $pfx ?>_cnt"><?= $present ?></span> / <?= $active ?>
            <?php if ($total > $active): ?><span class="text-body-secondary">(<?= $total - $active ?> bez frekwencji)</span><?php endif; ?>
          </span>
          <div class="d-flex gap-2">
            <button type="button" class="btn btn-outline-success btn-sm att-all-present py-0" data-pfx="<?= $pfx ?>"
                    title="Zaznacz wszystkich kursantów jako obecnych">
              <i class="bi bi-check-all me-1"></i>Wszyscy obecni
            </button>
            <button type="button" class="btn btn-outline-secondary btn-sm att-none py-0" data-pfx="<?= $pfx ?>"
                    title="Odznacz wszystkich">
              <i class="bi bi-square me-1"></i>Wyczyść
            </button>
          </div>
        </div>
        <div class="att-progress-bar">
          <div class="att-progress-fill" id="<?= $pfx ?>_bar" style="width:<?= $pct ?>%"></div>
        </div>
      </div>

      <!-- Instrukcja -->
      <div class="d-flex align-items-center gap-2 mb-3 px-1">
        <span class="att-hint"><i class="bi bi-info-circle me-1"></i>
          <strong>Zaznaczenie = obecny</strong> — zaznacz wszystkich, którzy uczestniczyli w zajęciach. Niezaznaczeni zostaną odnotowani jako nieobecni.
        </span>
      </div>

      <!-- Lista kursantów -->
      <div class="d-flex flex-column gap-2" id="<?= $pfx ?>_list">
        <?php foreach ($rows as $r):
          $cid  = (int)$r['client_id'];
          $canc = (int)($r['cancelled']     ?? 0) === 1;
          $pend = (int)($r['cancel_pending'] ?? 0) === 1;
          $ns   = !$canc && (int)($r['no_show'] ?? 0) === 1;
          $att  = (int)$r['attended'] === 1;
          $rowCls = $ns ? 'att-row-noshow' : ($canc ? 'att-row-cancelled' : ($att ? 'att-row-present' : ''));
        ?>
        <div class="att-row d-flex align-items-center gap-3 px-3 py-2 border rounded <?= $rowCls ?>" data-pfx="<?= $pfx ?>">
          <?php if ($canc || $ns): ?>
            <!-- odwołany / nie pojawił się — checkbox nieaktywny, tylko badge + cofnij -->
            <span class="att-cb-placeholder" style="width:1.35rem;flex-shrink:0"></span>
            <span class="att-name flex-grow-1 text-body-secondary"><?= h($r['client_name']) ?></span>
            <?php if ($ns): ?>
            <span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">
              <i class="bi bi-dash-circle me-1"></i>nie pojawił się
            </span>
            <?php else: ?>
            <span class="badge bg-secondary-subtle text-secondary-emphasis border border-secondary-subtle">
              <i class="bi bi-x-circle me-1"></i>odwołany
            </span>
            <?php endif; ?>
            <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 flex-shrink-0"
                    title="Przywróć udział" onclick="dydRestoreAtt(<?= (int)$s['id'] ?>,<?= $cid ?>)">
              <i class="bi bi-arrow-counterclockwise"></i>
            </button>

          <?php else: ?>
            <!-- normalny kursant — checkbox + opcjonalne akcje -->
            <input class="att-cb form-check-input" type="checkbox" name="attended[]" value="<?= $cid ?>"
                   id="<?= $pfx ?>_cb<?= $cid ?>"
                   <?= $att ? 'checked' : '' ?>
                   data-pfx="<?= $pfx ?>"
                   aria-label="<?= h($r['client_name']) ?> — obecny">
            <label for="<?= $pfx ?>_cb<?= $cid ?>" class="att-name flex-grow-1 mb-0" style="cursor:pointer">
              <?= h($r['client_name']) ?>
              <?php if ($pend): ?>
              <span class="badge text-bg-warning ms-1 small"><i class="bi bi-hourglass-split me-1"></i>prośba o odwołanie</span>
              <?php endif; ?>
            </label>
            <!-- stan: "obecny" chip pokazuje się gdy zaznaczony -->
            <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle att-present-badge <?= $att ? '' : 'd-none' ?>">
              <i class="bi bi-check2-circle me-1"></i>obecny
            </span>
            <!-- akcje dodatkowe -->
            <div class="dropdown flex-shrink-0">
              <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2 dropdown-toggle dropdown-toggle-split"
                      data-bs-toggle="dropdown" aria-expanded="false" title="Dodatkowe opcje"
                      style="--bs-btn-padding-x:.4rem">
                <span class="visually-hidden">Więcej</span>
              </button>
              <ul class="dropdown-menu dropdown-menu-end">
                <li>
                  <button type="button" class="dropdown-item text-warning-emphasis"
                          onclick="dydNoShow(<?= (int)$s['id'] ?>,<?= $cid ?>,<?= htmlspecialchars(json_encode($r['client_name']), ENT_QUOTES) ?>)">
                    <i class="bi bi-dash-circle me-2"></i>Nie pojawił się (no-show)
                  </button>
                </li>
                <li>
                  <button type="button" class="dropdown-item text-danger"
                          onclick="dydCancelAtt(<?= (int)$s['id'] ?>,<?= $cid ?>)">
                    <i class="bi bi-x-circle me-2"></i>Odwołaj udział
                  </button>
                </li>
              </ul>
            </div>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>

      <p class="att-hint mt-3 mb-0">Zapisanie zmieni status lekcji na <em>odbyła się</em>. Kursanci z odwołanym udziałem nie są wliczani do frekwencji ani ceny.</p>
      <?php endif; ?>
    </div>

    <div class="modal-footer">
      <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Zamknij</button>
      <?php if ($rows && ($s['status'] ?? '') !== 'remote_material'): ?>
      <button type="submit" class="btn btn-primary">
        <i class="bi bi-check2-square me-1"></i>Zapisz obecność
      </button>
      <?php endif; ?>
    </div>
  </form>
<?php };

$hwFormHtml = function(?array $r, string $pfx) use ($cur_course, $dtv, $sessionPicker) {
    $isEdit = (bool)$r; ?>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
    <input type="hidden" name="_op" value="save_homework">
    <input type="hidden" name="_tab" value="zadania">
    <input type="hidden" name="course_id" value="<?= $cur_course ?>">
    <input type="hidden" name="homework_id" value="<?= (int)($r['id'] ?? 0) ?>">
    <div class="modal-header">
      <h5 class="modal-title" id="<?= $pfx ?>_t"><i class="bi bi-<?= $isEdit?'pencil':'journal-plus' ?> me-2"></i><?= $isEdit?'Edytuj zadanie':'Nowe zadanie' ?></h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
    </div>
    <div class="modal-body">
      <div class="mb-2">
        <label class="form-label fw-semibold" for="<?= $pfx ?>_title">Tytuł <span class="text-danger">*</span></label>
        <input type="text" class="form-control" id="<?= $pfx ?>_title" name="title" required value="<?= h($r['title'] ?? '') ?>" placeholder="np. Ćwiczenie 1 — formularz HTML">
      </div>
      <div class="mb-2">
        <label class="form-label" for="<?= $pfx ?>_desc">Polecenie / opis</label>
        <textarea class="form-control" id="<?= $pfx ?>_desc" name="description" rows="3"><?= h($r['description'] ?? '') ?></textarea>
      </div>
      <div class="mb-2">
        <label class="form-label" for="<?= $pfx ?>_hint"><i class="bi bi-lightbulb me-1" aria-hidden="true"></i>Podpowiedź <span class="text-body-secondary small">(opc.)</span></label>
        <textarea class="form-control" id="<?= $pfx ?>_hint" name="hint" rows="2" placeholder="Wskazówka dla kursanta"><?= h($r['hint'] ?? '') ?></textarea>
      </div>
      <div class="mb-2">
        <label class="form-label" for="<?= $pfx ?>_session_txt">Powiązana lekcja <span class="text-body-secondary small">(opc.)</span></label>
        <?php $sessionPicker($pfx, (int)($r['session_id'] ?? 0)); ?>
      </div>
      <div class="mb-2">
        <label class="form-label" for="<?= $pfx ?>_due">Termin oddania <span class="text-body-secondary small">(opc.)</span></label>
        <input type="datetime-local" class="form-control" id="<?= $pfx ?>_due" name="due_at" value="<?= $dtv($r['due_at'] ?? '') ?>">
      </div>
      <div class="row g-2">
        <div class="col-6 mb-2">
          <label class="form-label" for="<?= $pfx ?>_open">Otwarcie <span class="text-body-secondary small">(opc.)</span></label>
          <input type="datetime-local" class="form-control" id="<?= $pfx ?>_open" name="open_at" value="<?= $dtv($r['open_at'] ?? '') ?>">
        </div>
        <div class="col-6 mb-2">
          <label class="form-label" for="<?= $pfx ?>_close">Zamknięcie <span class="text-body-secondary small">(opc.)</span></label>
          <input type="datetime-local" class="form-control" id="<?= $pfx ?>_close" name="close_at" value="<?= $dtv($r['close_at'] ?? '') ?>">
        </div>
      </div>
      <div class="mb-2">
        <label class="form-label" for="<?= $pfx ?>_attach">Załącznik <span class="text-body-secondary small">(opc., maks. 25 MB)</span></label>
        <input type="file" class="form-control" id="<?= $pfx ?>_attach" name="attach">
        <?php if (!empty($r['attach_name'])): ?><div class="form-text">Obecny: <?= h($r['attach_name']) ?> (prześlij nowy, aby zastąpić)</div><?php endif; ?>
      </div>
      <?php if ($isEdit): ?>
      <div class="form-check form-switch mb-2">
        <input class="form-check-input" type="checkbox" name="is_active" id="<?= $pfx ?>_act" <?= $r['is_active']?'checked':'' ?>>
        <label class="form-check-label" for="<?= $pfx ?>_act">Widoczne dla kursantów</label>
      </div>
      <?php endif; ?>
      <div class="form-check form-switch mb-1">
        <input class="form-check-input" type="checkbox" name="notify" id="<?= $pfx ?>_notify" value="1">
        <label class="form-check-label" for="<?= $pfx ?>_notify">Powiadom kursantów (e-mail / SMS)</label>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
      <button type="submit" class="btn btn-primary"><?= $isEdit?'Zapisz zmiany':'Dodaj zadanie' ?></button>
    </div>
  </form>
<?php };

$oc_pick_available = owncloud_admin_configured() && owncloud_instructor_account($uid) !== null;
$matFormHtml = function(?array $r, string $pfx) use ($cur_course, $TYPES, $dtv, $sessionPicker, $oc_pick_available) {
    $isEdit = (bool)$r; ?>
  <form method="post" enctype="multipart/form-data">
    <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
    <input type="hidden" name="_op" value="save_material">
    <input type="hidden" name="_tab" value="materialy">
    <input type="hidden" name="course_id" value="<?= $cur_course ?>">
    <input type="hidden" name="material_id" value="<?= (int)($r['id'] ?? 0) ?>">
    <div class="modal-header">
      <h5 class="modal-title" id="<?= $pfx ?>_t"><i class="bi bi-<?= $isEdit?'pencil':'collection' ?> me-2"></i><?= $isEdit?'Edytuj materiał':'Nowy materiał' ?></h5>
      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
    </div>
    <div class="modal-body">
      <div class="mb-2">
        <label class="form-label fw-semibold" for="<?= $pfx ?>_type">Typ <span class="text-danger">*</span></label>
        <select class="form-select" id="<?= $pfx ?>_type" name="type" required>
          <?php foreach ($TYPES as $slug=>$ti): ?>
          <option value="<?= h($slug) ?>" <?= ($r['type']??'zadanie')===$slug?'selected':'' ?>><?= h($ti['label']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="mb-2">
        <label class="form-label fw-semibold" for="<?= $pfx ?>_title">Tytuł <span class="text-danger">*</span></label>
        <input type="text" class="form-control" id="<?= $pfx ?>_title" name="title" required value="<?= h($r['title'] ?? '') ?>" placeholder="np. Dokumentacja HTML — MDN">
      </div>
      <div class="mb-2">
        <label class="form-label" for="<?= $pfx ?>_desc">Opis</label>
        <textarea class="form-control" id="<?= $pfx ?>_desc" name="description" rows="3"><?= h($r['description'] ?? '') ?></textarea>
      </div>
      <div class="mb-2">
        <label class="form-label" for="<?= $pfx ?>_session_txt">Powiązana lekcja <span class="text-body-secondary small">(opc.)</span></label>
        <?php $sessionPicker($pfx, (int)($r['session_id'] ?? 0)); ?>
      </div>
      <div class="mb-2">
        <label class="form-label" for="<?= $pfx ?>_url">Link (URL) <span class="text-body-secondary small">(opc.)</span></label>
        <input type="url" class="form-control" id="<?= $pfx ?>_url" name="url" value="<?= h($r['url'] ?? '') ?>" placeholder="https://…">
      </div>
      <div class="mb-2">
        <label class="form-label" for="<?= $pfx ?>_attach">Plik <span class="text-body-secondary small">(opc., maks. 25 MB)</span></label>
        <input type="file" class="form-control" id="<?= $pfx ?>_attach" name="attach">
        <?php if (!empty($r['attach_name'])): ?><div class="form-text">Obecny: <?= h($r['attach_name']) ?> (prześlij nowy, aby zastąpić)</div><?php endif; ?>
        <input type="hidden" name="cloud_stored" id="<?= h($pfx) ?>_cloud_stored" value="">
        <input type="hidden" name="cloud_name"   id="<?= h($pfx) ?>_cloud_name" value="">
        <div id="<?= h($pfx) ?>_cloud_sel" class="form-text" style="display:none">
          <i class="bi bi-check-circle-fill text-success me-1"></i>
          Wybrano z chmury: <span id="<?= h($pfx) ?>_cloud_sel_name" class="fw-semibold"></span>
          <button type="button" class="btn btn-link btn-sm p-0 text-danger ms-2" onclick="cloudClearPick('<?= h($pfx) ?>')">Usuń</button>
        </div>
        <div class="d-flex gap-1 flex-wrap mt-1">
          <?php if ($oc_pick_available): ?>
          <button type="button" class="btn btn-sm btn-outline-secondary" onclick="cloudOpenOC('<?= h($pfx) ?>')">
            <i class="bi bi-hdd-network me-1"></i>ownCloud
          </button>
          <?php endif; ?>
          <button type="button" class="btn btn-sm btn-outline-primary" onclick="cloudOpenURL('<?= h($pfx) ?>')">
            <i class="bi bi-cloud-arrow-down me-1"></i>Pobierz z URL
          </button>
        </div>
      </div>
      <div class="row g-2">
        <div class="col-6 mb-2">
          <label class="form-label" for="<?= $pfx ?>_open">Otwarcie <span class="text-body-secondary small">(opc.)</span></label>
          <input type="datetime-local" class="form-control" id="<?= $pfx ?>_open" name="open_at" value="<?= $dtv($r['open_at'] ?? '') ?>">
        </div>
        <div class="col-6 mb-2">
          <label class="form-label" for="<?= $pfx ?>_close">Zamknięcie <span class="text-body-secondary small">(opc.)</span></label>
          <input type="datetime-local" class="form-control" id="<?= $pfx ?>_close" name="close_at" value="<?= $dtv($r['close_at'] ?? '') ?>">
        </div>
      </div>
      <?php if ($isEdit): ?>
      <div class="form-check form-switch mb-2">
        <input class="form-check-input" type="checkbox" name="is_active" id="<?= $pfx ?>_act" <?= $r['is_active']?'checked':'' ?>>
        <label class="form-check-label" for="<?= $pfx ?>_act">Widoczne dla kursantów</label>
      </div>
      <?php endif; ?>
      <div class="form-check form-switch mb-1">
        <input class="form-check-input" type="checkbox" name="notify" id="<?= $pfx ?>_notify" value="1">
        <label class="form-check-label" for="<?= $pfx ?>_notify">Powiadom kursantów (e-mail / SMS)</label>
      </div>
    </div>
    <div class="modal-footer">
      <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
      <button type="submit" class="btn btn-primary"><?= $isEdit?'Zapisz zmiany':'Dodaj materiał' ?></button>
    </div>
  </form>
<?php };

$KP_TITLE  = 'Panel dydaktyka';

// Selektor grupy w pasku górnym (tylko gdy >1 kurs)
$_dyd_course_switcher = '';
if (count($courses) + count($courses_archived) > 1) {
    // Selektor grupy w stylu paska: przycisk „GRUPA ▾ nazwa” na granacie,
    // panel z wyszukiwarką (nazwa grupy / prowadzący) i listą linków — zmiana =
    // ta sama zakładka w wybranej grupie. Kierownik z własnymi grupami: podział
    // Twoje / innych. Klawiatura: ↓/↑ po liście, Enter, Esc (Bootstrap dropdown).
    $split_view = dyd_is_staff() && !empty($my_course_ids_set);
    $cur_c = null;
    foreach (array_merge($courses, $courses_archived) as $_c) if ((int)$_c['id'] === $cur_course) { $cur_c = $_c; break; }
    $_arch_ids = array_flip(array_map(fn($c) => (int)$c['id'], $courses_archived));
    $_live     = array_values(array_filter($courses, fn($c) => !isset($_arch_ids[(int)$c['id']])));
    $_item = function (array $c, bool $with_instructor) use ($cur_course, $tab): string {
        $act      = (int)$c['id'] === $cur_course;
        $archived = ($c['status'] ?? '') === 'archived';
        $closed   = $archived && !empty($c['closed_at']);
        $inactive = $archived || ($c['status'] ?? '') === 'cancelled' || empty($c['is_active']);
        $meta     = $closed ? 'zamknięta' : ($archived ? 'archiwum' : ($inactive ? 'nieaktywna' : ''));
        $meta     = $meta !== '' ? $meta
                  : ($with_instructor ? (string)($c['instructor_name'] ?: '—') : (int)($c['enrolled_count'] ?? 0) . ' os.');
        $search   = mb_strtolower((string)$c['name'] . ' ' . (string)($c['instructor_name'] ?? ''));
        return '<li' . ($archived ? ' class="dyd-cs-arch" hidden' : '') . '><a class="dropdown-item dyd-cs-item d-flex align-items-center gap-2' . ($act ? ' active' : '') . ($inactive ? ' dyd-cs-off' : '') . '"'
             . ' href="index.php?course=' . (int)$c['id'] . '&amp;tab=' . h($tab) . '" data-search="' . h($search) . '"' . ($act ? ' aria-current="true"' : '') . '>'
             . '<i class="bi bi-' . ($act ? 'check2' : ($closed ? 'lock' : ($inactive ? 'archive' : 'people'))) . ' flex-shrink-0" aria-hidden="true"></i>'
             . '<span class="text-truncate">' . h((string)$c['name']) . '</span>'
             . '<span class="ms-auto small dyd-cs-meta flex-shrink-0">' . h($meta) . '</span></a></li>';
    };
    ob_start(); ?>
<div class="dropdown dyd-cs">
  <button type="button" class="dyd-cs-btn dropdown-toggle" data-bs-toggle="dropdown" data-bs-auto-close="outside"
          aria-expanded="false" aria-haspopup="true" title="Zmień grupę">
    <span class="dyd-cs-lbl">Grupa</span>
    <span class="dyd-cs-name text-truncate"><?= h($cur_c ? (string)$cur_c['name'] : 'wybierz grupę') ?></span>
    <span class="visually-hidden">— zmień grupę</span>
  </button>
  <div class="dropdown-menu dropdown-menu-end dyd-cs-menu p-0">
    <div class="p-2 border-bottom">
      <label for="dydCsSearch" class="visually-hidden">Szukaj grupy</label>
      <input type="search" id="dydCsSearch" class="form-control form-control-sm" placeholder="Szukaj grupy lub prowadzącego…" autocomplete="off">
    </div>
    <ul class="list-unstyled mb-0 dyd-cs-list" role="list">
      <?php if ($split_view): ?>
      <li><h6 class="dropdown-header">Twoje grupy</h6></li>
      <?php foreach ($_live as $_c) if (isset($my_course_ids_set[(int)$_c['id']])) echo $_item($_c, false); ?>
      <li><h6 class="dropdown-header">Grupy innych prowadzących</h6></li>
      <?php foreach ($_live as $_c) if (!isset($my_course_ids_set[(int)$_c['id']])) echo $_item($_c, true); ?>
      <?php else: foreach ($_live as $_c) echo $_item($_c, dyd_is_staff()); endif; ?>
      <?php if ($courses_archived): $_arch_open = isset($_arch_ids[$cur_course]); ?>
      <li class="dyd-cs-arch-toggle-li"><button type="button" class="dropdown-item dyd-cs-arch-toggle d-flex align-items-center gap-2" aria-expanded="false">
        <i class="bi bi-archive" aria-hidden="true"></i>Archiwum <span class="badge text-bg-secondary"><?= count($courses_archived) ?></span>
        <i class="bi bi-chevron-down ms-auto" aria-hidden="true"></i></button></li>
      <?php foreach ($courses_archived as $_c) echo $_item($_c, dyd_is_staff()); ?>
      <?php endif; ?>
      <li class="dyd-cs-empty px-3 py-2 small text-body-secondary" hidden>Brak grup pasujących do wyszukiwania.</li>
    </ul>
  </div>
</div>
<script>
(function () {
  var root = document.currentScript.previousElementSibling;
  var q = root.querySelector('#dydCsSearch'), items = root.querySelectorAll('.dyd-cs-item'),
      empty = root.querySelector('.dyd-cs-empty'), heads = root.querySelectorAll('.dropdown-header'),
      tog = root.querySelector('.dyd-cs-arch-toggle'), arch = root.querySelectorAll('.dyd-cs-arch'), archOpen = false;
  function setArch(open) {
    archOpen = open; arch.forEach(function (li) { li.hidden = !open; });
    if (tog) { tog.setAttribute('aria-expanded', open ? 'true' : 'false'); tog.querySelector('.bi-chevron-down, .bi-chevron-up').className = 'bi bi-chevron-' + (open ? 'up' : 'down') + ' ms-auto'; }
  }
  if (tog) {
    tog.addEventListener('click', function (e) { e.preventDefault(); setArch(!archOpen); });
    if (root.querySelector('.dyd-cs-arch .dyd-cs-item.active')) setArch(true);   // bieżąca grupa jest w archiwum
  }
  root.addEventListener('shown.bs.dropdown', function () {
    q.focus(); var a = root.querySelector('.dyd-cs-item.active'); if (a) a.scrollIntoView({block: 'nearest'});
  });
  q.addEventListener('input', function () {
    var t = q.value.trim().toLowerCase(), n = 0;
    items.forEach(function (a) {
      var li = a.parentElement, inArch = li.classList.contains('dyd-cs-arch');
      var ok = t ? a.dataset.search.indexOf(t) !== -1 : (!inArch || archOpen);   // szukanie obejmuje też archiwum
      li.hidden = !ok; if (ok) n++;
    });
    heads.forEach(function (h) { h.parentElement.hidden = !!t; });
    if (tog) tog.parentElement.hidden = !!t;
    empty.hidden = n > 0;
  });
  q.addEventListener('keydown', function (e) {
    if (e.key === 'ArrowDown') { var f = root.querySelector('.dyd-cs-list li:not([hidden]) .dyd-cs-item'); if (f) { e.preventDefault(); f.focus(); } }
    if (e.key === 'Enter') { var v = root.querySelectorAll('.dyd-cs-list li:not([hidden]) .dyd-cs-item'); if (v.length === 1) { e.preventDefault(); location.href = v[0].href; } }
  });
})();
</script>
<?php $_dyd_course_switcher = ob_get_clean();
}

// Rola (kierownik / prowadzący / w zastępstwie) pokazuje się w menu użytkownika
// w pasku górnym — patrz dyd_topbar_enrich() w _nav.php.

// ── Pełnoekranowe potwierdzenie nieprzeczytanych komunikatów ─────────────────
// Komunikat placówki, którego prowadzący nie odczytał, zatrzymuje wejście do
// panelu: pokazujemy go na całą stronę i wymagamy potwierdzenia. Wyjątkiem jest
// sama zakładka „Komunikaty" (tam też się czyta) — inaczej nie dałoby się ich
// przejrzeć pojedynczo.
$_unread_notices = array_values(array_filter($dyd_notices, fn($n) => empty($n['is_read'])));
if ($_unread_notices && $tab !== 'komunikaty' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $KP_TITLE  = 'Nowy komunikat';
    $KP_TOPBAR = ['brand'=>'Panel dydaktyka','icon'=>'easel2','user'=>$me['name'] ?? '','logout'=>'logout.php'];
    if ($DYD_UI === 'usos') $KP_BODY_CLASS = trim(($KP_BODY_CLASS ?? '') . ' dyd-usos ti-skin');
    include dirname(__DIR__) . '/kursant/_layout_head.php';
    $_n_cnt = count($_unread_notices);
    ?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file(__DIR__ . '/../assets/ti_skin.css') ? (int)filemtime(__DIR__ . '/../assets/ti_skin.css') : 1 ?>">
<main id="main" class="container py-4" style="max-width:820px">
  <div class="card mb-3">
    <div class="card-header d-flex align-items-center gap-2">
      <i class="bi bi-megaphone-fill" aria-hidden="true"></i>
      <span><?= $_n_cnt === 1 ? 'Nowy komunikat placówki' : 'Nowe komunikaty placówki' ?></span>
      <span class="badge bg-secondary ms-1"><?= (int)$_n_cnt ?></span>
    </div>
    <div class="card-body">
      <p class="small text-body-secondary mb-0">
        <?= $_n_cnt === 1
            ? 'Zanim przejdziesz do panelu, zapoznaj się z komunikatem.'
            : 'Zanim przejdziesz do panelu, zapoznaj się z komunikatami.' ?>
        Potwierdzenie oznacza <?= $_n_cnt === 1 ? 'go' : 'je' ?> jako przeczytane —
        treść zostaje dostępna w zakładce „Komunikaty".
      </p>
    </div>
  </div>

  <?php foreach ($_unread_notices as $_n): ?>
  <div class="card mb-3">
    <div class="card-header d-flex align-items-center gap-2">
      <?php if (!empty($_n['is_pinned'])): ?><i class="bi bi-pin-fill text-warning" title="Przypięty" aria-hidden="true"></i><?php endif; ?>
      <span><?= h($_n['title']) ?></span>
      <span class="ms-auto small fw-normal text-body-secondary">
        <?= h(date('d.m.Y H:i', strtotime((string)$_n['created_at']))) ?><?= !empty($_n['author_name']) ? ' · ' . h($_n['author_name']) : '' ?>
      </span>
    </div>
    <div class="card-body">
      <?php if (trim((string)$_n['body']) !== ''): ?>
      <div style="white-space:pre-wrap"><?= h($_n['body']) ?></div>
      <?php else: ?>
      <div class="text-body-secondary small">(komunikat bez treści)</div>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>

  <form method="post" action="index.php">
    <input type="hidden" name="_token" value="<?= h(dyd_token()) ?>">
    <input type="hidden" name="_op" value="mark_all_notices">
    <input type="hidden" name="back_tab" value="<?= h($tab) ?>">
    <?php if ($cur_course): ?><input type="hidden" name="course_id" value="<?= (int)$cur_course ?>"><?php endif; ?>
    <div class="d-flex gap-2 flex-wrap align-items-center">
      <button class="btn btn-primary">
        <i class="bi bi-check2-circle me-1" aria-hidden="true"></i>
        Potwierdzam przeczytanie<?= $_n_cnt > 1 ? ' wszystkich' : '' ?> i przechodzę do panelu
      </button>
      <a href="index.php?tab=komunikaty" class="btn btn-outline-secondary">Otwórz zakładkę Komunikaty</a>
      <a href="logout.php" class="btn btn-link">Wyloguj</a>
    </div>
  </form>
</main>
    <?php
    $KP_SKIP_TAB_MEMORY = true;
    include dirname(__DIR__) . '/kursant/_layout_foot.php';
    exit;
}

if ($DYD_UI === 'usos') $KP_BODY_CLASS = trim(($KP_BODY_CLASS ?? '') . ' dyd-usos ti-skin');

$KP_TOPBAR = [
    'brand'         => 'Panel dydaktyka',
    'icon'          => 'easel2',
    'user'          => $me['name'] ?? '',
    'logout'        => 'logout.php',
    'notifications' => $_dyd_course_switcher,   // rola/kontekst — w menu użytkownika (_nav.php)
];
$KP_FULLCALENDAR = ($tab === 'lekcje');   // kalendarz zmiany terminu — tylko w Zajęciach (_tab_lekcje.php)
include dirname(__DIR__) . '/kursant/_layout_head.php';
?>
<style>
/* Responsywne wcięcia — treść max ~1100px, wycentrowana, bez białego pasa po prawej */
.dyd-wrap {
  padding-left:  max(1rem, calc((100% - 1100px) / 2));
  padding-right: max(1rem, calc((100% - 1100px) / 2));
}
.dyd-course-pills .nav-link { border:1px solid var(--bs-border-color); }
  .dyd-course-pills .nav-link.active { background:#2563eb; border-color:#2563eb; }
  .badge-soft { background:rgba(37,99,235,.12); color:#93c5fd; border:1px solid rgba(37,99,235,.35); }
  /* ── Synergia-like top navbar override (tylko dydaktyk) ─── */
  header .navbar { background:#1b2e45 !important; border-bottom:none !important; }
  header .navbar .navbar-brand, header .navbar .navbar-brand i { color:#fff !important; }
  header .navbar .btn-outline-secondary { color:rgba(255,255,255,.8) !important; border-color:rgba(255,255,255,.3) !important; }
  header .navbar .btn-outline-secondary:hover { background:rgba(255,255,255,.1) !important; color:#fff !important; }
  header .navbar .btn-outline-primary { border-color:rgba(255,255,255,.5) !important; color:#fff !important; }
  header .navbar .text-body-secondary { color:rgba(255,255,255,.75) !important; }

  /* ── Boczny panel nawigacyjny dydaktyka ──────────────────── */
  .dyd-sidebar {
    position:fixed; left:0; top:56px; bottom:0; width:220px;
    background:#1b2e45; overflow-y:auto; overflow-x:hidden; z-index:100;
    display:flex; flex-direction:column; padding:.5rem 0 1rem;
    border-right:1px solid rgba(255,255,255,.08);
  }
  .dyd-sb-link {
    display:flex; align-items:center; gap:.55rem;
    padding:.55rem 1rem; font-size:.85rem; font-weight:600;
    color:rgba(255,255,255,.8); text-decoration:none;
    border-left:3px solid transparent;
    transition:background .12s, color .12s, border-color .12s;
    white-space:nowrap; overflow:hidden;
  }
  .dyd-sb-link:hover { background:rgba(255,255,255,.1); color:#fff; border-left-color:rgba(255,255,255,.2); }
  .dyd-sb-link.active { background:rgba(255,255,255,.12); color:#fff; font-weight:700; border-left-color:#5bbcff; }
  .dyd-sb-link .dyd-sb-badge { margin-left:auto; flex-shrink:0; }
  .dyd-sb-section {
    padding:.6rem 1rem .2rem; font-size:.67rem; font-weight:700;
    text-transform:uppercase; letter-spacing:.08em; color:rgba(255,255,255,.35);
  }
  .dyd-sb-sep { border-top:1px solid rgba(255,255,255,.1); margin:.35rem 0; }

  /* Sidebar kurs — selektor u góry */
  .dyd-sb-course {
    padding:.65rem 1rem .5rem; border-bottom:1px solid rgba(255,255,255,.12); margin-bottom:.35rem;
  }
  .dyd-sb-course-name {
    font-size:.82rem; font-weight:700; color:#fff;
    white-space:nowrap; overflow:hidden; text-overflow:ellipsis; display:block;
  }
  .dyd-sb-course-sub { font-size:.72rem; color:rgba(255,255,255,.5); }

  /* Sidebar dropdown (item-list w sidebarze) */
  .dyd-sb-dropdown-items { padding-left:1.5rem; }
  .dyd-sb-dropdown-items .dyd-sb-link { padding:.4rem 1rem .4rem .5rem; font-size:.82rem; font-weight:600; }

  /* Podzakładki kursu */
  .dyd-sb-sub { padding:.38rem 1rem .38rem 1.75rem !important; font-size:.79rem !important; font-weight:500 !important; }
  .dyd-sb-sub .bi { font-size:.78rem; }
  /* Guzik zwijania sidebara */
  .dyd-sb-collapse-btn {
    display:flex; align-items:center; justify-content:center; align-self:flex-end;
    width:20px; height:20px; margin:.35rem .45rem .15rem auto;
    background:transparent; border:1px solid rgba(255,255,255,.2); border-radius:3px;
    color:rgba(255,255,255,.45); cursor:pointer; flex-shrink:0;
    transition:background .12s, color .12s;
  }
  .dyd-sb-collapse-btn:hover { background:rgba(255,255,255,.12); color:#fff; }
  /* Mobilny / desktop toggle sidebar */
  @media (min-width:768px) {
    .dyd-content { margin-left:220px; transition:margin-left .22s ease; }
    .dyd-sidebar { transition:width .22s ease; }
    .dyd-sb-hidden .dyd-sidebar { width:0; overflow:hidden; border:none; padding:0; }
    .dyd-sb-hidden .dyd-content { margin-left:0; }
  }
  @media (max-width:767px) {
    .dyd-sidebar { transform:translateX(-220px); transition:transform .22s ease; box-shadow:none; }
    .dyd-sidebar.dyd-sidebar-open { transform:translateX(0); box-shadow:4px 0 24px rgba(0,0,0,.35); }
    .dyd-sb-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:99; }
    .dyd-sb-overlay.show { display:block; }
  }

  /* ── Pływający guzik otwierania sidebara ── */
  #dydSbToggle {
    position:fixed; top:64px; left:8px; z-index:101;
    width:32px; height:32px; padding:0;
    background:#1b2e45; border:1px solid rgba(255,255,255,.22); border-radius:6px;
    color:rgba(255,255,255,.85); display:flex; align-items:center; justify-content:center;
    cursor:pointer; transition:background .12s, opacity .12s; box-shadow:0 2px 8px rgba(0,0,0,.35);
    line-height:1;
  }
  #dydSbToggle:hover { background:#243d5c; color:#fff; }
  @media (min-width:768px) {
    #dydSbToggle { display:none; }
    .dyd-sb-hidden #dydSbToggle { display:flex; }
  }

  /* ── MD3 / Material Design 3 overrides ────────────────────────────────────── */

  /* Cards */
  .dyd-wrap .card {
    border-radius: 12px !important;
    border-color: var(--bs-border-color) !important;
    box-shadow: 0 1px 2px rgba(0,0,0,.06), 0 2px 8px rgba(0,0,0,.04) !important;
  }
  .dyd-wrap .card-header {
    background: transparent !important;
    border-radius: 12px 12px 0 0 !important;
    padding: .7rem 1rem;
  }

  /* Nav tabs → MD3 indicator tabs */
  .dyd-wrap .nav-tabs {
    border-bottom: 1px solid var(--bs-border-color);
    gap: 0;
  }
  .dyd-wrap .nav-tabs .nav-item { margin-bottom: 0; }
  .dyd-wrap .nav-tabs .nav-link {
    border: none !important;
    border-bottom: 2.5px solid transparent !important;
    border-radius: 0 !important;
    padding: .55rem .9rem;
    font-size: .82rem; font-weight: 600;
    color: var(--bs-secondary-color);
    background: none;
    margin-bottom: -1px;
    transition: color .12s, border-color .12s, background .1s;
  }
  .dyd-wrap .nav-tabs .nav-link:hover {
    color: var(--bs-body-color);
    border-bottom-color: var(--bs-border-color) !important;
  }
  .dyd-wrap .nav-tabs .nav-link.active {
    color: #2563eb !important;
    border-bottom-color: #2563eb !important;
    background: none !important;
  }
  [data-bs-theme="dark"] .dyd-wrap .nav-tabs .nav-link.active { color: #93c5fd !important; border-bottom-color: #93c5fd !important; }

  /* Nav pills → MD3 secondary tabs */
  .dyd-wrap .nav-pills .nav-link {
    border-radius: 9999px !important;
    font-weight: 600; font-size: .82rem;
    padding: .35rem .9rem;
    color: var(--bs-secondary-color);
    transition: background .1s, color .1s;
  }
  .dyd-wrap .nav-pills .nav-link.active {
    background: rgba(37,99,235,.12) !important;
    color: #2563eb !important;
  }
  [data-bs-theme="dark"] .dyd-wrap .nav-pills .nav-link.active { background: rgba(147,197,253,.12) !important; color: #93c5fd !important; }

  /* Tables → MD3 data table */
  .dyd-wrap .table { font-size: .875rem; }
  .dyd-wrap .table thead th {
    font-size: .69rem; text-transform: uppercase;
    letter-spacing: .07em; font-weight: 700;
    color: var(--bs-secondary-color);
    background: var(--bs-tertiary-bg);
    border-bottom: 1px solid var(--bs-border-color);
    padding: .5rem .75rem;
  }
  .dyd-wrap .table td {
    padding: .6rem .75rem; vertical-align: middle;
    border-bottom-color: var(--bs-border-color-translucent);
  }
  .dyd-wrap .table tbody tr:last-child td { border-bottom: none; }
  .dyd-wrap .table-hover tbody tr:hover td { background: rgba(37,99,235,.04) !important; }

  /* Buttons → MD3 shapes + states */
  .dyd-wrap .btn                { border-radius: 20px !important; font-weight: 600; }
  .dyd-wrap .btn-sm             { border-radius: 14px !important; }
  .dyd-wrap .btn-lg             { border-radius: 24px !important; }
  .dyd-wrap .btn-primary        { box-shadow: none !important; }
  .dyd-wrap .btn-primary:hover  { box-shadow: 0 1px 3px rgba(37,99,235,.25), 0 2px 8px rgba(37,99,235,.15) !important; }
  .dyd-wrap .btn-close          { border-radius: 50% !important; }

  /* Badges → MD3 chips */
  .dyd-wrap .badge          { border-radius: 6px !important; font-weight: 600; letter-spacing: .02em; }
  .dyd-wrap .badge.rounded-pill { border-radius: 9999px !important; }

  /* Alerts → MD3 banner */
  .dyd-wrap .alert {
    border-radius: 12px !important;
    border-width: 1px;
  }

  /* Modals → MD3 dialogs */
  .modal .modal-content {
    border-radius: 28px !important;
    border: none;
    box-shadow: 0 8px 32px rgba(0,0,0,.18);
  }
  .modal .modal-header {
    border-bottom: none; border-radius: 28px 28px 0 0;
    padding: 1.25rem 1.25rem .5rem;
  }
  .modal .modal-footer {
    border-top: none; padding: .5rem 1.25rem 1.25rem;
  }
  .modal .modal-body { padding: .5rem 1.25rem; }
  .modal .modal-title { font-size: 1.05rem; font-weight: 600; }

  /* Form controls → MD3 outlined */
  .dyd-wrap .form-control,
  .dyd-wrap .form-select {
    border-radius: 8px !important;
    transition: border-color .12s, box-shadow .12s;
  }
  .dyd-wrap .form-control:focus,
  .dyd-wrap .form-select:focus {
    border-color: #2563eb !important;
    box-shadow: 0 0 0 3px rgba(37,99,235,.18) !important;
  }
  [data-bs-theme="dark"] .dyd-wrap .form-control:focus,
  [data-bs-theme="dark"] .dyd-wrap .form-select:focus {
    border-color: #93c5fd !important;
    box-shadow: 0 0 0 3px rgba(147,197,253,.18) !important;
  }

  /* Dropdowns → MD3 */
  .dyd-wrap .dropdown-menu {
    border-radius: 12px !important;
    box-shadow: 0 4px 16px rgba(0,0,0,.12);
    padding: .35rem;
  }
  .dyd-wrap .dropdown-item {
    border-radius: 8px !important;
    padding: .45rem .75rem;
    font-size: .875rem;
  }
  .dyd-wrap .dropdown-item.active,
  .dyd-wrap .dropdown-item:active { border-radius: 8px !important; }

  /* List groups → MD3 */
  .dyd-wrap .list-group-item { border-color: var(--bs-border-color-translucent); }
  .dyd-wrap .list-group { border-radius: 12px !important; overflow: hidden; }

  /* Pagination → MD3 */
  .dyd-wrap .page-link { border-radius: 8px !important; }

  /* Focus ring (WCAG 2.4.11) */
  .dyd-wrap *:focus-visible {
    outline: 3px solid #2563eb !important;
    outline-offset: 2px !important;
    border-radius: 4px !important;
  }
  [data-bs-theme="dark"] .dyd-wrap *:focus-visible { outline-color: #93c5fd !important; }
</style>
<?php /* MDUI 2 (MD3) usunięte 2026-09-29: Pulpit przebudowany na tabele skórki,
         żaden element <mdui-…> nie został — biblioteka ładowała się na próżno. */ ?>

<!-- ── Sidebar dydaktyka ── -->
<?php
$tab_is_course    = in_array($tab, ['lekcje','zadania','materialy','nieobecnosci','program','oceny','testy','egzaminy','rozliczenia','uczestnicy','plan','protokol'], true);
$tab_is_kierownik = in_array($tab, ['rozliczenia','wypłaty','praca_wlasna','grupy','billing','kursy'], true);

// Liczniki podzakładek kursu
$_sb_absent = $cur_course && k30_ti_course_tracks_attendance($cur_course) ? (int)(db_one(
    "SELECT COUNT(*) AS n FROM k30_ti_attendance a
     JOIN k30_ti_sessions s ON s.id=a.session_id
     WHERE s.course_id=? AND s.status IN ('held','individual_change')
       AND COALESCE(a.attended,0)=0 AND COALESCE(a.cancelled,0)=0
       AND COALESCE(a.cancel_pending,0)=0", [$cur_course])['n'] ?? 0) : 0;
$_sb_grades  = $cur_course ? (int)(db_one("SELECT COUNT(*) AS n FROM k30_ti_grades WHERE course_id=?", [$cur_course])['n'] ?? 0) : 0;
$_sb_program = $cur_course ? count(k30_ti_curriculum_list($cur_course)) : 0;
$_sb_testy   = $cur_course ? count(k30_ti_tests_list($cur_course)) : 0;
// Equi Exams — liczba egzaminów kursu i podejść czekających na ocenę
$_sb_egz     = $cur_course ? count(ti_exams_list($cur_course)) : 0;
$_sb_egz_rev = $cur_course ? ti_exam_pending_review_count($cur_course) : 0;
$_sb_roz_debt = 0;
if ($cur_course && dyd_is_staff()) {
    $_sb_roz_debt = (int)(db_one(
        "SELECT COUNT(DISTINCT e.client_id) AS n FROM k30_ti_enrollments e
         JOIN k30_ti_billing b ON b.client_id=e.client_id AND b.status='issued'
         WHERE e.course_id=? AND e.status='active'", [$cur_course])['n'] ?? 0);
}
// [icon, label, count, badge-variant]
$_sb_ctabs = $cur_course ? [
    'lekcje'       => ['calendar-week',   'Lekcje',        count($sessions ?? []), ''],
    'uczestnicy'   => ['people',          'Uczestnicy',    0, ''],
    'plan'         => ['calendar3',       'Plan zajęć',    0, ''],
    'protokol'     => ['card-checklist',  'Protokoły',     0, ''],
    'zadania'      => ['journal-check',   'Zadania',       count($homeworks ?? []),''],
    'materialy'    => ['collection-play', 'Materiały',     count($materials ?? []),''],
    'nieobecnosci' => ['person-x',        'Nieobecności',  $_sb_absent,   $_sb_absent  ? 'danger' : ''],
    'oceny'        => ['journal-bookmark','Oceny',         $_sb_grades,   ''],
    'program'      => ['list-check',      'Sylabus',       $_sb_program,  ''],   // dawniej „Program zajęć"
    'egzaminy'     => ['patch-question',  'Testy i egzaminy', $_sb_egz_rev ?: $_sb_egz, $_sb_egz_rev ? 'warning' : ''],
] : [];
if ($cur_course && dyd_is_staff()) {
    $_sb_ctabs['rozliczenia'] = ['receipt','Rozliczenia', $_sb_roz_debt, $_sb_roz_debt ? 'danger' : ''];
}
?>
<?php if ($DYD_UI === 'usos'):
  // Arkusz skórki linkowany PO bloku <style> panelu — inaczej bazowe reguły
  // .dyd-wrap wygrywałyby przy równej specyficzności (patrz komentarz w ti_skin.css).
  $_skin_css = __DIR__ . '/../assets/ti_skin.css';
?>
<link rel="stylesheet" href="../assets/ti_skin.css?v=<?= is_file($_skin_css) ? (int)filemtime($_skin_css) : 1 ?>">
<?php include __DIR__ . '/_usos_bar.php'; ?>
<?php endif; ?>

<div class="dyd-sb-overlay" id="dydSbOverlay"></div>
<nav class="dyd-sidebar" id="dydSidebar" aria-label="Menu dydaktyka">
  <button class="dyd-sb-collapse-btn" id="dydSbCollapse" title="Zwiń panel" type="button" aria-label="Zwiń panel boczny">
    <i class="bi bi-chevron-left" style="font-size:.7rem" aria-hidden="true"></i>
  </button>

  <div class="dyd-sb-section" style="padding-bottom:.1rem">Mój panel</div>
  <a class="dyd-sb-link <?= $tab==='pulpit'?'active':'' ?>" href="index.php?tab=pulpit"
     <?= $tab==='pulpit'?'aria-current="page"':'' ?>>
    <i class="bi bi-house" aria-hidden="true"></i>Pulpit
  </a>
  <a class="dyd-sb-link <?= $tab==='frekwencja_grup'?'active':'' ?>" href="index.php?tab=frekwencja_grup"
     <?= $tab==='frekwencja_grup'?'aria-current="page"':'' ?>>
    <i class="bi bi-bar-chart-steps" aria-hidden="true"></i>Frekwencja grup
  </a>
  <a class="dyd-sb-link <?= $tab==='dostepnosc'?'active':'' ?>" href="index.php?tab=dostepnosc"
     <?= $tab==='dostepnosc'?'aria-current="page"':'' ?>>
    <i class="bi bi-clock-history" aria-hidden="true"></i>Dostępność
    <?php if (isset($my_avail) && count($my_avail) > 0): ?>
    <span class="badge bg-secondary ms-auto" style="font-size:.6rem"><?= count($my_avail) ?></span>
    <?php endif; ?>
  </a>
  <?php if (dyd_plan_cykliczny_enabled()): ?>
  <a class="dyd-sb-link <?= $tab==='cykliczne'?'active':'' ?>" href="index.php?tab=cykliczne"
     <?= $tab==='cykliczne'?'aria-current="page"':'' ?>>
    <i class="bi bi-calendar-week" aria-hidden="true"></i>Plan cykliczny
  </a>
  <?php endif; ?>
  <a class="dyd-sb-link" href="protokoly_moje.php">
    <i class="bi bi-journal-check" aria-hidden="true"></i>Protokoły
    <?php if (!empty($_my_pending_protocols)): ?>
    <span class="badge bg-warning text-dark ms-auto" style="font-size:.6rem"><?= count($_my_pending_protocols) ?></span>
    <?php endif; ?>
  </a>

  <div class="dyd-sb-sep"></div>

  <?php if ($cur_course && $course): ?>
  <?php /* Picker grupy w sidebarze */ ?>
  <div class="dyd-sb-section" style="padding-bottom:.1rem">Kurs</div>
  <?php if (count($courses) > 1): ?>
  <div class="px-2 mb-1">
    <div class="dropdown">
      <button class="btn btn-sm w-100 text-start d-flex align-items-center gap-1 py-1 px-2 dyd-sb-coursebtn"
              style="background:rgba(255,255,255,.1);color:#fff;font-size:.78rem;border:1px solid rgba(255,255,255,.18);border-radius:5px;min-width:0"
              type="button" data-bs-toggle="dropdown" aria-expanded="false">
        <i class="bi bi-people-fill flex-shrink-0" style="font-size:.8rem" aria-hidden="true"></i>
        <span class="text-truncate flex-grow-1"><?= h($course['name']) ?></span>
        <i class="bi bi-chevron-expand flex-shrink-0" style="font-size:.72rem;opacity:.6" aria-hidden="true"></i>
      </button>
      <ul class="dropdown-menu" style="min-width:200px;max-height:60vh;overflow-y:auto">
        <?php foreach ($courses as $_c):
          $isActive = ((int)$_c['id'] === $cur_course);
          $inactive = ($_c['status'] ?? '') === 'cancelled' || empty($_c['is_active']);
        ?>
        <li>
          <a class="dropdown-item d-flex align-items-center gap-2 <?= $isActive?'active':'' ?> <?= $inactive?'text-body-secondary':'' ?>"
             href="index.php?course=<?= (int)$_c['id'] ?>&tab=<?= h($tab_is_course ? $tab : 'lekcje') ?>">
            <i class="bi bi-<?= $isActive?'check2':($inactive?'archive':'circle') ?> flex-shrink-0" aria-hidden="true"></i>
            <span class="text-truncate"><?= h($_c['name']) ?></span>
            <?php if (!$isActive): ?><span class="ms-auto small text-body-secondary flex-shrink-0"><?= (int)($_c['enrolled_count']??0) ?> os.</span><?php endif; ?>
          </a>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </div>
  <?php else: ?>
  <div class="px-3 mb-1 dyd-sb-coursename" style="font-size:.74rem;color:rgba(255,255,255,.5);white-space:nowrap;overflow:hidden;text-overflow:ellipsis"><?= h($course['name']) ?></div>
  <?php endif; ?>

  <?php foreach ($_sb_ctabs as $_ct_key => [$_ct_ico, $_ct_lbl, $_ct_n, $_ct_v]): ?>
  <a class="dyd-sb-link dyd-sb-sub <?= $tab === $_ct_key ? 'active' : '' ?>"
     href="index.php?course=<?= $cur_course ?>&tab=<?= $_ct_key ?>"
     <?= $tab === $_ct_key ? 'aria-current="page"' : '' ?>>
    <i class="bi bi-<?= $_ct_ico ?>" aria-hidden="true"></i><?= $_ct_lbl ?>
    <?php if ($_ct_n > 0): ?>
    <span class="badge bg-<?= $_ct_v ?: 'secondary' ?> ms-auto" style="font-size:.6rem"><?= (int)$_ct_n ?></span>
    <?php endif; ?>
  </a>
  <?php endforeach; ?>

  <a class="dyd-sb-link <?= $tab==='formalnosci'?'active':'' ?>" href="index.php?tab=formalnosci"
     <?= $tab==='formalnosci'?'aria-current="page"':'' ?>>
    <i class="bi bi-file-earmark-text" aria-hidden="true"></i>Formalności
    <?php $active_cnt = count(array_filter($dyd_contracts, fn($c) => in_array($c['status'],['podpisana','w realizacji'],true))); ?>
    <?php if ($active_cnt): ?>
    <span class="badge bg-success ms-auto" style="font-size:.6rem"><?= $active_cnt ?></span>
    <?php endif; ?>
  </a>
  <?php else: ?>
  <div class="dyd-sb-section">Kurs</div>
  <a class="dyd-sb-link" href="index.php?tab=lekcje">
    <i class="bi bi-pc-display" aria-hidden="true"></i>Zajęcia
  </a>
  <?php endif; ?>

  <div class="dyd-sb-sep"></div>
  <div class="dyd-sb-section">Komunikacja</div>

  <a class="dyd-sb-link <?= $tab==='wiadomosci'?'active':'' ?>" href="index.php?tab=wiadomosci"
     <?= $tab==='wiadomosci'?'aria-current="page"':'' ?>>
    <i class="bi bi-envelope" aria-hidden="true"></i>Wiadomości
    <?php if (!empty($dyd_msg_unread_total)): ?>
    <span class="badge bg-danger ms-auto" style="font-size:.6rem"><?= (int)$dyd_msg_unread_total ?></span>
    <?php endif; ?>
  </a>
  <a class="dyd-sb-link <?= $tab==='komunikaty'?'active':'' ?>" href="index.php?tab=komunikaty"
     <?= $tab==='komunikaty'?'aria-current="page"':'' ?>>
    <i class="bi bi-megaphone" aria-hidden="true"></i>Komunikaty
    <?php if (!empty($dyd_notices_unread) && $dyd_notices_unread > 0): ?>
    <span class="badge bg-warning text-dark ms-auto" style="font-size:.6rem"><?= (int)$dyd_notices_unread ?></span>
    <?php endif; ?>
  </a>

  <div class="dyd-sb-sep"></div>
  <div class="dyd-sb-section">Zasoby</div>

  <a class="dyd-sb-link" href="../ext/index.php?as=dyd">
    <i class="bi bi-book" aria-hidden="true"></i>Biblioteka materiałów
  </a>
  <a class="dyd-sb-link <?= $tab==='dysk'?'active':'' ?>" href="index.php?tab=dysk"
     <?= $tab==='dysk'?'aria-current="page"':'' ?>>
    <i class="bi bi-hdd-network" aria-hidden="true"></i>Mój dysk
  </a>
  <a class="dyd-sb-link <?= $tab==='zoom'?'active':'' ?>" href="index.php?tab=zoom"
     <?= $tab==='zoom'?'aria-current="page"':'' ?>>
    <i class="bi bi-camera-video" aria-hidden="true"></i>Zajętość Zoom
  </a>

  <?php if (dyd_is_staff()): ?>
  <?php
    $_kier_badge = 0;
    if ($cur_course) {
        $_kier_badge = (int)(db_one(
            "SELECT COUNT(DISTINCT e.client_id) AS n
             FROM k30_ti_enrollments e
             JOIN k30_ti_billing b ON b.client_id=e.client_id AND b.status='issued'
             WHERE e.course_id=? AND e.status='active'", [$cur_course])['n'] ?? 0);
    }
  ?>
  <div class="dyd-sb-sep"></div>
  <div class="dyd-sb-section">Kierownik</div>

  <a class="dyd-sb-link <?= $tab==='grupy'?'active':'' ?>" href="index.php?tab=grupy"
     <?= $tab==='grupy'?'aria-current="page"':'' ?>>
    <i class="bi bi-grid" aria-hidden="true"></i>Przegląd grup
  </a>
  <?php if ($cur_course): ?>
  <a class="dyd-sb-link <?= $tab==='rozliczenia'?'active':'' ?>"
     href="index.php?course=<?= $cur_course ?>&tab=rozliczenia"
     <?= $tab==='rozliczenia'?'aria-current="page"':'' ?>>
    <i class="bi bi-receipt" aria-hidden="true"></i>Rozliczenia grupy
    <?php if ($_kier_badge): ?><span class="badge bg-danger ms-auto" style="font-size:.6rem"><?= (int)$_kier_badge ?></span><?php endif; ?>
  </a>
  <?php endif; ?>
  <a class="dyd-sb-link <?= $tab==='billing'?'active':'' ?>" href="index.php?tab=billing"
     <?= $tab==='billing'?'aria-current="page"':'' ?>>
    <i class="bi bi-receipt" aria-hidden="true"></i>Rozliczenia kursantów
  </a>
  <a class="dyd-sb-link <?= $tab==='kursy'?'active':'' ?>" href="index.php?tab=kursy"
     <?= $tab==='kursy'?'aria-current="page"':'' ?>>
    <i class="bi bi-mortarboard" aria-hidden="true"></i>Zarządzanie kursami
  </a>
  <a class="dyd-sb-link <?= $tab==='wypłaty'?'active':'' ?>" href="index.php?tab=wypłaty"
     <?= $tab==='wypłaty'?'aria-current="page"':'' ?>>
    <i class="bi bi-wallet2" aria-hidden="true"></i>Wypłaty prowadzących
  </a>
  <a class="dyd-sb-link <?= $tab==='praca_wlasna'?'active':'' ?>" href="index.php?tab=praca_wlasna"
     <?= $tab==='praca_wlasna'?'aria-current="page"':'' ?>>
    <i class="bi bi-person-workspace" aria-hidden="true"></i>Praca własna
  </a>
  <?php /* Żetony, okresy i wyłączenia mieszkały w administracji; prowadzi je
           kierownik, więc są tu — w panelu, bez otwierania nowej karty. */ ?>
  <a class="dyd-sb-link" href="zetony.php">
    <i class="bi bi-coin text-warning" aria-hidden="true"></i>Żetony SZO
  </a>
  <a class="dyd-sb-link" href="okresy.php">
    <i class="bi bi-calendar-range" aria-hidden="true"></i>Okresy nauczania
  </a>
  <a class="dyd-sb-link" href="wylaczenia.php">
    <i class="bi bi-calendar-x" aria-hidden="true"></i>Wyłączenia panelu
  </a>
  <a class="dyd-sb-link" href="wydruki.php" target="_blank" rel="noopener">
    <i class="bi bi-printer" aria-hidden="true"></i>Wydruki i raporty
  </a>
  <a class="dyd-sb-link <?= $tab==='komunikacja'?'active':'' ?>" href="index.php?tab=komunikacja"
     <?= $tab==='komunikacja'?'aria-current="page"':'' ?>>
    <i class="bi bi-send" aria-hidden="true"></i>Komunikacja
  </a>
  <a class="dyd-sb-link" href="../email_templates.php" target="_blank" rel="noopener">
    <i class="bi bi-envelope-paper" aria-hidden="true"></i>Szablony e-mail
  </a>
  <a class="dyd-sb-link" href="../sms_templates.php" target="_blank" rel="noopener">
    <i class="bi bi-chat-left-text" aria-hidden="true"></i>Szablony SMS
  </a>
  <a class="dyd-sb-link" href="../index.php" target="_blank" rel="noopener" style="opacity:.6">
    <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>Pełny panel TI
  </a>
  <?php endif; ?>

  <div class="mt-auto"></div>
  <div class="dyd-sb-sep"></div>
  <button type="button" onclick="window.dydShowFlashPref && window.dydShowFlashPref()"
          class="dyd-sb-link w-100 text-start" style="background:none;border:none;opacity:.55;font-size:.78rem">
    <i class="bi bi-bell" aria-hidden="true"></i>Powiadomienia
  </button>
  <button type="button" onclick="window.dydStartTour && window.dydStartTour()"
          class="dyd-sb-link w-100 text-start" style="background:none;border:none;opacity:.55;font-size:.78rem">
    <i class="bi bi-info-circle" aria-hidden="true"></i>Tour powitalny
  </button>
  <a class="dyd-sb-link <?= $tab==='pomoc'?'active':'' ?>" href="index.php?tab=pomoc"
     <?= $tab==='pomoc'?'aria-current="page"':'' ?>>
    <i class="bi bi-compass" aria-hidden="true"></i>Gdzie co jest
  </a>
  <a class="dyd-sb-link" href="<?= h(rtrim(APP_URL,'/')) ?>/karty30/ti/index.php" style="opacity:.55;font-size:.78rem">
    <i class="bi bi-grid" aria-hidden="true"></i>Pełny moduł TI
  </a>

</nav>

<!-- Pływający guzik otwierania sidebara (mobile zawsze + desktop gdy zwinięty) -->
<button id="dydSbToggle" aria-label="Otwórz menu" aria-expanded="false" aria-controls="dydSidebar" type="button">
  <i class="bi bi-list fs-6" aria-hidden="true"></i>
</button>

<main id="main" class="container-fluid dyd-content dyd-wrap py-4">

  <?php /* h1 przeniesiony do info-bar; widok zachowuje semantykę przez nagłówki sekcji w zakładkach */ ?>

  <?= flash_html() ?>

  <?php if (!empty($cur_course) && ($_cc_row = ti_course_closed((int)$cur_course))): ?>
  <div class="alert alert-dark d-flex align-items-start gap-2" role="status">
    <i class="bi bi-lock-fill fs-5 mt-1 flex-shrink-0" aria-hidden="true"></i>
    <div><?= h(ti_course_closed_msg($_cc_row)) ?></div>
  </div>
  <?php endif; ?>

  <?php if (defined('KURSANT_NEW_UI_ENABLED') && KURSANT_NEW_UI_ENABLED && defined('KURSANT_NEW_UI_URL')): ?>
  <div class="alert alert-info d-flex align-items-start gap-2" role="status" id="dydNewUiBanner">
    <i class="bi bi-stars fs-5 mt-1 flex-shrink-0" aria-hidden="true"></i>
    <div class="flex-grow-1">
      <strong>Testujemy nowy interfejs panelu prowadzącego.</strong>
      Możesz już wypróbować nowocześniejszą wersję — część funkcji może tam jeszcze nie działać w pełni,
      w razie problemów zawsze możesz wrócić tutaj.
      <a href="<?= h(rtrim(KURSANT_NEW_UI_URL, '/') . '/logowanie-prowadzacy') ?>" class="alert-link ms-1">Wypróbuj nowy panel &rarr;</a>
    </div>
    <button type="button" class="btn-close" aria-label="Zamknij komunikat"
            onclick="try{localStorage.setItem('ti_dyd_newui_banner_dismissed','1')}catch(e){}; this.closest('#dydNewUiBanner').remove()"></button>
  </div>
  <script>
  (function(){
    try {
      if (localStorage.getItem('ti_dyd_newui_banner_dismissed') === '1') {
        var el = document.getElementById('dydNewUiBanner');
        if (el) el.remove();
      }
    } catch (e) {}
  })();
  </script>
  <?php endif; ?>

  <?php // „Pracujesz jako: …” + zmiana roli — w pasku górnym (_nav.php → dyd_topbar_enrich) ?>

  <?php // Komunikat o trwających wakacjach (okres typu vacation)
  $ti_vac = ti_current_vacation();
  if ($ti_vac): ?>
  <div class="alert alert-warning d-flex align-items-start gap-2" role="alert">
    <i class="bi bi-sun-fill fs-5 mt-1 flex-shrink-0" aria-hidden="true"></i>
    <div>
      <strong>Trwają wakacje — przerwa w zajęciach</strong> (<?= h($ti_vac['name']) ?>).
      Termin: <strong><?= h(date('d.m.Y', strtotime($ti_vac['date_from']))) ?> – <?= h(date('d.m.Y', strtotime($ti_vac['date_to']))) ?></strong>.
      <div class="small mt-1">Zajęcia wznawiamy <strong><?= h(date('d.m.Y', strtotime($ti_vac['resume_date']))) ?></strong>.
        <?php if (!empty($ti_vac['note'])): ?><span class="d-block"><?= h($ti_vac['note']) ?></span><?php endif; ?></div>
    </div>
  </div>
  <?php endif; ?>

  <?php // Komunikat o zaplanowanej / trwającej nieobecności prowadzącego
  if ($my_leaves): $today = date('Y-m-d');
    foreach ($my_leaves as $lv):
      $current = $lv['date_from'] <= $today;
      $range   = date('d.m.Y', strtotime($lv['date_from']));
      if ($lv['date_to'] !== $lv['date_from']) $range .= ' – ' . date('d.m.Y', strtotime($lv['date_to']));
  ?>
  <div class="alert <?= $current ? 'alert-warning' : 'alert-info' ?> d-flex align-items-start gap-2" role="alert">
    <i class="bi bi-airplane-fill fs-5 mt-1 flex-shrink-0" aria-hidden="true"></i>
    <div>
      <strong><?= $current ? 'Trwa Twoja nieobecność' : 'Masz zaplanowaną nieobecność' ?>
        (<?= h(ti_leave_type_label($lv['type'])) ?>)</strong> — termin <strong><?= h($range) ?></strong>.
      <?php if (!empty($lv['note'])): ?><div class="small mt-1"><?= h($lv['note']) ?></div><?php endif; ?>
      <div class="small mt-1">W tym czasie zaplanuj odwołanie lub przełożenie lekcji.
        <a href="urlopy.php">Nieobecności prowadzących</a>.</div>
    </div>
  </div>
  <?php endforeach; endif; ?>

  <?php if ($tab === 'pulpit'): ?>
  <?php include __DIR__ . '/_tab_pulpit.php'; ?>
  <?php elseif (!$courses): ?>
    <div class="card border-0 shadow-sm"><div class="card-body p-4 text-center text-body-secondary">
      <i class="bi bi-inbox fs-1 d-block mb-2" aria-hidden="true"></i>
      Nie prowadzisz obecnie żadnego kursu. Skontaktuj się z administratorem, aby przypisać Cię jako prowadzącego.
    </div></div>
  <?php else: ?>

  <?php if ($course): ?>
  <div class="d-flex align-items-center gap-2 mb-4 pb-2 border-bottom flex-wrap">
    <h5 class="mb-0 fw-semibold d-flex align-items-center gap-2 me-auto">
      <i class="bi bi-pc-display text-primary" aria-hidden="true"></i>
      <?= h($course['name']) ?>
      <?php if (!empty($course['location'])): ?>
      <span class="text-body-secondary fw-normal small"><i class="bi bi-geo-alt me-1" aria-hidden="true"></i><?= h($course['location']) ?></span>
      <?php endif; ?>
    </h5>
    <span class="badge rounded-pill text-bg-secondary" style="font-size:.72rem;font-weight:500">
      <?= (int)($course['enrolled_count'] ?? 0) ?> kursantów
    </span>
  </div>

    <?php /* ═══════════════════════ LEKCJE ═══════════════════════ */ ?>
    <?php if ($tab === 'lekcje'): ?>
    <?php include __DIR__ . '/_tab_lekcje.php'; ?>
    <?php endif; ?>

    <?php /* ═══════════════════════ ZADANIA ═══════════════════════ */ ?>
    <?php if ($tab === 'zadania'): ?>
    <?php include __DIR__ . '/_tab_zadania.php'; ?>
    <?php endif; /* tab zadania */ ?>

    <?php /* ═══════════════════════ MATERIAŁY ═══════════════════════ */ ?>
    <?php if ($tab === 'materialy'): ?>
    <?php include __DIR__ . '/_tab_materialy.php'; ?>
    <?php endif; ?>

    <?php /* ═══════════════════════ NIEOBECNOŚCI ═══════════════════════ */ ?>
    <?php if ($tab === 'nieobecnosci'): ?>
    <?php include __DIR__ . '/_tab_nieobecnosci.php'; ?>
    <?php endif; ?>

    <?php /* ═══════════════════════ PROGRAM ZAJĘĆ ═══════════════════════ */ ?>
    <?php if ($tab === 'program'): ?>
    <?php include __DIR__ . '/_tab_program.php'; ?>
    <?php endif; ?>

    <?php /* ═══════════════════════ UCZESTNICY (kartoteka grupy) ═══════════════════════ */ ?>
    <?php if ($tab === 'uczestnicy'): ?>
    <?php include __DIR__ . '/_tab_uczestnicy.php'; ?>
    <?php endif; ?>

    <?php /* ═══════════════════════ PLAN ZAJĘĆ ═══════════════════════ */ ?>
    <?php if ($tab === 'plan'): ?>
    <?php include __DIR__ . '/_tab_plan.php'; ?>
    <?php endif; ?>

    <?php /* ═══════════════════════ PROTOKOŁY OCEN ═══════════════════════ */ ?>
    <?php if ($tab === 'protokol'): ?>
    <?php if ($dziennik_off): ?>
      <div class="card">
        <div class="card-body text-center py-5">
          <div class="mb-3" style="font-size:3rem;line-height:1;color:#f59e0b" aria-hidden="true"><i class="bi bi-cone-striped"></i></div>
          <h2 class="h5 fw-bold mb-2">Protokoły są chwilowo niedostępne</h2>
          <p class="mb-2"><?= h(ti_blackout_message($dziennik_off)) ?></p>
          <p class="text-body-secondary small mb-0">Wyłączenie dziennika obejmuje także protokoły zajęć i obowiązuje <?= h(ti_blackout_range_text($dziennik_off)) ?>.</p>
        </div>
      </div>
    <?php else: ?>
    <?php include __DIR__ . '/_tab_protokol.php'; ?>
    <?php endif; ?>
    <?php endif; ?>

    <?php /* ═══════════════════════ OCENY (e-dziennik) ═══════════════════════ */ ?>
    <?php if ($tab === 'oceny'): ?>
    <?php if ($dziennik_off): ?>
      <div class="card border-0 shadow-sm">
        <div class="card-body text-center py-5">
          <div class="mb-3" style="font-size:3rem;line-height:1;color:#f59e0b" aria-hidden="true"><i class="bi bi-cone-striped"></i></div>
          <h2 class="h5 fw-bold mb-2">Dziennik ocen jest chwilowo wyłączony</h2>
          <p class="mb-2"><?= h(ti_blackout_message($dziennik_off)) ?></p>
          <p class="text-body-secondary small mb-0">
            Wyłączenie obowiązuje <?= h(ti_blackout_range_text($dziennik_off)) ?>.
            W tym czasie nie można wystawiać ani zmieniać ocen — pozostałe zakładki działają normalnie.
          </p>
        </div>
      </div>
    <?php else: ?>
    <?php include __DIR__ . '/_tab_oceny.php'; ?>
    <?php endif; ?>
    <?php endif; ?>

    <?php /* ═══════════════════════ ROZLICZENIA (staff/admin) ═══════════════════════ */ ?>
    <?php if ($tab === 'rozliczenia' && dyd_is_staff()): ?>
    <?php include __DIR__ . '/_tab_rozliczenia.php'; ?>
    <?php endif; ?>

  <?php endif; /* $course */ ?>

    <?php /* ═══════════════════════ GDZIE CO JEST ═══════════════════════ */ ?>
    <?php if ($tab === 'pomoc'): ?>
    <?php include __DIR__ . '/_tab_pomoc.php'; ?>
    <?php endif; ?>

    <?php /* ═══════════════════════ DOSTĘPNOŚĆ ═══════════════════════ */ ?>
    <?php if ($tab === 'dostepnosc'): ?>
    <?php include __DIR__ . '/_tab_dostepnosc.php'; ?>

    <?php elseif ($tab === 'zoom'): ?>
    <?php include __DIR__ . '/_tab_zoom.php'; ?>
    <?php endif; ?>

    <?php if ($tab === 'egzaminy'): ?>
    <?php include __DIR__ . '/_tab_egzaminy.php'; ?>
    <?php endif; ?>

    <?php if ($tab === 'testy'): ?>
    <?php include __DIR__ . '/_tab_testy.php'; ?>
    <?php endif; /* testy */ ?>

    <?php if ($tab === 'wiadomosci'): ?>
    <?php include __DIR__ . '/_tab_wiadomosci.php'; ?>
    <?php endif; /* wiadomosci */ ?>

  <?php /* ═══════════════════ KOMUNIKATY ═══════════════════ */ ?>
  <?php if ($tab === 'komunikaty'): ?>
  <?php include __DIR__ . '/_tab_komunikaty.php'; ?>
  <?php endif; /* komunikaty */ ?>

  <?php /* ═══════════════════ FORMALNOŚCI ═══════════════════ */ ?>
  <?php if ($tab === 'formalnosci'): ?>
  <?php include __DIR__ . '/_tab_formalnosci.php'; ?>
  <?php endif; /* formalnosci */ ?>

  <?php /* ═══════════════════ KIEROWNIK ═══════════════════ */ ?>
  <?php if ($tab === 'wypłaty' && dyd_is_staff()): ?>
  <?php include __DIR__ . '/_tab_wypłaty.php'; ?>
  <?php endif; ?>

  <?php if ($tab === 'praca_wlasna' && dyd_is_staff()): ?>
  <?php include __DIR__ . '/_tab_praca_wlasna.php'; ?>
  <?php endif; ?>

  <?php if ($tab === 'grupy' && dyd_is_staff()): ?>
  <?php include __DIR__ . '/_tab_grupy.php'; ?>
  <?php endif; ?>

  <?php if ($tab === 'billing' && dyd_is_staff()): ?>
  <?php include __DIR__ . '/_tab_billing.php'; ?>
  <?php endif; ?>

  <?php if ($tab === 'kursy' && dyd_is_staff()): ?>
  <?php include __DIR__ . '/_tab_kursy.php'; ?>
  <?php endif; ?>

  <?php if ($tab === 'dysk'): ?>
  <?php include __DIR__ . '/_tab_dysk.php'; ?>
  <?php endif; /* dysk */ ?>

  <?php if ($tab === 'frekwencja_grup'): ?>
  <?php include __DIR__ . '/_tab_frekwencja_grup.php'; ?>
  <?php endif; ?>

  <?php if ($tab === 'wydruki'): ?>
  <?php include __DIR__ . '/_tab_wydruki.php'; ?>
  <?php endif; ?>

  <?php if ($tab === 'cykliczne'): ?>
  <?php include __DIR__ . '/_tab_cykliczne.php'; ?>
  <?php endif; /* cykliczne */ ?>

  <?php endif; /* $courses */ ?>

  <?php if ($tab === 'komunikacja' && dyd_is_staff()): ?>
  <?php include __DIR__ . '/_tab_komunikacja.php'; ?>
  <?php endif; /* komunikacja */ ?>

  <!-- Wspólna lista lekcji dla wyszukiwarek „Powiązana lekcja" -->
  <datalist id="dyd-session-list">
    <?php foreach ($session_opts as $o): ?>
    <option data-id="<?= (int)$o['id'] ?>" value="<?= h($o['label']) ?>"></option>
    <?php endforeach; ?>
  </datalist>

</main>
<script>
// Kopiowanie adresu kanału iCal do schowka.
document.addEventListener('click', function(e){
  var b = e.target.closest('[data-copy-target]'); if (!b) return;
  var inp = document.getElementById(b.getAttribute('data-copy-target')); if (!inp) return;
  var done = function(){
    var orig = b.innerHTML;
    b.innerHTML = '<i class="bi bi-check2 me-1"></i>Skopiowano';
    setTimeout(function(){ b.innerHTML = orig; }, 1500);
  };
  inp.focus(); inp.select();
  if (navigator.clipboard && navigator.clipboard.writeText) {
    navigator.clipboard.writeText(inp.value).then(done, function(){ try { document.execCommand('copy'); done(); } catch(_){} });
  } else { try { document.execCommand('copy'); done(); } catch(_){} }
});

// ── Logika okna obecności ───────────────────────────────────────────────────
(function(){
  // Aktualizacja licznika i paska postępu + klasa wiersza
  function attUpdate(pfx) {
    var list = document.getElementById(pfx + '_list'); if (!list) return;
    var boxes = list.querySelectorAll('input[name="attended[]"]');
    var cnt = 0;
    boxes.forEach(function(cb){
      var row   = cb.closest('.att-row');
      var badge = row ? row.querySelector('.att-present-badge') : null;
      if (cb.checked) {
        cnt++;
        if (row)   { row.classList.add('att-row-present'); }
        if (badge) { badge.classList.remove('d-none'); }
      } else {
        if (row)   { row.classList.remove('att-row-present'); }
        if (badge) { badge.classList.add('d-none'); }
      }
    });
    var cntEl = document.getElementById(pfx + '_cnt');
    if (cntEl) cntEl.textContent = cnt;
    var bar = document.getElementById(pfx + '_bar');
    if (bar) {
      var total = boxes.length;
      bar.style.width = total > 0 ? Math.round(cnt / total * 100) + '%' : '0%';
    }
  }

  // Zmiana checkboxa
  document.addEventListener('change', function(e){
    var cb = e.target; if (!cb.matches('input[name="attended[]"]')) return;
    var pfx = cb.getAttribute('data-pfx'); if (!pfx) return;
    attUpdate(pfx);
  });

  // „Wszyscy obecni"
  document.addEventListener('click', function(e){
    var b = e.target.closest('.att-all-present'); if (!b) return;
    var pfx = b.getAttribute('data-pfx'); if (!pfx) return;
    var list = document.getElementById(pfx + '_list'); if (!list) return;
    list.querySelectorAll('input[name="attended[]"]:not(:disabled)').forEach(function(cb){ cb.checked = true; });
    attUpdate(pfx);
  });

  // „Wyczyść"
  document.addEventListener('click', function(e){
    var b = e.target.closest('.att-none'); if (!b) return;
    var pfx = b.getAttribute('data-pfx'); if (!pfx) return;
    var list = document.getElementById(pfx + '_list'); if (!list) return;
    list.querySelectorAll('input[name="attended[]"]:not(:disabled)').forEach(function(cb){ cb.checked = false; });
    attUpdate(pfx);
  });

  // Inicjalizacja po otwarciu modalu (wyrównaj stan)
  document.addEventListener('shown.bs.modal', function(e){
    var form = e.target.querySelector('form[id]');
    if (!form) return;
    var pfx = form.id.replace('_form', '');
    attUpdate(pfx);
  });
})();

// Odwołanie / przywrócenie udziału kursanta (z modalu obecności) — przez ukryty formularz.
function dydCancelAtt(sid, cid) {
  if (!confirm('Odwołać udział tego kursanta? Nie będzie liczony do ceny.')) return;
  document.getElementById('daa_op').value = 'cancel_attendee';
  document.getElementById('daa_sid').value = sid;
  document.getElementById('daa_cid').value = cid;
  document.getElementById('dydAttAction').submit();
}
function dydRestoreAtt(sid, cid) {
  if (!confirm('Przywrócić udział tego kursanta?')) return;
  document.getElementById('daa_op').value = 'restore_attendee';
  document.getElementById('daa_sid').value = sid;
  document.getElementById('daa_cid').value = cid;
  document.getElementById('dydAttAction').submit();
}

function dydNoShow(sid, cid, name) {
  document.getElementById('dns_sid').value = sid;
  document.getElementById('dns_cid').value = cid;
  document.getElementById('dns_name').textContent = name || '';
  document.getElementById('dns_full').checked = true;
  var r = document.getElementById('dns_reason'); if (r) r.value = '';
  var f = document.getElementById('dns_screenshot'); if (f) f.value = '';
  new bootstrap.Modal(document.getElementById('dydNoShowModal')).show();
}
// Odwołanie całej lekcji — otwiera modal z powodem.
function dydOpenCancelSession(sid, label) {
  document.getElementById('cs_sid').value = sid;
  document.getElementById('cs_label').textContent = label || '';
  var t = document.getElementById('cs_reason'); if (t) t.value = '';
  new bootstrap.Modal(document.getElementById('cancelSessionModal')).show();
}
// Zmiana terminu lekcji — otwiera modal z bieżącym terminem.
function dydOpenReschedule(sid, label, date, from, to) {
  document.getElementById('rs_sid').value = sid;
  document.getElementById('rs_label').textContent = label || '';
  if (typeof window.tiSetRescheduleDate === 'function') { window.tiSetRescheduleDate(date || ''); }
  else { var d = document.getElementById('rs_date'); if (d) d.value = date || ''; }
  var f = document.getElementById('rs_from'); if (f) f.value = from || '';
  var t = document.getElementById('rs_to');   if (t) t.value = to || '';
  new bootstrap.Modal(document.getElementById('reschedSessionModal')).show();
}
// Usprawiedliwienie nieobecności — otwiera modal z opcjonalnym powodem.
function dydOpenExcuse(sid, cid, label) {
  document.getElementById('ex_sid').value = sid;
  document.getElementById('ex_cid').value = cid;
  document.getElementById('ex_label').textContent = label || '';
  var r = document.getElementById('ex_reason'); if (r) r.value = '';
  new bootstrap.Modal(document.getElementById('excuseAbsenceModal')).show();
}

// Wyszukiwarka „Powiązana lekcja": tekst → ukryte session_id (mapa etykieta→id).
window.DYD_TODAY_SESSION = <?= json_encode($_today_sid ? ['id' => $_today_sid, 'label' => $_today_slbl] : null) ?>;
function dydFillToday(pfx) {
  var s = window.DYD_TODAY_SESSION; if (!s) return;
  var inp = document.getElementById(pfx + '_session_txt');
  var hid = document.getElementById(pfx + '_session');
  if (inp) inp.value = s.label;
  if (hid) hid.value = s.id;
}
(function(){
  var map = {};
  document.querySelectorAll('#dyd-session-list option').forEach(function(o){ map[o.value] = o.getAttribute('data-id'); });
  document.addEventListener('input', function(e){
    var inp = e.target.closest('.dyd-lesson-combo'); if (!inp) return;
    var hid = document.getElementById(inp.getAttribute('data-target')); if (!hid) return;
    hid.value = map[inp.value] || '';
  });
})();

// Filtrowanie list (lekcje / zadania / materiały) po tekście.
document.addEventListener('input', function(e){
  var box = e.target.closest('[data-dyd-filterbox]'); if (!box) return;
  var list = document.getElementById(box.getAttribute('data-dyd-filterbox')); if (!list) return;
  var q = box.value.toLowerCase().trim();
  var items = list.querySelectorAll('[data-filter-item]');
  var shown = 0;
  items.forEach(function(item){
    if (!item.dataset.filterItem) return; // stały element (komunikat „brak")
    var match = !q || item.textContent.toLowerCase().indexOf(q) !== -1;
    item.style.display = match ? '' : 'none';
    if (match) shown++;
  });
  // Pokaż / ukryj komunikat „brak wyników"
  var msg = list.querySelector('.dyd-filter-empty');
  if (msg) msg.style.display = (q && shown === 0) ? '' : 'none';
});
</script>
<script>
// SMS do grupy — wyzwalany przez dropdown item (form jest ukryty)
document.getElementById('dyd-sms-week-trigger')?.addEventListener('click', function(e) {
  e.preventDefault();
  document.getElementById('dyd-sms-week-form')?.requestSubmit();
});
</script>
<script>
(function() {
  var sb     = document.getElementById('dydSidebar');
  var ov     = document.getElementById('dydSbOverlay');
  var btn    = document.getElementById('dydSbToggle');
  var colBtn = document.getElementById('dydSbCollapse');
  if (!sb || !ov || !btn) return;

  function isDesktop() { return window.innerWidth >= 768; }

  // Przywróć stan collapsed na desktopie
  if (isDesktop() && localStorage.getItem('dydSbCollapsed') === '1') {
    document.body.classList.add('dyd-sb-hidden');
    btn.style.display = 'flex';
  }

  function openSb() {
    if (isDesktop()) {
      document.body.classList.remove('dyd-sb-hidden');
      localStorage.removeItem('dydSbCollapsed');
    } else {
      sb.classList.add('dyd-sidebar-open');
      ov.classList.add('show');
    }
    btn.setAttribute('aria-expanded', 'true');
  }
  function closeSb() {
    if (isDesktop()) {
      document.body.classList.add('dyd-sb-hidden');
      localStorage.setItem('dydSbCollapsed', '1');
    } else {
      sb.classList.remove('dyd-sidebar-open');
      ov.classList.remove('show');
    }
    btn.setAttribute('aria-expanded', 'false');
  }

  btn.addEventListener('click', function() {
    var isOpen = isDesktop()
      ? !document.body.classList.contains('dyd-sb-hidden')
      : sb.classList.contains('dyd-sidebar-open');
    isOpen ? closeSb() : openSb();
  });

  if (colBtn) colBtn.addEventListener('click', closeSb);
  ov.addEventListener('click', closeSb);
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape' && !isDesktop() && sb.classList.contains('dyd-sidebar-open')) closeSb();
  });
})();
</script>
<?php include __DIR__ . '/_wizard.php'; ?>
<?php /* Shepherd.js (tour powitalny) doładowywany dopiero przy starcie touru — patrz loadShepherd() niżej */ ?>
<style>
.shepherd-element { font-size:.9rem; }
.shepherd-text { font-size:.875rem; color:var(--bs-body-color); }
.shepherd-header { background:#1b2e45 !important; }
.shepherd-title { color:#fff !important; font-size:.95rem !important; font-weight:700 !important; }
.shepherd-cancel-icon { color:rgba(255,255,255,.7) !important; }
.shepherd-cancel-icon:hover { color:#fff !important; }
.shepherd-button-primary { background:#2563eb !important; border:none !important; border-radius:6px !important; font-size:.82rem !important; }
.shepherd-button-secondary { background:transparent !important; color:var(--bs-secondary-color) !important; border:1px solid var(--bs-border-color) !important; border-radius:6px !important; font-size:.82rem !important; }
.shepherd-has-title .shepherd-content .shepherd-header { border-radius:.4rem .4rem 0 0; }
.shepherd-element { border-radius:.5rem !important; overflow:hidden; box-shadow:0 8px 32px rgba(0,0,0,.25) !important; }
</style>
<script>
(function() {
  // sessionStorage: reset automatycznie przy każdym nowym logowaniu (nowa sesja przeglądarki)
  var SESSION_KEY = 'dydTourShown';

  // Biblioteka touru ładowana na żądanie (nie przy każdym wejściu na stronę)
  function loadShepherd(cb) {
    if (typeof Shepherd !== 'undefined') return cb();
    var base = 'https://cdn.jsdelivr.net/npm/shepherd.js@14/dist/';
    var css = document.createElement('link'); css.rel = 'stylesheet'; css.href = base + 'css/shepherd.css';
    document.head.appendChild(css);
    var js = document.createElement('script'); js.src = base + 'js/shepherd.min.js'; js.onload = cb;
    document.head.appendChild(js);
  }

  function startTour() {
    if (typeof Shepherd === 'undefined') return loadShepherd(startTour);
    var tour = new Shepherd.Tour({
      useModalOverlay: true,
      defaultStepOptions: {
        cancelIcon: { enabled: true },
        scrollTo: { behavior: 'smooth', block: 'center' },
        buttons: [
          { text: 'Wstecz',  action: function() { tour.back();    }, secondary: true },
          { text: 'Dalej →', action: function() { tour.next();    }, classes: 'shepherd-button-primary' },
        ],
        when: { show: function() { sessionStorage.setItem(SESSION_KEY, '1'); } }
      }
    });

    tour.addStep({
      id: 'sidebar',
      title: '📋 Panel nawigacyjny',
      text:  'Ten panel po lewej stronie to Twoje centrum dowodzenia. Znajdziesz tu wszystkie sekcje panelu dydaktyka.',
      attachTo: { element: '#dydSidebar', on: 'right' },
      buttons: [
        { text: 'Pomiń tour', action: function() { tour.cancel(); }, secondary: true },
        { text: 'Dalej →',    action: function() { tour.next();  }, classes: 'shepherd-button-primary' },
      ]
    });

    tour.addStep({
      id: 'pulpit',
      title: '🏠 Pulpit',
      text:  'Pulpit pokazuje dzisiejsze zajęcia i skróty do najważniejszych funkcji.',
      attachTo: { element: '.dyd-sb-link[href*="tab=pulpit"]', on: 'right' }
    });

    tour.addStep({
      id: 'kurs-section',
      title: '📚 Kurs',
      text:  'Sekcja <strong>Kurs</strong> zawiera wszystkie podzakładki wybranej grupy — lekcje, zadania, materiały, oceny i więcej. Liczby przy każdej zakładce pokazują ile wpisów jest w danej sekcji.',
      attachTo: { element: '.dyd-sb-section', on: 'right' }
    });

    var ctab = document.querySelector('.dyd-sb-sub');
    if (ctab) {
      tour.addStep({
        id: 'sub-tabs',
        title: '🗂 Podzakładki grupy',
        text:  'Każda podzakładka to osobny widok — kliknij <strong>Lekcje</strong> żeby zobaczyć kalendarz zajęć, <strong>Zadania</strong> żeby zarządzać pracami domowymi, itd.',
        attachTo: { element: '.dyd-sb-sub', on: 'right' }
      });
    }

    var picker = document.querySelector('.dyd-sb-section + div .dropdown button');
    if (picker) {
      tour.addStep({
        id: 'course-picker',
        title: '👥 Zmiana grupy',
        text:  'Jeśli prowadzisz kilka grup, możesz tu przełączać się między nimi. Podzakładki odświeżają się automatycznie.',
        attachTo: { element: picker, on: 'bottom' }
      });
    }

    tour.addStep({
      id: 'collapse',
      title: '◀ Zwijanie panelu',
      text:  'Klikając <strong>‹</strong> możesz zwinąć panel boczny i zyskać więcej miejsca na treść. Kliknij ikonę ☰ żeby go z powrotem otworzyć.',
      attachTo: { element: '#dydSbCollapse', on: 'right' }
    });

    tour.addStep({
      id: 'komunikacja',
      title: '✉ Komunikacja',
      text:  '<strong>Wiadomości</strong> — wymiana wiadomości z kursantami i kierownictwem.<br><strong>Komunikaty</strong> — ogłoszenia od placówki.',
      attachTo: { element: '.dyd-sb-section:last-of-type', on: 'right' }
    });

    tour.addStep({
      id: 'finish',
      title: '✅ Gotowe!',
      text:  'Znasz już podstawy panelu dydaktyka. Możesz wrócić do tego tour w dowolnym momencie klikając <i class="bi bi-info-circle"></i> w sidebarze.',
      buttons: [
        { text: 'Zakończ', action: function() { tour.complete(); }, classes: 'shepherd-button-primary' }
      ]
    });

    tour.on('complete', function() {
      if (typeof window._dydOnTourComplete === 'function') window._dydOnTourComplete();
    });
    tour.start();
  }

  // Auto-start: przy pierwszej wizycie w tej sesji (logowaniu)
  if (!sessionStorage.getItem(SESSION_KEY)) {
    sessionStorage.setItem(SESSION_KEY, '1');
    setTimeout(startTour, 800);
  }

  // Guzik restartu toura
  window.dydStartTour = startTour;
})();
</script>

<!-- ─── Modal: wybór stylu powiadomień (jednorazowy) ──────────────────── -->
<div class="modal fade" id="dydFlashPrefModal" tabindex="-1"
     aria-labelledby="dfpLbl" aria-modal="true">
  <div class="modal-dialog modal-dialog-centered" style="max-width:420px">
    <div class="modal-content">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title fw-bold" id="dfpLbl">
          <i class="bi bi-bell text-primary me-2" aria-hidden="true"></i>Styl powiadomień
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body py-3">
        <p class="text-body-secondary small mb-3">Wybierz jak chcesz widzieć potwierdzenia operacji (np. „Lekcja dodana"):</p>
        <div class="d-flex gap-3">
          <button type="button" id="dydFlashOptTop"
                  class="btn btn-outline-secondary flex-fill py-3 d-flex flex-column align-items-center gap-2">
            <i class="bi bi-arrow-bar-up" style="font-size:1.9rem" aria-hidden="true"></i>
            <span class="fw-semibold">Pasek u góry</span>
            <span class="text-body-secondary text-center" style="font-size:.74rem">Wąskie powiadomienie,<br>znika automatycznie</span>
          </button>
          <button type="button" id="dydFlashOptModal"
                  class="btn btn-outline-primary flex-fill py-3 d-flex flex-column align-items-center gap-2">
            <i class="bi bi-window-fullscreen" style="font-size:1.9rem" aria-hidden="true"></i>
            <span class="fw-semibold">Okno pośrodku</span>
            <span class="text-body-secondary text-center" style="font-size:.74rem">Duże okno modalne,<br>trzeba zamknąć</span>
          </button>
        </div>
      </div>
      <div class="modal-footer border-0 pt-0 justify-content-center">
        <span class="text-body-secondary" style="font-size:.72rem">
          <i class="bi bi-gear me-1" aria-hidden="true"></i>Zmień kiedy chcesz klikając <strong>Powiadomienia</strong> w sidebarze.
        </span>
      </div>
    </div>
  </div>
</div>

<!-- ─── Modal: flash wyświetlany centralnie ───────────────────────────── -->
<div class="modal fade" id="dydFlashCenter" tabindex="-1" aria-modal="true" aria-live="assertive">
  <div class="modal-dialog modal-dialog-centered" style="max-width:380px">
    <div class="modal-content border-0" style="border-radius:1rem;overflow:hidden">
      <div class="modal-body text-center py-4 px-4">
        <div class="mb-3" id="dydFlashCenterIcon" style="font-size:2.8rem" aria-hidden="true"></div>
        <div id="dydFlashCenterMsg" style="font-size:1.05rem;font-weight:500;line-height:1.5"></div>
      </div>
      <div class="modal-footer border-0 justify-content-center pt-0 pb-3">
        <button type="button" class="btn btn-primary px-5" data-bs-dismiss="modal">OK</button>
      </div>
    </div>
  </div>
</div>

<!-- ─── Floating tour button ──────────────────────────────────────────── -->
<button type="button" id="dydHelpFab"
        onclick="window.dydStartTour && window.dydStartTour()"
        aria-label="Tour powitalny / pomoc"
        title="Tour powitalny">
  <i class="bi bi-question-lg" aria-hidden="true"></i>
</button>
<style>
#dydHelpFab {
  position:fixed; bottom:1.6rem; right:1.6rem; z-index:998;
  width:44px; height:44px; border-radius:50%; border:none;
  background:#2563eb; color:#fff;
  box-shadow:0 3px 14px rgba(37,99,235,.45);
  display:flex; align-items:center; justify-content:center;
  font-size:1.15rem; cursor:pointer;
  transition:background .15s, transform .15s, box-shadow .15s;
}
#dydHelpFab:hover { background:#1d4ed8; transform:scale(1.1); box-shadow:0 4px 18px rgba(37,99,235,.55); }
</style>

<script>
(function() {
  var FLASH_KEY = 'dydFlashStyle_<?= (int)$uid ?>';

  function applyFlashStyle() {
    var style = localStorage.getItem(FLASH_KEY) || 'top';
    if (style !== 'modal') return;
    var wrap = document.getElementById('_flash_wrap');
    if (!wrap) return;
    var toast = wrap.querySelector('#_flash_toast');
    if (!toast) return;
    var iconEl  = toast.querySelector('i.bi');
    var msgEl   = toast.querySelector('span');
    var iconCls = iconEl ? iconEl.className : '';
    var msgHtml = msgEl  ? msgEl.innerHTML  : '';
    var accent = '#2563eb';
    if (iconCls.includes('check-circle'))     accent = '#16a34a';
    else if (iconCls.includes('x-circle'))   accent = '#dc2626';
    else if (iconCls.includes('exclamation'))accent = '#d97706';
    document.getElementById('dydFlashCenterIcon').innerHTML =
      '<i class="' + iconCls + '" style="color:' + accent + '" aria-hidden="true"></i>';
    document.getElementById('dydFlashCenterMsg').innerHTML = msgHtml;
    wrap.remove();
    new bootstrap.Modal(document.getElementById('dydFlashCenter')).show();
  }

  function showFlashPrefChooser() {
    var el = document.getElementById('dydFlashPrefModal');
    if (!el) return;
    var modal = new bootstrap.Modal(el);
    function choose(val) {
      localStorage.setItem(FLASH_KEY, val);
      modal.hide();
      applyFlashStyle();
    }
    document.getElementById('dydFlashOptTop').onclick   = function() { choose('top'); };
    document.getElementById('dydFlashOptModal').onclick = function() { choose('modal'); };
    modal.show();
  }

  // Domyślnie „pasek u góry" (bez wymuszania wyboru) — kto chce okno modalne,
  // wybiera je ręcznie linkiem „Powiadomienia" w sidebarze (dydShowFlashPref()).
  document.addEventListener('DOMContentLoaded', applyFlashStyle);

  window.dydShowFlashPref = showFlashPrefChooser;
})();
</script>

<!-- Modal: ownCloud file picker -->
<div class="modal fade" id="cloudOCModal" tabindex="-1" aria-labelledby="cloudOCModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="cloudOCModalLabel"><i class="bi bi-hdd-network me-2"></i>Wybierz plik z ownCloud</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body p-0">
        <nav aria-label="Breadcrumb" class="px-3 pt-2 pb-1">
          <ol class="breadcrumb mb-0 small" id="cloudOCBreadcrumb"></ol>
        </nav>
        <div id="cloudOCList" class="list-group list-group-flush" style="max-height:50vh;overflow-y:auto"></div>
        <div id="cloudOCSpinner" class="text-center py-4" style="display:none">
          <div class="spinner-border text-secondary" role="status"><span class="visually-hidden">Ładowanie…</span></div>
        </div>
        <div id="cloudOCError" class="alert alert-danger m-3" style="display:none"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
      </div>
    </div>
  </div>
</div>

<!-- Modal: URL import -->
<div class="modal fade" id="cloudURLModal" tabindex="-1" aria-labelledby="cloudURLModalLabel" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="cloudURLModalLabel"><i class="bi bi-cloud-arrow-down me-2"></i>Pobierz plik z URL</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body">
        <div class="mb-2">
          <label class="form-label" for="cloudURLInput">URL pliku <span class="text-danger">*</span></label>
          <input type="url" class="form-control" id="cloudURLInput" placeholder="https://…" autocomplete="off">
        </div>
        <div class="mb-2">
          <label class="form-label" for="cloudURLFilename">Nazwa pliku <span class="text-body-secondary small">(opc.)</span></label>
          <input type="text" class="form-control" id="cloudURLFilename" placeholder="np. dokument.pdf">
          <div class="form-text">Zostaw puste — zostanie pobrana z URL.</div>
        </div>
        <div id="cloudURLError" class="alert alert-danger" style="display:none"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Anuluj</button>
        <button type="button" class="btn btn-primary" id="cloudURLConfirm">
          <i class="bi bi-download me-1"></i>Pobierz i dołącz
        </button>
      </div>
    </div>
  </div>
</div>

<script>
// ── Cloud file picker (ownCloud + URL) ──────────────────────────────────────
(function(){
  var _target   = '';
  var _csrfToken = <?= json_encode(dyd_token()) ?>;

  function cloudPost(data, cb) {
    data._token = _csrfToken;
    var fd = new FormData();
    Object.keys(data).forEach(function(k){ fd.append(k, data[k]); });
    fetch('cloud_pick.php', {method:'POST', body:fd, credentials:'same-origin'})
      .then(function(r){ return r.json(); })
      .then(cb)
      .catch(function(){ cb({ok:false, err:'Błąd połączenia.'}); });
  }

  window.cloudClearPick = function(pfx) {
    document.getElementById(pfx+'_cloud_stored').value = '';
    document.getElementById(pfx+'_cloud_name').value   = '';
    document.getElementById(pfx+'_cloud_sel').style.display = 'none';
    var fi = document.getElementById(pfx+'_attach');
    if (fi) fi.disabled = false;
  };

  function cloudSetPick(pfx, stored, name) {
    document.getElementById(pfx+'_cloud_stored').value = stored;
    document.getElementById(pfx+'_cloud_name').value   = name;
    document.getElementById(pfx+'_cloud_sel_name').textContent = name;
    document.getElementById(pfx+'_cloud_sel').style.display = '';
    var fi = document.getElementById(pfx+'_attach');
    if (fi) fi.disabled = true;
  }

  // ── ownCloud browser ─────────────────────────────────────────────────────
  var ocModal = null, ocPath = '/';

  function ocSetState(state, msg) {
    document.getElementById('cloudOCList').style.display    = state==='list'   ? '' : 'none';
    document.getElementById('cloudOCSpinner').style.display = state==='spin'   ? '' : 'none';
    var el = document.getElementById('cloudOCError');
    el.style.display = state==='err' ? '' : 'none';
    if (state==='err') el.textContent = msg||'Błąd.';
  }

  function ocBreadcrumb(path) {
    var bc = document.getElementById('cloudOCBreadcrumb');
    bc.innerHTML = '';
    function addItem(label, clickPath, active) {
      var li = document.createElement('li');
      li.className = 'breadcrumb-item' + (active ? ' active' : '');
      if (active) { li.textContent = label; }
      else {
        var a = document.createElement('a'); a.href='#'; a.textContent=label;
        a.addEventListener('click', function(e){e.preventDefault(); ocLoad(clickPath);});
        li.appendChild(a);
      }
      bc.appendChild(li);
    }
    var segs = (path||'/').split('/').filter(function(s){return s!=='';});
    addItem('Moje pliki', '/', segs.length===0);
    var built='';
    segs.forEach(function(seg,i){
      built+='/'+seg;
      addItem(seg, built, i===segs.length-1);
    });
  }

  function ocLoad(path) {
    ocPath = path;
    ocSetState('spin');
    cloudPost({act:'oc_list',path:path}, function(res){
      if (!res.ok) { ocSetState('err', res.err); return; }
      ocBreadcrumb(res.path||'/');
      var list = document.getElementById('cloudOCList');
      list.innerHTML = '';
      if (!res.items || res.items.length===0) {
        list.innerHTML='<div class="list-group-item text-body-secondary small py-2 px-3">Folder jest pusty.</div>';
        ocSetState('list'); return;
      }
      res.items.forEach(function(item){
        var a = document.createElement('a');
        a.className='list-group-item list-group-item-action d-flex align-items-center gap-2 py-2';
        a.href='#';
        var icon=document.createElement('i');
        icon.className='bi bi-'+(item.is_dir?'folder-fill text-warning':'file-earmark text-secondary');
        a.appendChild(icon);
        var span=document.createElement('span'); span.className='flex-grow-1'; span.textContent=item.name;
        a.appendChild(span);
        if (!item.is_dir && item.size>0) {
          var sz=document.createElement('small'); sz.className='text-body-secondary';
          sz.textContent=Math.ceil(item.size/1024)+' KB'; a.appendChild(sz);
        }
        a.addEventListener('click', function(e){
          e.preventDefault();
          if (item.is_dir) { ocLoad(item.path); }
          else { ocImport(item.path, item.name); }
        });
        list.appendChild(a);
      });
      ocSetState('list');
    });
  }

  function ocImport(path, name) {
    ocSetState('spin');
    cloudPost({act:'oc_import',path:path}, function(res){
      if (!res.ok) { ocSetState('err', res.err); return; }
      ocModal.hide();
      cloudSetPick(_target, res.stored, res.name||name);
    });
  }

  window.cloudOpenOC = function(pfx) {
    _target = pfx;
    if (!ocModal) ocModal = new bootstrap.Modal(document.getElementById('cloudOCModal'));
    ocLoad('/');
    ocModal.show();
  };

  // ── URL importer ─────────────────────────────────────────────────────────
  var urlModal = null, urlReady = false;

  window.cloudOpenURL = function(pfx) {
    _target = pfx;
    if (!urlModal) urlModal = new bootstrap.Modal(document.getElementById('cloudURLModal'));
    if (!urlReady) {
      urlReady = true;
      document.getElementById('cloudURLConfirm').addEventListener('click', function(){
        var url  = document.getElementById('cloudURLInput').value.trim();
        var name = document.getElementById('cloudURLFilename').value.trim();
        var err  = document.getElementById('cloudURLError');
        err.style.display='none';
        if (!url) { err.textContent='Podaj URL pliku.'; err.style.display=''; return; }
        var btn = document.getElementById('cloudURLConfirm');
        btn.disabled=true;
        btn.innerHTML='<span class="spinner-border spinner-border-sm me-1" role="status"></span>Pobieranie…';
        cloudPost({act:'url_import',url:url,filename:name}, function(res){
          btn.disabled=false;
          btn.innerHTML='<i class="bi bi-download me-1"></i>Pobierz i dołącz';
          if (!res.ok) { err.textContent=res.err||'Błąd.'; err.style.display=''; return; }
          urlModal.hide();
          cloudSetPick(_target, res.stored, res.name||(name||url.split('/').pop()));
        });
      });
    }
    document.getElementById('cloudURLInput').value='';
    document.getElementById('cloudURLFilename').value='';
    document.getElementById('cloudURLError').style.display='none';
    urlModal.show();
  };
})();
</script>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
