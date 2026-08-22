<?php
/**
 * crm/inbox.php — Skrzynka odbiorcza współdzielona w CRM.
 *
 * Ta sama baza wiadomości co Poczta EZD (crm_communications zasilane przez
 * PocztaScanService) — jeden zbiór danych, dwa widoki: kancelaria pracuje w EZD,
 * sprzedaż w CRM. „Przeczytane" i przypisanie są wspólne, bo to te same kolumny.
 *
 * Wiadomość można obsłużyć na trzy sposoby, bez wychodzenia z ekranu:
 *   • w CRM        — odpowiedź, sprawa CRM, oferta,
 *   • w EZD        — dopięcie do koszulki albo założenie nowej (rejestr pism),
 *   • przekazaniem — e-mail do osoby/skrzynki z cytatem i notatką.
 *
 * Układ master-detail, bez modali — działa z klawiatury i czytnikiem ekranu.
 */
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/includes/db.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/crm.php';
require_once dirname(__DIR__) . '/includes/crm_mailbox.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
crm_migrate();

$PAGE_TITLE = 'Skrzynka CRM';
$can_write  = can_write('crm') || is_admin();
$uid        = (int)(current_user()['id'] ?? 0);
$ready      = crm_mailbox_ready();

// ── Akcje (POST → redirect, żeby odświeżenie nie powtarzało operacji) ────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$can_write) { flash_set('danger', 'Brak uprawnień.'); }
    else {
        $op   = (string)($_POST['_op'] ?? '');
        $mid  = (int)($_POST['msg_id'] ?? 0);
        $back = 'inbox.php?' . http_build_query(array_filter([
            'view'       => $_POST['view'] ?? null,
            'mailbox_id' => $_POST['mailbox_id'] ?? null,
            'q'          => $_POST['q'] ?? null,
            'msg'        => $mid ?: null,
        ]));

        switch ($op) {
            case 'read':      crm_mailbox_mark_read($mid, true);       flash_set('success', 'Oznaczono jako przeczytane.'); break;
            case 'unread':    crm_mailbox_mark_read($mid, false);      flash_set('success', 'Oznaczono jako nieprzeczytane.'); break;
            case 'archive':   crm_mailbox_set_status($mid, 'archived'); flash_set('success', 'Wiadomość załatwiona.'); break;
            case 'spam':      crm_mailbox_set_status($mid, 'spam');     flash_set('success', 'Oznaczono jako spam.'); break;
            case 'restore':   crm_mailbox_set_status($mid, 'active');   flash_set('success', 'Przywrócono do skrzynki.'); break;
            case 'assign_me': crm_mailbox_assign($mid, $uid);           flash_set('success', 'Przypisano Tobie.'); break;

            case 'assign':
                crm_mailbox_assign($mid, (int)($_POST['user_id'] ?? 0) ?: null);
                flash_set('success', 'Przypisanie zmienione.');
                break;

            case 'case':
                $r = crm_mailbox_create_case($mid, [
                    'title'     => (string)($_POST['case_title'] ?? ''),
                    'priority'  => (string)($_POST['case_priority'] ?? 'medium'),
                    'assign_to' => (int)($_POST['case_owner'] ?? 0),
                    'keep_open' => !empty($_POST['case_keep_open']),
                ]);
                if (!empty($r['ok'])) {
                    flash_set('success', 'Sprawa utworzona z wiadomości.');
                    header('Location: ' . $r['url']); exit;
                }
                flash_set('danger', $r['error'] ?? 'Nie udało się utworzyć sprawy.');
                break;

            case 'ezd':
                $r = crm_mailbox_to_ezd(
                    $mid,
                    (int)($_POST['sprawa_id'] ?? 0) ?: null,
                    (int)($_POST['teczka_id'] ?? 0) ?: null
                );
                if (!empty($r['ok'])) {
                    flash_set('success', 'Wiadomość przekazana do EZD' . ($r['znak'] ? ' — ' . $r['znak'] : '') . '.');
                    header('Location: ' . ($r['url'] ?: $back)); exit;
                }
                flash_set('danger', $r['error'] ?: 'Nie udało się przekazać do EZD.');
                break;

            case 'forward':
                $r = crm_mailbox_forward($mid, (string)($_POST['fwd_to'] ?? ''), (string)($_POST['fwd_note'] ?? ''));
                flash_set(!empty($r['ok']) ? 'success' : 'danger',
                    !empty($r['ok']) ? 'Wiadomość przekazana — trafiła do kolejki wysyłki.' : ($r['error'] ?: 'Nie udało się przekazać.'));
                break;

            case 'scan':
                if (!is_admin()) { flash_set('danger', 'Tylko administrator.'); break; }
                $r = crm_mailbox_scan((int)($_POST['scan_mailbox'] ?? 0) ?: null);
                if (empty($r['ok'])) flash_set('danger', $r['error'] ?? 'Skanowanie nieudane.');
                else flash_set($r['errors'] ? 'warning' : 'success', sprintf(
                    'Pobrano %d, dopasowano %d, zapisano %d, pominięto %d.%s',
                    $r['fetched'], $r['matched'], $r['created'], $r['skipped'],
                    $r['errors'] ? ' Uwagi: ' . implode(' | ', array_slice($r['errors'], 0, 2)) : ''
                ));
                break;

            case 'autocreate':
                if (!is_admin()) { flash_set('danger', 'Tylko administrator.'); break; }
                crm_setting_save('poczta_autocreate_contacts', !empty($_POST['on']) ? '1' : '0');
                flash_set('success', 'Ustawienie zapisane.');
                break;
        }
        header('Location: ' . $back); exit;
    }
}

