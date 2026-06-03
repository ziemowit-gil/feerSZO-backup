<?php
/**
 * Zaplanowane zadanie: synchronizacja wolontariuszy z FEER NGO → Moodle.
 *
 * Logika:
 *  1. Pobierz aktywnych wolontariuszy z FEER API.
 *  2. Dla każdego: znajdź lub utwórz konto Moodle.
 *  3. Zapisz na kursy skonfigurowane globalnie i wg mapowania Działanie→Kurs.
 *  4. Pobierz zakończonych wolontariuszy i zawieś ich konta (jeśli opcja włączona).
 *  5. Zapisz wynik do local_feer_sync_log.
 */

namespace local_feer_sync\task;

defined('MOODLE_INTERNAL') || die();

use local_feer_sync\api\feer_client;

class sync_task extends \core\task\scheduled_task {

    public function get_name(): string {
        return get_string('task_sync', 'local_feer_sync');
    }

    public function execute(): void {
        global $DB, $CFG;

        $trigger   = $this->is_manual() ? 'manual' : 'scheduled';
        $details   = [];
        $counters  = ['fetched' => 0, 'created' => 0, 'updated' => 0, 'suspended' => 0, 'errors' => 0];
        $status    = 'ok';
        $message   = '';

        try {
            $this->do_sync($counters, $details);
        } catch (\Throwable $e) {
            $status  = 'error';
            $message = $e->getMessage();
            mtrace('[feer_sync] ERROR: ' . $message);
        }

        // Zapisz log
        $DB->insert_record('local_feer_sync_log', (object)[
            'timecreated' => time(),
            'trigger'     => $trigger,
            'status'      => $status,
            'fetched'     => $counters['fetched'],
            'created'     => $counters['created'],
            'updated'     => $counters['updated'],
            'suspended'   => $counters['suspended'],
            'errors'      => $counters['errors'],
            'message'     => $message,
            'details'     => json_encode($details, JSON_UNESCAPED_UNICODE),
        ]);

        // Zapamiętaj czas ostatniej synchronizacji
        set_config('last_sync_at', date('Y-m-d H:i:s'), 'local_feer_sync');

        mtrace(sprintf(
            '[feer_sync] Zakończono: pobrano=%d, utworzono=%d, zaktualizowano=%d, zawieszono=%d, błędów=%d',
            $counters['fetched'], $counters['created'], $counters['updated'],
            $counters['suspended'], $counters['errors']
        ));
    }

    // ── Właściwa logika synchronizacji ───────────────────────────────────────

    private bool $_writeback_login = false;
    private ?feer_client $_client  = null;

    private function do_sync(array &$cnt, array &$details): void {
        global $DB;

        $client      = new feer_client();
        $this->_client = $client;
        $create_miss       = (bool)get_config('local_feer_sync', 'create_if_missing');
        $suspend_end       = (bool)get_config('local_feer_sync', 'suspend_on_end');
        $sync_standalone   = (bool)get_config('local_feer_sync', 'sync_standalone');
        $writeback_login   = (bool)get_config('local_feer_sync', 'writeback_login');
        $this->_writeback_login = $writeback_login;

        // Kursy auto-zapisu (globalne)
        $global_courses = $this->parse_course_ids(
            get_config('local_feer_sync', 'auto_enroll_courses') ?: ''
        );

        // Mapowanie Działanie → Kurs
        $action_map = $this->parse_action_map(
            get_config('local_feer_sync', 'action_course_map') ?: '{}'
        );

        // ── 1. Aktywni wolontariusze (+ standalone jeśli włączone) ───────────
        $active = $client->get_active_volunteers('', $sync_standalone);
        $cnt['fetched'] += count($active);

        foreach ($active as $vol) {
            try {
                $this->sync_one_volunteer($vol, $global_courses, $action_map, $create_miss, $cnt, $details);
            } catch (\Throwable $e) {
                $cnt['errors']++;
                $details[] = ['email' => $vol['email'], 'action' => 'sync', 'error' => $e->getMessage()];
                mtrace('[feer_sync] Błąd dla ' . $vol['email'] . ': ' . $e->getMessage());
            }
        }

        // ── 2. Zakończone umowy — zawieś konta ────────────────────────────────
        if ($suspend_end) {
            $ended       = $client->get_ended_volunteers();
            $cnt['fetched'] += count($ended);

            foreach ($ended as $vol) {
                try {
                    $this->maybe_suspend($vol, $cnt, $details);
                } catch (\Throwable $e) {
                    $cnt['errors']++;
                    $details[] = ['email' => $vol['email'], 'action' => 'suspend', 'error' => $e->getMessage()];
                }
            }
        }
    }

