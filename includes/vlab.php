<?php
/**
 * includes/vlab.php — Warstwa VLAB: zarządzanie kontenerami Docker dla kursantów TI
 * przez połączenie SSH z uprzednio skonfigurowanym hostem.
 *
 * Połączenie SSH:
 *   1. phpseclib3\Net\SSH2 — jeśli biblioteka jest dostępna (composer require phpseclib/phpseclib),
 *   2. fallback: systemowa binarka `ssh` przez proc_open.
 *
 * Komendy docker budowane są WYŁĄCZNIE jako tablice argumentów (escapeshellarg),
 * dane od kursanta są sanityzowane — brak interpolacji surowego inputu do shella.
 *
 * ── Wymóg po stronie hosta ────────────────────────────────────────────────────
 * Obrazy z szablonów (k30_ti_vlab_templates.docker_image) powinny udostępniać:
 *   - sshd na porcie 22   (dostęp SSH dla kursanta),
 *   - ttyd na porcie 7681 (terminal w przeglądarce), uruchamiany np.:
 *       ttyd -p 7681 -c <user>:<pass> bash
 * Kontener startuje z `-P` (publish wszystkich EXPOSE na losowe porty hosta);
 * porty hosta dla 22/7681 odczytywane są przez `docker port`.
 *
 * Przykładowy Dockerfile sandboxa:
 *   FROM ubuntu:22.04
 *   RUN apt-get update && apt-get install -y openssh-server sudo curl \
 *       && curl -L https://github.com/tsl0922/ttyd/releases/latest/download/ttyd.x86_64 -o /usr/bin/ttyd \
 *       && chmod +x /usr/bin/ttyd && mkdir /run/sshd
 *   EXPOSE 22 7681
 *   COPY entrypoint.sh /entrypoint.sh
 *   CMD ["/entrypoint.sh"]   # ustawia hasło użytkownika z env, startuje sshd + ttyd
 */

require_once __DIR__ . '/db.php';

/** Zwraca (i w razie potrzeby tworzy) wiersz konfiguracji VLAB. */
function vlab_config(): array {
    $row = db_one("SELECT * FROM k30_ti_vlab_config WHERE id=1");
    if (!$row) {
        db()->exec("INSERT OR IGNORE INTO k30_ti_vlab_config (id) VALUES (1)");
        $row = db_one("SELECT * FROM k30_ti_vlab_config WHERE id=1");
    }
    return $row ?: [];
}

/** Czy moduł jest skonfigurowany i włączony. */
function vlab_enabled(): bool {
    $c = vlab_config();
    return !empty($c['is_enabled']) && $c['ssh_host'] !== '' && $c['ssh_user'] !== '';
}

/**
 * Wykonuje polecenie na zdalnym hoście po SSH.
 * $argv — tablica argumentów (pierwszy = program, np. 'docker'); każdy element escapowany.
 * Zwraca ['ok'=>bool,'out'=>string,'err'=>string,'code'=>int].
 */
