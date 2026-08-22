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

require_once __DIR__ . '/invoices.php';   // invoices_config(), invoice_get(), stałe statusów

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

// ── FA(3): generowanie XML faktury ───────────────────────────────────────────

/** Schemat FA(3) dostarczony z paczką klienta KSeF — do walidacji lokalnej. */
const KSEF_FA_XSD = __DIR__ . '/../vendor/n1ebieski/ksef-php-client/resources/xsd/faktura/schemat.xsd';

/** Przestrzeń nazw wzoru FA(3). */
const KSEF_FA_NS = 'http://crd.gov.pl/wzor/2025/06/25/13775/';

/**
 * Stawka VAT z faktury → wartość dopuszczona przez schemat (TStawkaPodatku).
 *
 * Schemat zna: 23, 22, 8, 7, 5, 4, 3, „0 KR", „0 WDT", „0 EX", „zw".
 * Samo „0" jest niedopuszczalne — trzeba wskazać rodzaj zera. „np" (nie podlega)
 * nie ma odpowiednika w tym polu i wymaga innego potraktowania faktury, dlatego
 * zwracamy null i przerywamy wysyłkę z jasnym komunikatem.
 */
function ksef_vat_rate(string $rate): ?string
{
    $r = strtolower(trim($rate));
    if (in_array($r, ['23', '22', '8', '7', '5', '4', '3'], true)) return $r;
    if ($r === 'zw') return 'zw';
    if ($r === '0' || $r === '0 kr') return '0 KR';
    if ($r === '0 wdt') return '0 WDT';
    if ($r === '0 ex')  return '0 EX';
    return null;
}

/**
 * Buduje XML faktury w strukturze FA(3).
 *
 * Obsługiwany zakres: faktura sprzedaży (RodzajFaktury=VAT), sprzedawca krajowy,
 * nabywca krajowy z NIP albo osoba bez identyfikatora (BrakID), stawki liczbowe
 * i zwolnienie. Korekty, zaliczki, waluty obce z kursem i procedury szczególne
 * nie są tu obsługiwane — wymagają dodatkowych bloków schematu.
 *
 * @throws RuntimeException gdy dane faktury nie dają się odwzorować w FA(3).
 */
