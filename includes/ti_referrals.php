<?php
/**
 * includes/ti_referrals.php — program poleceń (rabat za polecenie) dla zajęć TI.
 *
 * Istniejący kursant ma osobisty kod polecający. Znajomy podaje ten kod przy
 * zakładaniu konta w panelu (karty30/ti/dydaktyk/konta.php) — od tej chwili:
 *   - POLECAJĄCY dostaje JEDNORAZOWO X% rabatu na najbliższe rozliczenie
 *     (naliczane, gdy tylko system wystawi mu kolejne rozliczenie — patrz
 *     ti_referral_billing_adjustment()),
 *   - POLECONY dostaje Y% rabatu na swoje pierwsze Z rozliczeń (okresów).
 * Rabat jest realizowany przez ISTNIEJĄCE pole k30_ti_billing.adjustment
 * (to samo, którego prowadzący/kierownik używa do ręcznych korekt) — nie
 * wprowadzamy równoległego mechanizmu naliczania.
 *
 * Rabat nakładany jest WYŁĄCZNIE przy PIERWSZYM wystawieniu rozliczenia za dany
 * okres (k30_ti_issue_billing()), nigdy przy ponownym przeliczeniu — inaczej
 * nadpisywałby ręczną korektę pracownika przy każdym kolejnym uruchomieniu.
 */

function ti_referrals_migrate(): void {
    static $done = false; if ($done) return; $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS k30_ti_referral_codes (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            client_id  INTEGER NOT NULL REFERENCES k30_clients(id) ON DELETE CASCADE,
            code       TEXT    NOT NULL UNIQUE,
            created_at TEXT    NOT NULL DEFAULT (datetime('now'))
        )");
        db()->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_ti_refcode_client ON k30_ti_referral_codes(client_id)");

        db()->exec("CREATE TABLE IF NOT EXISTS k30_ti_referrals (
            id                       INTEGER PRIMARY KEY AUTOINCREMENT,
            code_id                  INTEGER NOT NULL REFERENCES k30_ti_referral_codes(id) ON DELETE CASCADE,
            referrer_client_id       INTEGER NOT NULL REFERENCES k30_clients(id) ON DELETE CASCADE,
            referred_client_id       INTEGER NOT NULL REFERENCES k30_clients(id) ON DELETE CASCADE,
            referrer_pct             REAL    NOT NULL DEFAULT 0,
            referred_pct             REAL    NOT NULL DEFAULT 0,
            referred_periods_total   INTEGER NOT NULL DEFAULT 0,
            referred_periods_used    INTEGER NOT NULL DEFAULT 0,
            referred_last_period_key TEXT    NOT NULL DEFAULT '',
            referred_status          TEXT    NOT NULL DEFAULT 'active',
            referrer_reward_status   TEXT    NOT NULL DEFAULT 'pending',
            referrer_reward_at       TEXT,
            created_by               INTEGER,
            created_at               TEXT    NOT NULL DEFAULT (datetime('now'))
        )");
        db()->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_ti_referral_referred ON k30_ti_referrals(referred_client_id)");
        db()->exec("CREATE INDEX IF NOT EXISTS idx_ti_referral_referrer ON k30_ti_referrals(referrer_client_id)");
    } catch (\Throwable $e) {}
}

const TI_REFERRAL_DEFAULTS = [
    'ti_referral_enabled'          => '0',
    'ti_referral_referrer_pct'     => '10',
    'ti_referral_referred_pct'     => '15',
    'ti_referral_referred_periods' => '2',
];

function ti_referral_setting_get(string $key): string {
    $r = db_one("SELECT value FROM settings WHERE key_=?", [$key]);
    return $r !== null ? (string)$r['value'] : (string)(TI_REFERRAL_DEFAULTS[$key] ?? '');
}