function vlab_ssh_exec(array $argv): array {
    $c = vlab_config();
    if (empty($c['ssh_host']) || empty($c['ssh_user'])) {
        return ['ok' => false, 'out' => '', 'err' => 'VLAB: brak konfiguracji hosta SSH.', 'code' => -1];
    }
    $cmd = implode(' ', array_map('escapeshellarg', $argv));

    // 1) phpseclib, jeśli dostępne
    if (class_exists('\\phpseclib3\\Net\\SSH2')) {
        try {
            $ssh = new \phpseclib3\Net\SSH2($c['ssh_host'], (int)($c['ssh_port'] ?: 22));
            $ssh->setTimeout(60);
            $authed = false;
            if (($c['ssh_auth'] ?? 'key') === 'key' && !empty($c['ssh_key_path']) && is_readable($c['ssh_key_path'])) {
                $key = \phpseclib3\Crypt\PublicKeyLoader::load(file_get_contents($c['ssh_key_path']));
                $authed = $ssh->login($c['ssh_user'], $key);
            } else {
                $authed = $ssh->login($c['ssh_user'], (string)($c['ssh_password'] ?? ''));
            }
            if (!$authed) {
                return ['ok' => false, 'out' => '', 'err' => 'SSH: autoryzacja nieudana (phpseclib).', 'code' => -1];
            }
            $out  = $ssh->exec($cmd);
            $code = $ssh->getExitStatus();
            return ['ok' => $code === 0, 'out' => trim((string)$out), 'err' => $code === 0 ? '' : trim((string)$out), 'code' => (int)$code];
        } catch (\Throwable $e) {
            // spróbuj fallbacku systemowego
        }
    }

    // 2) fallback: systemowa binarka ssh
    $ssh_args = [
        'ssh',
        '-o', 'BatchMode=yes',
        '-o', 'StrictHostKeyChecking=accept-new',
        '-o', 'ConnectTimeout=15',
        '-p', (string)($c['ssh_port'] ?: 22),
    ];
    if (($c['ssh_auth'] ?? 'key') === 'key' && !empty($c['ssh_key_path'])) {
        $ssh_args[] = '-i';
        $ssh_args[] = $c['ssh_key_path'];
    }
    $ssh_args[] = $c['ssh_user'] . '@' . $c['ssh_host'];
    $ssh_args[] = $cmd;

    $full = implode(' ', array_map('escapeshellarg', $ssh_args));
    $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open($full, $desc, $pipes);
    if (!is_resource($proc)) {
        return ['ok' => false, 'out' => '', 'err' => 'Nie można uruchomić procesu ssh.', 'code' => -1];
    }
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $code = proc_close($proc);
    return ['ok' => $code === 0, 'out' => trim((string)$out), 'err' => trim((string)$err), 'code' => (int)$code];
}

/** Test połączenia z demonem Dockera. */
function vlab_docker_ping(): array {
    $r = vlab_ssh_exec(['docker', 'version', '--format', '{{.Server.Version}}']);
    return $r;
}

/** Odczytuje zmapowany port hosta dla danego portu kontenera. */
function vlab_docker_port(string $name, int $containerPort): ?int {
    $r = vlab_ssh_exec(['docker', 'port', $name, (string)$containerPort]);
    if (!$r['ok'] || $r['out'] === '') return null;
    // format: 0.0.0.0:49153  (lub kilka linii / [::]:49153)
    $line = trim(explode("\n", $r['out'])[0]);
    if (preg_match('/:(\d+)\s*$/', $line, $m)) return (int)$m[1];
    return null;
}

/** Stan kontenera wg Dockera: running|exited|... lub null gdy nie istnieje. */
function vlab_docker_state(string $name): ?string {
    $r = vlab_ssh_exec(['docker', 'inspect', '-f', '{{.State.Status}}', $name]);
    if (!$r['ok']) return null;
    return $r['out'] !== '' ? $r['out'] : null;
}

/** Sanityzacja etykiety kursanta → [a-z0-9-], maks 32 znaki. */
function vlab_sanitize_label(string $label): string {
    $s = strtolower(trim($label));
    $s = preg_replace('/[^a-z0-9-]+/', '-', $s);
    $s = trim($s, '-');
    return substr($s, 0, 32);
}

/** Loguje operację VLAB do audytu. */
function vlab_log(?int $containerId, int $studentId, string $action, bool $ok, string $detail = ''): void {
    try {
        db_insert('k30_ti_vlab_log', [
            'container_id' => $containerId,
            'student_id'   => $studentId,
            'action'       => $action,
            'ok'           => $ok ? 1 : 0,
            'detail'       => mb_substr($detail, 0, 2000),
            'created_at'   => date('Y-m-d H:i:s'),
        ]);
    } catch (\Throwable $e) { /* audyt nie może blokować operacji */ }
}

/** Liczba aktywnych (nieusuniętych) kontenerów kursanta. */
function vlab_student_count(int $studentId): int {
    $r = db_one("SELECT COUNT(*) c FROM k30_ti_vlab_containers WHERE student_id=? AND status!='removed'", [$studentId]);
    return (int)($r['c'] ?? 0);
}

/**
 * Provisioning nowego kontenera dla kursanta.
 * Zwraca ['ok'=>bool,'msg'=>string,'id'=>?int].
 */
