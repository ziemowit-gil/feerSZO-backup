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
 *   • w dzienniku EZD — rejestracja: wpis do dziennika podawczego i sygnatura,
 *                       przy istniejącej koszulce albo w nowo założonej,
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
require_once dirname(__DIR__) . '/includes/crm_sender_trust.php';
require_once dirname(__DIR__) . '/includes/crm_sender.php';   // user_pref() — zapamiętany wybór widoku
require_once dirname(__DIR__) . '/includes/nozbe.php';
require_once dirname(__DIR__) . '/includes/crm_case_extras.php';
require_once dirname(__DIR__) . '/includes/crm_perms.php';
require_once dirname(__DIR__) . '/modules/crm_inbox/lib/inboxView.php';

require_login();
require_module_enabled('crm_enabled', 'Moduł CRM');
crm_require('inbox', 'read');
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
            'unread'     => $_POST['unread'] ?? null,
            'att'        => $_POST['att'] ?? null,
            'msg'        => $mid ?: null,
        ]));

        switch ($op) {
            case 'read':      crm_mailbox_mark_read($mid, true);       flash_set('success', 'Oznaczono jako przeczytane.'); break;
            case 'unread':    crm_mailbox_mark_read($mid, false);      flash_set('success', 'Oznaczono jako nieprzeczytane.'); break;
            case 'archive':   crm_mailbox_set_status($mid, 'archived'); flash_set('success', 'Wiadomość załatwiona.'); break;
            case 'spam':      crm_mailbox_set_status($mid, 'spam');     flash_set('success', 'Oznaczono jako spam.'); break;
            case 'restore':   crm_mailbox_set_status($mid, 'active');   flash_set('success', 'Przywrócono do skrzynki.'); break;
            case 'assign_me': crm_mailbox_assign($mid, $uid);           flash_set('success', 'Przypisano Tobie.'); break;
            case 'hide':      crm_mailbox_set_hidden($mid, true);
                              flash_set('success', 'Wiadomość nie będzie już pokazywana w Skrzynce CRM (widok „Ukryte").');
                              $back = 'inbox.php?' . http_build_query(array_filter([
                                  'view' => $_POST['view'] ?? null, 'mailbox_id' => $_POST['mailbox_id'] ?? null,
                              ]));
                              break;
            case 'unhide':    crm_mailbox_set_hidden($mid, false); flash_set('success', 'Wiadomość wróciła do Skrzynki CRM.'); break;
            case 'fetch_body':
                $r = crmInboxFetchFullBody($mid);
                flash_set(!empty($r['ok']) ? 'success' : 'danger', !empty($r['ok'])
                    ? 'Pobrano pełną treść wiadomości ze skrzynki.' : ($r['error'] ?? 'Nie udało się pobrać treści.'));
                break;

            case 'attach_case':
                $r = crm_case_attach_message($mid, (int)($_POST['case_id'] ?? 0));
                flash_set(!empty($r['ok']) ? 'success' : 'danger', !empty($r['ok'])
                    ? (!empty($r['detached'])
                        ? 'Wiadomość odpięta od sprawy.'
                        : 'Wiadomość dopięta do sprawy „' . ($r['case']['title'] ?? '') . '".')
                    : ($r['error'] ?: 'Nie udało się dopiąć wiadomości.'));
                break;

            case 'nozbe':
                if (!nozbe_configured()) { flash_set('warning', 'Integracja z Nozbe nie jest skonfigurowana.'); break; }
                $nz = nozbe_push_crm_message($mid);
                if (!empty($nz['ok'])) {
                    flash_set('success', !empty($nz['existing'])
                        ? 'Ta wiadomość jest już w Nozbe.'
                        : 'Zadanie utworzone w Nozbe.');
                } else {
                    flash_set('danger', 'Nozbe: ' . ($nz['error'] ?: 'nie udało się utworzyć zadania.'));
                }
                break;

            case 'mark_all_read':
                $n = crm_mailbox_mark_all_read([
                    'view'       => $_POST['view'] ?? null,
                    'mailbox_id' => $_POST['mailbox_id'] ?? null,
                ]);
                flash_set($n ? 'success' : 'info', $n
                    ? 'Oznaczono jako przeczytane: ' . $n . ' wiad.'
                    : 'W tym widoku nie ma nieprzeczytanych wiadomości.');
                $back = 'inbox.php?' . http_build_query(array_filter([
                    'view' => $_POST['view'] ?? null, 'mailbox_id' => $_POST['mailbox_id'] ?? null,
                    'q'    => $_POST['q'] ?? null,
                ]));
                break;

            case 'bulk_read':
                $ids = array_slice(array_unique(array_map('intval', (array)($_POST['msg_ids'] ?? []))), 0, 500);
                $n = 0;
                // crm_mailbox_mark_read() nic nie zwraca — liczymy wywołania, nie wynik
                foreach ($ids as $bid) { if ($bid > 0) { crm_mailbox_mark_read($bid, true); $n++; } }
                flash_set($n ? 'success' : 'warning', $n
                    ? 'Oznaczono jako przeczytane: ' . $n . ' wiad.'
                    : 'Nie zaznaczono żadnej wiadomości.');
                $back = 'inbox.php?' . http_build_query(array_filter([
                    'view' => $_POST['view'] ?? null, 'mailbox_id' => $_POST['mailbox_id'] ?? null,
                    'q'    => $_POST['q'] ?? null,
                ]));
                break;

            case 'bulk_hide':
            case 'bulk_unhide':
                $ids  = array_slice(array_unique(array_map('intval', (array)($_POST['msg_ids'] ?? []))), 0, 500);
                $hide = $op === 'bulk_hide';
                $n = 0;
                foreach ($ids as $bid) { if ($bid > 0 && crm_mailbox_set_hidden($bid, $hide)) $n++; }
                if (!$n) flash_set('warning', 'Nie zaznaczono żadnej wiadomości.');
                else flash_set('success', $hide
                    ? 'Porzucono ' . $n . ' wiad. — zniknęły ze Skrzynki CRM (widok „Ukryte").'
                    : 'Przywrócono ' . $n . ' wiad. do Skrzynki CRM.');
                $back = 'inbox.php?' . http_build_query(array_filter([
                    'view' => $_POST['view'] ?? null, 'mailbox_id' => $_POST['mailbox_id'] ?? null,
                    'q'    => $_POST['q'] ?? null,
                ]));
                break;

            case 'assign':
                crm_mailbox_assign($mid, (int)($_POST['user_id'] ?? 0) ?: null);
                flash_set('success', 'Przypisanie zmienione.');
                break;

            case 'case':
                $r = crm_mailbox_create_case($mid, [
                    'title'      => (string)($_POST['case_title'] ?? ''),
                    'priority'   => (string)($_POST['case_priority'] ?? 'medium'),
                    'assign_to'  => (int)($_POST['case_owner'] ?? 0),
                    'keep_open'  => !empty($_POST['case_keep_open']),
                    // Domyślnie sprawa idzie na kontakt nadawcy maila — pole jest
                    // widoczne w formularzu tylko po to, by dało się to nadpisać
                    // (np. sprawa dotyczy kogoś, kogo nadawca tylko reprezentuje).
                    'contact_id' => (int)($_POST['case_contact_id'] ?? 0) ?: null,
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
                    (int)($_POST['teczka_id'] ?? 0) ?: null,
                    [
                        'name'   => (string)($_POST['strona_name'] ?? ''),
                        'crm_id' => (int)($_POST['strona_crm_id'] ?? 0) ?: null,
                        'rola'   => (string)($_POST['strona_rola'] ?? ''),
                    ]
                );
                if (!empty($r['ok'])) {
                    flash_set('success', 'Wiadomość zarejestrowana w dzienniku EZD' . ($r['znak'] ? ' — ' . $r['znak'] : '') . '.'
                        . (!empty($r['notified']) ? ' Nadawca dostał e-mail o zarejestrowaniu sprawy.' : ''));
                    header('Location: ' . ($r['url'] ?: $back)); exit;
                }
                flash_set('danger', $r['error'] ?: 'Nie udało się zarejestrować w dzienniku EZD.');
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
        // Akcja wysłana skryptem (przeglądanie bez przeładowania) — zamiast
        // przekierowania krótka odpowiedź JSON z komunikatem dla paska u dołu.
        if (!empty($_POST['_xhr'])) {
            $f = flash_get() ?? ['type' => 'success', 'msg' => ''];
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => !in_array($f['type'], ['danger', 'error'], true), 'type' => $f['type'],
                              'msg' => $f['msg'], 'op' => $op, 'id' => $mid], JSON_UNESCAPED_UNICODE);
            exit;
        }
        header('Location: ' . $back); exit;
    }
    if (!empty($_POST['_xhr'])) {
        flash_get();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'type' => 'danger', 'msg' => 'Brak uprawnień.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

// ── Filtry i dane ───────────────────────────────────────────────────────────
$view   = isset(CRM_MAILBOX_VIEWS[$_GET['view'] ?? '']) ? (string)$_GET['view'] : 'new';
$mbox_f = (int)($_GET['mailbox_id'] ?? 0);
$search = trim((string)($_GET['q'] ?? ''));
$page   = max(1, (int)($_GET['page'] ?? 1));
$sel_id = (int)($_GET['msg'] ?? 0);
// Szybkie filtry z paska: tylko nieprzeczytane, tylko z załącznikiem
$f_unread = !empty($_GET['unread']) && $view !== 'new';
$f_att    = !empty($_GET['att']);
// Wiadomość wskazana w adresie (a nie pierwsza z listy) = tryb czytania na telefonie
$explicit = $sel_id > 0;

// Grupowanie w wątki — wybór zapamiętany, bo to preferencja pracy, a nie filtr
// jednej wizyty. ?threads=0/1 przestawia, brak parametru czyta ostatni wybór.
if (isset($_GET['threads'])) user_pref_set('crm_inbox_threads', $_GET['threads'] === '1' ? '1' : '0');
$threads = user_pref('crm_inbox_threads', '1') === '1';
// Widok rozmowy: cały wątek w panelu czytania, bez przełączania się między wiadomościami
if (isset($_GET['conv'])) user_pref_set('crm_inbox_conv', $_GET['conv'] === '1' ? '1' : '0');
$conv_on = user_pref('crm_inbox_conv', '1') === '1';

$inbox  = crm_mailbox_inbox(['view' => $view, 'mailbox_id' => $mbox_f, 'q' => $search,
                             'unread' => $f_unread, 'att' => $f_att,
                             'page' => $page, 'per_page' => 30, 'group_threads' => $threads]);
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

// Sąsiedzi otwartej wiadomości na bieżącej stronie listy — „Poprzednia/Następna”
// działają też bez JS-a, a skróty j/k korzystają z tych samych linków.
$row_ids = array_map(static fn($r) => (int)$r['id'], $inbox['rows']);
$sel_pos = array_search($sel_id, $row_ids, true);
$prev_id = ($sel_pos !== false && $sel_pos > 0) ? $row_ids[$sel_pos - 1] : 0;
$next_id = ($sel_pos !== false && $sel_pos < count($row_ids) - 1) ? $row_ids[$sel_pos + 1] : 0;

// Autoryzacja nadawców dla całej listy naraz (domena organizacji + adresy z umów)
$trust_map = crm_sender_trust_bulk(array_merge(
    array_column($inbox['rows'], 'from_email'),
    [$msg['from_email'] ?? '']
));
$trust_of  = static function (?string $email) use ($trust_map): array {
    $e = strtolower(trim((string)$email));
    return $trust_map[$e] ?? ['level' => '', 'label' => '', 'title' => '', 'contract' => null];
};

// Szablony odpowiedzi — do przycisków „Odpowiedz szablonem" w pasku wiadomości.
$reply_tpls = [];
try {
    $reply_tpls = db_all("SELECT id, name, subject FROM crm_templates
                           WHERE is_active=1 AND channel='email' ORDER BY name LIMIT 12");
} catch (\Throwable $e) {}

$ezd_on     = crm_mailbox_ezd_available();
$ezd_sprawy = $msg ? crm_mailbox_ezd_sprawy('', 30) : [];
$ezd_teczki = $msg ? crm_mailbox_ezd_teczki() : [];

$qs = static function (array $over = []) use ($view, $mbox_f, $search, $sel_id, $f_unread, $f_att): string {
    return http_build_query(array_filter(array_merge([
        'view' => $view, 'mailbox_id' => $mbox_f ?: null, 'q' => $search ?: null,
        'unread' => $f_unread ? 1 : null, 'att' => $f_att ? 1 : null, 'msg' => $sel_id ?: null,
    ], $over), static fn($v) => $v !== null && $v !== ''));
};

/* Awatar z inicjałów: odcień liczony z nazwy (crc32 → hue), więc ten sam nadawca
   ma zawsze ten sam kolor — także po odświeżeniu listy. */
$ib_avatar = static function (string $name): array {
    $name = trim($name);
    $words = preg_split('/\s+/u', preg_replace('/^(Do:|Re:)\s*/iu', '', $name)) ?: [];
    $ini = '';
    foreach ($words as $w) { $c = mb_substr($w, 0, 1, 'UTF-8'); if ($c !== '' && preg_match('/[\p{L}\p{N}]/u', $c)) $ini .= mb_strtoupper($c, 'UTF-8'); if (mb_strlen($ini) >= 2) break; }
    if ($ini === '') $ini = '@';
    return [$ini, crc32(mb_strtolower($name, 'UTF-8')) % 360];
};

// Widoki: cztery codzienne na wierzchu, rzadsze w menu „Więcej”
$views_main = ['new', 'mine', 'unassigned', 'all'];
$views_more = array_values(array_diff(array_keys(CRM_MAILBOX_VIEWS), $views_main));

include __DIR__ . '/includes/header_crm.php';
?>
<link rel="stylesheet" href="<?= APP_URL ?>/modules/crm_inbox/view/inbox.css?v=<?= (int)@filemtime(dirname(__DIR__) . '/modules/crm_inbox/view/inbox.css') ?>">

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

<div class="ib-shell" id="ibShell">

<!-- ══ Nagłówek: tytuł, widoki, działania ═════════════════════════════════ -->
<div class="ib-top">
  <h1 class="ib-title">
    <span class="ib-title-ic" aria-hidden="true"><i class="bi bi-inbox-fill"></i></span>
    Skrzynka
    <span class="ib-help" tabindex="0" role="note"
          aria-label="Wspólna skrzynka odbiorcza — obsłuż wiadomość w CRM, zarejestruj w dzienniku EZD albo przekaż e-mailem"
          title="Wspólna skrzynka odbiorcza — obsłuż wiadomość w CRM, zarejestruj w dzienniku EZD albo przekaż e-mailem">
      <i class="bi bi-question-circle" aria-hidden="true"></i>
    </span>
  </h1>

  <nav class="ib-views" aria-label="Widoki skrzynki">
    <?php foreach ($views_main as $vk): $vv = CRM_MAILBOX_VIEWS[$vk]; $n = (int)($counts[$vk] ?? 0); ?>
    <a href="?<?= $qs(['view' => $vk, 'msg' => null, 'page' => null, 'unread' => null]) ?>" class="ib-pill<?= $view === $vk ? ' is-on' : '' ?>"
       <?= $view === $vk ? 'aria-current="page"' : '' ?>>
      <i class="bi <?= $vv['icon'] ?>" aria-hidden="true"></i><?= h($vv['label']) ?>
      <span class="ib-cnt<?= $n ? '' : ' is-zero' ?>" data-ib-cnt="<?= h($vk) ?>"><?= $n ?></span>
    </a>
    <?php endforeach; ?>
    <?php // Bieżący widok z „Więcej” pokazujemy też na wierzchu — stan nie może się chować w menu
          if (in_array($view, $views_more, true)): $vv = CRM_MAILBOX_VIEWS[$view]; $n = (int)($counts[$view] ?? 0); ?>
    <a href="?<?= $qs(['msg' => null, 'page' => null]) ?>" class="ib-pill is-on" aria-current="page">
      <i class="bi <?= $vv['icon'] ?>" aria-hidden="true"></i><?= h($vv['label']) ?>
      <span class="ib-cnt<?= $n ? '' : ' is-zero' ?>" data-ib-cnt="<?= h($view) ?>"><?= $n ?></span>
    </a>
    <?php endif; ?>
    <div class="dropdown">
      <button type="button" class="ib-pill" data-bs-toggle="dropdown" aria-expanded="false">
        Więcej <i class="bi bi-chevron-down ib-act-caret" aria-hidden="true"></i>
      </button>
      <ul class="dropdown-menu ib-menu ib-more-menu">
        <?php foreach ($views_more as $vk): $vv = CRM_MAILBOX_VIEWS[$vk]; $n = (int)($counts[$vk] ?? 0);
              // Puste widoki pomocnicze („Ukryte", „Kopie robocze") nie zajmują miejsca
              if (in_array($vk, ['hidden', 'drafts'], true) && !$n && $view !== $vk) continue; ?>
        <li>
          <a class="dropdown-item<?= $view === $vk ? ' active' : '' ?>" href="?<?= $qs(['view' => $vk, 'msg' => null, 'page' => null, 'unread' => null]) ?>"
             <?= $view === $vk ? 'aria-current="page"' : '' ?>>
            <i class="bi <?= $vv['icon'] ?>" aria-hidden="true"></i><?= h($vv['label']) ?>
            <span class="ib-cnt<?= $n ? '' : ' is-zero' ?>" data-ib-cnt="<?= h($vk) ?>"><?= $n ?></span>
          </a>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </nav>

  <div class="ib-top-act">
    <?php if ($can_write): ?>
    <?php /* Kompozytor ten sam co przy kontakcie (crm/compose_modal.php), tylko
             otwierany bez kontaktu: odbiorcę wybiera się w oknie. */ ?>
    <button type="button" class="ib-tbtn ib-tbtn--cta" onclick="openCommModal(0,'email')"
            title="Napisz nową wiadomość do kontaktu z CRM — wyśle się z CRM i zapisze w historii kontaktu">
      <i class="bi bi-pencil-square" aria-hidden="true"></i>Napisz
    </button>
    <?php endif; ?>
    <?php if (is_admin()): ?>
    <form method="post" class="d-flex gap-1 align-items-center">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_op" value="scan">
      <input type="hidden" name="view" value="<?= h($view) ?>">
      <?php if (count($boxes) > 1): ?>
      <select name="scan_mailbox" class="ib-field" style="max-width:180px" aria-label="Skrzynka do sprawdzenia">
        <option value="">wszystkie skrzynki</option>
        <?php foreach ($boxes as $b): ?>
        <option value="<?= (int)$b['id'] ?>"><?= h($b['mailbox']) ?></option>
        <?php endforeach; ?>
      </select>
      <?php endif; ?>
      <button class="ib-tbtn" <?= $ready && $boxes ? '' : 'disabled' ?> title="Pobierz nowe wiadomości ze skrzynek teraz">
        <i class="bi bi-arrow-repeat" aria-hidden="true"></i>Sprawdź teraz
      </button>
    </form>
    <a href="<?= APP_URL ?>/poczta/index.php" class="ib-tbtn" title="Konfiguracja skrzynek" aria-label="Konfiguracja skrzynek"><i class="bi bi-gear" aria-hidden="true"></i></a>
    <?php endif; ?>
  </div>
</div>

<!-- ══ Jeden pasek: szukaj, szybkie filtry, skrzynka, narzędzia ═════════════ -->
<div class="ib-toolbar">
  <form method="get" class="ib-search" role="search">
    <input type="hidden" name="view" value="<?= h($view) ?>">
    <?php if ($mbox_f): ?><input type="hidden" name="mailbox_id" value="<?= $mbox_f ?>"><?php endif; ?>
    <?php if ($f_unread): ?><input type="hidden" name="unread" value="1"><?php endif; ?>
    <?php if ($f_att): ?><input type="hidden" name="att" value="1"><?php endif; ?>
    <i class="bi bi-search" aria-hidden="true"></i>
    <input type="search" name="q" id="ibSearch" value="<?= h($search) ?>" autocomplete="off"
           aria-label="Szukaj w skrzynce: temat, nadawca, kontakt albo numer wiadomości"
           placeholder="Szukaj: temat, nadawca, kontakt, nr wiadomości…">
    <?php if ($search !== ''): ?>
    <a class="ib-search-x" href="?<?= $qs(['q' => null, 'msg' => null, 'page' => null]) ?>" aria-label="Wyczyść wyszukiwanie" title="Wyczyść wyszukiwanie">
      <i class="bi bi-x-lg" aria-hidden="true"></i>
    </a>
    <?php endif; ?>
    <kbd aria-hidden="true" title="Skrót: /">/</kbd>
  </form>

  <?php if (!in_array($view, ['new', 'sent', 'drafts'], true)): ?>
  <a class="ib-chip-f<?= $f_unread ? ' is-on' : '' ?>" href="?<?= $qs(['unread' => $f_unread ? null : 1, 'msg' => null, 'page' => null]) ?>"
     aria-pressed="<?= $f_unread ? 'true' : 'false' ?>" role="button">
    <i class="bi bi-envelope<?= $f_unread ? '-fill' : '' ?>" aria-hidden="true"></i>Nieprzeczytane
  </a>
  <?php endif; ?>
  <a class="ib-chip-f<?= $f_att ? ' is-on' : '' ?>" href="?<?= $qs(['att' => $f_att ? null : 1, 'msg' => null, 'page' => null]) ?>"
     aria-pressed="<?= $f_att ? 'true' : 'false' ?>" role="button">
    <i class="bi bi-paperclip" aria-hidden="true"></i>Z załącznikiem
  </a>

  <div class="ib-tb-right">
  <?php if (count($boxes) > 1):
        $mbox_counts = crm_mailbox_counts_by_mailbox($view);
        $mbox_cur    = null;
        foreach ($boxes as $b) { if ((int)$b['id'] === $mbox_f) { $mbox_cur = $b; break; } } ?>
  <div class="dropdown">
    <button type="button" class="ib-tbtn<?= $mbox_cur ? ' ib-tbtn--on' : '' ?>" data-bs-toggle="dropdown" aria-expanded="false"
            aria-label="Skrzynka: <?= h($mbox_cur['mailbox'] ?? 'wszystkie') ?>"
            style="--c:<?= h($mbox_cur ? crm_mailbox_color($mbox_f) : '#8a8a8a') ?>">
      <?php if ($mbox_cur): ?>
      <span class="ib-mbox-dot" aria-hidden="true"></span><span class="ib-mbox-name"><?= h($mbox_cur['mailbox']) ?></span>
      <?php else: ?>
      <i class="bi bi-collection" aria-hidden="true"></i>Wszystkie skrzynki
      <?php endif; ?>
      <i class="bi bi-chevron-down ib-act-caret" aria-hidden="true"></i>
    </button>
    <ul class="dropdown-menu dropdown-menu-end ib-menu">
      <li>
        <a class="dropdown-item<?= $mbox_f ? '' : ' active' ?>" href="?<?= $qs(['mailbox_id' => null, 'msg' => null, 'page' => null]) ?>">
          <i class="bi bi-collection" aria-hidden="true"></i>Wszystkie skrzynki
          <span class="ib-menu-note"><?= array_sum($mbox_counts) ?></span>
        </a>
      </li>
      <?php foreach ($boxes as $b): $bid = (int)$b['id']; ?>
      <li>
        <a class="dropdown-item<?= $mbox_f === $bid ? ' active' : '' ?>" style="--c:<?= h(crm_mailbox_color($bid)) ?>"
           href="?<?= $qs(['mailbox_id' => $bid, 'msg' => null, 'page' => null]) ?>" title="<?= h($b['mailbox']) ?>">
          <span class="ib-mbox-dot" aria-hidden="true"></span><span class="ib-mbox-name"><?= h($b['mailbox']) ?></span>
          <span class="ib-menu-note"><?= (int)($mbox_counts[$bid] ?? 0) ?></span>
        </a>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>

  <?php /* Rzadsze narzędzia w jednym menu — wcześniej pięć przycisków w rzędzie. */
        $tg_on = $inbox['grouped'] ?? false;
        $unread_scope = $can_write ? crm_mailbox_unread_in_scope(['view' => $view, 'mailbox_id' => $mbox_f]) : 0; ?>
  <?php /* Odczytaj wszystko — na wierzchu (po urlopie, po zalewie newsletterów). Zakres to bieżący widok
           i wybrana skrzynka — dokładnie to, co widać; wyszukiwarka go nie zawęża. Skrót: Shift+I. */
  if ($unread_scope > 0):
        $mb_name = '';
        foreach ($boxes as $b) { if ((int)$b['id'] === $mbox_f) { $mb_name = (string)$b['mailbox']; break; } }
        $scope_txt = 'w widoku „' . (CRM_MAILBOX_VIEWS[$view]['label'] ?? $view) . '"'
                   . ($mb_name !== '' ? ', skrzynka ' . $mb_name : ''); ?>
  <form method="post" class="d-inline">
    <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
    <input type="hidden" name="_op" value="mark_all_read">
    <input type="hidden" name="view" value="<?= h($view) ?>">
    <input type="hidden" name="mailbox_id" value="<?= $mbox_f ?: '' ?>">
    <button class="ib-tbtn" id="ibReadAll" title="Oznacz jako przeczytane wszystkie nieprzeczytane <?= h($scope_txt) ?> (Shift+I)"
            onclick="return confirm('Oznaczyć jako przeczytane wszystkie nieprzeczytane <?= h($scope_txt) ?>? Dotyczy <?= (int)$unread_scope ?> wiad.')">
      <i class="bi bi-envelope-open" aria-hidden="true"></i>Odczytaj wszystko
      <span class="ib-menu-note"><?= (int)$unread_scope ?></span>
    </button>
  </form>
  <?php endif; ?>
  <div class="dropdown">
    <button type="button" class="ib-tbtn" data-bs-toggle="dropdown" aria-expanded="false" data-bs-auto-close="outside">
      <i class="bi bi-sliders" aria-hidden="true"></i>Widok i narzędzia
      <i class="bi bi-chevron-down ib-act-caret" aria-hidden="true"></i>
    </button>
    <ul class="dropdown-menu dropdown-menu-end ib-menu">
      <li><h6 class="dropdown-header">Lista</h6></li>
      <li>
        <a class="dropdown-item" href="?<?= $qs(['threads' => $threads ? '0' : '1', 'msg' => null, 'page' => null]) ?>"
           title="<?= $search !== '' ? 'Przy aktywnym wyszukiwaniu wątki są rozwijane — inaczej wynik pokazywałby wiadomość bez szukanej frazy' : 'Zwiń odpowiedzi w jeden wiersz wątku' ?>">
          <i class="bi bi-<?= $threads ? 'check-square-fill' : 'square' ?>" aria-hidden="true"></i>Grupuj w wątki
          <span class="visually-hidden"><?= $threads ? '(włączone)' : '(wyłączone)' ?></span>
          <?php if ($threads && !$tg_on): ?><span class="ib-menu-note">wyłączone przy szukaniu</span><?php endif; ?>
        </a>
      </li>
      <li>
        <a class="dropdown-item" href="<?= APP_URL ?>/crm/inbox_rules.php"><i class="bi bi-funnel" aria-hidden="true"></i>Reguły skrzynki</a>
      </li>
      <li>
        <button type="button" class="dropdown-item" id="ibCompactBtn" aria-pressed="false">
          <i class="bi bi-square" aria-hidden="true"></i>Lista zwarta (bez podglądu treści)
        </button>
      </li>
      <li>
        <button type="button" class="dropdown-item" id="ibSoundBtn" aria-pressed="true">
          <i class="bi bi-bell-fill" id="ibSoundIco" aria-hidden="true"></i><span id="ibSoundTxt">Sygnał przy nowej wiadomości</span>
        </button>
      </li>
      <li>
        <button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#ibKeysModal">
          <i class="bi bi-keyboard" aria-hidden="true"></i>Skróty klawiszowe
          <span class="ib-menu-note">?</span>
        </button>
      </li>
      <li><hr class="dropdown-divider my-1"></li>
      <li><h6 class="dropdown-header">Działania</h6></li>
      <?php if (crm_can('export', 'read')):
            $exp_qs = http_build_query(array_filter([
                'what' => 'comms', 'q' => $search ?: null, 'mailbox_id' => $mbox_f ?: null,
                'direction' => in_array($view, ['sent', 'drafts'], true) ? 'out' : 'in',
            ])); ?>
      <li>
        <a class="dropdown-item" href="<?= APP_URL ?>/crm/export.php?<?= h($exp_qs) ?>&amp;format=xlsx"
           title="Pobierz zestawienie korespondencji z bieżącego widoku (XLSX)">
          <i class="bi bi-download" aria-hidden="true"></i>Eksport do Excela
        </a>
      </li>
      <?php endif; ?>
      <?php if (is_admin() && crm_mailbox_autocreate()): ?>
      <li>
        <form method="post">
          <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
          <input type="hidden" name="_op" value="autocreate">
          <input type="hidden" name="view" value="<?= h($view) ?>">
          <button class="dropdown-item" name="on" value="" title="Wiadomości od nieznanych nadawców zakładają teraz kartotekę kontaktu — kliknij, aby wyłączyć">
            <i class="bi bi-person-plus" aria-hidden="true"></i>Auto-kartoteka: włączona
            <span class="ib-menu-note">wyłącz</span>
          </button>
        </form>
      </li>
      <?php endif; ?>
    </ul>
  </div>
  </div>
</div>

<div class="ib-wrap<?= $explicit ? ' is-reading' : '' ?>" id="ibWrap">
  <script>
  // Zapamiętana szerokość listy i tryb zwarty — przed pierwszym malowaniem, bez mrugnięcia
  try {
    var _w = parseInt(localStorage.getItem('crmInboxListW') || '', 10);
    if (_w >= 300 && _w <= 900) document.getElementById('ibWrap').style.setProperty('--ib-list-w', _w + 'px');
    if (localStorage.getItem('crmInboxCompact') === '1') document.getElementById('ibWrap').classList.add('is-compact');
  } catch (e) {}
  </script>

  <!-- ══ LISTA ═══════════════════════════════════════════════════════════ -->
  <section class="ib-col-list" aria-label="Lista wiadomości">
    <?php $bulk_view = $view === 'hidden';
          $abandon_days = (int)(crm_setting('crm_inbox_abandon_days') ?: 90); ?>
    <?php if ($bulk_view && $abandon_days > 0): ?>
    <div class="ib-note">
      <i class="bi bi-clock-history me-1" aria-hidden="true"></i>
      Wiadomości bez akcji (aktywne, bez opiekuna, poza EZD) starsze niż <strong><?= $abandon_days ?></strong> dni
      są porzucane automatycznie. Zostają tutaj — w Poczcie i EZD bez zmian.
    </div>
    <?php endif; ?>
    <button type="button" class="ib-new" id="ibNewBar" hidden>
      <i class="bi bi-arrow-down-circle-fill" aria-hidden="true"></i>
      <span id="ibNewTxt">Nowe wiadomości</span>
      <span class="ms-auto text-decoration-underline">pokaż</span>
    </button>

    <?php if ($can_write && $inbox['rows']): ?>
    <!-- Masowe działania: zaznaczenie obejmuje to, co widać w bieżącym widoku. -->
    <form method="post" id="ibBulkForm">
      <input type="hidden" name="_csrf" value="<?= csrf_token() ?>">
      <input type="hidden" name="_op" id="ibBulkOp" value="<?= $bulk_view ? 'bulk_unhide' : 'bulk_hide' ?>">
      <input type="hidden" name="view" value="<?= h($view) ?>">
      <input type="hidden" name="mailbox_id" value="<?= $mbox_f ?: '' ?>">
      <input type="hidden" name="q" value="<?= h($search) ?>">
      <div class="ib-bulk<?= $bulk_view ? ' ib-bulk--restore' : '' ?>" id="ibBulkBar">
        <label>
          <input type="checkbox" id="ibCheckAll"
                 aria-label="Zaznacz wszystkie widoczne wiadomości (<?= count($inbox['rows']) ?>)">
          <span>Zaznacz widoczne (<?= count($inbox['rows']) ?>)</span>
        </label>
        <span id="ibBulkInfo" hidden>· <span class="ib-bulk-n" id="ibBulkN">0</span> zazn.</span>
        <button class="ib-bulk-act" id="ibBulkReadBtn" disabled title="Oznacz zaznaczone wiadomości jako przeczytane">
          <i class="bi bi-envelope-open" aria-hidden="true"></i>Oznacz przeczytane
        </button>
        <button class="ib-bulk-act" id="ibBulkBtn" disabled
                <?= $bulk_view ? '' : 'onclick="return confirm(\'Porzucić zaznaczone wiadomości? Znikną ze Skrzynki CRM — zostaną w widoku Ukryte, w Poczcie i EZD bez zmian.\')"' ?>>
          <?php if ($bulk_view): ?>
          <i class="bi bi-eye" aria-hidden="true"></i>Przywróć
          <?php else: ?>
          <i class="bi bi-hand-thumbs-down" aria-hidden="true"></i>Porzuć zaznaczone
          <?php endif; ?>
        </button>
      </div>
    </form>
    <?php endif; ?>

    <div id="ibListWrap">
    <div class="ib-list" id="ibList">
      <?php if (!$inbox['rows']): ?>
      <div class="ib-list-empty">
        <i class="bi bi-inbox" aria-hidden="true"></i>
        <strong>Brak wiadomości w tym widoku</strong>
        <span><?= ($search !== '' || $f_unread || $f_att) ? 'Nic nie pasuje do wyszukiwania i filtrów.' : 'Zmień widok albo sprawdź skrzynkę ponownie.' ?></span>
      </div>
      <?php else: $last_day = ''; $outgoing = in_array($view, ['sent', 'drafts'], true);
        foreach ($inbox['rows'] as $r):
        $day = crmInboxDayLabel((string)$r['sent_at']);
        if ($day !== $last_day) { $last_day = $day; echo '<h2 class="ib-day">' . h($day) . '</h2>'; }
        $rid    = (int)$r['id'];
        $act    = $rid === $sel_id;
        // W widokach wychodzących liczy się ADRESAT, nie nadawca — inaczej wszystkie
        // wiersze pokazywałyby naszą własną skrzynkę.
        $who = $outgoing
            ? 'Do: ' . (string)($r['contact_name'] ?: $r['from_email'] ?: '—')
            : (string)($r['from_name'] ?: $r['from_email'] ?: $r['contact_name'] ?: '—');
        $unread = !(int)$r['is_read'];
        $no     = crm_msg_no($rid, $r['msg_no'] ?? null);
        $tc     = (int)($r['thread_count'] ?? 1);
        $href   = '?' . $qs(['msg' => $rid]);
        [$av_ini, $av_h] = $ib_avatar($who);
      ?>
      <div class="ib-row<?= $act ? ' is-active' : '' ?><?= $unread ? ' unread' : '' ?>" data-id="<?= $rid ?>">
        <?php if (!empty($r['mailbox_id'])): $rc = crm_mailbox_color((int)$r['mailbox_id']); ?>
        <a class="ib-stripe" style="background:<?= h($rc) ?>" tabindex="-1"
           href="?<?= $qs(['mailbox_id' => (int)$r['mailbox_id'], 'msg' => null, 'page' => null]) ?>"
           title="Wpłynęło na: <?= h($r['mailbox_name'] ?: '—') ?> — kliknij, aby filtrować"
           aria-label="Filtruj: skrzynka <?= h($r['mailbox_name'] ?: '—') ?>"></a>
        <?php else: ?>
        <span class="ib-stripe" style="background:#e1e1e1" aria-hidden="true"></span>
        <?php endif; ?>
        <?php if ($can_write): ?>
        <input type="checkbox" class="ib-check" form="ibBulkForm" name="msg_ids[]" value="<?= $rid ?>"
               aria-label="Zaznacz wiadomość: <?= h($r['subject'] ?: '(bez tematu)') ?>">
        <?php endif; ?>
        <a class="ib-item" href="<?= h($href) ?>" data-ib-open="<?= $rid ?>" <?= $act ? 'aria-current="true"' : '' ?>>
          <span class="ib-av<?= $outgoing ? ' ib-av--out' : '' ?>" style="--h:<?= (int)$av_h ?>" aria-hidden="true"><?= h($av_ini) ?></span>
          <span class="ib-l1">
            <?php if ($unread): ?><span class="visually-hidden ib-sr-unread">Nieprzeczytana.</span><?php endif; ?>
            <span class="ib-who" title="<?= h($who . (!$outgoing && $r['from_email'] && $r['from_name'] ? ' <' . $r['from_email'] . '>' : '')) ?>"><?= h($who) ?></span>
            <?= crm_sender_trust_badge($trust_of($r['from_email'] ?? ''), 'sm') ?>
            <?php require_once dirname(__DIR__) . '/includes/crmAutomat.php'; if (!$outgoing && crm_is_automat($r['from_email'] ?? '', $r['from_name'] ?? '')): ?>
            <span class="ib-tag" style="background:#E5E7EB;color:#374151" title="Nadawca rozpoznany jako automat (no-reply, powiadomienia systemowe)"><i class="bi bi-robot" aria-hidden="true"></i> automat</span>
            <?php endif; ?>
            <?php if ((int)$r['has_attachments']): ?>
            <i class="bi bi-paperclip ib-ico" title="Wiadomość ma załącznik" aria-hidden="true"></i><span class="visually-hidden">Ma załącznik.</span>
            <?php endif; ?>
            <?php if (($inbox['grouped'] ?? false) && $tc > 1): ?>
            <span class="ib-tcount" title="Wiadomości w wątku: <?= $tc ?>"><?= $tc ?><span class="visually-hidden"> w wątku</span></span>
            <?php endif; ?>
            <span class="ib-time"><?= h(date('H:i', strtotime((string)$r['sent_at']))) ?></span>
          </span>
          <span class="ib-subj"><?= h($r['subject'] ?: '(bez tematu)') ?></span>
          <?php // Treść tekstowa bywa zapisana z encjami (&nbsp;) — w podglądzie mają być spacje
                $snip = trim(preg_replace('/\s+/u', ' ', str_replace("\xc2\xa0", ' ', html_entity_decode(crm_mail_body_snippet($r, 240), ENT_QUOTES | ENT_HTML5, 'UTF-8'))) ?? '');
                if ($snip !== ''): ?>
          <span class="ib-snip"><?= h($snip) ?></span>
          <?php endif; ?>
          <?php if (!empty($r['assigned_name']) || $r['inbox_status'] !== 'active' || (!$mbox_f && count($boxes) > 1 && !empty($r['mailbox_name']))): ?>
          <span class="ib-tags">
            <?php if (!empty($r['assigned_name'])): ?>
            <span class="ib-tag ib-tag--who" title="Prowadzi: <?= h($r['assigned_name']) ?>"><i class="bi bi-person-check" aria-hidden="true"></i> <?= h($r['assigned_name']) ?></span>
            <?php endif; ?>
            <?php if ($r['inbox_status'] === 'archived'): ?>
            <span class="ib-tag ib-tag--ok">załatwione</span>
            <?php elseif ($r['inbox_status'] === 'spam'): ?>
            <span class="ib-tag ib-tag--bad">spam</span>
            <?php endif; ?>
            <?php if (!$mbox_f && count($boxes) > 1 && !empty($r['mailbox_name'])): ?>
            <span class="ib-tag ib-tag--mb"><?= h($r['mailbox_name']) ?></span>
            <?php endif; ?>
          </span>
          <?php endif; ?>
          <?php if ($no !== ''): ?><span class="ib-id" aria-hidden="true">#<?= h($no) ?></span><?php endif; ?>
        </a>
        <?php if ($can_write && !$bulk_view): ?>
        <div class="ib-quick" role="group" aria-label="Szybkie akcje: <?= h($r['subject'] ?: '(bez tematu)') ?>">
          <?php $qh = '<input type="hidden" name="_csrf" value="' . csrf_token() . '"><input type="hidden" name="msg_id" value="' . $rid . '">'
                    . '<input type="hidden" name="view" value="' . h($view) . '"><input type="hidden" name="q" value="' . h($search) . '">'
                    . ($mbox_f ? '<input type="hidden" name="mailbox_id" value="' . $mbox_f . '">' : '')
                    . ($f_unread ? '<input type="hidden" name="unread" value="1">' : '')
                    . ($f_att ? '<input type="hidden" name="att" value="1">' : ''); ?>
          <?php if (($r['inbox_status'] ?? 'active') === 'active'): ?>
          <form method="post" data-ib-xhr="archive"><?= $qh ?><input type="hidden" name="_op" value="archive">
            <button type="submit" title="Załatwione" aria-label="Załatwione"><i class="bi bi-check2-circle" aria-hidden="true"></i></button></form>
          <?php else: ?>
          <form method="post" data-ib-xhr="restore"><?= $qh ?><input type="hidden" name="_op" value="restore">
            <button type="submit" title="Przywróć do skrzynki" aria-label="Przywróć do skrzynki"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i></button></form>
          <?php endif; ?>
          <form method="post" data-ib-xhr="<?= $unread ? 'read' : 'unread' ?>"><?= $qh ?><input type="hidden" name="_op" value="<?= $unread ? 'read' : 'unread' ?>">
            <button type="submit" title="<?= $unread ? 'Oznacz jako przeczytane' : 'Oznacz jako nieprzeczytane' ?>" aria-label="<?= $unread ? 'Oznacz jako przeczytane' : 'Oznacz jako nieprzeczytane' ?>"><i class="bi bi-<?= $unread ? 'envelope-open' : 'envelope' ?>" aria-hidden="true"></i></button></form>
          <form method="post" data-ib-xhr="assign_me"><?= $qh ?><input type="hidden" name="_op" value="assign_me">
            <button type="submit" title="Przypisz do mnie" aria-label="Przypisz do mnie"><i class="bi bi-person-check" aria-hidden="true"></i></button></form>
          <form method="post" data-ib-xhr="spam"><?= $qh ?><input type="hidden" name="_op" value="spam">
            <button type="submit" title="Spam" aria-label="Oznacz jako spam"><i class="bi bi-slash-circle" aria-hidden="true"></i></button></form>
        </div>
        <?php endif; ?>
      </div>
      <?php // Zwinięty wątek: reszta wymiany pod jednym rozwinięciem, bez ładowania strony
            if (($inbox['grouped'] ?? false) && $tc > 1 && !empty($r['thread_key'])): ?>
      <details class="ib-thread">
        <summary title="Pozostałe wiadomości tego wątku">
          <i class="bi bi-arrow-return-right" aria-hidden="true"></i>
          <?= $tc - 1 ?> <?= $tc - 1 === 1 ? 'wcześniejsza wiadomość' : ($tc - 1 < 5 ? 'wcześniejsze wiadomości' : 'wcześniejszych wiadomości') ?>
          w wątku
        </summary>
        <?php foreach (crm_mailbox_thread_rest((string)$r['thread_key'], $rid) as $tr): ?>
        <a class="ib-thread-item<?= (int)$tr['id'] === $sel_id ? ' active' : '' ?>"
           href="?<?= $qs(['msg' => (int)$tr['id']]) ?>" data-ib-open="<?= (int)$tr['id'] ?>">
          <i class="bi bi-<?= $tr['direction'] === 'out' ? 'reply' : 'envelope' ?>" aria-hidden="true"></i>
          <span class="ib-thread-who"><?= h($tr['direction'] === 'out' ? 'my' : ($tr['from_name'] ?: $tr['from_email'] ?: '—')) ?></span>
          <span class="ib-thread-subj"><?= h($tr['subject'] ?: '(bez tematu)') ?></span>
          <span class="ib-thread-date"><?= h(date('j.m H:i', strtotime((string)$tr['sent_at']))) ?></span>
        </a>
        <?php endforeach; ?>
      </details>
      <?php endif; ?>
      <?php endforeach; endif; ?>
    </div>

    <?php $pages = (int)ceil($inbox['total'] / max(1, $inbox['per_page'])); ?>
    <div class="ib-pager">
      <span>Łącznie: <strong><?= (int)$inbox['total'] ?></strong></span>
      <?php if ($pages > 1): ?>
      <nav class="ib-pg" aria-label="Strony listy">
        <?php for ($p = 1; $p <= min($pages, 12); $p++): ?>
        <a href="?<?= $qs(['page' => $p, 'msg' => null]) ?>" class="<?= $p === (int)$inbox['page'] ? 'is-on' : '' ?>"
           <?= $p === (int)$inbox['page'] ? 'aria-current="page"' : '' ?>><?= $p ?></a>
        <?php endfor; ?>
      </nav>
      <?php endif; ?>
    </div>
    </div><!-- /#ibListWrap -->
  </section>

  <div class="ib-resizer" id="ibResizer" role="separator" tabindex="0" aria-orientation="vertical"
       aria-label="Szerokość listy wiadomości — przeciągnij albo użyj strzałek" aria-controls="ibListWrap"
       aria-valuemin="300" aria-valuemax="900" aria-valuenow="440"></div>

  <!-- ══ WIADOMOŚĆ ═══════════════════════════════════════════════════════ -->
  <section class="ib-col-msg" id="ibMsgCol" aria-label="Podgląd wiadomości">
  <?php if (!$msg): ?>
    <div class="ib-pane ib-empty" id="ibPane">
      <div>
        <i class="bi bi-envelope-open" aria-hidden="true"></i>
        <div class="mt-2" style="font-size:1rem;font-weight:600;color:#1d1d1d">Wybierz wiadomość z listy</div>
        <div style="font-size:.82rem" class="mt-1">
          <?= $inbox['rows'] ? 'Treść, załączniki i akcje pokażą się tutaj.' : 'W tym widoku nie ma wiadomości.' ?>
        </div>
      </div>
    </div>
  <?php else:
    $hidden = '<input type="hidden" name="_csrf" value="' . csrf_token() . '">'
            . '<input type="hidden" name="msg_id" value="' . (int)$msg['id'] . '">'
            . '<input type="hidden" name="view" value="' . h($view) . '">'
            . '<input type="hidden" name="q" value="' . h($search) . '">'
            . ($mbox_f ? '<input type="hidden" name="mailbox_id" value="' . $mbox_f . '">' : '')
            . ($f_unread ? '<input type="hidden" name="unread" value="1">' : '')
            . ($f_att ? '<input type="hidden" name="att" value="1">' : '');
    $rail_cases  = $msg['ctx']['cases']  ?? [];
    $rail_offers = $msg['ctx']['offers'] ?? [];
    $rail_thread = count($msg['ctx']['thread'] ?? []) > 1 ? $msg['ctx']['thread'] : [];
    $msg_no  = crm_msg_no((int)$msg['id'], $msg['msg_no'] ?? null);
    $msg_out = ($msg['direction'] ?? 'in') === 'out';
    // Pozostałe wiadomości wątku (z treścią), podział na wcześniejsze i późniejsze względem otwartej
    $conv_before = $conv_after = [];
    if ($conv_on && $rail_thread) {
        $seen_cur = false;
        foreach ($rail_thread as $tr) {
            if ((int)$tr['id'] === (int)$msg['id']) { $seen_cur = true; continue; }
            $full = crm_mailbox_message((int)$tr['id']);
            if (!$full) continue;
            if ($seen_cur) $conv_after[] = $full; else $conv_before[] = $full;
        }
    }
    // W widoku rozmowy wątek jest w treści — boczne pole „Wątek” byłoby duplikatem
    $conv_thread = $rail_thread;
    if ($conv_on) $rail_thread = [];
    $conv_card = static function (array $m) use ($ib_avatar): void {
        $out = ($m['direction'] ?? 'in') === 'out';
        $who = $out ? 'Ty → ' . ($m['contact_name'] ?: ($m['from_email'] ?: '—'))
                    : ($m['from_name'] ?: ($m['from_email'] ?: '—'));
        [$ini, $hue] = $ib_avatar($out ? 'Ty' : (string)$who);
        $plain = trim(preg_replace('/\s+/u', ' ', strip_tags((string)($m['body'] ?: $m['body_html'] ?? ''))));
        $open  = empty($m['is_read']) && !$out;
        ?>
        <details class="ib-conv-item<?= $out ? ' is-out' : '' ?>"<?= $open ? ' open' : '' ?>>
          <summary>
            <span class="ib-av<?= $out ? ' ib-av--out' : '' ?>" style="--h:<?= (int)$hue ?>" aria-hidden="true"><?= h($ini) ?></span>
            <span class="ib-conv-who"><?= h($who) ?></span>
            <span class="ib-conv-snip"><?= h(mb_substr($plain, 0, 140)) ?></span>
            <time datetime="<?= h(date('c', strtotime((string)$m['sent_at']))) ?>"><?= h(date('d.m.Y H:i', strtotime((string)$m['sent_at']))) ?></time>
          </summary>
          <iframe class="ib-frame" title="Wiadomość: <?= h($who) ?>, <?= h(date('d.m.Y H:i', strtotime((string)$m['sent_at']))) ?>"
                  sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"
                  referrerpolicy="no-referrer" srcdoc="<?= h(crmInboxFrameDoc($m)) ?>"></iframe>
        </details>
        <?php
    };
  ?>
    <article class="ib-pane" id="ibPane" data-msg="<?= (int)$msg['id'] ?>" aria-labelledby="ibMsgTitle">

      <div class="ib-head">
        <?php [$mav_ini, $mav_h] = $ib_avatar($msg_out ? (string)($msg['contact_name'] ?: $msg['from_email'] ?: '—')
                                                       : (string)($msg['from_name'] ?: $msg['from_email'] ?: $msg['contact_name'] ?: '—')); ?>
        <span class="ib-av ib-av--lg<?= $msg_out ? ' ib-av--out' : '' ?>" style="--h:<?= (int)$mav_h ?>" aria-hidden="true"><?= h($mav_ini) ?></span>
        <div class="ib-head-main">
          <?php if ($msg_no !== ''): ?>
          <div class="ib-h1-no">WIADOMOŚĆ NR <span class="ib-no">#<?= h($msg_no) ?></span></div>
          <?php endif; ?>
          <h2 class="ib-h1" id="ibMsgTitle" tabindex="-1"><?= h($msg['subject'] ?: '(bez tematu)') ?></h2>
          <div class="ib-meta">
            <?php if ($msg_out): ?>
            <span>Do:</span>
            <strong><?= h($msg['contact_name'] ?: ($msg['from_email'] ?: '—')) ?></strong>
            <?php else: ?>
            <strong><?= h($msg['from_name'] ?: ($msg['from_email'] ?: '—')) ?></strong>
            <?php if (!empty($msg['from_email']) && $msg['from_name']): ?>&lt;<?= h($msg['from_email']) ?>&gt;<?php endif; ?>
            <?php endif; ?>
            · <time datetime="<?= h(date('c', strtotime((string)$msg['sent_at']))) ?>"><?= h(date('d.m.Y H:i', strtotime((string)$msg['sent_at']))) ?></time>
            <?php if (!empty($msg['mailbox_name'])): ?>
            · na <span class="ib-mbox-dot" style="--c:<?= h(crm_mailbox_color((int)($msg['mailbox_id'] ?? 0))) ?>" aria-hidden="true"></span>
            <?= h($msg['mailbox_name']) ?>
            <?php endif; ?>
          </div>
        </div>
        <nav class="ib-nav" aria-label="Nawigacja między wiadomościami">
          <a class="ib-act ib-back" href="?<?= $qs(['msg' => null]) ?>" data-ib-back>
            <i class="bi bi-arrow-left" aria-hidden="true"></i>Lista
          </a>
          <span class="ib-nav-pos"><?= $sel_pos !== false ? ($sel_pos + 1) . ' z ' . count($row_ids) : '' ?></span>
          <?php /* Zawsze oba linki — skrypt przestawia je wg bieżącej listy, która
                   po przeglądaniu bez przeładowania może różnić się od serwerowej. */ ?>
          <a <?= $prev_id ? 'href="?' . h($qs(['msg' => $prev_id])) . '"' : 'aria-disabled="true"' ?> data-ib-go="prev" role="link"
             aria-label="Poprzednia wiadomość" title="Poprzednia wiadomość (k)"><i class="bi bi-chevron-up" aria-hidden="true"></i></a>
          <a <?= $next_id ? 'href="?' . h($qs(['msg' => $next_id])) . '"' : 'aria-disabled="true"' ?> data-ib-go="next" role="link"
             aria-label="Następna wiadomość" title="Następna wiadomość (j)"><i class="bi bi-chevron-down" aria-hidden="true"></i></a>
        </nav>
        <div class="ib-chips">
          <?php if (!empty($msg['contact_id'])): ?>
          <a class="ib-chip" href="<?= APP_URL ?>/crm/contact/view.php?id=<?= (int)$msg['contact_id'] ?>" title="Kartoteka kontaktu">
            <i class="bi <?= h(CRM_CONTACT_TYPES[$msg['contact_type']]['icon'] ?? 'bi-person') ?>" aria-hidden="true"></i>
            <?= h($msg['contact_name']) ?>
          </a>
          <?php endif; ?>
          <?php if (!empty($msg['assigned_name'])): ?>
          <span class="ib-chip ib-chip--who"><i class="bi bi-person-check-fill" aria-hidden="true"></i>Prowadzi: <?= h($msg['assigned_name']) ?></span>
          <?php endif; ?>
          <?php if (!empty($msg['ezd_sprawa_id'])): ?>
          <a class="ib-chip ib-chip--ezd" href="<?= APP_URL ?>/ezd/sprawy/view.php?id=<?= (int)$msg['ezd_sprawa_id'] ?>">
            <i class="bi bi-folder-symlink" aria-hidden="true"></i>w EZD
          </a>
          <?php endif; ?>
          <?= crm_sender_trust_badge($trust_of($msg['from_email'] ?? ''), 'md') ?>
          <?php if ($msg['inbox_status'] !== 'active'): ?>
          <span class="ib-chip"><?= $msg['inbox_status'] === 'archived' ? 'załatwione' : h($msg['inbox_status']) ?></span>
          <?php endif; ?>
          <?php if ((int)($msg['crm_hidden'] ?? 0) === 1): $auto = empty($msg['crm_hidden_by']); ?>
          <span class="ib-chip ib-chip--warn"
                title="<?= $auto ? 'Porzucona automatycznie — leżała bez akcji dłużej niż próg z ustawień' : 'Ktoś schował tę wiadomość ze Skrzynki CRM' ?>">
            <i class="bi bi-eye-slash" aria-hidden="true"></i><?= $auto ? 'porzucona automatycznie' : 'ukryta w CRM' ?>
          </span>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($can_write && !$msg_out): ?>
      <?php /* Pasek akcji: na wierzchu czynności, od których zaczyna się obsługa;
               „Przekaż" (dokąd dalej) i „Więcej" (porządkowanie) w menu. Pasek jest
               przyklejony do góry panelu — przy długiej treści zostaje pod ręką. */ ?>
      <div class="ib-bar" role="toolbar" aria-label="Działania na wiadomości">
        <?php if ((int)($msg['assigned_to'] ?? 0) !== $uid): ?>
        <form method="post" data-ib-xhr="assign_me"><?= $hidden ?><input type="hidden" name="_op" value="assign_me">
          <button class="ib-act ib-act--primary" data-ib-key="a" title="Przypisz tę wiadomość do siebie — trafi do widoku „Przypisane mi” (a)">
            <i class="bi bi-person-check" aria-hidden="true"></i>Wezmę to</button></form>
        <?php endif; ?>

        <?php if (!empty($msg['contact_id'])):
          // „Re: temat" — bez mnożenia prefiksów przy odpowiedzi na odpowiedź
          $reply_subject = (string)($msg['subject'] ?: '(bez tematu)');
          if (!preg_match('/^\s*re:/i', $reply_subject)) $reply_subject = 'Re: ' . $reply_subject;
        ?>
        <?php /* Temat idzie przez data-atrybut, NIE przez onclick: json_encode()
                 zwraca tekst w cudzysłowach, które kończyły atrybut onclick. */ ?>
        <div class="ib-split">
          <button type="button" class="ib-act" data-reply data-ib-key="r"
                  data-contact="<?= (int)$msg['contact_id'] ?>"
                  data-subject="<?= h($reply_subject) ?>"
                  title="Napisz odpowiedź do nadawcy — wyśle się z CRM i zapisze w historii kontaktu (r)">
            <i class="bi bi-reply" aria-hidden="true"></i>Odpowiedz
          </button>
          <div class="dropdown">
            <button type="button" class="ib-act" data-bs-toggle="dropdown" data-ib-dd aria-expanded="false"
                    aria-label="Więcej sposobów odpowiedzi: szablony i asystent AI">
              <i class="bi bi-chevron-down" aria-hidden="true"></i>
            </button>
            <ul class="dropdown-menu ib-menu">
              <?php if ($reply_tpls): ?>
              <li><h6 class="dropdown-header">Odpowiedz szablonem</h6></li>
              <?php foreach ($reply_tpls as $t): ?>
              <li>
                <button type="button" class="dropdown-item" data-reply
                        data-contact="<?= (int)$msg['contact_id'] ?>"
                        data-tpl="<?= (int)$t['id'] ?>"
                        data-subject="<?= h($reply_subject) ?>"
                        title="<?= h($t['subject'] ?: $t['name']) ?>">
                  <i class="bi bi-file-earmark-text" aria-hidden="true"></i><?= h($t['name']) ?>
                </button>
              </li>
              <?php endforeach; ?>
              <li>
                <a class="dropdown-item" href="<?= APP_URL ?>/crm/templates.php">
                  <i class="bi bi-gear" aria-hidden="true"></i>Zarządzaj szablonami
                </a>
              </li>
              <li><hr class="dropdown-divider my-1"></li>
              <?php endif; ?>
              <?php /* Asystent NIE odpowiada za nas — przygotowuje PROPOZYCJĘ do przeczytania. */ ?>
              <li>
                <button type="button" class="dropdown-item" id="ibAssistBtn" data-msg="<?= (int)$msg['id'] ?>"
                        data-contact="<?= (int)$msg['contact_id'] ?>" data-subject="<?= h((string)($msg['subject'] ?? '')) ?>"
                        title="Kwalifikacja zgłoszenia i propozycja odpowiedzi — do przeczytania przed wysłaniem">
                  <i class="bi bi-stars" style="color:#6a00ff" aria-hidden="true"></i>Asystent AI
                </button>
              </li>
            </ul>
          </div>
        </div>
        <?php else: ?>
        <button type="button" class="ib-act" id="ibAssistBtn" data-msg="<?= (int)$msg['id'] ?>"
                data-contact="0" data-subject="<?= h((string)($msg['subject'] ?? '')) ?>"
                title="Kwalifikacja zgłoszenia i propozycja odpowiedzi — do przeczytania przed wysłaniem">
          <i class="bi bi-stars" style="color:#6a00ff" aria-hidden="true"></i>Asystent AI
        </button>
        <?php endif; ?>

        <?php if ($msg['inbox_status'] === 'active'): ?>
        <form method="post" data-ib-xhr="archive"><?= $hidden ?><input type="hidden" name="_op" value="archive">
          <button class="ib-act" data-ib-key="e" title="Sprawa zamknięta — wiadomość przejdzie do widoku „Załatwione” (e)">
            <i class="bi bi-check2-all" aria-hidden="true"></i>Załatwione</button></form>
        <?php else: ?>
        <form method="post" data-ib-xhr="restore"><?= $hidden ?><input type="hidden" name="_op" value="restore">
          <button class="ib-act" data-ib-key="e" title="Wróć do obsługi — wiadomość znów pojawi się wśród aktywnych (e)">
            <i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i>Przywróć do obsługi</button></form>
        <?php endif; ?>

        <span class="ib-sep" aria-hidden="true"></span>

        <div class="dropdown">
          <button type="button" class="ib-act" data-bs-toggle="dropdown" data-ib-dd aria-expanded="false"
                  title="Skieruj tę wiadomość dalej: sprawa CRM, dziennik EZD, e-mail, zadanie">
            <i class="bi bi-signpost-split" aria-hidden="true"></i>Przekaż
            <i class="bi bi-chevron-down ib-act-caret" aria-hidden="true"></i>
          </button>
          <ul class="dropdown-menu ib-menu">
            <li>
              <button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#mdCase"
                      title="Załóż sprawę CRM z tej wiadomości — do prowadzenia tematu handlowego">
                <i class="bi bi-briefcase-fill" style="color:#0050ef" aria-hidden="true"></i>Załóż sprawę CRM
                <span class="ib-menu-note">c</span>
              </button>
            </li>
            <?php if (!empty($msg['contact_id'])):
                    $case_list = crm_cases_for_contact((int)$msg['contact_id']);
                    $cur_case  = (int)($msg['case_id'] ?? 0);
                    if ($case_list): ?>
            <li>
              <form method="post" class="px-3 py-1" onclick="event.stopPropagation()"><?= $hidden ?>
                <input type="hidden" name="_op" value="attach_case">
                <label class="form-label small mb-1" for="ibAttachCase">Dopnij do sprawy</label>
                <select name="case_id" id="ibAttachCase" class="ib-field w-100" onchange="this.form.submit()"
                        title="Dopnij tę wiadomość do sprawy — korespondencja będzie widoczna przy sprawie">
                  <option value="0">— wybierz sprawę —</option>
                  <?php foreach ($case_list as $cs): ?>
                  <option value="<?= (int)$cs['id'] ?>" <?= $cur_case === (int)$cs['id'] ? 'selected' : '' ?>>
                    <?= h(mb_strimwidth((string)$cs['title'], 0, 40, '…')) ?>
                  </option>
                  <?php endforeach; ?>
                </select>
              </form>
            </li>
            <?php endif; endif; ?>
            <li><hr class="dropdown-divider my-1"></li>
            <li>
              <button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#mdEzd"
                      title="Wpis w dzienniku podawczym EZD — dla korespondencji formalnej (pismo z urzędu, wniosek o umowę)">
                <i class="bi bi-journal-arrow-down" style="color:#00807e" aria-hidden="true"></i>Zarejestruj w dzienniku EZD
                <span class="ib-menu-note">z</span>
              </button>
            </li>
            <?php if (module_enabled('tasks_enabled')): ?>
            <li>
              <a class="dropdown-item" href="<?= h(APP_URL . '/tasks/router.php?_route=/nowe&comm=' . (int)$msg['id'] . '&src=crm') ?>"
                 title="Zadanie w module Zadania — temat, treść i załączniki tej wiadomości, z linkiem do niej">
                <i class="bi bi-kanban" style="color:#007a00" aria-hidden="true"></i>Utwórz zadanie
              </a>
            </li>
            <?php endif; ?>
            <li>
              <button type="button" class="dropdown-item" data-bs-toggle="modal" data-bs-target="#mdFwd"
                      title="Wyślij tę wiadomość dalej e-mailem — z cytatem oryginału i notatką">
                <i class="bi bi-forward-fill" style="color:#c25100" aria-hidden="true"></i>Przekaż e-mailem
                <span class="ib-menu-note">f</span>
              </button>
            </li>
            <?php if (nozbe_configured()): $nz_link = nozbe_link_for('crm_message', (int)$msg['id']); ?>
            <li><hr class="dropdown-divider my-1"></li>
            <li>
              <?php if ($nz_link): ?>
              <a class="dropdown-item" href="<?= h($nz_link['url']) ?>" target="_blank" rel="noopener"
                 title="Zadanie z tej wiadomości powstało już w Nozbe — otwórz je">
                <i class="bi bi-check2-square" style="color:#008a00" aria-hidden="true"></i>Otwórz w Nozbe
              </a>
              <?php else: ?>
              <form method="post"><?= $hidden ?><input type="hidden" name="_op" value="nozbe">
                <button class="dropdown-item" title="Utwórz w Nozbe zadanie „odpowiedz na tę wiadomość” — z linkiem do niej i treścią w komentarzu">
                  <i class="bi bi-check2-square" style="color:#008a00" aria-hidden="true"></i>Do Nozbe
                </button>
              </form>
              <?php endif; ?>
            </li>
            <?php endif; ?>
          </ul>
        </div>

        <div class="dropdown">
          <button type="button" class="ib-act" data-bs-toggle="dropdown" data-ib-dd aria-expanded="false"
                  aria-label="Więcej działań na wiadomości" title="Oznaczenia i porządkowanie skrzynki">
            <i class="bi bi-three-dots" aria-hidden="true"></i>Więcej
          </button>
          <ul class="dropdown-menu dropdown-menu-end ib-menu">
            <li>
              <form method="post" data-ib-xhr="unread"><?= $hidden ?><input type="hidden" name="_op" value="unread">
                <button class="dropdown-item" data-ib-key="u" title="Cofnij odczytanie — wiadomość wróci do widoku „Nowe” jako nieprzeczytana">
                  <i class="bi bi-envelope" aria-hidden="true"></i>Oznacz jako nieprzeczytaną
                  <span class="ib-menu-note">u</span>
                </button>
              </form>
            </li>
            <li>
              <form method="post" data-ib-xhr="spam"><?= $hidden ?><input type="hidden" name="_op" value="spam">
                <button class="dropdown-item" data-ib-key="!" title="Przenieś do widoku „Spam” — zniknie z listy roboczej">
                  <i class="bi bi-slash-circle" aria-hidden="true"></i>Spam
                  <span class="ib-menu-note">!</span>
                </button>
              </form>
            </li>
            <li><hr class="dropdown-divider my-1"></li>
            <li>
              <?php if ((int)($msg['crm_hidden'] ?? 0) === 1): ?>
              <form method="post" data-ib-xhr="unhide"><?= $hidden ?><input type="hidden" name="_op" value="unhide">
                <button class="dropdown-item" title="Cofnij porzucenie — wiadomość wróci do widoków Skrzynki CRM">
                  <i class="bi bi-eye" aria-hidden="true"></i>Przywróć do skrzynki
                </button>
              </form>
              <?php else: ?>
              <form method="post" data-ib-xhr="hide"><?= $hidden ?><input type="hidden" name="_op" value="hide">
                <button class="dropdown-item text-danger"
                        title="<?= empty($msg['assigned_to'])
                            ? 'Nikt tego nie poprowadzi — wiadomość zniknie ze Skrzynki CRM (zostanie w widoku Ukryte; Poczta i EZD bez zmian)'
                            : 'Wiadomość zniknie ze Skrzynki CRM (zostanie w widoku Ukryte; Poczta i EZD bez zmian)' ?>">
                  <i class="bi bi-eye-slash" aria-hidden="true"></i>Porzuć
                </button>
              </form>
              <?php endif; ?>
            </li>
          </ul>
        </div>

        <form method="post" class="ib-assign" data-ib-xhr="assign"><?= $hidden ?><input type="hidden" name="_op" value="assign">
          <select name="user_id" class="ib-field" aria-label="Przypisz wiadomość osobie"
                  title="Wskaż osobę, która poprowadzi tę wiadomość" onchange="this.form.requestSubmit ? this.form.requestSubmit() : this.form.submit()">
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

      <?php if ($msg_out): ?>
      <div class="ib-bar ib-bar--info">
        <i class="bi bi-<?= $view === 'drafts' ? 'file-earmark-text' : 'send' ?>" aria-hidden="true"></i>
        <?php if ($view === 'drafts' || ($msg['status'] ?? '') === 'zaplanowana'): ?>
        <span>Kopia robocza — wiadomość zapisana w historii, ale <strong>niewysłana</strong>.
        Żeby ją wysłać, otwórz kompozytor przy kontakcie i wklej treść.</span>
        <?php else: ?>
        <span>Wiadomość wychodząca · status: <strong><?= h($msg['status'] ?? '—') ?></strong></span>
        <?php endif; ?>
        <?php if (!empty($msg['contact_id'])): ?>
        <a class="ib-act ms-auto" href="<?= APP_URL ?>/crm/contact/view.php?id=<?= (int)$msg['contact_id'] ?>">
          <i class="bi bi-person" aria-hidden="true"></i>Kartoteka kontaktu
        </a>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <?php if ($can_write): ?>
      <!-- Panel asystenta — pusty do czasu kliknięcia, żeby nie zajmował miejsca -->
      <div class="ib-assist" id="ibAssist" hidden>
        <div class="ib-assist-head">
          <i class="bi bi-stars" aria-hidden="true"></i>
          <strong>Asystent AI</strong>
          <span class="ib-assist-note">propozycja do przeczytania i poprawienia — nic nie zostało wysłane</span>
          <button type="button" class="btn-close btn-sm ms-auto" id="ibAssistClose" aria-label="Zamknij"></button>
        </div>
        <div id="ibAssistBody"></div>
      </div>
      <?php endif; ?>

      <div class="ib-pane-cols">
      <div class="ib-content">
      <?php if (!empty($msg['attachments'])): ?>
      <?php
        /* Ikona i sposób podglądu wynikają z typu MIME, a gdy go brak — z
           rozszerzenia (część serwerów wysyła application/octet-stream dla wszystkiego). */
        $ib_att_kind = function (array $a): string {
            $mime = strtolower((string)($a['mime_type'] ?? ''));
            $ext  = strtolower(pathinfo((string)($a['original_name'] ?? ''), PATHINFO_EXTENSION));
            if (str_starts_with($mime, 'image/') || in_array($ext, ['jpg','jpeg','png','gif','webp','bmp','svg'], true)) return 'image';
            if ($mime === 'application/pdf' || $ext === 'pdf')                                                            return 'pdf';
            if (str_starts_with($mime, 'text/') || in_array($ext, ['txt','csv','log','md','json','xml'], true))           return 'text';
            return 'other';
        };
        $ib_att_icon = ['image' => 'bi-file-earmark-image', 'pdf' => 'bi-file-earmark-pdf',
                        'text'  => 'bi-file-earmark-text',  'other' => 'bi-file-earmark'];
        $ib_att_color= ['image' => '#6a00ff', 'pdf' => '#a20025', 'text' => '#00807e', 'other' => '#5f5f5f'];
      ?>
      <div class="ib-ctx">
        <div class="ib-lbl">Załączniki (<?= count($msg['attachments']) ?>)</div>
        <div class="ib-atts">
        <?php foreach ($msg['attachments'] as $a):
          $kind = $ib_att_kind($a);
          $size = (int)($a['size_bytes'] ?? 0);
          $human= $size >= 1048576 ? round($size / 1048576, 1) . ' MB'
                : ($size >= 1024   ? round($size / 1024) . ' kB' : ($size ?: '') . ($size ? ' B' : ''));
          /* Wszystkie załączniki idą przez crm/api/attachment.php: sprawdza dostęp
             do skrzynki, a plików, których nie ma na dysku, dociąga ze skrzynki na żądanie. */
          $url    = APP_URL . '/crm/api/attachment.php?id=' . (int)$a['id'];
          $onsrv  = !empty($a['stored_path']);
          $remote = !$onsrv && !empty($a['graph_attachment_id']);
        ?>
          <?php if ($onsrv || $remote): ?>
          <button type="button" class="ib-att" data-att-url="<?= h($url) ?>"
                  data-att-kind="<?= h($kind) ?>" data-att-name="<?= h((string)$a['original_name']) ?>"
                  title="<?= $onsrv
                      ? 'Pokaż podgląd — ' . h((string)$a['original_name'])
                      : 'Plik jest w skrzynce pocztowej — kliknij, aby go pobrać i otworzyć' ?>">
            <i class="bi <?= h($ib_att_icon[$kind]) ?>" style="color:<?= h($ib_att_color[$kind]) ?>" aria-hidden="true"></i>
            <span class="ib-att-name"><?= h((string)$a['original_name']) ?></span>
            <?php if ($human): ?><span class="ib-att-size"><?= h($human) ?></span><?php endif; ?>
            <?php if ($remote): ?>
            <i class="bi bi-cloud-arrow-down ib-att-cloud" title="Zostanie pobrany ze skrzynki przy pierwszym otwarciu" aria-hidden="true"></i>
            <?php endif; ?>
          </button>
          <?php else: ?>
          <span class="ib-att ib-att--missing" title="Wiadomość nie ma zapisanego odnośnika do pliku — otwórz ją w programie pocztowym">
            <i class="bi bi-paperclip" aria-hidden="true"></i>
            <span class="ib-att-name"><?= h((string)$a['original_name']) ?></span>
            <span class="ib-att-size">niedostępny</span>
          </span>
          <?php endif; ?>
        <?php endforeach; ?>
        </div>
      </div>
      <?php endif; ?>

      <?php /* Treść w ramce sandbox (bez allow-scripts): style nadawcy działają,
               ale nie dotykają CRM, a nic z treści nie może się wykonać. Wysokość
               ramki dopasowuje skrypt do treści — przewija się cały panel, nie ramka. */
            $frame_doc = crmInboxFrameDoc($msg);
            $has_remote = crmInboxHasRemote((string)($msg['body_html'] ?? '') . (crm_mail_looks_html((string)($msg['body'] ?? '')) ? (string)$msg['body'] : '')); ?>
      <?php if ($can_write && crmInboxBodyIsPreview($msg)): ?>
      <form method="post" class="ib-imgbar" data-ib-xhr="fetch_body"><?= $hidden ?><input type="hidden" name="_op" value="fetch_body">
        <i class="bi bi-scissors" aria-hidden="true"></i>
        <span>Przy pobieraniu zapisano tylko <strong>skrót treści</strong> — reszta jest w skrzynce pocztowej.</span>
        <button>Pobierz pełną treść</button>
      </form>
      <?php endif; ?>
      <?php if ($has_remote): ?>
      <div class="ib-imgbar" data-ib-imgbar>
        <i class="bi bi-image" aria-hidden="true"></i>
        <span>Obrazy z zewnętrznych serwerów są zablokowane — chroni to przed śledzeniem otwarcia wiadomości.</span>
        <button type="button" data-ib-imgs>Pokaż obrazy</button>
        <label><input type="checkbox" data-ib-imgs-always> zawsze pokazuj</label>
      </div>
      <?php endif; ?>
      <?php if ($conv_thread): ?>
      <div class="ib-conv-bar">
        <i class="bi bi-chat-left-text" aria-hidden="true"></i>
        <strong>Rozmowa</strong> · <?= count($conv_thread) ?> wiad.
        <a class="ms-auto" href="?<?= $qs(['conv' => $conv_on ? '0' : '1']) ?>"><?= $conv_on ? 'Tylko ta wiadomość' : 'Pokaż całą rozmowę' ?></a>
      </div>
      <?php endif; ?>
      <?php foreach ($conv_before as $cm) $conv_card($cm); ?>
      <?php if ($conv_before): ?><div class="ib-conv-cur"><i class="bi bi-arrow-down-short" aria-hidden="true"></i> Otwarta wiadomość</div><?php endif; ?>
      <iframe class="ib-frame" id="ibFrame" title="Treść wiadomości: <?= h($msg['subject'] ?: '(bez tematu)') ?>"
              sandbox="allow-same-origin allow-popups allow-popups-to-escape-sandbox"
              referrerpolicy="no-referrer" srcdoc="<?= h($frame_doc) ?>"></iframe>
      <?php foreach ($conv_after as $cm) $conv_card($cm); ?>
      </div><!-- /.ib-content -->

      <?php /* Sprawy, oferty i wątek — obok treści, gdy panel jest szeroki
               (zapytanie kontenerowe w inbox.css), pod treścią na węższym. */ ?>
      <?php if ($rail_cases || $rail_offers || $rail_thread): ?>
      <aside class="ib-rail" aria-label="Kontekst kontaktu">
        <?php include __DIR__ . '/includes/inbox_rail.php'; ?>
      </aside>
      <?php endif; ?>
      </div><!-- /.ib-pane-cols -->

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
            <div class="col-md-6 position-relative">
              <label class="form-label small fw-semibold mb-1">Kontakt (dla kogo sprawa)</label>
              <input type="hidden" name="case_contact_id" id="caseContactId" value="<?= (int)($msg['contact_id'] ?? 0) ?>">
              <input type="text" id="caseContactName" class="form-control form-control-sm"
                     value="<?= h($msg['contact_name'] ?? '') ?>" autocomplete="off"
                     placeholder="Domyślnie nadawca — zacznij pisać, by wybrać innego">
              <div id="caseContactSuggestions" class="list-group mt-1" style="position:absolute;z-index:500;min-width:280px;max-height:180px;overflow-y:auto;display:none"></div>
            </div>
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
          <?php /* Nazwa mówi, co się NAPRAWDĘ dzieje: wiadomość dostaje wpis
                   w dzienniku podawczym EZD (rejestr pism) i sygnaturę. „Przekaż do EZD"
                   sugerowało przeniesienie wiadomości gdzie indziej — a ona zostaje
                   w skrzynce, dochodzi jej tylko rejestracja.
                   Zanim ktoś kliknie, pokazujemy czego dotyczy decyzja (pełny temat)
                   i po co w ogóle jest dziennik. */ ?>
          <div class="ib-ezd-subject">
            <div class="ib-lbl">Wiadomość do zarejestrowania w dzienniku</div>
            <h2 class="ib-ezd-h2"><?= h($msg['subject'] ?: '(bez tematu)') ?></h2>
            <div class="text-muted" style="font-size:.8rem">
              <?= h($msg['from_name'] ?: ($msg['from_email'] ?: '—')) ?>
              · <?= h(date('d.m.Y H:i', strtotime((string)$msg['sent_at']))) ?>
              <?php $ezd_no = crm_msg_no((int)$msg['id'], $msg['msg_no'] ?? null); ?>
              <?php if ($ezd_no !== ''): ?> · <span class="ib-no">#<?= h($ezd_no) ?></span><?php endif; ?>
            </div>
          </div>
          <div class="ib-ezd-note">
            <i class="bi bi-info-circle-fill" aria-hidden="true"></i>
            <div>
              <strong>Dziennik podawczy EZD rejestruje korespondencję formalną</strong> — taką, która
              wszczyna albo dokumentuje sprawę urzędową: pismo z urzędu wzywające do działania,
              wniosek beneficjenta o umowę, wezwanie, skarga, decyzja. Rejestracja nadaje
              wiadomości sygnaturę i wpisuje ją do rejestru pism; sama wiadomość zostaje
              w skrzynce.
              <div class="mt-1">
                Zwykłe zapytanie handlowe, ustalenia z klientem czy korespondencja robocza
                <strong>nie trafiają do dziennika</strong> — zostają w CRM (sprawa CRM albo oferta).
              </div>
            </div>
          </div>
          <?php if (!$ezd_on): ?>
            <div class="text-muted" style="font-size:.85rem">
              Moduł EZD jest wyłączony albo nie masz w nim uprawnień do zapisu — rejestracja niedostępna.
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
                    Wiadomość dostanie wpis w dzienniku i sygnaturę przy tej koszulce,
                    a w skrzynce trafi do „załatwionych".
                  </div>
                </form>
              </div>
              <div class="col-md-6">
                <form method="post"><?= $hidden ?><input type="hidden" name="_op" value="ezd">
                  <div class="ib-lbl">Albo załóż nową koszulkę</div>
                  <div class="input-group input-group-sm mb-2">
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
                  <div class="row g-2 position-relative">
                    <div class="col-7">
                      <input type="hidden" name="strona_crm_id" id="ezdStronaCrmId" value="<?= (int)($msg['contact_id'] ?? 0) ?>">
                      <input type="text" id="ezdStronaName" name="strona_name" class="form-control form-control-sm"
                             value="<?= h($msg['contact_name'] ?? '') ?>" autocomplete="off"
                             placeholder="Strona sprawy — domyślnie nadawca">
                      <div id="ezdStronaSuggestions" class="list-group mt-1" style="position:absolute;z-index:500;min-width:260px;max-height:180px;overflow-y:auto;display:none"></div>
                    </div>
                    <div class="col-5">
                      <select name="strona_rola" class="form-select form-select-sm">
                        <option value="">— rola —</option>
                        <option value="Wnioskodawca">Wnioskodawca</option>
                        <option value="Strona">Strona</option>
                        <option value="Pełnomocnik">Pełnomocnik</option>
                        <option value="Uczestnik">Uczestnik</option>
                      </select>
                    </div>
                  </div>
                  <div class="form-text" style="font-size:.72rem">
                    Znak sprawy nadaje EZD — temat wiadomości staje się tytułem koszulki.
                    Kontakt powyżej zostaje wpisany jako strona koszulki — nadpisz go, gdy sprawa
                    dotyczy kogoś innego niż nadawca.
                    Nadawca dostanie e-mail „Informacja o zarejestrowaniu sprawy w systemie EZD FEER"
                    (nie wysyłamy go przy dopinaniu do istniejącej koszulki ani do nadawców automatycznych).
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
    </article>
  <?php endif; ?>
  </section>
</div><!-- /.ib-wrap -->
</div><!-- /.ib-shell -->

<div id="ibMsgModals">
<?php if ($msg && $can_write): ?>
<?php
  /* Modale akcji — POZA .ib-wrap, bo w kontenerze z overflow Bootstrap
     pozycjonuje je względem rodzica i okno potrafi zostać przycięte. */
  $mdls = [
      'mdCase' => ['Załóż sprawę CRM',  'bi-briefcase-fill',       '#0050ef', $mdl_case ?? ''],
      'mdEzd'  => ['Zarejestruj w dzienniku (EZD)', 'bi-journal-arrow-down', '#00807e', $mdl_ezd ?? ''],
      'mdFwd'  => ['Przekaż e-mailem',  'bi-forward-fill',         '#c25100', $mdl_fwd  ?? ''],
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
        <?php if ($mid !== 'mdEzd'): /* EZD ma własny, duży nagłówek tematu */ ?>
        <div class="text-muted mb-2" style="font-size:.8rem">
          <?= h(mb_strimwidth((string)($msg['subject'] ?: '(bez tematu)'), 0, 90, '…')) ?>
        </div>
        <?php endif; ?>
        <?= $mbody ?>
      </div>
    </div>
  </div>
</div>
<?php endforeach; ?>
<?php endif; ?>
</div>

<!-- ══ SKRÓTY KLAWISZOWE ════════════════════════════════════════════════════ -->
<div class="modal fade" id="ibKeysModal" tabindex="-1" aria-labelledby="ibKeysTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h5 class="modal-title fw-bold" id="ibKeysTitle" style="font-size:1rem">
          <i class="bi bi-keyboard me-2" aria-hidden="true"></i>Skróty klawiszowe Skrzynki
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Zamknij"></button>
      </div>
      <div class="modal-body pt-2">
        <div class="form-check form-switch mb-2">
          <input class="form-check-input" type="checkbox" id="ibKeysOn" checked>
          <label class="form-check-label" for="ibKeysOn">Skróty jednoklawiszowe włączone</label>
          <div class="form-text">Wyłącz, jeśli używasz sterowania głosem albo skróty kolidują z czytnikiem ekranu. Ustawienie zapamiętuje przeglądarka.</div>
        </div>
        <table class="ib-keys">
          <tr><th colspan="2">Przeglądanie</th></tr>
          <tr><td><kbd>j</kbd> <kbd>k</kbd></td><td>następna / poprzednia wiadomość</td></tr>
          <tr><td><kbd>↓</kbd> <kbd>↑</kbd></td><td>to samo, gdy kursor jest na liście</td></tr>
          <tr><td><kbd>Enter</kbd> <kbd>o</kbd></td><td>otwórz i przejdź do treści</td></tr>
          <tr><td><kbd>Esc</kbd></td><td>wróć do listy (na telefonie i z treści)</td></tr>
          <tr><td><kbd>/</kbd></td><td>szukaj w skrzynce</td></tr>
          <tr><td><kbd>x</kbd></td><td>zaznacz / odznacz wiadomość</td></tr>
          <tr><td><kbd>Shift</kbd>+<kbd>I</kbd></td><td>odczytaj wszystkie nieprzeczytane w widoku</td></tr>
          <tr><th colspan="2">Działania na otwartej wiadomości</th></tr>
          <tr><td><kbd>a</kbd></td><td>Wezmę to</td></tr>
          <tr><td><kbd>r</kbd></td><td>Odpowiedz</td></tr>
          <tr><td><kbd>e</kbd></td><td>Załatwione (albo Przywróć do obsługi)</td></tr>
          <tr><td><kbd>u</kbd></td><td>oznacz jako nieprzeczytaną</td></tr>
          <tr><td><kbd>!</kbd></td><td>spam</td></tr>
          <tr><td><kbd>c</kbd></td><td>załóż sprawę CRM</td></tr>
          <tr><td><kbd>z</kbd></td><td>zarejestruj w dzienniku EZD</td></tr>
          <tr><td><kbd>f</kbd></td><td>przekaż e-mailem</td></tr>
          <tr><td><kbd>?</kbd></td><td>to okno</td></tr>
        </table>
      </div>
    </div>
  </div>
</div>

<div class="ib-toast" id="ibToast" role="status" aria-live="polite" aria-label="Powiadomienie" hidden></div>
<div class="visually-hidden" id="ibLive" aria-live="polite"></div>

<!-- ══ PODGLĄD ZAŁĄCZNIKA ════════════════════════════════════════════════════ -->
<div class="modal fade" id="ibAttModal" tabindex="-1" aria-labelledby="ibAttTitle" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header py-2">
        <h5 class="modal-title text-truncate" id="ibAttTitle" style="font-size:.95rem"></h5>
        <div class="ms-auto d-flex gap-1 align-items-center">
          <a href="#" id="ibAttOpen" target="_blank" rel="noopener"
             class="btn btn-sm btn-outline-secondary" title="Otwórz w nowej karcie">
            <i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>
          </a>
          <a href="#" id="ibAttDownload" class="btn btn-sm btn-outline-secondary" title="Pobierz plik">
            <i class="bi bi-download" aria-hidden="true"></i>
          </a>
          <button type="button" class="btn-close ms-1" data-bs-dismiss="modal" aria-label="Zamknij"></button>
        </div>
      </div>
      <div class="modal-body" id="ibAttBody" style="background:#f7f7f7"></div>
    </div>
  </div>
</div>

<script>
window.IB = <?= json_encode([
    'poll'      => rtrim(APP_URL, '/') . '/crm/api/inbox_poll.php',
    'assistant' => rtrim(APP_URL, '/') . '/crm/api/inbox_assistant.php',
    'contacts'  => rtrim(APP_URL, '/') . '/crm/api/contacts_search.php',
    'params'    => ['view' => $view, 'mailbox_id' => $mbox_f ?: '', 'q' => $search],
    'view'      => $view,
    'lastId'    => (int)max(array_merge([0], array_column($inbox['rows'], 'id'))),
    'csrf'      => csrf_token(),
    'canWrite'  => $can_write,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>
<script src="<?= APP_URL ?>/modules/crm_inbox/view/inbox.js?v=<?= (int)@filemtime(dirname(__DIR__) . '/modules/crm_inbox/view/inbox.js') ?>"></script>

<?php include __DIR__ . '/includes/footer_crm.php'; ?>
