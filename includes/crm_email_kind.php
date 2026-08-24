<?php
/**
 * includes/crm_email_kind.php — czy adres e-mail jest IMIENNY czy OGÓLNY.
 *
 * Po co: wysyłka pisana do człowieka („Szanowna Pani Anno") wygląda źle, gdy
 * trafia na biuro@ albo sekretariat@ — i odwrotnie, „Szanowni Państwo" do
 * a.kowalska@ brzmi jak masówka. Do tej pory nikt tego nie rozróżniał, więc
 * albo wszystko było bezosobowe, albo zwrot trafiał w pustkę.
 *
 * Dwa poziomy rozpoznania:
 *
 *   1. LOKALNY — wzorce, które nie wymagają niczego poza samym adresem:
 *      role z RFC 2142 (info, office, sales), polskie odpowiedniki (biuro,
 *      sekretariat, kontakt, fundacja), skrzynki funkcyjne z cyfrą albo myślnikiem
 *      w środku nazwy działu, oraz — po drugiej stronie — układy imię.nazwisko,
 *      i.nazwisko, inazwisko zestawione z nazwiskiem z kartoteki.
 *
 *   2. AI (Anthropic) — wołany TYLKO dla adresów, których wzorce nie rozstrzygają,
 *      i tylko gdy klucz jest skonfigurowany. Model dostaje sam adres i nazwę
 *      kontaktu, nigdy treści korespondencji. Wynik jest zapisywany, więc każdy
 *      adres pyta się raz.
 *
 * Rozpoznanie zapisujemy przy kartotece (crm_contacts.email_kind), bo to cecha
 * adresu, a nie jednej wysyłki — i bo inaczej każda kampania liczyłaby to od nowa.
 */

require_once __DIR__ . '/db.php';

const CRM_EMAIL_KINDS = [
    'personal' => ['label' => 'imienny', 'icon' => 'bi-person-fill',   'color' => '#2E844A'],
    'generic'  => ['label' => 'ogólny',  'icon' => 'bi-buildings-fill', 'color' => '#B45309'],
    'unknown'  => ['label' => 'nierozpoznany', 'icon' => 'bi-question-circle', 'color' => '#6B7280'],
];

/** Nazwy skrzynek funkcyjnych. RFC 2142 + to, co realnie spotyka się w Polsce. */
const CRM_EMAIL_ROLE_LOCALS = [
    // RFC 2142 i pochodne
    'info', 'postmaster', 'hostmaster', 'webmaster', 'abuse', 'noc', 'security',
    'sales', 'support', 'marketing', 'admin', 'administrator', 'office', 'contact',
    'help', 'helpdesk', 'billing', 'accounts', 'noreply', 'no-reply', 'donotreply',
    'mailer-daemon', 'newsletter', 'press', 'media', 'careers', 'jobs', 'hr',
    // polskie
    'biuro', 'sekretariat', 'kontakt', 'kancelaria', 'ksiegowosc', 'ksiegowość',
    'faktury', 'rekrutacja', 'kadry', 'zarzad', 'zarząd', 'fundacja', 'stowarzyszenie',
    'urzad', 'urząd', 'poczta', 'mail', 'skrzynka', 'bok', 'obsluga', 'obsługa',
    'reklamacje', 'zamowienia', 'zamówienia', 'sklep', 'serwis', 'pomoc', 'szkolenia',
    'projekty', 'promocja', 'wolontariat', 'darowizny', 'dotacje', 'przetargi',
    'inwestycje', 'oswiata', 'oświata', 'kultura', 'sport', 'ochrona', 'iod', 'rodo',
];

/** Samonaprawa: kolumny rozpoznania przy kartotece. */
function crm_email_kind_migrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    foreach ([
        "ALTER TABLE crm_contacts ADD COLUMN email_kind TEXT",
        "ALTER TABLE crm_contacts ADD COLUMN email_kind_why TEXT",
        "ALTER TABLE crm_contacts ADD COLUMN email_kind_at DATETIME",
    ] as $sql) {
        try { db()->exec($sql); } catch (\Throwable $e) {}
    }
}

/** Część przed małpą, bez znaków diakrytycznych i separatorów na brzegach. */
function _cek_local(string $email): string
{
    $at = strrpos($email, '@');
    $l  = $at === false ? $email : substr($email, 0, $at);
    $l  = mb_strtolower(trim($l));
    $l  = strtr($l, ['ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ź'=>'z','ż'=>'z']);
    // „jan.kowalski+kampania@" — tag po plusie nie należy do nazwy skrzynki
    if (($plus = strpos($l, '+')) !== false) $l = substr($l, 0, $plus);
    return trim($l, ' ._-');
}

