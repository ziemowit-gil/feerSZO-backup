<?php
/**
 * modules/seeders/lib/seedersRegistry.php — rejestr seederów (cli/seed*.php, cli/cleanup_*.php)
 * dostępnych z Admin › Seedery (/seeder-test).
 *
 * Wpis: klucz => [
 *   'group'  => sekcja na stronie, 'title' => nazwa, 'desc' => co tworzy (krótko),
 *   'script' => plik w cli/ (domyślny dla akcji),
 *   'fields' => [nazwa => ['label', 'type' => text|email|password|number|select|check, 'default', 'options', 'help',
 *                           'pos' => true (argument pozycyjny, w kolejności pól) | 'opt' => '--x' (--x=wartość) | 'flag' => '--x']],
 *   'actions'=> [ ['id', 'label', 'kind' => preview|apply|danger, 'args' => [stałe argumenty], 'use' => [pola], 'script' => inny plik, 'req' => [pola wymagane]] ],
 *   'cli_only' => true — skrypt uruchamia osobny proces PHP, więc z przeglądarki tylko polecenie do skopiowania.
 * ]
 * Skrypty muszą obsługiwać tryb in-process (includes/cli_inproc.php: cli_exit, cli_getopt, SZO_CLI_INPROC).
 */

function seedersRegistry(): array
{
    $dom = ['label' => 'Domena e-maili', 'type' => 'text', 'default' => 'feer.test', 'pos' => true, 'help' => 'konta lokalne @domena'];
    $pw  = ['label' => 'Hasło kont', 'type' => 'password', 'pos' => true, 'help' => 'nie jest nigdzie zapisywane poza kontami'];
    return [
        // ── Konta testowe ─────────────────────────────────────────────────
        'test_accounts' => [
            'group' => 'Konta testowe', 'title' => 'Konta QA (SZO + panel TI)', 'script' => 'seed_test_accounts.php',
            'desc'  => 'admin/editor/viewer@domena, kursant pełnoletni i małoletni z rodzicem, dydaktyk oraz „Kurs testowy (TEST)” z zapisami. Idempotentne.',
            'fields'  => ['password' => $pw, 'domain' => $dom],
            'actions' => [
                ['id' => 'seed',  'label' => 'Załóż konta', 'kind' => 'apply',  'use' => ['password', 'domain'], 'req' => ['password']],
                ['id' => 'clean', 'label' => 'Usuń konta',  'kind' => 'danger', 'use' => ['domain'], 'script' => 'cleanup_test_accounts.php'],
            ],
        ],
        'rola_metro' => [
            'group' => 'Konta testowe', 'title' => 'Użytkownik rolaMetroTest', 'script' => 'seedRolaMetroTest.php',
            'desc'  => 'Jedno konto lokalne w wybranej roli — do oglądania widoków Metro tak, jak widzi je dana rola.',
            'fields'  => [
                'password' => $pw,
                'role'     => ['label' => 'Rola', 'type' => 'select', 'options' => ['viewer' => 'viewer', 'editor' => 'editor', 'admin' => 'admin'], 'default' => 'viewer', 'pos' => true],
                'domain'   => $dom,
            ],
            'actions' => [
                ['id' => 'status', 'label' => 'Stan',        'kind' => 'preview', 'args' => ['status'], 'use' => ['domain']],
                ['id' => 'create', 'label' => 'Załóż',       'kind' => 'apply',   'args' => ['create'], 'use' => ['password', 'role', 'domain'], 'req' => ['password']],
                ['id' => 'delete', 'label' => 'Usuń',        'kind' => 'danger',  'args' => ['delete'], 'use' => ['domain']],
            ],
        ],
        'edok_kontrahent' => [
            'group' => 'Konta testowe', 'title' => 'Kontrahent Portalu Kontrahenta (EODoK)', 'script' => 'seed_edok_kontrahent_test.php',
            'desc'  => 'Kontrahent testowy z aktywnym kontem (NIP + e-mail) i kilka dokumentów EODoK w różnych stanach.',
            'fields'  => ['password' => $pw, 'domain' => $dom],
            'actions' => [
                ['id' => 'seed',  'label' => 'Załóż',  'kind' => 'apply',  'use' => ['password', 'domain'], 'req' => ['password']],
                ['id' => 'clean', 'label' => 'Usuń',   'kind' => 'danger', 'use' => ['domain'], 'script' => 'cleanup_edok_kontrahent_test.php'],
            ],
        ],

        // ── Dydaktyka TI ──────────────────────────────────────────────────
        'demo_lessons' => [
            'group' => 'Dydaktyka TI', 'title' => 'Grupa DEMO z zajęciami dla kursanta', 'script' => 'seed_demo_lessons.php',
            'desc'  => '„Pracownia komputerowa (DEMO)” i 8 zajęć (minione, dziś, jutro, za tydzień i dwa) dla konta kursanta. Bez SMS i e-maili.',
            'fields'  => [
                'login'      => ['label' => 'Login kursanta', 'type' => 'text', 'default' => 'test2', 'opt' => '--login'],
                'instructor' => ['label' => 'E-mail prowadzącego', 'type' => 'email', 'opt' => '--instructor', 'help' => 'opcjonalnie'],
            ],
            'actions' => [
                ['id' => 'dry',    'label' => 'Podgląd',          'kind' => 'preview', 'args' => ['--dry-run'], 'use' => ['login', 'instructor']],
                ['id' => 'seed',   'label' => 'Utwórz',           'kind' => 'apply',   'use' => ['login', 'instructor']],
                ['id' => 'reset',  'label' => 'Odśwież (od nowa)', 'kind' => 'apply',  'args' => ['--reset'], 'use' => ['login', 'instructor']],
                ['id' => 'remove', 'label' => 'Usuń dane demo',   'kind' => 'danger',  'args' => ['--remove'], 'use' => ['login']],
            ],
        ],
        'ti_protocols' => [
            'group' => 'Dydaktyka TI', 'title' => 'Grupa TEST z 4 protokołami', 'script' => 'tiTestProtocols.php',
            'desc'  => 'Grupa „TEST — 4 protokoły…” dla prowadzącego: zatwierdzony, zaległy, 0 godzin i bieżący miesiąc. Bez SMS i e-maili.',
            'fields'  => ['who' => ['label' => 'Prowadzący (e-mail albo ID)', 'type' => 'text', 'pos' => true]],
            'actions' => [['id' => 'seed', 'label' => 'Utwórz grupę', 'kind' => 'apply', 'use' => ['who'], 'req' => ['who']]],
        ],
        'ti_faktury' => [
            'group' => 'Dydaktyka TI', 'title' => 'Dane do faktur TI', 'script' => 'seed_ti_faktury.php',
            'desc'  => 'Kursant → dwie grupy (stawka godzinowa i opłata stała) → lekcje z obecnością → rozliczenie miesiąca. Wszystkie e-maile na jeden adres.',
            'fields'  => [
                'email' => ['label' => 'Adres na wszystkie e-maile', 'type' => 'email', 'opt' => '--email'],
                'month' => ['label' => 'Miesiąc', 'type' => 'number', 'opt' => '--month', 'default' => date('n')],
                'year'  => ['label' => 'Rok', 'type' => 'number', 'opt' => '--year', 'default' => date('Y')],
            ],
            'actions' => [
                ['id' => 'seed',  'label' => 'Utwórz',             'kind' => 'apply',  'use' => ['email', 'month', 'year']],
                ['id' => 'clean', 'label' => 'Usuń i utwórz od nowa', 'kind' => 'danger', 'args' => ['--clean'], 'use' => ['email', 'month', 'year']],
            ],
        ],
        'umowa_szkolenia' => [
            'group' => 'Dydaktyka TI', 'title' => 'Umowy szkoleniowe TI (testowe)', 'script' => 'seedUmowaSzkolenia.php',
            'desc'  => 'Dwie umowy TI/UMSZ/TEST (projekt i zarejestrowana) — poza prawdziwą numeracją RU.',
            'fields'  => [],
            'actions' => [
                ['id' => 'state',   'label' => 'Stan',              'kind' => 'preview'],
                ['id' => 'seed',    'label' => 'Dodaj 2 umowy',     'kind' => 'apply',  'args' => ['--apply']],
                ['id' => 'delprev', 'label' => 'Do usunięcia (podgląd)', 'kind' => 'preview', 'args' => ['--delete']],
                ['id' => 'delete',  'label' => 'Usuń testowe',      'kind' => 'danger', 'args' => ['--delete', '--apply']],
            ],
        ],
        'pfron' => [
            'group' => 'Dydaktyka TI', 'title' => 'Klienci PFRON (4 osoby)', 'script' => 'seed_pfron_test_clients.php',
            'desc'  => 'Czterech klientów TEST z aktywną umową PFRON (bez numeru dokumentu — nie zużywa numeracji).',
            'fields'  => [],
            'actions' => [
                ['id' => 'seed',  'label' => 'Załóż', 'kind' => 'apply'],
                ['id' => 'clean', 'label' => 'Usuń',  'kind' => 'danger', 'script' => 'cleanup_pfron_test_clients.php'],
            ],
        ],
        'nabor' => [
            'group' => 'Dydaktyka TI', 'title' => 'Zgłoszenie z naboru do grup', 'script' => 'seedNaborZgloszenie.php',
            'desc'  => 'Przykładowe zgłoszenie z pełnym obiegiem (konto kandydata, PDF, koszulka EZD, CRM, e-mail). Każde --apply = nowe zgłoszenie.',
            'fields'  => [
                'email'  => ['label' => 'E-mail kandydata', 'type' => 'email', 'opt' => '--email'],
                'phone'  => ['label' => 'Telefon (48…)', 'type' => 'text', 'opt' => '--phone', 'help' => 'opcjonalnie, do SMS-ów statusów'],
                'course' => ['label' => 'ID grupy', 'type' => 'number', 'opt' => '--course', 'help' => 'opcjonalnie'],
            ],
            'actions' => [
                ['id' => 'prev',    'label' => 'Podgląd',          'kind' => 'preview', 'use' => ['email', 'phone', 'course'], 'req' => ['email']],
                ['id' => 'seed',    'label' => 'Utwórz zgłoszenie', 'kind' => 'apply',  'args' => ['--apply'], 'use' => ['email', 'phone', 'course'], 'req' => ['email']],
                ['id' => 'delprev', 'label' => 'Do usunięcia (podgląd)', 'kind' => 'preview', 'args' => ['--delete'], 'use' => ['email']],
                ['id' => 'delete',  'label' => 'Usuń przykładowe', 'kind' => 'danger',  'args' => ['--delete', '--apply'], 'use' => ['email']],
            ],
        ],

        // ── Umowy, wolontariat i CRM ──────────────────────────────────────
        'zlecenie_tech' => [
            'group' => 'Umowy, wolontariat i CRM', 'title' => 'Umowa zlecenie techniczna („---”)', 'script' => 'seedZlecenieTechniczna.php',
            'desc'  => 'Umowa techniczna dla prowadzącego bez zwykłej umowy — bez RU, PDF, M365 i CRM.',
            'fields'  => [
                'osoba' => ['label' => 'Osoba (z rejestru osób)', 'type' => 'text', 'opt' => '--osoba', 'help' => 'domyślnie „Ziemowit Gil”'],
                'email' => ['label' => 'E-mail', 'type' => 'email', 'opt' => '--email'],
                'od'    => ['label' => 'Obowiązuje od', 'type' => 'date', 'opt' => '--od'],
            ],
            'actions' => [
                ['id' => 'prev', 'label' => 'Podgląd', 'kind' => 'preview', 'use' => ['osoba', 'email', 'od']],
                ['id' => 'seed', 'label' => 'Zapisz',  'kind' => 'apply', 'args' => ['--apply'], 'use' => ['osoba', 'email', 'od']],
            ],
        ],
        'oferta_redaktor' => [
            'group' => 'Umowy, wolontariat i CRM', 'title' => 'Ogłoszenie wolontariatu „Redaktor/ka”', 'script' => 'seedOfertaRedaktor.php',
            'desc'  => 'Ogłoszenie w Wolontariat › Zgłoszenia. Ponowne uruchomienie aktualizuje to samo ogłoszenie.',
            'fields'  => [],
            'actions' => [
                ['id' => 'prev',    'label' => 'Podgląd',           'kind' => 'preview'],
                ['id' => 'seed',    'label' => 'Zapisz jako szkic', 'kind' => 'apply', 'args' => ['--apply']],
                ['id' => 'publish', 'label' => 'Zapisz i opublikuj', 'kind' => 'apply', 'args' => ['--apply', '--publish']],
            ],
        ],
        'formularz_redaktor' => [
            'group' => 'Umowy, wolontariat i CRM', 'title' => 'Formularz CRM „Redaktor/ka”', 'script' => 'seedFormularzRedaktor.php',
            'desc'  => 'Formularz webowy CRM /crm/form/redaktor-www (lead capture z polami dodatkowymi i tagami).',
            'fields'  => ['notify' => ['label' => 'Powiadomienie o zgłoszeniu na', 'type' => 'email', 'opt' => '--notify', 'help' => 'opcjonalnie']],
            'actions' => [
                ['id' => 'prev', 'label' => 'Podgląd', 'kind' => 'preview'],
                ['id' => 'seed', 'label' => 'Zapisz',  'kind' => 'apply', 'args' => ['--apply'], 'use' => ['notify']],
            ],
        ],

        // ── EODoK i Zadania ───────────────────────────────────────────────
        'edok_docs' => [
            'group' => 'EODoK i Zadania', 'title' => '20 dokumentów testowych EODoK', 'script' => 'seed_edok_test_docs.php',
            'desc'  => '10 wydatków i 10 przychodów (EODoK-TEST/…), większość zaakceptowana. Każde uruchomienie dokłada kolejne 20. Usuwanie: EODoK › „Usuń testowe”.',
            'fields'  => [],
            'actions' => [['id' => 'seed', 'label' => 'Dodaj 20 dokumentów', 'kind' => 'apply']],
        ],
        'tasks' => [
            'group' => 'EODoK i Zadania', 'title' => 'Dane testowe modułu Zadania', 'script' => 'seed_tasks.php',
            'desc'  => '3 konta testowe, 2 obszary, listy i zadania.',
            'fields'  => [],
            'actions' => [
                ['id' => 'seed',  'label' => 'Utwórz',                'kind' => 'apply'],
                ['id' => 'clean', 'label' => 'Usuń i utwórz od nowa', 'kind' => 'danger', 'args' => ['--clean']],
            ],
        ],
        'zadania_powiadomienia' => [
            'group' => 'EODoK i Zadania', 'title' => '3 zadania z powiadomieniami', 'script' => 'seedZadaniaPowiadomienia.php',
            'cli_only' => true,
            'desc'  => 'Zadania przez to samo API co formularz (powiadomienia jak od użytkownika). Uruchamia osobny proces PHP — tylko z terminala.',
            'fields'  => [
                'as'     => ['label' => 'Autor (e-mail)', 'type' => 'email', 'opt' => '--as'],
                'assign' => ['label' => 'Przypisz do (e-maile po przecinku)', 'type' => 'text', 'opt' => '--assign'],
                'ws'     => ['label' => 'ID obszaru', 'type' => 'number', 'opt' => '--ws'],
            ],
            'actions' => [
                ['id' => 'prev', 'label' => 'Podgląd', 'kind' => 'preview', 'use' => ['as', 'assign', 'ws']],
                ['id' => 'seed', 'label' => 'Utwórz',  'kind' => 'apply', 'args' => ['--apply'], 'use' => ['as', 'assign', 'ws']],
            ],
        ],
    ];
}

