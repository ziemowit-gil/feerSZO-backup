<?php
/**
 * installer.php — automatyczny instalator instancji.
 * Tworzy bazę SQLite ze schema.sql, konto administratora, domyślne ustawienia
 * i (opcjonalnie) treści demonstracyjne.
 */
declare(strict_types=1);

/** Presety wyglądu dostępne w instalatorze i w panelu. */
function theme_presets(): array
{
    return [
        // Preset wzorowany na jasnej, wyśrodkowanej wizytówce (styl carrd.co)
        'coral' => [
            'label'         => 'Coral (jasny, jedna kolumna)',
            'site_layout'   => 'stack',
            'color_bg'      => '#F5F5F5',
            'color_accent'  => '#FF724F',
            'color_accent2' => '#FF8466',
            'color_text'    => '#D67F69',
            'color_heading' => '#D67F69',
            'color_btn_bg'  => '#FFFFFF',
            'color_btn_text'=> '#1A4757',
            'color_rule'    => '#D67F69',
            'font_family'   => 'Inter',
            'bg_pattern'    => '1',
        ],
        'bento' => [
            'label'         => 'Bento (ciemny, siatka kafelków)',
            'site_layout'   => 'bento',
            'color_bg'      => '#09090B',
            'color_accent'  => '#6366F1',
            'color_accent2' => '#818CF8',
            'color_text'    => '#D4D4D8',
            'color_heading' => '#FAFAFA',
            'color_btn_bg'  => '#18181B',
            'color_btn_text'=> '#FAFAFA',
            'color_rule'    => '#27272A',
            'font_family'   => 'Inter',
            'bg_pattern'    => '1',
        ],
        'mono' => [
            'label'         => 'Mono (jasny, minimalny)',
            'site_layout'   => 'stack',
            'color_bg'      => '#FFFFFF',
            'color_accent'  => '#111827',
            'color_accent2' => '#374151',
            'color_text'    => '#374151',
            'color_heading' => '#111827',
            'color_btn_bg'  => '#F3F4F6',
            'color_btn_text'=> '#111827',
            'color_rule'    => '#E5E7EB',
            'font_family'   => 'Inter',
            'bg_pattern'    => '0',
        ],
    ];
}

/** Domyślne ustawienia nowej instancji. */
function default_settings(): array
{
    return [
        'site_name'        => 'Moja wizytówka',
        'owner_name'       => '',
        'owner_name_bold'  => '',   // druga część nazwy renderowana pogrubieniem
        'tagline'          => '',
        'headline'         => '',
        'bio'              => '',
        'avatar'           => '',
        'job_title'        => '',
        'company'          => '',
        'email'            => '',
        'phone'            => '',
        'website'          => '',
        'location'         => '',
        'vcard_enabled'    => '1',
        'vcard_label'      => 'Zapisz kontakt (vCard)',
        'footer_text'      => '',
        'meta_title'       => '',
        'meta_description' => '',
        'meta_keywords'    => '',
        'og_image'         => '',
        'site_layout'      => 'stack',
        'avatar_shape'     => 'circle',   // circle | rounded | square
        'analytics_code'   => '',
        'installed_at'     => date('c'),
        'app_version'      => APP_VERSION,
    ];
}

/** Walidacja danych z formularza instalatora. */
function install_validate(array $in): array
{
    $err = [];
    if (($in['site_name'] ?? '') === '')                             $err[] = 'Podaj nazwę strony.';
    if (($in['owner_name'] ?? '') === '')                            $err[] = 'Podaj imię i nazwisko.';
    if (!filter_var($in['email'] ?? '', FILTER_VALIDATE_EMAIL))      $err[] = 'Podaj prawidłowy adres e-mail administratora.';
    if (!isset(theme_presets()[$in['preset'] ?? '']))                $err[] = 'Wybierz preset wyglądu.';
    return array_merge($err, password_problems((string)($in['password'] ?? ''), (string)($in['password2'] ?? '')));
}

/** Wykonaj schema.sql (idempotentnie). */
function install_schema(): void
{
    $sql = @file_get_contents(SCHEMA_FILE);
    if ($sql === false) {
        throw new RuntimeException('Nie można odczytać pliku schema.sql.');
    }
    db()->exec($sql);
}

/** Zabezpiecz katalog uploads przed wykonywaniem skryptów (Apache). */
function install_protect_uploads(): void
{
    if (!is_dir(UPLOAD_ROOT)) @mkdir(UPLOAD_ROOT, 0775, true);
    foreach (['', '/avatars', '/gallery', '/tiles'] as $sub) {
        $d = UPLOAD_ROOT . $sub;
        if (!is_dir($d)) @mkdir($d, 0775, true);
    }
    $ht = <<<HT
    # Blokada wykonywania kodu w katalogu z plikami użytkownika
    php_flag engine off
    <IfModule mod_php.c>
      php_admin_flag engine off
    </IfModule>
    RemoveHandler .php .phtml .php3 .php4 .php5 .php7 .php8 .phps .cgi .pl .py
    RemoveType .php .phtml .php3 .php4 .php5 .php7 .php8 .phps
    AddType text/plain .php .phtml .phps .cgi .pl .py .html .htm .shtml
    <IfModule mod_headers.c>
      Header set X-Content-Type-Options "nosniff"
      Header set Content-Security-Policy "default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'"
    </IfModule>
    Options -ExecCGI -Indexes
    HT;
    @file_put_contents(UPLOAD_ROOT . '/.htaccess', preg_replace('~^ {4}~m', '', $ht));
    @file_put_contents(UPLOAD_ROOT . '/index.html', '');
}