/** Słowa z imienia i nazwiska kontaktu, bez diakrytyków, min. 3 znaki. */
function _cek_name_parts(?string $name): array
{
    $n = mb_strtolower(trim((string)$name));
    $n = strtr($n, ['ą'=>'a','ć'=>'c','ę'=>'e','ł'=>'l','ń'=>'n','ó'=>'o','ś'=>'s','ź'=>'z','ż'=>'z']);
    $parts = preg_split('/[^a-z0-9]+/', $n) ?: [];
    return array_values(array_filter($parts, static fn($p) => mb_strlen($p) >= 3));
}

/**
 * Rozpoznanie po samym adresie (bez AI).
 *
 * @return array{kind:string,why:string,sure:bool} sure=false → warto dopytać AI
 */
function crm_email_kind_local(string $email, ?string $contact_name = null): array
{
    $email = trim(mb_strtolower($email));
    if ($email === '' || !str_contains($email, '@')) {
        return ['kind' => 'unknown', 'why' => 'brak adresu', 'sure' => true];
    }

    $local = _cek_local($email);
    if ($local === '') return ['kind' => 'unknown', 'why' => 'pusta nazwa skrzynki', 'sure' => true];

    // 1. Dokładna nazwa funkcyjna albo funkcyjna z dopiskiem działu (biuro.gdansk)
    $head = preg_split('/[._-]/', $local)[0] ?? $local;
    if (in_array($local, CRM_EMAIL_ROLE_LOCALS, true)) {
        return ['kind' => 'generic', 'why' => 'skrzynka funkcyjna: ' . $local, 'sure' => true];
    }
    if (in_array($head, CRM_EMAIL_ROLE_LOCALS, true)) {
        return ['kind' => 'generic', 'why' => 'nazwa zaczyna się od skrzynki funkcyjnej: ' . $head, 'sure' => true];
    }

    // 2. Zgodność z nazwiskiem z kartoteki — najmocniejsza przesłanka „imienny"
    $parts = _cek_name_parts($contact_name);
    foreach ($parts as $p) {
        if (str_contains($local, $p)) {
            return ['kind' => 'personal', 'why' => 'adres zawiera człon nazwy kontaktu: ' . $p, 'sure' => true];
        }
    }

    // 3. Układ imię.nazwisko / i.nazwisko — dwa człony liter, oba sensownej długości
    if (preg_match('/^([a-z]+)[._-]([a-z]+)$/', $local, $m)) {
        $a = $m[1]; $b = $m[2];
        if ((mb_strlen($a) >= 2 && mb_strlen($b) >= 3) || (mb_strlen($a) >= 3 && mb_strlen($b) >= 2)) {
            return ['kind' => 'personal', 'why' => 'układ imię.nazwisko: ' . $local, 'sure' => true];
        }
    }

    // 4. Cyfry w nazwie i człony typu „dzial", „zespol" — raczej skrzynka wspólna
    if (preg_match('/(dzial|zespol|team|grupa|oddzial|filia|biuro|office)/', $local)) {
        return ['kind' => 'generic', 'why' => 'nazwa wskazuje na komórkę organizacji', 'sure' => true];
    }

    // 5. Jedno słowo bez kropki — może być imię (anna@) albo skrót działu (adm@).
    //    Tu wzorce się kończą; to jest właśnie materiał dla AI.
    return ['kind' => 'unknown', 'why' => 'wzorce nie rozstrzygają: ' . $local, 'sure' => false];
}

/** Czy dopytywanie modelu jest w ogóle możliwe. */
function crm_email_kind_ai_available(): bool
{
    try {
        return trim((string)(db_one("SELECT value FROM settings WHERE key_='anthropic_api_key'")['value'] ?? '')) !== '';
    } catch (\Throwable $e) { return false; }
}

/**
 * Dopytanie modelu o adresy, których wzorce nie rozstrzygnęły.
 *
 * Pytamy PACZKĄ (do 40 adresów naraz), bo osobne zapytanie na adres przy imporcie
 * kilkuset kontaktów byłoby i wolne, i kosztowne. Model dostaje wyłącznie adres
 * i nazwę kontaktu — żadnej treści korespondencji.
 *
 * @param array<int,array{email:string,name:string}> $items
 * @return array<string,array{kind:string,why:string}> klucz = adres
 */
