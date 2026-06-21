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
 * ── Konto logowania kursanta na hoście ────────────────────────────────────────
 * Dla każdej maszyny zakładane jest konto systemowe na HOŚCIE (nazwa = nazwa
 * kontenera). Jego login shell to wrapper `docker exec -it <kontener>`, więc
 * `ssh <konto>@<host>` wpuszcza kursanta wprost do kontenera. Konto kasowane
 * przy usuwaniu maszyny. WYMÓG: użytkownik SSH (vlab_config.ssh_user) musi mieć
 * passwordless sudo (useradd/usermod/userdel/chpasswd) LUB być rootem; host musi
 * mieć grupę `docker` (konto kursanta jest do niej dodawane). Hasło konta
 * pokazywane jest kursantowi JEDEN raz (panel + e-mail), nie jest zapisywane.
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

/** Czy administrator wyłączył VLAB dla kursantów (tryb przerwy/konserwacji). */
function vlab_is_disabled(): bool {
    return !empty(vlab_config()['is_disabled']);
}

/** Komunikat wyświetlany kursantom przy wyłączonym VLAB (własny lub domyślny). */
function vlab_disabled_notice(): string {
    $n = trim((string)(vlab_config()['disabled_notice'] ?? ''));
    return $n !== '' ? $n : 'Moduł VLab jest chwilowo niedostępny. Spróbuj ponownie później.';
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

/**
 * Wykonuje skrypt powłoki na hoście z uprawnieniami roota.
 * Najpierw przez `sudo -n` (wymaga passwordless sudo dla użytkownika SSH);
 * gdy sudo niedostępne/wymaga hasła/tty, próbuje wprost (gdy użytkownik SSH jest rootem).
 * Gdy oba podejścia zawiodą z braku uprawnień, zwraca czytelny komunikat.
 */
function vlab_ssh_root(string $script): array {
    $r = vlab_ssh_exec(['sudo', '-n', 'sh', '-c', $script]);
    // sudo niedostępne / wymaga hasła / wymaga tty / nieznane polecenie → spróbuj wprost (root)
    if (!$r['ok'] && preg_match('/\bsudo\b|not found|command not found|tty|password is required|a terminal is required/i', $r['err'] . ' ' . $r['out'])) {
        $r = vlab_ssh_exec(['sh', '-c', $script]);
    }
    // Oba podejścia bez uprawnień → jasna wskazówka dla administratora
    if (!$r['ok'] && preg_match('/permission denied|operation not permitted|must be (root|superuser)|only root/i', $r['err'] . ' ' . $r['out'])) {
        $c = vlab_config();
        $r['err'] = 'Konto SSH „' . ($c['ssh_user'] ?? '?') . '" nie ma uprawnień root ani bezhasłowego sudo na hoście '
            . '(' . ($c['ssh_host'] ?? '?') . ') — nie można zarządzać kontami systemowymi. '
            . 'Skonfiguruj passwordless sudo dla useradd/usermod/chpasswd/chage lub łącz się jako root. '
            . 'Szczegóły: ' . trim($r['err'] !== '' ? $r['err'] : $r['out']);
    }
    return $r;
}

/** Nazwa konta hosta dla kontenera (= nazwa kontenera; bezpieczny zestaw znaków). */
function vlab_host_username(array $container): string {
    return preg_replace('/[^a-z0-9_]/', '', strtolower((string)($container['container_name'] ?? '')));
}

/**
 * Tworzy na hoście konto systemowe, którego logowanie SSH od razu wpuszcza
 * kursanta do kontenera (login shell = wrapper `docker exec`). Hasło jest
 * zwracane jednorazowo (NIE jest przechowywane w bazie).
 *
 * $forceChange=true → ustawia `chage -d 0`, więc sshd (UsePAM yes) wymusi zmianę
 * hasła przy najbliższym logowaniu SSH (działa niezależnie od powłoki logowania).
 *
 * Zwraca ['ok'=>bool,'user'=>string,'password'=>string,'msg'=>string].
 */
function vlab_host_user_create(array $container, bool $forceChange = false): array {
    $u    = vlab_host_username($container);
    $name = (string)$container['container_name'];
    if ($u === '' || $name === '') return ['ok' => false, 'msg' => 'Brak nazwy kontenera.'];

    // Hasło: heks + stały sufiks (mała+wielka litera, cyfra, znak specjalny) — bez apostrofu.
    $pass = bin2hex(random_bytes(8)) . 'Aa1!';
    $w    = '/usr/local/bin/vlab-' . $u;

    // Jeden skrypt → jedno połączenie SSH. $u/$name/$pass z bezpiecznych zestawów znaków.
    $script = implode("\n", [
        'set -e',
        "U='{$u}'",
        "NM='{$name}'",
        "P='{$pass}'",
        'FORCE=' . ($forceChange ? '1' : '0'),
        'W="/usr/local/bin/vlab-$U"',
        // wrapper: natychmiast wchodzi do kontenera (root w kontenerze = sandbox)
        'printf \'#!/bin/sh\nexec docker exec -it %s bash -l 2>/dev/null || exec docker exec -it %s sh -l\n\' "$NM" "$NM" > "$W"',
        'chmod 755 "$W"',
        'grep -qxF "$W" /etc/shells 2>/dev/null || echo "$W" >> /etc/shells',
        'id "$U" >/dev/null 2>&1 || useradd -m -s "$W" "$U"',
        'usermod -s "$W" "$U"',
        'printf \'%s:%s\' "$U" "$P" | chpasswd',
        // wymuszenie zmiany hasła przy następnym logowaniu (jeśli zażądano)
        '[ "$FORCE" = 1 ] && (chage -d 0 "$U" 2>/dev/null || passwd -e "$U" 2>/dev/null) || true',
        // dostęp do dockera dla wrappera (grupa docker; jeśli brak — pomijamy)
        'getent group docker >/dev/null 2>&1 && usermod -aG docker "$U" || true',
    ]);

    $r = vlab_ssh_root($script);
    if (!$r['ok']) {
        return ['ok' => false, 'msg' => $r['err'] ?: 'Nie udało się utworzyć konta na hoście.'];
    }
    return ['ok' => true, 'user' => $u, 'password' => $pass, 'force_change' => $forceChange, 'msg' => 'Konto hosta utworzone.'];
}

/**
 * Wymusza zmianę hasła SSH konta hosta przy najbliższym logowaniu (bez zmiany
 * samego hasła). Używa `chage -d 0` (fallback `passwd -e`). Zwraca ['ok','msg'].
 */
function vlab_host_user_force_pwchange(array $container): array {
    $u = vlab_host_username($container);
    if ($u === '') return ['ok' => false, 'msg' => 'Brak konta hosta dla tej maszyny.'];
    $script = implode("\n", [
        "U='{$u}'",
        'id "$U" >/dev/null 2>&1 || { echo "no-user"; exit 1; }',
        'chage -d 0 "$U" 2>/dev/null || passwd -e "$U"',
    ]);
    $r = vlab_ssh_root($script);
    return ['ok' => $r['ok'], 'msg' => $r['ok'] ? 'Wymuszono zmianę hasła przy następnym logowaniu.' : ($r['err'] ?: 'Nie udało się wymusić zmiany hasła.')];
}

/** Usuwa konto systemowe hosta powiązane z kontenerem (best-effort). */
function vlab_host_user_remove(array $container): array {
    $u = vlab_host_username($container);
    if ($u === '') return ['ok' => true];
    $script = implode("\n", [
        "U='{$u}'",
        'userdel -r "$U" 2>/dev/null || true',
        'rm -f "/usr/local/bin/vlab-$U"',
        'sed -i "\\#^/usr/local/bin/vlab-$U\\$#d" /etc/shells 2>/dev/null || true',
    ]);
    return vlab_ssh_root($script);
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

/** Inicjały kursanta z nazwy (np. „Jan Kowalski" → „jk"); fallback „k". */
function vlab_initials(string $name): string {
    $ini = '';
    foreach (preg_split('/\s+/', trim($name)) as $p) {
        if ($p !== '') $ini .= mb_substr($p, 0, 1);
    }
    $ini = strtolower(preg_replace('/[^a-z]/i', '', $ini));
    return $ini !== '' ? substr($ini, 0, 4) : 'k';
}

/** Krótka, bezpieczna nazwa obrazu (bez registry/tagu): „ubuntu:latest" → „ubuntu". */
function vlab_image_slug(string $image): string {
    $img = preg_replace('/:.*$/', '', $image);   // odetnij :tag
    $img = preg_replace('#^.*/#', '', $img);      // ostatni segment po /
    $img = strtolower(preg_replace('/[^a-z0-9]/i', '', $img));
    return $img !== '' ? substr($img, 0, 12) : 'img';
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
    if (vlab_is_disabled()) return ['ok' => false, 'msg' => vlab_disabled_notice()];

    $cfg = vlab_config();
    $tpl = db_one("SELECT * FROM k30_ti_vlab_templates WHERE id=? AND is_active=1", [$templateId]);
    if (!$tpl) return ['ok' => false, 'msg' => 'Nieprawidłowy szablon.'];

    $max = (int)($cfg['max_per_student'] ?? 3);
    if (vlab_student_count($studentId) >= $max) {
        return ['ok' => false, 'msg' => "Osiągnięto limit maszyn ({$max})."];
    }

    $label = vlab_sanitize_label($label) ?: 'lab';

    // Nazwa kontenera: inicjały kursanta - nazwa obrazu + data (ddmmrr).
    $cn        = $clientId ? db_one("SELECT name FROM k30_clients WHERE id=?", [$clientId]) : null;
    $initials  = vlab_initials((string)($cn['name'] ?? ''));
    $imageSlug = vlab_image_slug((string)$tpl['docker_image']);
    $base      = $initials . '-' . $imageSlug . date('dmy');
    $name      = $base;
    $n         = 1;
    // container_name jest UNIQUE — w razie kolizji dokładamy sufiks -2, -3, …
    while (db_one("SELECT id FROM k30_ti_vlab_containers WHERE container_name=?", [$name])) {
        $n++;
        $name = $base . '-' . $n;
    }

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

    // Konto na hoście, którego logowanie SSH wpuszcza kursanta wprost do kontenera.
    $container = db_one("SELECT * FROM k30_ti_vlab_containers WHERE id=?", [$id]);
    $hostUser = $hostPass = '';
    // Domyślnie wymuszaj zmianę hasła przy pierwszym logowaniu (chyba że admin wyłączył).
    $forceChange = !isset($cfg['force_pw_first_login']) || (int)$cfg['force_pw_first_login'] === 1;
    if ($container) {
        $hu = vlab_host_user_create($container, $forceChange);
        if ($hu['ok']) {
            $hostUser = $hu['user'];
            $hostPass = $hu['password'];
            db_update('k30_ti_vlab_containers', [
                'host_user'        => $hostUser,
                'force_pw_pending' => $forceChange ? 1 : 0,
            ], $id);
            $container['host_user'] = $hostUser;
        } else {
            // Kontener działa — nie przerywamy, ale sygnalizujemy problem z kontem hosta.
            vlab_log($id, $studentId, 'host_user', false, $hu['msg']);
        }
    }

    // Dane dostępowe do maszyny wysyłamy kursantowi mailem (hasło do panelu idzie SMS-em).
    if ($container) vlab_email_credentials($container, $hostUser, $hostPass, $forceChange && $hostUser !== '');

    $out = ['ok' => true, 'msg' => 'Maszyna utworzona.', 'id' => $id];
    if ($hostUser !== '') {
        // Pełny zestaw danych logowania — front pokazuje go JEDEN raz po utworzeniu.
        $out['host_user']     = $hostUser;
        $out['host_password'] = $hostPass;
        $out['force_change']  = $forceChange;
        $out['creds'] = [
            'ssh_host'      => (string)($cfg['public_host'] ?? ''),
            'ssh_port'      => (int)($cfg['ssh_port'] ?: 22),
            'host_user'     => $hostUser,
            'host_password' => $hostPass,
            'ttyd_url'      => vlab_ttyd_url($container ?: []),
            'ttyd_user'     => (string)($container['ttyd_user'] ?? ''),
            'ttyd_password' => (string)($container['ttyd_password'] ?? ''),
            'force_change'  => $forceChange,
        ];
    } else {
        $out['msg'] = 'Maszyna utworzona, ale nie udało się założyć konta logowania na hoście — użyj terminala w przeglądarce lub zgłoś prowadzącemu.';
    }
    return $out;
}

/**
 * Wysyła kursantowi e-mail z danymi dostępowymi do kontenera (SSH + terminal ttyd).
 * Adres pobierany z k30_clients.email. Zwraca true gdy zlecono wysyłkę.
 */
function vlab_email_credentials(array $container, string $hostUser = '', string $hostPass = '', bool $forceChange = false): bool {
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
    // Logowanie SSH przez konto hosta (wpuszcza wprost do kontenera).
    if ($hostUser !== '' && $host !== '') {
        $port   = (int)($cfg['ssh_port'] ?: 22);
        $sshCmd = 'ssh ' . htmlspecialchars($hostUser, ENT_QUOTES) . '@' . $host . ' -p ' . $port;
        $rows .= "<tr><td style='padding:4px 12px;color:#555'>Połączenie SSH</td><td style='padding:4px 12px'><code>{$sshCmd}</code></td></tr>";
        if ($hostPass !== '') {
            $rows .= "<tr><td style='padding:4px 12px;color:#555'>Hasło SSH</td><td style='padding:4px 12px'><code>" . htmlspecialchars($hostPass, ENT_QUOTES) . "</code></td></tr>";
        }
        if ($forceChange) {
            $rows .= "<tr><td style='padding:4px 12px;color:#555'>Uwaga</td><td style='padding:4px 12px'>Przy pierwszym logowaniu SSH system poprosi o ustawienie własnego hasła.</td></tr>";
        }
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
    if (vlab_is_disabled()) return ['ok' => false, 'msg' => vlab_disabled_notice()];
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
        // Skasuj powiązane konto systemowe na hoście (best-effort).
        if (!empty($row['host_user'])) vlab_host_user_remove($row);
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
