<?php
/**
 * modules/backup_remote/logic/backupRemoteActions.php — akcje karty „Kopia na serwer zdalny” w admin/backups.php
 * (POST _action=remote_*; CSRF i rola admin sprawdzane przez stronę-gospodarza).
 *
 * backupRemoteHandlePost() zwraca ['flash','type','redirect'] — przy błędach walidacji i teście połączenia
 * strona renderuje się na miejscu (redirect=false), z wartościami formularza i wynikiem testu w backupRemoteViewState().
 */

require_once dirname(__DIR__) . '/lib/backupRemote.php';

/** Stan widoku na czas jednego żądania: form (wartości do ponownego wyświetlenia), errors, test. */
function backupRemoteViewState(?array $set = null): array {
    static $state = [];
    if ($set !== null) $state = $set;
    return $state;
}

/** Wartości z formularza (pola br_*). Puste hasło = bez zmiany zapisanego. */
function backupRemoteFormFromPost(array $p): array {
    $cur  = backupRemoteConfig();
    $port = (int)($p['br_port'] ?? 22);
    return [
        'enabled'        => !empty($p['br_enabled']),
        'method'         => ($p['br_method'] ?? '') === 'rsync' ? 'rsync' : 'sftp',
        'host'           => trim((string)($p['br_host'] ?? '')),
        'port'           => $port,
        'user'           => trim((string)($p['br_user'] ?? '')),
        'path'           => trim((string)($p['br_path'] ?? '')),
        'auth'           => ($p['br_auth'] ?? '') === 'password' ? 'password' : 'key',
        'key_path'       => trim((string)($p['br_key_path'] ?? '')),
        'password'       => (string)($p['br_password'] ?? ''),
        'password_enc'   => $cur['password_enc'],
        'full_every'     => (int)($p['br_full_every'] ?? 1) === 7 ? 7 : 1,
        'retention_days' => max(0, min(3650, (int)($p['br_retention_days'] ?? 90))),
        'hostkey'        => $cur['hostkey'],
    ];
}

function backupRemoteHandlePost(string $act): array {
    $res = fn(string $msg, string $type, bool $redirect = true) => ['flash' => $msg, 'type' => $type, 'redirect' => $redirect];

    if ($act === 'remote_save') {
        $form = backupRemoteFormFromPost($_POST);
        $errors = $form['enabled'] ? backupRemoteValidate($form) : [];
        if ($errors) {
            $form['password'] = ''; // hasła nie odsyłamy do przeglądarki
            backupRemoteViewState(['form' => $form, 'errors' => $errors, 'open' => true]);
            return $res('Nie zapisano ustawień serwera zdalnego — popraw zaznaczone pola.', 'danger', false);
        }
        $wasEnabled = backupRemoteConfig()['enabled'];
        backupRemoteSaveConfig($form);
        admin_audit('backup_remote_settings', 'backups',
            'Kopia na serwer zdalny: ' . ($form['enabled'] ? 'włączona' : 'wyłączona') . ', ' . strtoupper($form['method'])
            . ' ' . $form['user'] . '@' . $form['host'] . ':' . $form['port'] . ' → ' . $form['path']
            . ', uwierzytelnianie ' . ($form['auth'] === 'key' ? 'kluczem' : 'hasłem')
            . ($form['auth'] === 'password' && $form['password'] !== '' ? ' (zmieniono hasło)' : ''));
        $msg = 'Zapisano ustawienia kopii na serwer zdalny.';
        if ($form['enabled'] && !$wasEnabled) {
            $msg .= ' Pierwsza wysyłka to pełna kopia — przy najbliższym przebiegu CRON (co godzinę) albo po kliknięciu „Wyślij teraz”.';
        }
        return $res($msg, 'success');
    }

    if ($act === 'remote_test') {
        @set_time_limit(120);
        $form = backupRemoteFormFromPost($_POST);
        $test = backupRemoteTest($form); // przypięcie klucza hosta aktualizuje $form['hostkey'] (org_setting() ma cache na żądanie)
        $form['password'] = '';
        backupRemoteViewState(['form' => $form, 'errors' => [], 'test' => $test, 'open' => true]);
        return $test['ok']
            ? $res('Test połączenia z serwerem zdalnym: OK. Ustawienia z formularza nie są zapisane, dopóki nie klikniesz „Zapisz”.', 'success', false)
            : $res('Test połączenia z serwerem zdalnym nie powiódł się — szczegóły w karcie „Kopia na serwer zdalny”.', 'danger', false);
    }

    if ($act === 'remote_genkey') {
        try {
            $k = backupRemoteGenerateKey();
        } catch (\Throwable $e) {
            return $res('Nie wygenerowano klucza: ' . h($e->getMessage()), 'danger');
        }
        org_setting_set('backup_remote_key_path', $k['path']);
        org_setting_set('backup_remote_auth', 'key');
        org_setting_set('backup_remote_password', '');
        admin_audit('backup_remote_genkey', 'backups', 'Wygenerowano nowy klucz SSH kopii zdalnej: ' . $k['path']);
        return $res('Wygenerowano nowy klucz SSH. Dopisz klucz publiczny (karta „Kopia na serwer zdalny”) do pliku ~/.ssh/authorized_keys na serwerze, potem kliknij „Test połączenia”.', 'warning');
    }

    if ($act === 'remote_forget_hostkey') {
        org_setting_set('backup_remote_hostkey', '');
        admin_audit('backup_remote_forget_hostkey', 'backups', 'Zapomniano klucz hosta serwera kopii zdalnej.');
        return $res('Zapomniano klucz hosta. Przy następnym połączeniu zostanie zapamiętany klucz przedstawiony przez serwer — najpierw zrób „Test połączenia” i porównaj odcisk z kluczem serwera.', 'warning');
    }

    if ($act === 'remote_send' || $act === 'remote_send_full') {
        @set_time_limit(0);
        @ignore_user_abort(true);
        $full = $act === 'remote_send_full';
        $r = $full ? backupRemoteRunFull('panel', true) : backupRemoteRunIncremental('panel');
        admin_audit($r['ok'] ? 'backup_remote_send' : 'backup_remote_send_failed', 'backups',
            ($full ? 'Pełna kopia' : 'Wysyłka') . ' na serwer zdalny z panelu: '
            . ($r['ok'] ? ((int)($r['files'] ?? 0) . ' plików, ' . backupRemoteHumanSize((int)($r['bytes'] ?? 0))) : ($r['error'] ?? 'błąd')));
        if (!empty($r['skipped']) && !$r['ok']) return $res(h($r['error'] ?? 'Pominięto.'), 'warning');
        if (!$r['ok']) return $res('Wysyłka na serwer zdalny nie powiodła się: ' . h($r['error'] ?? 'nieznany błąd'), 'danger');
        if ((int)($r['files'] ?? 0) === 0) {
            $msg = 'Brak nowych kopii lokalnych do wysłania — serwer zdalny jest aktualny.';
            if (!empty($r['skipped_plain'])) $msg .= ' Pominięto ' . (int)$r['skipped_plain'] . ' niezaszyfrowanych kopii (szyfrowanie jest włączone).';
            return $res($msg, 'info');
        }
        $msg = (!empty($r['first_full']) || $full ? 'Wysłano pełną kopię na serwer zdalny: ' : 'Wysłano na serwer zdalny: ')
             . (int)$r['files'] . ' plik(ów), ' . backupRemoteHumanSize((int)$r['bytes']) . '.';
        if (!empty($r['warning'])) $msg .= ' ' . h($r['warning']);
        return $res($msg, 'success');
    }

    return $res('Nieznana akcja.', 'danger');
}
