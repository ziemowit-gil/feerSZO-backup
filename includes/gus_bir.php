<?php
/**
 * includes/gus_bir.php — wyszukiwanie podmiotów w rejestrze REGON (GUS, API BIR 1.1).
 *
 * Po co, skoro jest Biała Lista VAT: Biała Lista zna wyłącznie podatników VAT.
 * Fundacje, stowarzyszenia, szkoły i jednostki budżetowe — czyli połowa naszych
 * kontrahentów — nie mają tam wpisu i wyszukiwanie po NIP kończy się „nie
 * znaleziono". Rejestr REGON zna wszystkich.
 *
 * Wymaga BEZPŁATNEGO klucza (GUS wydaje go od ręki): Ustawienia → `gus_bir_key`.
 * Bez klucza funkcje po prostu zwracają błąd, a import przechodzi na Białą Listę.
 *
 * Protokół to SOAP z sesją: Zaloguj → (sid w nagłówku) → DaneSzukajPodmioty.
 * Nie używamy rozszerzenia soap ani biblioteki — składamy XML i wysyłamy POST-em,
 * bo to jedno wywołanie i nie chcemy zależności w środowisku produkcyjnym.
 */

require_once __DIR__ . '/db.php';

const GUS_BIR_URL = 'https://wyszukiwarkaregon.stat.gov.pl/wsBIR/UslugaBIRzewnPubl.svc';
const GUS_BIR_NS  = 'http://CIS/BIR/PublDane/2014/07';

function gus_bir_key(): string {
    try {
        return trim((string)(db_one("SELECT value FROM settings WHERE key_='gus_bir_key'")['value'] ?? ''));
    } catch (\Throwable $e) { return ''; }
}

function gus_bir_available(): bool { return gus_bir_key() !== ''; }

/** Surowe wywołanie SOAP (zwraca ciało odpowiedzi albo null). */
function _gus_bir_call(string $action, string $body, string $sid = ''): ?string {
    $envelope =
        '<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope" '
      . 'xmlns:ns="' . GUS_BIR_NS . '" xmlns:wsa="http://www.w3.org/2005/08/addressing">'
      . '<soap:Header>'
      . '<wsa:To>' . GUS_BIR_URL . '</wsa:To>'
      . '<wsa:Action>' . GUS_BIR_NS . '/IUslugaBIRzewnPubl/' . $action . '</wsa:Action>'
      . '</soap:Header>'
      . '<soap:Body>' . $body . '</soap:Body>'
      . '</soap:Envelope>';

    $headers = "Content-Type: application/soap+xml; charset=utf-8\r\n";
    if ($sid !== '') $headers .= "sid: {$sid}\r\n";

    $ctx = stream_context_create(['http' => [
        'method'        => 'POST',
        'header'        => $headers,
        'content'       => $envelope,
        'ignore_errors' => true,
        'timeout'       => 15,
    ]]);

    $resp = @file_get_contents(GUS_BIR_URL, false, $ctx);
    return $resp === false ? null : $resp;
}

/** Identyfikator sesji BIR (ważny ok. 60 min) — trzymamy w sesji PHP. */
function gus_bir_sid(): string {
    $key = gus_bir_key();
    if ($key === '') return '';

    if (session_status() === PHP_SESSION_ACTIVE
        && !empty($_SESSION['gus_sid']) && ($_SESSION['gus_sid_exp'] ?? 0) > time()) {
        return (string)$_SESSION['gus_sid'];
    }

    $resp = _gus_bir_call('Zaloguj', '<ns:Zaloguj><ns:pKluczUzytkownika>'
        . htmlspecialchars($key, ENT_XML1) . '</ns:pKluczUzytkownika></ns:Zaloguj>');
    if ($resp === null) return '';

    if (!preg_match('#<ZalogujResult>(.*?)</ZalogujResult>#s', $resp, $m)) return '';
    $sid = trim($m[1]);
    if ($sid === '') return '';

    if (session_status() === PHP_SESSION_ACTIVE) {
        $_SESSION['gus_sid']     = $sid;
        $_SESSION['gus_sid_exp'] = time() + 45 * 60;
    }
    return $sid;
}

/**
 * Szuka podmiotu po NIP albo REGON.
 *
 * @param string $kind  'nip' | 'regon'
 * @return array ['nip','regon','krs','nazwa','adres','status','aktywna'] albo ['error'=>…]
 */
function gus_bir_lookup(string $kind, string $value): array {
    $value = preg_replace('/\D/', '', $value);
    if ($value === '') return ['error' => 'Pusty numer.'];
    if (gus_bir_key() === '') return ['error' => 'Brak klucza API GUS (BIR).'];

    $sid = gus_bir_sid();
    if ($sid === '') return ['error' => 'Nie udało się zalogować do API GUS — sprawdź klucz.'];

    $param = $kind === 'regon'
        ? (strlen($value) === 14 ? '<dat:Regon14>' . $value . '</dat:Regon14>'
                                 : '<dat:Regon>'   . $value . '</dat:Regon>')
        : '<dat:Nip>' . $value . '</dat:Nip>';

    $body = '<ns:DaneSzukajPodmioty><ns:pParametryWyszukiwania '
          . 'xmlns:dat="http://CIS/BIR/PublDane/2014/07/DataContract">'
          . $param . '</ns:pParametryWyszukiwania></ns:DaneSzukajPodmioty>';

    $resp = _gus_bir_call('DaneSzukajPodmioty', $body, $sid);
    if ($resp === null) return ['error' => 'Brak połączenia z API GUS.'];

    if (!preg_match('#<DaneSzukajPodmiotyResult>(.*?)</DaneSzukajPodmiotyResult>#s', $resp, $m)) {
        return ['error' => 'Nieoczekiwana odpowiedź API GUS.'];
    }
    $xml = html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
    if (trim($xml) === '' || !str_contains($xml, '<dane>')) {
        return ['error' => 'Nie znaleziono podmiotu w rejestrze REGON.'];
    }

    $get = static function (string $tag) use ($xml): string {
        return preg_match("#<{$tag}>(.*?)</{$tag}>#s", $xml, $mm) ? trim($mm[1]) : '';
    };

    $nazwa = $get('Nazwa');
    if ($nazwa === '') return ['error' => 'Rejestr REGON nie zwrócił nazwy podmiotu.'];

    $ulica = trim($get('Ulica'));
    $dom   = trim($get('NrNieruchomosci'));
    $lokal = trim($get('NrLokalu'));
    $kod   = trim($get('KodPocztowy'));
    $msc   = trim($get('Miejscowosc'));
    if ($kod !== '' && !str_contains($kod, '-') && strlen($kod) === 5) {
        $kod = substr($kod, 0, 2) . '-' . substr($kod, 2);
    }
    $street = trim($ulica . ($dom !== '' ? ' ' . $dom : '') . ($lokal !== '' ? '/' . $lokal : ''));
    $adres  = implode(', ', array_filter([$street, trim($kod . ' ' . $msc)]));

    return [
        'nip'     => $get('Nip'),
        'regon'   => $get('Regon'),
        'krs'     => '',
        'nazwa'   => $nazwa,
        'adres'   => $adres,
        'status'  => $get('SilosID') === '6' ? 'wykreślony' : 'w rejestrze REGON',
        'aktywna' => $get('DataZakonczeniaDzialalnosci') === '',
    ];
}