// ── Filtry i dane ───────────────────────────────────────────────────────────
$view   = isset(CRM_MAILBOX_VIEWS[$_GET['view'] ?? '']) ? (string)$_GET['view'] : 'new';
$mbox_f = (int)($_GET['mailbox_id'] ?? 0);
$search = trim((string)($_GET['q'] ?? ''));
$page   = max(1, (int)($_GET['page'] ?? 1));
$sel_id = (int)($_GET['msg'] ?? 0);

$inbox  = crm_mailbox_inbox(['view' => $view, 'mailbox_id' => $mbox_f, 'q' => $search, 'page' => $page, 'per_page' => 20]);
$counts = crm_mailbox_counts();
$boxes  = crm_mailbox_list(false);
$users  = [];
try { $users = db_all("SELECT id, name FROM users WHERE is_active=1 ORDER BY name"); } catch (\Throwable $e) {}

if (!$sel_id && $inbox['rows']) $sel_id = (int)$inbox['rows'][0]['id'];
$msg = $sel_id ? crm_mailbox_message($sel_id) : null;
if ($msg && !(int)$msg['is_read'] && $can_write) {
    crm_mailbox_mark_read($sel_id, true);   // otwarcie = przeczytanie
    $msg['is_read'] = 1;
}

$ezd_on     = crm_mailbox_ezd_available();
$ezd_sprawy = $msg ? crm_mailbox_ezd_sprawy('', 30) : [];
$ezd_teczki = $msg ? crm_mailbox_ezd_teczki() : [];

$qs = static function (array $over = []) use ($view, $mbox_f, $search, $sel_id): string {
    return http_build_query(array_filter(array_merge([
        'view' => $view, 'mailbox_id' => $mbox_f ?: null, 'q' => $search ?: null, 'msg' => $sel_id ?: null,
    ], $over), static fn($v) => $v !== null && $v !== ''));
};

/** Nagłówek grupy dat na liście — „Dziś", „Wczoraj", inaczej data. */
$day_label = static function (string $ts): string {
    $d = date('Y-m-d', strtotime($ts));
    if ($d === date('Y-m-d')) return 'Dziś';
    if ($d === date('Y-m-d', strtotime('-1 day'))) return 'Wczoraj';
    return date('j.m.Y', strtotime($ts));
};

include __DIR__ . '/includes/header_crm.php';
?>
<style>
.ib-wrap { display:grid; grid-template-columns:minmax(340px, 420px) 1fr; gap:1.1rem;
  align-items:stretch; min-height:26rem }
@media (max-width:1099px) { .ib-wrap { grid-template-columns:1fr; min-height:0 } }
/* Panel bez wybranej wiadomości: treść wyśrodkowana, a nie przyklejona do góry
   cienkiego paska — inaczej obok wysokiej listy wygląda jak błąd układu. */
.ib-empty { display:flex; align-items:center; justify-content:center; padding:2.5rem 1.15rem }
/* Lista ma się rozciągać na wysokość kolumny, żeby ramki obu paneli kończyły
   się na tej samej linii. */
.ib-wrap > div { min-width:0 }
.ib-wrap > div > .ib-list { height:100% }

