<?php
declare(strict_types=1);

/**
 * modules/gosposia/lib/GosposiaClassifier.php — ocena nadawców „reklamowego syfu” przez Claude (Anthropic Messages API) z heurystyką awaryjną.
 *
 * Wejście: nadawcy już po odfiltrowaniu białej listy (te nigdy tu nie trafiają). Do AI idą tylko metadane, pogrupowane po adresie:
 * adres, nazwa, liczba wiadomości, do 5 tematów (po 120 znaków), czy jest List-Unsubscribe / List-Id, Precedence. Bez treści i załączników.
 * Paczki po BATCH nadawców w jednym żądaniu; wynik wymuszony przez output_config.format (JSON Schema), więc bez otoczek markdown.
 *
 * Klucz: settings.anthropic_api_key (ten sam co w innych funkcjach AI SZO), awaryjnie zmienna środowiskowa ANTHROPIC_API_KEY.
 * Model: settings.gosposia_model → env ANTHROPIC_GOSPOSIA_MODEL → claude-haiku-5-5. Gdy API nie odpowiada (sieć, 429, 5xx, brak klucza),
 * paczka dostaje ocenę heurystyczną (source = 'heurystyka'), więc moduł zawsze działa.
 */
final class GosposiaClassifier
{
    public const CATEGORIES = [
        'newsletter'              => 'Newsletter',
        'oferta_handlowa'         => 'Oferta handlowa',
        'powiadomienie_systemowe' => 'Powiadomienie',
        'cold_mailing'            => 'Zimny mailing',
        'wazne_korespondencja'    => 'Ważna korespondencja',
    ];
    public const DEFAULT_MODEL = 'claude-haiku-5-5';
    private const BATCH = 40;
    private const API_URL = 'https://api.anthropic.com/v1/messages';

    /** @var callable|null  (string $body, string $key): ?string — podmiana transportu w testach */
    private $transport;

    public function __construct(?callable $transport = null) { $this->transport = $transport; }

    public static function apiKey(): string
    {
        $k = '';
        try { $k = trim((string)(db_one("SELECT value FROM settings WHERE key_ = 'anthropic_api_key'")['value'] ?? '')); } catch (\Throwable) {}
        return $k !== '' ? $k : trim((string)getenv('ANTHROPIC_API_KEY'));
    }

    public static function model(): string
    {
        $m = '';
        try { $m = trim((string)(db_one("SELECT value FROM settings WHERE key_ = 'gosposia_model'")['value'] ?? '')); } catch (\Throwable) {}
        if ($m === '') $m = trim((string)getenv('ANTHROPIC_GOSPOSIA_MODEL'));
        return $m !== '' ? $m : self::DEFAULT_MODEL;
    }

    /**
     * @param list<array{email:string, name:string, count:int, subjects:list<string>, unsubscribe:bool, list_id:bool, precedence:string}> $senders
     * @return array{results: array<string, array{is_junk:bool, category:string, confidence:float, reason:string, source:string}>, ai_batches:int, fallback_batches:int}
     */
    public function classify(array $senders, ?callable $onBatch = null): array
    {
        $out = ['results' => [], 'ai_batches' => 0, 'fallback_batches' => 0];
        $key = self::apiKey();
        $total = count($senders);
        if ($onBatch) $onBatch(0, $total);
        foreach (array_chunk($senders, self::BATCH) as $batch) {
            $ai = $key !== '' || $this->transport ? $this->callClaude($batch, $key) : null;
            if ($ai === null) $out['fallback_batches']++; else $out['ai_batches']++;
            foreach ($batch as $s) {
                $e = $s['email'];
                $out['results'][$e] = $ai[$e] ?? self::heuristic($s);
            }
            if ($onBatch) $onBatch(count($out['results']), $total);
        }
        return $out;
    }