/**
 * Argumenty dla akcji z danych formularza. Zwraca [skrypt, argumenty, błąd|null].
 * @return array{0:string,1:string[],2:?string}
 */
function seedersBuildArgs(array $entry, array $action, array $input): array
{
    $script = (string)($action['script'] ?? $entry['script']);
    $args = array_map('strval', $action['args'] ?? []);
    $pos = [];
    $opt = [];
    foreach ($action['use'] ?? [] as $name) {
        $f = $entry['fields'][$name] ?? null;
        if (!$f) continue;
        $type = $f['type'] ?? 'text';
        $v = $type === 'check' ? !empty($input[$name]) : trim((string)($input[$name] ?? ''));
        if (in_array($name, $action['req'] ?? [], true) && ($v === '' || $v === false)) {
            return [$script, [], 'Uzupełnij pole: ' . $f['label'] . '.'];
        }
        if ($type === 'email' && $v !== '' && !filter_var($v, FILTER_VALIDATE_EMAIL)) {
            return [$script, [], 'Niepoprawny adres w polu: ' . $f['label'] . '.'];
        }
        if ($type === 'select' && $v !== '' && !isset($f['options'][$v])) {
            return [$script, [], 'Niedozwolona wartość w polu: ' . $f['label'] . '.'];
        }
        if (!empty($f['pos'])) {
            // argumenty pozycyjne muszą zachować kolejność — pusty w środku zastępuje wartość domyślna
            $pos[] = $v !== '' ? $v : (string)($f['default'] ?? '');
        } elseif (!empty($f['flag'])) {
            if ($v) $opt[] = $f['flag'];
        } elseif (!empty($f['opt']) && $v !== '') {
            $opt[] = $f['opt'] . '=' . $v;
        }
    }
    while ($pos && end($pos) === '') array_pop($pos);
    return [$script, array_merge($args, $pos, $opt), null];
}

/** Polecenie do terminala; wartości z $secrets (hasła) zastąpione gwiazdkami. */
function seedersCommand(string $script, array $args, array $secrets = []): string
{
    $parts = ['php', 'cli/' . $script];
    foreach ($args as $a) $parts[] = in_array($a, $secrets, true) ? "'********'" : escapeshellarg($a);
    return implode(' ', $parts);
}

/** Wartości pól, które są hasłami — do zamaskowania w wyświetlanym poleceniu. */
function seedersSecrets(array $entry, array $input): array
{
    $out = [];
    foreach ($entry['fields'] ?? [] as $name => $f) {
        if (($f['type'] ?? '') === 'password' && ($input[$name] ?? '') !== '') $out[] = (string)$input[$name];
    }
    return $out;
}
