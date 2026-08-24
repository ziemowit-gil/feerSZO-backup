<?php
/**
 * crm/triage.php — segregacja napływających kontaktów przez AI.
 *
 * Jeden plik: backend (SQLite + endpointy AJAX) i frontend (Alpine.js + Tailwind).
 * Działa w dwóch trybach:
 *   • wewnątrz feerSZO — korzysta z config.php, sesji, uprawnień CRM i klucza
 *     Anthropic zapisanego w Admin → Ustawienia AI,
 *   • samodzielnie — własny plik SQLite obok skryptu, dostęp z localhost albo
 *     po kluczu z TRIAGE_ACCESS_KEY.
 *
 * ─── PODZIAŁ PRACY: ANALIZĘ ROBI AI, SIATKĘ BEZPIECZEŃSTWA ROBI KOD ────────
 *
 * KLASYFIKUJE MODEL. To on czyta nadawcę i treść i decyduje między
 * GOV_REVIEW, COMMERCIAL_SPAM i VALUABLE — łącznie z rozpoznaniem urzędów,
 * których żadna lista domen nie obejmie (samorząd, spółki komunalne,
 * instytucje kultury, sądy). Kod nie próbuje zgadywać za niego.
 *
 * Kod dokłada tylko JEDNOKIERUNKOWĄ siatkę bezpieczeństwa, bo wymaganie
 * „żadna wiadomość urzędowa nie może zostać uznana za spam" jest regułą
 * twardą, a model daje odpowiedź prawdopodobną, nie pewną:
 *
 *   1. PRZED wywołaniem — rozpoznane cechy urzędowe (domena .gov.pl,
 *      e-Doręczenia, ePUAP, przedrostek samorządowy) trafiają do promptu
 *      jako fakt, żeby model nie musiał ich odgadywać;
 *   2. PO odpowiedzi — kategoria może zostać PODNIESIONA do GOV_REVIEW
 *      (gdy zadziałała reguła twarda albo gdy sam model uznał nadawcę za
 *      urzędowego), nigdy obniżona. Każde podniesienie ląduje w notatkach
 *      audytu, więc widać, gdzie model i reguła się rozeszły.
 *
 * Siatka działa też przy awarii: błąd sieci, limit API albo nieparsowalny
 * JSON NIGDY nie dają COMMERCIAL_SPAM. Rekord idzie do przeglądu przez
 * człowieka. Automat może pomylić się na korzyść skrzynki, nigdy przeciw niej.
 */

declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════════════════
// KONFIGURACJA
// ═══════════════════════════════════════════════════════════════════════════

/** 'claude' albo 'gemini'. */
const TRIAGE_PROVIDER = 'claude';

/** Klucze API. Puste = brane z ustawień feerSZO (settings.anthropic_api_key). */
const TRIAGE_ANTHROPIC_KEY = '';
const TRIAGE_GEMINI_KEY    = '';

/**
 * Modele. Do klasyfikacji wystarcza model szybki — to zadanie decyzyjne
 * na krótkim tekście, nie generowanie. Sonnet zostaw na trudne przypadki.
 */
const TRIAGE_CLAUDE_MODEL = 'claude-haiku-4-5-20251001';
const TRIAGE_GEMINI_MODEL = 'gemini-2.0-flash';   // ustaw model, do którego masz dostęp

/** Klucz dostępu dla trybu samodzielnego (poza localhost). Puste = zablokowane. */
const TRIAGE_ACCESS_KEY = '';

// ═══════════════════════════════════════════════════════════════════════════
// BOOTSTRAP — feerSZO albo tryb samodzielny
// ═══════════════════════════════════════════════════════════════════════════

$TRIAGE_EMBEDDED = false;
$__cfg = dirname(__DIR__) . '/config.php';

