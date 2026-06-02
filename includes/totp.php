<?php
/**
 * TOTP — Time-based One-Time Password (RFC 6238 / RFC 4226)
 * Pure PHP, no external dependencies.
 */
class TOTP
{
    private const DIGITS    = 6;
    private const PERIOD    = 30;   // seconds per step
    private const ALGO      = 'sha1';

    // ── Public API ────────────────────────────────────────────────────────────

    /**
     * Generate a 160-bit (20-byte) random secret, base32-encoded.
     */
    public static function generate_secret(): string
    {
        return self::base32_encode(random_bytes(20));
    }

    /**
     * Build the otpauth:// URI for QR code generation.
     */
    public static function get_qr_uri(string $secret, string $label, string $issuer): string
    {
        return 'otpauth://totp/'
            . rawurlencode($issuer . ':' . $label)
            . '?secret='  . rawurlencode($secret)
            . '&issuer='  . rawurlencode($issuer)
            . '&algorithm=SHA1'
            . '&digits='  . self::DIGITS
            . '&period='  . self::PERIOD;
    }

    /**
     * Verify a 6-digit TOTP code with a ±window time-step tolerance (default ±1 = 90 s).
     */
    public static function verify(string $secret, string $code, int $window = 1): bool
    {
        $code = preg_replace('/\s/', '', $code);
        if (!preg_match('/^\d{6}$/', $code)) {
            return false;
        }
        $t = (int) floor(time() / self::PERIOD);
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals(self::get_code_at($secret, $t + $i), $code)) {
                return true;
            }
        }
        return false;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Compute HOTP code at counter value $t.
     */
    private static function get_code_at(string $secret, int $t): string
    {
        $key     = self::base32_decode($secret);
        $counter = pack('N*', 0) . pack('N*', $t);   // 8-byte big-endian

        $hash    = hash_hmac(self::ALGO, $counter, $key, true);
        $offset  = ord($hash[19]) & 0x0F;

        $code =
            ((ord($hash[$offset])     & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) <<  8) |
             (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string)($code % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Encode binary data as base32 (RFC 4648, uppercase, no padding stripped).
     */
    private static function base32_encode(string $data): string
    {
        static $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $encoded = '';
        $n       = strlen($data);
        $buffer  = 0;
        $bits    = 0;

        for ($i = 0; $i < $n; $i++) {
            $buffer = ($buffer << 8) | ord($data[$i]);
            $bits  += 8;
            while ($bits >= 5) {
                $bits   -= 5;
                $encoded .= $chars[($buffer >> $bits) & 0x1F];
            }
        }
        if ($bits > 0) {
            $encoded .= $chars[($buffer << (5 - $bits)) & 0x1F];
        }
        return $encoded;
    }

    /**
     * Decode a base32 string (case-insensitive) to binary.
     */
    private static function base32_decode(string $data): string
    {
        static $map = null;
        if ($map === null) {
            $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
            $map   = [];
            for ($i = 0; $i < 32; $i++) {
                $map[$chars[$i]] = $i;
            }
        }

        $data    = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $data));
        $decoded = '';
        $buffer  = 0;
        $bits    = 0;
        $n       = strlen($data);

        for ($i = 0; $i < $n; $i++) {
            $ch = $data[$i];
            if (!isset($map[$ch])) continue;
            $buffer = ($buffer << 5) | $map[$ch];
            $bits  += 5;
            if ($bits >= 8) {
                $bits   -= 8;
                $decoded .= chr(($buffer >> $bits) & 0xFF);
            }
        }
        return $decoded;
    }
}