function crm_email_kind_ai(array $items): array
{
    if (!$items || !crm_email_kind_ai_available()) return [];

    $key   = trim((string)(db_one("SELECT value FROM settings WHERE key_='anthropic_api_key'")['value'] ?? ''));
    $model = trim((string)(db_one("SELECT value FROM settings WHERE key_='anthropic_model'")['value'] ?? ''))
           ?: 'claude-sonnet-4-5-20250929';

    $lines = [];
    foreach (array_slice($items, 0, 40) as $i => $it) {
        $lines[] = ($i + 1) . '. ' . $it['email'] . ' — kontakt w bazie: ' . ($it['name'] ?: '(bez nazwy)');
    }

    $system = "Rozpoznajesz, czy adres e-mail jest IMIENNY (należy do konkretnego człowieka, "
        . "np. a.kowalska@, jan.nowak@, anna@kancelaria.pl) czy OGÓLNY (skrzynka instytucji, działu "
        . "lub funkcji, np. biuro@, sekretariat@, rekrutacja@, urzad@).\n\n"
        . "Zasady:\n"
        . "1. Oceniaj po nazwie skrzynki, domenie i nazwie kontaktu z bazy.\n"
        . "2. Polskie imiona i nazwiska (także skrócone: jkowalski, a.nowak) to adres imienny.\n"
        . "3. Nazwy komórek, funkcji, projektów, ról i adresy typu noreply to adresy ogólne.\n"
        . "4. Gdy naprawdę nie da się rozstrzygnąć, zwróć \"unknown\" — zgadywanie jest gorsze "
        . "od przyznania się, bo na tej podstawie system dobiera zwrot grzecznościowy.\n"
        . "5. Uzasadnienie: maksymalnie 8 słów, po polsku.";

    $schema = [
        'type' => 'object',
        'properties' => [
            'wyniki' => [
                'type'  => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'email' => ['type' => 'string'],
                        'kind'  => ['type' => 'string', 'enum' => ['personal', 'generic', 'unknown']],
                        'why'   => ['type' => 'string'],
                    ],
                    'required' => ['email', 'kind', 'why'],
                    'additionalProperties' => false,
                ],
            ],
        ],
        'required' => ['wyniki'],
        'additionalProperties' => false,
    ];

    $body = json_encode([
        'model'      => $model,
        'max_tokens' => 2048,
        'system'     => $system,
        'messages'   => [['role' => 'user', 'content' => "Adresy do rozpoznania:\n" . implode("\n", $lines)]],
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
            'timeout'       => 45,
        ],
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
    ]);

    $resp = @file_get_contents('https://api.anthropic.com/v1/messages', false, $ctx);
    if ($resp === false) return [];

    $data = json_decode($resp, true) ?? [];
    if (!empty($data['error'])) {
        error_log('[crm_email_kind_ai] ' . ($data['error']['message'] ?? 'błąd API'));
        return [];
    }

    $text = '';
    foreach ((array)($data['content'] ?? []) as $b) {
        if (($b['type'] ?? '') === 'text') { $text = (string)$b['text']; break; }
    }
    $out = json_decode($text, true);
    if (!is_array($out) || empty($out['wyniki'])) return [];

    $map = [];
    foreach ($out['wyniki'] as $r) {
        $e = mb_strtolower(trim((string)($r['email'] ?? '')));
        if ($e === '') continue;
        $k = (string)($r['kind'] ?? 'unknown');
        $map[$e] = [
            'kind' => isset(CRM_EMAIL_KINDS[$k]) ? $k : 'unknown',
            'why'  => 'AI: ' . mb_substr((string)($r['why'] ?? ''), 0, 80),
        ];
    }
    return $map;
}

/**
 * Rozpoznanie dla jednego kontaktu, z zapisem wyniku.
 *
 * @param bool $allow_ai czy wolno dopytać model, gdy wzorce nie rozstrzygają
 */