if (is_file($__cfg)) {
    require_once $__cfg;
    require_once dirname(__DIR__) . '/includes/db.php';
    require_once dirname(__DIR__) . '/includes/auth.php';
    require_once dirname(__DIR__) . '/includes/functions.php';
    require_login();
    require_once dirname(__DIR__) . '/includes/crm_perms.php';
    crm_require('inbox', 'read');
    $TRIAGE_EMBEDDED = true;
    $pdo = db();
} else {
    // Tryb samodzielny. Endpoint wydaje pieniądze na API, więc nie może stać
    // otworem — localhost albo klucz w adresie.
    $remote    = $_SERVER['REMOTE_ADDR'] ?? '';
    $is_local  = in_array($remote, ['127.0.0.1', '::1'], true);
    $key_given = (string)($_GET['k'] ?? $_SERVER['HTTP_X_TRIAGE_KEY'] ?? '');
    if (!$is_local && !(TRIAGE_ACCESS_KEY !== '' && hash_equals(TRIAGE_ACCESS_KEY, $key_given))) {
        http_response_code(403);
        exit('Dostęp tylko z localhost albo z kluczem (ustaw TRIAGE_ACCESS_KEY i podaj ?k=…).');
    }
    session_start();
    $pdo = new PDO('sqlite:' . __DIR__ . '/triage.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    if (!function_exists('h')) {
        function h(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
    }
}

if (empty($_SESSION['triage_csrf'])) {
    $_SESSION['triage_csrf'] = bin2hex(random_bytes(16));
}
$CSRF = $_SESSION['triage_csrf'];

// ═══════════════════════════════════════════════════════════════════════════
// SCHEMAT
// ═══════════════════════════════════════════════════════════════════════════

/**
 * Tabela audytu. Trzymamy też surową odpowiedź modelu — bez niej nie da się
 * później dociec, czy błędna klasyfikacja to wina promptu, czy modelu.
 */
$pdo->exec("CREATE TABLE IF NOT EXISTS contacts_audit (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    created_at     TEXT    NOT NULL DEFAULT (datetime('now','localtime')),
    sender_name    TEXT    NOT NULL DEFAULT '',
    sender_email   TEXT    NOT NULL DEFAULT '',
    subject        TEXT    NOT NULL DEFAULT '',
    body           TEXT    NOT NULL DEFAULT '',
    category       TEXT    NOT NULL DEFAULT 'VALUABLE',
    is_government  INTEGER NOT NULL DEFAULT 0,
    needs_human    INTEGER NOT NULL DEFAULT 1,
    confidence     REAL    NOT NULL DEFAULT 0,
    audit_notes    TEXT    NOT NULL DEFAULT '',
    ai_status      TEXT    NOT NULL DEFAULT 'ok',
    ai_model       TEXT    NOT NULL DEFAULT '',
    ai_raw         TEXT    NOT NULL DEFAULT ''
)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_audit_cat  ON contacts_audit(category, created_at)");
$pdo->exec("CREATE INDEX IF NOT EXISTS idx_audit_gov  ON contacts_audit(is_government)");

const TRIAGE_CATEGORIES = ['GOV_REVIEW', 'COMMERCIAL_SPAM', 'VALUABLE'];

// ═══════════════════════════════════════════════════════════════════════════
// ROZPOZNANIE NADAWCY URZĘDOWEGO — deterministyczne, bez udziału AI
// ═══════════════════════════════════════════════════════════════════════════

/** Domeny i sufiksy zarezerwowane dla administracji publicznej. */
const TRIAGE_GOV_SUFFIXES = [
    '.gov.pl', '.gov', '.mil.pl', '.sejm.gov.pl', '.senat.gov.pl',
    '.edu.pl',           // uczelnie publiczne — też korespondencja formalna
    '.europa.eu', '.ec.europa.eu',
];

/**
 * Przedrostki domen samorządowych. Polski samorząd w większości NIE siedzi
 * w .gov.pl — Urząd Miasta Krakowa ma um.krakow.pl, ośrodki pomocy mops.*,
 * starostwa starostwo.*. Sam przedrostek to poszlaka, nie dowód, ale ta reguła
 * działa wyłącznie w jedną stronę: może podnieść wiadomość do przeglądu,
 * nigdy jej nie odrzuci. Fałszywy alarm kosztuje minutę, przeoczone wezwanie
 * — termin administracyjny.
 */
const TRIAGE_GOV_PREFIXES = [
    'um.', 'umig.', 'ug.', 'umg.', 'miasto.', 'gmina.', 'powiat.', 'starostwo.',
    'bip.', 'mops.', 'gops.', 'mopr.', 'pcpr.', 'pup.', 'wup.', 'sanepid.',
    'sad.', 'wsa.', 'nsa.', 'prokuratura.', 'kuratorium.', 'urzad.', 'ratusz.',
];

/** Konkretne domeny systemów państwowych i instytucji. */
const TRIAGE_GOV_DOMAINS = [
    'edoreczenia.gov.pl', 'e-doreczenia.gov.pl', 'poczta.edoreczenia.gov.pl',
    'epuap.gov.pl', 'moj.gov.pl', 'obywatel.gov.pl', 'biznes.gov.pl',
    'zus.pl', 'ezus.pl', 'platnik.zus.pl',
    'mf.gov.pl', 'podatki.gov.pl', 'e-urzad.mf.gov.pl', 'kas.gov.pl',
    'ezamowienia.gov.pl', 'uzp.gov.pl', 'platformazakupowa.pl', 'ezamawiajacy.pl',
    'nfz.gov.pl', 'gus.gov.pl', 'stat.gov.pl', 'ceidg.gov.pl', 'ms.gov.pl',
    'krs.ms.gov.pl', 'pfron.org.pl', 'niw.gov.pl', 'ngo.gov.pl',
];

/**
 * Wzorce w adresie, temacie lub treści, które same w sobie oznaczają
 * korespondencję urzędową — nawet gdy przyszła z domeny operatora.
 *
 * e-Doręczenia bywają przekazywane przez operatora wyznaczonego (Poczta
 * Polska), więc adres nadawcy nie zawsze kończy się na .gov.pl. Urzędowe
 * Poświadczenie Doręczenia liczy termin od chwili doręczenia, nie odczytania —
 * dlatego wolimy fałszywy alarm niż przeoczenie.
 */
const TRIAGE_GOV_PATTERNS = [
    'e-doręczenia', 'e-doreczenia', 'edoreczenia', 'doręczenie elektroniczne',
    'upd', 'urzędowe poświadczenie doręczenia', 'urzedowe poswiadczenie doreczenia',
    'epuap', 'e-puap', 'ePUAP2', 'skrytka epuap', 'adres do doręczeń elektronicznych',
    'ade:', 'ae:pl-', 'urząd skarbowy', 'urzad skarbowy', 'naczelnik urzędu',
    'zakład ubezpieczeń społecznych', 'zaklad ubezpieczen spolecznych',
    'postępowanie administracyjne', 'wezwanie do', 'decyzja administracyjna',
    'kpa', 'zamówienie publiczne', 'zamowienie publiczne', 'przetarg',
    'kontrola', 'wniosek o udostępnienie informacji publicznej',
];

/** Domena z adresu e-mail, małe litery, bez „www.”. */
function triage_domain(string $email): string
{
    $at = strrpos($email, '@');
    if ($at === false) return '';
    $d = strtolower(trim(substr($email, $at + 1)));
    return preg_replace('~^www\.~', '', $d) ?? $d;
}

/**
 * Czy nadawca jest urzędowy? Zwraca listę powodów (pustą, gdy nie jest).
 *
 * @return string[]
 */
function triage_gov_reasons(string $email, string $subject, string $body): array
{
    $reasons = [];
    $domain  = triage_domain($email);

    if ($domain !== '') {
        foreach (TRIAGE_GOV_SUFFIXES as $suf) {
            if (str_ends_with($domain, ltrim($suf, '.')) || str_ends_with('.' . $domain, $suf)) {
                $reasons[] = 'domena nadawcy kończy się na ' . $suf;
                break;
            }
        }
        foreach (TRIAGE_GOV_DOMAINS as $gd) {
            if ($domain === $gd || str_ends_with($domain, '.' . $gd)) {
                $reasons[] = 'domena systemu państwowego: ' . $gd;
                break;
            }
        }
        foreach (TRIAGE_GOV_PREFIXES as $pre) {
            if (str_starts_with($domain, $pre)) {
                $reasons[] = 'domena samorządowa (przedrostek „' . rtrim($pre, '.') . '”)';
                break;
            }
        }
    }

    $hay = mb_strtolower($email . ' ' . $subject . ' ' . mb_substr($body, 0, 4000));
    foreach (TRIAGE_GOV_PATTERNS as $pat) {
        if (str_contains($hay, mb_strtolower($pat))) {
            $reasons[] = 'wzorzec urzędowy w treści: „' . $pat . '”';
            if (count($reasons) >= 4) break;   // do audytu wystarczą powody, nie katalog
        }
    }
    return $reasons;
}

// ═══════════════════════════════════════════════════════════════════════════
// KLIENT AI
// ═══════════════════════════════════════════════════════════════════════════

/** Klucz API dla wybranego dostawcy — ze stałej, a w feerSZO z ustawień. */
function triage_api_key(bool $embedded): string
{
    if (TRIAGE_PROVIDER === 'gemini') return TRIAGE_GEMINI_KEY;
    if (TRIAGE_ANTHROPIC_KEY !== '')  return TRIAGE_ANTHROPIC_KEY;
    if ($embedded) {
        try { return (string)(db_one("SELECT value FROM settings WHERE key_='anthropic_api_key'")['value'] ?? ''); }
        catch (\Throwable $e) { return ''; }
    }
    return '';
}

/** Prompt systemowy — reguły biznesowe wyłożone modelowi wprost. */
function triage_system_prompt(): string
{
    return <<<PROMPT
Jesteś systemem segregacji korespondencji przychodzącej do organizacji pozarządowej w Polsce.
Twoim jedynym zadaniem jest przypisanie wiadomości do JEDNEJ z trzech kategorii.

KATEGORIE:

1. GOV_REVIEW — korespondencja urzędowa i państwowa.
   Domeny .gov.pl, systemy e-Doręczenia, ePUAP, ZUS, urzędy skarbowe, KAS,
   platformy przetargowe i zamówień publicznych, sądy, urzędy miast i gmin,
   instytucje finansujące (PFRON, NIW). Wezwania, decyzje, postanowienia,
   zawiadomienia, Urzędowe Poświadczenie Doręczenia (UPD), kontrole, przetargi.
   ZASADA BEZWZGLĘDNA: korespondencji urzędowej NIGDY nie oznaczasz jako spam.
   W razie choćby cienia wątpliwości, czy nadawca jest urzędem — wybierasz
   GOV_REVIEW. Przeoczony termin administracyjny kosztuje więcej niż zbędny
   przegląd przez człowieka.

2. COMMERCIAL_SPAM — masowa komercja bez związku z działalnością organizacji.
   Boty SEO i pozycjonowanie, oferty stron internetowych, scraping, masowe
   „zimne maile" z domen publicznych (@gmail.com, @wp.pl itp.), newslettery
   marketingowe, sprzedaż baz danych, kryptowaluty, oferty kredytowe.
   NIE wrzucaj tu: wiadomości od znanych partnerów, zapytań o współpracę
   merytoryczną ani czegokolwiek z domeny urzędowej.

3. VALUABLE — realna, merytoryczna korespondencja.
   Partnerzy, darczyńcy, beneficjenci, pracownicy, wolontariusze, media,
   konkretne zapytania o działalność organizacji, sprawy bieżące.

ODPOWIEDŹ:
Zwróć WYŁĄCZNIE obiekt JSON, bez komentarza, bez bloku ```:
{"category":"GOV_REVIEW|COMMERCIAL_SPAM|VALUABLE","confidence":0.0-1.0,"is_government":true|false,"reason":"jedno zdanie po polsku, dlaczego ta kategoria"}
PROMPT;
}

/**
 * Wyciąga obiekt JSON z odpowiedzi modelu.
 *
 * Modele lubią opakować JSON w blok ```json albo dopisać zdanie wstępu mimo
 * zakazu. Zamiast ufać, że tym razem nie dopiszą, szukamy pierwszego
 * kompletnego obiektu w tekście.
 */
function triage_extract_json(string $text): ?array
{
    $t = trim($text);
    $t = preg_replace('~^```(?:json)?\s*|\s*```$~i', '', $t) ?? $t;

    $direct = json_decode($t, true);
    if (is_array($direct)) return $direct;

    // Pierwszy zbalansowany blok { … } w tekście.
    $start = strpos($t, '{');
    if ($start === false) return null;
    $depth = 0; $in_str = false; $esc = false;
    for ($i = $start, $n = strlen($t); $i < $n; $i++) {
        $ch = $t[$i];
        if ($in_str) {
            if ($esc)            { $esc = false; continue; }
            if ($ch === '\\')    { $esc = true;  continue; }
            if ($ch === '"')     { $in_str = false; }
            continue;
        }
        if ($ch === '"') { $in_str = true; continue; }
        if ($ch === '{') $depth++;
        if ($ch === '}') {
            $depth--;
            if ($depth === 0) {
                $cand = json_decode(substr($t, $start, $i - $start + 1), true);
                return is_array($cand) ? $cand : null;
            }
        }
    }
    return null;
}

/** Wywołanie HTTP POST z JSON-em. Zwraca [ciało, błąd]. */
function triage_http_post(string $url, array $headers, string $payload): array
{
    $ctx = stream_context_create([
        'http' => [
            'method'        => 'POST',
            'header'        => implode("\r\n", array_merge($headers, [
                'Content-Type: application/json',
                'Content-Length: ' . strlen($payload),
            ])),
            'content'       => $payload,
            'ignore_errors' => true,
            'timeout'       => 30,
        ],
    ]);
    $resp = @file_get_contents($url, false, $ctx);
    if ($resp === false) return ['', 'Brak połączenia z API.'];
    return [$resp, null];
}

/**
 * Klasyfikacja przez model. Zwraca strukturę zawsze — także przy błędzie,
 * wtedy ze statusem 'error' i BEZ kategorii spamowej.
 *
 * @param string[] $gov_reasons wynik detekcji deterministycznej (wejście do promptu)
 */
function triage_classify(array $msg, array $gov_reasons, bool $embedded): array
{
    $model = TRIAGE_PROVIDER === 'gemini' ? TRIAGE_GEMINI_MODEL : TRIAGE_CLAUDE_MODEL;
    $key   = triage_api_key($embedded);

    $fail = fn(string $why) => [
        'category'   => null, 'confidence' => 0.0, 'is_government' => false,
        'reason'     => '', 'status' => 'error', 'error' => $why,
        'model'      => $model, 'raw' => '',
    ];

    if ($key === '') {
        return $fail('Brak klucza API. Uzupełnij TRIAGE_ANTHROPIC_KEY albo Admin → Ustawienia AI.');
    }

    // Wynik detekcji podajemy modelowi jako FAKT, nie pytanie — inaczej model
    // bywa „odważniejszy" od reguły twardej i zwraca spam mimo domeny .gov.pl.
    $gov_note = $gov_reasons
        ? "UWAGA — system wykrył cechy nadawcy urzędowego: " . implode('; ', $gov_reasons)
          . ". To przesądza o kategorii GOV_REVIEW."
        : "System nie wykrył cech nadawcy urzędowego, ale oceń to również samodzielnie.";

    $user = "Nadawca: {$msg['sender_name']} <{$msg['sender_email']}>\n"
          . "Temat: {$msg['subject']}\n"
          . "Treść:\n" . mb_substr($msg['body'], 0, 6000) . "\n\n"
          . $gov_note;

    if (TRIAGE_PROVIDER === 'gemini') {
        $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
             . rawurlencode($model) . ':generateContent?key=' . rawurlencode($key);
        [$resp, $err] = triage_http_post($url, [], json_encode([
            'systemInstruction' => ['parts' => [['text' => triage_system_prompt()]]],
            'contents'          => [['role' => 'user', 'parts' => [['text' => $user]]]],
            'generationConfig'  => ['temperature' => 0, 'responseMimeType' => 'application/json'],
        ], JSON_UNESCAPED_UNICODE));
        if ($err) return $fail($err);
        $data = json_decode($resp, true) ?? [];
        if (!empty($data['error'])) return $fail('Gemini: ' . ($data['error']['message'] ?? 'błąd API'));
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
    } else {
        [$resp, $err] = triage_http_post('https://api.anthropic.com/v1/messages', [
            'x-api-key: ' . $key,
            'anthropic-version: 2023-06-01',
        ], json_encode([
            // Bez `temperature` — modele Opus 4.7/4.8 i Sonnet 5 odrzucają parametry
            // próbkowania błędem 400, a temperatura 0 i tak nigdy nie gwarantowała
            // powtarzalnego wyniku. Formatu pilnuje prompt i triage_extract_json().
            'model'      => $model,
            'max_tokens' => 400,
            'system'     => triage_system_prompt(),
            'messages'   => [['role' => 'user', 'content' => $user]],
        ], JSON_UNESCAPED_UNICODE));
        if ($err) return $fail($err);
        $data = json_decode($resp, true) ?? [];
        if (!empty($data['error'])) return $fail('Anthropic: ' . ($data['error']['message'] ?? 'błąd API'));
        // Wszystkie bloki tekstowe — przy modelu z myśleniem content[0] to `thinking`.
        $text = '';
        foreach ((array)($data['content'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'text') $text .= (string)($block['text'] ?? '');
        }
        $text = trim($text);
    }

    if ($text === '') return $fail('Model nie zwrócił treści.');

    $parsed = triage_extract_json($text);
    if (!is_array($parsed) || !in_array($parsed['category'] ?? '', TRIAGE_CATEGORIES, true)) {
        $out = $fail('Odpowiedź modelu nie była poprawnym JSON-em z kategorią.');
        $out['raw'] = $text;
        return $out;
    }

    return [
        'category'      => (string)$parsed['category'],
        'confidence'    => max(0.0, min(1.0, (float)($parsed['confidence'] ?? 0))),
        'is_government' => !empty($parsed['is_government']),
        'reason'        => trim((string)($parsed['reason'] ?? '')),
        'status'        => 'ok',
        'error'         => null,
        'model'         => $model,
        'raw'           => $text,
    ];
}

// ═══════════════════════════════════════════════════════════════════════════
// ENDPOINTY AJAX
// ═══════════════════════════════════════════════════════════════════════════

$api = (string)($_GET['api'] ?? '');

if ($api !== '') {
    header('Content-Type: application/json; charset=utf-8');

    // ── Lista ──────────────────────────────────────────────────────────────
    if ($api === 'list') {
        $cat  = (string)($_GET['category'] ?? '');
        $sql  = "SELECT id, created_at, sender_name, sender_email, subject, category,
                        is_government, needs_human, confidence, audit_notes, ai_status, ai_model
                   FROM contacts_audit";
        $par  = [];
        if (in_array($cat, TRIAGE_CATEGORIES, true)) { $sql .= " WHERE category=?"; $par[] = $cat; }
        $sql .= " ORDER BY is_government DESC, created_at DESC, id DESC LIMIT 200";

        $st = $pdo->prepare($sql); $st->execute($par);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);

        $counts = ['ALL' => 0, 'GOV_REVIEW' => 0, 'COMMERCIAL_SPAM' => 0, 'VALUABLE' => 0, 'NEEDS_HUMAN' => 0];
        foreach ($pdo->query("SELECT category, COUNT(*) n FROM contacts_audit GROUP BY category") as $r) {
            $counts[$r['category']] = (int)$r['n'];
            $counts['ALL'] += (int)$r['n'];
        }
        $counts['NEEDS_HUMAN'] = (int)($pdo->query("SELECT COUNT(*) n FROM contacts_audit WHERE needs_human=1")->fetch()['n'] ?? 0);

        echo json_encode(['ok' => true, 'rows' => $rows, 'counts' => $counts], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── Klasyfikacja ───────────────────────────────────────────────────────
    if ($api === 'classify') {
        $in = json_decode(file_get_contents('php://input'), true) ?? [];

        if (!hash_equals($CSRF, (string)($in['_csrf'] ?? ''))) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'Nieprawidłowy token CSRF.']); exit;
        }

        $msg = [
            'sender_name'  => trim((string)($in['sender_name']  ?? '')),
            'sender_email' => trim((string)($in['sender_email'] ?? '')),
            'subject'      => trim((string)($in['subject']      ?? '')),
            'body'         => trim((string)($in['body']         ?? '')),
        ];
        if ($msg['sender_email'] === '' || ($msg['subject'] === '' && $msg['body'] === '')) {
            echo json_encode(['ok' => false, 'error' => 'Podaj adres nadawcy oraz temat lub treść.']); exit;
        }

        // 1. Reguła twarda PRZED modelem.
        $gov_reasons = triage_gov_reasons($msg['sender_email'], $msg['subject'], $msg['body']);

        // 2. Model.
        $ai = triage_classify($msg, $gov_reasons, $TRIAGE_EMBEDDED);

        // 3. Reguła twarda PO modelu — decyduje ona, nie model.
        $notes = [];
        if ($ai['status'] === 'ok') {
            $category = $ai['category'];
            if ($ai['reason'] !== '') $notes[] = 'AI: ' . $ai['reason'];
            // Analiza należy do modelu — także rozpoznanie urzędu, którego lista
            // domen nie obejmuje (samorząd, spółki komunalne, instytucje
            // kultury). Jeśli model uznał nadawcę za urzędowego, jego zdanie
            // podnosi kategorię, choćby kategorię zwrócił inną.
            if ($ai['is_government'] && $category !== 'GOV_REVIEW') {
                $notes[]  = 'ESKALACJA: model rozpoznał nadawcę urzędowego mimo kategorii ' . $category . '.';
                $category = 'GOV_REVIEW';
            }
        } else {
            // Awaria nie może skasować wiadomości. Bez wskazań urzędowych
            // rekord idzie do „wartościowych" — czyli tam, gdzie ktoś na niego
            // spojrzy — z podniesioną flagą przeglądu.
            $category = $gov_reasons ? 'GOV_REVIEW' : 'VALUABLE';
            $notes[]  = 'BŁĄD AI: ' . $ai['error'] . ' — klasyfikacja zachowawcza, wymaga człowieka.';
        }

        if ($gov_reasons) {
            if ($category !== 'GOV_REVIEW') {
                $notes[] = 'NADPISANIE REGUŁĄ: model zwrócił ' . $category
                         . ', ale nadawca jest urzędowy — kategoria zmieniona na GOV_REVIEW.';
                $category = 'GOV_REVIEW';
            }
            $notes[] = 'Powody urzędowe: ' . implode('; ', $gov_reasons);
        }

        $is_gov      = $category === 'GOV_REVIEW' ? 1 : 0;
        // Człowiek musi spojrzeć na wszystko poza pewnym spamem: urząd z powodu
        // terminów, resztę z powodu niepewności modelu.
        $needs_human = ($category === 'COMMERCIAL_SPAM' && $ai['status'] === 'ok' && $ai['confidence'] >= 0.75) ? 0 : 1;

        $st = $pdo->prepare(
            "INSERT INTO contacts_audit
             (sender_name, sender_email, subject, body, category, is_government,
              needs_human, confidence, audit_notes, ai_status, ai_model, ai_raw)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)"
        );
        $st->execute([
            $msg['sender_name'], $msg['sender_email'], $msg['subject'], $msg['body'],
            $category, $is_gov, $needs_human, $ai['confidence'],
            implode(' ', $notes), $ai['status'], $ai['model'], mb_substr((string)$ai['raw'], 0, 4000),
        ]);

        echo json_encode([
            'ok'       => true,
            'id'       => (int)$pdo->lastInsertId(),
            'category' => $category,
            'warning'  => $ai['status'] === 'error' ? $ai['error'] : null,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // ── Szczegóły jednego wpisu ────────────────────────────────────────────
    if ($api === 'detail') {
        $st = $pdo->prepare("SELECT * FROM contacts_audit WHERE id=?");
        $st->execute([(int)($_GET['id'] ?? 0)]);
        echo json_encode(['ok' => true, 'row' => $st->fetch(PDO::FETCH_ASSOC) ?: null], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'Nieznany endpoint.']);
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════
// WIDOK
// ═══════════════════════════════════════════════════════════════════════════
$SELF = htmlspecialchars(strtok($_SERVER['REQUEST_URI'] ?? 'triage.php', '?'), ENT_QUOTES, 'UTF-8');
?><!DOCTYPE html>
<html lang="pl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Segregacja kontaktów — AI</title>
<script src="https://cdn.tailwindcss.com"></script>
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js"></script>
<style>[x-cloak]{display:none!important}</style>
</head>
<body class="bg-slate-100 text-slate-800">

<div x-data="triage()" x-init="load()" x-cloak class="max-w-6xl mx-auto p-4 sm:p-6">

  <header class="mb-5">
    <h1 class="text-xl font-bold text-slate-900">Segregacja kontaktów przez AI</h1>
    <p class="text-sm text-slate-500 mt-1 max-w-3xl">
      Model klasyfikuje nadawcę i treść, ale <strong>korespondencja urzędowa jest chroniona regułą
      twardą w kodzie</strong> — nadawca z domeny rządowej albo z systemu e-Doręczeń trafia do
      przeglądu niezależnie od odpowiedzi modelu. Nic nie jest kasowane automatycznie.
    </p>
  </header>

  <!-- ── Formularz testowy ─────────────────────────────────────────────── -->
  <section class="bg-white rounded-xl border border-slate-200 shadow-sm p-4 mb-5">
    <h2 class="text-sm font-semibold text-slate-700 mb-3">Nowa wiadomość do sprawdzenia</h2>

    <form @submit.prevent="submit()" class="grid gap-3 sm:grid-cols-2">
      <label class="block">
        <span class="text-xs font-medium text-slate-600">Nadawca — nazwa</span>
        <input x-model="form.sender_name" type="text" placeholder="np. Urząd Miasta Krakowa"
               class="mt-1 w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500">
      </label>
      <label class="block">
        <span class="text-xs font-medium text-slate-600">Nadawca — e-mail <span class="text-rose-600">*</span></span>
        <input x-model="form.sender_email" type="email" required placeholder="kancelaria@um.krakow.pl"
               class="mt-1 w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500">
      </label>
      <label class="block sm:col-span-2">
        <span class="text-xs font-medium text-slate-600">Temat</span>
        <input x-model="form.subject" type="text" placeholder="np. Wezwanie do uzupełnienia wniosku"
               class="mt-1 w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500">
      </label>
      <label class="block sm:col-span-2">
        <span class="text-xs font-medium text-slate-600">Treść</span>
        <textarea x-model="form.body" rows="4" placeholder="Wklej treść wiadomości…"
                  class="mt-1 w-full rounded-lg border-slate-300 text-sm shadow-sm focus:border-sky-500 focus:ring-sky-500"></textarea>
      </label>

      <div class="sm:col-span-2 flex flex-wrap items-center gap-2">
        <button type="submit" :disabled="busy"
                class="inline-flex items-center gap-2 rounded-lg bg-sky-600 px-4 py-2 text-sm font-semibold text-white
                       hover:bg-sky-700 disabled:opacity-50 disabled:cursor-not-allowed">
          <svg x-show="busy" class="animate-spin h-4 w-4" viewBox="0 0 24 24" fill="none">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
          </svg>
          <span x-text="busy ? 'Analizuję…' : 'Sprawdź i zapisz'"></span>
        </button>

        <!-- Przykłady: najszybszy sposób, żeby zobaczyć, że reguła twarda działa. -->
        <template x-for="(s, i) in samples" :key="i">
          <button type="button" @click="form = Object.assign({}, s)"
                  class="rounded-lg border border-slate-300 px-2.5 py-1.5 text-xs text-slate-600 hover:bg-slate-50"
                  x-text="s._label"></button>
        </template>
      </div>

      <p x-show="msg" x-text="msg" :class="msgOk ? 'text-emerald-700' : 'text-rose-700'"
         class="sm:col-span-2 text-sm font-medium"></p>
    </form>
  </section>

  <!-- ── Filtry ────────────────────────────────────────────────────────── -->
  <nav class="flex flex-wrap gap-2 mb-3" aria-label="Filtry kategorii">
    <template x-for="t in tabs" :key="t.key">
      <button @click="filter = t.key; load()"
              :aria-current="filter === t.key ? 'true' : 'false'"
              :class="filter === t.key
                        ? 'bg-slate-900 text-white border-slate-900'
                        : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50'"
              class="inline-flex items-center gap-2 rounded-full border px-3.5 py-1.5 text-sm font-medium transition">
        <span x-text="t.label"></span>
        <span :class="filter === t.key ? 'bg-white/20 text-white' : t.chip"
              class="rounded-full px-2 py-0.5 text-xs font-bold"
              x-text="counts[t.count] ?? 0"></span>
      </button>
    </template>
  </nav>

  <!-- ── Tabela ────────────────────────────────────────────────────────── -->
  <section class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
    <div class="overflow-x-auto">
      <table class="min-w-full text-sm">
        <caption class="sr-only">Sklasyfikowane wiadomości przychodzące</caption>
        <thead class="bg-slate-50 text-xs uppercase tracking-wide text-slate-500">
          <tr>
            <th scope="col" class="px-4 py-2.5 text-left font-semibold">Nadawca</th>
            <th scope="col" class="px-4 py-2.5 text-left font-semibold">Temat</th>
            <th scope="col" class="px-4 py-2.5 text-left font-semibold">Kategoria</th>
            <th scope="col" class="px-4 py-2.5 text-left font-semibold">Pewność</th>
            <th scope="col" class="px-4 py-2.5 text-left font-semibold">Notatki audytu</th>
            <th scope="col" class="px-4 py-2.5 text-right font-semibold">Kiedy</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-100">
          <template x-for="r in rows" :key="r.id">
            <tr :class="r.is_government == 1 ? 'bg-amber-50/60' : ''" class="align-top hover:bg-slate-50">
              <td class="px-4 py-3">
                <div class="font-semibold text-slate-900" x-text="r.sender_name || '—'"></div>
                <div class="text-xs text-slate-500 font-mono" x-text="r.sender_email"></div>
              </td>
              <td class="px-4 py-3 max-w-xs">
                <div class="truncate" x-text="r.subject || '(bez tematu)'"></div>
              </td>
              <td class="px-4 py-3 whitespace-nowrap">
                <span :class="badge(r.category)" class="rounded-full px-2.5 py-1 text-xs font-bold"
                      x-text="label(r.category)"></span>
                <div x-show="r.needs_human == 1" class="mt-1 text-[11px] font-semibold text-amber-700">
                  ⚑ wymaga człowieka
                </div>
                <div x-show="r.ai_status !== 'ok'" class="mt-1 text-[11px] font-semibold text-rose-700">
                  błąd AI — klasyfikacja zachowawcza
                </div>
              </td>
              <td class="px-4 py-3 whitespace-nowrap text-slate-600"
                  x-text="r.ai_status === 'ok' ? Math.round(r.confidence * 100) + '%' : '—'"></td>
              <td class="px-4 py-3 text-xs text-slate-500 max-w-sm" x-text="r.audit_notes || '—'"></td>
              <td class="px-4 py-3 text-right text-xs text-slate-400 whitespace-nowrap" x-text="r.created_at"></td>
            </tr>
          </template>
          <tr x-show="!rows.length">
            <td colspan="6" class="px-4 py-10 text-center text-slate-400">
              Nic tu jeszcze nie ma. Dodaj wiadomość formularzem powyżej.
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>

  <p class="mt-3 text-xs text-slate-400">
    Rekordy nie są kasowane — tabela <code>contacts_audit</code> jest rejestrem decyzji,
    także tych, w których model się pomylił i reguła go nadpisała.
  </p>
</div>

<script>
function triage() {
  return {
    csrf: <?= json_encode($CSRF) ?>,
    api:  <?= json_encode($SELF) ?>,
    rows: [], counts: {}, filter: 'ALL', busy: false, msg: '', msgOk: true,

    form: { sender_name: '', sender_email: '', subject: '', body: '' },

    tabs: [
      { key: 'ALL',             label: 'Wszystkie',           count: 'ALL',             chip: 'bg-slate-100 text-slate-600' },
      { key: 'GOV_REVIEW',      label: 'Do pilnego przejrzenia — Gov', count: 'GOV_REVIEW', chip: 'bg-amber-100 text-amber-800' },
      { key: 'COMMERCIAL_SPAM', label: 'Spam komercyjny',     count: 'COMMERCIAL_SPAM', chip: 'bg-slate-100 text-slate-600' },
      { key: 'VALUABLE',        label: 'Wartościowe',         count: 'VALUABLE',        chip: 'bg-emerald-100 text-emerald-800' },
    ],

    // Trzy przypadki graniczne, na których widać, czy reguła twarda działa.
    samples: [
      { _label: 'Przykład: e-Doręczenia', sender_name: 'Urząd Miasta Krakowa',
        sender_email: 'kancelaria@um.krakow.pl',
        subject: 'Urzędowe Poświadczenie Doręczenia — wezwanie do uzupełnienia wniosku',
        body: 'Na adres do doręczeń elektronicznych organizacji wpłynęło pismo. Termin na uzupełnienie wynosi 7 dni od doręczenia.' },
      { _label: 'Przykład: bot SEO', sender_name: 'Marketing Pro',
        sender_email: 'kontakt.seo.oferta@gmail.com',
        subject: 'Pozycjonowanie strony — pierwsza pozycja w Google w 30 dni',
        body: 'Dzień dobry, analizowałem Państwa stronę i widzę wiele błędów SEO. Oferujemy audyt gratis oraz link building. Proszę o kontakt.' },
      { _label: 'Przykład: partner', sender_name: 'Anna Nowak',
        sender_email: 'a.nowak@fundacja-partner.org.pl',
        subject: 'Podsumowanie warsztatów i faktura za salę',
        body: 'Cześć, w załączeniu podsumowanie wczorajszych warsztatów. Fakturę za wynajem sali wyślemy do końca tygodnia.' },
    ],

    label(c) {
      return { GOV_REVIEW: 'Urzędowa', COMMERCIAL_SPAM: 'Spam', VALUABLE: 'Wartościowy' }[c] || c;
    },
    badge(c) {
      return {
        GOV_REVIEW:      'bg-amber-100 text-amber-900 ring-1 ring-amber-300',
        COMMERCIAL_SPAM: 'bg-slate-200 text-slate-600',
        VALUABLE:        'bg-emerald-100 text-emerald-800',
      }[c] || 'bg-slate-100 text-slate-600';
    },

    async load() {
      const q = this.filter === 'ALL' ? '' : '&category=' + encodeURIComponent(this.filter);
      const r = await fetch(this.api + '?api=list' + q, { credentials: 'same-origin' });
      const d = await r.json();
      if (d.ok) { this.rows = d.rows; this.counts = d.counts; }
    },

    async submit() {
      this.busy = true; this.msg = '';
      try {
        const r = await fetch(this.api + '?api=classify', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          credentials: 'same-origin',
          body: JSON.stringify(Object.assign({ _csrf: this.csrf }, this.form)),
        });
        const d = await r.json();
        if (d.ok) {
          this.msgOk = !d.warning;
          this.msg = d.warning
            ? 'Zapisano do przeglądu przez człowieka — ' + d.warning
            : 'Zaklasyfikowano jako: ' + this.label(d.category);
          this.form = { sender_name: '', sender_email: '', subject: '', body: '' };
          await this.load();
        } else {
          this.msgOk = false;
          this.msg = d.error || 'Nie udało się zapisać.';
        }
      } catch (e) {
        this.msgOk = false;
        this.msg = 'Błąd połączenia z serwerem.';
      } finally {
        this.busy = false;
      }
    },
  };
}
</script>
</body>
</html>
