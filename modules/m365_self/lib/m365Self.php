<?php
/**
 * modules/m365_self/lib/m365Self.php — samodzielne założenie konta Microsoft 365 przez współpracownika.
 *
 * Warunki (m365SelfStatus): działa integracja M365, osoba ma umowę (wolontariat/zlecenie/dzieło) bez konta M365,
 * umowa kwalifikuje się do aktywnego konta (m365_should_be_active), zaakceptowała zgodę na formy dokumentowe
 * i podpisała oświadczenie o ochronie danych. Tworzenie robi to samo co administrator w contracts/m365_action.php
 * (login, licencja, zapis w umowie, powiązanie konta lokalnego) — hasło tymczasowe pokazujemy raz, w sesji.
 */

const M365_SELF_TYPES = ['wolontariat', 'zlecenie', 'dzielo'];

/** Najnowsza umowa osoby bez konta M365 (po adresie e-mail) albo null. */
function m365SelfContract(array $user): ?array {
    $email = trim((string)($user['email'] ?? ''));
    if ($email === '') return null;
    foreach (M365_SELF_TYPES as $type) {
        $row = db_one(
            "SELECT * FROM umowy_{$type} WHERE LOWER(email)=LOWER(?) AND COALESCE(m365_user_id,'')='' ORDER BY id DESC LIMIT 1",
            [$email]
        );
        if ($row) { $row['_type'] = $type; return $row; }
    }
    return null;
}

/** ['ok'=>bool, 'reason'=>string, 'contract'=>?array]. 'reason' = komunikat dla osoby, gdy !ok. */
function m365SelfStatus(array $user): array {
    $uid = (int)$user['id'];
    $contract = m365SelfContract($user);
    if (!$contract) return ['ok' => false, 'reason' => 'Nie znaleźliśmy umowy, do której można założyć konto Microsoft 365.', 'contract' => null];

    if (m365_setting('m365_self_create') === '0') {
        return ['ok' => false, 'reason' => 'Samodzielne zakładanie kont jest wyłączone — konto założy dział IT.', 'contract' => $contract];
    }
    $g = new M365Graph();
    if (m365_setting('m365_enabled') !== '1' || !$g->is_configured()) {
        return ['ok' => false, 'reason' => 'Integracja z Microsoft 365 nie jest skonfigurowana. Skontaktuj się z administratorem.', 'contract' => $contract];
    }
    if (!m365_should_be_active($contract)) {
        return ['ok' => false, 'reason' => 'Konto będzie można założyć od dnia rozpoczęcia umowy.', 'contract' => $contract];
    }
    $row = db_one("SELECT document_form_consent, gdpr_statement_signed_at FROM users WHERE id=?", [$uid]);
    if ((int)($row['document_form_consent'] ?? 0) !== 1) {
        return ['ok' => false, 'reason' => 'Najpierw zaakceptuj regulamin korzystania z panelu.', 'contract' => $contract];
    }
    if (empty($row['gdpr_statement_signed_at'])) {
        return ['ok' => false, 'reason' => 'Najpierw podpisz oświadczenie o ochronie danych.', 'contract' => $contract];
    }
    return ['ok' => true, 'reason' => '', 'contract' => $contract];
}

/**
 * Zakłada konto. Zwraca ['login'=>..., 'password'=>...]; rzuca RuntimeException z komunikatem dla osoby.
 */
