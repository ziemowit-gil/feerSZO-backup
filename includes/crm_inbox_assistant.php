<?php
/**
 * includes/crm_inbox_assistant.php — asystent AI dla Skrzynki CRM.
 *
 * Asystent NIE odpowiada klientowi samodzielnie. Czyta wiadomość, kwalifikuje
 * ją i przygotowuje PROPOZYCJĘ odpowiedzi, którą operator czyta, poprawia
 * i dopiero wysyła. To świadoma zmiana wobec typowego „bota obsługi klienta":
 * w organizacji, która prowadzi sprawy urzędowe i rozliczenia, wiadomość
 * wysłana w jej imieniu przez automat jest zobowiązaniem, którego nikt nie
 * przeczytał.
 *
 * Kwalifikacja i propozycja idą razem, jednym wywołaniem — operator i tak
 * patrzy na jedno i drugie naraz, a dwa wywołania to dwa razy dłużej i dwa
 * razy drożej.
 */

declare(strict_types=1);

require_once __DIR__ . '/crm.php';

/** Kategorie zgłoszeń rozpoznawane przez asystenta. */
const CRM_ASSIST_INTENTS = [
    'techniczne'  => ['label' => 'Pomoc techniczna',  'icon' => 'bi-wrench',        'color' => '#2563EB'],
    'oferta'      => ['label' => 'Zapytanie ofertowe','icon' => 'bi-file-earmark-ruled', 'color' => '#0F766E'],
    'reklamacja'  => ['label' => 'Reklamacja',        'icon' => 'bi-exclamation-octagon', 'color' => '#B42318'],
    'rozliczenia' => ['label' => 'Rozliczenia',       'icon' => 'bi-cash-coin',     'color' => '#92400E'],
    'wspolpraca'  => ['label' => 'Współpraca',        'icon' => 'bi-handshake',     'color' => '#7C3AED'],
    'urzedowe'    => ['label' => 'Sprawa urzędowa',   'icon' => 'bi-bank',          'color' => '#B45309'],
    'inne'        => ['label' => 'Inne',              'icon' => 'bi-chat-dots',     'color' => '#6B7280'],
];

/** Klucz Anthropic z ustawień. */
function crm_assist_api_key(): string
{
    try { return (string)(db_one("SELECT value FROM settings WHERE key_='anthropic_api_key'")['value'] ?? ''); }
    catch (\Throwable $e) { return ''; }
}

function crm_assist_model(): string
{
    try {
        $m = trim((string)(db_one("SELECT value FROM settings WHERE key_='anthropic_model'")['value'] ?? ''));
        return $m !== '' ? $m : 'claude-haiku-4-5-20251001';
    } catch (\Throwable $e) { return 'claude-haiku-4-5-20251001'; }
}

/**
 * Prompt systemowy asystenta.
 *
 * Nazwa organizacji podstawiana z ustawień — asystent ma mówić w imieniu tej
 * organizacji, a nie recytować placeholder z instrukcji.
 */
function crm_assist_system_prompt(): string
{
    $org = (string)(org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : 'organizacja'));

    return <<<PROMPT
Jesteś profesjonalnym, empatycznym i skutecznym asystentem AI w systemie CRM organizacji {$org}.
Twoim zadaniem jest wspieranie zespołu obsługi poprzez wstępną kwalifikację zgłoszeń,
przygotowywanie odpowiedzi na powtarzalne pytania oraz wskazywanie brakujących informacji.

### 1. Tożsamość i styl komunikacji
* Rola: reprezentant działu obsługi / koordynator wsparcia.
* Ton: profesjonalny, uprzejmy, rzeczowy, spokojny i pomocny. Bez żargonu korporacyjnego,
  prostym i zrozumiałym językiem.
* Język: zawsze w języku, w którym napisał nadawca (domyślnie polski).

### 2. Cele
1. Identyfikacja: ustal, czy piszący jest znany organizacji. Jeśli do weryfikacji brakuje
   danych, poproś o adres e-mail przypisany do sprawy albo numer sprawy — dyskretnie.
2. Diagnoza: rozpoznaj intencję zgłoszenia (pomoc techniczna, zapytanie ofertowe,
   reklamacja, rozliczenia, współpraca, sprawa urzędowa).
3. Proste sprawy: odpowiadaj wprost, krok po kroku, bez odsyłania donikąd.
4. Eskalacja: sprawy skomplikowane, reklamacje finansowe, wymagające uprawnień
   administracyjnych albo gdy nadawca wyraźnie prosi o człowieka — przygotuj zwięzłe
   podsumowanie i wskaż eskalację.

