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
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_notices.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_periods.php';
require_once dirname(dirname(dirname(__DIR__))) . '/includes/ti_planner.php';

karty30_migrate();
k30_ti_reschedule_migrate();
ti_notices_migrate();
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

$courses   = dyd_courses($uid);
// Dla admina/staff: zestaw ID kursów gdzie sam jest prowadzącym lub co-prowadzącym
$my_course_ids_set = dyd_is_staff()
    ? array_flip(array_column(k30_ti_instructor_courses($uid, false), 'id'))
    : [];
$my_leaves = ti_leaves_for_instructor($uid);   // własne urlopy: trwające + nadchodzące
$my_avail  = ti_instructor_availability($uid);  // własne okna dostępności w tygodniu
$dyd_notices        = ti_notices_list_active_for_instructor($uid);
$dyd_notices_unread = ti_notices_unread_count_instructor($uid);

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
$_sess_key  = 'dyd_course_' . $uid;

// Pobierz kurs: URL → sesja → pierwszy z listy
if (isset($_GET['course'])) {
    $cur_course = (int)$_GET['course'];
    if (in_array($cur_course, $course_ids, true)) {
        $_SESSION[$_sess_key] = $cur_course;  // zapamiętaj wybór
    }
} elseif (!empty($_SESSION[$_sess_key]) && in_array((int)$_SESSION[$_sess_key], $course_ids, true)) {
    $cur_course = (int)$_SESSION[$_sess_key];
} else {
    $cur_course = 0;  // nie ustawiony — pokaż picker (jeśli >1 kurs) lub wybierz jedyny
}
if (!in_array($cur_course, $course_ids, true)) $cur_course = $course_ids[0] ?? 0;

$tab = $_GET['tab'] ?? 'pulpit';
if (!in_array($tab, ['pulpit', 'lekcje', 'zadania', 'materialy', 'nieobecnosci', 'program', 'oceny', 'dostepnosc', 'testy', 'wiadomosci', 'formalnosci', 'komunikaty', 'dysk', 'cykliczne', 'rozliczenia', 'wypłaty', 'praca_wlasna', 'grupy', 'billing', 'kursy'], true)) $tab = 'pulpit';
if (in_array($tab, ['rozliczenia', 'wypłaty', 'praca_wlasna', 'grupy', 'billing', 'kursy'], true) && !dyd_is_staff()) $tab = 'pulpit';

