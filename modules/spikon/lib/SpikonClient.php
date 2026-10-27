<?php
/**
 * modules/spikon/lib/SpikonClient.php — klient API telefonii Spikon (dokumentacja API 1.3.1, panel.spikon.pl).
 *
 * Każda metoda to POST application/x-www-form-urlencoded na {baza}/api/{metoda} z api_key; odpowiedź domyślnie JSON
 * (api_format=0). Dokumentacja nie podaje przykładu odpowiedzi błędu, tylko listę kodów (-1…-12, -1000) i pole „desc”,
 * więc kształt błędu odczytujemy ostrożnie (extractError). Kolekcje mają klucze „prefiks-N” (np. phone-book-1) obok „count”.
 *
 * Połączenie: strumienie PHP, nie cURL (hosting z open_basedir) — z weryfikacją certyfikatu, bo idzie klucz API.
 * Klucz API nigdy nie trafia do komunikatów wyjątków ani logów.
 */

final class SpikonException extends \RuntimeException
{
    public function __construct(string $message, private int $apiCode = 0)
    {
        parent::__construct($message);
    }

    /** Kod błędu z listy Spikon (-1…-12, -1000); 0 = błąd transportu / nieczytelna odpowiedź. */
    public function apiCode(): int { return $this->apiCode; }
}

final class SpikonClient
{
    public const ERR_NO_ELEMENTS = -8;
    public const ERR_ALREADY_EXISTS = -10;
    /** Książka o id 0 jest główną książką konta — Spikon nie pozwala jej skasować. */
    public const MAIN_BOOK_ID = 0;

    private const ERROR_TEXT = [
        -1 => 'Nieznana metoda API.',
        -2 => 'Nie przesłano klucza API.',
        -3 => 'Nieznany klucz API (po wygenerowaniu nowego stary przestaje działać).',
        -4 => 'API jest wyłączone w panelu Spikon (Ustawienia i dodatki → API - ustawienia).',
        -5 => 'Adres IP serwera nie jest na białej liście Spikon (Ustawienia i dodatki → API - ustawienia).',
        -6 => 'Błędna wartość parametru.',
        -7 => 'Brakuje parametru.',
        -8 => 'Nie znaleziono elementów.',
        -9 => 'Wynik nie jest jeszcze gotowy.',
        -10 => 'Taki element już istnieje.',
        -11 => 'Operacja niedozwolona.',
        -12 => 'Błędny format danych.',
        -1000 => 'Błąd ogólny po stronie Spikon (serwis: serwis@spikon.pl, 588 808 888).',
    ];

    public function __construct(
        private string $apiKey,
        private string $base = 'https://panel.spikon.pl',
        private int $timeout = 20,
    ) {}

    /** @return array<string,mixed> zdekodowany JSON odpowiedzi */
    public function call(string $method, array $params = []): array
    {
        if ($this->apiKey === '') throw new SpikonException('Brak klucza API Spikon w ustawieniach.');
        $body = http_build_query(['api_key' => $this->apiKey, 'api_format' => 0] + $params);
        $ctx = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\nAccept: application/json\r\n",
                'content' => $body,
                'timeout' => $this->timeout,
                'ignore_errors' => true,
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $url = rtrim($this->base, '/') . '/api/' . ltrim($method, '/');
        $raw = @file_get_contents($url, false, $ctx);
        if ($raw === false) {
            $err = error_get_last()['message'] ?? '';
            $hint = stripos($err, 'certificate') !== false ? ' (certyfikat TLS — sprawdź openssl.cafile)' : '';
            throw new SpikonException('Nie udało się połączyć ze Spikon' . $hint . '.');
        }
        $j = json_decode($raw, true);
        if (!is_array($j)) throw new SpikonException('Spikon zwrócił nieczytelną odpowiedź.');
        if ($e = self::extractError($j)) throw new SpikonException($e['message'], $e['code']);
        return $j;
    }