    private function sync_one_volunteer(
        array $vol,
        array $global_courses,
        array $action_map,
        bool  $create_miss,
        array &$cnt,
        array &$details
    ): void {
        global $DB;

        $email = strtolower(trim($vol['email'] ?? ''));
        if (!$email) return;

        // Sprawdź cache mapowania w naszej tabeli
        $mapping = $DB->get_record('local_feer_sync_users', ['email' => $email]);

        // Znajdź użytkownika Moodle
        $moodle_uid = $mapping->moodle_user_id ?? null;
        if (!$moodle_uid) {
            $moodle_uid = $this->find_moodle_user($email);
        }

        if (!$moodle_uid) {
            if (!$create_miss) {
                $details[] = ['email' => $email, 'action' => 'skip', 'reason' => 'brak konta Moodle'];
                return;
            }
            // Utwórz konto
            $moodle_uid = $this->create_moodle_user($vol);
            $cnt['created']++;
            $details[] = ['email' => $email, 'action' => 'created', 'moodle_id' => $moodle_uid];

            // Writeback loginu do FEER (dla standalone volunteers)
            if ($this->_writeback_login && $this->_client && !empty($vol['is_standalone'])) {
                try {
                    global $DB;
                    $mu = $DB->get_record('user', ['id' => $moodle_uid], 'username');
                    if ($mu && $mu->username) {
                        $this->_client->writeback_moodle_user($email, $mu->username, $moodle_uid);
                    }
                } catch (\Throwable $wb) {
                    mtrace('[feer_sync] writeback failed for ' . $email . ': ' . $wb->getMessage());
                }
            }
        } else {
            $cnt['updated']++;
        }

        // Zaktualizuj cache
        $now = time();
        if ($mapping) {
            $DB->update_record('local_feer_sync_users', (object)[
                'id'           => $mapping->id,
                'moodle_user_id'=> $moodle_uid,
                'feer_id'      => $vol['id'],
                'feer_status'  => $vol['status'],
                'action_id'    => $vol['action_id'] ?? null,
                'synced_at'    => $now,
            ]);
        } else {
            $DB->insert_record('local_feer_sync_users', (object)[
                'email'         => $email,
                'moodle_user_id'=> $moodle_uid,
                'feer_id'       => $vol['id'],
                'feer_status'   => $vol['status'],
                'action_id'     => $vol['action_id'] ?? null,
                'synced_at'     => $now,
                'created_at'    => $now,
            ]);
        }

        // Przywróć konto jeśli było zawieszone
        $this->ensure_user_active($moodle_uid);

        // Zapisz na kursy globalne
        foreach ($global_courses as $course_id) {
            $this->enroll_if_needed($moodle_uid, $course_id);
        }

        // Zapisz na kurs wg działania
        $action_id = (int)($vol['action_id'] ?? 0);
        if ($action_id && isset($action_map[$action_id])) {
            $this->enroll_if_needed($moodle_uid, (int)$action_map[$action_id]);
        }
    }