function vlab_provision(int $studentId, int $clientId, int $templateId, string $label): array {
    if (!vlab_enabled()) return ['ok' => false, 'msg' => 'Moduł VLAB nie jest skonfigurowany.'];

    $cfg = vlab_config();
    $tpl = db_one("SELECT * FROM k30_ti_vlab_templates WHERE id=? AND is_active=1", [$templateId]);
    if (!$tpl) return ['ok' => false, 'msg' => 'Nieprawidłowy szablon.'];

    $max = (int)($cfg['max_per_student'] ?? 3);
    if (vlab_student_count($studentId) >= $max) {
        return ['ok' => false, 'msg' => "Osiągnięto limit maszyn ({$max})."];
    }

    $label = vlab_sanitize_label($label) ?: 'lab';
    $name  = 'vlab_s' . $studentId . '_' . substr(bin2hex(random_bytes(4)), 0, 8);

    // Wygenerowane dane dostępowe (sandbox throwaway)
    $sshUser  = 'student';
    $sshPass  = bin2hex(random_bytes(6));
    $ttydUser = 'lab';
    $ttydPass = bin2hex(random_bytes(6));

    $row = [
        'student_id'     => $studentId,
        'client_id'      => $clientId ?: null,
        'template_id'    => $templateId,
        'label'          => $label,
        'container_name' => $name,
        'status'         => 'provisioning',
        'ssh_user'       => $sshUser,
        'ssh_password'   => $sshPass,
        'ttyd_user'      => $ttydUser,
        'ttyd_password'  => $ttydPass,
        'created_at'     => date('Y-m-d H:i:s'),
        'last_action_at' => date('Y-m-d H:i:s'),
    ];
    $id = db_insert('k30_ti_vlab_containers', $row);

    // Budowa polecenia docker run
    $argv = ['docker', 'run', '-d', '--name', $name, '-P'];
    $cpus = $tpl['cpus'] !== '' ? $tpl['cpus'] : ($cfg['default_cpus'] ?? '');
    $mem  = $tpl['mem']  !== '' ? $tpl['mem']  : ($cfg['default_mem'] ?? '');
    if ($cpus !== '') { $argv[] = '--cpus'; $argv[] = $cpus; }
    if ($mem  !== '') { $argv[] = '-m';     $argv[] = $mem; }
    // Dane dostępowe przekazywane do entrypointu kontenera przez env
    $argv[] = '-e'; $argv[] = 'VLAB_SSH_USER=' . $sshUser;
    $argv[] = '-e'; $argv[] = 'VLAB_SSH_PASS=' . $sshPass;
    $argv[] = '-e'; $argv[] = 'VLAB_TTYD_USER=' . $ttydUser;
    $argv[] = '-e'; $argv[] = 'VLAB_TTYD_PASS=' . $ttydPass;
    $argv[] = '--label'; $argv[] = 'feer.vlab=1';
    $argv[] = $tpl['docker_image'];
    if (!empty($tpl['run_cmd'])) {
        // run_cmd dzielony po spacjach (komenda definiowana przez admina, nie kursanta)
        foreach (preg_split('/\s+/', trim($tpl['run_cmd'])) as $part) {
            if ($part !== '') $argv[] = $part;
        }
    }

    $r = vlab_ssh_exec($argv);
    if (!$r['ok']) {
        db_update('k30_ti_vlab_containers', ['status' => 'error', 'error_msg' => mb_substr($r['err'] ?: 'docker run nieudane', 0, 1000)], $id);
        vlab_log($id, $studentId, 'create', false, $r['err']);
        return ['ok' => false, 'msg' => 'Nie udało się utworzyć maszyny: ' . ($r['err'] ?: 'błąd Dockera'), 'id' => $id];
    }

    $cid     = trim(explode("\n", $r['out'])[0]);
    $sshPort = (int)$tpl['expose_ssh']  ? vlab_docker_port($name, 22)   : null;
    $ttydPort= (int)$tpl['expose_ttyd'] ? vlab_docker_port($name, 7681) : null;

    db_update('k30_ti_vlab_containers', [
        'container_id' => substr($cid, 0, 64),
        'status'       => 'running',
        'ssh_port'     => $sshPort,
        'ttyd_port'    => $ttydPort,
        'error_msg'    => '',
        'last_action_at' => date('Y-m-d H:i:s'),
    ], $id);
    vlab_log($id, $studentId, 'create', true, $name);

    // Dane dostępowe do maszyny wysyłamy kursantowi mailem (hasło do panelu idzie SMS-em).
    $container = db_one("SELECT * FROM k30_ti_vlab_containers WHERE id=?", [$id]);
    if ($container) vlab_email_credentials($container);

    return ['ok' => true, 'msg' => 'Maszyna utworzona.', 'id' => $id];
}

