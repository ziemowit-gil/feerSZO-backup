<?php
declare(strict_types=1);

/**
 * modules/kontoBezosobowe/logic/KontoBezosobowe.php — konto bez umowy, bezosobowe (polecenie 2026-11-25:
 * „dopisz funkcję Konto bez umowy – bezosobowe; twórz maila boXXXX, XXXX losowe cyfry, 20-znakowe hasło, drukuj dane do PDF;
 * twórz wówczas konto pocztowe”).
 *
 *  - Skrzynka bo{4 losowe cyfry}@domena na serwerze poczty (API jak przy umowach: DhostingConfig / DhostingApi), domena = pierwsza
 *    domena docelowa z konfiguracji Migratora poczty (DhostingContractMail::domain()).
 *  - Adres unikalny: lista skrzynek na serwerze, rejestr kont bezosobowych, skrzynki umów, users.m365_login/email.
 *  - Hasło 20 znaków (DhostingConfig::generatePassword(20)); nie zapisujemy go — administrator widzi je raz i drukuje PDF
 *    (15 minut, sesja), potem pozostaje tylko „Nowe hasło”.
 *  - Tabela impersonal_accounts: adres, przeznaczenie, opis, kto i kiedy założył, ostatnia zmiana hasła.
 */
require_once dirname(__DIR__, 2) . '/dhosting_migrator/logic/DhostingContractMail.php';

final class KontoBezosobowe
{
    public const PASS_LEN = 20;
    public const PREFIX = 'bo';
    private const TRIES = 60;

    public static function migrate(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        db()->exec("CREATE TABLE IF NOT EXISTS impersonal_accounts (
            id INTEGER PRIMARY KEY,
            mailbox VARCHAR(190) NOT NULL,
            label VARCHAR(190) NOT NULL DEFAULT '',
            note TEXT NULL,
            status VARCHAR(12) NOT NULL DEFAULT 'active',
            created_at VARCHAR(19) NULL,
            created_by VARCHAR(190) NOT NULL DEFAULT '',
            password_at VARCHAR(19) NULL,
            password_by VARCHAR(190) NOT NULL DEFAULT '')");
        try { db()->exec("CREATE UNIQUE INDEX idx_impacc_mailbox ON impersonal_accounts(mailbox)"); } catch (\Throwable $e) {}
    }

    /** Zakładanie możliwe: API serwera poczty skonfigurowane i jest domena. */
    public static function ready(): bool
    {
        return DhostingConfig::api()->configured() && DhostingContractMail::domain() !== '';
    }

    public static function all(): array
    {
        self::migrate();
        return db_all("SELECT * FROM impersonal_accounts ORDER BY id DESC");
    }

    public static function find(int $id): ?array
    {
        self::migrate();
        return db_one("SELECT * FROM impersonal_accounts WHERE id=?", [$id]) ?: null;
    }

    /**
     * Zakłada skrzynkę bezosobową. Zwraca ['id','mailbox','password','label','issued_at'].
     * Błąd API → wyjątek z komunikatem dla administratora.
     */
    public static function create(string $label, string $note, string $by): array
    {
        self::migrate();
        $label = mb_substr(trim($label), 0, 190);
        $note = trim($note);
        if ($label === '') throw new InvalidArgumentException('Podaj przeznaczenie konta (np. Recepcja, Biuro projektu).');
        if (!self::ready()) throw new RuntimeException('Brak danych API serwera poczty albo domeny. Uzupełnij je w Migratorze poczty.');
        $api = DhostingConfig::api();
        $domain = DhostingContractMail::domain();
        $box = self::newAddress($domain, $api->getMailboxList($domain));
        $password = DhostingConfig::generatePassword(self::PASS_LEN);
        $api->createMailbox($box, $password, null, DhostingConfig::quota(), 'Konto bezosobowe: ' . $label);

        $now = date('Y-m-d H:i:s');
        db()->prepare("INSERT INTO impersonal_accounts (mailbox, label, note, status, created_at, created_by, password_at, password_by) VALUES (?, ?, ?, 'active', ?, ?, ?, ?)")
            ->execute([$box, $label, $note !== '' ? $note : null, $now, $by, $now, $by]);
        $id = (int)db()->lastInsertId();
        self::audit('impersonal_account.create', ['id' => $id, 'mailbox' => $box, 'label' => $label]);
        return ['id' => $id, 'mailbox' => $box, 'password' => $password, 'label' => $label, 'issued_at' => $now];
    }

    /** Nowe 20-znakowe hasło do istniejącej skrzynki bezosobowej. */
    public static function newPassword(int $id, string $by): array
    {
        $row = self::find($id);
        if (!$row) throw new RuntimeException('Nie ma takiego konta bezosobowego.');
        $password = DhostingConfig::generatePassword(self::PASS_LEN);
        DhostingConfig::api()->changeMailboxPassword((string)$row['mailbox'], $password);
        $now = date('Y-m-d H:i:s');
        db()->prepare("UPDATE impersonal_accounts SET password_at=?, password_by=? WHERE id=?")->execute([$now, $by, $id]);
        self::audit('impersonal_account.password', ['id' => $id, 'mailbox' => $row['mailbox']]);
        return ['id' => $id, 'mailbox' => (string)$row['mailbox'], 'password' => $password, 'label' => (string)$row['label'], 'issued_at' => $now];
    }

    /** bo + 4 losowe cyfry; pomija adresy zajęte na serwerze i znane w SZO. */
    public static function newAddress(string $domain, array $taken): string
    {
        $taken = array_flip(array_map('strtolower', $taken));
        for ($i = 0; $i < self::TRIES; $i++) {
            $cand = self::PREFIX . str_pad((string)random_int(0, 9999), 4, '0', STR_PAD_LEFT) . '@' . strtolower($domain);
            if (isset($taken[$cand]) || self::knownInSzo($cand)) continue;
            return $cand;
        }
        throw new RuntimeException('Nie udało się wylosować wolnego adresu ' . self::PREFIX . 'XXXX@' . $domain . '. Spróbuj ponownie.');
    }

    private static function knownInSzo(string $addr): bool
    {
        if (db_one("SELECT id FROM impersonal_accounts WHERE mailbox=? LIMIT 1", [$addr])) return true;
        try { if (db_one("SELECT id FROM dhosting_contract_boxes WHERE mailbox=? LIMIT 1", [$addr])) return true; } catch (\Throwable $e) {}
        if (db_one("SELECT id FROM users WHERE LOWER(TRIM(m365_login))=? OR LOWER(TRIM(email))=? LIMIT 1", [$addr, $addr])) return true;
        return false;
    }

    private static function audit(string $event, array $data): void
    {
        try { if (function_exists('audit_log')) audit_log($event, $data); } catch (\Throwable $e) {}
    }
}