/* ── Lista ─────────────────────────────────────────────────────────────── */
.ib-list { background:#fff; border:1px solid #E5E7EB; border-radius:12px; overflow:hidden }
.ib-day { padding:.35rem .85rem; background:#F9FAFB; border-bottom:1px solid #F1F2F4;
  font-size:.68rem; font-weight:700; letter-spacing:.08em; text-transform:uppercase; color:#9CA3AF }
.ib-item { display:flex; gap:.65rem; padding:.7rem .85rem; border-bottom:1px solid #F3F4F6;
  text-decoration:none; color:#111827; align-items:flex-start }
.ib-item:last-child { border-bottom:none }
.ib-item:hover { background:#FAFBFC }
.ib-item.active { background:#F2F7FF; box-shadow:inset 3px 0 0 var(--crm-primary) }
.ib-av { width:34px; height:34px; flex-shrink:0; border-radius:50%; background:#E8EDF4; color:#3B4A5A;
  display:flex; align-items:center; justify-content:center; font-size:.75rem; font-weight:700 }
.ib-item.unread .ib-av { background:var(--crm-primary); color:#fff }
.ib-mid { min-width:0; flex:1 }
.ib-top { display:flex; align-items:baseline; gap:.5rem }
.ib-subj { font-size:.87rem; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; flex:1 }
.ib-item.unread .ib-subj { font-weight:700 }
.ib-time { font-size:.7rem; color:#9CA3AF; white-space:nowrap }
.ib-who { font-size:.78rem; color:#4B5563; overflow:hidden; text-overflow:ellipsis; white-space:nowrap }
.ib-snip { font-size:.76rem; color:#9CA3AF; overflow:hidden; text-overflow:ellipsis; white-space:nowrap }
.ib-tags { display:flex; gap:.3rem; margin-top:.15rem; flex-wrap:wrap }
.ib-tag { font-size:.66rem; font-weight:700; letter-spacing:.03em; padding:.05rem .4rem; border-radius:3px }

/* ── Panel wiadomości ──────────────────────────────────────────────────── */
.ib-pane { background:#fff; border:1px solid #E5E7EB; border-radius:12px }
.ib-head { padding:1rem 1.15rem .8rem }
.ib-h1 { font-size:1.08rem; font-weight:700; line-height:1.3; margin:0 0 .3rem }
.ib-meta { font-size:.8rem; color:#6B7280 }
.ib-chip { display:inline-flex; align-items:center; gap:.35rem; padding:.25rem .6rem; border-radius:2rem;
  background:#F3F4F6; font-size:.78rem; color:#374151; text-decoration:none }
.ib-chip:hover { background:#E9EDF3; color:#111827 }
.ib-bar { display:flex; gap:.4rem; flex-wrap:wrap; padding:.55rem 1.15rem; background:#FAFBFC;
  border-top:1px solid #F1F2F4; border-bottom:1px solid #F1F2F4 }
.ib-body { padding:1.15rem; font-size:.9rem; line-height:1.6; overflow-wrap:anywhere }
.ib-body img { max-width:100%; height:auto }
.ib-sep { width:1px; align-self:stretch; background:#E5E7EB; margin:0 .15rem }
/* Formularze akcji przeniesione do modali — z dawnych szuflad zostaje tylko
   wypełnienie treści, używane w oknach. */
.ib-dbody { padding:.2rem 0 0 }
.ib-ctx { padding:.9rem 1.15rem; border-top:1px solid #F1F2F4; font-size:.82rem }
.ib-ctx a { text-decoration:none }
.ib-lbl { font-size:.68rem; font-weight:700; letter-spacing:.07em; text-transform:uppercase; color:#9CA3AF; margin-bottom:.25rem }
.ib-pill { display:inline-flex; align-items:center; gap:.4rem; padding:.3rem .5rem .3rem .8rem; border-radius:2rem;
  font-size:.78rem; font-weight:600; text-decoration:none; border:2px solid transparent }
/* Licznik jako osobna plakietka — inaczej „Nowe 23" czyta się jak jedno wyrażenie. */
.ib-cnt { display:inline-block; min-width:1.5rem; padding:0 .35rem; border-radius:2rem;
  font-size:.72rem; font-weight:700; line-height:1.4; text-align:center }

/* ── Nagłówek strony (lokalny, jednowierszowy) ──────────────────────────── */
.ib-header { display:flex; align-items:center; justify-content:space-between;
  gap:1rem; flex-wrap:wrap; margin-bottom:.9rem }
.ib-header-title { display:flex; align-items:center; gap:.5rem;
  font-size:1.22rem; font-weight:700; color:#111827; line-height:1.2 }
.ib-header-actions { display:flex; gap:.4rem; flex-wrap:wrap; align-items:center; flex-shrink:0 }
.ib-help { color:#9CA3AF; font-size:.9rem; cursor:help; display:inline-flex }
.ib-help:hover, .ib-help:focus-visible { color:var(--crm-primary) }
</style>

<?php /* Nagłówek LOKALNY, jednowierszowy — .crm-page-header jest komponentem
         współdzielonym przez 16 stron CRM i układa tytuł nad podtytułem, co na tym
         ekranie kosztowało cały wiersz przed listą. Opis modułu nie znika: siedzi
         w podpowiedzi przy tytule, bo przydaje się raz, a zabierał miejsce zawsze. */ ?>
<div class="ib-header">
  <div class="ib-header-title">
    <i class="bi bi-inbox-fill" style="color:#0176D3" aria-hidden="true"></i>
    <span>Skrzynka CRM</span>
    <span class="ib-help" tabindex="0" role="note"
          aria-label="Wspólna skrzynka odbiorcza — obsłuż wiadomość w CRM, przekaż do EZD albo dalej e-mailem"
          title="Wspólna skrzynka odbiorcza — obsłuż wiadomość w CRM, przekaż do EZD albo dalej e-mailem">
      <i class="bi bi-question-circle" aria-hidden="true"></i>
    </span>
  </div>
  <div class="ib-header-actions">
    <?php if (is_admin()): ?>
    <form method="post" class="d-flex gap-1">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_op" value="scan">
      <input type="hidden" name="view" value="<?= h($view) ?>">
      <?php if (count($boxes) > 1): ?>
      <select name="scan_mailbox" class="form-select form-select-sm" style="max-width:210px" aria-label="Skrzynka do sprawdzenia">
        <option value="">wszystkie skrzynki</option>
        <?php foreach ($boxes as $b): ?>
        <option value="<?= (int)$b['id'] ?>"><?= h($b['mailbox']) ?></option>
        <?php endforeach; ?>
      </select>
      <?php endif; ?>
      <button class="btn btn-crm-outline btn-sm" <?= $ready && $boxes ? '' : 'disabled' ?>>
        <i class="bi bi-arrow-repeat me-1"></i>Sprawdź teraz
      </button>
    </form>
    <a href="<?= APP_URL ?>/poczta/index.php" class="btn btn-crm-ghost btn-sm" title="Konfiguracja skrzynek"><i class="bi bi-gear"></i></a>
    <?php if (crm_mailbox_autocreate()): ?>
    <!-- Stan „włączone" nie zasługuje na osobny wiersz nad listą — to potwierdzenie,
         nie ostrzeżenie. Wariant WYŁĄCZONY zostaje pełnym alertem niżej, bo mówi
         o wiadomościach, które są pomijane. -->
    <form method="post" class="d-inline-flex align-items-center gap-1" style="font-size:.76rem">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_op" value="autocreate">
      <input type="hidden" name="view" value="<?= h($view) ?>">
      <span class="text-success text-nowrap" title="Wiadomości od nieznanych nadawców zakładają kartotekę kontaktu">
        <i class="bi bi-check-circle-fill" aria-hidden="true"></i> auto-kartoteka
      </span>
      <button class="btn btn-link btn-sm p-0 text-muted" name="on" value="" style="font-size:.76rem">wyłącz</button>
    </form>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php if (!$ready): ?>
<div class="alert alert-warning">
  <strong>Moduł poczty nie jest gotowy.</strong> Skrzynka CRM czyta wiadomości pobierane przez moduł poczty.
  Skonfiguruj skrzynkę w <a href="<?= APP_URL ?>/poczta/index.php">module Poczta</a>.
</div>
<?php elseif (!$boxes): ?>
<?php $any_mailbox = 0; try { $any_mailbox = (int)(db_one("SELECT COUNT(*) AS n FROM poczta_mailboxes")['n'] ?? 0); } catch (\Throwable $e) {} ?>
<div class="alert alert-info">
  <?php if ($any_mailbox && !is_admin()): ?>
  <strong>Nie masz dostępu do żadnej skrzynki.</strong>
  Skrzynki współdzielone przydziela administrator (Poczta → Skrzynki → Edytuj → „Kto ma dostęp"),
  a skrzynka osobista jest widoczna dla swojego właściciela.
  <?php else: ?>
  Nie dodano jeszcze żadnej skrzynki — dodaj ją w <a href="<?= APP_URL ?>/poczta/index.php">module Poczta</a>.
  Wiadomości pojawią się tu po pierwszym skanowaniu.
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if (is_admin() && !crm_mailbox_autocreate()): ?>
<form method="post" class="alert alert-light border d-flex align-items-center gap-2 flex-wrap py-2">
  <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
  <input type="hidden" name="_op" value="autocreate">
  <input type="hidden" name="view" value="<?= h($view) ?>">
  <input type="hidden" name="on" value="1">
  <i class="bi bi-info-circle" aria-hidden="true"></i>
  <div class="flex-grow-1" style="font-size:.85rem">
    Wiadomości od nadawców spoza kartoteki są <strong>pomijane</strong> — zapytania nowych klientów nie trafiają do CRM.
  </div>
  <button class="btn btn-sm btn-outline-dark">Zakładaj kartotekę dla nieznanych nadawców</button>
</form>
<?php endif; ?>

<!-- Widoki -->
<div class="d-flex flex-wrap gap-2 mb-3">
  <?php foreach (CRM_MAILBOX_VIEWS as $vk => $vv): $n = (int)($counts[$vk] ?? 0); ?>
  <a href="?<?= $qs(['view' => $vk, 'msg' => null, 'page' => null]) ?>" class="ib-pill"
     style="background:<?= $view === $vk ? 'var(--crm-primary-bg)' : '#F3F4F6' ?>;
            color:<?= $view === $vk ? 'var(--crm-primary)' : '#374151' ?>;
            border-color:<?= $view === $vk ? 'var(--crm-primary)' : 'transparent' ?>">
    <i class="bi <?= $vv['icon'] ?>" aria-hidden="true"></i><?= h($vv['label']) ?>
    <?php
      // Licznik pokazujemy ZAWSZE, wyszarzony przy zerze. Ukrywanie zera sprawiało,
      // że „Spam" wyglądał na widok bez licznika, a nie na widok pusty.
      $cnt_style = $n
        ? ($view === $vk ? 'background:var(--crm-primary);color:#fff' : 'background:#E5E7EB;color:#374151')
        : 'background:transparent;color:#9CA3AF;border:1px solid #E5E7EB';
    ?>
    <span class="ib-cnt" style="<?= $cnt_style ?>"><?= $n ?></span>
  </a>
  <?php endforeach; ?>
  <form method="get" class="d-flex gap-1 ms-auto">
    <input type="hidden" name="view" value="<?= h($view) ?>">
    <div class="input-group input-group-sm" style="max-width:280px">
      <span class="input-group-text bg-white"><i class="bi bi-search text-muted"></i></span>
      <input name="q" class="form-control" placeholder="Temat, nadawca, kontakt…" value="<?= h($search) ?>">
    </div>
    <?php if (count($boxes) > 1): ?>
    <select name="mailbox_id" class="form-select form-select-sm" style="max-width:190px" aria-label="Skrzynka">
      <option value="">wszystkie skrzynki</option>
      <?php foreach ($boxes as $b): ?>
      <option value="<?= (int)$b['id'] ?>" <?= $mbox_f === (int)$b['id'] ? 'selected' : '' ?>><?= h($b['mailbox']) ?></option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <button class="btn btn-outline-secondary btn-sm"><i class="bi bi-funnel"></i></button>
    <?php if ($search || $mbox_f): ?>
    <a href="?view=<?= h($view) ?>" class="btn btn-outline-secondary btn-sm" title="Wyczyść"><i class="bi bi-x"></i></a>
    <?php endif; ?>
  </form>
</div>

<div class="ib-wrap">

  <!-- ══ LISTA ═══════════════════════════════════════════════════════════ -->
  <div>
    <div class="ib-list" role="list" aria-label="Wiadomości">
      <?php if (!$inbox['rows']): ?>
      <div class="text-center text-muted d-flex flex-column justify-content-center" style="min-height:22rem;padding:1.15rem">
        <div>
          <i class="bi bi-inbox display-6 d-block mb-2 opacity-25" aria-hidden="true"></i>
          <div style="font-size:.9rem">Brak wiadomości w tym widoku</div>
          <div style="font-size:.78rem" class="mt-1">Zmień filtr albo sprawdź skrzynkę ponownie.</div>
        </div>
      </div>
      <?php else: $last_day = ''; foreach ($inbox['rows'] as $r):
        $day = $day_label((string)$r['sent_at']);
        if ($day !== $last_day) { $last_day = $day; echo '<div class="ib-day">' . h($day) . '</div>'; }
        $act    = (int)$r['id'] === $sel_id;
        $who    = (string)($r['from_name'] ?: $r['from_email'] ?: $r['contact_name'] ?: '—');
        $unread = !(int)$r['is_read'];
      ?>
      <a role="listitem" class="ib-item<?= $act ? ' active' : '' ?><?= $unread ? ' unread' : '' ?>"
         href="?<?= $qs(['msg' => (int)$r['id']]) ?>" aria-current="<?= $act ? 'true' : 'false' ?>">
        <span class="ib-av" aria-hidden="true"><?= h(CrmManager::makeInitials($who)) ?></span>
        <span class="ib-mid">
          <span class="ib-top">
            <span class="ib-subj"><?= h($r['subject'] ?: '(bez tematu)') ?></span>
            <?php if ((int)$r['has_attachments']): ?><i class="bi bi-paperclip text-muted" aria-label="Załącznik"></i><?php endif; ?>
            <span class="ib-time"><?= h(date('H:i', strtotime((string)$r['sent_at']))) ?></span>
          </span>
          <span class="ib-who d-block"><?= h($who) ?></span>
          <span class="ib-snip d-block"><?= h(mb_substr(trim(preg_replace('/\s+/u', ' ', (string)$r['body'])), 0, 90)) ?></span>
          <?php if (!empty($r['assigned_name']) || $r['inbox_status'] !== 'active' || $unread): ?>
          <span class="ib-tags">
            <?php if ($unread): ?><span class="ib-tag" style="background:var(--crm-primary-bg);color:var(--crm-primary)">nowa</span><?php endif; ?>
            <?php if (!empty($r['assigned_name'])): ?>
            <span class="ib-tag" style="background:#EEF2FF;color:#4338CA"><?= h($r['assigned_name']) ?></span>
            <?php endif; ?>
            <?php if ($r['inbox_status'] === 'archived'): ?>
            <span class="ib-tag" style="background:#EFF7ED;color:#2E844A">załatwione</span>
            <?php elseif ($r['inbox_status'] === 'spam'): ?>
            <span class="ib-tag" style="background:#FEF2F2;color:#DC2626">spam</span>
            <?php endif; ?>
          </span>
          <?php endif; ?>
        </span>
      </a>
      <?php endforeach; endif; ?>
    </div>

    <?php $pages = (int)ceil($inbox['total'] / max(1, $inbox['per_page'])); if ($pages > 1): ?>
    <div class="d-flex justify-content-between align-items-center mt-2">
      <small class="text-muted">Łącznie: <strong><?= (int)$inbox['total'] ?></strong></small>
      <div class="d-flex gap-1 flex-wrap">
        <?php for ($p = 1; $p <= min($pages, 12); $p++): ?>
        <a href="?<?= $qs(['page' => $p, 'msg' => null]) ?>"
           class="btn btn-sm <?= $p === (int)$inbox['page'] ? 'btn-primary' : 'btn-outline-secondary' ?>"><?= $p ?></a>
        <?php endfor; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <!-- ══ WIADOMOŚĆ ═══════════════════════════════════════════════════════ -->
  <div>
  <?php if (!$msg): ?>
    <div class="ib-pane ib-empty">
      <div class="text-center text-muted">
        <i class="bi bi-envelope-open display-6 d-block mb-2 opacity-25" aria-hidden="true"></i>
        <div style="font-size:.9rem">Wybierz wiadomość z listy</div>
        <div style="font-size:.78rem" class="mt-1">
          <?= $inbox['rows'] ? 'Treść, załączniki i akcje pokażą się tutaj.' : 'W tym widoku nie ma wiadomości.' ?>
        </div>
      </div>
    </div>
  <?php else:
    $hidden = '<input type="hidden" name="_csrf" value="' . csrf_token() . '">'
            . '<input type="hidden" name="msg_id" value="' . (int)$msg['id'] . '">'
            . '<input type="hidden" name="view" value="' . h($view) . '">'
            . '<input type="hidden" name="q" value="' . h($search) . '">';
  ?>
    <div class="ib-pane">

      <div class="ib-head">
        <h1 class="ib-h1"><?= h($msg['subject'] ?: '(bez tematu)') ?></h1>
        <div class="ib-meta">
          <strong><?= h($msg['from_name'] ?: ($msg['from_email'] ?: '—')) ?></strong>
          <?php if (!empty($msg['from_email']) && $msg['from_name']): ?>
          &lt;<?= h($msg['from_email']) ?>&gt;
          <?php endif; ?>
          · <?= h(date('d.m.Y H:i', strtotime((string)$msg['sent_at']))) ?>
          <?php if (!empty($msg['mailbox_name'])): ?> · na <?= h($msg['mailbox_name']) ?><?php endif; ?>
        </div>
        <div class="d-flex gap-2 flex-wrap mt-2">
          <?php if (!empty($msg['contact_id'])): ?>
          <a class="ib-chip" href="<?= APP_URL ?>/crm/contact/view.php?id=<?= (int)$msg['contact_id'] ?>">
            <i class="bi <?= h(CRM_CONTACT_TYPES[$msg['contact_type']]['icon'] ?? 'bi-person') ?>"></i>
            <?= h($msg['contact_name']) ?>
          </a>
          <?php endif; ?>
          <?php if (!empty($msg['assigned_name'])): ?>
          <span class="ib-chip" style="background:#EEF2FF;color:#4338CA">
            <i class="bi bi-person-check-fill"></i><?= h($msg['assigned_name']) ?>
          </span>
          <?php endif; ?>
          <?php if (!empty($msg['ezd_sprawa_id'])): ?>
          <a class="ib-chip" style="background:#ECFDF5;color:#0F766E"
             href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= (int)$msg['ezd_sprawa_id'] ?>">
            <i class="bi bi-folder-symlink"></i>w EZD
          </a>
          <?php endif; ?>
          <?php if ($msg['inbox_status'] !== 'active'): ?>
          <span class="ib-chip"><?= $msg['inbox_status'] === 'archived' ? 'załatwione' : h($msg['inbox_status']) ?></span>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($can_write): ?>
      <div class="ib-bar">
        <?php if ((int)($msg['assigned_to'] ?? 0) !== $uid): ?>
        <form method="post"><?= $hidden ?><input type="hidden" name="_op" value="assign_me">
          <button class="btn btn-crm-primary btn-sm"><i class="bi bi-person-check me-1"></i>Wezmę to</button></form>
        <?php endif; ?>
        <?php if (!empty($msg['contact_id'])): ?>
        <button class="btn btn-crm-outline btn-sm" onclick="openCommModal(<?= (int)$msg['contact_id'] ?>,'email')">
          <i class="bi bi-reply me-1"></i>Odpowiedz
        </button>
        <?php endif; ?>
        <?php if ($msg['inbox_status'] === 'active'): ?>
        <form method="post"><?= $hidden ?><input type="hidden" name="_op" value="archive">
          <button class="btn btn-crm-outline btn-sm"><i class="bi bi-check2-all me-1"></i>Załatwione</button></form>
        <?php else: ?>
        <form method="post"><?= $hidden ?><input type="hidden" name="_op" value="restore">
          <button class="btn btn-crm-outline btn-sm"><i class="bi bi-arrow-counterclockwise me-1"></i>Przywróć</button></form>
        <?php endif; ?>
        <form method="post"><?= $hidden ?><input type="hidden" name="_op" value="unread">
          <button class="btn btn-crm-ghost btn-sm" title="Oznacz jako nieprzeczytane"><i class="bi bi-envelope"></i></button></form>
        <form method="post"><?= $hidden ?><input type="hidden" name="_op" value="spam">
          <button class="btn btn-crm-ghost btn-sm" title="Oznacz jako spam"><i class="bi bi-slash-circle"></i></button></form>
        <?php if ($can_write): ?>
        <!-- Skróty do trzech głównych akcji. Formularze zostają niżej, bo wymagają
             pól, ale przy długiej wiadomości były poza ekranem — a to one są
             powodem, dla którego ktoś tu wchodzi. Klik rozwija i przewija do
             właściwej szuflady. -->
        <span class="ib-sep" aria-hidden="true"></span>
        <button type="button" class="btn btn-crm-outline btn-sm" data-bs-toggle="modal" data-bs-target="#mdCase"
                title="Załóż sprawę CRM z tej wiadomości">
          <i class="bi bi-briefcase-fill me-1" style="color:#1D4ED8" aria-hidden="true"></i>Sprawa
        </button>
        <button type="button" class="btn btn-crm-outline btn-sm" data-bs-toggle="modal" data-bs-target="#mdEzd"
                title="Przekaż wiadomość do EZD">
          <i class="bi bi-folder-symlink-fill me-1" style="color:#0F766E" aria-hidden="true"></i>EZD
        </button>
        <button type="button" class="btn btn-crm-outline btn-sm" data-bs-toggle="modal" data-bs-target="#mdFwd"
                title="Przekaż wiadomość e-mailem">
          <i class="bi bi-forward-fill me-1" style="color:#B45309" aria-hidden="true"></i>Przekaż
        </button>
        <?php endif; ?>
        <form method="post" class="ms-auto"><?= $hidden ?><input type="hidden" name="_op" value="assign">
          <select name="user_id" class="form-select form-select-sm" style="max-width:200px"
                  aria-label="Przypisz osobę" onchange="this.form.submit()">
            <option value="">— przypisz osobę —</option>
            <?php foreach ($users as $u): ?>
            <option value="<?= (int)$u['id'] ?>" <?= (int)($msg['assigned_to'] ?? 0) === (int)$u['id'] ? 'selected' : '' ?>>
              <?= h($u['name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </form>
      </div>
      <?php endif; ?>

      <?php if (!empty($msg['attachments'])): ?>
      <div class="ib-ctx" style="border-top:none">
        <div class="ib-lbl">Załączniki</div>
        <?php foreach ($msg['attachments'] as $a): ?>
          <?php if (!empty($a['stored_path'])): ?>
          <a href="<?= h(upload_link($a['stored_path'])) ?>" target="_blank" class="me-2">
            <i class="bi bi-paperclip"></i> <?= h($a['original_name']) ?>
          </a>
          <?php else: ?>
          <span class="text-muted me-2" title="Plik nie został pobrany na serwer">
            <i class="bi bi-paperclip"></i> <?= h($a['original_name']) ?>
          </span>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <div class="ib-body">
        <?php if (!empty($msg['body_html'])):
          // Treść z zewnątrz — zostawiamy formatowanie, wycinamy wykonywalne elementy.
          $html = (string)$msg['body_html'];
          $html = preg_replace('#<(script|style|iframe|object|embed|form)\b[^>]*>.*?</\1>#is', '', $html);
          $html = preg_replace('#<(script|style|iframe|object|embed|form|link|meta)\b[^>]*/?>#is', '', $html);
          $html = preg_replace('#\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i', '', $html);
          $html = preg_replace('#(href|src)\s*=\s*("|\')\s*javascript:[^"\']*\2#i', '$1="#"', $html);
          echo $html;
        else: ?>
          <?= nl2br(h((string)$msg['body'])) ?>
        <?php endif; ?>
      </div>

      <?php if ($can_write): ?>
      <?php
        /* Formularze akcji buforujemy TUTAJ, a pokazujemy w modalach na końcu
           strony. Znaczniki żyją w jednym miejscu (bez duplikacji), a okno nie
           zależy od przewinięcia długiej wiadomości. Modale muszą stać POZA
           .ib-wrap — modal w kontenerze z overflow potrafi się źle pozycjonować. */
        ob_start(); ?>
        <div class="ib-dbody">
          <form method="post" class="row g-2 align-items-end"><?= $hidden ?>
            <input type="hidden" name="_op" value="case">
            <div class="col-md-6">
              <label class="form-label small fw-semibold mb-1">Tytuł sprawy</label>
              <input name="case_title" class="form-control form-control-sm"
                     value="<?= h($msg['subject'] ?: 'Wiadomość e-mail') ?>">
            </div>
            <div class="col-md-3">
              <label class="form-label small fw-semibold mb-1">Priorytet</label>
              <select name="case_priority" class="form-select form-select-sm">
                <option value="low">Niski</option>
                <option value="medium" selected>Średni</option>
                <option value="high">Wysoki</option>
              </select>
            </div>
            <div class="col-md-3">
              <label class="form-label small fw-semibold mb-1">Prowadzi</label>
              <select name="case_owner" class="form-select form-select-sm">
                <option value="">ja</option>
                <?php foreach ($users as $u): ?>
                <option value="<?= (int)$u['id'] ?>"><?= h($u['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-8">
              <div class="form-check">
                <input class="form-check-input" type="checkbox" name="case_keep_open" value="1" id="ckopen">
                <label class="form-check-label small" for="ckopen">Zostaw wiadomość w skrzynce (domyślnie idzie do „załatwionych")</label>
              </div>
            </div>
            <div class="col-md-4 text-end">
              <button class="btn btn-crm-primary btn-sm"><i class="bi bi-plus-lg me-1"></i>Utwórz sprawę</button>
            </div>
          </form>
        </div>
      <?php $mdl_case = ob_get_clean(); ob_start(); ?>
        <div class="ib-dbody">
          <?php if (!$ezd_on): ?>
            <div class="text-muted" style="font-size:.85rem">
              Moduł EZD jest wyłączony albo nie masz w nim uprawnień do zapisu — przekazanie niedostępne.
            </div>
          <?php else: ?>
            <div class="row g-3">
              <div class="col-md-6">
                <form method="post"><?= $hidden ?><input type="hidden" name="_op" value="ezd">
                  <div class="ib-lbl">Dopnij do istniejącej koszulki</div>
                  <div class="input-group input-group-sm">
                    <select name="sprawa_id" class="form-select" required>
                      <option value="">— wybierz koszulkę —</option>
                      <?php foreach ($ezd_sprawy as $sp): ?>
                      <option value="<?= (int)$sp['id'] ?>">
                        <?= h($sp['znak_sprawy']) ?> — <?= h(mb_substr((string)$sp['title'], 0, 60)) ?>
                      </option>
                      <?php endforeach; ?>
                    </select>
                    <button class="btn btn-crm-outline"><i class="bi bi-link-45deg"></i> Dopnij</button>
                  </div>
                  <div class="form-text" style="font-size:.72rem">
                    Powstanie pismo w rejestrze EZD, wiadomość trafi do „załatwionych".
                  </div>
                </form>
              </div>
              <div class="col-md-6">
                <form method="post"><?= $hidden ?><input type="hidden" name="_op" value="ezd">
                  <div class="ib-lbl">Albo załóż nową koszulkę</div>
                  <div class="input-group input-group-sm">
                    <select name="teczka_id" class="form-select" required>
                      <option value="">— segregator (teczka) —</option>
                      <?php foreach ($ezd_teczki as $t): ?>
                      <option value="<?= (int)$t['id'] ?>">
                        <?= h($t['symbol']) ?> — <?= h(mb_substr((string)$t['title'], 0, 50)) ?>
                      </option>
                      <?php endforeach; ?>
                    </select>
                    <button class="btn btn-crm-outline"><i class="bi bi-folder-plus"></i> Załóż</button>
                  </div>
                  <div class="form-text" style="font-size:.72rem">
                    Znak sprawy nadaje EZD — temat wiadomości staje się tytułem koszulki.
                  </div>
                </form>
              </div>
            </div>
          <?php endif; ?>
        </div>
      <?php $mdl_ezd = ob_get_clean(); ob_start(); ?>
        <div class="ib-dbody">
          <form method="post" class="row g-2 align-items-end"><?= $hidden ?>
            <input type="hidden" name="_op" value="forward">
            <div class="col-md-5">
              <label class="form-label small fw-semibold mb-1">Adres odbiorcy</label>
              <input name="fwd_to" type="email" class="form-control form-control-sm" required
                     placeholder="np. ksiegowosc@feer.org.pl" list="fwdList">
              <datalist id="fwdList">
                <?php foreach ($users as $u): $ue = db_one("SELECT email FROM users WHERE id=?", [(int)$u['id']])['email'] ?? '';
                  if ($ue): ?><option value="<?= h($ue) ?>"><?= h($u['name']) ?></option><?php endif; endforeach; ?>
              </datalist>
            </div>
            <div class="col-md-5">
              <label class="form-label small fw-semibold mb-1">Notatka dla odbiorcy</label>
              <input name="fwd_note" class="form-control form-control-sm" placeholder="np. proszę o wycenę do piątku">
            </div>
            <div class="col-md-2 text-end">
              <button class="btn btn-crm-outline btn-sm w-100"><i class="bi bi-send me-1"></i>Przekaż</button>
            </div>
            <div class="col-12 form-text" style="font-size:.72rem">
              Wysyłamy oryginał z cytatem i notatką; wpis o przekazaniu trafia do historii kontaktu.
            </div>
          </form>
        </div>
      <?php $mdl_fwd = ob_get_clean(); ?>
      <?php endif; ?>

      <?php if (!empty($msg['ctx']['cases']) || !empty($msg['ctx']['offers']) || count($msg['ctx']['thread']) > 1): ?>
      <div class="ib-ctx">
        <div class="row g-3">
          <?php if ($msg['ctx']['cases']): ?>
          <div class="col-md-4">
            <div class="ib-lbl">Sprawy kontaktu</div>
            <?php foreach ($msg['ctx']['cases'] as $c): ?>
            <div><a href="<?= APP_URL ?>/crm/cases/view.php?id=<?= (int)$c['id'] ?>"><?= h($c['title']) ?></a></div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
          <?php if ($msg['ctx']['offers']): ?>
          <div class="col-md-4">
            <div class="ib-lbl">Oferty</div>
            <?php foreach ($msg['ctx']['offers'] as $o): ?>
            <div><a href="<?= APP_URL ?>/crm/offers/view.php?id=<?= (int)$o['id'] ?>"><?= h($o['offer_number']) ?></a>
              — <?= h(number_format((float)$o['total_gross'], 0, ',', ' ')) ?> <?= h($o['currency']) ?></div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
          <?php if (count($msg['ctx']['thread']) > 1): ?>
          <div class="col-md-4">
            <div class="ib-lbl">Wątek (<?= count($msg['ctx']['thread']) ?>)</div>
            <?php foreach ($msg['ctx']['thread'] as $t): ?>
            <div>
              <a href="?<?= $qs(['msg' => (int)$t['id']]) ?>" class="<?= (int)$t['id'] === $sel_id ? 'fw-bold' : '' ?>">
                <i class="bi bi-<?= $t['direction'] === 'in' ? 'arrow-down-left' : 'arrow-up-right' ?>"></i>
                <?= h(date('d.m.Y H:i', strtotime((string)$t['sent_at']))) ?>
              </a>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
  </div>
</div>

<?php if ($msg && $can_write): ?>
<?php
  /* Modale akcji — POZA .ib-wrap, bo w kontenerze siatki z overflow Bootstrap
     pozycjonuje je względem rodzica i okno potrafi zostać przycięte. */
  $mdls = [
      'mdCase' => ['Załóż sprawę CRM',  'bi-briefcase-fill',       '#1D4ED8', $mdl_case ?? ''],
      'mdEzd'  => ['Przekaż do EZD',    'bi-folder-symlink-fill',  '#0F766E', $mdl_ezd  ?? ''],
      'mdFwd'  => ['Przekaż e-mailem',  'bi-forward-fill',         '#B45309', $mdl_fwd  ?? ''],
  ];
?>
<?php foreach ($mdls as $mid => [$mtitle, $micon, $mcolor, $mbody]): if ($mbody === '') continue; ?>
<div class="modal fade" id="<?= h($mid) ?>" tabindex="-1" aria-labelledby="<?= h($mid) ?>Label" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h5 class="modal-title fw-bold" id="<?= h($mid) ?>Label" style="font-size:1rem">
          <i class="bi <?= h($micon) ?> me-2" style="color:<?= h($mcolor) ?>" aria-hidden="true"></i><?= h($mtitle) ?>
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body pt-2">
        <div class="text-muted mb-2" style="font-size:.8rem">
          <?= h(mb_strimwidth((string)($msg['subject'] ?: '(bez tematu)'), 0, 90, '…')) ?>
        </div>
        <?= $mbody ?>
      </div>
    </div>
  </div>
</div>
<?php endforeach; ?>
<script>
// Kursor w pierwszym polu po otwarciu okna — bez tego trzeba klikać w formularz.
['mdCase','mdEzd','mdFwd'].forEach(function (id) {
  var m = document.getElementById(id);
  if (!m) return;
  m.addEventListener('shown.bs.modal', function () {
    var f = m.querySelector('input:not([type=hidden]):not([type=checkbox]), select, textarea');
    if (f) f.focus();
  });
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/includes/footer_crm.php'; ?>