/**
 * Wysyła kursantowi e-mail z danymi dostępowymi do kontenera (SSH + terminal ttyd).
 * Adres pobierany z k30_clients.email. Zwraca true gdy zlecono wysyłkę.
 */
function vlab_email_credentials(array $container): bool {
    $cfg    = vlab_config();
    $client = !empty($container['client_id'])
        ? db_one("SELECT name,email FROM k30_clients WHERE id=?", [$container['client_id']])
        : null;
    $email = trim((string)($client['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        vlab_log((int)$container['id'], (int)$container['student_id'], 'email', false, 'Brak adresu e-mail kursanta.');
        return false;
    }

    if (!function_exists('mail_queue_add')) {
        @require_once __DIR__ . '/mail_queue.php';
    }
    if (!function_exists('mail_queue_add')) {
        vlab_log((int)$container['id'], (int)$container['student_id'], 'email', false, 'Brak modułu mail_queue.');
        return false;
    }

    $org    = defined('ORG_NAME') ? ORG_NAME : 'VLab';
    $name   = htmlspecialchars($client['name'] ?? '', ENT_QUOTES);
    $label  = htmlspecialchars($container['label'], ENT_QUOTES);
    $host   = htmlspecialchars($cfg['public_host'] ?? '', ENT_QUOTES);
    $ttyd   = vlab_ttyd_url($container);

    $rows = '';
    if (!empty($container['ssh_port']) && $host !== '') {
        $sshCmd = 'ssh ' . htmlspecialchars($container['ssh_user'], ENT_QUOTES) . '@' . $host
                . ' -p ' . (int)$container['ssh_port'];
        $rows .= "<tr><td style='padding:4px 12px;color:#555'>Połączenie SSH</td><td style='padding:4px 12px'><code>{$sshCmd}</code></td></tr>";
        $rows .= "<tr><td style='padding:4px 12px;color:#555'>Hasło SSH</td><td style='padding:4px 12px'><code>" . htmlspecialchars($container['ssh_password'], ENT_QUOTES) . "</code></td></tr>";
    }
    if ($ttyd) {
        $tt = htmlspecialchars($ttyd, ENT_QUOTES);
        $rows .= "<tr><td style='padding:4px 12px;color:#555'>Terminal w przeglądarce</td><td style='padding:4px 12px'><a href='{$tt}'>{$tt}</a></td></tr>";
        $rows .= "<tr><td style='padding:4px 12px;color:#555'>Login terminala</td><td style='padding:4px 12px'><code>" . htmlspecialchars($container['ttyd_user'], ENT_QUOTES) . " / " . htmlspecialchars($container['ttyd_password'], ENT_QUOTES) . "</code></td></tr>";
    }

    $subject = "VLab — dane dostępowe do maszyny „{$container['label']}”";
    $html = "<p>Cześć {$name},</p>"
          . "<p>Twoja maszyna wirtualna <strong>{$label}</strong> w {$org} jest gotowa. Poniżej dane dostępowe:</p>"
          . "<table style='border-collapse:collapse;font-size:14px'>{$rows}</table>"
          . "<p style='color:#888;font-size:12px;margin-top:16px'>Wiadomość wygenerowana automatycznie. Nie udostępniaj tych danych osobom trzecim.</p>";

    try {
        mail_queue_add($email, $client['name'] ?? '', $subject, $html, '', 'vlab', (int)$container['id'], '', true);
        vlab_log((int)$container['id'], (int)$container['student_id'], 'email', true, $email);
        return true;
    } catch (\Throwable $e) {
        vlab_log((int)$container['id'], (int)$container['student_id'], 'email', false, $e->getMessage());
        return false;
    }
}

/**
 * Operacja na kontenerze kursanta (start|stop|restart|remove), z weryfikacją właściciela.
 * Zwraca ['ok'=>bool,'msg'=>string].
 */
function vlab_action(int $containerId, int $studentId, string $op): array {
    $row = db_one("SELECT * FROM k30_ti_vlab_containers WHERE id=? AND student_id=?", [$containerId, $studentId]);
    if (!$row) return ['ok' => false, 'msg' => 'Maszyna nie istnieje.'];
    if ($row['status'] === 'removed') return ['ok' => false, 'msg' => 'Maszyna została już usunięta.'];

    $name = $row['container_name'];
    switch ($op) {
        case 'start':   $argv = ['docker', 'start', $name];          $newStatus = 'running'; break;
        case 'stop':    $argv = ['docker', 'stop',  $name];          $newStatus = 'stopped'; break;
        case 'restart': $argv = ['docker', 'restart', $name];        $newStatus = 'running'; break;
        case 'remove':  $argv = ['docker', 'rm', '-f', $name];       $newStatus = 'removed'; break;
        default: return ['ok' => false, 'msg' => 'Nieznana operacja.'];
    }

    $r = vlab_ssh_exec($argv);
    if (!$r['ok']) {
        vlab_log($containerId, $studentId, $op, false, $r['err']);
        return ['ok' => false, 'msg' => 'Operacja nieudana: ' . ($r['err'] ?: 'błąd Dockera')];
    }

    $upd = ['status' => $newStatus, 'last_action_at' => date('Y-m-d H:i:s'), 'error_msg' => ''];
    if ($op === 'remove') {
        $upd['removed_at'] = date('Y-m-d H:i:s');
    } elseif ($op === 'start' || $op === 'restart') {
        // porty mogą się zmienić po restarcie
        $tpl = $row['template_id'] ? db_one("SELECT * FROM k30_ti_vlab_templates WHERE id=?", [$row['template_id']]) : null;
        if (!$tpl || (int)$tpl['expose_ssh'])  $upd['ssh_port']  = vlab_docker_port($name, 22);
        if (!$tpl || (int)$tpl['expose_ttyd']) $upd['ttyd_port'] = vlab_docker_port($name, 7681);
    }
    db_update('k30_ti_vlab_containers', $upd, $containerId);
    vlab_log($containerId, $studentId, $op, true, $name);
    return ['ok' => true, 'msg' => 'Gotowe.'];
}

/** Buduje URL do terminala ttyd dla kontenera (lub null). */
function vlab_ttyd_url(array $container): ?string {
    $c = vlab_config();
    if (empty($c['ttyd_enabled']) || empty($container['ttyd_port']) || empty($c['public_host'])) return null;
    $scheme = ($c['ttyd_scheme'] ?? 'http') === 'https' ? 'https' : 'http';
    return $scheme . '://' . $c['public_host'] . ':' . (int)$container['ttyd_port'] . '/';
}

/** Synchronizuje status kontenera z faktycznym stanem Dockera (best-effort). */
function vlab_refresh(int $containerId, int $studentId): array {
    $row = db_one("SELECT * FROM k30_ti_vlab_containers WHERE id=? AND student_id=?", [$containerId, $studentId]);
    if (!$row || $row['status'] === 'removed') return ['ok' => false, 'msg' => 'Brak maszyny.'];
    $state = vlab_docker_state($row['container_name']);
    if ($state === null) {
        db_update('k30_ti_vlab_containers', ['status' => 'error', 'error_msg' => 'Kontener nie istnieje na hoście.'], $containerId);
        return ['ok' => true, 'msg' => 'Zaktualizowano.'];
    }
    $map = ['running' => 'running', 'exited' => 'stopped', 'created' => 'stopped', 'paused' => 'stopped'];
    db_update('k30_ti_vlab_containers', ['status' => $map[$state] ?? 'error'], $containerId);
    return ['ok' => true, 'msg' => 'Zaktualizowano.'];
}
