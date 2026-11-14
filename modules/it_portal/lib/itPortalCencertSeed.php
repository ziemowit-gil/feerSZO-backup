<?php
/**
 * modules/it_portal/lib/itPortalCencertSeed.php — usługa „Podpis kwalifikowany (CenCert)” w Portalu Usług IT (/uslugi-it/uslugi/podpis-kwalifikowany).
 * Polecenie 2026-11-14: „ogarnij też CenCert” z listą instrukcji producenta; „CenCert jest polski i wydaje dla nas certyfikaty do podpisu kwalifikowanego”.
 * Linki do PDF-ów CenCert sprawdzone 2026-11-14 (HTTP 200). Treść własnymi słowami, bez kopiowania opisów producenta.
 * Używa migracja '2026-11_it_portal_cencert'.
 */

function itPortalCencertApply(string $app): string {
    require_once dirname(__DIR__) . '/logic/itPortal.php';
    itPortalMigrate();
    $app = rtrim($app, '/');
    $intro = itPortalSanitizeHtml('<p>Certyfikaty do podpisu kwalifikowanego wydaje dla Fundacji CenCert, polski kwalifikowany dostawca usług zaufania. '
        . 'Podpis kwalifikowany ma taką samą moc jak podpis własnoręczny. Instrukcja poniżej mówi, jak go używać, odnowić i przenieść.</p>'
        . '<p>Jeśli czegoś w niej brakuje, <a href="' . $app . '/helpdesk/new.php">napisz do nas w Helpdesku</a>.</p>');
    $cat = db_one("SELECT id FROM itp_categories WHERE slug = 'podpis-kwalifikowany'");
    if ($cat) {
        $catId = (int)$cat['id'];
        db_exec("UPDATE itp_categories SET intro_html = ?, intro_blocks = '', intro_plain = ?, is_active = 1, updated_at = datetime('now') WHERE id = ?", [$intro, itPortalPlain($intro), $catId]);
    } else {
        $aud = array_column(itPortalAudiences(), 'id', 'name');
        $catId = itPortalCategorySave(['name' => 'Podpis kwalifikowany (CenCert)', 'slug' => 'podpis-kwalifikowany', 'icon' => 'key', 'sort_order' => 27, 'is_active' => 1,
            'audiences' => array_values(array_filter([$aud['Pracownik'] ?? 0, $aud['IT'] ?? 0])), 'intro_html' => $intro]);
    }
    $slug = 'cencert';
    $old = db_one("SELECT id, related FROM itp_articles WHERE category_id = ? AND slug = ?", [$catId, $slug]);
    $title = 'Podpis kwalifikowany CenCert: używanie, odnawianie, przenoszenie';
    itPortalArticleSave(['title' => $title, 'slug' => $slug, 'category_id' => $catId,
        'keywords' => 'cencert, podpis kwalifikowany, podpis elektroniczny, e-podpis, certyfikat kwalifikowany, pieczęć elektroniczna, znacznik czasu, karta, czytnik, rsign, podpis mobilny, podpis w chmurze, odnowienie certyfikatu, przeniesienie certyfikatu, pem-heart, xbrl, xml, sprawozdanie finansowe',
        'blocks' => json_encode(itPortalCencertBlocks($app), JSON_UNESCAPED_UNICODE), 'is_published' => 1, 'is_pinned' => 0, 'sort_order' => 10,
        'related' => $old ? itPortalIds($old['related'] ?? '[]') : []], $old ? (int)$old['id'] : 0);
    return "podpis-kwalifikowany/{$slug} — {$title} (" . ($old ? 'nadpisany' : 'utworzony') . ')';
}