    private function maybe_suspend(array $vol, array &$cnt, array &$details): void {
        global $DB;

        $email = strtolower(trim($vol['email'] ?? ''));
        if (!$email) return;

        $mapping = $DB->get_record('local_feer_sync_users', ['email' => $email]);
        $moodle_uid = $mapping->moodle_user_id ?? $this->find_moodle_user($email);
        if (!$moodle_uid) return;

        // Zawieś tylko jeśli nie ma INNEJ aktywnej umowy (sprawdzone przez group=active w API)
        $moodle_user = \core_user::get_user($moodle_uid);
        if ($moodle_user && !$moodle_user->suspended) {
            $this->get_moodle_api_direct()->suspend_user((int)$moodle_uid, true);
            $cnt['suspended']++;
            $details[] = ['email' => $email, 'action' => 'suspended', 'reason' => $vol['status']];
            mtrace('[feer_sync] Zawieszono: ' . $email . ' (status: ' . $vol['status'] . ')');
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private ?object $_moodle_api = null;

    /**
     * Lazy-init bezpośredniego klienta Moodle WS (używa istniejącej konfiguracji).
     * Zamiast importować klasy z feerSZO używamy Moodle core_user + enrol API.
     */
    private function get_moodle_api_direct(): object {
        if ($this->_moodle_api) return $this->_moodle_api;
        // Prosta implementacja inline korzystająca z konfiguracji pluginu
        $url   = rtrim(get_config('local_feer_sync', 'moodle_ws_url') ?: '', '/');
        $token = get_config('local_feer_sync', 'moodle_ws_token') ?: '';
        $this->_moodle_api = new class($url, $token) {
            private string $base;
            private string $token;
            public function __construct(string $url, string $token) {
                $this->base  = $url . '/webservice/rest/server.php';
                $this->token = $token;
            }
            public function call(string $fn, array $p = []): mixed {
                $p['wstoken'] = $this->token;
                $p['wsfunction'] = $fn;
                $p['moodlewsrestformat'] = 'json';
                $curl = curl_init($this->base);
                curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>http_build_query($p),CURLOPT_TIMEOUT=>10]);
                $r = curl_exec($curl); curl_close($curl);
                return json_decode($r ?: '{}', true);
            }
            public function suspend_user(int $uid, bool $s=true): void {
                $this->call('core_user_update_users', ['users[0][id]'=>$uid,'users[0][suspended]'=>(int)$s]);
            }
            public function unsuspend_user(int $uid): void { $this->suspend_user($uid, false); }
        };
        return $this->_moodle_api;
    }

    private function find_moodle_user(string $email): ?int {
        global $DB;
        $u = $DB->get_record('user', ['email' => $email, 'deleted' => 0]);
        return $u ? (int)$u->id : null;
    }

    private function create_moodle_user(array $vol): int {
        global $CFG;
        require_once($CFG->dirroot . '/user/lib.php');

        $firstname = trim($vol['firstname'] ?? '');
        $lastname  = trim($vol['lastname']  ?? '.');
        $email     = strtolower(trim($vol['email']));
        $username  = strtolower(preg_replace('/[^a-z0-9._-]/', '', $email) ?: 'user_' . time());

        // Unikalna username jeśli zajęta
        global $DB;
        $base = $username;
        $i    = 1;
        while ($DB->record_exists('user', ['username' => $username])) {
            $username = $base . $i++;
        }

        $user              = new \stdClass();
        $user->username    = $username;
        $user->email       = $email;
        $user->firstname   = $firstname ?: $lastname;
        $user->lastname    = $lastname;
        $user->password    = hash_internal_user_password(bin2hex(random_bytes(12)));
        $user->auth        = 'manual';
        $user->confirmed   = 1;
        $user->mnethostid  = 1;
        $user->lang        = 'pl';
        $user->country     = 'PL';
        $user->timecreated = time();
        $user->timemodified= time();

        return (int)user_create_user($user, false, false);
    }

    private function ensure_user_active(int $moodle_uid): void {
        global $DB;
        $u = $DB->get_record('user', ['id' => $moodle_uid]);
        if ($u && $u->suspended) {
            $DB->set_field('user', 'suspended', 0, ['id' => $moodle_uid]);
        }
    }

    private function enroll_if_needed(int $moodle_uid, int $course_id): void {
        global $DB;
        if (!$course_id) return;

        // Sprawdź czy już zapisany
        $ctx = \context_course::instance($course_id, IGNORE_MISSING);
        if (!$ctx) return;

        $is_enrolled = is_enrolled($ctx, $moodle_uid);
        if ($is_enrolled) return;

        // Pobierz instancję enrol_manual
        $enrol  = enrol_get_plugin('manual');
        $instances = enrol_get_instances($course_id, true);
        $instance  = null;
        foreach ($instances as $inst) {
            if ($inst->enrol === 'manual') { $instance = $inst; break; }
        }
        if (!$instance || !$enrol) return;

        $enrol->enrol_user($instance, $moodle_uid, 5); // rola 5 = student
    }

    private function parse_course_ids(string $raw): array {
        return array_values(array_filter(array_map('intval', explode(',', $raw))));
    }

    private function parse_action_map(string $json): array {
        $map = json_decode($json, true) ?? [];
        $result = [];
        foreach ($map as $k => $v) {
            $result[(int)$k] = (int)$v;
        }
        return $result;
    }

    private function is_manual(): bool {
        return (bool)get_config('local_feer_sync', '_manual_trigger');
    }
}
