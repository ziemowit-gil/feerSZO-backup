<?php
/**
 * includes/contract_template_engine.php
 * Silnik szablonów dokumentów umów.
 * Zastępuje zmienne {zmienna} wartościami z wiersza umowy i danych org.
 */

function cte_migrate(): void {
    static $done = false;
    if ($done) return;
    $done = true;

    db()->exec("CREATE TABLE IF NOT EXISTS contract_doc_templates (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        name        TEXT    NOT NULL,
        type        TEXT    NOT NULL DEFAULT 'universal',
        description TEXT    NULL,
        body        TEXT    NOT NULL DEFAULT '',
        is_active   INTEGER NOT NULL DEFAULT 1,
        created_by  INTEGER NULL,
        created_at  DATETIME NOT NULL DEFAULT (datetime('now','localtime')),
        updated_at  DATETIME NOT NULL DEFAULT (datetime('now','localtime'))
    )");
}

/**
 * Zwraca listę dostępnych zmiennych z opisami, pogrupowaną.
 */
function cte_variables(): array {
    return [
        'Organizacja' => [
            '{org_nazwa}'    => 'Pełna nazwa organizacji',
            '{org_adres}'    => 'Adres siedziby',
            '{org_miasto}'   => 'Miasto siedziby',
            '{org_nip}'      => 'NIP organizacji',
            '{org_krs}'      => 'KRS organizacji',
            '{org_regon}'    => 'REGON organizacji',
            '{org_email}'    => 'E-mail kontaktowy organizacji',
            '{data_dzisiaj}' => 'Dzisiejsza data (dd.mm.rrrr)',
        ],
        'Wolontariusz / Zleceniobiorca' => [
            '{imie_nazwisko}'  => 'Imię i nazwisko',
            '{pesel}'          => 'PESEL',
            '{adres}'          => 'Adres zamieszkania',
            '{email}'          => 'Adres e-mail',
            '{telefon}'        => 'Numer telefonu',
            '{nr_dowodu}'      => 'Nr dokumentu tożsamości',
        ],
        'Umowa' => [
            '{numer_umowy}'    => 'Numer umowy/porozumienia',
            '{data_zawarcia}'  => 'Data zawarcia',
            '{data_od}'        => 'Data rozpoczęcia',
            '{data_do}'        => 'Data zakończenia',
            '{bezterminowa}'   => 'Bezterminowa (tak/nie)',
            '{miejsce}'        => 'Miejsce świadczenia',
            '{przedmiot}'      => 'Przedmiot / zakres obowiązków',
            '{opiekun}'        => 'Opiekun / koordynator',
            '{projekt}'        => 'Projekt / program',
            '{godziny_tyg}'    => 'Godziny tygodniowo',
            '{wynagrodzenie}'  => 'Wynagrodzenie / kwota',
            '{nr_konta}'       => 'Numer konta bankowego',
        ],
    ];
}

/**
 * Buduje mapę zmiennych → wartości dla danej umowy.
 *
 * @param string $type   'wolontariat' | 'zlecenie' | 'dzielo' | 'praca'
 * @param array  $row    Wiersz z tabeli umowy_*
 */
