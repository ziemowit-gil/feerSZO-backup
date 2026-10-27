<?php
/**
 * modules/whatsapp/lib/WhatsappHttpGateway.php — konfigurowalna bramka HTTP/JSON do wysyłki szablonów WhatsApp Business.
 *
 * Dlaczego konfigurowalna: dokumentacja techniczna WhatsApp Business API u SMSApi nie jest publiczna (dostaje się ją po weryfikacji
 * firmy przez Meta), więc adresu, nagłówka uwierzytelnienia ani kształtu JSON-a nie zgadujemy — administrator wpisuje je z dokumentacji
 * w ustawieniach. Bramka wysyła jedno żądanie POST z JSON-em zbudowanym z szablonu treści:
 *
 *   {to}  {to_plus}  {template}  {language}  {text}   — wartość tekstowa, zamieniana na zawartość literału JSON (bez cudzysłowów),
 *                                                        czyli wpisujesz je w cudzysłowach: "to": "{to}"
 *   {params_json}         — tablica parametrów szablonu jako surowy JSON, np. ["Anna","12:00"]
 *   {params_object_json}  — to samo jako obiekt {"1":"Anna","2":"12:00"}
 *
 * Sukces = HTTP 2xx oraz (gdy ustawiono ścieżkę „sukces”) niepusta wartość pod tą ścieżką w odpowiedzi JSON, np. messages.0.id.
 * Połączenie: strumienie PHP (nie cURL — hosting z open_basedir), weryfikacja TLS; zwykłe http tylko do localhost (testy na atrapie).
 * Token nie trafia do wyjątków ani logów.
 */

final class WhatsappGatewayException extends \RuntimeException {}

final class WhatsappHttpGateway
{
    /** @param array{url:string,auth_header:string,auth_prefix:string,token:string,body_template:string,ok_path:string,id_path:string} $cfg */
    public function __construct(private array $cfg) {}

    public function isConfigured(): bool
    {
        return trim($this->cfg['url']) !== '' && trim($this->cfg['body_template']) !== '';
    }

    /** Adres musi być https; http wolno wyłącznie dla localhost (token nie może iść otwartym tekstem po sieci). */
    public static function urlError(string $url): ?string
    {
        $p = parse_url($url);
        if (!$p || empty($p['scheme']) || empty($p['host'])) return 'Adres API musi być pełnym adresem URL (https://…).';
        $local = in_array(strtolower($p['host']), ['localhost', '127.0.0.1', '[::1]', '::1'], true);
        if (strtolower($p['scheme']) === 'https') return null;
        if (strtolower($p['scheme']) === 'http' && $local) return null;
        return 'Adres API musi zaczynać się od https:// (http jest dozwolone tylko dla localhost).';
    }

