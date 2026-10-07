<?php
/** Zarządzanie kontami EDU z generatora (admin): zawieszanie, menedżer, MFA, role Entra. */
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/includes/db.php';
require_once dirname(__DIR__, 2) . '/includes/auth.php';
require_once dirname(__DIR__, 2) . '/includes/functions.php';
require_once dirname(__DIR__, 2) . '/includes/m365.php';
require_once dirname(__DIR__, 2) . '/includes/ti_online.php';
require_once __DIR__ . '/logic/msEduBulk.php';
require_once __DIR__ . '/logic/msEduBulkImport.php';

require_role('admin');
// Każda operacja zmieniająca konta wymaga świeżej weryfikacji IKA (porównanie z tenantem jest tylko odczytem)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_op'] ?? '') !== 'compare') msEduBulkIkaGuard();
$PAGE_TITLE = 'Konta EDU — zarządzanie';
$self = strtok((string)($_SERVER['REQUEST_URI'] ?? 'konta.php'), '?');

$cmp = null; $cmpErr = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($_POST['_op'] ?? '', ['compare', 'sync_missing', 'register_szo', 'recreate_szo', 'recreate_registry'], true)) {
    csrf_check();
    if (!m365_edu_ready()) $cmpErr = 'Tenant EDU nie jest skonfigurowany.';
    else {
        try {
            if ($_POST['_op'] === 'register_szo') {
                flash_set('success', 'Dodano do rejestru EDU: ' . msEduBulkRegisterSzoStudents((array)($_POST['sids'] ?? [])) . '.');
                header('Location: ' . $self); exit;
            }
            if ($_POST['_op'] === 'recreate_szo') {
                $r = msEduBulkRecreateSzoStudents((array)($_POST['sids'] ?? []));
                flash_set($r['errors'] ? 'warning' : 'success', 'Utworzono w tenancie EDU: ' . $r['done'] . ($r['errors'] ? '. Błędy: ' . implode(' | ', $r['errors']) : '.'));
                header('Location: ' . $self); exit;
            }
            if ($_POST['_op'] === 'recreate_registry') {
                $accs = msEduBulkAccountsByIds((array)($_POST['ids'] ?? []));
                // Hasła zachowujemy: z wyników bieżącej sesji (generator) i z wklejonej listy „login hasło”
                $passMap = [];
                foreach ($_SESSION['msEduBulkResult'] ?? [] as $sr) if (!empty($sr['upn']) && !empty($sr['password'])) $passMap[mb_strtolower($sr['upn'])] = $sr['password'];
                $passMap = msEduBulkParsePasswordList((string)($_POST['passwords'] ?? '')) + $passMap;
                $res = msEduBulkRecreateRegistry(m365_edu(), $accs, $passMap, msEduBulkSkuList($_POST['skus'] ?? []));
                $newPw = array_filter($res['ok'], fn($r) => $r['password_new']);
                if ($newPw) $_SESSION['msEduBulkResult'] = array_merge($_SESSION['msEduBulkResult'] ?? [], array_values($newPw));
                flash_set($res['errors'] || $newPw ? 'warning' : 'success', 'Utworzono w tenancie EDU: ' . count($res['ok'])
                    . ($newPw ? '. Dla ' . count($newPw) . ' kont nie znano wcześniejszego hasła — nadano nowe (lista w wynikach generatora): ' . implode(', ', array_column($newPw, 'upn')) : '')
                    . ($res['errors'] ? '. Błędy: ' . implode(' | ', $res['errors']) : '.'));
                header('Location: ' . $self); exit;
            }
            if ($_POST['_op'] === 'sync_missing') {
                $n = msEduBulkMarkMissingDeleted(msEduBulkAccountsByIds((array)($_POST['ids'] ?? [])));
                flash_set('success', 'Przeniesiono do usuniętych: ' . $n . '.');
                header('Location: ' . $self . '?widok=usuniete'); exit;
            }
            $cmp = msEduBulkCompareTenant(m365_edu());
            $zaSkus = m365_edu()->get_subscribed_skus();
            $cmpTenant = ['id' => m365_edu()->tenant_id(), 'domain' => function_exists('m365_training_domain') ? m365_training_domain() : ''];
        } catch (\Throwable $e) { $cmpErr = $e->getMessage(); }
    }
    $_GET['widok'] = 'porownanie';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = (string)($_POST['_op'] ?? '');
    // Szybka akcja przy koncie (suspend_one / restore_one) — dotyczy tylko tego jednego konta
    if (in_array($op, ['suspend_one', 'restore_one'], true)) { $op = substr($op, 0, -4); $_POST['ids'] = [(int)($_POST['one'] ?? 0)]; }
    $accs = msEduBulkAccountsByIds((array)($_POST['ids'] ?? []));
    if (in_array($op, ['regenerate_one', 'regenerate'], true)) {
        // Regeneracja konta (pojedyncza lub masowa): zakłada je ponownie w Entra ID z tym samym loginem (gdy go tam brak);
        // hasło jak wygenerowano wcześniej, jeśli jest w wynikach bieżącej sesji, inaczej nowe (trafia na wydruk).
        // Opcjonalnie e-mail z danymi, przeprosinami i własną wiadomością.
        $accs = $op === 'regenerate_one' ? msEduBulkAccountsByIds([(int)($_POST['one'] ?? 0)]) : $accs;
        if (!m365_edu_ready()) flash_set('danger', 'Tenant EDU nie jest skonfigurowany.');
        elseif (!$accs) flash_set('danger', 'Zaznacz konta.');
        else {
            require_once __DIR__ . '/logic/msEduBulkMail.php';
            $passMap = [];
            foreach ($_SESSION['msEduBulkResult'] ?? [] as $sr) if (!empty($sr['upn']) && !empty($sr['password'])) $passMap[mb_strtolower($sr['upn'])] = $sr['password'];
            $res = msEduBulkRecreateRegistry(m365_edu(), $accs, $passMap, msEduBulkSkuList($_POST['regen_skus'] ?? []),
                ['login' => (string)($_POST['regen_login'] ?? ''), 'password' => (string)($_POST['regen_pass'] ?? '')]);
            $noMail = []; $toMail = [];
            if ($res['ok']) {
                $_SESSION['msEduBulkReset'] = $res['ok']; // login + hasło do wydruku
                if (!empty($_POST['regen_mail'])) foreach ($res['ok'] as $row) {
                    if (!filter_var(trim((string)$row['email']), FILTER_VALIDATE_EMAIL)) { $noMail[] = $row['upn']; continue; }
                    $toMail[] = $row;
                }
                // E-mail NIE wychodzi od razu — najpierw podgląd treści i potwierdzenie (karta nad listą)
                if ($toMail) $_SESSION['msEduBulkRegenMail'] = ['rows' => $toMail, 'custom' => (string)($_POST['regen_msg'] ?? '')];
            }
            $msg = 'Zregenerowano w tenancie EDU: ' . count($res['ok']) . '/' . count($accs) . '.';
            if ($toMail) $msg .= ' Sprawdź treść e-maila (' . count($toMail) . ') poniżej i potwierdź wysyłkę.';
            if ($noMail) $msg .= ' Bez e-maila (dane na wydruku PDF): ' . implode(', ', $noMail) . '.';
            if ($res['ok'] && array_filter($res['ok'], fn($r) => $r['password_new'])) $msg .= ' Część kont dostała NOWE hasło — patrz wydruk PDF nad listą.';
            if ($res['errors']) $msg .= ' Błędy: ' . implode(' | ', $res['errors']);
            flash_set($res['errors'] || $noMail ? 'warning' : 'success', $msg);
        }
        header('Location: ' . $self); exit;
    }
    if (in_array($op, ['regen_mail_send', 'regen_mail_cancel', 'regen_mail_refresh'], true)) {
        require_once __DIR__ . '/logic/msEduBulkMail.php';
        $pm = $_SESSION['msEduBulkRegenMail'] ?? null;
        if (!$pm) flash_set('danger', 'Brak e-maili oczekujących na wysyłkę.');
        elseif ($op === 'regen_mail_refresh') { $_SESSION['msEduBulkRegenMail']['custom'] = (string)($_POST['regen_msg'] ?? ''); }
        elseif ($op === 'regen_mail_cancel') { unset($_SESSION['msEduBulkRegenMail']); flash_set('info', 'Wysyłka e-maili odrzucona (konta zostały zregenerowane, hasła są na wydruku).'); }
        else {
            $custom = array_key_exists('regen_msg', $_POST) ? (string)$_POST['regen_msg'] : $pm['custom'];
            $sent = 0; $fail = [];
            foreach ($pm['rows'] as $row) { $m = msEduBulkSendRegenMail($row, $custom); if ($m['sent']) $sent++; else $fail[] = $row['upn'] . ': ' . implode(' ', $m['failed']); }
            unset($_SESSION['msEduBulkRegenMail']);
            flash_set($fail ? 'warning' : 'success', 'Wysłano e-maile z danymi i przeprosinami: ' . $sent . '/' . count($pm['rows']) . ($fail ? '. Błędy: ' . implode(' | ', $fail) : '.'));
        }
        header('Location: ' . $self); exit;
    }
    if ($op === 'reset_mail') {
        if (!m365_edu_ready()) flash_set('danger', 'Tenant EDU nie jest skonfigurowany.');
        elseif (!$accs) flash_set('danger', 'Zaznacz konta.');
        else {
            require_once __DIR__ . '/logic/msEduBulkMail.php';
            $r = msEduBulkResetAndMail(m365_edu(), $accs, MS_EDU_BULK_RESET_MAIL_SUBJECT, MS_EDU_BULK_RESET_MAIL_BODY);
            $_SESSION['msEduBulkReset'] = $r['rows']; // nowe hasła do wydruku (lista / paski) — tylko w sesji admina
            $msg = 'Zresetowano hasła i zakolejkowano e-maile: ' . $r['done'] . '/' . count($accs) . '.';
            if ($r['skipped']) $msg .= ' Pominięto (bez zmiany hasła): ' . implode(' | ', $r['skipped']) . '.';
            if ($r['errors']) $msg .= ' Błędy: ' . implode(' | ', $r['errors']) . ($r['unsent'] ? ' — ich nowe hasła są na wydruku PDF poniżej.' : '');
            flash_set($r['errors'] || $r['skipped'] ? 'warning' : 'success', $msg);
        }
        header('Location: ' . $self); exit;
    }
    if (!m365_edu_ready()) flash_set('danger', 'Tenant EDU nie jest skonfigurowany.');
    elseif (!$accs) flash_set('danger', 'Zaznacz konta.');
    elseif ($op === 'role' && !isset(M365Graph::SAFE_DIRECTORY_ROLES[$_POST['role'] ?? ''])) flash_set('danger', 'Wybierz rolę.');
    else {
        $r = msEduBulkAccountsAct(m365_edu(), $accs, $op, ['licenses' => !empty($_POST['licenses']), 'manager' => $_POST['manager'] ?? '', 'role' => $_POST['role'] ?? '', 'group' => msEduBulkGroupFromPost($_POST), 'skus' => $_POST['skus'] ?? []]);
        flash_set($r['errors'] ? 'warning' : 'success', 'Wykonano: ' . $r['done'] . '/' . count($accs) . ($r['errors'] ? '. Błędy: ' . implode(' | ', $r['errors']) : '.'));
    }
    header('Location: ' . $self); exit;
}