function ti_referral_setting_set(string $key, string $value): void {
    if (db_one("SELECT 1 FROM settings WHERE key_=?", [$key])) {
        db()->prepare("UPDATE settings SET value=? WHERE key_=?")->execute([$value, $key]);
    } else {
        db()->prepare("INSERT INTO settings (key_, value) VALUES (?,?)")->execute([$key, $value]);
    }
}

function ti_referral_settings(): array {
    return [
        'enabled'          => ti_referral_setting_get('ti_referral_enabled') === '1',
        'referrer_pct'     => (float)ti_referral_setting_get('ti_referral_referrer_pct'),
        'referred_pct'     => (float)ti_referral_setting_get('ti_referral_referred_pct'),
        'referred_periods' => (int)ti_referral_setting_get('ti_referral_referred_periods'),
    ];
}

function ti_referral_settings_save(bool $enabled, float $referrer_pct, float $referred_pct, int $referred_periods): void {
    ti_referral_setting_set('ti_referral_enabled', $enabled ? '1' : '0');
    ti_referral_setting_set('ti_referral_referrer_pct', (string)max(0, min(100, $referrer_pct)));
    ti_referral_setting_set('ti_referral_referred_pct', (string)max(0, min(100, $referred_pct)));
    ti_referral_setting_set('ti_referral_referred_periods', (string)max(0, $referred_periods));
}

/** Zwraca istniejący kod polecający kursanta albo generuje nowy (jeden na klienta). */
function ti_referral_code_for_client(int $client_id): string {
    ti_referrals_migrate();
    $ex = db_one("SELECT code FROM k30_ti_referral_codes WHERE client_id=?", [$client_id]);
    if ($ex) return (string)$ex['code'];
    // Bez 0/O, 1/I/L — mniej pomyłek przy dyktowaniu kodu przez telefon.
    static $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    do {
        $code = '';
        for ($i = 0; $i < 6; $i++) $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    } while (db_one("SELECT id FROM k30_ti_referral_codes WHERE code=?", [$code]));
    db_insert('k30_ti_referral_codes', ['client_id' => $client_id, 'code' => $code]);
    return $code;
}

/**
 * Zamienia kod polecający na aktywne polecenie dla nowego kursanta.
 * @return array{ok:bool, error?:string, referrer_name?:string}
 */
function ti_referral_redeem(string $code, int $referred_client_id, ?int $by = null): array {
    ti_referrals_migrate();
    $code = strtoupper(trim($code));
    if ($code === '') return ['ok' => false, 'error' => 'Brak kodu.'];

    $settings = ti_referral_settings();
    if (!$settings['enabled']) return ['ok' => false, 'error' => 'Program poleceń jest obecnie wyłączony.'];

    $row = db_one("SELECT * FROM k30_ti_referral_codes WHERE code=?", [$code]);
    if (!$row) return ['ok' => false, 'error' => 'Nieprawidłowy kod polecający.'];
    if ((int)$row['client_id'] === $referred_client_id) {
        return ['ok' => false, 'error' => 'Nie można użyć własnego kodu polecającego.'];
    }
    if (db_one("SELECT id FROM k30_ti_referrals WHERE referred_client_id=?", [$referred_client_id])) {
        return ['ok' => false, 'error' => 'Ten kursant już skorzystał z polecenia.'];
    }

    db_insert('k30_ti_referrals', [
        'code_id'                => (int)$row['id'],
        'referrer_client_id'     => (int)$row['client_id'],
        'referred_client_id'     => $referred_client_id,
        'referrer_pct'           => $settings['referrer_pct'],
        'referred_pct'           => $settings['referred_pct'],
        'referred_periods_total' => $settings['referred_periods'],
        'created_by'             => $by,
    ]);

    $referrer = db_one("SELECT name FROM k30_clients WHERE id=?", [(int)$row['client_id']]);
    return ['ok' => true, 'referrer_name' => (string)($referrer['name'] ?? '')];
}

