<?php
/**
 * inc/tiCertStore/tiCertStore.php — przechowywanie certyfikatów prowadzących (.p12) na serwerze.
 *
 * Od 11.10.2026 plik certyfikatu nie idzie mailem: zostaje w systemie, a prowadzący przy
 * zatwierdzaniu protokołu klika „Odczytaj certyfikat” i wpisuje hasło do niego oraz kod SMS.
 *
 * Bezpieczeństwo — dwie warstwy:
 *   1. sam plik .p12 jest zaszyfrowany hasłem prowadzącego (min. 16 znaków, ustawia je sam
 *      w kreatorze; serwer go nie zna i nie zapisuje),
 *   2. przed zapisem do bazy plik jest dodatkowo szyfrowany AES-256-GCM kluczem wyprowadzonym
 *      z APP_KEY (osobny kontekst „ti-cert-store”), więc sama kopia bazy nie wystarczy.
 * Bez hasła prowadzącego nie da się podpisać — klucz prywatny siedzi w zaszyfrowanym .p12.
 *
 * Tabela k30_ti_cert_store: jeden wiersz na certyfikat (po odcisku z admin_x509_certs).
 */

function tiCertStoreMigrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    db()->exec("CREATE TABLE IF NOT EXISTS k30_ti_cert_store (
        fingerprint TEXT    PRIMARY KEY,
        user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
        p12_enc     TEXT    NOT NULL,
        created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
    )");
    db()->exec("CREATE INDEX IF NOT EXISTS idx_ti_cert_store_user ON k30_ti_cert_store(user_id)");
}

/** Klucz szyfrujący magazynu — z APP_KEY, z własnym kontekstem (nie ten sam co w innych modułach). */
function tiCertStoreKey(): string {
    if (!defined('APP_KEY') || (string)APP_KEY === '') throw new \RuntimeException('Brak APP_KEY — nie można zapisać certyfikatu.');
    return hash_hmac('sha256', 'ti-cert-store', (string)APP_KEY, true);
}

function tiCertStoreEncrypt(string $p12): string {
    $iv  = random_bytes(12);
    $tag = '';
    $enc = openssl_encrypt($p12, 'aes-256-gcm', tiCertStoreKey(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($enc === false) throw new \RuntimeException('Nie udało się zaszyfrować certyfikatu.');
    return 'v1:' . base64_encode($iv . $tag . $enc);
}

function tiCertStoreDecrypt(string $stored): ?string {
    if (!str_starts_with($stored, 'v1:')) return null;
    $raw = base64_decode(substr($stored, 3), true);
    if ($raw === false || strlen($raw) < 29) return null;
    $dec = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', tiCertStoreKey(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $dec === false ? null : $dec;
}

/** Zapisuje plik .p12 certyfikatu o danym odcisku. */
function tiCertStoreSave(int $userId, string $fingerprint, string $p12): void {
    tiCertStoreMigrate();
    db()->prepare("INSERT OR REPLACE INTO k30_ti_cert_store (fingerprint, user_id, p12_enc, created_at) VALUES (?, ?, ?, datetime('now'))")
        ->execute([$fingerprint, $userId, tiCertStoreEncrypt($p12)]);
}

/**
 * Aktywny (nieodwołany, ważny) certyfikat użytkownika, którego plik jest na serwerze.
 * Zwraca dane certyfikatu (numer, ważność, podmiot) i — gdy $withFile — odszyfrowany .p12.
 */
function tiCertStoreActive(int $userId, bool $withFile = false): ?array {
    tiCertStoreMigrate();
    $row = db_one(
        "SELECT c.id, c.numer, c.subject_cn, c.valid_from, c.valid_to, c.fingerprint, s.p12_enc
           FROM admin_x509_certs c JOIN k30_ti_cert_store s ON s.fingerprint = c.fingerprint
          WHERE c.user_id = ? AND s.user_id = ? AND c.revoked_at IS NULL AND c.valid_to > datetime('now')
          ORDER BY c.id DESC LIMIT 1",
        [$userId, $userId]
    );
    if (!$row) return null;
    if ($withFile) {
        $row['p12'] = tiCertStoreDecrypt((string)$row['p12_enc']);
        if ($row['p12'] === null) return null;
    }
    unset($row['p12_enc']);
    return $row;
}

/** Usuwa zapisane pliki certyfikatów, które zostały odwołane (sprzątanie przy wystawianiu nowego). */
function tiCertStorePurgeRevoked(int $userId): void {
    tiCertStoreMigrate();
    db()->prepare("DELETE FROM k30_ti_cert_store WHERE user_id = ? AND fingerprint IN
                     (SELECT fingerprint FROM admin_x509_certs WHERE user_id = ? AND revoked_at IS NOT NULL)")
        ->execute([$userId, $userId]);
}