function itPortalCencertBlocks(string $app): array {
    $hd = $app . '/helpdesk/new.php';
    $u = 'https://www.cencert.pl/wp-content/uploads/';
    $a = static fn(string $path, string $text): string => '<a href="' . $u . $path . '">' . $text . '</a>';
    return [
        ['type' => 'text', 'html' => '<p>CenCert to polski kwalifikowany dostawca usług zaufania. Wydaje dla Fundacji certyfikaty do podpisu kwalifikowanego. '
            . 'Podpis kwalifikowany ma taką samą moc prawną jak podpis własnoręczny, więc podpisujesz nim umowy, pisma i sprawozdania bez drukowania.</p>'
            . '<p>CenCert oferuje dwie formy podpisu. Podpis na karcie wymaga karty kryptograficznej, czytnika i programu do podpisywania na komputerze. '
            . 'Podpis rSign działa w chmurze: podpisujesz z telefonu, bez karty i czytnika. Oprócz podpisu CenCert wydaje też pieczęcie elektroniczne i znaczniki czasu.</p>'],

        ['type' => 'text', 'html' => '<h2>Poradnik użytkownika</h2>'
            . '<p>Ogólne informacje o podpisie elektronicznym, pieczęci elektronicznej i znacznikach czasu znajdziesz w '
            . $a('2020/03/Poradnik-U%C5%BCytkownika-CenCert.pdf', 'Poradniku Użytkownika CenCert (PDF)') . '.</p>'],

        ['type' => 'text', 'html' => '<h2>Odnowienie certyfikatu</h2>'
            . '<p>Certyfikat kwalifikowany jest ważny przez określony czas, zwykle kilka lat. Odnów go przez internet, zanim wygaśnie. Po wygaśnięciu potrzebna jest zwykle ponowna weryfikacja tożsamości.</p><ul>'
            . '<li>' . $a('2026/06/Odnowienie-certyfikatu-kwalifikowanego-CenCert-ONLINE-na-obecnej-karcie-v26.06.pdf', 'Odnowienie certyfikatu online na obecnej karcie (PDF)') . '</li>'
            . '<li>' . $a('2022/09/CenCert-odnowienie-podpisu-w-procesie-z-karty-na-karte-przewodnik.pdf', 'Odnowienie certyfikatu online na nowej karcie (PDF)') . '</li></ul>'],

        ['type' => 'text', 'html' => '<h2>Przeniesienie certyfikatu</h2>'
            . '<p>Certyfikat możesz przenieść między kartą a podpisem w chmurze rSign albo przenieść do CenCert certyfikat od innego dostawcy:</p><ul>'
            . '<li>' . $a('2022/09/CenCert-przeniesienie-certyfikatu-CenCert-na-karcie-do-rSign-przewodnik.pdf', 'z karty CenCert do podpisu mobilnego rSign (PDF)') . '</li>'
            . '<li>' . $a('2022/09/CenCert-przeniesienie-certyfikatu-rSign-na-karcte-przewodnik.pdf', 'z podpisu mobilnego rSign na kartę (PDF)') . '</li>'
            . '<li>' . $a('2024/12/Przeniesienie-certyfikatu-kwalifikowanego-do-CenCert-rSign-podpis-w-chmurze-v23.1.pdf', 'certyfikat od innego dostawcy do CenCert rSign, podpis w chmurze (PDF)') . '</li>'
            . '<li>' . $a('2024/12/Przeniesienie-certyfikatu-kwalifikowanego-do-CenCert-podpis-na-karcie-v23.1.pdf', 'certyfikat od innego dostawcy do CenCert, podpis na karcie (PDF)') . '</li></ul>'],

        ['type' => 'text', 'html' => '<h2>Podpisywanie sprawozdania finansowego (XML, XBRL)</h2>'
            . '<p>Sprawozdanie finansowe w formacie XML podpisuje zwykle kilka osób, na przykład członkowie Zarządu. Jak dodać kolejne podpisy do tego samego pliku w programie PEM-HEART Signature, opisuje '
            . $a('2022/03/Jak-podpisac-sprawozdanie-finansowe-w-postaci-pliku-xml.pdf', 'instrukcja podpisywania sprawozdania XML przez wiele osób (PDF)') . '.</p>'],

        ['type' => 'callout', 'kind' => 'warn', 'html' => '<p>Kartę, PIN i dane do rSign trzymaj tylko dla siebie. Nikomu ich nie przekazuj, także współpracownikom. '
            . 'Podpis kwalifikowany złożony przez inną osobę Twoją kartą jest podpisem w Twoim imieniu. Zgubioną kartę albo telefon z rSign zgłoś od razu w CenCert i w '
            . '<a href="' . $hd . '">Helpdesku IT</a>.</p>'],

        ['type' => 'callout', 'kind' => 'info', 'html' => '<p>Potrzebujesz podpisu kwalifikowanego do pracy w Fundacji albo kończy Ci się certyfikat? <a href="' . $hd . '">Napisz do Helpdesku IT</a>, pomożemy z zamówieniem i odnowieniem.</p>'],
    ];
}