### 3. Bezpieczeństwo i prywatność (RODO)
* NIGDY nie ujawniaj danych osobowych innych osób ani wrażliwych danych systemowych.
* Jeśli nadawca przesłał hasło, numer karty płatniczej albo inne dane poufne — w propozycji
  odpowiedzi upomnij go, żeby tego nie robił, i wskaż bezpieczny kanał kontaktu.
* Nie podejmuj wiążących decyzji finansowych (zwroty, anulowanie faktur). Możesz wyłącznie
  przyjąć zgłoszenie do weryfikacji przez osoby odpowiedzialne za rozliczenia.
* Korespondencji urzędowej (.gov.pl, e-Doręczenia, ePUAP, ZUS, urząd skarbowy, przetargi)
  nigdy nie kwalifikuj jako sprawy błahej — oznacz intencję „urzedowe" i eskalację.

### 4. Formatowanie odpowiedzi dla nadawcy
* Zwięźle, bez ścian tekstu.
* Wypunktowania przy opcjach i krokach.
* Zakończ jasnym wezwaniem do działania: pytaniem pomocniczym albo prośbą o sprecyzowanie.

### 5. Sytuacje szczególne
* Za mało danych: „Dziękuję za wiadomość. Abyśmy mogli szybko pomóc, czy mógłbyś podać
  adres e-mail przypisany do sprawy albo numer zgłoszenia?"
* Eskalacja: „Twoje zgłoszenie wymaga konsultacji ze specjalistą. Przekazuję sprawę do
  zespołu [dział] — ktoś skontaktuje się z Tobą [termin]. Czy mogę pomóc w czymś jeszcze?"