function ksef_invoice_xml(array $inv): string
{
    $cfg = ksef_config();
    if (strlen($cfg['nip']) !== 10) {
        throw new RuntimeException('Brak poprawnego NIP-u sprzedawcy w konfiguracji.');
    }

    $seller_name = org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : '');
    $seller_addr = trim(preg_replace('/\s*\R\s*/u', ', ', (string)org_setting('org_adres')) ?? '');
    if ($seller_name === '' || $seller_addr === '') {
        throw new RuntimeException('Uzupełnij nazwę i adres organizacji w ustawieniach — KSeF ich wymaga.');
    }

    // Sumy per stawka — schemat oczekuje podstaw i kwot podatku w osobnych polach.
    $by_rate = [];
    foreach (($inv['items'] ?? []) as $it) {
        $fa_rate = ksef_vat_rate((string)$it['vat_rate']);
        if ($fa_rate === null) {
            throw new RuntimeException('Stawka „' . $it['vat_rate'] . '" nie ma odpowiednika w schemacie FA(3).');
        }
        $by_rate[$fa_rate] ??= ['net' => 0.0, 'vat' => 0.0];
        $by_rate[$fa_rate]['net'] += (float)$it['line_net'];
        $by_rate[$fa_rate]['vat'] += (float)$it['line_vat'];
    }
    if (!$by_rate) throw new RuntimeException('Faktura bez pozycji.');

    $d = new DOMDocument('1.0', 'UTF-8');
    $d->formatOutput = false;
    $root = $d->createElementNS(KSEF_FA_NS, 'Faktura');
    $d->appendChild($root);

    $el = static function (DOMElement $p, string $n, ?string $v = null) use ($d): DOMElement {
        $e = $d->createElementNS(KSEF_FA_NS, $n);
        if ($v !== null && $v !== '') $e->appendChild($d->createTextNode($v));
        $p->appendChild($e);
        return $e;
    };
    $money = static fn(float $v) => number_format($v, 2, '.', '');

    // ── Nagłówek ──
    $h  = $el($root, 'Naglowek');
    $kf = $el($h, 'KodFormularza', KSEF_FORM_CODE['value']);
    $kf->setAttribute('kodSystemowy', KSEF_FORM_CODE['system']);
    $kf->setAttribute('wersjaSchemy', KSEF_FORM_CODE['schema']);
    $el($h, 'WariantFormularza', '3');
    $el($h, 'DataWytworzeniaFa', gmdate('Y-m-d\TH:i:s\Z'));
    $el($h, 'SystemInfo', 'SZO');

    // ── Sprzedawca ──
    $p1 = $el($root, 'Podmiot1');
    $di = $el($p1, 'DaneIdentyfikacyjne');
    $el($di, 'NIP',   $cfg['nip']);
    $el($di, 'Nazwa', mb_substr($seller_name, 0, 512));
    $a1 = $el($p1, 'Adres');
    $el($a1, 'KodKraju', 'PL');
    $el($a1, 'AdresL1',  mb_substr($seller_addr, 0, 512));

    // ── Nabywca ──
    $buyer_nip  = preg_replace('/\D+/', '', (string)($inv['buyer_tax_no'] ?? '')) ?? '';
    $buyer_addr = trim(trim((string)($inv['buyer_street'] ?? '')) . ', '
                     . trim(trim((string)($inv['buyer_post_code'] ?? '')) . ' ' . trim((string)($inv['buyer_city'] ?? ''))), ' ,');

    $p2  = $el($root, 'Podmiot2');
    $di2 = $el($p2, 'DaneIdentyfikacyjne');
    if (strlen($buyer_nip) === 10) $el($di2, 'NIP', $buyer_nip);
    else                           $el($di2, 'BrakID', '1');   // osoba fizyczna bez NIP
    $el($di2, 'Nazwa', mb_substr((string)$inv['buyer_name'], 0, 512));
    if ($buyer_addr !== '') {
        $a2 = $el($p2, 'Adres');
        $el($a2, 'KodKraju', 'PL');
        $el($a2, 'AdresL1',  mb_substr($buyer_addr, 0, 512));
    }
    // JST i GV są w schemacie WYMAGANE (brak minOccurs): 2 = nie dotyczy.
    $el($p2, 'JST', '2');
    $el($p2, 'GV',  '2');

    // ── Treść ──
    $fa = $el($root, 'Fa');
    $el($fa, 'KodWaluty', (string)($inv['currency'] ?: 'PLN'));
    $el($fa, 'P_1', (string)$inv['issue_date']);
    $el($fa, 'P_2', (string)$inv['number']);

    // Podstawy i podatek per stawka — numeracja pól narzucona przez schemat.
    // Mapowanie stawek na pola podstaw i podatku. UWAGA: P_13_4 to ryczałt dla
    // taksówek osobowych, NIE stawka 0% — dla zera krajowego właściwe jest
    // P_13_6_1 („stawka 0% z wyłączeniem WDT i eksportu"), a dla zwolnienia P_13_7.
    $slots = [
        '23'    => ['P_13_1',   'P_14_1'],
        '8'     => ['P_13_2',   'P_14_2'],
        '5'     => ['P_13_3',   'P_14_3'],
        '0 KR'  => ['P_13_6_1', null],
        'zw'    => ['P_13_7',   null],
    ];
    foreach ($by_rate as $rate => $sum) {
        if (!isset($slots[$rate])) {
            throw new RuntimeException('Stawka ' . $rate . ' wymaga pola, którego generator jeszcze nie obsługuje.');
        }
        [$net_f, $vat_f] = $slots[$rate];
        $el($fa, $net_f, $money($sum['net']));
        if ($vat_f !== null) $el($fa, $vat_f, $money($sum['vat']));
    }

    $el($fa, 'P_15', $money((float)$inv['total_gross']));

    $an = $el($fa, 'Adnotacje');
    $el($an, 'P_16',  '2');   // metoda kasowa — nie
    $el($an, 'P_17',  '2');   // samofakturowanie — nie
    $el($an, 'P_18',  '2');   // odwrotne obciążenie — nie
    $el($an, 'P_18A', '2');   // mechanizm podzielonej płatności — nie
    // Zwolnienie: gdy jakakolwiek pozycja ma stawkę „zw", deklarujemy zwolnienie
    // i WSKAZUJEMY PODSTAWĘ PRAWNĄ (P_19A) — schemat wprost wymienia art. 113
    // ust. 1 jako taki przypadek. Podstawa pochodzi z ustawień modułu Faktury,
    // więc dokument w KSeF mówi to samo co nasz wydruk.
    $zw = $el($an, 'Zwolnienie');
    if (isset($by_rate['zw'])) {
        $el($zw, 'P_19', '1');
        $basis = trim((string)(invoices_config()['zw_basis'] ?? ''));
        $el($zw, 'P_19A', mb_substr($basis !== '' ? $basis : 'art. 113 ust. 1 ustawy o podatku od towarów i usług', 0, 256));
    } else {
        $el($zw, 'P_19N', '1');
    }
    $nt = $el($an, 'NoweSrodkiTransportu');
    $el($nt, 'P_22N', '1');
    $el($an, 'P_23', '2');    // art. 129 ustawy — nie
    $pm = $el($an, 'PMarzy');
    $el($pm, 'P_PMarzyN', '1');

    $el($fa, 'RodzajFaktury', 'VAT');

    foreach (($inv['items'] ?? []) as $i => $it) {
        $w = $el($fa, 'FaWiersz');
        $el($w, 'NrWierszaFa', (string)($i + 1));
        $el($w, 'P_7',  mb_substr((string)$it['name'], 0, 512));
        $el($w, 'P_8A', mb_substr((string)$it['unit'], 0, 256));
        $el($w, 'P_8B', rtrim(rtrim(number_format((float)$it['qty'], 6, '.', ''), '0'), '.'));
        $el($w, 'P_9A', $money((float)$it['unit_net']));
        $el($w, 'P_11', $money((float)$it['line_net']));
        $el($w, 'P_12', (string)ksef_vat_rate((string)$it['vat_rate']));
    }

    return (string)$d->saveXML();
}

