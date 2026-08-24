<?php
/**
 * includes/org_case.php — odmiana nazwy organizacji przez przypadki.
 *
 * Ekrany logowania mają się przedstawiać po polsku: „Panel kursanta Fundacji
 * Edukacji Empatii Rozwoju FEER", a nie „Panel kursanta Fundacja Edukacji…".
 * Mianownik w takim miejscu czyta się jak automat, a nazwa organizacji jest
 * ustawieniem — nie da się jej odmienić raz na sztywno w kodzie.
 *
 * TRZY KROKI, W TEJ KOLEJNOŚCI:
 *   1. Zapisana odmiana (settings) — liczona RAZ i trzymana razem z odciskiem
 *      nazwy źródłowej, więc zmiana nazwy organizacji unieważnia ją sama.
 *   2. Model (Anthropic), gdy klucz jest skonfigurowany. Pytamy model, a nie
 *      reguły, bo reguły umieją odmienić tylko formę prawną: „Spółdzielnia
 *      Socjalna Wspólnota" wychodzi z nich jako „Spółdzielni SocjalnA Wspólnota",
 *      z przymiotnikiem w mianowniku. Koszt to jedno zapytanie na całe życie nazwy.
 *   3. Reguły — awaryjnie, gdy klucza nie ma albo model nie odpowiedział.
 *      Pierwszy wyraz to niemal zawsze forma prawna („Fundacja", „Stowarzyszenie"),
 *      a te odmieniają się przewidywalnie.
 *
 * Gdy wszystko zawiedzie, zwracamy nazwę bez zmian. Brak odmiany jest brzydki,
 * ale zła odmiana nazwy własnej organizacji jest gorsza.
 */

require_once __DIR__ . '/db.php';

/** Formy prawne, których dopełniacz znamy na pewno. */
const ORG_CASE_RULES = [
    'fundacja'        => 'Fundacji',
    'stowarzyszenie'  => 'Stowarzyszenia',
    'spółdzielnia'    => 'Spółdzielni',
    'spoldzielnia'    => 'Spółdzielni',
    'towarzystwo'     => 'Towarzystwa',
    'instytut'        => 'Instytutu',
    'centrum'         => 'Centrum',          // nieodmienne w liczbie pojedynczej
    'ośrodek'         => 'Ośrodka',
    'osrodek'         => 'Ośrodka',
    'związek'         => 'Związku',
    'zwiazek'         => 'Związku',
    'federacja'       => 'Federacji',
    'akademia'        => 'Akademii',
    'szkoła'          => 'Szkoły',
    'szkola'          => 'Szkoły',
    'przedszkole'     => 'Przedszkola',
    'poradnia'        => 'Poradni',
    'spółka'          => 'Spółki',
    'spolka'          => 'Spółki',
];

/** Odcisk nazwy — po nim poznajemy, że zapisana odmiana dotyczy innej nazwy. */
function _org_case_key(string $name): string
{
    return substr(sha1(mb_strtolower(trim($name))), 0, 12);
}

/**
 * Dopełniacz nazwy organizacji: „…Panel kursanta [Fundacji FEER]".
 *
 * @param string|null $name Nazwa w mianowniku; null = nazwa z ustawień/ORG_NAME.
 */
function org_name_genitive(?string $name = null): string
{
    $name = trim((string)($name ?? (function_exists('org_setting') ? org_setting('org_name') : '')));
    if ($name === '' && defined('ORG_NAME')) $name = (string)ORG_NAME;
    if ($name === '') return '';

    $key = _org_case_key($name);

    // 1. Zapisana odmiana
    try {
        $row = db_one("SELECT value FROM settings WHERE key_=?", ['org_name_genitive']);
        $saved = json_decode((string)($row['value'] ?? ''), true);
        if (is_array($saved) && ($saved['key'] ?? '') === $key && !empty($saved['form'])) {
            return (string)$saved['form'];
        }
    } catch (\Throwable $e) {}

    // 2. Model — odmienia całą nazwę, nie tylko formę prawną
    $form = _org_case_ai($name);
    if ($form !== '') {
        org_name_genitive_save($name, $form);
        return $form;
    }

    // 3. Reguły — awaryjnie: odmieniamy wyłącznie pierwszy wyraz
    $parts = preg_split('/\s+/', $name, 2);
    $head  = mb_strtolower(trim($parts[0] ?? '', " \t\n\r\0\x0B„”\"'"));
    if (isset(ORG_CASE_RULES[$head])) {
        $form = ORG_CASE_RULES[$head] . (isset($parts[1]) ? ' ' . $parts[1] : '');
        // Świadomie NIE zapisujemy: to forma przybliżona. Gdy klucz API się pojawi,
        // następne wywołanie ma spytać model, zamiast utrwalić półśrodek.
        return $form;
    }

    return $name;
}

