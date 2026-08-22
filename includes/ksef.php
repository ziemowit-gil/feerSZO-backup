<?php
/**
 * includes/ksef.php — wystawianie faktur w KSeF (alternatywa dla Fakturowni).
 *
 * Backend modułu Faktury wybiera ustawienie `invoices_backend`:
 *   'fakturownia' (domyślnie) | 'ksef'
 *
 * Uwierzytelnianie TOKENEM KSeF (bez certyfikatu):
 *   1. challenge            → challenge + timestampMs
 *   2. „token|timestampMs"  → RSA-OAEP (SHA-256) kluczem publicznym MF
 *   3. withKsefToken        → referenceNumber + token operacji
 *   4. getStatus (polling)  → kod 200 = uwierzytelniono
 *   5. redeemToken          → accessToken (Bearer do dalszych wywołań)
 *
 * Wysyłka faktury (sesja interaktywna):
 *   openOnline (klucz AES-256 zaszyfrowany kluczem MF) → sendOnline (XML
 *   zaszyfrowany AES-256-CBC) → getStatus faktury → numer KSeF → closeOnline
 *
 * ⚠ WYMAGA WALIDACJI NA ŚRODOWISKU TESTOWYM/DEMO PRZED PRODUKCJĄ.
 * Struktura FA(3) jest tu zbudowana pod prostą fakturę krajową (sprzedawca
 * krajowy, nabywca krajowy albo osoba bez NIP, stawki liczbowe lub zwolnienie).
 * Nie jest walidowana lokalnie względem XSD Ministerstwa — pierwsze wysłanie
 * należy wykonać na DEMO i sprawdzić UPO.
 *
 * Wymaga: db.php, functions.php, invoices.php, vendor/autoload.php (SDK)
 */

declare(strict_types=1);

/** Adresy bramek KSeF per środowisko. SDK zna tylko DEMO, produkcję podajemy sami. */
const KSEF_ENVIRONMENTS = [
    'test' => ['label' => 'Testowe',     'url' => 'https://api-te.ksef.mf.gov.pl/v2'],
    'demo' => ['label' => 'Demo',        'url' => 'https://api-demo.ksef.mf.gov.pl/v2'],
    'prod' => ['label' => 'Produkcyjne', 'url' => 'https://api.ksef.mf.gov.pl/v2'],
];

/** Kod formularza faktury ustrukturyzowanej obsługiwany przez API v2. */
const KSEF_FORM_CODE = ['system' => 'FA (3)', 'schema' => '1-0E', 'value' => 'FA'];

// ── Konfiguracja ─────────────────────────────────────────────────────────────

/**
 * Ustawienia KSeF. NIP i token dziedziczymy po module Księgowości (`kdok_ksef_*`),
 * który używa tych samych poświadczeń do ODBIORU dokumentów — nie ma sensu
 * trzymać drugiego tokena dla tej samej organizacji. Klucze `ksef_*` pozwalają
 * je nadpisać, gdy wystawianie ma iść na innym koncie.
 */
function ksef_config(): array
{
    $env = trim(org_setting('ksef_env')) ?: (trim(org_setting('kdok_ksef_env')) ?: 'demo');
    if (!isset(KSEF_ENVIRONMENTS[$env])) {
        // Księgowość używa 'production' — sprowadzamy do naszego słownika.
        $env = $env === 'production' ? 'prod' : 'demo';
    }

    $nip = trim(org_setting('ksef_nip')) ?: (trim(org_setting('kdok_ksef_nip')) ?: org_setting('org_nip'));

    return [
        'env'   => $env,
        'url'   => KSEF_ENVIRONMENTS[$env]['url'],
        'nip'   => preg_replace('/\D+/', '', (string)$nip) ?? '',
        'token' => trim(org_setting('ksef_token')) ?: trim(org_setting('kdok_ksef_token')),
    ];
}

/** Czy da się wystawiać w KSeF (token + poprawny 10-cyfrowy NIP). */
function ksef_ready(): bool
{
    $c = ksef_config();
    return $c['token'] !== '' && strlen($c['nip']) === 10;
}

/** Który backend obsługuje wystawianie faktur. */
function invoices_backend(): string
{
    return trim(org_setting('invoices_backend')) === 'ksef' ? 'ksef' : 'fakturownia';
}

// ── Klient SDK ───────────────────────────────────────────────────────────────

/** Klient SDK KSeF. $bearer = null dla wywołań publicznych (challenge, klucze). */
function ksef_client(?string $bearer = null): object
{
    require_once dirname(__DIR__) . '/vendor/autoload.php';
    $cfg = ksef_config();

    return \Intermedia\Ksef\Apiv2\Client::builder()
        ->setServerUrl($cfg['url'])
        ->setSecurity($bearer ?? '')   // setSecurity wymaga stringa
        ->build();
}

/**
 * Klucz publiczny MF (PEM) do szyfrowania tokena i klucza symetrycznego.
 * Cache w settings na tydzień — endpoint nie zmienia się często.
 *
 * @return string PEM albo '' gdy nie udało się pobrać ani nie ma zapasu.
 */
