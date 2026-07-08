<?php
/**
 * includes/ezd_ai.php — asystent AI (Anthropic Claude) dla modułu EZD.
 *
 * Przerejestrowanie sprawy ze starego systemu klasyfikacji do Nowego JRWA:
 * na podstawie opisu sprawy (i opcjonalnie starego znaku) Claude kwalifikuje
 * sprawę do klasy JRWA, generuje adnotacje, instrukcję stanowiskową dla
 * dokumentacji papierowej oraz wiersz do protokołu przerejestrowania.
 *
 * Wywołanie przez surowe HTTP (stream_context — wzorzec projektu, jak
 * crm/api/ai_generate.php), ze structured outputs (output_config.format),
 * więc odpowiedź jest walidowanym JSON-em — bez kruchego parsowania tekstu.
 */

/** Klucz API Anthropic z ustawień (admin → Ustawienia AI). '' gdy brak. */
function ezd_ai_api_key(): string {
    return db_one("SELECT value FROM settings WHERE key_='anthropic_api_key'")['value'] ?? '';
}

/** Czy integracja AI jest skonfigurowana. */
function ezd_ai_enabled(): bool {
    return ezd_ai_api_key() !== '';
}

/** Model do użycia — z ustawień, domyślnie claude-opus-4-8. */
function ezd_ai_model(): string {
    $m = db_one("SELECT value FROM settings WHERE key_='anthropic_model'")['value'] ?? '';
    return $m !== '' ? $m : 'claude-opus-4-8';
}

/**
 * Buduje tekstowy wykaz JRWA z bazy (żywy słownik ezd_jrwa) do system promptu.
 * Hierarchia po parent_id, z kategorią archiwalną przy klasach końcowych.
 */
function ezd_ai_jrwa_catalog(): string {
    $rows = db_all("SELECT id, symbol, title, kat_arch, description, parent_id FROM ezd_jrwa ORDER BY sort_order, symbol");
    $byParent = [];
    foreach ($rows as $r) $byParent[(int)($r['parent_id'] ?? 0)][] = $r;
    $out = '';
    $walk = function (int $parent, int $depth) use (&$walk, &$out, $byParent) {
        foreach ($byParent[$parent] ?? [] as $r) {
            $line = str_repeat('  ', $depth) . $r['symbol'] . ' — ' . $r['title'];
            if (trim((string)$r['kat_arch']) !== '') $line .= ' [kat. arch. ' . $r['kat_arch'] . ']';
            if (trim((string)$r['description']) !== '') $line .= ' — ' . preg_replace('/\s+/', ' ', $r['description']);
            $out .= $line . "\n";
            $walk((int)$r['id'], $depth + 1);
        }
    };
    $walk(0, 0);
    return $out;
}

/**
 * Kwalifikuje sprawę do Nowego JRWA i generuje komplet danych przerejestrowania.
 *
 * @param string $opis        Opis merytoryczny sprawy (wymagany)
 * @param string $stary_znak  Stary znak sprawy ('' gdy brak)
 * @param string $forma       'auto' | 'papierowa' | 'elektroniczna'
 * @return array{ok:bool, error?:string, data?:array} data zawiera:
 *   kod_jrwa, kod_nazwa, kat_arch, uzasadnienie, forma, nowy_znak,
 *   adnotacja_stara, adnotacja_nowa, instrukcja (string[]), protokol_md
 */
