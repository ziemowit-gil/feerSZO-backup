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

/** Lista zablokowanego oprogramowania (jedna pozycja na wiersz, ustawiana przez admina). */
function vlab_blocked_software(): array {
    $raw = (string)(vlab_config()['blocked_software'] ?? '');
    $lines = array_map('trim', explode("\n", $raw));
    return array_values(array_filter($lines, fn($l) => $l !== ''));
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
 * Parsuje listę portów kontenera podaną przez użytkownika ("80, 443/tcp, 8080").
 * Zwraca unikalną listę ['port'=>int,'proto'=>'tcp'|'udp'] (maks 12 pozycji),
 * z pominięciem portów zarządzanych 22 (sshd) i 7681 (ttyd).
 */
function vlab_parse_ports(string $spec): array {
    $out = []; $seen = [];
    foreach (preg_split('/[\s,;]+/', trim($spec)) as $tok) {
        if ($tok === '') continue;
        $proto = 'tcp';
        if (preg_match('#^(\d{1,5})(?:/(tcp|udp))?$#i', $tok, $m)) {
            $port = (int)$m[1];
            if (!empty($m[2])) $proto = strtolower($m[2]);
            if ($port < 1 || $port > 65535) continue;
            if (in_array($port, [22, 7681], true)) continue; // zarządzane przez VLAB
            $key = $port . '/' . $proto;
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $out[] = ['port' => $port, 'proto' => $proto];
            if (count($out) >= 12) break;
        }
    }
    return $out;
}

/**
 * Provisioning nowego kontenera dla kursanta.
 * $ports — opcjonalna lista portów kontenera do wystawienia (np. „80,443"); SSH/terminal
 * są wystawiane zawsze przez obraz. Zwraca ['ok'=>bool,'msg'=>string,'id'=>?int].
 */
function vlab_provision(int $studentId, int $clientId, int $templateId, string $label, string $ports = ''): array {
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

    // Budowa polecenia docker run. `-P` publikuje porty z EXPOSE obrazu (sshd 22 / ttyd 7681),
    // a wybrane przez użytkownika porty publikujemy jawnie przez `-p <port>/<proto>` (losowy port hosta).
    $argv = ['docker', 'run', '-d', '--name', $name, '-P'];
    $extraPorts = vlab_parse_ports($ports !== '' ? $ports : (string)($tpl['default_ports'] ?? ''));
    foreach ($extraPorts as $p) { $argv[] = '-p'; $argv[] = $p['port'] . '/' . $p['proto']; }
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
        // Zamknij i wyczyść wszystkie otwarte porty (UFW + Azure NSG) — best-effort.
        foreach (vlab_ports_list($containerId) as $p) { try { vlab_port_close((int)$p['id']); } catch (\Throwable $e) {} }
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

// ─────────────────────────────────────────────────────────────────────────────
//  Zarządzanie portami: zapora hosta (UFW przez SSH) + reguły NSG w Microsoft Azure
// ─────────────────────────────────────────────────────────────────────────────

/** Normalizuje protokół do 'tcp'|'udp'. */
function vlab_proto(string $p): string {
    $p = strtolower(trim($p));
    return $p === 'udp' ? 'udp' : 'tcp';
}

/** Wszystkie mapowania portów kontenera: [['cport'=>'80/tcp','host'=>49153], ...]. */
function vlab_docker_ports_all(string $name): array {
    $r = vlab_ssh_exec(['docker', 'port', $name]);
    if (!$r['ok'] || trim($r['out']) === '') return [];
    $out = [];
    foreach (preg_split('/\r?\n/', trim($r['out'])) as $line) {
        // format: "22/tcp -> 0.0.0.0:49153"
        if (preg_match('#^(\d+/\w+)\s*->\s*.*?:(\d+)$#', trim($line), $m)) {
            $out[] = ['cport' => $m[1], 'host' => (int)$m[2]];
        }
    }
    return $out;
}

// ── UFW (zapora hosta) ───────────────────────────────────────────────────────

/** Czy sterowanie UFW jest włączone w konfiguracji. */
function vlab_ufw_enabled(): bool {
    $c = vlab_config();
    return !isset($c['ufw_enabled']) || (int)$c['ufw_enabled'] === 1;
}

/** Otwiera/zamyka port w UFW (allow / delete allow). Zwraca ['ok','msg']. */
function vlab_ufw_set(int $port, string $proto, bool $allow): array {
    if (!vlab_ufw_enabled()) return ['ok' => false, 'msg' => 'Sterowanie UFW wyłączone w konfiguracji.'];
    if ($port < 1 || $port > 65535) return ['ok' => false, 'msg' => 'Nieprawidłowy numer portu.'];
    $proto = vlab_proto($proto);
    $rule  = $port . '/' . $proto;
    // ufw musi istnieć; brak ufw → czytelny błąd
    $script = $allow
        ? "command -v ufw >/dev/null 2>&1 || { echo 'ufw-missing'; exit 1; }; ufw allow {$rule}"
        : "command -v ufw >/dev/null 2>&1 || { echo 'ufw-missing'; exit 1; }; ufw delete allow {$rule} 2>/dev/null || true";
    $r = vlab_ssh_root($script);
    if (!$r['ok'] && strpos($r['out'] . $r['err'], 'ufw-missing') !== false) {
        return ['ok' => false, 'msg' => 'Na hoście nie znaleziono UFW.'];
    }
    return ['ok' => $r['ok'], 'msg' => $r['ok'] ? 'OK' : ($r['err'] ?: 'Błąd UFW.')];
}

/** Surowy `ufw status` (best-effort, do podglądu). */
function vlab_ufw_status(): string {
    $r = vlab_ssh_root('command -v ufw >/dev/null 2>&1 && ufw status || echo "UFW niedostępne"');
    return trim($r['out'] !== '' ? $r['out'] : $r['err']);
}

// ── Microsoft Azure (Network Security Group, ARM REST) ───────────────────────

/** Czy integracja z Azure NSG jest skonfigurowana i włączona. */
function vlab_azure_enabled(): bool {
    $c = vlab_config();
    return !empty($c['az_enabled']) && !empty($c['az_tenant']) && !empty($c['az_client_id'])
        && !empty($c['az_client_secret']) && !empty($c['az_subscription'])
        && !empty($c['az_resource_group']) && !empty($c['az_nsg']);
}

/** Lekki klient HTTP (JSON). Zwraca ['ok','code','body'(array|string),'err']. */
function vlab_http(string $method, string $url, array $headers = [], $body = null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : http_build_query($body));
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    if ($resp === false) return ['ok' => false, 'code' => 0, 'body' => '', 'err' => $err ?: 'Błąd połączenia.'];
    $json = json_decode($resp, true);
    return ['ok' => $code >= 200 && $code < 300, 'code' => $code, 'body' => $json ?? $resp, 'err' => ''];
}

/** Token ARM (client credentials, scope management.azure.com). Zwraca ['ok','token'|'err']. */
function vlab_azure_token(): array {
    $c = vlab_config();
    $r = vlab_http(
        'POST',
        "https://login.microsoftonline.com/{$c['az_tenant']}/oauth2/v2.0/token",
        ['Content-Type: application/x-www-form-urlencoded'],
        [
            'grant_type'    => 'client_credentials',
            'client_id'     => $c['az_client_id'],
            'client_secret' => $c['az_client_secret'],
            'scope'         => 'https://management.azure.com/.default',
        ]
    );
    if (!$r['ok'] || empty($r['body']['access_token'])) {
        $msg = is_array($r['body']) ? ($r['body']['error_description'] ?? json_encode($r['body'])) : (string)$r['body'];
        return ['ok' => false, 'err' => 'Azure: nie udało się pobrać tokenu — ' . ($r['err'] ?: $msg)];
    }
    return ['ok' => true, 'token' => $r['body']['access_token']];
}

/** Bazowy URL reguły NSG. */
function vlab_azure_rule_url(string $ruleName): string {
    $c = vlab_config();
    return "https://management.azure.com/subscriptions/{$c['az_subscription']}"
        . "/resourceGroups/{$c['az_resource_group']}"
        . "/providers/Microsoft.Network/networkSecurityGroups/{$c['az_nsg']}"
        . "/securityRules/" . rawurlencode($ruleName) . "?api-version=2023-09-01";
}

/** Tworzy/aktualizuje regułę Allow Inbound w NSG. Zwraca ['ok','msg']. */
function vlab_azure_rule_put(string $ruleName, int $port, string $proto, int $priority): array {
    $t = vlab_azure_token();
    if (!$t['ok']) return ['ok' => false, 'msg' => $t['err']];
    $payload = json_encode(['properties' => [
        'protocol'                 => vlab_proto($proto) === 'udp' ? 'Udp' : 'Tcp',
        'sourcePortRange'          => '*',
        'destinationPortRange'     => (string)$port,
        'sourceAddressPrefix'      => '*',
        'destinationAddressPrefix' => '*',
        'access'                   => 'Allow',
        'direction'                => 'Inbound',
        'priority'                 => $priority,
        'description'              => 'VLab port ' . $port . '/' . vlab_proto($proto),
    ]]);
    $r = vlab_http('PUT', vlab_azure_rule_url($ruleName),
        ['Authorization: Bearer ' . $t['token'], 'Content-Type: application/json'], $payload);
    if ($r['ok']) return ['ok' => true, 'msg' => 'OK'];
    $msg = is_array($r['body']) ? ($r['body']['error']['message'] ?? json_encode($r['body'])) : (string)$r['body'];
    return ['ok' => false, 'msg' => 'Azure NSG (HTTP ' . $r['code'] . '): ' . $msg];
}

/** Usuwa regułę NSG. Zwraca ['ok','msg']. */
function vlab_azure_rule_delete(string $ruleName): array {
    $t = vlab_azure_token();
    if (!$t['ok']) return ['ok' => false, 'msg' => $t['err']];
    $r = vlab_http('DELETE', vlab_azure_rule_url($ruleName), ['Authorization: Bearer ' . $t['token']]);
    // 200/202 = usunięto, 204 = nie istniało — traktujemy jako sukces
    if ($r['ok'] || $r['code'] === 404) return ['ok' => true, 'msg' => 'OK'];
    $msg = is_array($r['body']) ? ($r['body']['error']['message'] ?? json_encode($r['body'])) : (string)$r['body'];
    return ['ok' => false, 'msg' => 'Azure NSG (HTTP ' . $r['code'] . '): ' . $msg];
}

// ── Orkiestracja + rejestr ───────────────────────────────────────────────────

/** Lista otwartych portów kontenera (z rejestru). */
function vlab_ports_list(int $containerId): array {
    return db_all("SELECT * FROM k30_ti_vlab_ports WHERE container_id=? ORDER BY host_port", [$containerId]);
}

/** Wybiera wolny priorytet reguły NSG (100–4096) nieużywany w rejestrze. */
function vlab_azure_next_priority(): int {
    $rows = db_all("SELECT az_priority FROM k30_ti_vlab_ports WHERE az_priority IS NOT NULL");
    $used = array_map(fn($r) => (int)$r['az_priority'], $rows);
    for ($p = 2000; $p <= 4000; $p++) {
        if (!in_array($p, $used, true)) return $p;
    }
    return 4096;
}

/**
 * Otwiera port dla kontenera: UFW (zapora hosta) + Azure NSG (jeśli włączone).
 * Best-effort: każdy kanał raportowany osobno; wpis trafia do rejestru.
 * Zwraca ['ok'=>bool,'msg'=>string,'ufw'=>bool,'azure'=>bool].
 */
function vlab_port_open(int $containerId, int $hostPort, string $proto, ?int $byUserId = null, string $note = ''): array {
    $proto = vlab_proto($proto);
    if ($hostPort < 1 || $hostPort > 65535) return ['ok' => false, 'msg' => 'Nieprawidłowy numer portu.'];
    $cont = db_one("SELECT * FROM k30_ti_vlab_containers WHERE id=? AND status!='removed'", [$containerId]);
    if (!$cont) return ['ok' => false, 'msg' => 'Maszyna nie istnieje.'];

    $msgs = [];
    // UFW
    $ufwOk = false;
    if (vlab_ufw_enabled()) {
        $u = vlab_ufw_set($hostPort, $proto, true);
        $ufwOk = $u['ok'];
        $msgs[] = 'UFW: ' . ($u['ok'] ? 'otwarty' : $u['msg']);
    } else {
        $msgs[] = 'UFW: pominięty (wyłączony)';
    }

    // Azure NSG
    $azOk = false; $rule = ''; $priority = null;
    if (vlab_azure_enabled()) {
        $rule     = 'vlab-' . $containerId . '-' . $hostPort . '-' . $proto;
        // zachowaj istniejący priorytet, jeśli port był już w rejestrze
        $existing = db_one("SELECT az_priority FROM k30_ti_vlab_ports WHERE container_id=? AND host_port=? AND proto=?", [$containerId, $hostPort, $proto]);
        $priority = $existing && $existing['az_priority'] ? (int)$existing['az_priority'] : vlab_azure_next_priority();
        $a = vlab_azure_rule_put($rule, $hostPort, $proto, $priority);
        $azOk = $a['ok'];
        $msgs[] = 'Azure: ' . ($a['ok'] ? 'reguła dodana (prio ' . $priority . ')' : $a['msg']);
    } else {
        $msgs[] = 'Azure: pominięty (niewłączony)';
    }

    // Rejestr (upsert)
    $existing = db_one("SELECT id FROM k30_ti_vlab_ports WHERE container_id=? AND host_port=? AND proto=?", [$containerId, $hostPort, $proto]);
    $data = [
        'container_id' => $containerId, 'host_port' => $hostPort, 'proto' => $proto,
        'ufw_ok' => $ufwOk ? 1 : 0, 'az_ok' => $azOk ? 1 : 0,
        'az_rule' => $rule, 'az_priority' => $priority, 'note' => mb_substr($note, 0, 200),
    ];
    if ($existing) {
        db_update('k30_ti_vlab_ports', $data, (int)$existing['id']);
    } else {
        $data['created_by'] = $byUserId ?: null;
        $data['created_at'] = date('Y-m-d H:i:s');
        db_insert('k30_ti_vlab_ports', $data);
    }
    vlab_log($containerId, (int)$cont['student_id'], 'port_open', $ufwOk || $azOk, $hostPort . '/' . $proto . ' — ' . implode('; ', $msgs));

    $ok = $ufwOk || $azOk || (!vlab_ufw_enabled() && !vlab_azure_enabled());
    return ['ok' => $ok, 'msg' => implode(' · ', $msgs), 'ufw' => $ufwOk, 'azure' => $azOk];
}

/** Zamyka port (UFW delete + usunięcie reguły NSG) i kasuje wpis z rejestru. */
function vlab_port_close(int $portRowId): array {
    $row = db_one("SELECT * FROM k30_ti_vlab_ports WHERE id=?", [$portRowId]);
    if (!$row) return ['ok' => false, 'msg' => 'Wpis nie istnieje.'];
    $msgs = [];
    if (vlab_ufw_enabled()) {
        $u = vlab_ufw_set((int)$row['host_port'], $row['proto'], false);
        $msgs[] = 'UFW: ' . ($u['ok'] ? 'zamknięty' : $u['msg']);
    }
    if (vlab_azure_enabled() && $row['az_rule'] !== '') {
        $a = vlab_azure_rule_delete($row['az_rule']);
        $msgs[] = 'Azure: ' . ($a['ok'] ? 'reguła usunięta' : $a['msg']);
    }
    db()->prepare("DELETE FROM k30_ti_vlab_ports WHERE id=?")->execute([$portRowId]);
    vlab_log((int)$row['container_id'], 0, 'port_close', true, $row['host_port'] . '/' . $row['proto'] . ' — ' . implode('; ', $msgs));
    return ['ok' => true, 'msg' => implode(' · ', $msgs) ?: 'Port zamknięty.'];
}

// ── Samoobsługa kursanta (porty własnej maszyny) ─────────────────────────────

/** Czy kursanci mogą sami zarządzać portami swoich maszyn (ustawienie admina). */
function vlab_student_can_ports(): bool {
    $c = vlab_config();
    return !isset($c['ports_self_service']) || (int)$c['ports_self_service'] === 1;
}

/**
 * Dane portów dla kursanta: mapowania kontenera (docker port) + aktualnie otwarte porty.
 * Weryfikuje właściciela. Zwraca ['ok','mappings','open'] lub ['ok'=>false,'msg'].
 */
function vlab_student_ports_data(int $containerId, int $studentId): array {
    $cont = db_one("SELECT * FROM k30_ti_vlab_containers WHERE id=? AND student_id=? AND status!='removed'", [$containerId, $studentId]);
    if (!$cont) return ['ok' => false, 'msg' => 'Maszyna nie istnieje.'];
    $maps = $cont['status'] === 'running' ? vlab_docker_ports_all($cont['container_name']) : [];

    // Wnioski kursanta: oczekujące + ostatnie 5 zamkniętych/odrzuconych
    $requests = db_all(
        "SELECT id, action, host_port, proto, note, status, reject_reason, created_at, approved_at
         FROM k30_vlab_port_requests
         WHERE container_id=? AND requested_by_student=?
         ORDER BY created_at DESC LIMIT 20",
        [$containerId, $studentId]
    );

    return ['ok' => true, 'mappings' => $maps, 'open' => vlab_ports_list($containerId), 'requests' => $requests];
}

/** Składa wniosek o otwarcie portu maszyny kursanta (wymaga zatwierdzenia przez admina). */
function vlab_port_open_student(int $containerId, int $studentId, int $hostPort, string $proto): array {
    if (vlab_is_disabled())        return ['ok' => false, 'msg' => vlab_disabled_notice()];
    if (!vlab_student_can_ports()) return ['ok' => false, 'msg' => 'Zarządzanie portami zostało wyłączone przez administratora.'];
    return vlab_port_request_open($containerId, $hostPort, $proto, 'kursant', null, $studentId);
}

/** Składa wniosek o zamknięcie portu maszyny kursanta (wymaga zatwierdzenia przez admina). */
function vlab_port_close_student(int $portRowId, int $studentId): array {
    if (!vlab_student_can_ports()) return ['ok' => false, 'msg' => 'Zarządzanie portami zostało wyłączone przez administratora.'];
    return vlab_port_request_close($portRowId, 'kursant', null, $studentId);
}

// ── Wnioski o otwarcie/zamknięcie portów (wymagają zatwierdzenia przez admina) ─

/**
 * Złóż wniosek o otwarcie portu (kursant lub k30 staff).
 * @param int|null $byUserId     ID użytkownika systemowego (staff) lub null
 * @param int|null $byStudentId  ID konta kursanta lub null
 */
function vlab_port_request_open(int $containerId, int $hostPort, string $proto, string $note = '',
                                 ?int $byUserId = null, ?int $byStudentId = null): array {
    $proto = vlab_proto($proto);
    if ($hostPort < 1 || $hostPort > 65535) return ['ok' => false, 'msg' => 'Nieprawidłowy numer portu.'];
    $cont = db_one("SELECT * FROM k30_ti_vlab_containers WHERE id=? AND status!='removed'", [$containerId]);
    if (!$cont) return ['ok' => false, 'msg' => 'Maszyna nie istnieje.'];

    // Kursant może zgłaszać tylko wnioski dla swojej maszyny
    if ($byStudentId !== null) {
        if ((int)$cont['student_id'] !== $byStudentId) return ['ok' => false, 'msg' => 'Brak dostępu.'];
    }

    // Nie duplikuj wniosku oczekującego
    $dup = db_one(
        "SELECT id FROM k30_vlab_port_requests WHERE container_id=? AND host_port=? AND proto=? AND action='open' AND status='pending'",
        [$containerId, $hostPort, $proto]
    );
    if ($dup) return ['ok' => false, 'msg' => 'Wniosek o ten port jest już oczekujący.'];

    db_insert('k30_vlab_port_requests', [
        'container_id'        => $containerId,
        'action'              => 'open',
        'host_port'           => $hostPort,
        'proto'               => $proto,
        'note'                => mb_substr($note, 0, 200),
        'requested_by'        => $byUserId,
        'requested_by_student'=> $byStudentId,
        'status'              => 'pending',
    ]);
    return ['ok' => true, 'msg' => 'Wniosek złożony — oczekuje na zatwierdzenie przez administratora.'];
}

/**
 * Złóż wniosek o zamknięcie portu.
 */
function vlab_port_request_close(int $portRowId, string $note = '',
                                  ?int $byUserId = null, ?int $byStudentId = null): array {
    $row = db_one("SELECT p.*, c.student_id AS c_student FROM k30_ti_vlab_ports p
                   JOIN k30_ti_vlab_containers c ON c.id=p.container_id
                   WHERE p.id=?", [$portRowId]);
    if (!$row) return ['ok' => false, 'msg' => 'Port nie istnieje.'];

    if ($byStudentId !== null && (int)$row['c_student'] !== $byStudentId) {
        return ['ok' => false, 'msg' => 'Brak dostępu.'];
    }

    $dup = db_one(
        "SELECT id FROM k30_vlab_port_requests WHERE port_row_id=? AND action='close' AND status='pending'",
        [$portRowId]
    );
    if ($dup) return ['ok' => false, 'msg' => 'Wniosek o zamknięcie tego portu jest już oczekujący.'];

    db_insert('k30_vlab_port_requests', [
        'container_id'        => (int)$row['container_id'],
        'action'              => 'close',
        'host_port'           => (int)$row['host_port'],
        'proto'               => $row['proto'],
        'port_row_id'         => $portRowId,
        'note'                => mb_substr($note, 0, 200),
        'requested_by'        => $byUserId,
        'requested_by_student'=> $byStudentId,
        'status'              => 'pending',
    ]);
    return ['ok' => true, 'msg' => 'Wniosek złożony — oczekuje na zatwierdzenie przez administratora.'];
}

/** Lista wniosków dla kontenera (opcjonalnie filtr statusu). */
function vlab_port_requests_for(int $containerId, string $status = ''): array {
    $params = [$containerId];
    $where  = 'r.container_id=?';
    if ($status !== '') { $where .= ' AND r.status=?'; $params[] = $status; }
    return db_all(
        "SELECT r.*,
                COALESCE(u.name, sa.login) AS req_name,
                au.name AS approver_name
         FROM k30_vlab_port_requests r
         LEFT JOIN users u ON u.id=r.requested_by
         LEFT JOIN k30_ti_student_accounts sa ON sa.id=r.requested_by_student
         LEFT JOIN users au ON au.id=r.approved_by
         WHERE $where
         ORDER BY r.created_at DESC",
        $params
    );
}

/** Lista wszystkich wniosków pending (widok globalny dla admina). */
function vlab_port_requests_pending_all(): array {
    return db_all(
        "SELECT r.*,
                c.label AS cont_label, c.container_name,
                cl.name AS client_name,
                COALESCE(u.name, sa.login) AS req_name
         FROM k30_vlab_port_requests r
         JOIN k30_ti_vlab_containers c ON c.id=r.container_id
         LEFT JOIN k30_clients cl ON cl.id=c.client_id
         LEFT JOIN users u ON u.id=r.requested_by
         LEFT JOIN k30_ti_student_accounts sa ON sa.id=r.requested_by_student
         WHERE r.status='pending'
         ORDER BY r.created_at ASC"
    );
}

/** Admin zatwierdza wniosek — wykonuje faktyczną zmianę portu. */
function vlab_port_request_approve(int $requestId, int $adminId): array {
    $req = db_one("SELECT * FROM k30_vlab_port_requests WHERE id=? AND status='pending'", [$requestId]);
    if (!$req) return ['ok' => false, 'msg' => 'Wniosek nie istnieje lub nie jest oczekujący.'];

    if ($req['action'] === 'open') {
        $result = vlab_port_open((int)$req['container_id'], (int)$req['host_port'], $req['proto'], $adminId, $req['note']);
    } else {
        if (!$req['port_row_id']) return ['ok' => false, 'msg' => 'Brak port_row_id dla zamknięcia.'];
        $result = vlab_port_close((int)$req['port_row_id']);
    }

    db()->prepare(
        "UPDATE k30_vlab_port_requests SET status='approved', approved_by=?, approved_at=datetime('now') WHERE id=?"
    )->execute([$adminId, $requestId]);

    vlab_port_request_notify($req, 'approved');
    return $result;
}

/** Admin odrzuca wniosek. */
function vlab_port_request_reject(int $requestId, int $adminId, string $reason = ''): void {
    db()->prepare(
        "UPDATE k30_vlab_port_requests SET status='rejected', approved_by=?, approved_at=datetime('now'), reject_reason=? WHERE id=? AND status='pending'"
    )->execute([$adminId, mb_substr($reason, 0, 300), $requestId]);
    $req = db_one("SELECT * FROM k30_vlab_port_requests WHERE id=?", [$requestId]);
    if ($req) vlab_port_request_notify($req, 'rejected', $reason);
}

/**
 * Sprawdza rate limit wniosków portowych kursanta: max 5 w ciągu ostatniej godziny.
 * Zwraca ['ok'=>true] lub ['ok'=>false,'msg'=>string].
 */
function vlab_port_rate_check(int $studentId, int $maxPerHour = 5): array {
    $row = db_one(
        "SELECT COUNT(*) AS cnt FROM k30_vlab_port_requests
         WHERE requested_by_student=? AND created_at >= datetime('now','-1 hour')",
        [$studentId]
    );
    if ((int)($row['cnt'] ?? 0) >= $maxPerHour) {
        return ['ok' => false, 'msg' => 'Przekroczono limit wniosków (' . $maxPerHour . ' na godzinę). Spróbuj za chwilę.'];
    }
    return ['ok' => true];
}

/** Powiadomienie e-mail do kursanta o decyzji ws. wniosku portowego. */
function vlab_port_request_notify(array $req, string $decision, string $reason = ''): void {
    if (!function_exists('mail_queue_add')) { @require_once __DIR__ . '/mail_queue.php'; }
    if (!function_exists('mail_queue_add')) return;

    $cont = db_one("SELECT * FROM k30_ti_vlab_containers WHERE id=?", [(int)$req['container_id']]);
    if (!$cont) return;
    $client = $cont['client_id']
        ? db_one("SELECT name, email FROM k30_clients WHERE id=?", [(int)$cont['client_id']])
        : null;
    $email = trim((string)($client['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return;

    $org      = defined('ORG_NAME') ? ORG_NAME : 'VLab';
    $name     = htmlspecialchars($client['name'] ?? '', ENT_QUOTES);
    $contLbl  = htmlspecialchars($cont['label'] ?? $cont['container_name'] ?? '', ENT_QUOTES);
    $portDesc = htmlspecialchars($req['host_port'] . '/' . $req['proto'], ENT_QUOTES);
    $action   = $req['action'] === 'open' ? 'otwarcie portu' : 'zamknięcie portu';
    $panelUrl = rtrim(defined('APP_URL') ? APP_URL : '', '/') . '/karty30/ti/kursant/index.php?tab=vlab';

    if ($decision === 'approved') {
        $subject = "{$org}: wniosek o {$action} {$portDesc} — zatwierdzony";
        $decHtml = "<p style='color:#166534;background:#dcfce7;padding:10px 14px;border-radius:6px'>"
                 . "✅ Twój wniosek o <strong>{$action} {$portDesc}</strong> dla maszyny <strong>{$contLbl}</strong> został <strong>zatwierdzony</strong>.</p>";
    } else {
        $subject = "{$org}: wniosek o {$action} {$portDesc} — odrzucony";
        $reasonHtml = $reason !== '' ? "<p><strong>Powód:</strong> " . htmlspecialchars($reason, ENT_QUOTES) . "</p>" : '';
        $decHtml = "<p style='color:#991b1b;background:#fee2e2;padding:10px 14px;border-radius:6px'>"
                 . "❌ Twój wniosek o <strong>{$action} {$portDesc}</strong> dla maszyny <strong>{$contLbl}</strong> został <strong>odrzucony</strong>.</p>"
                 . $reasonHtml;
    }

    $html = "<p>Cześć {$name},</p>"
          . $decHtml
          . "<p><a href='{$panelUrl}'>Przejdź do panelu VLab</a></p>"
          . "<p style='color:#888;font-size:12px'>Wiadomość automatyczna — {$org}.</p>";

    try {
        mail_queue_add($email, $client['name'] ?? '', $subject, $html, '', 'vlab_port', (int)$cont['id'], '', false);
    } catch (\Throwable $e) {}
}

// ── Dedykowany adres IP (usługa płatna: aktywacja + abonament miesięczny) ────

/** Cennik i dostępność usługi (z konfiguracji admina). */
function vlab_dedicated_ip_pricing(): array {
    $c = vlab_config();
    return [
        'enabled'        => !empty($c['dedicated_ip_enabled']),
        'activation_fee' => (float)($c['dedicated_ip_activation_fee'] ?? 100),
        'monthly_fee'    => (float)($c['dedicated_ip_monthly_fee'] ?? 30),
    ];
}

/** Aktualne zamówienie (oczekujące lub aktywne) dla maszyny, albo null. */
function vlab_dedicated_ip_for_container(int $containerId): ?array {
    return db_one(
        "SELECT * FROM k30_ti_vlab_dedicated_ip WHERE container_id=? AND status IN ('requested','active') ORDER BY id DESC LIMIT 1",
        [$containerId]
    ) ?: null;
}

/** Cała historia zamówień dedykowanego IP dla maszyny (widok admina). */
function vlab_dedicated_ip_history_for(int $containerId): array {
    return db_all("SELECT * FROM k30_ti_vlab_dedicated_ip WHERE container_id=? ORDER BY id DESC", [$containerId]);
}

/** Wszystkie zamówienia oczekujące na przydzielenie IP (widok globalny admina). */
function vlab_dedicated_ip_pending_all(): array {
    return db_all(
        "SELECT d.*, c.label AS cont_label, c.container_name, cl.name AS client_name
         FROM k30_ti_vlab_dedicated_ip d
         JOIN k30_ti_vlab_containers c ON c.id=d.container_id
         LEFT JOIN k30_clients cl ON cl.id=d.client_id
         WHERE d.status='requested'
         ORDER BY d.requested_at ASC"
    );
}

/**
 * Kursant zamawia dedykowane IP dla swojej maszyny: dolicza jednorazową opłatę aktywacyjną
 * do rozliczenia (k30_ti_billing) i zapisuje zamówienie ze statusem 'requested' — realny
 * adres przydziela administrator ręcznie (vlab_dedicated_ip_activate).
 */
function vlab_dedicated_ip_request(int $containerId, int $studentId): array {
    $cont = db_one("SELECT * FROM k30_ti_vlab_containers WHERE id=? AND status!='removed'", [$containerId]);
    if (!$cont) return ['ok' => false, 'msg' => 'Maszyna nie istnieje.'];
    if ((int)$cont['student_id'] !== $studentId) return ['ok' => false, 'msg' => 'Brak dostępu.'];

    $pricing = vlab_dedicated_ip_pricing();
    if (!$pricing['enabled']) return ['ok' => false, 'msg' => 'Usługa dedykowanego IP jest obecnie niedostępna.'];

    $dup = db_one("SELECT id FROM k30_ti_vlab_dedicated_ip WHERE container_id=? AND status IN ('requested','active')", [$containerId]);
    if ($dup) return ['ok' => false, 'msg' => 'Dla tej maszyny już istnieje zamówienie lub aktywna usługa dedykowanego IP.'];

    $clientId = (int)$cont['client_id'];
    if (!$clientId) return ['ok' => false, 'msg' => 'Maszyna nie jest powiązana z kontem rozliczeniowym.'];

    if (!function_exists('ti_billing_add_charge')) require_once __DIR__ . '/ti_payments.php';
    $label    = $cont['label'] !== '' ? $cont['label'] : $cont['container_name'];
    $chargeId = ti_billing_add_charge(
        $clientId, $pricing['activation_fee'],
        'VLAB: aktywacja dedykowanego IP — maszyna „' . $label . '"'
    );

    $orderId = db_insert('k30_ti_vlab_dedicated_ip', [
        'container_id'         => $containerId,
        'student_id'           => $studentId,
        'client_id'            => $clientId,
        'status'               => 'requested',
        'activation_fee'       => $pricing['activation_fee'],
        'monthly_fee'          => $pricing['monthly_fee'],
        'activation_charge_id' => $chargeId,
    ]);

    $order = db_one("SELECT * FROM k30_ti_vlab_dedicated_ip WHERE id=?", [$orderId]);
    if ($order) vlab_dedicated_ip_notify($order, 'requested');

    return ['ok' => true, 'msg' => 'Zamówienie przyjęte. Opłata aktywacyjna '
        . number_format($pricing['activation_fee'], 2, ',', ' ') . ' zł została dodana do Twojego rozliczenia. '
        . 'Administrator przydzieli adres IP i powiadomi Cię e-mailem.'];
}

/** Admin przydziela realny adres IP i aktywuje usługę. */
function vlab_dedicated_ip_activate(int $orderId, int $adminId, string $ip): array {
    $ip = trim($ip);
    if ($ip === '' || !filter_var($ip, FILTER_VALIDATE_IP)) return ['ok' => false, 'msg' => 'Nieprawidłowy adres IP.'];

    $order = db_one("SELECT * FROM k30_ti_vlab_dedicated_ip WHERE id=? AND status='requested'", [$orderId]);
    if (!$order) return ['ok' => false, 'msg' => 'Zamówienie nie istnieje lub nie jest oczekujące.'];

    // Miesiąc aktywacji jest już pokryty opłatą aktywacyjną — cykl abonamentowy zaczyna się od kolejnego.
    db()->prepare(
        "UPDATE k30_ti_vlab_dedicated_ip
         SET status='active', ip_address=?, activated_at=datetime('now'), activated_by=?, last_billed_period=?
         WHERE id=?"
    )->execute([$ip, $adminId, date('Y-m'), $orderId]);

    $order['status'] = 'active';
    $order['ip_address'] = $ip;
    vlab_dedicated_ip_notify($order, 'activated');
    return ['ok' => true, 'msg' => 'Dedykowany adres IP aktywowany.'];
}

/** Admin anuluje zamówienie/usługę — zatrzymuje dalsze naliczanie abonamentu. */
function vlab_dedicated_ip_cancel(int $orderId, int $adminId, string $reason = ''): array {
    $order = db_one("SELECT * FROM k30_ti_vlab_dedicated_ip WHERE id=? AND status IN ('requested','active')", [$orderId]);
    if (!$order) return ['ok' => false, 'msg' => 'Zamówienie nie istnieje lub jest już zakończone.'];

    db()->prepare(
        "UPDATE k30_ti_vlab_dedicated_ip SET status='cancelled', cancelled_at=datetime('now'), cancelled_by=?, note=? WHERE id=?"
    )->execute([$adminId, mb_substr($reason, 0, 300), $orderId]);

    vlab_dedicated_ip_notify($order, 'cancelled', $reason);
    return ['ok' => true, 'msg' => 'Usługa dedykowanego IP anulowana.'];
}

/**
 * Cron: nalicza opłatę abonamentową za bieżący miesiąc każdej aktywnej usłudze, która
 * jeszcze nie została rozliczona w tym miesiącu (last_billed_period). Idempotentne —
 * bezpieczne do wielokrotnego wywołania w tym samym miesiącu.
 */
function vlab_dedicated_ip_bill_monthly(): array {
    if (!function_exists('ti_billing_add_charge')) require_once __DIR__ . '/ti_payments.php';
    $period = date('Y-m');
    $rows = db_all("SELECT * FROM k30_ti_vlab_dedicated_ip WHERE status='active' AND last_billed_period!=?", [$period]);

    $billed = 0;
    foreach ($rows as $r) {
        if ((float)$r['monthly_fee'] > 0) {
            $cont  = db_one("SELECT label, container_name FROM k30_ti_vlab_containers WHERE id=?", [(int)$r['container_id']]);
            $label = $cont ? ($cont['label'] !== '' ? $cont['label'] : $cont['container_name']) : '';
            ti_billing_add_charge(
                (int)$r['client_id'], (float)$r['monthly_fee'],
                'VLAB: opłata miesięczna za dedykowane IP — maszyna „' . $label . '" (' . $r['ip_address'] . ')'
            );
            $billed++;
        }
        db()->prepare("UPDATE k30_ti_vlab_dedicated_ip SET last_billed_period=? WHERE id=?")->execute([$period, (int)$r['id']]);
    }
    return ['ok' => true, 'billed' => $billed, 'checked' => count($rows), 'period' => $period];
}

/** Powiadomienie e-mail do kursanta o zdarzeniu w cyklu życia zamówienia dedykowanego IP. */
function vlab_dedicated_ip_notify(array $order, string $event, string $reason = ''): void {
    if (!function_exists('mail_queue_add')) { @require_once __DIR__ . '/mail_queue.php'; }
    if (!function_exists('mail_queue_add')) return;

    $client = db_one("SELECT name, email FROM k30_clients WHERE id=?", [(int)$order['client_id']]);
    $email  = trim((string)($client['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return;

    $cont     = db_one("SELECT label, container_name FROM k30_ti_vlab_containers WHERE id=?", [(int)$order['container_id']]);
    $contLbl  = htmlspecialchars($cont['label'] ?? $cont['container_name'] ?? '', ENT_QUOTES);
    $org      = defined('ORG_NAME') ? ORG_NAME : 'VLab';
    $name     = htmlspecialchars($client['name'] ?? '', ENT_QUOTES);
    $panelUrl = rtrim(defined('APP_URL') ? APP_URL : '', '/') . '/karty30/ti/kursant/index.php?tab=vlab';

    if ($event === 'requested') {
        $subject = "{$org}: zamówienie dedykowanego IP przyjęte";
        $body = "<p>Przyjęliśmy zamówienie dedykowanego adresu IP dla maszyny <strong>{$contLbl}</strong>.</p>"
              . "<p>Opłata aktywacyjna " . number_format((float)$order['activation_fee'], 2, ',', ' ') . " zł została dodana do rozliczenia. "
              . "Po przydzieleniu adresu przez administratora otrzymasz kolejny e-mail.</p>";
    } elseif ($event === 'activated') {
        $subject = "{$org}: dedykowane IP aktywne";
        $body = "<p style='color:#166534;background:#dcfce7;padding:10px 14px;border-radius:6px'>"
              . "✅ Dedykowany adres IP dla maszyny <strong>{$contLbl}</strong> jest aktywny: <strong>" . htmlspecialchars($order['ip_address'] ?? '', ENT_QUOTES) . "</strong></p>"
              . "<p>Opłata abonamentowa: " . number_format((float)$order['monthly_fee'], 2, ',', ' ') . " zł / miesiąc.</p>";
    } else {
        $subject = "{$org}: usługa dedykowanego IP zakończona";
        $reasonHtml = $reason !== '' ? "<p><strong>Powód:</strong> " . htmlspecialchars($reason, ENT_QUOTES) . "</p>" : '';
        $body = "<p>Usługa dedykowanego adresu IP dla maszyny <strong>{$contLbl}</strong> została zakończona.</p>{$reasonHtml}";
    }

    $html = "<p>Cześć {$name},</p>{$body}<p><a href='{$panelUrl}'>Przejdź do panelu VLab</a></p>"
          . "<p style='color:#888;font-size:12px'>Wiadomość automatyczna — {$org}.</p>";

    try {
        mail_queue_add($email, $client['name'] ?? '', $subject, $html, '', 'vlab_dedicated_ip', (int)$order['id'], '', false);
    } catch (\Throwable $e) {}
}

// ── Dedykowany serwer u zewnętrznego partnera (xxx.edukacja.cloud) ───────────

/** Cennik i dostępność usługi (z konfiguracji admina). */
function vlab_dedicated_server_pricing(): array {
    $c = vlab_config();
    return [
        'enabled' => !empty($c['dedicated_server_enabled']),
        'domain'  => $c['dedicated_server_domain'] !== '' ? $c['dedicated_server_domain'] : 'edukacja.cloud',
        'specs'   => $c['dedicated_server_specs'] !== '' ? $c['dedicated_server_specs'] : '8 GB RAM / 50 GB SSD',
        'periods' => [
            'monthly' => [
                'label'         => 'Miesięczny',
                'price'         => (float)($c['dedicated_server_monthly_price'] ?? 0),
                'regular_price' => (float)($c['dedicated_server_monthly_regular_price'] ?? 0),
            ],
            'annual' => [
                'label'         => 'Roczny',
                'price'         => (float)($c['dedicated_server_annual_price'] ?? 0),
                'regular_price' => (float)($c['dedicated_server_annual_regular_price'] ?? 0),
            ],
        ],
    ];
}

/** Aktualne (nieanulowane) zamówienie kursanta, jeśli istnieje. */
function vlab_dedicated_server_for_student(int $studentId): ?array {
    return db_one(
        "SELECT * FROM k30_ti_vlab_dedicated_server WHERE student_id=? AND status!='cancelled' ORDER BY id DESC LIMIT 1",
        [$studentId]
    ) ?: null;
}

/** Cała historia zamówień kursanta (widok admina). */
function vlab_dedicated_server_history_for(int $studentId): array {
    return db_all("SELECT * FROM k30_ti_vlab_dedicated_server WHERE student_id=? ORDER BY id DESC", [$studentId]);
}

/** Zamówienia wymagające akcji admina: 'requested' czeka na opłatę, 'paid' czeka na realizację u partnera. */
function vlab_dedicated_server_pending_all(): array {
    return db_all(
        "SELECT d.*, cl.name AS client_name, a.login AS student_login
         FROM k30_ti_vlab_dedicated_server d
         LEFT JOIN k30_clients cl ON cl.id=d.client_id
         LEFT JOIN k30_ti_student_accounts a ON a.id=d.student_id
         WHERE d.status IN ('requested','paid')
         ORDER BY d.requested_at ASC"
    );
}

/** Waliduje prefiks nazwy hosta: etykieta DNS 2–32 znaki, litery/cyfry/myślnik, bez myślnika na krawędziach. */
function vlab_dedicated_server_valid_prefix(string $prefix): bool {
    return (bool)preg_match('/^[a-z0-9]([a-z0-9-]{0,30}[a-z0-9])?$/', $prefix);
}

/**
 * Kursant zamawia dedykowany serwer: nalicza opłatę za pierwszy okres do rozliczenia (k30_ti_billing —
 * fundacja wystawia i podpina fakturę przez istniejący mechanizm faktur w Rozliczeniach) i zapisuje
 * zamówienie ze statusem 'requested'. Realizację u zewnętrznego partnera wykonuje admin ręcznie po
 * potwierdzeniu wpłaty (vlab_dedicated_server_mark_paid → vlab_dedicated_server_activate).
 */
function vlab_dedicated_server_request(int $studentId, string $hostnamePrefix, string $username, string $period): array {
    $hostnamePrefix = strtolower(trim($hostnamePrefix));
    $username       = trim($username);
    $period         = $period === 'annual' ? 'annual' : 'monthly';

    if (!vlab_dedicated_server_valid_prefix($hostnamePrefix)) {
        return ['ok' => false, 'msg' => 'Nazwa serwera może zawierać tylko małe litery, cyfry i myślniki (2–32 znaki), bez myślnika na początku/końcu.'];
    }
    if ($username === '' || mb_strlen($username) > 64) {
        return ['ok' => false, 'msg' => 'Podaj nazwę użytkownika (max 64 znaki).'];
    }

    $pricing = vlab_dedicated_server_pricing();
    if (!$pricing['enabled']) return ['ok' => false, 'msg' => 'Zamawianie dedykowanych serwerów jest obecnie niedostępne.'];

    $student = db_one("SELECT * FROM k30_ti_student_accounts WHERE id=? AND is_active=1", [$studentId]);
    if (!$student) return ['ok' => false, 'msg' => 'Konto kursanta nie istnieje.'];
    $clientId = (int)$student['client_id'];
    if (!$clientId) return ['ok' => false, 'msg' => 'Konto nie jest powiązane z kontem rozliczeniowym.'];

    $dup = db_one("SELECT id FROM k30_ti_vlab_dedicated_server WHERE student_id=? AND status!='cancelled'", [$studentId]);
    if ($dup) return ['ok' => false, 'msg' => 'Masz już zamówienie lub aktywny dedykowany serwer.'];

    $taken = db_one("SELECT id FROM k30_ti_vlab_dedicated_server WHERE hostname_prefix=? AND status!='cancelled'", [$hostnamePrefix]);
    if ($taken) return ['ok' => false, 'msg' => 'Ta nazwa serwera jest już zajęta — wybierz inną.'];

    $plan = $pricing['periods'][$period];
    if ((float)$plan['price'] <= 0) return ['ok' => false, 'msg' => 'Cennik dla tego okresu nie jest jeszcze skonfigurowany — skontaktuj się z administratorem.'];

    if (!function_exists('ti_billing_add_charge')) require_once __DIR__ . '/ti_payments.php';
    $chargeId = ti_billing_add_charge(
        $clientId, (float)$plan['price'],
        'Dedykowany serwer ' . $hostnamePrefix . '.' . $pricing['domain'] . ' — okres ' . $plan['label']
    );

    $orderId = db_insert('k30_ti_vlab_dedicated_server', [
        'student_id'        => $studentId,
        'client_id'         => $clientId,
        'hostname_prefix'   => $hostnamePrefix,
        'server_username'   => $username,
        'billing_period'    => $period,
        'price'             => $plan['price'],
        'regular_price'     => $plan['regular_price'],
        'status'            => 'requested',
        'billing_charge_id' => $chargeId,
    ]);

    $order = db_one("SELECT * FROM k30_ti_vlab_dedicated_server WHERE id=?", [$orderId]);
    if ($order) vlab_dedicated_server_notify($order, 'requested');

    return ['ok' => true, 'msg' => 'Zamówienie przyjęte. Opłata ' . number_format((float)$plan['price'], 2, ',', ' ')
        . ' zł za okres „' . $plan['label'] . '" została dodana do Twojego rozliczenia — fundacja wystawi fakturę. '
        . 'Po zaksięgowaniu wpłaty złożymy zamówienie u partnera i uruchomimy serwer.'];
}

/** Admin potwierdza otrzymanie wpłaty — kolejny krok: złożenie zamówienia u zewnętrznego partnera. */
function vlab_dedicated_server_mark_paid(int $orderId, int $adminId): array {
    $order = db_one("SELECT * FROM k30_ti_vlab_dedicated_server WHERE id=? AND status='requested'", [$orderId]);
    if (!$order) return ['ok' => false, 'msg' => 'Zamówienie nie istnieje lub nie czeka na opłatę.'];

    db()->prepare("UPDATE k30_ti_vlab_dedicated_server SET status='paid', paid_at=datetime('now'), paid_by=? WHERE id=?")
        ->execute([$adminId, $orderId]);

    $order['status'] = 'paid';
    vlab_dedicated_server_notify($order, 'paid');
    return ['ok' => true, 'msg' => 'Zamówienie oznaczone jako opłacone.'];
}

/** Admin aktywuje serwer po zrealizowaniu zamówienia u zewnętrznego partnera. */
function vlab_dedicated_server_activate(int $orderId, int $adminId, string $hostname = '', string $partnerRef = ''): array {
    $order = db_one("SELECT * FROM k30_ti_vlab_dedicated_server WHERE id=? AND status='paid'", [$orderId]);
    if (!$order) return ['ok' => false, 'msg' => 'Zamówienie nie istnieje lub nie jest opłacone.'];

    $pricing  = vlab_dedicated_server_pricing();
    $hostname = trim($hostname) !== '' ? trim($hostname) : ($order['hostname_prefix'] . '.' . $pricing['domain']);
    $next     = $order['billing_period'] === 'annual' ? date('Y-m-d', strtotime('+1 year')) : date('Y-m-d', strtotime('+1 month'));

    db()->prepare(
        "UPDATE k30_ti_vlab_dedicated_server
         SET status='active', server_hostname=?, partner_order_ref=?, activated_at=datetime('now'), activated_by=?, next_renewal_at=?
         WHERE id=?"
    )->execute([$hostname, mb_substr($partnerRef, 0, 200), $adminId, $next, $orderId]);

    $order['status']          = 'active';
    $order['server_hostname'] = $hostname;
    vlab_dedicated_server_notify($order, 'active');
    return ['ok' => true, 'msg' => 'Serwer aktywowany.'];
}

/** Admin anuluje zamówienie/usługę — zatrzymuje dalsze naliczanie odnowień. */
function vlab_dedicated_server_cancel(int $orderId, int $adminId, string $reason = ''): array {
    $order = db_one("SELECT * FROM k30_ti_vlab_dedicated_server WHERE id=? AND status IN ('requested','paid','active')", [$orderId]);
    if (!$order) return ['ok' => false, 'msg' => 'Zamówienie nie istnieje lub jest już zakończone.'];

    db()->prepare(
        "UPDATE k30_ti_vlab_dedicated_server SET status='cancelled', cancelled_at=datetime('now'), cancelled_by=?, note=? WHERE id=?"
    )->execute([$adminId, mb_substr($reason, 0, 300), $orderId]);

    vlab_dedicated_server_notify($order, 'cancelled', $reason);
    return ['ok' => true, 'msg' => 'Zamówienie/usługa dedykowanego serwera anulowana.'];
}

/**
 * Cron: nalicza opłatę za kolejny okres każdemu aktywnemu serwerowi, którego termin odnowienia minął,
 * i przesuwa termin o kolejny okres (miesiąc/rok). Bezpieczne do wielokrotnego uruchamiania w tym samym
 * dniu — po przesunięciu next_renewal_at wiersz nie kwalifikuje się już do ponownego naliczenia.
 */
function vlab_dedicated_server_bill_renewals(): array {
    if (!function_exists('ti_billing_add_charge')) require_once __DIR__ . '/ti_payments.php';
    $today = date('Y-m-d');
    $rows  = db_all(
        "SELECT * FROM k30_ti_vlab_dedicated_server WHERE status='active' AND next_renewal_at IS NOT NULL AND next_renewal_at<=?",
        [$today]
    );

    $pricing = vlab_dedicated_server_pricing();
    $billed  = 0;
    foreach ($rows as $r) {
        if ((float)$r['price'] > 0) {
            ti_billing_add_charge(
                (int)$r['client_id'], (float)$r['price'],
                'Dedykowany serwer ' . ($r['server_hostname'] ?: ($r['hostname_prefix'] . '.' . $pricing['domain'])) . ' — odnowienie (' . $r['billing_period'] . ')'
            );
            $billed++;
        }
        $next = $r['billing_period'] === 'annual'
            ? date('Y-m-d', strtotime($r['next_renewal_at'] . ' +1 year'))
            : date('Y-m-d', strtotime($r['next_renewal_at'] . ' +1 month'));
        db()->prepare("UPDATE k30_ti_vlab_dedicated_server SET next_renewal_at=? WHERE id=?")->execute([$next, (int)$r['id']]);
        vlab_dedicated_server_notify($r, 'renewed');
    }
    return ['ok' => true, 'billed' => $billed, 'checked' => count($rows), 'date' => $today];
}

/** Powiadomienie e-mail do kursanta o zdarzeniu w cyklu życia zamówienia dedykowanego serwera. */
function vlab_dedicated_server_notify(array $order, string $event, string $reason = ''): void {
    if (!function_exists('mail_queue_add')) { @require_once __DIR__ . '/mail_queue.php'; }
    if (!function_exists('mail_queue_add')) return;

    $client = db_one("SELECT name, email FROM k30_clients WHERE id=?", [(int)$order['client_id']]);
    $email  = trim((string)($client['email'] ?? ''));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return;

    $pricing  = vlab_dedicated_server_pricing();
    $hostFull = htmlspecialchars($order['server_hostname'] ?: ($order['hostname_prefix'] . '.' . $pricing['domain']), ENT_QUOTES);
    $org      = defined('ORG_NAME') ? ORG_NAME : 'VLab';
    $name     = htmlspecialchars($client['name'] ?? '', ENT_QUOTES);
    $panelUrl = rtrim(defined('APP_URL') ? APP_URL : '', '/') . '/karty30/ti/kursant/index.php?tab=vlab';

    if ($event === 'requested') {
        $subject = "{$org}: zamówienie dedykowanego serwera przyjęte";
        $body = "<p>Przyjęliśmy zamówienie dedykowanego serwera <strong>{$hostFull}</strong>.</p>"
              . "<p>Opłata " . number_format((float)$order['price'], 2, ',', ' ') . " zł została dodana do rozliczenia — otrzymasz fakturę. "
              . "Po zaksięgowaniu wpłaty złożymy zamówienie u partnera i uruchomimy serwer.</p>";
    } elseif ($event === 'paid') {
        $subject = "{$org}: wpłata za dedykowany serwer potwierdzona";
        $body = "<p>Potwierdziliśmy wpłatę za serwer <strong>{$hostFull}</strong>. Składamy zamówienie u partnera — o uruchomieniu poinformujemy kolejnym e-mailem.</p>";
    } elseif ($event === 'active') {
        $subject = "{$org}: dedykowany serwer aktywny";
        $body = "<p style='color:#166534;background:#dcfce7;padding:10px 14px;border-radius:6px'>"
              . "✅ Twój dedykowany serwer <strong>{$hostFull}</strong> jest aktywny.</p>"
              . "<p>Dane dostępowe (login: <strong>" . htmlspecialchars($order['server_username'] ?? '', ENT_QUOTES) . "</strong>) otrzymasz od naszego partnera hostingowego lub od administratora.</p>";
    } elseif ($event === 'renewed') {
        $subject = "{$org}: odnowienie dedykowanego serwera";
        $body = "<p>Naliczyliśmy opłatę za kolejny okres rozliczeniowy serwera <strong>{$hostFull}</strong>: "
              . number_format((float)$order['price'], 2, ',', ' ') . " zł.</p>";
    } else {
        $subject = "{$org}: zamówienie dedykowanego serwera zakończone";
        $reasonHtml = $reason !== '' ? "<p><strong>Powód:</strong> " . htmlspecialchars($reason, ENT_QUOTES) . "</p>" : '';
        $body = "<p>Zamówienie/usługa dedykowanego serwera <strong>{$hostFull}</strong> zostało zakończone.</p>{$reasonHtml}";
    }

    $html = "<p>Cześć {$name},</p>{$body}<p><a href='{$panelUrl}'>Przejdź do panelu VLab</a></p>"
          . "<p style='color:#888;font-size:12px'>Wiadomość automatyczna — {$org}.</p>";

    try {
        mail_queue_add($email, $client['name'] ?? '', $subject, $html, '', 'vlab_dedicated_server', (int)$order['id'], '', false);
    } catch (\Throwable $e) {}
}