### FORMAT ODPOWIEDZI
Zwróć WYŁĄCZNIE obiekt JSON, bez komentarza i bez bloku ```:
{
  "intent": "techniczne|oferta|reklamacja|rozliczenia|wspolpraca|urzedowe|inne",
  "summary": "jedno–dwa zdania: o co chodzi w zgłoszeniu",
  "escalate": true|false,
  "escalate_reason": "dlaczego wymaga człowieka; puste gdy nie wymaga",
  "missing": ["czego brakuje, żeby załatwić sprawę"],
  "sensitive": true|false,
  "reply": "gotowa treść odpowiedzi do nadawcy, w jego języku, bez nagłówka i bez podpisu"
}
PROMPT;
}

/** Wycina pierwszy kompletny obiekt JSON z odpowiedzi modelu. */
function crm_assist_json(string $text): ?array
{
    $t = trim(preg_replace('~^```(?:json)?\s*|\s*```$~i', '', trim($text)) ?? $text);
    $d = json_decode($t, true);
    if (is_array($d)) return $d;

    $start = strpos($t, '{');
    if ($start === false) return null;
    $depth = 0; $in = false; $esc = false;
    for ($i = $start, $n = strlen($t); $i < $n; $i++) {
        $ch = $t[$i];
        if ($in) {
            if ($esc)         { $esc = false; continue; }
            if ($ch === '\\') { $esc = true;  continue; }
            if ($ch === '"')  { $in = false; }
            continue;
        }
        if ($ch === '"') { $in = true; continue; }
        if ($ch === '{') $depth++;
        if ($ch === '}' && --$depth === 0) {
            $d = json_decode(substr($t, $start, $i - $start + 1), true);
            return is_array($d) ? $d : null;
        }
    }
    return null;
}

/**
 * Analizuje wiadomość ze Skrzynki i przygotowuje propozycję odpowiedzi.
 *
 * @param array $msg wiersz z crm_communications (subject, body, from_name, from_email…)
 * @return array{ok:bool,error:?string,intent:string,summary:string,escalate:bool,
 *               escalate_reason:string,missing:array,sensitive:bool,reply:string}
 */
function crm_inbox_assist(array $msg): array
{
    $fail = fn(string $why) => [
        'ok' => false, 'error' => $why, 'intent' => 'inne', 'summary' => '',
        'escalate' => true, 'escalate_reason' => 'Asystent niedostępny — obsłuż ręcznie.',
        'missing' => [], 'sensitive' => false, 'reply' => '',
    ];

    $key = crm_assist_api_key();
    if ($key === '') {
        return $fail('Brak klucza Anthropic API. Skonfiguruj w: Admin → Ustawienia AI.');
    }

    // Kontekst: kto pisze i co już o nim wiemy. Bez tego asystent pyta o rzeczy,
    // które są w kartotece — a to najszybszy sposób na zirytowanie nadawcy.
    $ctx = '';
    if (!empty($msg['contact_id'])) {
        $c = db_one("SELECT imie_nazwisko, type, status, organizacja, email, telefon FROM crm_contacts WHERE id=?",
                    [(int)$msg['contact_id']]);
        if ($c) {
            $ctx .= "Nadawca JEST w kartotece CRM: {$c['imie_nazwisko']}"
                  . ($c['organizacja'] ? " ({$c['organizacja']})" : '')
                  . ", status: {$c['status']}.\n";
            try {
                $n = (int)(db_one("SELECT COUNT(*) AS c FROM crm_communications WHERE contact_id=?",
                                  [(int)$msg['contact_id']])['c'] ?? 0);
                if ($n > 1) $ctx .= "Wcześniejszych wiadomości w historii: " . ($n - 1) . ".\n";
                $cases = db_all("SELECT title, status FROM crm_cases WHERE contact_id=? AND status IN ('open','in_progress') LIMIT 5",
                                [(int)$msg['contact_id']]);
                foreach ($cases as $cs) $ctx .= "Otwarta sprawa: {$cs['title']} ({$cs['status']}).\n";
            } catch (\Throwable $e) {}
        }
    } else {
        $ctx .= "Nadawcy NIE MA w kartotece CRM — to pierwszy kontakt albo pisze z innego adresu.\n";
    }

    $body = trim((string)($msg['body'] ?? ''));
    if ($body === '' && !empty($msg['body_html'])) $body = strip_tags((string)$msg['body_html']);

    $user = "Nadawca: " . (string)($msg['from_name'] ?? '') . " <" . (string)($msg['from_email'] ?? '') . ">\n"
          . "Temat: " . (string)($msg['subject'] ?? '') . "\n"
          . "Treść:\n" . mb_substr($body, 0, 8000) . "\n\n"
          . "Kontekst z CRM:\n" . ($ctx ?: 'brak');

    $payload = json_encode([
        'model'       => crm_assist_model(),
        'max_tokens'  => 1200,
        'temperature' => 0.2,
        'system'      => crm_assist_system_prompt(),
        'messages'    => [['role' => 'user', 'content' => $user]],
    ], JSON_UNESCAPED_UNICODE);

    $ctxOpt = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => implode("\r\n", [
            'Content-Type: application/json',
            'x-api-key: ' . $key,
            'anthropic-version: 2023-06-01',
            'Content-Length: ' . strlen($payload),
        ]),
        'content'       => $payload,
        'ignore_errors' => true,
        'timeout'       => 45,
    ]]);

    $resp = @file_get_contents('https://api.anthropic.com/v1/messages', false, $ctxOpt);
    if ($resp === false) return $fail('Brak połączenia z Anthropic API.');

    $data = json_decode($resp, true) ?? [];
    if (!empty($data['error'])) return $fail('Anthropic: ' . ($data['error']['message'] ?? 'błąd API'));

    $text = (string)($data['content'][0]['text'] ?? '');
    if ($text === '') return $fail('Model nie zwrócił treści.');

    $p = crm_assist_json($text);
    if (!is_array($p)) return $fail('Odpowiedź modelu nie była poprawnym JSON-em.');

    $intent = (string)($p['intent'] ?? 'inne');
    if (!isset(CRM_ASSIST_INTENTS[$intent])) $intent = 'inne';

    return [
        'ok'              => true,
        'error'           => null,
        'intent'          => $intent,
        'summary'         => trim((string)($p['summary'] ?? '')),
        // Sprawa urzędowa zawsze do człowieka — niezależnie od tego, co
        // odpowiedział model. Terminów administracyjnych nie pilnuje automat.
        'escalate'        => !empty($p['escalate']) || $intent === 'urzedowe',
        'escalate_reason' => trim((string)($p['escalate_reason'] ?? ''))
                             ?: ($intent === 'urzedowe' ? 'Korespondencja urzędowa — wymaga decyzji człowieka.' : ''),
        'missing'         => array_values(array_filter(array_map('strval', (array)($p['missing'] ?? [])))),
        'sensitive'       => !empty($p['sensitive']),
        'reply'           => trim((string)($p['reply'] ?? '')),
    ];
}