function ezd_ai_przerejestruj(string $opis, string $stary_znak = '', string $forma = 'auto'): array {
    $api_key = ezd_ai_api_key();
    if ($api_key === '') {
        return ['ok' => false, 'error' => 'Brak klucza Anthropic API. Skonfiguruj w: Admin → Ustawienia AI.'];
    }

    $org  = defined('ORG_NAME') ? ORG_NAME : (org_setting('org_name') ?: 'organizacja');
    $rok  = date('Y');
    $jrwa = ezd_ai_jrwa_catalog();

    $system = <<<SYSTEM
Działaj jako ekspert ds. zarządzania dokumentacją, archiwizacji oraz systemów EZD/SZO w organizacji pozarządowej ({$org}). Twoim zadaniem jest przeprowadzenie przerejestrowania sprawy ze starego systemu klasyfikacji do Nowego JRWA, ze szczególnym uwzględnieniem spraw wszczętych i prowadzonych w formie papierowej (tradycyjnej).

Oto struktura Nowego JRWA (żywy wykaz z systemu — używaj WYŁĄCZNIE tych symboli):

{$jrwa}

Zasady postępowania:
1. Analizuj istotę merytoryczną sprawy (liczy się treść i cel dokumentu, a nie osoba składająca podpis).
2. Jeśli sprawa dotyczy krótkiego, akcyjnego wsparcia wolontariuszy bez umów pisemnych, bezwzględnie wskaż klasę "142".
3. Jeśli sprawa dotyczy algorytmów, narzędzi generatywnych, instrukcji dla promptów lub integracji automatyzujących kategoryzację — przypisz ją do odpowiedniej podklasy z grupy "54".
4. Klasyfikuj do klasy KOŃCOWEJ (bez podklas), nie do grupy strukturalnej. Wyjątek: klasa "3" (projekty) — wtedy zaproponuj ręczną podklasę 3.x w polu kod_jrwa (np. "3.1") i wyjaśnij to w uzasadnieniu.
5. Jeżeli użytkownik zaznaczy lub z opisu wynika, że sprawa została wszczęta tradycyjnie (papierowo), forma = "Papierowa (tradycyjna)" i instrukcja MUSI opisywać fizyczne oznaczenie dokumentacji (skreślenie starego znaku pojedynczą linią z zachowaniem czytelności, dopisanie nowego znaku i daty, parafka). W przeciwnym razie forma = "Elektroniczna (EZD)".
6. Nowy znak sprawy buduj wg schematu: {$org}.[kod_jrwa].[kolejny numer — użyj "N"].{$rok}, np. "{$org}.142.N.{$rok}" (N zastąpi kancelaria kolejnym numerem z rejestru).
7. Instrukcja stanowiskowa: 4–7 krótkich, operacyjnych kroków dla pracownika biura, spersonalizowanych do tej konkretnej sprawy (co zrobić z koszulką/teczką, jakie dokumenty spiąć: oświadczenia, listy obecności, zgody RODO — jeśli dotyczą tej sprawy).
8. Odpowiadaj po polsku, konkretnie i operacyjnie.
SYSTEM;

    $user_msg = "Opis sprawy: " . $opis;
    $user_msg .= "\nStary znak sprawy: " . ($stary_znak !== '' ? $stary_znak : '(brak — wstaw punktor „—" w adnotacji)');
    if ($forma === 'papierowa')          $user_msg .= "\nUżytkownik zaznaczył: sprawa wszczęta i prowadzona PAPIEROWO (tradycyjnie).";
    elseif ($forma === 'elektroniczna')  $user_msg .= "\nUżytkownik zaznaczył: sprawa prowadzona ELEKTRONICZNIE (EZD).";
    else                                 $user_msg .= "\nFormę prowadzenia wydedukuj z opisu.";

    $schema = [
        'type' => 'object',
        'properties' => [
            'kod_jrwa'        => ['type' => 'string', 'description' => 'Dokładny kod klasy Nowego JRWA (np. "142", "543", "3.1")'],
            'kod_nazwa'       => ['type' => 'string', 'description' => 'Nazwa (hasło) wskazanej klasy JRWA'],
            'kat_arch'        => ['type' => 'string', 'description' => 'Kategoria archiwalna klasy (np. "B5", "A"); "" gdy nieznana'],
            'uzasadnienie'    => ['type' => 'string', 'description' => 'Jedno–dwa zdania: dlaczego ta klasa'],
            'forma'           => ['type' => 'string', 'enum' => ['Papierowa (tradycyjna)', 'Elektroniczna (EZD)']],
            'nowy_znak'       => ['type' => 'string', 'description' => 'Przykładowy nowy znak sprawy wg schematu'],
            'adnotacja_stara' => ['type' => 'string', 'description' => 'Adnotacja do naniesienia na starą sprawę'],
            'adnotacja_nowa'  => ['type' => 'string', 'description' => 'Adnotacja do naniesienia na nową sprawę'],
            'instrukcja'      => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Kroki instrukcji stanowiskowej'],
        ],
        'required' => ['kod_jrwa', 'kod_nazwa', 'kat_arch', 'uzasadnienie', 'forma', 'nowy_znak', 'adnotacja_stara', 'adnotacja_nowa', 'instrukcja'],
        'additionalProperties' => false,
    ];

    $request_body = json_encode([
        'model'      => ezd_ai_model(),
        'max_tokens' => 2048,
        'system'     => $system,
        'messages'   => [['role' => 'user', 'content' => $user_msg]],
        'output_config' => ['format' => ['type' => 'json_schema', 'schema' => $schema]],
    ], JSON_UNESCAPED_UNICODE);

    $ctx = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", [
                'Content-Type: application/json',
                'x-api-key: ' . $api_key,
                'anthropic-version: 2023-06-01',
                'Content-Length: ' . strlen($request_body),
            ]),
            'content'       => $request_body,
            'ignore_errors' => true,
            'timeout'       => 60,
        ],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);

    $resp = @file_get_contents('https://api.anthropic.com/v1/messages', false, $ctx);
    if ($resp === false) return ['ok' => false, 'error' => 'Błąd połączenia z Anthropic API.'];

    $data = json_decode($resp, true) ?? [];
    if (!empty($data['error'])) {
        return ['ok' => false, 'error' => 'Anthropic API: ' . ($data['error']['message'] ?? 'nieznany błąd')];
    }
    if (($data['stop_reason'] ?? '') === 'refusal') {
        return ['ok' => false, 'error' => 'Model odmówił przetworzenia tego opisu — zmień sformułowanie.'];
    }

    // Structured outputs: pierwszy blok text zawiera poprawny JSON wg schematu
    $text = '';
    foreach ((array)($data['content'] ?? []) as $block) {
        if (($block['type'] ?? '') === 'text') { $text = (string)$block['text']; break; }
    }
    $out = json_decode($text, true);
    if (!is_array($out) || empty($out['kod_jrwa'])) {
        return ['ok' => false, 'error' => 'API nie zwróciło poprawnej kwalifikacji. Spróbuj ponownie.'];
    }

    // Wiersz protokołu w Markdown (Lp. uzupełnia strona zapisująca)
    $out['protokol_md'] = '| {LP} | ' . ($stary_znak !== '' ? $stary_znak : '—') . ' | '
        . $out['nowy_znak'] . ' | ' . $out['forma'] . ' |';

    return ['ok' => true, 'data' => $out];
}