    /** @return array{code:int,message:string}|null */
    private static function extractError(array $j): ?array
    {
        $e = $j['error'] ?? null;
        if ($e === null) return null;
        $code = 0; $name = ''; $desc = '';
        if (is_array($e)) {
            $code = (int)($e['id'] ?? $e['code'] ?? 0);
            $name = (string)($e['name'] ?? $e['message'] ?? '');
            $desc = (string)($e['desc'] ?? '');
        } else {
            $name = (string)$e;
        }
        $msg = self::ERROR_TEXT[$code] ?? ($name !== '' ? $name : 'Błąd API Spikon.');
        if ($desc !== '') $msg .= ' ' . $desc;
        return ['code' => $code, 'message' => $msg];
    }

    /** Elementy kolekcji „prefiks-N” (phone-book-1, phone-account-2…) w kolejności, bez klucza count. */
    private static function items(mixed $node, string $prefix): array
    {
        if (!is_array($node)) return [];
        $out = [];
        foreach ($node as $k => $v) {
            if (is_array($v) && str_starts_with((string)$k, $prefix)) $out[] = $v;
        }
        return $out;
    }

    /** Stan środków na koncie (może być ujemny). */
    public function balance(): float
    {
        $j = $this->call('balance/show', ['sign' => 1]);   // sign=1: kropka zamiast przecinka dziesiętnego
        return (float)($j['account']['balance'] ?? 0);
    }

    /** @return list<array{id:int,number:string,internal:string,login:string,description:string,state:string,recording:bool}> */
    public function phoneAccounts(): array
    {
        $j = $this->call('phone_account/list');
        $out = [];
        foreach (self::items($j['phone-accounts'] ?? [], 'phone-account-') as $a) {
            $out[] = [
                'id' => (int)($a['id'] ?? 0),
                'number' => (string)($a['number'] ?? ''),
                'internal' => (string)($a['internal'] ?? ''),
                'login' => (string)($a['login'] ?? ''),
                'description' => (string)($a['description'] ?? ''),
                'state' => (string)($a['state'] ?? ''),
                'recording' => ((string)($a['recording'] ?? '0')) === '1',
            ];
        }
        return $out;
    }

    /** @return list<array{id:int,name:string}> */
    public function phoneBooks(): array
    {
        $j = $this->call('phone_book/list');
        $out = [];
        foreach (self::items($j['phone-books'] ?? [], 'phone-book-') as $b) {
            $out[] = ['id' => (int)($b['id'] ?? 0), 'name' => (string)($b['name'] ?? '')];
        }
        return $out;
    }

    /** Nazwa unikalna, do 25 znaków; duplikat = SpikonException z kodem ERR_ALREADY_EXISTS. */
    public function phoneBookAdd(string $name): int
    {
        $j = $this->call('phone_book/add', ['phone_book_name' => mb_substr($name, 0, 25)]);
        return (int)($j['phone-books']['phone_book']['id'] ?? 0);
    }

    public function phoneBookDelete(int $bookId): void
    {
        if ($bookId === self::MAIN_BOOK_ID) throw new SpikonException('Głównej książki telefonicznej nie można skasować.', -11);
        $this->call('phone_book/delete', ['phone_book_id' => $bookId]);
    }

    /** @return int identyfikator wpisu — Spikon nie zwraca go przy podglądzie książki, więc zapisujemy go tu przy dodawaniu */
    public function elementAdd(int $bookId, string $name, string $number): int
    {
        $j = $this->call('phone_book_element/add', [
            'phone_book_id' => $bookId,
            'phone_book_element_name' => mb_substr($name, 0, 120),
            'phone_book_element_number' => mb_substr($number, 0, 25),
        ]);
        return (int)($j['phone-book-elements']['phone_book_element']['id'] ?? 0);
    }

    public function elementDelete(int $elementId): void
    {
        $this->call('phone_book_element/delete', ['phone_book_element_id' => $elementId]);
    }

    /** Spikon najpierw dzwoni na konto telefoniczne, po odebraniu łączy z numerem docelowym (do 25 znaków). */
    public function callInitiate(int $phoneAccountId, string $destination): string
    {
        $j = $this->call('call/initiate', [
            'phone_account_id' => $phoneAccountId,
            'destination_number' => mb_substr($destination, 0, 25),
        ]);
        return (string)($j['call']['state'] ?? '');
    }
}