    /** @return array<string, array>|null  null = API niedostępne (wtedy heurystyka dla całej paczki) */
    private function callClaude(array $batch, string $key): ?array
    {
        $items = [];
        foreach ($batch as $s) {
            $items[] = [
                'sender_email'     => $s['email'],
                'sender_name'      => mb_substr($s['name'], 0, 80),
                'message_count'    => $s['count'],
                'sample_subjects'  => array_map(fn($t) => mb_substr((string)$t, 0, 120), array_slice($s['subjects'], 0, 5)),
                'list_unsubscribe' => $s['unsubscribe'],
                'list_id'          => $s['list_id'],
                'precedence'       => $s['precedence'],
            ];
        }
        $system = "Pomagasz posprzątać służbową skrzynkę pocztową pracownika polskiej fundacji. Dostajesz listę nadawców "
            . "z metadanymi ich wiadomości (bez treści). Dla KAŻDEGO nadawcy oceń, czy to „reklamowy syf”: newsletter, oferta handlowa, "
            . "powiadomienie marketingowe, zimny mailing sprzedażowy.\n\n"
            . "Kategorie:\n"
            . "- newsletter: cykliczna wysyłka treści lub promocji, zwykle z nagłówkiem wypisania;\n"
            . "- oferta_handlowa: rabaty, promocje, sprzedaż, kody zniżkowe;\n"
            . "- powiadomienie_systemowe: automatyczne powiadomienia serwisów i aplikacji (marketingowe albo masowe);\n"
            . "- cold_mailing: niezamówiona oferta od firmy lub handlowca pisana jak list do konkretnej osoby;\n"
            . "- wazne_korespondencja: wszystko inne, zwłaszcza pisma urzędów, faktury, umowy, sprawy kadrowe, korespondencja z ludźmi.\n\n"
            . "Zasady: is_junk = true tylko dla pierwszych czterech kategorii. Faktury, rachunki, potwierdzenia płatności, kody logowania, "
            . "zmiany haseł, alerty bezpieczeństwa i wiadomości od osób prywatnych to wazne_korespondencja (is_junk = false). "
            . "Przy wątpliwości wybierz wazne_korespondencja albo niską pewność — pomyłka kosztuje użytkownika utratę ważnego maila. "
            . "confidence to liczba od 0.0 do 1.0. reason: po polsku, najwyżej 10 słów. sender_email przepisz dokładnie z wejścia.";
        $schema = [
            'type' => 'object',
            'properties' => [
                'senders' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'sender_email' => ['type' => 'string'],
                            'is_junk'      => ['type' => 'boolean'],
                            'category'     => ['type' => 'string', 'enum' => array_keys(self::CATEGORIES)],
                            'confidence'   => ['type' => 'number'],
                            'reason'       => ['type' => 'string'],
                        ],
                        'required' => ['sender_email', 'is_junk', 'category', 'confidence', 'reason'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['senders'],
            'additionalProperties' => false,
        ];
        $body = json_encode([
            'model'         => self::model(),
            'max_tokens'    => 8000,
            'system'        => $system,
            'messages'      => [['role' => 'user', 'content' => "Nadawcy do oceny (JSON):\n" . json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]],
            // klasyfikacja: niski wysiłek wystarcza i jest najtańszy; format wymusza czysty JSON zgodny ze schematem
            'output_config' => ['effort' => 'low', 'format' => ['type' => 'json_schema', 'schema' => $schema]],
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        $resp = $this->transport ? ($this->transport)($body, $key) : $this->post($body, $key);
        if ($resp === null) return null;
        $data = json_decode($resp, true);
        if (!is_array($data) || !empty($data['error'])) {
            error_log('[gosposia] Claude API: ' . (is_array($data) ? (string)($data['error']['type'] ?? 'błąd') : 'nieczytelna odpowiedź'));
            return null;
        }
        if (($data['stop_reason'] ?? '') !== 'end_turn') { error_log('[gosposia] Claude API: stop_reason ' . (string)($data['stop_reason'] ?? '?')); return null; }
        $text = '';
        foreach ((array)($data['content'] ?? []) as $b) if (($b['type'] ?? '') === 'text') { $text = (string)$b['text']; break; }
        $json = json_decode($text, true);
        if (!is_array($json) || !isset($json['senders']) || !is_array($json['senders'])) return null;

        $known = array_flip(array_column($batch, 'email'));
        $map = [];
        foreach ($json['senders'] as $r) {
            $e = strtolower(trim((string)($r['sender_email'] ?? '')));
            if (!isset($known[$e])) continue;   // tylko adresy, o które pytaliśmy
            $cat = isset(self::CATEGORIES[$r['category'] ?? '']) ? (string)$r['category'] : 'wazne_korespondencja';
            $map[$e] = [
                'is_junk'    => (bool)($r['is_junk'] ?? false) && $cat !== 'wazne_korespondencja',
                'category'   => $cat,
                'confidence' => max(0.0, min(1.0, (float)($r['confidence'] ?? 0))),
                'reason'     => self::shortReason((string)($r['reason'] ?? '')),
                'source'     => 'ai',
            ];
        }
        return $map;
    }

    private function post(string $body, string $key): ?string
    {
        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 90,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'x-api-key: ' . $key, 'anthropic-version: 2023-06-01'],
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (!is_string($resp) || $code === 0 || $code === 429 || $code >= 500) {
            error_log('[gosposia] Claude API niedostępne (HTTP ' . $code . ') — ocena heurystyczna');
            return null;
        }
        return $resp;
    }

    /** Ocena bez AI: nagłówki list mailingowych, adres nadawcy i słowa w tematach. */
    public static function heuristic(array $s): array
    {
        $local = strtolower((string)strstr($s['email'], '@', true));
        $subjects = mb_strtolower(implode(' | ', $s['subjects']));
        $important = (bool)preg_match('/faktur|rachun|płatnoś|platnos|przelew|umow|hasł|hasl|kod (weryfik|logow|dostęp)|logowani|bezpieczeńst|security|password|invoice|receipt|wezwani|pismo|decyzj/u', $subjects);
        $promo = (bool)preg_match('/rabat|promocj|wyprzeda|zniżk|znizk|kod rabat|black friday|cyber monday|-\d{1,2} ?%|\d{1,2} ?% (taniej|zniżki|rabatu)|oferta|okazj|darmowa dostawa|newsletter|webinar|sale\b|discount|% off|deal/u', $subjects);
        $bulk = $s['unsubscribe'] || $s['list_id'] || in_array($s['precedence'], ['bulk', 'list', 'junk'], true);
        $marketingLocal = (bool)preg_match('/^(newsletter|news|marketing|promo|promocje|oferty?|offers?|sklep|shop|deals|info|hello|mailing|kampanie?)([._-]|$)/', $local);

        if ($important) return self::verdict(false, 'wazne_korespondencja', 0.5, 'Tematy wyglądają na sprawy ważne (heurystyka)');
        if ($bulk && $promo) return self::verdict(true, 'oferta_handlowa', 0.75, 'Wysyłka masowa z promocjami w tematach (heurystyka)');
        if ($bulk) return self::verdict(true, 'newsletter', 0.65, 'Wysyłka masowa z nagłówkiem wypisania (heurystyka)');
        if ($promo && $marketingLocal) return self::verdict(true, 'oferta_handlowa', 0.6, 'Adres marketingowy i promocje w tematach (heurystyka)');
        if ($promo) return self::verdict(true, 'cold_mailing', 0.4, 'Słowa sprzedażowe w tematach (heurystyka)');
        return self::verdict(false, 'wazne_korespondencja', 0.5, 'Brak cech wysyłki reklamowej (heurystyka)');
    }

    private static function verdict(bool $junk, string $cat, float $conf, string $why): array
    {
        return ['is_junk' => $junk, 'category' => $cat, 'confidence' => $conf, 'reason' => $why, 'source' => 'heurystyka'];
    }

    private static function shortReason(string $r): string
    {
        $words = preg_split('/\s+/u', trim($r)) ?: [];
        return mb_substr(implode(' ', array_slice($words, 0, 12)), 0, 120);
    }
}