function m365SelfCreate(array $user): array {
    $st = m365SelfStatus($user);
    if (!$st['ok']) throw new RuntimeException($st['reason']);
    $c    = $st['contract'];
    $type = $c['_type'];
    if (!in_array($type, M365_SELF_TYPES, true)) throw new RuntimeException('Nieprawidłowy typ umowy.');
    $name = trim((string)($c['imie_nazwisko'] ?? '')) ?: trim((string)($user['name'] ?? ''));
    if ($name === '') throw new RuntimeException('Brak imienia i nazwiska w umowie.');

    // Blokada na umowie: dwa równoległe kliknięcia nie założą dwóch kont (wygasa po 5 min, np. po awarii)
    try { db()->exec("ALTER TABLE umowy_{$type} ADD COLUMN m365_self_lock DATETIME"); } catch (\Throwable $e) {}
    $lock = db()->prepare("UPDATE umowy_{$type} SET m365_self_lock=datetime('now')
                           WHERE id=? AND COALESCE(m365_user_id,'')='' AND (m365_self_lock IS NULL OR m365_self_lock < datetime('now','-5 minutes'))");
    $lock->execute([(int)$c['id']]);
    if ($lock->rowCount() !== 1) throw new RuntimeException('Zakładanie konta jest już w toku — odśwież stronę za chwilę.');

    $graph = new M365Graph();
    $oid = '';
    try {
        $login = $graph->unique_login($name);
        $pass  = M365Graph::generate_password();

        $sku = m365_setting('m365_license_sku_id');
        if ($sku) {
            foreach ($graph->get_subscribed_skus() as $s) {
                if ($s['skuId'] === $sku) {
                    if (($s['prepaidUnits']['enabled'] ?? 0) - ($s['consumedUnits'] ?? 0) <= 0) {
                        throw new RuntimeException('Brak wolnych licencji Microsoft 365 — zgłoś to administratorowi.');
                    }
                    break;
                }
            }
        }

        $created = $graph->create_user($login, $name, $pass, true);
        $oid = $created['id'];
        if ($sku) $graph->assign_license($oid, $sku);

        $uid = (int)$user['id'];
        $pdo = db();
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE umowy_{$type} SET m365_konto=1, m365_login=?, m365_user_id=?, m365_konto_aktywne=1,
                       m365_data_utworzenia=?, m365_licencja_przypisana=?, m365_self_lock=NULL WHERE id=?")
            ->execute([$login, $oid, date('Y-m-d H:i:s'), $sku ? 1 : 0, (int)$c['id']]);
        // konto lokalne tej osoby wiążemy z kontem Microsoft (logowanie jednym kliknięciem)
        $pdo->prepare("UPDATE users SET microsoft_id=? WHERE id=?")->execute([$oid, $uid]);
        $pdo->commit();
    } catch (\Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        // sprzątanie: konto utworzone w Entra ID, ale reszta się nie udała — nie zostawiamy sieroty
        if ($oid !== '') { try { $graph->delete_user($oid); } catch (\Throwable $e2) {} }
        db()->prepare("UPDATE umowy_{$type} SET m365_self_lock=NULL WHERE id=?")->execute([(int)$c['id']]);
        throw $e instanceof RuntimeException ? $e : new RuntimeException('Nie udało się założyć konta: ' . $e->getMessage());
    }

    try {
        require_once dirname(__DIR__, 3) . '/includes/it_helpers.php';
        it_migrate();
        $svc = db_one("SELECT id FROM it_services WHERE slug='m365'");
        if ($svc) it_log_password(['service_id' => (int)$svc['id'], 'contract_type' => $type, 'contract_id' => (int)$c['id'],
            'login' => $login, 'plain' => $pass, 'sent_to_email' => null,
            'notes' => 'Konto założone samodzielnie przez współpracownika', 'issued_by' => $uid]);
    } catch (\Throwable $e) {}
    m365SelfNotifyIt($user, $login, $type, (int)$c['id']);
    if (function_exists('authlog_write')) authlog_write($uid, 'm365_self_created', (string)($user['email'] ?? ''), 'Samodzielnie założono konto M365: ' . $login);
    return ['login' => $login, 'password' => $pass];
}

/** Zgłoszenie informacyjne dla działu IT (helpdesk, kategoria it_m365) o samodzielnie założonym koncie. */
function m365SelfNotifyIt(array $user, string $login, string $type, int $contractId): void {
    try {
        require_once dirname(__DIR__, 3) . '/includes/helpdesk.php';
        $name = (string)($user['name'] ?? $user['email'] ?? '');
        $desc = "Współpracownik samodzielnie założył konto Microsoft 365 po akceptacji regulaminu i podpisaniu oświadczenia RODO.\n\n"
              . "Osoba: {$name} ({$user['email']})\nLogin M365: {$login}\nUmowa: {$type} #{$contractId}\n\nZgłoszenie informacyjne — działań nie wymaga.";
        $tid = db_insert('helpdesk_tickets', [
            'number' => hd_next_number('CHG'), 'title' => 'Samodzielnie założone konto M365: ' . $login,
            'description' => $desc, 'category' => 'it_m365', 'priority' => 'niski', 'status' => 'nowe',
            'requester_id' => (int)$user['id'], 'requester_name' => $name, 'requester_email' => (string)($user['email'] ?? ''),
            'source' => 'm365_self_create',
        ]);
        db_insert('helpdesk_messages', ['ticket_id' => $tid, 'user_id' => (int)$user['id'], 'user_name' => $name, 'body' => $desc, 'is_internal' => 0]);
    } catch (\Throwable $e) { /* powiadomienie nie może zablokować założenia konta */ }
}