function cte_build_map(string $type, array $row): array {
    $fmt_date = fn(?string $d) => $d ? date('d.m.Y', strtotime($d)) : '';

    $map = [
        // org
        '{org_nazwa}'    => org_setting('org_name')        ?: (defined('ORG_NAME') ? ORG_NAME : ''),
        '{org_adres}'    => org_setting('org_adres')        ?: '',
        '{org_miasto}'   => org_setting('org_miejscowosc')  ?: '',
        '{org_nip}'      => org_setting('org_nip')          ?: '',
        '{org_krs}'      => org_setting('org_krs')          ?: '',
        '{org_regon}'    => org_setting('org_regon')        ?: '',
        '{org_email}'    => org_setting('org_email')        ?: '',
        '{data_dzisiaj}' => date('d.m.Y'),

        // person
        '{imie_nazwisko}'  => $row['imie_nazwisko']  ?? '',
        '{pesel}'          => $row['pesel']           ?? '',
        '{adres}'          => $row['adres']           ?? '',
        '{email}'          => $row['email']           ?? '',
        '{telefon}'        => $row['telefon']         ?? '',
        '{nr_dowodu}'      => $row['id_document_number'] ?? ($row['nr_dowodu'] ?? ''),

        // umowa
        '{numer_umowy}'    => $row['numer_umowy']          ?? ($row['numer'] ?? ''),
        '{data_zawarcia}'  => $fmt_date($row['data_zawarcia'] ?? null),
        '{data_od}'        => $fmt_date($row['data_rozpoczecia'] ?? ($row['data_od'] ?? null)),
        '{data_do}'        => $fmt_date($row['data_zakonczenia'] ?? ($row['data_do'] ?? null)),
        '{bezterminowa}'   => !empty($row['bezterminowa']) ? 'bezterminowa' : 'na czas określony',
        '{miejsce}'        => $row['miejsce_wolontariatu'] ?? ($row['miejsce_wykonania'] ?? ($row['miejsce'] ?? '')),
        '{przedmiot}'      => $row['przedmiot_porozumienia'] ?? ($row['zakres_obowiazkow'] ?? ($row['przedmiot'] ?? '')),
        '{opiekun}'        => $row['opiekun'] ?? '',
        '{projekt}'        => $row['projekt_program'] ?? ($row['projekt'] ?? ''),
        '{godziny_tyg}'    => $row['godzin_tygodniowo'] ?? '',
        '{wynagrodzenie}'  => isset($row['wynagrodzenie']) ? number_format((float)$row['wynagrodzenie'], 2, ',', ' ') . ' zł' : '',
        '{nr_konta}'       => $row['nr_konta'] ?? '',
    ];

    return $map;
}

/**
 * Renderuje szablon — zastępuje zmienne wartościami, zwraca HTML.
 */
function cte_render(string $body, array $map): string {
    return strtr($body, array_map('htmlspecialchars', $map));
}

/**
 * Pobiera szablon z bazy. Zwraca null jeśli nie istnieje lub nieaktywny.
 */
function cte_get(int $id): ?array {
    cte_migrate();
    return db_one("SELECT * FROM contract_doc_templates WHERE id=? AND is_active=1", [$id]) ?: null;
}

/**
 * Wyśrodkowuje wszystkie bloki tekstu (akapity, nagłówki, elementy list) w
 * treści wzoru — dopisuje/nadpisuje `text-align:center` w ich atrybucie
 * style, zachowując resztę stylu. Używane przez masową poprawkę formatowania
 * istniejących wzorów (admin/contract_templates.php).
 */
function cte_center_all_blocks(string $html): string {
    if (trim($html) === '') return $html;

    $dom = new \DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML(
        '<?xml encoding="utf-8"?><div id="cte-root">' . $html . '</div>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );
    libxml_clear_errors();

    $xpath = new \DOMXPath($dom);
    $root  = $xpath->query('//div[@id="cte-root"]')->item(0);
    if (!$root) return $html;

    foreach ($xpath->query('.//p | .//div | .//h1 | .//h2 | .//h3 | .//h4 | .//h5 | .//h6 | .//li', $root) as $el) {
        $decls = array_filter(array_map('trim', explode(';', $el->getAttribute('style'))));
        $decls = array_values(array_filter($decls, fn($d) => stripos($d, 'text-align') !== 0));
        $decls[] = 'text-align:center';
        $el->setAttribute('style', implode('; ', $decls));
    }

    $out = '';
    foreach ($root->childNodes as $child) {
        $out .= $dom->saveHTML($child);
    }

    // DOMDocument::saveHTML zapisuje puste elementy jako <br> (bez /) —
    // PHPWord Html::addHtml() parsuje treść jako ścisły XML i taki
    // niedomknięty tag psuje cały eksport DOCX (patrz pamięć projektu:
    // feedback_phpword_html_unclosed_br). Zawsze domykaj.
    $out = preg_replace_callback(
        '/<(br|hr|img|input)\b([^>]*?)\/?>/i',
        fn($m) => '<' . $m[1] . rtrim($m[2]) . '/>',
        $out
    );

    return $out;
}

/**
 * Zwraca listę aktywnych szablonów, opcjonalnie filtrowaną po typie.
 */
function cte_list(?string $type = null): array {
    cte_migrate();
    if ($type) {
        return db_all(
            "SELECT * FROM contract_doc_templates WHERE is_active=1 AND (type=? OR type='universal') ORDER BY name",
            [$type]
        );
    }
    return db_all("SELECT * FROM contract_doc_templates WHERE is_active=1 ORDER BY type, name");
}
