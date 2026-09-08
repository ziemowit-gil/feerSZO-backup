<?php
/**
 * includes/ti_price_changes.php — planowane zmiany cen zajęć TI (grupowe/indywidualne).
 *
 * Cena kursu (k30_ti_courses.billing_amount) i indywidualny override
 * (k30_ti_enrollments.billing_amount/hourly_rate — kod 9999) to POJEDYNCZE,
 * bezhistoryczne wartości: zmiana nadpisuje je od razu, na zawsze, bez śladu
 * "dlaczego" i bez możliwości zaplanowania jej na przyszłość ani ograniczenia
 * w czasie. Ten moduł tego NIE zastępuje — dodaje WARSTWĘ ponad nim: zapisaną
 * zmianę (kwota albo procent, z zakresem dat i uzasadnieniem), która modyfikuje
 * wynik k30_ti_effective_billing() tylko dla okresów w zakresie dat, i wysyła
 * o tym e-mail do kursanta/opiekuna.
 *
 * Zakres: 'course' (cały kurs — wszyscy kursanci bez własnego override)
 *      albo 'client' (jeden kursant w tym kursie — nadpisuje 'course').
 */

function ti_price_changes_migrate(): void {
    static $done = false; if ($done) return; $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS k30_ti_price_changes (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            scope         TEXT    NOT NULL,
            course_id     INTEGER NOT NULL REFERENCES k30_ti_courses(id) ON DELETE CASCADE,
            client_id     INTEGER REFERENCES k30_clients(id) ON DELETE CASCADE,
            change_type   TEXT    NOT NULL,
            change_value  REAL    NOT NULL,
            date_from     TEXT    NOT NULL,
            date_to       TEXT,
            reason        TEXT    NOT NULL DEFAULT '',
            email_subject TEXT    NOT NULL DEFAULT '',
            email_body    TEXT    NOT NULL DEFAULT '',
            status        TEXT    NOT NULL DEFAULT 'active',
            notified_at   TEXT,
            notified_count INTEGER NOT NULL DEFAULT 0,
            created_by    INTEGER,
            created_at    TEXT    NOT NULL DEFAULT (datetime('now'))
        )");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_ti_price_ch_course ON k30_ti_price_changes(course_id, status)");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_ti_price_ch_client ON k30_ti_price_changes(client_id, status)");
    } catch (\Throwable $e) {}
}

const TI_PRICE_CHANGE_TYPES = ['amount' => 'Nowa kwota (zł)', 'percent' => 'Zmiana procentowa (%)'];

/** Etykieta czytelna dla listy/e-maila, np. "+10%" albo "120,00 zł". */
function ti_price_change_value_label(string $type, float $value): string {
    if ($type === 'percent') {
        return ($value > 0 ? '+' : '') . rtrim(rtrim(number_format($value, 2, ',', ''), '0'), ',') . '%';
    }
    return number_format($value, 2, ',', ' ') . ' zł';
}

/**
 * Zmiana obowiązująca dla kursu/kursanta w danym okresie rozliczeniowym
 * (najpierw indywidualna, potem grupowa) — albo null.
 */
function ti_price_change_effective_for(int $course_id, int $client_id, string $period_from, string $period_to): ?array {
    ti_price_changes_migrate();
    return db_one(
        "SELECT * FROM k30_ti_price_changes
          WHERE status='active' AND course_id=?
            AND date_from <= ? AND (date_to IS NULL OR date_to >= ?)
            AND (client_id = ? OR client_id IS NULL)
          ORDER BY (client_id IS NOT NULL) DESC, date_from DESC
          LIMIT 1",
        [$course_id, $period_to, $period_from, $client_id]
    );
}

/** Nakłada dopasowaną zmianę na wynik k30_ti_effective_billing() — bez zmiany, gdy $change=null. */
function ti_price_change_apply_to_effective(array $eff, ?array $change): array {
    if (!$change) return $eff;
    $type = (string)$change['change_type'];
    $val  = (float)$change['change_value'];
    if (in_array((int)$eff['model'], [1, 3], true)) {
        $eff['amount'] = $type === 'percent' ? round($eff['amount'] * (1 + $val / 100), 2) : max(0, $val);
    } else {
        $eff['hourly_rate'] = $type === 'percent' ? round($eff['hourly_rate'] * (1 + $val / 100), 2) : max(0, $val);
    }
    $eff['price_change_id'] = (int)$change['id'];
    return $eff;
}

/**
 * Domyślny tekst e-maila — punkt wyjścia do edycji przez kierownika w formularzu.
 * Opisuje samą zmianę (np. "+10%" albo "120,00 zł"), nie kwoty przed/po —
 * przy zasięgu 'course' i modelu godzinowym każdy kursant ma własną stawkę
 * na zapisie (nie ma jednej "ceny kursu"), więc jedna para przed/po byłaby myląca.
 */