/**
 * Waliduje XML względem schematu FA(3) dostarczonego z paczką klienta KSeF.
 *
 * Walidujemy PRZED wysłaniem — odrzucenie po stronie KSeF jest wolniejsze,
 * mniej czytelne i zużywa limity API.
 *
 * @return array{ok:bool,errors:list<string>}
 */
function ksef_validate_xml(string $xml): array
{
    if (!is_file(KSEF_FA_XSD)) {
        return ['ok' => false, 'errors' => ['Brak schematu FA(3) — sprawdź instalację paczki n1ebieski/ksef-php-client.']];
    }

    $prev = libxml_use_internal_errors(true);
    libxml_clear_errors();

    $doc = new DOMDocument();
    $ok  = $doc->loadXML($xml) && $doc->schemaValidate(KSEF_FA_XSD);

    $errors = [];
    foreach (libxml_get_errors() as $e) $errors[] = trim($e->message);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    return ['ok' => (bool)$ok, 'errors' => $errors];
}

// ── Wysyłka faktury do KSeF ──────────────────────────────────────────────────

/**
 * Wysyła fakturę do KSeF w sesji interaktywnej.
 *
 * Przebieg: walidacja XML lokalnie → uwierzytelnienie tokenem → openOnline
 * (klucz AES-256 zaszyfrowany kluczem MF) → sendOnline (XML zaszyfrowany
 * AES-256-CBC) → polling statusu faktury → numer KSeF → closeOnline.
 *
 * Walidujemy PRZED wysłaniem, bo odrzucenie po stronie KSeF jest wolniejsze,
 * mniej czytelne i zużywa limity API.
 *
 * @return array{ok:bool,error?:string,ksef_number?:string,reference?:string,session?:string}
 */