/**
 * Instalacja: schemat + ustawienia + konto admina (+ demo).
 * Zwraca listę błędów (pusta = sukces).
 */
function install_run(array $in): array
{
    $errors = install_validate($in);
    if ($errors) return $errors;

    try {
        install_schema();
        install_protect_uploads();

        $preset = theme_presets()[$in['preset']];
        unset($preset['label']);

        // „Alicja Bazan” → imię zwykłe + nazwisko pogrubione (jak na wizytówce)
        $parts     = preg_split('~\s+~', trim((string)$in['owner_name'])) ?: [];
        $firstPart = count($parts) > 1 ? implode(' ', array_slice($parts, 0, -1)) : (string)$in['owner_name'];
        $lastPart  = count($parts) > 1 ? (string)end($parts) : '';

        $settings = array_merge(default_settings(), $preset, [
            'site_name'       => $in['site_name'],
            'owner_name'      => $firstPart,
            'owner_name_bold' => $lastPart,
            'tagline'         => (string)($in['tagline'] ?? ''),
            'email'           => $in['email'],
            'meta_title'      => $in['site_name'],
        ]);
        settings_save($settings);

        db_insert('users', [
            'email'         => $in['email'],
            'password_hash' => password_hash((string)$in['password'], PASSWORD_DEFAULT),
            'name'          => $in['owner_name'],
            'role'          => 'admin',
        ]);

        if (!empty($in['seed'])) install_seed((string)$in['owner_name']);

        return [];
    } catch (Throwable $e) {
        return ['Błąd instalacji: ' . $e->getMessage()];
    }
}

/** Treści demonstracyjne — od razu widoczna, sensowna strona. */
function install_seed(string $owner): void
{
    $pageId = db_insert('pages', [
        'slug'             => 'o-mnie',
        'title'            => 'O mnie',
        'excerpt'          => 'Kilka słów o tym, czym się zajmuję.',
        'format'           => 'markdown',
        'content'          => "## Czym się zajmuję\n\nProwadzę szkolenia, warsztaty i wystąpienia. Ta podstrona to przykład — "
                            . "edytuj ją w panelu **Podstrony**.\n\n- Szkolenia i warsztaty\n- Wystąpienia publiczne\n- Konsultacje\n\n"
                            . "> Treść możesz pisać w Markdownie albo w HTML.",
        'meta_title'       => 'O mnie',
        'meta_description' => 'Kilka słów o tym, czym się zajmuję.',
        'position'         => 1,
    ]);

    $galId = db_insert('galleries', [
        'slug'        => 'realizacje',
        'title'       => 'Realizacje',
        'description' => 'Wybrane zdjęcia ze zrealizowanych projektów. Dodaj własne w panelu → Galerie.',
        'position'    => 1,
    ]);

    $tiles = [
        ['type' => 'heading', 'size' => 'full', 'title' => 'Czym się zajmuję', 'position' => 1],
        ['type' => 'text',    'size' => 'lg',   'title' => 'Krótko o mnie',
         'body' => "Cześć! Nazywam się {$owner}. Ten kafelek to widżet tekstowy — zmień jego treść w panelu → Kafelki.",
         'icon' => 'bi-lightbulb-fill', 'position' => 2],
        ['type' => 'page',    'size' => 'sm',   'title' => 'O mnie', 'page_id' => $pageId,
         'icon' => 'bi-file-text', 'position' => 3],
        ['type' => 'gallery', 'size' => 'wide', 'title' => 'Realizacje', 'subtitle' => 'Galeria zdjęć',
         'gallery_id' => $galId, 'icon' => 'bi-images', 'position' => 4],
        ['type' => 'divider', 'size' => 'full', 'position' => 5],
        ['type' => 'social',  'size' => 'sm',   'title' => 'LinkedIn', 'url' => 'https://www.linkedin.com/',
         'icon' => 'bi-linkedin', 'position' => 6],
        ['type' => 'social',  'size' => 'sm',   'title' => 'Instagram', 'url' => 'https://www.instagram.com/',
         'icon' => 'bi-instagram', 'position' => 7],
        ['type' => 'social',  'size' => 'sm',   'title' => 'Facebook', 'url' => 'https://www.facebook.com/',
         'icon' => 'bi-facebook', 'position' => 8],
        ['type' => 'link',    'size' => 'wide', 'title' => 'Zaproś mnie do siebie!', 'subtitle' => 'Napisz, chętnie przyjadę',
         'url' => 'mailto:' . setting('email'), 'icon' => 'bi-geo-alt-fill', 'position' => 9],
    ];
    foreach ($tiles as $t) {
        db_insert('bento_tiles', $t);
    }
}