// ── Picker grupy: gdy prowadzący ma >1 kurs i nie wybrał (brak URL + brak sesji) ─
$_force_pick = isset($_GET['pick']);  // ?pick=1 z przycisku "Zmień grupę"
if (($cur_course === 0 || $_force_pick) && count($courses) > 1) {
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

// ── Operacje zapisu ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    dyd_token_check();
    $op        = $_POST['_op'] ?? '';
    $course_id = (int)($_POST['course_id'] ?? 0);
    $back_tab  = in_array($_POST['_tab'] ?? '', ['lekcje','zadania','materialy'], true) ? $_POST['_tab'] : 'lekcje';

    // ── Dostępność prowadzącego (własna, niezależna od kursu) ───────────────────
    if ($op === 'avail_add') {
        $dw       = (int)($_POST['day_of_week'] ?? -1);
        $av_st    = in_array($_POST['status'] ?? '', ['draft','approved'], true) ? $_POST['status'] : 'approved';
        if (!ti_avail_add($uid, $dw, $_POST['time_from'] ?? '', $_POST['time_to'] ?? '', $av_st)) {
            flash_set('danger', 'Podaj poprawny dzień oraz godziny od–do (od < do).');
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

    // Komunikaty placówki — nie wymagają course_id
    if ($op === 'mark_notice') {
        $nid = (int)($_POST['notice_id'] ?? 0);
        if ($nid) ti_notices_mark_read_instructor($nid, $uid);
        header('Location: index.php?tab=komunikaty'); exit;
    }
    if ($op === 'mark_all_notices') {
        ti_notices_mark_all_read_instructor($uid);
        flash_set('success', 'Wszystkie komunikaty oznaczone jako przeczytane.');
        header('Location: index.php?tab=komunikaty'); exit;
    }

    // Pozostałe operacje wymagają własności kursu.
    if (!dyd_owns_course($uid, $course_id)) { http_response_code(403); exit('Brak uprawnień do tego kursu.'); }

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
        $dur   = 60;
        if ($tf && $tt) {
            $m = (strtotime('1970-01-01 ' . $tt) - strtotime('1970-01-01 ' . $tf)) / 60;
            if ($m > 0) $dur = (int)$m;
        }
        if ($date === '') { flash_set('danger', 'Data lekcji jest wymagana.'); header('Location: ' . dyd_back($course_id, 'lekcje')); exit; }

        // Zajęcia tylko w dostępności prowadzącego (gdy zdefiniowana)
        $av = ti_instructor_available_at(ti_course_instructor_id($course_id), $date, $tf, $tt);
        if (!$av['ok']) { flash_set('danger', $av['reason']); header('Location: ' . dyd_back($course_id, 'lekcje')); exit; }

        if ($sid && dyd_owns_session($uid, $sid)) {
            $st = in_array($_POST['status'] ?? '', ['planned','held','remote_material'], true) ? $_POST['status'] : 'planned';
            db()->prepare(
                "UPDATE k30_ti_sessions
                 SET lesson_date=?, time_from=?, time_to=?, duration_min=?, topic=?, notes=?, has_homework=?, self_prep_remote=?, status=?, updated_at=datetime('now')
                 WHERE id=?"
            )->execute([$date, $tf, $tt, $dur, $topic, $notes, $hw, $spr, $st, $sid]);
            k30_ti_session_set_curriculum($sid, (array)($_POST['curriculum_ids'] ?? []));
            if ($st === 'remote_material') {
                // Praca własna prowadzącego = wszyscy obecni bez ręcznego sprawdzania
                db()->prepare("UPDATE k30_ti_attendance SET attended=1 WHERE session_id=? AND COALESCE(cancelled,0)=0 AND COALESCE(no_show,0)=0")->execute([$sid]);
            }
            flash_set('success', 'Lekcja zaktualizowana.');
        } else {
            $sid = db_insert('k30_ti_sessions', [
                'course_id'       => $course_id, 'lesson_date' => $date,
                'time_from'       => $tf, 'time_to' => $tt, 'duration_min' => $dur,
                'status'          => 'planned', 'topic' => $topic, 'notes' => $notes,
                'has_homework'    => $hw, 'self_prep_remote' => $spr,
                'created_by'      => $uid, 'created_at' => date('Y-m-d H:i:s'),
            ]);
            k30_ti_session_set_curriculum($sid, (array)($_POST['curriculum_ids'] ?? []));
            // Wstępna obecność dla aktywnych uczestników
            foreach (db_all("SELECT client_id FROM k30_ti_enrollments WHERE course_id=? AND status='active'", [$course_id]) as $e) {
                try { db_insert('k30_ti_attendance', ['session_id'=>$sid, 'client_id'=>(int)$e['client_id'], 'attended'=>0]); }
                catch (\Throwable $ex) {}
            }
            $msg = 'Lekcja dodana.';
            if (isset($_POST['notify']) && !$spr) {
                $cn = db_one("SELECT name FROM k30_ti_courses WHERE id=?", [$course_id]);
                $when = $date . ($tf !== '' ? ' o ' . $tf : '');
                $n = ti_lesson_sms_notify($course_id, 'Nowe zajecia: ' . ($cn['name'] ?? '') . ' — ' . $when . '. Szczegoly w panelu kursanta.');
                if ($n) $msg .= " Wysłano SMS: {$n}.";
            }
            flash_set('success', $msg);
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

    if ($op === 'sms_week_group') {
        require_once dirname(dirname(dirname(__DIR__))) . '/includes/sms.php';
        if (!sms_is_enabled()) {
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
        $every = max(1, (int)($_POST['weeks'] ?? 1));
        $count = max(1, min(52, (int)($_POST['count'] ?? 1)));
        if ($date === '' || !DateTime::createFromFormat('Y-m-d', $date)) {
            flash_set('danger', 'Podaj poprawną datę startową serii.'); header('Location: ' . dyd_back($course_id, 'lekcje')); exit;
        }
        $dur = 60;
        if ($tf && $tt) { $m = (strtotime('1970-01-01 ' . $tt) - strtotime('1970-01-01 ' . $tf)) / 60; if ($m > 0) $dur = (int)$m; }
        // Cała seria ma ten sam dzień tygodnia i godziny — sprawdzamy raz
        $av = ti_instructor_available_at(ti_course_instructor_id($course_id), $date, $tf, $tt);
        if (!$av['ok']) { flash_set('danger', $av['reason'] . ' Seria nie została utworzona.'); header('Location: ' . dyd_back($course_id, 'lekcje')); exit; }
        $enrollees = db_all("SELECT client_id FROM k30_ti_enrollments WHERE course_id=? AND status='active'", [$course_id]);
        $created = 0;
        for ($i = 0; $i < $count; $i++) {
            $d = date('Y-m-d', strtotime($date . ' +' . ($i * $every) . ' weeks'));
            $sid = db_insert('k30_ti_sessions', [
                'course_id' => $course_id, 'lesson_date' => $d, 'time_from' => $tf, 'time_to' => $tt,
                'duration_min' => $dur, 'status' => 'planned', 'topic' => $topic, 'notes' => '',
                'created_by' => $uid, 'created_at' => date('Y-m-d H:i:s'),
            ]);
            foreach ($enrollees as $e) {
                try { db_insert('k30_ti_attendance', ['session_id' => $sid, 'client_id' => (int)$e['client_id'], 'attended' => 0]); }
                catch (\Throwable $ex) {}
            }
            $created++;
        }
        flash_set('success', "Utworzono serię: {$created} lekcji (co {$every} tyg.).");
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
        $every      = max(1, min(8, (int)($_POST['interval_weeks'] ?? 1)));
        if (!$date_from || !$date_to || $date_to < $date_from) {
            flash_set('danger', 'Podaj poprawny zakres dat.');
            header('Location: ' . dyd_back($course_id, 'lekcje')); exit;
        }
        $dur = 60;
        if ($tf && $tt) { $m = (strtotime('1970-01-01 '.$tt) - strtotime('1970-01-01 '.$tf)) / 60; if ($m > 0) $dur = (int)$m; }
        $rule_id = db_insert('k30_ti_series', [
            'course_id'      => $course_id,
            'time_from'      => $tf,
            'time_to'        => $tt,
            'interval_weeks' => $every,
            'date_from'      => $date_from,
            'date_to'        => $date_to,
            'topic'          => $topic,
            'created_by'     => $uid,
            'created_at'     => date('Y-m-d H:i:s'),
        ]);
        $enrollees = db_all("SELECT client_id FROM k30_ti_enrollments WHERE course_id=? AND status='active'", [$course_id]);
        $d = $date_from;
        $created = 0;
        while ($d <= $date_to && $created < 104) {
            $sid = db_insert('k30_ti_sessions', [
                'course_id' => $course_id, 'lesson_date' => $d, 'time_from' => $tf, 'time_to' => $tt,
                'duration_min' => $dur, 'status' => 'planned', 'topic' => $topic, 'notes' => '',
                'created_by' => $uid, 'created_at' => date('Y-m-d H:i:s'), 'series_id' => $rule_id,
            ]);
            foreach ($enrollees as $e) {
                try { db_insert('k30_ti_attendance', ['session_id' => $sid, 'client_id' => (int)$e['client_id'], 'attended' => 0]); }
                catch (\Throwable $ex) {}
            }
            $d = date('Y-m-d', strtotime($d . " +{$every} weeks"));
            $created++;
        }
        flash_set('success', "Zajęcia stałe dodane: {$created} lekcji (co {$every} tyg.).");
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
                flash_set('info', 'Praca własna prowadzącego — wszyscy kursanci oznaczeni jako obecni.');
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
            $av = ti_instructor_available_at(ti_course_instructor_id($course_id), $date, $tf, $tt);
            if (!$av['ok']) { flash_set('danger', $av['reason']); header('Location: ' . dyd_back($course_id, 'lekcje')); exit; }
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
        if ($req && dyd_owns_session($uid, (int)$req['session_id'])) {
            $accept = $op === 'reschedule_accept';
            if ($accept) {
                $av = ti_instructor_available_at(ti_course_instructor_id($course_id), (string)$req['proposed_date'], (string)$req['proposed_from'], (string)$req['proposed_to']);
                if (!$av['ok']) { flash_set('danger', 'Nie można zaakceptować: ' . $av['reason']); header('Location: ' . dyd_back($course_id, 'lekcje')); exit; }
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
        $raw = (string)($_POST['csv'] ?? '');
        if (trim($raw) === '') { flash_set('danger', 'Wklej dane CSV do zaimportowania.'); header('Location: ' . dyd_back($course_id, 'program')); exit; }
        $res = k30_ti_curriculum_import_csv($course_id, $raw, $uid);
        $msg = 'Zaimportowano pozycji: ' . (int)($res['added'] ?? 0) . '.';
        if (!empty($res['errors'])) $msg .= ' Błędów: ' . count($res['errors']) . '.';
        flash_set(!empty($res['errors']) ? 'warning' : 'success', $msg);
        header('Location: ' . dyd_back($course_id, 'program')); exit;
    }

    // ── OCENY (e-dziennik) ──────────────────────────────────────────────────────
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

        try { $up = k30_ti_homework_upload('attach', 'mat'); }
        catch (\Throwable $e) { flash_set('danger', $e->getMessage()); header('Location: ' . dyd_back($course_id, 'materialy')); exit; }

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

    // ── WIADOMOŚCI ────────────────────────────────────────────────────────────
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
        ti_msg_post_to_student($acc_id, $subject, $body, $uid, $senderName, false);
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
        // Weryfikuj że wiadomość należy do kursanta z kursu tego prowadzącego
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
            ti_admin_msg_send($uid, $senderName, $subject, $body, $toAdminId);
            flash_set('success', 'Wiadomość wysłana.');
        }
        header('Location: index.php?tab=wiadomosci&thread=admin&to_admin=' . $toAdminId); exit;
    }
}

// ── Dane do widoku ──────────────────────────────────────────────────────────
$course   = null;
foreach ($courses as $c) { if ((int)$c['id'] === $cur_course) { $course = $c; break; } }
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
                c.name AS course_name, c.id AS course_id
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

$lessonFormHtml = function(?array $r, string $pfx) use ($cur_course) {
    $isEdit  = (bool)$r;
    $isPast  = $isEdit && isset($r['lesson_date']) && $r['lesson_date'] < date('Y-m-d'); ?>
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
      <div class="mb-2">
        <label class="form-label fw-semibold" for="<?= $pfx ?>_topic">Temat lekcji</label>
        <input type="text" class="form-control" id="<?= $pfx ?>_topic" name="topic" value="<?= h($r['topic'] ?? '') ?>" placeholder="np. Podstawy HTML">
      </div>
      <div class="mb-2">
        <label class="form-label" for="<?= $pfx ?>_notes">Notatki</label>
        <textarea class="form-control" id="<?= $pfx ?>_notes" name="notes" rows="2"><?= h($r['notes'] ?? '') ?></textarea>
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
            <i class="bi bi-laptop me-1 text-info" aria-hidden="true"></i>Praca własna prowadzącego — przygotowanie materiałów
          </label>
        </div>
      </div>
      <?php
        // Realizowane punkty planu nauczania — progressive disclosure (rozwijane),
        // natywny multi-select dla pełnej obsługi klawiaturą i czytnikiem ekranu.
        $_curr = k30_ti_curriculum_list($cur_course, true);
        $_sel  = $isEdit ? k30_ti_session_curriculum_ids((int)$r['id']) : [];
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
      <?php if ($isEdit): ?>
      <div class="mb-1">
        <label class="form-label" for="<?= $pfx ?>_status">Status</label>
        <select class="form-select" id="<?= $pfx ?>_status" name="status">
          <option value="planned" <?= ($r['status']??'')==='planned'?'selected':'' ?>>Zaplanowana</option>
          <option value="held" <?= ($r['status']??'')==='held'?'selected':'' ?>>Odbyła się</option>
          <option value="remote_material" <?= ($r['status']??'')==='remote_material'?'selected':'' ?>>Praca własna prowadzącego (materiał zdalny)</option>
        </select>
        <?php if (($r['status']??'')==='cancelled'): ?><div class="form-text text-warning">Lekcja odwołana — zapis zmieni status.</div><?php endif; ?>
      </div>
      <?php else: ?>
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
          <strong>Praca własna prowadzącego</strong><br>
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

$matFormHtml = function(?array $r, string $pfx) use ($cur_course, $TYPES, $dtv, $sessionPicker) {
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

// Selektor grupy w navbarze (tylko gdy >1 kurs)
$_dyd_course_switcher = '';
if (count($courses) > 1) {
    $cur_course_name = '';
    foreach ($courses as $_c) { if ((int)$_c['id'] === $cur_course) { $cur_course_name = $_c['name']; break; } }
    ob_start(); ?>
<div class="dropdown">
  <button class="btn btn-outline-secondary btn-sm dropdown-toggle d-flex align-items-center gap-1"
          type="button" data-bs-toggle="dropdown" aria-expanded="false"
          style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
          title="Zmień grupę">
    <i class="bi bi-people-fill flex-shrink-0" aria-hidden="true"></i>
    <span class="text-truncate"><?= h($cur_course_name) ?></span>
  </button>
  <ul class="dropdown-menu dropdown-menu-end" style="min-width:220px">
    <li><h6 class="dropdown-header"><i class="bi bi-arrow-left-right me-1"></i>Zmień grupę</h6></li>
    <?php
    $has_mine   = !empty($my_course_ids_set);
    $split_view = dyd_is_staff() && $has_mine;
    if ($split_view): ?>
    <li><h6 class="dropdown-header text-primary" style="font-size:.7rem">Twoje grupy</h6></li>
    <?php
      foreach ($courses as $_c):
        if (!isset($my_course_ids_set[(int)$_c['id']])) continue;
        $isActive = ((int)$_c['id'] === $cur_course);
        $inactive = ($_c['status'] ?? '') === 'cancelled' || empty($_c['is_active']);
    ?>
    <li>
      <a class="dropdown-item d-flex align-items-center gap-2 <?= $isActive ? 'active' : '' ?> <?= $inactive ? 'text-body-secondary' : '' ?>"
         href="index.php?course=<?= (int)$_c['id'] ?>&tab=<?= h($tab) ?>"
         <?= $isActive ? 'aria-current="true"' : '' ?>>
        <i class="bi bi-<?= $isActive ? 'check2' : ($inactive ? 'archive' : 'circle') ?> flex-shrink-0" aria-hidden="true"></i>
        <span class="text-truncate"><?= h($_c['name']) ?></span>
        <?php if (!$isActive && !$inactive): ?><span class="ms-auto small text-body-secondary flex-shrink-0"><?= (int)($_c['enrolled_count'] ?? 0) ?> os.</span><?php endif; ?>
      </a>
    </li>
    <?php endforeach; ?>
    <li><hr class="dropdown-divider my-1"></li>
    <li><h6 class="dropdown-header text-body-secondary" style="font-size:.7rem">Grupy innych</h6></li>
    <?php
      foreach ($courses as $_c):
        if (isset($my_course_ids_set[(int)$_c['id']])) continue;
        $isActive = ((int)$_c['id'] === $cur_course);
        $inactive = ($_c['status'] ?? '') === 'cancelled' || empty($_c['is_active']);
    ?>
    <li>
      <a class="dropdown-item d-flex align-items-center gap-2 <?= $isActive ? 'active' : '' ?> <?= $inactive ? 'text-body-secondary' : '' ?>"
         href="index.php?course=<?= (int)$_c['id'] ?>&tab=<?= h($tab) ?>"
         <?= $isActive ? 'aria-current="true"' : '' ?>>
        <i class="bi bi-<?= $isActive ? 'check2' : ($inactive ? 'archive' : 'circle') ?> flex-shrink-0" aria-hidden="true"></i>
        <span class="text-truncate"><?= h($_c['name']) ?></span>
        <span class="ms-auto small text-body-secondary flex-shrink-0"><?= h($_c['instructor_name'] ?? '—') ?></span>
      </a>
    </li>
    <?php endforeach; ?>
    <?php else: ?>
    <?php
      foreach ($courses as $_c):
        $isActive = ((int)$_c['id'] === $cur_course);
        $inactive = ($_c['status'] ?? '') === 'cancelled' || empty($_c['is_active']);
    ?>
    <li>
      <a class="dropdown-item d-flex align-items-center gap-2 <?= $isActive ? 'active' : '' ?> <?= $inactive ? 'text-body-secondary' : '' ?>"
         href="index.php?course=<?= (int)$_c['id'] ?>&tab=<?= h($tab) ?>"
         <?= $isActive ? 'aria-current="true"' : '' ?>>
        <i class="bi bi-<?= $isActive ? 'check2' : ($inactive ? 'archive' : 'circle') ?> flex-shrink-0" aria-hidden="true"></i>
        <span class="text-truncate"><?= h($_c['name']) ?></span>
        <?php if (!$isActive && !$inactive): ?><span class="ms-auto small text-body-secondary flex-shrink-0"><?= (int)($_c['enrolled_count'] ?? 0) ?> os.</span><?php endif; ?>
      </a>
    </li>
    <?php endforeach; ?>
    <?php endif; ?>
    <li><hr class="dropdown-divider"></li>
    <li><a class="dropdown-item" href="index.php?pick=1"><i class="bi bi-grid me-2"></i>Zmień grupę…</a></li>
  </ul>
</div>
<?php $_dyd_course_switcher = ob_get_clean();
}

$_dyd_staff_badge = dyd_is_staff()
    ? '<span class="badge ms-2 flex-shrink-0" style="background:#f59e0b;color:#1c1917;font-size:.68rem;letter-spacing:.03em;vertical-align:middle" title="Widzisz wszystkie grupy">'
      . '<i class="bi bi-shield-fill-check me-1" aria-hidden="true"></i>Uprawnienia kierownika</span>'
    : '';

$KP_TOPBAR = [
    'brand'         => 'Panel dydaktyka',
    'icon'          => 'easel2',
    'user'          => $me['name'] ?? '',
    'logout'        => 'logout.php',
    'notifications' => $_dyd_course_switcher . $_dyd_staff_badge,
];
include dirname(__DIR__) . '/kursant/_layout_head.php';
?>
<style>
  .dyd-wrap { max-width:1100px; }
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

  /* ── Pasek modułowy (Synergia-like secondary nav) ─────── */
  .dyd-globalbar {
    background:#2c4a6e; border-bottom:none;
    padding:.15rem 1rem; display:flex; align-items:center; gap:.05rem; flex-wrap:wrap;
  }
  .dyd-globalbar .dyd-gb-link {
    display:inline-flex; align-items:center; gap:.4rem;
    padding:.42rem .85rem; border-radius:0; font-size:.85rem; font-weight:600;
    color:rgba(255,255,255,.82); text-decoration:none;
    border:none; border-bottom:3px solid transparent;
    transition:background .12s, color .12s, border-color .12s;
    min-height:38px;
  }
  .dyd-globalbar .dyd-gb-link:hover { background:rgba(255,255,255,.1); color:#fff; }
  .dyd-globalbar .dyd-gb-link.active {
    background:rgba(255,255,255,.12); color:#fff; font-weight:700;
    border-bottom-color:#5bbcff;
  }
  .dyd-globalbar button.dyd-gb-link { background:transparent; cursor:pointer; line-height:1; }
  .dyd-globalbar .dyd-gb-dropdown { position:relative; }
  .dyd-globalbar .dyd-gb-dropdown .dyd-gb-link { border-bottom-color:transparent; }
  .dyd-globalbar .dyd-gb-dropdown .dropdown-toggle::after { margin-left:.25rem; }
  .dyd-globalbar .dropdown-menu { background:#1e3a5f; border:1px solid rgba(255,255,255,.15); }
  .dyd-globalbar .dropdown-item { color:rgba(255,255,255,.85); }
  .dyd-globalbar .dropdown-item:hover, .dyd-globalbar .dropdown-item:focus { background:rgba(255,255,255,.12); color:#fff; }
  .dyd-globalbar .dropdown-item.active { background:rgba(91,188,255,.2); color:#fff; }

  /* ── Pasek informacyjny prowadzącego (Synergia-like user banner) ── */
  .dyd-info-bar {
    background:#415a77; color:#fff;
    padding:.35rem 1rem; font-size:.81rem;
    display:flex; align-items:center; flex-wrap:wrap; gap:.5rem .75rem;
    border-bottom:1px solid rgba(255,255,255,.1);
  }
  .dyd-info-bar .dyd-ib-sep { color:rgba(255,255,255,.3); }
  .dyd-info-bar .dyd-ib-dim { color:rgba(255,255,255,.6); font-size:.76rem; }

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
<!-- MDUI 2 (MD3) — wymagany dla zakładki Pulpit -->
<link rel="stylesheet" href="https://unpkg.com/mdui@2/mdui.css">
<script type="module" src="https://unpkg.com/mdui@2/mdui.esm.js"></script>

<!-- ── Globalny pasek nawigacyjny dydaktyka ── -->
<?php
$tab_is_course    = in_array($tab, ['lekcje','zadania','materialy','nieobecnosci','program','oceny','testy','rozliczenia'], true);
$tab_is_kierownik = in_array($tab, ['rozliczenia','wypłaty','praca_wlasna','grupy','billing','kursy'], true);
?>
<nav class="dyd-globalbar" aria-label="Menu dydaktyka">
  <a class="dyd-gb-link <?= $tab==='pulpit'?'active':'' ?>" href="index.php?tab=pulpit"
     <?= $tab==='pulpit'?'aria-current="page"':'' ?>>
    <i class="bi bi-house" aria-hidden="true"></i>Pulpit
  </a>
  <a class="dyd-gb-link <?= $tab_is_course?'active':'' ?>"
     href="index.php?course=<?= $cur_course ?>&tab=lekcje"
     <?= $tab_is_course?'aria-current="page"':'' ?>>
    <i class="bi bi-pc-display" aria-hidden="true"></i>Zajęcia
    <?php if ($courses): ?>
    <span class="badge bg-secondary" style="font-size:.65rem"><?= count($courses) ?> gr.</span>
    <?php endif; ?>
  </a>
  <a class="dyd-gb-link <?= $tab==='formalnosci'?'active':'' ?>" href="index.php?tab=formalnosci"
     <?= $tab==='formalnosci'?'aria-current="page"':'' ?>>
    <i class="bi bi-file-earmark-text" aria-hidden="true"></i>Formalności
    <?php $active_cnt = count(array_filter($dyd_contracts, fn($c) => in_array($c['status'],['podpisana','w realizacji'],true))); ?>
    <?php if ($active_cnt): ?>
    <span class="badge bg-success" style="font-size:.65rem"><?= $active_cnt ?></span>
    <?php endif; ?>
  </a>
  <a class="dyd-gb-link <?= $tab==='dostepnosc'?'active':'' ?>" href="index.php?tab=dostepnosc"
     <?= $tab==='dostepnosc'?'aria-current="page"':'' ?>>
    <i class="bi bi-clock-history" aria-hidden="true"></i>Dostępność
    <?php if (isset($my_avail) && count($my_avail) > 0): ?>
    <span class="badge bg-secondary" style="font-size:.65rem"><?= count($my_avail) ?></span>
    <?php endif; ?>
  </a>
  <div class="dyd-gb-dropdown">
    <button class="dyd-gb-link <?= in_array($tab,['cykliczne'],true)?'active':'' ?> dropdown-toggle"
            data-bs-toggle="dropdown" aria-expanded="false" type="button">
      <i class="bi bi-calendar3-week" aria-hidden="true"></i>Planowanie
    </button>
    <ul class="dropdown-menu">
      <li><a class="dropdown-item <?= $tab==='cykliczne'?'active':'' ?>" href="index.php?tab=cykliczne">
        <i class="bi bi-calendar-week me-2"></i>Plan cykliczny
      </a></li>
      <li><a class="dropdown-item" href="planner.php">
        <i class="bi bi-layout-wtf me-2"></i>Planner
      </a></li>
    </ul>
  </div>
  <a class="dyd-gb-link <?= $tab==='wiadomosci'?'active':'' ?>" href="index.php?tab=wiadomosci"
     <?= $tab==='wiadomosci'?'aria-current="page"':'' ?>>
    <i class="bi bi-envelope" aria-hidden="true"></i>Wiadomości
    <?php if (!empty($dyd_msg_unread_total)): ?>
    <span class="badge bg-danger" style="font-size:.65rem"><?= (int)$dyd_msg_unread_total ?></span>
    <?php endif; ?>
  </a>
  <a class="dyd-gb-link <?= $tab==='komunikaty'?'active':'' ?>" href="index.php?tab=komunikaty"
     <?= $tab==='komunikaty'?'aria-current="page"':'' ?>>
    <i class="bi bi-megaphone" aria-hidden="true"></i>Komunikaty
    <?php if (!empty($dyd_notices)): ?>
    <span class="badge bg-warning text-dark" style="font-size:.65rem"><?= count($dyd_notices) ?></span>
    <?php endif; ?>
  </a>
  <a class="dyd-gb-link <?= $tab==='dysk'?'active':'' ?>" href="index.php?tab=dysk"
     <?= $tab==='dysk'?'aria-current="page"':'' ?>>
    <i class="bi bi-hdd-network" aria-hidden="true"></i>Mój dysk
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
  <div class="dyd-gb-dropdown">
    <button class="dyd-gb-link <?= $tab_is_kierownik?'active':'' ?> dropdown-toggle"
            data-bs-toggle="dropdown" aria-expanded="false" type="button">
      <i class="bi bi-shield-fill-check" aria-hidden="true"></i>Kierownik
      <?php if ($_kier_badge): ?><span class="badge bg-danger" style="font-size:.65rem"><?= (int)$_kier_badge ?></span><?php endif; ?>
    </button>
    <ul class="dropdown-menu dropdown-menu-end">
      <li><h6 class="dropdown-header">Grupy i finanse</h6></li>
      <li>
        <a class="dropdown-item <?= $tab==='grupy'?'active':'' ?>" href="index.php?tab=grupy">
          <i class="bi bi-grid me-2"></i>Przegląd grup
        </a>
      </li>
      <?php if ($cur_course): ?>
      <li>
        <a class="dropdown-item <?= $tab==='rozliczenia'?'active':'' ?>"
           href="index.php?course=<?= $cur_course ?>&tab=rozliczenia">
          <i class="bi bi-receipt me-2"></i>Rozliczenia grupy
          <?php if ($_kier_badge): ?><span class="badge bg-danger ms-1"><?= (int)$_kier_badge ?></span><?php endif; ?>
        </a>
      </li>
      <?php endif; ?>
      <li>
        <a class="dropdown-item <?= $tab==='billing'?'active':'' ?>" href="index.php?tab=billing">
          <i class="bi bi-receipt me-2"></i>Rozliczenia kursantów
        </a>
      </li>
      <li>
        <a class="dropdown-item <?= $tab==='kursy'?'active':'' ?>" href="index.php?tab=kursy">
          <i class="bi bi-mortarboard me-2"></i>Zarządzanie kursami
        </a>
      </li>
      <li><hr class="dropdown-divider"></li>
      <li><h6 class="dropdown-header">Wypłaty</h6></li>
      <li>
        <a class="dropdown-item <?= $tab==='wypłaty'?'active':'' ?>" href="index.php?tab=wypłaty">
          <i class="bi bi-wallet2 me-2"></i>Wypłaty prowadzących
        </a>
      </li>
      <li>
        <a class="dropdown-item <?= $tab==='praca_wlasna'?'active':'' ?>" href="index.php?tab=praca_wlasna">
          <i class="bi bi-person-workspace me-2"></i>Praca własna prowadzących
        </a>
      </li>
      <li><a class="dropdown-item" href="../zetony.php" target="_blank" rel="noopener"><i class="bi bi-coin me-2 text-warning"></i>Żetony SZO</a></li>
      <li><hr class="dropdown-divider"></li>
      <li><h6 class="dropdown-header">Raporty i inne</h6></li>
      <li><a class="dropdown-item" href="../raporty.php" target="_blank" rel="noopener"><i class="bi bi-file-earmark-bar-graph me-2"></i>Raporty i WUP</a></li>
      <li><a class="dropdown-item" href="../komunikacja.php" target="_blank" rel="noopener"><i class="bi bi-send me-2"></i>Wyślij e-mail / SMS</a></li>
      <li><hr class="dropdown-divider"></li>
      <li><a class="dropdown-item text-muted" href="../index.php" target="_blank" rel="noopener"><i class="bi bi-box-arrow-up-right me-2"></i>Pełny panel TI</a></li>
    </ul>
  </div>
  <?php endif; ?>

</nav>

<!-- ── Synergia-like info bar (prowadzący + kurs) ───────────── -->
<div class="dyd-info-bar" aria-label="Informacje o prowadzącym i kursie">
  <span><i class="bi bi-person-fill me-1" aria-hidden="true"></i><strong><?= h($me['name'] ?? '') ?></strong></span>
  <?php if ($course && $tab_is_course): ?>
  <span class="dyd-ib-sep">|</span>
  <span><i class="bi bi-pc-display me-1" aria-hidden="true"></i><?= h($course['name']) ?></span>
  <?php if (!empty($course['location'])): ?>
  <span class="dyd-ib-dim"><i class="bi bi-geo-alt me-1"></i><?= h($course['location']) ?></span>
  <?php endif; ?>
  <span class="dyd-ib-dim">· <?= (int)($course['enrolled_count'] ?? 0) ?> kursantów</span>
  <?php elseif (!$tab_is_course): ?>
  <span class="dyd-ib-sep">|</span>
  <span class="dyd-ib-dim"><?= $tab === 'pulpit' ? 'Pulpit' : ucfirst($tab) ?></span>
  <?php endif; ?>
  <a href="<?= h(rtrim(APP_URL,'/')) ?>/karty30/ti/index.php" class="ms-auto dyd-ib-dim text-decoration-none" style="font-size:.76rem">
    <i class="bi bi-grid me-1" aria-hidden="true"></i>Pełny moduł TI
  </a>
</div>

<main id="main" class="container dyd-wrap py-4">

  <?php /* h1 przeniesiony do info-bar; widok zachowuje semantykę przez nagłówki sekcji w zakładkach */ ?>

  <?= flash_html() ?>

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
        <a href="<?= h(rtrim(APP_URL,'/')) ?>/karty30/ti/urlopy.php">Nieobecności prowadzących</a>.</div>
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

  <!-- Wybór kursu — popup picker -->
  <?php if (count($courses) > 1 && $tab_is_course): ?>
  <?php
  $_cp_data = array_map(fn($c) => [
      'id'       => (int)$c['id'],
      'name'     => $c['name'],
      'location' => $c['location'] ?? '',
      'enrolled' => (int)($c['enrolled_count'] ?? 0),
      'active'   => (int)$c['id'] === $cur_course,
      'url'      => 'index.php?course=' . (int)$c['id'] . '&tab=' . urlencode($tab),
  ], $courses);
  ?>
  <div class="mb-3" style="position:relative">
    <button type="button" id="dyd-cp-trigger"
            class="btn btn-sm d-inline-flex align-items-center gap-2"
            style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:.35rem .85rem;font-weight:600;color:#1e293b;box-shadow:0 1px 3px rgba(0,0,0,.07)"
            aria-haspopup="listbox" aria-expanded="false">
      <i class="bi bi-collection" style="color:#6d28d9" aria-hidden="true"></i>
      <span id="dyd-cp-label"><?= h($course['name'] ?? 'Wybierz grupę') ?></span>
      <?php if (!empty($course['is_co_instructor'])): ?>
      <span class="badge" style="background:#fef9c3;color:#854d0e;font-size:.62rem;border:1px solid #fde68a">co</span>
      <?php endif; ?>
      <span class="badge rounded-pill" style="background:#f0fdf4;color:#16a34a;font-size:.65rem;border:1px solid #bbf7d0"><?= (int)($course['enrolled_count'] ?? 0) ?> os.</span>
      <i class="bi bi-chevron-down" style="font-size:.6rem;opacity:.5" aria-hidden="true"></i>
    </button>

    <!-- Panel wyskakujący -->
    <div id="dyd-cp-panel" hidden
         role="listbox" aria-label="Wybierz grupę"
         style="position:absolute;top:calc(100% + 6px);left:0;z-index:500;
                background:#fff;border:1px solid #e2e8f0;border-radius:14px;
                box-shadow:0 12px 40px rgba(2,6,23,.16);padding:.35rem;
                min-width:280px;max-width:420px;width:max-content">
      <?php
      $_cp_split = dyd_is_staff() && !empty($my_course_ids_set);
      $cp_render_item = function($c, $tab, $cur_course, $my_course_ids_set, $is_other = false) { $_active = (int)$c['id'] === $cur_course; ?>
      <a href="index.php?course=<?= (int)$c['id'] ?>&tab=<?= h($tab) ?>"
         role="option" aria-selected="<?= $_active ? 'true' : 'false' ?>"
         class="dyd-cp-item d-flex align-items-center gap-3 text-decoration-none rounded-3 px-3 py-2<?= $_active ? ' dyd-cp-active' : '' ?>">
        <span class="dyd-cp-ic d-flex align-items-center justify-content-center flex-shrink-0"
              style="width:36px;height:36px;border-radius:9px;
                     background:<?= $_active ? '#ede9fe' : ($is_other ? '#f1f5f9' : '#f8fafc') ?>;
                     color:<?= $_active ? '#7c3aed' : ($is_other ? '#64748b' : '#64748b') ?>">
          <i class="bi bi-people-fill" aria-hidden="true"></i>
        </span>
        <span class="flex-grow-1 min-width-0">
          <span class="d-block fw-semibold" style="font-size:.88rem;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:240px"><?= h($c['name']) ?></span>
          <?php if (!empty($c['is_co_instructor'])): ?>
          <span class="badge" style="background:#fef9c3;color:#854d0e;font-size:.6rem;border:1px solid #fde68a;vertical-align:middle">coProwadzący</span>
          <?php endif; ?>
          <?php if ($is_other && !empty($c['instructor_name'])): ?>
          <span class="d-block text-body-secondary" style="font-size:.73rem"><i class="bi bi-person me-1" aria-hidden="true"></i><?= h($c['instructor_name']) ?></span>
          <?php elseif (!empty($c['location'])): ?>
          <span class="d-block text-body-secondary" style="font-size:.73rem"><i class="bi bi-geo-alt me-1" aria-hidden="true"></i><?= h($c['location']) ?></span>
          <?php endif; ?>
        </span>
        <span class="flex-shrink-0" style="font-size:.72rem;font-weight:600;color:<?= $_active ? '#7c3aed' : '#94a3b8' ?>">
          <?= (int)($c['enrolled_count'] ?? 0) ?> os.
        </span>
        <?php if ($_active): ?>
        <i class="bi bi-check2 flex-shrink-0" style="color:#7c3aed;font-size:1rem" aria-hidden="true"></i>
        <?php endif; ?>
      </a>
      <?php }; ?>
      <?php if ($_cp_split): ?>
      <div style="padding:.25rem .75rem;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#6d28d9">Twoje grupy</div>
      <?php foreach ($courses as $c): if (!isset($my_course_ids_set[(int)$c['id']])) continue; $cp_render_item($c, $tab, $cur_course, $my_course_ids_set, false); endforeach; ?>
      <div style="border-top:1px solid #e2e8f0;margin:.35rem 0"></div>
      <div style="padding:.25rem .75rem;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#64748b">Grupy innych</div>
      <?php foreach ($courses as $c): if (isset($my_course_ids_set[(int)$c['id']])) continue; $cp_render_item($c, $tab, $cur_course, $my_course_ids_set, true); endforeach; ?>
      <?php else: ?>
      <?php foreach ($courses as $c): $cp_render_item($c, $tab, $cur_course, $my_course_ids_set, false); endforeach; ?>
      <?php endif; ?>
    </div>
  </div>
  <script>
  (function(){
    var btn   = document.getElementById('dyd-cp-trigger');
    var panel = document.getElementById('dyd-cp-panel');
    if (!btn || !panel) return;
    function open()  { panel.hidden=false; btn.setAttribute('aria-expanded','true');  btn.querySelector('.bi-chevron-down').style.transform='rotate(180deg)'; }
    function close() { panel.hidden=true;  btn.setAttribute('aria-expanded','false'); btn.querySelector('.bi-chevron-down').style.transform=''; }
    btn.addEventListener('click', function(e){ e.stopPropagation(); panel.hidden ? open() : close(); });
    document.addEventListener('click', function(e){ if (!btn.contains(e.target) && !panel.contains(e.target)) close(); });
    document.addEventListener('keydown', function(e){ if (e.key==='Escape') close(); });
  })();
  </script>
  <?php endif; ?>

  <?php if ($course): ?>
  <div class="card border-0 shadow-sm mb-3"><div class="card-body py-3 d-flex flex-wrap align-items-center gap-2">
    <div>
      <span class="fw-semibold"><i class="bi bi-pc-display text-primary me-1" aria-hidden="true"></i><?= h($course['name']) ?></span>
      <?php if (!empty($course['location'])): ?><span class="text-body-secondary small ms-2"><i class="bi bi-geo-alt me-1"></i><?= h($course['location']) ?></span><?php endif; ?>
    </div>
    <span class="text-body-secondary small ms-auto"><?= (int)($course['enrolled_count'] ?? 0) ?> aktywnych kursantów</span>
  </div></div>

  <!-- Zakładki kursu — widoczne tylko gdy aktywna zakładka należy do obszaru kursu -->
  <?php if ($tab_is_course): ?>
  <?php
    $absent_count = ($cur_course && k30_ti_course_tracks_attendance($cur_course)) ? (int)(db_one(
        "SELECT COUNT(*) AS n FROM k30_ti_attendance a
         JOIN k30_ti_sessions s ON s.id=a.session_id
         WHERE s.course_id=? AND s.status IN ('held','individual_change') AND COALESCE(a.attended,0)=0
           AND COALESCE(a.cancelled,0)=0 AND COALESCE(a.cancel_pending,0)=0", [$cur_course])['n'] ?? 0) : 0;
    $grades_count = (int)(db_one("SELECT COUNT(*) AS n FROM k30_ti_grades WHERE course_id=?", [$cur_course])['n'] ?? 0);
    $program_count = count(k30_ti_curriculum_list($cur_course));
    $testy_count  = count(k30_ti_tests_list($cur_course));
    // [label, icon, count, 'alert'|'warn'|'']
    $tabs = [
      'lekcje'      => ['Lekcje',       'calendar-week',     count($sessions),   ''],
      'zadania'     => ['Zadania',       'journal-check',     count($homeworks),  ''],
      'materialy'   => ['Materiały',     'collection-play',   count($materials),  ''],
      'nieobecnosci'=> ['Nieobecności',  'person-x',          $absent_count,      $absent_count > 0 ? 'alert' : ''],
      'oceny'       => ['Oceny',         'journal-bookmark',  $grades_count,      ''],
      'program'     => ['Program',       'list-check',        $program_count,     ''],
      'testy'       => ['Testy',         'card-checklist',    $testy_count,       ''],
    ];
    if (dyd_is_staff()) {
      $roz_debt_n = (int)(db_one(
          "SELECT COUNT(DISTINCT e.client_id) AS n
           FROM k30_ti_enrollments e
           JOIN k30_ti_billing b ON b.client_id=e.client_id AND b.status='issued'
           WHERE e.course_id=? AND e.status='active'", [$cur_course])['n'] ?? 0);
      $tabs['rozliczenia'] = ['Rozliczenia', 'receipt', $roz_debt_n, $roz_debt_n > 0 ? 'alert' : ''];
    }
  ?>
  <style>
  .dyd-ctabs{overflow:hidden;border-bottom:2px solid var(--bs-border-color);margin-bottom:1rem}
  .dyd-ctabs-track{display:flex;overflow-x:auto;scrollbar-width:none;-webkit-overflow-scrolling:touch;margin-bottom:-2px}
  .dyd-ctabs-track::-webkit-scrollbar{display:none}
  .dyd-ct{display:flex;align-items:center;gap:.3rem;padding:.55rem .9rem;font-size:.82rem;font-weight:500;
          color:var(--bs-secondary-color);text-decoration:none;border-bottom:2px solid transparent;
          white-space:nowrap;transition:color .12s,border-color .12s;flex-shrink:0}
  .dyd-ct:hover{color:var(--bs-body-color);background:rgba(0,0,0,.03)}
  [data-bs-theme="dark"] .dyd-ct:hover{background:rgba(255,255,255,.05)}
  .dyd-ct.active{color:var(--bs-primary);border-bottom-color:var(--bs-primary);font-weight:600}
  .dyd-ct-ic{font-size:.9rem;opacity:.75}
  .dyd-ct.active .dyd-ct-ic{opacity:1}
  .dyd-ct-n{display:inline-flex;align-items:center;justify-content:center;min-width:17px;height:17px;
            padding:0 4px;border-radius:9px;font-size:.65rem;font-weight:700;line-height:1;
            background:var(--bs-secondary-bg);color:var(--bs-secondary-color)}
  .dyd-ct-n.nz{background:var(--bs-primary-bg-subtle);color:var(--bs-primary)}
  .dyd-ct.active .dyd-ct-n.nz{background:var(--bs-primary);color:#fff}
  .dyd-ct-n.al{background:var(--bs-danger);color:#fff}
  .dyd-ct-n.zr{opacity:.45}
  </style>
  <nav class="dyd-ctabs" aria-label="Zakładki kursu">
    <div class="dyd-ctabs-track" role="tablist">
      <?php foreach ($tabs as $k => [$label, $icon, $cnt, $variant]): ?>
      <?php
        $is_active = $tab === $k;
        $badge_cls = $variant === 'alert' ? 'al' : ($cnt > 0 ? 'nz' : 'zr');
      ?>
      <a class="dyd-ct <?= $is_active ? 'active' : '' ?>"
         href="index.php?course=<?= $cur_course ?>&tab=<?= $k ?>"
         role="tab" aria-selected="<?= $is_active ? 'true' : 'false' ?>">
        <i class="bi bi-<?= $icon ?> dyd-ct-ic" aria-hidden="true"></i>
        <?= h($label) ?>
        <span class="dyd-ct-n <?= $badge_cls ?>"><?= $cnt ?></span>
      </a>
      <?php endforeach; ?>
    </div>
  </nav>
  <?php endif; ?>

  <div class="dyd-tabpane">

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

    <?php /* ═══════════════════════ OCENY (e-dziennik) ═══════════════════════ */ ?>
    <?php if ($tab === 'oceny'): ?>
    <?php include __DIR__ . '/_tab_oceny.php'; ?>
    <?php endif; ?>

    <?php /* ═══════════════════════ ROZLICZENIA (staff/admin) ═══════════════════════ */ ?>
    <?php if ($tab === 'rozliczenia' && dyd_is_staff()): ?>
    <?php include __DIR__ . '/_tab_rozliczenia.php'; ?>
    <?php endif; ?>

  </div><!-- /dyd-tabpane kurs -->
  <?php endif; /* $course */ ?>

    <?php /* ═══════════════════════ DOSTĘPNOŚĆ ═══════════════════════ */ ?>
    <?php if ($tab === 'dostepnosc'): ?>
    <?php include __DIR__ . '/_tab_dostepnosc.php'; ?>
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

  <?php if ($tab === 'cykliczne'): ?>
  <?php include __DIR__ . '/_tab_cykliczne.php'; ?>
  <?php endif; /* cykliczne */ ?>

  <?php endif; /* $courses */ ?>

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
  var d = document.getElementById('rs_date'); if (d) d.value = date || '';
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
  var items = list.querySelectorAll('.list-group-item');
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
<?php include __DIR__ . '/_wizard.php'; ?>
<?php include dirname(__DIR__) . '/kursant/_layout_foot.php'; ?>