    /**
     * Buduje treść żądania z szablonu. Wartości tekstowe są escapowane do JSON-a w jednym przebiegu (strtr), więc tekst
     * wiadomości zawierający „{to}” nie zostanie podmieniony drugi raz. Wynik musi być poprawnym JSON-em.
     *
     * @param array{to:string,template:string,language:string,text:string} $v
     * @param list<string> $params
     */
    public static function renderBody(string $tpl, array $v, array $params): string
    {
        $esc = static fn(string $s): string => substr((string)json_encode($s, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 1, -1);
        $list = array_map('strval', array_values($params));
        $obj = [];
        foreach ($list as $i => $p) $obj[(string)($i + 1)] = $p;
        $map = [
            '{to}' => $esc($v['to']),
            '{to_plus}' => $esc('+' . $v['to']),
            '{template}' => $esc($v['template']),
            '{language}' => $esc($v['language']),
            '{text}' => $esc($v['text']),
            '{params_json}' => json_encode($list, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            '{params_object_json}' => $obj ? json_encode($obj, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '{}',
        ];
        $body = strtr($tpl, $map);
        if (json_decode($body) === null && trim($body) !== 'null') {
            throw new WhatsappGatewayException('Szablon treści żądania nie daje poprawnego JSON-a: ' . json_last_error_msg() . '.');
        }
        return $body;
    }

    /** Wartość z dekodowanego JSON-a po ścieżce z kropkami i indeksami: messages.0.id. */
    public static function jsonPath(mixed $json, string $path): mixed
    {
        foreach (explode('.', trim($path)) as $seg) {
            if ($seg === '') continue;
            if (is_array($json) && array_key_exists($seg, $json)) $json = $json[$seg];
            else return null;
        }
        return $json;
    }

    /**
     * @param list<string> $params parametry szablonu w kolejności {{1}}, {{2}}…
     * @return array{id:string}
     */
    public function send(string $to, string $template, string $language, array $params, string $text): array
    {
        if (!$this->isConfigured()) throw new WhatsappGatewayException('Bramka WhatsApp nie jest skonfigurowana (adres API i szablon treści żądania).');
        if ($e = self::urlError($this->cfg['url'])) throw new WhatsappGatewayException($e);
        $header = trim($this->cfg['auth_header']);
        if ($header !== '' && !preg_match('/^[A-Za-z0-9-]+$/', $header)) throw new WhatsappGatewayException('Nazwa nagłówka uwierzytelnienia ma niedozwolone znaki.');

        $body = self::renderBody($this->cfg['body_template'], ['to' => $to, 'template' => $template, 'language' => $language, 'text' => $text], $params);
        $headers = "Content-Type: application/json\r\nAccept: application/json\r\n";
        if ($header !== '' && $this->cfg['token'] !== '') $headers .= $header . ': ' . $this->cfg['auth_prefix'] . $this->cfg['token'] . "\r\n";

        $ctx = stream_context_create([
            'http' => ['method' => 'POST', 'header' => $headers, 'content' => $body, 'timeout' => 15, 'ignore_errors' => true],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);
        $prev = ini_set('default_socket_timeout', '8');   // strumienie HTTP nie mają osobnego limitu połączenia
        $raw = @file_get_contents($this->cfg['url'], false, $ctx);
        if ($prev !== false) ini_set('default_socket_timeout', $prev);
        if ($raw === false) {
            $hint = stripos((string)(error_get_last()['message'] ?? ''), 'certificate') !== false ? ' (certyfikat TLS — sprawdź openssl.cafile)' : '';
            throw new WhatsappGatewayException('Nie udało się połączyć z bramką WhatsApp' . $hint . '.');
        }
        $status = 0;
        foreach (($http_response_header ?? []) as $h) if (preg_match('#^HTTP/\S+\s+(\d{3})#', $h, $m)) $status = (int)$m[1];   // ostatni = po przekierowaniach
        if ($status < 200 || $status >= 300) {
            throw new WhatsappGatewayException('Bramka WhatsApp odpowiedziała HTTP ' . $status . ': ' . $this->scrub($raw));
        }
        $json = json_decode($raw, true);
        $okPath = trim($this->cfg['ok_path']);
        if ($okPath !== '') {
            $val = is_array($json) ? self::jsonPath($json, $okPath) : null;
            if ($val === null || $val === '' || $val === false || $val === []) {
                throw new WhatsappGatewayException('Bramka WhatsApp nie potwierdziła wysyłki (brak „' . $okPath . '” w odpowiedzi): ' . $this->scrub($raw));
            }
        }
        $idPath = trim($this->cfg['id_path']);
        $id = $idPath !== '' && is_array($json) ? self::jsonPath($json, $idPath) : null;
        return ['id' => is_scalar($id) ? mb_substr((string)$id, 0, 120) : ''];
    }

    /** Komunikat bez tokenu i adresów URL, do 200 znaków. */
    private function scrub(string $text): string
    {
        if ($this->cfg['token'] !== '') $text = str_replace($this->cfg['token'], '***', $text);
        $text = (string)preg_replace('~https?://[^\s)\'"]+~i', '[URL]', $text);
        return trim(mb_substr(preg_replace('/\s+/', ' ', $text), 0, 200));
    }
}