function ti_price_change_default_email(array $data, string $course_name): array {
    $org    = defined('ORG_NAME') ? ORG_NAME : 'Zajęcia TI';
    $range  = date('d.m.Y', strtotime($data['date_from']));
    $range .= !empty($data['date_to']) ? ' – ' . date('d.m.Y', strtotime($data['date_to'])) : ' (bezterminowo)';
    $value_label = ti_price_change_value_label($data['change_type'], (float)$data['change_value']);

    $subject = "{$org}: zmiana ceny zajęć — {$course_name}";
    $body = "Dzień dobry,\n\n"
        . "informujemy o zmianie ceny zajęć „{$course_name}”, obowiązującej od {$range}.\n\n"
        . "Zmiana: {$value_label}\n\n"
        . (trim($data['reason']) !== '' ? "Uzasadnienie: {$data['reason']}\n\n" : '')
        . "Dokładną nową kwotę znajdziesz w panelu, w zakładce Rozliczenia. W razie pytań prosimy o kontakt "
        . "z prowadzącym albo z biurem placówki.\n\n"
        . "Pozdrawiamy,\n{$org}";
    return ['subject' => $subject, 'body' => $body];
}

/**
 * Tworzy zaplanowaną zmianę ceny. $data: scope,course_id,client_id,change_type,
 * change_value,date_from,date_to,reason,email_subject,email_body,created_by.
 */
function ti_price_change_create(array $data): int {
    ti_price_changes_migrate();
    return db_insert('k30_ti_price_changes', [
        'scope'         => $data['scope'],
        'course_id'     => (int)$data['course_id'],
        'client_id'     => $data['scope'] === 'client' ? (int)$data['client_id'] : null,
        'change_type'   => $data['change_type'],
        'change_value'  => (float)$data['change_value'],
        'date_from'     => $data['date_from'],
        'date_to'       => $data['date_to'] ?: null,
        'reason'        => trim((string)$data['reason']),
        'email_subject' => trim((string)($data['email_subject'] ?? '')),
        'email_body'    => trim((string)($data['email_body'] ?? '')),
        'created_by'    => $data['created_by'] ?? null,
    ]);
}

function ti_price_change_get(int $id): ?array {
    ti_price_changes_migrate();
    return db_one("SELECT * FROM k30_ti_price_changes WHERE id=?", [$id]);
}

function ti_price_changes_for_course(int $course_id): array {
    ti_price_changes_migrate();
    return db_all(
        "SELECT pc.*, cl.name AS client_name FROM k30_ti_price_changes pc
         LEFT JOIN k30_clients cl ON cl.id = pc.client_id
         WHERE pc.course_id=? ORDER BY pc.created_at DESC",
        [$course_id]
    );
}

function ti_price_change_cancel(int $id): void {
    ti_price_changes_migrate();
    db()->prepare("UPDATE k30_ti_price_changes SET status='cancelled' WHERE id=?")->execute([$id]);
}

/** Odbiorcy powiadomienia: kursant(ci) objęci zmianą + opiekunowie małoletnich. */
function ti_price_change_recipients(array $change): array {
    if ($change['scope'] === 'client') {
        return db_all(
            "SELECT a.is_minor, a.guardian_email, cl.name, cl.email
             FROM k30_ti_student_accounts a JOIN k30_clients cl ON cl.id=a.client_id
             WHERE a.client_id=? AND a.is_active=1",
            [(int)$change['client_id']]
        );
    }
    // Zasięg 'course': wszyscy aktywni kursanci kursu BEZ własnego override cenowego
    // (kto ma indywidualny model, dostanie osobne powiadomienie przy swojej zmianie).
    return db_all(
        "SELECT DISTINCT a.is_minor, a.guardian_email, cl.name, cl.email
         FROM k30_ti_enrollments e
         JOIN k30_ti_student_accounts a ON a.client_id=e.client_id AND a.is_active=1
         JOIN k30_clients cl ON cl.id=e.client_id
         WHERE e.course_id=? AND e.status='active' AND (e.billing_model IS NULL OR e.billing_model=0)",
        [(int)$change['course_id']]
    );
}

/** Wysyła e-mail (z treści zapisanej przy tworzeniu zmiany) do objętych kursantów/opiekunów. */
function ti_price_change_notify(int $id): int {
    ti_price_changes_migrate();
    $change = ti_price_change_get($id);
    if (!$change || $change['status'] !== 'active') return 0;
    if (!function_exists('mail_queue_add')) @require_once __DIR__ . '/mail_queue.php';

    $subject = $change['email_subject'] !== '' ? $change['email_subject'] : 'Zmiana ceny zajęć';
    $body_html = nl2br(htmlspecialchars((string)$change['email_body'], ENT_QUOTES));
    $org = defined('ORG_NAME') ? ORG_NAME : '';
    $html = "<div>{$body_html}</div><p style='color:#888;font-size:12px'>Wiadomość automatyczna z systemu {$org}.</p>";

    $sent = 0;
    foreach (ti_price_change_recipients($change) as $s) {
        $emails = [];
        $primary = trim((string)($s['email'] ?? ''));
        if ($primary !== '' && filter_var($primary, FILTER_VALIDATE_EMAIL)) $emails[$primary] = (string)$s['name'];
        $gemail = trim((string)($s['guardian_email'] ?? ''));
        if (!empty($s['is_minor']) && $gemail !== '' && filter_var($gemail, FILTER_VALIDATE_EMAIL)) $emails[$gemail] = (string)$s['name'];
        foreach ($emails as $addr => $nm) {
            try { mail_queue_add($addr, $nm, $subject, $html, '', 'ti_price_change', $id, '', false); $sent++; }
            catch (\Throwable $e) {}
        }
    }
    db()->prepare("UPDATE k30_ti_price_changes SET notified_at=datetime('now'), notified_count=notified_count+? WHERE id=?")
        ->execute([$sent, $id]);
    return $sent;
}