/**
 * Wołane WYŁĄCZNIE przy pierwszym wystawieniu rozliczenia za dany okres
 * (k30_ti_issue_billing(), gałąź „nowy wiersz"). Nalicza rabat polecanego
 * (Y% przez Z okresów) i/lub jednorazową nagrodę polecającego (X%), jeśli
 * dotyczą tego klienta — i od razu zapisuje zużycie/flagę w bazie.
 *
 * @return array{adjustment: float, note: string} adjustment ⩽ 0 (rabat pomniejsza kwotę)
 */
function ti_referral_billing_adjustment(int $client_id, int $month, int $year, float $amount): array {
    ti_referrals_migrate();
    if ($amount <= 0) return ['adjustment' => 0.0, 'note' => ''];

    $adjustment = 0.0;
    $notes = [];
    $period_key = sprintf('%04d-%02d', $year, $month);

    // ── Rabat POLECONEGO: Y% przez pierwsze Z okresów ──────────────────────
    $ref = db_one(
        "SELECT * FROM k30_ti_referrals WHERE referred_client_id=? AND referred_status='active'",
        [$client_id]
    );
    if ($ref && (int)$ref['referred_periods_used'] < (int)$ref['referred_periods_total']
        && (string)$ref['referred_last_period_key'] !== $period_key) {
        $pct = (float)$ref['referred_pct'];
        $adjustment -= round($amount * $pct / 100, 2);
        $used  = (int)$ref['referred_periods_used'] + 1;
        $total = (int)$ref['referred_periods_total'];
        $notes[] = "Rabat polecającego -{$pct}% (okres {$used}/{$total})";
        db()->prepare(
            "UPDATE k30_ti_referrals SET referred_periods_used=?, referred_last_period_key=?,
                    referred_status=CASE WHEN ?>=referred_periods_total THEN 'completed' ELSE referred_status END
             WHERE id=?"
        )->execute([$used, $period_key, $used, (int)$ref['id']]);
    }

    // ── Nagroda POLECAJĄCEGO: X% jednorazowo, gdy polecony zaczął płacić ───
    $reward = db_one(
        "SELECT * FROM k30_ti_referrals WHERE referrer_client_id=? AND referrer_reward_status='pending'",
        [$client_id]
    );
    if ($reward) {
        $pct = (float)$reward['referrer_pct'];
        $adjustment -= round($amount * $pct / 100, 2);
        $notes[] = "Nagroda za polecenie -{$pct}%";
        db()->prepare(
            "UPDATE k30_ti_referrals SET referrer_reward_status='applied', referrer_reward_at=datetime('now') WHERE id=?"
        )->execute([(int)$reward['id']]);
    }

    return ['adjustment' => $adjustment, 'note' => implode('; ', $notes)];
}

/** Polecenie, w którym dany klient jest POLECONYM (albo null). Do wglądu w UI. */
function ti_referral_for_referred(int $client_id): ?array {
    ti_referrals_migrate();
    return db_one(
        "SELECT r.*, c.name AS referrer_name FROM k30_ti_referrals r
         JOIN k30_clients c ON c.id = r.referrer_client_id
         WHERE r.referred_client_id=?",
        [$client_id]
    );
}

/** Lista poleceń, w których dany klient jest POLECAJĄCYM. Do wglądu w UI. */
function ti_referrals_by_referrer(int $client_id): array {
    ti_referrals_migrate();
    return db_all(
        "SELECT r.*, c.name AS referred_name FROM k30_ti_referrals r
         JOIN k30_clients c ON c.id = r.referred_client_id
         WHERE r.referrer_client_id=? ORDER BY r.created_at DESC",
        [$client_id]
    );
}

/** Wszystkie polecenia — widok kierownika. */
function ti_referrals_list_all(): array {
    ti_referrals_migrate();
    return db_all(
        "SELECT r.*, cr.name AS referrer_name, cd.name AS referred_name
           FROM k30_ti_referrals r
           JOIN k30_clients cr ON cr.id = r.referrer_client_id
           JOIN k30_clients cd ON cd.id = r.referred_client_id
          ORDER BY r.created_at DESC"
    );
}