function ksef_send_invoice(int $invoice_id): array
{
    $inv = invoice_get($invoice_id);
    if (!$inv) return ['ok' => false, 'error' => 'Nie znaleziono faktury.'];

    if (invoice_is_test($inv)) {
        return ['ok' => false, 'error' => 'Faktura testowa nie jest wysyłana do KSeF.'];
    }
    if (!empty($inv['ksef_number'])) {
        return ['ok' => false, 'error' => 'Ta faktura jest już w KSeF (numer ' . $inv['ksef_number'] . ').'];
    }
    if (empty($inv['number'])) {
        return ['ok' => false, 'error' => 'Faktura nie ma numeru — najpierw ją wystaw.'];
    }

    // 1. XML + walidacja lokalna
    try {
        $xml = ksef_invoice_xml($inv);
    } catch (\Throwable $e) {
        return ['ok' => false, 'error' => 'Nie udało się zbudować FA(3): ' . $e->getMessage()];
    }
    $v = ksef_validate_xml($xml);
    if (!$v['ok']) {
        return ['ok' => false, 'error' => 'XML nie przechodzi walidacji schematu: ' . ($v['errors'][0] ?? 'nieznany błąd')];
    }

    // 2. Uwierzytelnienie
    $auth = ksef_authenticate();
    if (empty($auth['ok'])) return ['ok' => false, 'error' => (string)$auth['error']];

    $pem = ksef_public_key();
    if ($pem === '') return ['ok' => false, 'error' => 'Brak klucza publicznego MF.'];

    $session_ref = null;
    try {
        $sdk = ksef_client($auth['token']);

        // 3. Sesja: klucz symetryczny szyfrowany kluczem MF, IV jawny.
        $crypto = new \Intermedia\Ksef\Apiv2\Crypto($pem);
        $sym    = $crypto->generateSymetricKey();
        $encKey = $crypto->encryptSymmetricKey($sym['key']);

        $openReq = new \Intermedia\Ksef\Apiv2\Models\Operations\OpenOnlineSessionRequest(
            formCode: new \Intermedia\Ksef\Apiv2\Models\Operations\OpenOnlineSessionFormCode(
                systemCode:    KSEF_FORM_CODE['system'],
                schemaVersion: KSEF_FORM_CODE['schema'],
                value:         KSEF_FORM_CODE['value'],
            ),
            encryption: new \Intermedia\Ksef\Apiv2\Models\Operations\OpenOnlineSessionEncryption(
                encryptedSymmetricKey: base64_encode($encKey),
                initializationVector:  base64_encode($sym['iv']),
            ),
        );
        $open = $sdk->sessions->openOnline(request: $openReq);
        $session_ref = $open->openOnlineSessionResponse->referenceNumber ?? null;
        if (!$session_ref) return ['ok' => false, 'error' => 'KSeF nie otworzył sesji.'];

        // 4. Wysyłka: skróty i rozmiary liczone z ORYGINAŁU i z SZYFROGRAMU.
        $enc = $crypto->encryptXmlPayload($xml, $sym['key'], $sym['iv']);

        $sendBody = new \Intermedia\Ksef\Apiv2\Models\Operations\SendOnlineRequestBody(
            invoiceHash:             base64_encode(hash('sha256', $xml, true)),
            invoiceSize:             strlen($xml),
            encryptedInvoiceHash:    base64_encode(hash('sha256', $enc, true)),
            encryptedInvoiceSize:    strlen($enc),
            encryptedInvoiceContent: base64_encode($enc),
        );
        $send = $sdk->sessions->invoices->sendOnline(referenceNumber: $session_ref, requestBody: $sendBody);
        $inv_ref = $send->sendInvoiceResponse->referenceNumber ?? null;
        if (!$inv_ref) return ['ok' => false, 'error' => 'KSeF nie przyjął faktury (brak numeru referencyjnego).'];

        // 5. Polling — nadanie numeru KSeF jest asynchroniczne.
        $ksef_number = null; $code = 0; $desc = '';
        for ($i = 0; $i < 20; $i++) {
            $st = $sdk->sessions->invoices->getStatus($session_ref, $inv_ref);
            $sr = $st->sessionInvoiceStatusResponse ?? null;
            $code = (int)($sr->status->code ?? 0);
            $desc = (string)($sr->status->description ?? '');
            $ksef_number = $sr->ksefNumber ?? null;
            if ($ksef_number || $code >= 400) break;
            usleep(900000);
        }

        // 6. Sesję zamykamy zawsze — otwarta blokuje limity konta.
        try { $sdk->sessions->closeOnline($session_ref); } catch (\Throwable $e) {}

        if (!$ksef_number) {
            return ['ok' => false, 'error' => 'KSeF nie nadał numeru: ' . ($desc ?: ('kod ' . $code))];
        }

        $now = date('Y-m-d H:i:s');
        db()->prepare(
            "UPDATE invoices
                SET ksef_number=?, ksef_reference=?, ksef_session=?, status='wystawiona',
                    issued_at=COALESCE(issued_at, ?), last_error=NULL, last_sync_at=?, updated_at=?
              WHERE id=?"
        )->execute([$ksef_number, $inv_ref, $session_ref, $now, $now, $now, $invoice_id]);

        return ['ok' => true, 'ksef_number' => $ksef_number, 'reference' => $inv_ref, 'session' => $session_ref];

    } catch (\Throwable $e) {
        if ($session_ref) {
            try { ksef_client($auth['token'])->sessions->closeOnline($session_ref); } catch (\Throwable $e2) {}
        }
        db()->prepare("UPDATE invoices SET status='blad', last_error=?, updated_at=? WHERE id=?")
            ->execute([mb_substr('KSeF: ' . $e->getMessage(), 0, 500), date('Y-m-d H:i:s'), $invoice_id]);
        return ['ok' => false, 'error' => 'KSeF: ' . $e->getMessage()];
    }
}
