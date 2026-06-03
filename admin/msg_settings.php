<?php
/**
 * admin/msg_settings.php — Centrum zarządzania powiadomieniami e-mail
 *
 * Sekcje:
 *  1. Status kanału e-mail (M365 / PHP mail)
 *  2. Wiadomości (wątki wolontariusz ↔ admin)
 *  3. Zadania — domyślne preferencje dla nowych użytkowników
 *  4. Ogłoszenia systemowe
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/task_notify.php';

require_role('admin');

$PAGE_TITLE = 'Centrum powiadomień';

// ── Odczyt ustawień ───────────────────────────────────────────────────────────
function _ns($key, $default = '1'): string {
    $v = org_setting($key);
    return ($v !== null && $v !== '') ? $v : $default;
}

// ── POST: zapis ───────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $section = $_POST['_section'] ?? '';

    $save = [];

    if ($section === 'messages') {
        $save['msg_notify_admin_on_question'] = isset($_POST['notify_admin']) ? '1' : '0';
        $save['msg_notify_user_on_reply']     = isset($_POST['notify_user'])  ? '1' : '0';
    }

    if ($section === 'tasks_defaults') {
        $save['task_notify_default_assigned']  = isset($_POST['td_assigned'])  ? '1' : '0';
        $save['task_notify_default_mentioned'] = isset($_POST['td_mentioned']) ? '1' : '0';
        $save['task_notify_default_comment']   = isset($_POST['td_comment'])   ? '1' : '0';
        $save['task_notify_default_due_1day']  = isset($_POST['td_due_1day'])  ? '1' : '0';
        $save['task_notify_default_due_today'] = isset($_POST['td_due_today']) ? '1' : '0';
    }

    if ($section === 'announcements') {
        $save['ann_send_email_default'] = isset($_POST['ann_email_default']) ? '1' : '0';
    }

    foreach ($save as $key => $val) {
        try {
            db()->prepare(
                "INSERT INTO settings (key_, value) VALUES (?,?)
                 ON CONFLICT(key_) DO UPDATE SET value=excluded.value"
            )->execute([$key, $val]);
        } catch (\Throwable $e) {
            try {
                $ex = db_one("SELECT key_ FROM settings WHERE key_=?", [$key]);
                if ($ex) db()->prepare("UPDATE settings SET value=? WHERE key_=?")->execute([$val, $key]);
                else     db_insert('settings', ['key_' => $key, 'value' => $val]);
            } catch (\Throwable $e2) {}
        }
    }

    flash_set('success', 'Ustawienia zapisane.');
    header('Location: msg_settings.php');
    exit;
}

// ── Status kanału e-mail ──────────────────────────────────────────────────────
$m365_ok = false;
$mail_channel = 'brak';
try {
    $m365_client = org_setting('m365_client_id');
    $m365_tenant = org_setting('m365_tenant_id');
    $m365_sender = org_setting('m365_sender_user_id') ?: org_setting('m365_from_email');
    if ($m365_client && $m365_tenant && $m365_sender) {
        $m365_ok = true;
        $mail_channel = 'Microsoft 365 (Graph API)';
    } elseif (function_exists('mail')) {
        $mail_channel = 'PHP mail() — konfiguracja serwera';
    }
} catch (\Throwable $e) {}

// ── Załaduj bieżące ustawienia ────────────────────────────────────────────────
$msg_notify_admin = _ns('msg_notify_admin_on_question');
$msg_notify_user  = _ns('msg_notify_user_on_reply');

$td_assigned  = _ns('task_notify_default_assigned',  '1');
$td_mentioned = _ns('task_notify_default_mentioned', '1');
$td_comment   = _ns('task_notify_default_comment',   '0');
$td_due_1day  = _ns('task_notify_default_due_1day',  '1');
$td_due_today = _ns('task_notify_default_due_today', '1');

$ann_email_default = _ns('ann_send_email_default', '0');

// ── Statystyki powiadomień zadań (ostatnie 7 dni) ─────────────────────────────
$notif_stats = [];
try {
    $rows = db_all(
        "SELECT event_type, COUNT(*) AS cnt
         FROM task_notification_log
         WHERE sent_at >= DATE('now','-7 days')
         GROUP BY event_type ORDER BY cnt DESC"
    );
    foreach ($rows as $r) $notif_stats[$r['event_type']] = (int)$r['cnt'];
} catch (\Throwable $e) {}
$notif_total_7d = array_sum($notif_stats);

// ── Liczba użytkowników z aktywnym e-mail ─────────────────────────────────────
$users_with_email = 0;
try {
    $users_with_email = (int)(db_one(
        "SELECT COUNT(*) AS c FROM users WHERE is_active=1 AND email IS NOT NULL AND email != ''"
    )['c'] ?? 0);
} catch (\Throwable $e) {}

include dirname(__DIR__) . '/includes/header.php';
?>

<style>
/* ── general ──────────────────────────────────────── */
.nc-wrap      { max-width: 860px; }
.nc-section   { border: 1px solid #e2e8f0; border-radius: 10px; background: #fff; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,.04); margin-bottom: 1.5rem; }
.nc-sec-head  { padding: .9rem 1.25rem; background: #f8fafc; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; gap: .65rem; }
.nc-sec-title { font-weight: 700; font-size: .93rem; color: #0f172a; flex: 1; }
.nc-sec-sub   { font-size: .77rem; color: #94a3b8; }
.nc-body      { padding: 1.25rem; }

/* ── status bar ───────────────────────────────────── */
.nc-status    { border-radius: 10px; padding: 1.1rem 1.4rem; display: flex; align-items: center; gap: 1rem; flex-wrap: wrap; margin-bottom: 1.5rem; }
.nc-status.ok { background: #f0fdf4; border: 1px solid #bbf7d0; }
.nc-status.warn { background: #fffbeb; border: 1px solid #fde68a; }
.nc-stat-dot  { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
.nc-stat-info { flex: 1; min-width: 0; }
.nc-stat-kpis { display: flex; gap: 1.5rem; flex-wrap: wrap; }
.nc-kpi       { text-align: center; }
.nc-kpi-val   { font-size: 1.4rem; font-weight: 800; color: #0f172a; line-height: 1; }
.nc-kpi-lbl   { font-size: .72rem; color: #64748b; margin-top: .15rem; }

/* ── toggle row ───────────────────────────────────── */
.nc-row       { display: flex; align-items: flex-start; gap: 1rem; padding: .8rem 0; border-bottom: 1px solid #f1f5f9; }
.nc-row:last-child { border-bottom: none; padding-bottom: 0; }
.nc-row-icon  { width: 36px; height: 36px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: .95rem; flex-shrink: 0; margin-top: .1rem; }
.nc-row-body  { flex: 1; }
.nc-row-title { font-weight: 600; font-size: .88rem; color: #0f172a; }
.nc-row-desc  { font-size: .78rem; color: #64748b; margin-top: .15rem; line-height: 1.45; }
.nc-row-toggle { flex-shrink: 0; margin-top: .1rem; }
.form-check-input[type=checkbox] { width:2.4em; height:1.3em; cursor:pointer; }
.form-check-input:checked        { background-color:#2563eb; border-color:#2563eb; }

/* ── event chip (stat) ────────────────────────────── */
.nc-event-chip { display:inline-flex; align-items:center; gap:.3rem; background:#f1f5f9; border:1px solid #e2e8f0; border-radius:6px; padding:.2rem .55rem; font-size:.77rem; margin:.15rem; }
.nc-event-chip .cnt { font-weight:700; color:#0f172a; }

/* ── defaults banner ──────────────────────────────── */
.nc-defaults-note { font-size:.76rem; color:#64748b; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:.6rem .9rem; margin-bottom:1rem; }

/* ── save row ─────────────────────────────────────── */
.nc-save-row  { display:flex; justify-content:flex-end; padding-top:1rem; border-top:1px solid #f1f5f9; margin-top:.5rem; }
</style>

<div class="nc-wrap">

  <!-- Breadcrumb / title -->
  <div class="d-flex align-items-center gap-2 mb-4">
    <div style="width:42px;height:42px;border-radius:10px;background:#eff6ff;display:flex;align-items:center;justify-content:center;font-size:1.2rem;flex-shrink:0">
      <i class="bi bi-bell-fill text-primary"></i>
    </div>
    <div>
      <h4 class="mb-0">Centrum powiadomień</h4>
      <div class="text-muted" style="font-size:.8rem">Kontroluj które e-maile system wysyła automatycznie i do kogo</div>
    </div>
    <div class="ms-auto d-flex gap-2">
      <a href="message_types.php" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-tags me-1"></i>Typy wiadomości
      </a>
      <a href="m365.php" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-envelope-at me-1"></i>Konfiguracja e-mail
      </a>
    </div>
  </div>

  <?= flash_html() ?>

  <!-- ══ Status kanału e-mail ══════════════════════════════════════ -->
  <div class="nc-status <?= $m365_ok ? 'ok' : 'warn' ?>">
    <div class="nc-stat-dot" style="background:<?= $m365_ok ? '#22c55e' : '#f59e0b' ?>"></div>
    <div class="nc-stat-info">
      <div style="font-weight:700;font-size:.9rem;color:<?= $m365_ok ? '#166534' : '#92400e' ?>">
        <?= $m365_ok ? 'Kanał e-mail aktywny' : 'Kanał e-mail — sprawdź konfigurację' ?>
      </div>
      <div style="font-size:.78rem;color:#6b7280;margin-top:.15rem">
        <?= h($mail_channel) ?>
        <?php if (!$m365_ok): ?>
        · <a href="m365.php" style="color:#d97706">skonfiguruj Microsoft 365</a>
        <?php endif; ?>
      </div>
    </div>
    <div class="nc-stat-kpis">
      <div class="nc-kpi">
        <div class="nc-kpi-val"><?= $users_with_email ?></div>
        <div class="nc-kpi-lbl">użytkowników<br>z e-mailem</div>
      </div>
      <div class="nc-kpi">
        <div class="nc-kpi-val"><?= $notif_total_7d ?></div>
        <div class="nc-kpi-lbl">wysłanych<br>ostatnie 7 dni</div>
      </div>
    </div>
  </div>

  <!-- Ostatnia aktywność powiadomień -->
  <?php if ($notif_stats): ?>
  <div class="mb-4" style="font-size:.8rem;color:#64748b">
    <span style="font-weight:600;margin-right:.4rem">Rodzaje (7 dni):</span>
    <?php
    $event_labels = [
        'assigned'       => ['Przypisanie',   'bi-person-plus text-primary'],
        'mentioned'      => ['Wzmianka @',    'bi-at text-info'],
        'comment_added'  => ['Komentarz',     'bi-chat-left text-secondary'],
        'due_1day'       => ['Termin jutro',  'bi-calendar-event text-warning'],
        'due_today'      => ['Termin dzisiaj','bi-alarm text-danger'],
    ];
    foreach ($notif_stats as $ev => $cnt):
        [$lbl, $ico] = $event_labels[$ev] ?? [$ev, 'bi-bell'];
    ?>
    <span class="nc-event-chip"><i class="bi <?= $ico ?>"></i> <?= h($lbl) ?> <span class="cnt"><?= $cnt ?></span></span>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- ══ 1. Wiadomości ════════════════════════════════════════════ -->
  <div class="nc-section">
    <div class="nc-sec-head">
      <i class="bi bi-chat-dots-fill" style="color:#2563eb;font-size:1rem"></i>
      <div class="nc-sec-title">Wiadomości</div>
      <div class="nc-sec-sub">Wątki wolontariusz ↔ opiekun / admin</div>
    </div>
    <div class="nc-body">
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="_section" value="messages">

        <div class="nc-row">
          <div class="nc-row-icon" style="background:#dbeafe;color:#2563eb">
            <i class="bi bi-person-lines-fill"></i>
          </div>
          <div class="nc-row-body">
            <div class="nc-row-title">Powiadamiaj opiekuna / admina o nowej wiadomości</div>
            <div class="nc-row-desc">
              Gdy wolontariusz, pracownik lub zleceniobiorca wyśle wiadomość, e-mail trafia
              do przypisanego opiekuna umowy. Brak opiekuna → wszyscy administratorzy.
            </div>
          </div>
          <div class="nc-row-toggle">
            <div class="form-check form-switch mb-0">
              <input class="form-check-input" type="checkbox" role="switch"
                     id="notify_admin" name="notify_admin"
                     <?= $msg_notify_admin !== '0' ? 'checked' : '' ?>>
              <label class="visually-hidden" for="notify_admin">Powiadom opiekuna</label>
            </div>
          </div>
        </div>

        <div class="nc-row">
          <div class="nc-row-icon" style="background:#f0fdf4;color:#16a34a">
            <i class="bi bi-reply-fill"></i>
          </div>
          <div class="nc-row-body">
            <div class="nc-row-title">Powiadamiaj użytkownika o odpowiedzi admina</div>
            <div class="nc-row-desc">
              Gdy admin lub opiekun odpowie na wiadomość, użytkownik dostaje e-mail na adres
              przypisany do jego umowy.
            </div>
          </div>
          <div class="nc-row-toggle">
            <div class="form-check form-switch mb-0">
              <input class="form-check-input" type="checkbox" role="switch"
                     id="notify_user" name="notify_user"
                     <?= $msg_notify_user !== '0' ? 'checked' : '' ?>>
              <label class="visually-hidden" for="notify_user">Powiadom użytkownika</label>
            </div>
          </div>
        </div>

        <div class="nc-save-row">
          <button type="submit" class="btn btn-primary btn-sm px-4">
            <i class="bi bi-floppy me-1"></i>Zapisz
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- ══ 2. Zadania — domyślne preferencje ════════════════════════ -->
  <div class="nc-section">
    <div class="nc-sec-head">
      <i class="bi bi-check2-square" style="color:#7c3aed;font-size:1rem"></i>
      <div class="nc-sec-title">Zadania — domyślne preferencje nowych kont</div>
      <div class="nc-sec-sub">Każdy użytkownik może nadpisać własne ustawienia</div>
    </div>
    <div class="nc-body">
      <div class="nc-defaults-note">
        <i class="bi bi-info-circle me-1"></i>
        Te ustawienia stosują się tylko do <strong>nowych kont</strong> (przy pierwszym zapisie preferencji).
        Istniejący użytkownicy zachowują swoje ustawienia —
        <a href="users.php">zarządzaj nimi indywidualnie</a>.
        Każdy użytkownik może też sam zmienić preferencje w
        <a href="<?= APP_URL ?>/tasks/notification_settings.php" target="_blank">ustawieniach zadań</a>.
      </div>

      <form method="post">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="_section" value="tasks_defaults">

        <?php
        $task_defs = [
            ['key'=>'td_assigned',  'db'=>$td_assigned,  'icon'=>'bi-person-plus-fill','bg'=>'#dbeafe','ic'=>'#2563eb',
             'title'=>'Przypisanie do zadania','desc'=>'Gdy ktoś przypisze użytkownika do nowego zadania.'],
            ['key'=>'td_mentioned', 'db'=>$td_mentioned, 'icon'=>'bi-at',               'bg'=>'#e0f2fe','ic'=>'#0284c7',
             'title'=>'Wzmianka @','desc'=>'Gdy ktoś użyje @NazwyUżytkownika w komentarzu.'],
            ['key'=>'td_comment',   'db'=>$td_comment,   'icon'=>'bi-chat-left-text-fill','bg'=>'#f1f5f9','ic'=>'#64748b',
             'title'=>'Nowy komentarz','desc'=>'Każdy komentarz do przypisanych zadań.'],
            ['key'=>'td_due_1day',  'db'=>$td_due_1day,  'icon'=>'bi-calendar-event-fill','bg'=>'#fef9c3','ic'=>'#ca8a04',
             'title'=>'Termin jutro','desc'=>'Przypomnienie dzień przed terminem.'],
            ['key'=>'td_due_today', 'db'=>$td_due_today, 'icon'=>'bi-alarm-fill',        'bg'=>'#fee2e2','ic'=>'#dc2626',
             'title'=>'Termin dzisiaj','desc'=>'Przypomnienie rano w dniu terminu.'],
        ];
        foreach ($task_defs as $opt):
            $on = $opt['db'] !== '0';
        ?>
        <div class="nc-row">
          <div class="nc-row-icon" style="background:<?= $opt['bg'] ?>;color:<?= $opt['ic'] ?>">
            <i class="bi <?= $opt['icon'] ?>"></i>
          </div>
          <div class="nc-row-body">
            <div class="nc-row-title"><?= $opt['title'] ?></div>
            <div class="nc-row-desc"><?= $opt['desc'] ?></div>
          </div>
          <div class="nc-row-toggle">
            <div class="form-check form-switch mb-0">
              <input class="form-check-input" type="checkbox" role="switch"
                     id="<?= $opt['key'] ?>" name="<?= $opt['key'] ?>"
                     <?= $on ? 'checked' : '' ?>>
              <label class="visually-hidden" for="<?= $opt['key'] ?>"><?= $opt['title'] ?></label>
            </div>
          </div>
        </div>
        <?php endforeach; ?>

        <div class="nc-save-row">
          <button type="submit" class="btn btn-primary btn-sm px-4">
            <i class="bi bi-floppy me-1"></i>Zapisz domyślne
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- ══ 3. Ogłoszenia ════════════════════════════════════════════ -->
  <div class="nc-section">
    <div class="nc-sec-head">
      <i class="bi bi-megaphone-fill" style="color:#d97706;font-size:1rem"></i>
      <div class="nc-sec-title">Ogłoszenia systemowe</div>
      <div class="nc-sec-sub">Dodatkowe e-maile przy publikacji ogłoszeń</div>
    </div>
    <div class="nc-body">
      <form method="post">
        <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="_section" value="announcements">

        <div class="nc-row">
          <div class="nc-row-icon" style="background:#fef9c3;color:#ca8a04">
            <i class="bi bi-envelope-paper-fill"></i>
          </div>
          <div class="nc-row-body">
            <div class="nc-row-title">Wyślij e-mail przy publikacji ogłoszenia (domyślnie)</div>
            <div class="nc-row-desc">
              Gdy admin tworzy nowe ogłoszenie, pole „Wyślij e-mail" będzie domyślnie zaznaczone.
              Można je odznaczyć ręcznie przy każdym ogłoszeniu.
            </div>
          </div>
          <div class="nc-row-toggle">
            <div class="form-check form-switch mb-0">
              <input class="form-check-input" type="checkbox" role="switch"
                     id="ann_email_default" name="ann_email_default"
                     <?= $ann_email_default !== '0' ? 'checked' : '' ?>>
              <label class="visually-hidden" for="ann_email_default">E-mail przy ogłoszeniu</label>
            </div>
          </div>
        </div>

        <div class="nc-save-row">
          <button type="submit" class="btn btn-primary btn-sm px-4">
            <i class="bi bi-floppy me-1"></i>Zapisz
          </button>
        </div>
      </form>
    </div>
  </div>

  <!-- ══ 4. Linki do innych ustawień ══════════════════════════════ -->
  <div class="row g-3 mb-2">
    <div class="col-sm-6 col-md-4">
      <a href="m365.php" class="d-flex align-items-center gap-2 p-3 border rounded text-decoration-none text-dark"
         style="background:#f8fafc;font-size:.84rem;transition:all .1s" onmouseover="this.style.borderColor='#2563eb'" onmouseout="this.style.borderColor=''">
        <i class="bi bi-envelope-at text-primary fs-5"></i>
        <div><div class="fw-semibold">Konfiguracja e-mail</div><div class="text-muted" style="font-size:.74rem">M365 / SMTP / PHP mail()</div></div>
        <i class="bi bi-chevron-right ms-auto text-muted" style="font-size:.75rem"></i>
      </a>
    </div>
    <div class="col-sm-6 col-md-4">
      <a href="events_settings.php" class="d-flex align-items-center gap-2 p-3 border rounded text-decoration-none text-dark"
         style="background:#f8fafc;font-size:.84rem;transition:all .1s" onmouseover="this.style.borderColor='#2563eb'" onmouseout="this.style.borderColor=''">
        <i class="bi bi-calendar-event text-success fs-5"></i>
        <div><div class="fw-semibold">Powiadomienia o wydarzeniach</div><div class="text-muted" style="font-size:.74rem">Potwierdzenia rejestracji, szablony</div></div>
        <i class="bi bi-chevron-right ms-auto text-muted" style="font-size:.75rem"></i>
      </a>
    </div>
    <div class="col-sm-6 col-md-4">
      <a href="manage_cpc.php" class="d-flex align-items-center gap-2 p-3 border rounded text-decoration-none text-dark"
         style="background:#f8fafc;font-size:.84rem;transition:all .1s" onmouseover="this.style.borderColor='#2563eb'" onmouseout="this.style.borderColor=''">
        <i class="bi bi-shield-lock text-warning fs-5"></i>
        <div><div class="fw-semibold">Kody IKA</div><div class="text-muted" style="font-size:.74rem">Powiadomienia autoryzacyjne</div></div>
        <i class="bi bi-chevron-right ms-auto text-muted" style="font-size:.75rem"></i>
      </a>
    </div>
  </div>

</div>

<?php include dirname(__DIR__) . '/includes/footer.php'; ?>
