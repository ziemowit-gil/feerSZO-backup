<?php
/**
 * settings.php — ustawienia główne strony (profil, kontakt, wygląd, SEO).
 */
declare(strict_types=1);

require_once APP_ROOT . '/includes/installer.php';   // presety kolorów

/** Pola tekstowe zapisywane 1:1 z formularza. */
function settings_text_fields(): array
{
    return [
        // Profil
        'site_name', 'owner_name', 'owner_name_bold', 'tagline', 'headline', 'bio',
        'job_title', 'company', 'avatar_shape',
        // Kontakt
        'email', 'phone', 'website', 'location', 'vcard_label',
        // Wygląd
        'site_layout', 'font_family',
        'color_bg', 'color_accent', 'color_accent2', 'color_text', 'color_heading',
        'color_btn_bg', 'color_btn_text', 'color_rule',
        // SEO / stopka
        'meta_title', 'meta_description', 'meta_keywords', 'footer_text', 'analytics_code',
    ];
}

function admin_settings(): void
{
    $errors = [];

    if (is_post()) {
        csrf_check();

        // 1) Szybkie zastosowanie presetu kolorów
        $preset = post('apply_preset');
        if ($preset !== '' && isset(theme_presets()[$preset])) {
            $p = theme_presets()[$preset];
            unset($p['label']);
            settings_save($p);
            flash('success', 'Zastosowano preset wyglądu.');
            redirect('/admin/settings#wyglad');
        }

        // 2) Walidacja
        if (post('site_name') === '')                                        $errors[] = 'Nazwa strony jest wymagana.';
        if (post('email') !== '' && !filter_var(post('email'), FILTER_VALIDATE_EMAIL)) $errors[] = 'Adres e-mail jest nieprawidłowy.';
        if (post('website') !== '' && !preg_match('~^https?://~i', post('website'))) $errors[] = 'Adres strony musi zaczynać się od http:// lub https://';
        foreach (['color_bg','color_accent','color_accent2','color_text','color_heading','color_btn_bg','color_btn_text','color_rule'] as $c) {
            if (post($c) !== '' && !preg_match('~^#[0-9a-f]{6}$~i', post($c))) {
                $errors[] = 'Nieprawidłowy kolor w polu ' . $c . ' (oczekiwany format #RRGGBB).';
            }
        }

        // 3) Pliki: avatar i obraz Open Graph
        $uploads = [];
        foreach (['avatar' => 'avatars', 'og_image' => 'tiles'] as $field => $subdir) {
            if (!empty($_FILES[$field]['name'])) {
                $res = save_uploaded_image($_FILES[$field], $subdir);
                if (!$res['ok']) { $errors[] = ucfirst($field) . ': ' . $res['error']; }
                else             { $uploads[$field] = $subdir . '/' . $res['file']; }
            }
        }

        if (!$errors) {
            $data = [];
            foreach (settings_text_fields() as $f) {
                if (array_key_exists($f, $_POST)) $data[$f] = post($f);
            }
            // Pola logiczne (checkboxy)
            $data['vcard_enabled'] = post_bool('vcard_enabled') ? '1' : '0';
            $data['bg_pattern']    = post_bool('bg_pattern') ? '1' : '0';

            // Usuwanie / podmiana plików
            foreach (['avatar', 'og_image'] as $field) {
                if (post_bool('remove_' . $field) && setting($field) !== '') {
                    delete_upload(setting($field));
                    $data[$field] = '';
                }
                if (isset($uploads[$field])) {
                    if (setting($field) !== '') delete_upload(setting($field));
                    $data[$field] = $uploads[$field];
                }
            }

            settings_save($data);
            flash('success', 'Ustawienia zapisane.');
            redirect('/admin/settings');
        }
    }

    admin_render('settings', [
        'page_title' => 'Ustawienia',
        'errors'     => $errors,
        'presets'    => theme_presets(),
    ]);
}