function ksef_public_key(bool $force = false): string
{
    $cfg   = ksef_config();
    $k_key = 'ksef_pubkey_' . $cfg['env'];
    $k_ts  = 'ksef_pubkey_ts_' . $cfg['env'];

    $cached = trim(org_setting($k_key));
    $ts     = (int)(org_setting($k_ts) ?: 0);
    if (!$force && $cached !== '' && (time() - $ts) < 604800) return $cached;

    try {
        $res = ksef_client()->security->getPublicKeyCertificates();
        $pem = '';
        foreach ((array)($res->publicKeyCertificates ?? []) as $cert) {
            // Odpowiedź bywa tablicą albo obiektem — bierzemy pierwszy klucz w PEM.
            $val = is_array($cert)
                ? (string)($cert['publicKeyPem'] ?? $cert['certificate'] ?? '')
                : (string)($cert->publicKeyPem ?? '');
            if ($val !== '' && str_contains($val, 'BEGIN')) { $pem = $val; break; }
        }
        if ($pem !== '') {
            org_setting_set($k_key, $pem);
            org_setting_set($k_ts, (string)time());
            return $pem;
        }
    } catch (\Throwable $e) {
        // Brak sieci albo zmiana API — spadamy na cache lub klucz wpisany ręcznie.
    }

    // Zapas: ten sam klucz, którego używa moduł Księgowości.
    $manual = trim(org_setting('kdok_ksef_pubkey_manual_' . ($cfg['env'] === 'prod' ? 'production' : $cfg['env'])));
    return $cached !== '' ? $cached : $manual;
}

/** RSA-OAEP (MGF1/SHA-256) kluczem publicznym MF — użycie wspólne dla tokena i klucza AES. */
function ksef_rsa_encrypt(string $plain, string $pem): ?string
{
    require_once dirname(__DIR__) . '/vendor/autoload.php';
    try {
        $rsa = \phpseclib3\Crypt\PublicKeyLoader::load($pem)
            ->withPadding(\phpseclib3\Crypt\RSA::ENCRYPTION_OAEP)
            ->withHash('sha256')
            ->withMGFHash('sha256');
        $out = $rsa->encrypt($plain);
        return $out === false ? null : $out;
    } catch (\Throwable $e) {
        return null;
    }
}

// ── Uwierzytelnianie tokenem ─────────────────────────────────────────────────

/**
 * Uwierzytelnia się tokenem KSeF i zwraca token dostępowy (Bearer).
 *
 * @return array{ok:bool,token?:string,error?:string}
 */
function ksef_authenticate(): array
{
    if (!ksef_ready()) {
        return ['ok' => false, 'error' => 'KSeF nie jest skonfigurowany (brak tokena lub NIP-u).'];
    }
    $pem = ksef_public_key();
    if ($pem === '') {
        return ['ok' => false, 'error' => 'Brak klucza publicznego MF — nie udało się go pobrać ani nie wpisano ręcznie.'];
    }

    $cfg = ksef_config();

    try {
        $sdk = ksef_client();

        $ch  = $sdk->auth->challenge();
        $chr = $ch->authenticationChallengeResponse ?? null;
        if (!$chr) return ['ok' => false, 'error' => 'Brak odpowiedzi na inicjalizację uwierzytelnienia.'];

        // Token łączony ze znacznikiem czasu z challenge'a — kolejność i separator
        // narzuca API (token|timestampMs), timestamp w milisekundach.
        $enc = ksef_rsa_encrypt($cfg['token'] . '|' . $chr->timestampMs, $pem);
        if ($enc === null) return ['ok' => false, 'error' => 'Nie udało się zaszyfrować tokena KSeF.'];

        $req = new \Intermedia\Ksef\Apiv2\Models\Operations\AuthWithKsefTokenRequest(
            challenge: $chr->challenge,
            contextIdentifier: new \Intermedia\Ksef\Apiv2\Models\Operations\AuthWithKsefTokenContextIdentifier(
                type:  \Intermedia\Ksef\Apiv2\Models\Components\AuthenticationContextIdentifierType::Nip,
                value: $cfg['nip'],
            ),
            encryptedToken: base64_encode($enc),
        );

        $init = $sdk->auth->withKsefToken(request: $req);
        $ini  = $init->authenticationInitResponse ?? null;
        if (!$ini) return ['ok' => false, 'error' => 'KSeF nie zwrócił numeru referencyjnego uwierzytelnienia.'];

        // Dalsze wywołania idą już z tokenem operacji.
        $sdkAuth = ksef_client($ini->authenticationToken->token);

        // Uwierzytelnianie jest asynchroniczne — czekamy na kod 200.
        $code = 0; $desc = '';
        for ($i = 0; $i < 12; $i++) {
            $sr   = ($sdkAuth->auth->getStatus($ini->referenceNumber))->authenticationOperationStatusResponse ?? null;
            $code = (int)($sr->status->code ?? 0);
            $desc = (string)($sr->status->description ?? '');
            if ($code >= 200) break;
            usleep(700000);
        }
        if ($code !== 200) {
            return ['ok' => false, 'error' => 'Uwierzytelnianie nieudane: ' . ($desc ?: ('kod ' . $code))];
        }

        $tok = ($sdkAuth->auth->redeemToken())->authenticationTokensResponse->accessToken->token ?? '';
        if ($tok === '') return ['ok' => false, 'error' => 'KSeF nie wydał tokena dostępowego.'];

        return ['ok' => true, 'token' => $tok];

    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => 'KSeF: ' . $e->getMessage()];
    }
}

/** Test połączenia dla ekranu ustawień. */
function ksef_test_connection(): array
{
    $cfg = ksef_config();
    if (!ksef_ready()) return ['ok' => false, 'error' => 'Brak tokena albo NIP-u (wymagane 10 cyfr).'];
    if (ksef_public_key() === '') return ['ok' => false, 'error' => 'Nie udało się pobrać klucza publicznego MF.'];

    $a = ksef_authenticate();
    return $a['ok']
        ? ['ok' => true, 'msg' => 'Uwierzytelniono w KSeF (' . KSEF_ENVIRONMENTS[$cfg['env']]['label'] . ', NIP ' . $cfg['nip'] . ').']
        : ['ok' => false, 'error' => (string)$a['error']];
}