function crm_contact_email_kind(array $contact, bool $allow_ai = false): array
{
    crm_email_kind_migrate();

    $email = trim((string)($contact['email'] ?? ''));
    if ($email === '') return ['kind' => 'unknown', 'why' => 'brak adresu'];

    // Zapisane rozpoznanie wygrywa — chyba że adres się zmienił
    $saved = (string)($contact['email_kind'] ?? '');
    if ($saved !== '' && isset(CRM_EMAIL_KINDS[$saved])) {
        return ['kind' => $saved, 'why' => (string)($contact['email_kind_why'] ?? '')];
    }

    $r = crm_email_kind_local($email, $contact['imie_nazwisko'] ?? null);
    if (!$r['sure'] && $allow_ai) {
        $ai = crm_email_kind_ai([['email' => $email, 'name' => (string)($contact['imie_nazwisko'] ?? '')]]);
        if (isset($ai[mb_strtolower($email)])) $r = $ai[mb_strtolower($email)] + ['sure' => true];
    }

    if (!empty($contact['id'])) crm_email_kind_save((int)$contact['id'], $r['kind'], $r['why']);
    return ['kind' => $r['kind'], 'why' => $r['why']];
}

/** Zapis rozpoznania przy kartotece. */
function crm_email_kind_save(int $contact_id, string $kind, string $why): void
{
    crm_email_kind_migrate();
    try {
        db()->prepare("UPDATE crm_contacts SET email_kind=?, email_kind_why=?, email_kind_at=? WHERE id=?")
            ->execute([$kind, mb_substr($why, 0, 160), date('Y-m-d H:i:s'), $contact_id]);
    } catch (\Throwable $e) {}
}

/**
 * Zwrot grzecznościowy dopasowany do adresu.
 *
 * Adres ogólny dostaje formę bezosobową, imienny — z imieniem, o ile da się je
 * wyłuskać z kartoteki. Bez rozpoznania zwracamy formę bezpieczną: „Szanowni
 * Państwo" do człowieka brzmi formalnie, ale „Szanowna Pani Anno" na sekretariat
 * brzmi jak pomyłka.
 */
function crm_contact_salutation(array $contact, ?string $kind = null): string
{
    $kind ??= (string)($contact['email_kind'] ?? '');
    $name  = trim((string)($contact['imie_nazwisko'] ?? ''));

    if ($kind !== 'personal' || $name === '') return 'Szanowni Państwo';

    $first = preg_split('/\s+/', $name)[0] ?? '';
    if (mb_strlen($first) < 3) return 'Szanowni Państwo';

    // Bez odmiany przez przypadki: „Szanowna Pani Anna" jest poprawne w nagłówku
    // pisma, a próba wołacza na cudzych imionach kończy się „Szanowny Panie Iwo".
    return 'Dzień dobry, ' . $first;
}

/** Zbiorcze rozpoznanie — do listy kontaktów i przed wysyłką. */
function crm_email_kinds_bulk(array $contact_ids, bool $allow_ai = false): array
{
    crm_email_kind_migrate();
    if (!$contact_ids) return [];

    $in   = implode(',', array_map('intval', $contact_ids));
    $rows = db_all("SELECT id, imie_nazwisko, email, email_kind, email_kind_why FROM crm_contacts WHERE id IN ($in)");

    $out = [];
    $ask = [];
    foreach ($rows as $c) {
        $email = trim((string)$c['email']);
        if ($email === '') { $out[(int)$c['id']] = ['kind' => 'unknown', 'why' => 'brak adresu']; continue; }

        $saved = (string)($c['email_kind'] ?? '');
        if ($saved !== '' && isset(CRM_EMAIL_KINDS[$saved])) {
            $out[(int)$c['id']] = ['kind' => $saved, 'why' => (string)$c['email_kind_why']];
            continue;
        }
        $r = crm_email_kind_local($email, $c['imie_nazwisko']);
        if ($r['sure']) {
            crm_email_kind_save((int)$c['id'], $r['kind'], $r['why']);
            $out[(int)$c['id']] = ['kind' => $r['kind'], 'why' => $r['why']];
        } else {
            $out[(int)$c['id']] = ['kind' => 'unknown', 'why' => $r['why']];
            $ask[mb_strtolower($email)] = ['id' => (int)$c['id'], 'email' => $email, 'name' => (string)$c['imie_nazwisko']];
        }
    }

    if ($ask && $allow_ai) {
        $ai = crm_email_kind_ai(array_values($ask));
        foreach ($ai as $email => $r) {
            if (!isset($ask[$email])) continue;
            $cid = $ask[$email]['id'];
            crm_email_kind_save($cid, $r['kind'], $r['why']);
            $out[$cid] = $r;
        }
    }
    return $out;
}