// Wydruk nowych haseł po masowym resecie (dane tylko z sesji)
if (isset($_GET['pdf']) && !empty($_SESSION['msEduBulkReset'])) {
    $pr = $_SESSION['msEduBulkReset']; $pm = (string)$_GET['pdf'];
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="nowe_hasla_m365_edu_' . date('Ymd_His') . '.pdf"');
    echo $pm === 'cards' ? msEduBulkPdfCards($pr, 'Nowe hasło — konto Microsoft 365')
       : ($pm === 'strips' ? msEduBulkPdfStrips($pr, 'Nowe hasła — konta Microsoft 365')
       : ($pm === 'strips1' ? msEduBulkPdfStrips($pr, 'Nowe hasła — konta Microsoft 365', 1) : msEduBulkPdf($pr, 'Nowe hasła — konta Microsoft 365')));
    exit;
}
if (isset($_GET['reset_clear'])) { unset($_SESSION['msEduBulkReset']); header('Location: ' . $self); exit; }
$widok = in_array($_GET['widok'] ?? '', ['usuniete', 'porownanie'], true) ? $_GET['widok'] : 'wygenerowane';
$zaQ = trim((string)($_GET['q'] ?? '')); $zaStatus = (string)($_GET['status'] ?? ''); $zaGrupa = (string)($_GET['grupa'] ?? '');
$zaRows = $widok === 'wygenerowane' ? msEduBulkAccountsList($zaQ, $zaStatus, $zaGrupa) : [];
$delRows = $widok === 'usuniete' ? msEduBulkDeletedList($zaQ) : [];
$cntGen = (int)db()->query("SELECT COUNT(*) FROM msedu_bulk_accounts")->fetchColumn();
$cntDel = (int)db()->query("SELECT COUNT(*) FROM msedu_bulk_deleted")->fetchColumn();
$_alias = str_starts_with($self, '/ms');
$lnk = $_alias ? ['hub' => '/ms', 'kreator' => '/ms/kreator', 'konta' => '/ms/konta'] : ['hub' => 'index.php', 'kreator' => 'kreator.php', 'konta' => 'konta.php'];
$zaAction = $self; $zaTokenName = '_csrf'; $zaTokenValue = csrf_token(); $zaAllowRoles = true;
$zaSkus = m365_edu_ready() ? m365_edu()->get_subscribed_skus() : [];
$zaDetailUrl = str_starts_with($self, '/ms') ? '/ms/konto' : 'konto.php';
include dirname(__DIR__, 2) . '/includes/header.php';
?>
<div class="container-xl py-4">
  <div class="d-flex align-items-center gap-2 mb-3 flex-wrap">
    <h1 class="h3 fw-bold mb-0"><i class="bi bi-person-gear me-2"></i>Konta EDU — zarządzanie</h1>
  </div>
  <?php include __DIR__ . '/views/tenantInfo.php'; ?>
  <?= msEduBulkTabs(['hub' => ['Generator', $lnk['hub'], 'bi-people-fill'], 'kreator' => ['Kreator z listy (XLS)', $lnk['kreator'], 'bi-magic'], 'konta' => ['Administracja kont', $lnk['konta'], 'bi-person-gear']], 'konta') ?>
  <?= flash_html() ?>
  <?php if (!empty($_SESSION['msEduBulkRegenMail'])):
    require_once __DIR__ . '/logic/msEduBulkMail.php';
    $_rm = $_SESSION['msEduBulkRegenMail']; $_rr = $_rm['rows'];
    [$_rsub, $_rhtml] = msEduBulkMailRender(MS_EDU_BULK_REGEN_MAIL_SUBJECT, msEduBulkRegenMailBody($_rm['custom']), $_rr[0]); ?>
  <form method="post" action="<?= h($self) ?>" class="card border-warning mb-3">
    <input type="hidden" name="_csrf" value="<?= h(csrf_token()) ?>">
    <div class="card-header fw-semibold"><i class="bi bi-envelope-paper me-1"></i>Podgląd e-maila po regeneracji — nie wysłano (<?= count($_rr) ?> odbiorców)</div>
    <div class="card-body"><div class="row g-3">
      <div class="col-lg-7"><div class="small fw-semibold mb-1">Treść dla: <?= h($_rr[0]['imie'] . ' ' . $_rr[0]['nazwisko']) ?> &lt;<?= h($_rr[0]['email']) ?>&gt;</div>
        <div class="border rounded p-2 bg-white" style="font-size:.85rem"><div class="fw-semibold mb-2"><?= h($_rsub) ?></div><?= $_rhtml ?></div></div>
      <div class="col-lg-5"><label class="form-label small fw-semibold" for="rmMsg">Własna wiadomość (edytuj przed wysyłką)</label>
        <textarea id="rmMsg" name="regen_msg" rows="4" maxlength="1000" class="form-control form-control-sm"><?= h($_rm['custom']) ?></textarea>
        <div class="small text-muted mt-2">Odbiorcy: <?= h(implode(', ', array_map(fn($r) => $r['email'], array_slice($_rr, 0, 8)))) ?><?= count($_rr) > 8 ? ' i ' . (count($_rr) - 8) . ' więcej' : '' ?></div>
        <div class="small text-muted">Po zmianie wiadomości użyj „Odśwież podgląd”.</div></div>
    </div>
    <div class="d-flex gap-2 mt-3 flex-wrap">
      <button name="_op" value="regen_mail_refresh" class="btn btn-sm btn-outline-primary"><i class="bi bi-arrow-repeat me-1"></i>Odśwież podgląd</button>
      <button name="_op" value="regen_mail_cancel" class="btn btn-sm btn-outline-secondary" onclick="return confirm('Odrzucić wysyłkę e-maili?')">Nie wysyłaj</button>
      <button name="_op" value="regen_mail_send" class="btn btn-sm btn-warning ms-auto" onclick="return confirm('Wysłać e-maile z danymi logowania do <?= count($_rr) ?> osób?')"><i class="bi bi-send me-1"></i>Wyślij e-maile (<?= count($_rr) ?>)</button>
    </div></div>
  </form>
  <?php endif; ?>
  <?php if (!empty($_SESSION['msEduBulkReset'])): ?>
  <div class="alert alert-info d-flex align-items-center gap-2 flex-wrap" role="status">
    <span><strong>Nowe hasła (<?= count($_SESSION['msEduBulkReset']) ?>)</strong> po ostatnim resecie — wydrukuj i zachowaj poufnie:</span>
    <a href="?pdf=1" target="_blank" class="btn btn-sm btn-primary ms-auto"><i class="bi bi-printer me-1" aria-hidden="true"></i>Lista PDF</a>
    <a href="?pdf=strips" target="_blank" class="btn btn-sm btn-primary"><i class="bi bi-list-nested me-1" aria-hidden="true"></i>Paski: dwa bloki</a>
    <a href="?pdf=strips1" target="_blank" class="btn btn-sm btn-primary"><i class="bi bi-list-nested me-1" aria-hidden="true"></i>Paski: jedna kolumna</a>
    <a href="?pdf=cards" target="_blank" class="btn btn-sm btn-primary"><i class="bi bi-scissors me-1" aria-hidden="true"></i>Kartki</a>
    <a href="?reset_clear=1" class="btn btn-sm btn-outline-secondary" onclick="return confirm('Usunąć listę nowych haseł z sesji? Nie da się jej odzyskać.')">Wyczyść listę</a>
  </div>
  <?php endif; ?>
  <ul class="nav nav-tabs mb-3" aria-label="Podgląd kont">
    <li class="nav-item"><a class="nav-link<?= $widok === 'wygenerowane' ? ' active' : '' ?>" <?= $widok === 'wygenerowane' ? 'aria-current="page"' : '' ?> href="<?= h($self) ?>">Wygenerowane <span class="badge text-bg-secondary"><?= $cntGen ?></span></a></li>
    <li class="nav-item"><a class="nav-link<?= $widok === 'usuniete' ? ' active' : '' ?>" <?= $widok === 'usuniete' ? 'aria-current="page"' : '' ?> href="<?= h($self) ?>?widok=usuniete">Usunięte <span class="badge text-bg-secondary"><?= $cntDel ?></span></a></li>
    <li class="nav-item"><a class="nav-link<?= $widok === 'porownanie' ? ' active' : '' ?>" <?= $widok === 'porownanie' ? 'aria-current="page"' : '' ?> href="<?= h($self) ?>?widok=porownanie">Porównanie z tenantem</a></li>
  </ul>
  <?php include __DIR__ . '/views/' . ['usuniete' => 'usuniete', 'porownanie' => 'porownanie', 'wygenerowane' => 'zarzadzanie'][$widok] . '.php'; ?>
</div>
<?php include dirname(__DIR__, 2) . '/includes/footer.php';