/** Zapisuje odmianę razem z odciskiem nazwy źródłowej. */
function org_name_genitive_save(string $source, string $form): void
{
    try {
        db()->prepare("INSERT INTO settings (key_, value) VALUES ('org_name_genitive', ?)
                       ON CONFLICT(key_) DO UPDATE SET value=excluded.value")
            ->execute([json_encode(['key' => _org_case_key($source), 'form' => $form], JSON_UNESCAPED_UNICODE)]);
    } catch (\Throwable $e) {}
}

/**
 * Pytanie do modelu o dopełniacz.
 *
 * Wołane RAZ na nazwę — wynik jest zapisywany. Bez klucza API po prostu milczy;
 * ekran logowania nie może czekać na zewnętrzne API ani się przez nie wywalić.
 */
function _org_case_ai(string $name): string
{
    try {
        $key = trim((string)(db_one("SELECT value FROM settings WHERE key_='anthropic_api_key'")['value'] ?? ''));
    } catch (\Throwable $e) { return ''; }
    if ($key === '') return '';

    $model = '';
    try {
        $model = trim((string)(db_one("SELECT value FROM settings WHERE key_='anthropic_model'")['value'] ?? ''));
    } catch (\Throwable $e) {}
    $model = $model ?: 'claude-sonnet-4-5-20250929';

    $system = "Odmieniasz polskie nazwy własne organizacji przez przypadki.\n"
        . "Zwróć nazwę w DOPEŁNIACZU (kogo? czego?), tak by pasowała do zdania "
        . "\"Panel kursanta <nazwa>\".\n"
        . "Zasady:\n"
        . "1. Odmieniaj wyłącznie wyrazy, które się odmieniają — skróty i nazwy własne "
        . "pisane wielkimi literami (FEER, PFRON) zostaw bez zmian.\n"
        . "2. Nie dopisuj, nie skracaj i nie poprawiaj nazwy — zmienia się tylko forma gramatyczna.\n"
        . "3. Zachowaj oryginalną pisownię wielkich liter i cudzysłowy.";

    $schema = [
        'type' => 'object',
        'properties' => ['dopelniacz' => ['type' => 'string']],
        'required' => ['dopelniacz'],
        'additionalProperties' => false,
    ];

    $body = json_encode([
        'model'      => $model,
        'max_tokens' => 300,
        'system'     => $system,
        'messages'   => [['role' => 'user', 'content' => 'Mianownik: ' . $name]],
        'output_config' => ['format' => ['type' => 'json_schema', 'schema' => $schema]],
    ], JSON_UNESCAPED_UNICODE);

    $ctx = stream_context_create([
        'http' => [
            'method'  => 'POST',
            'header'  => implode("\r\n", [
                'Content-Type: application/json',
                'x-api-key: ' . $key,
                'anthropic-version: 2023-06-01',
                'Content-Length: ' . strlen($body),
            ]),
            'content'       => $body,
            'ignore_errors' => true,
            'timeout'       => 12,        // ekran logowania nie może wisieć
        ],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);

    $resp = @file_get_contents('https://api.anthropic.com/v1/messages', false, $ctx);
    if ($resp === false) return '';

    $data = json_decode($resp, true) ?? [];
    $text = '';
    foreach ((array)($data['content'] ?? []) as $b) {
        if (($b['type'] ?? '') === 'text') { $text = (string)$b['text']; break; }
    }
    $out = json_decode($text, true);
    $form = is_array($out) ? trim((string)($out['dopelniacz'] ?? '')) : '';

    // Model bywa nadgorliwy: odmiana nie może zmieniać nazwy nie do poznania
    if ($form === '' || mb_strlen($form) > mb_strlen($name) + 12) return '';
    return $form;
}

/**
 * Nagłówek ekranu logowania: „<moduł> <nazwa organizacji w dopełniaczu>".
 *
 * Jedno miejsce, żeby wszystkie wejścia do systemu przedstawiały się tak samo.
 */
function org_login_title(string $module): string
{
    $org = org_name_genitive();
    return $org === '' ? $module : $module . ' ' . $org;
}
