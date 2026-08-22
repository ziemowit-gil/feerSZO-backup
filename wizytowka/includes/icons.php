<?php
/**
 * icons.php — ikony Bootstrap Icons (CDN, licencja MIT) + emoji.
 * W bazie przechowujemy nazwę klasy (np. "bi-linkedin") albo emoji.
 */
declare(strict_types=1);

/** Zestaw podpowiedzi dla pickera w panelu: klasa => etykieta. */
function icon_choices(): array
{
    return [
        'Kontakt' => [
            'bi-envelope-fill'   => 'E-mail',
            'bi-telephone-fill'  => 'Telefon',
            'bi-whatsapp'        => 'WhatsApp',
            'bi-geo-alt-fill'    => 'Lokalizacja',
            'bi-calendar-check'  => 'Kalendarz / termin',
            'bi-globe2'          => 'Strona www',
            'bi-download'        => 'Pobierz',
            'bi-file-earmark-pdf'=> 'PDF',
        ],
        'Social media' => [
            'bi-linkedin'        => 'LinkedIn',
            'bi-facebook'        => 'Facebook',
            'bi-instagram'       => 'Instagram',
            'bi-twitter-x'       => 'X (Twitter)',
            'bi-youtube'         => 'YouTube',
            'bi-tiktok'          => 'TikTok',
            'bi-spotify'         => 'Spotify',
            'bi-github'          => 'GitHub',
            'bi-behance'         => 'Behance',
            'bi-dribbble'        => 'Dribbble',
            'bi-pinterest'       => 'Pinterest',
            'bi-threads'         => 'Threads',
            'bi-medium'          => 'Medium',
            'bi-substack'        => 'Substack',
        ],
        'Treść' => [
            'bi-images'          => 'Galeria',
            'bi-file-text'       => 'Podstrona',
            'bi-star-fill'       => 'Wyróżnienie',
            'bi-mortarboard-fill'=> 'Szkolenia',
            'bi-mic-fill'        => 'Wystąpienia',
            'bi-newspaper'       => 'Media',
            'bi-award-fill'      => 'Nagrody',
            'bi-heart-fill'      => 'Serce',
            'bi-lightbulb-fill'  => 'Pomysł',
            'bi-briefcase-fill'  => 'Praca',
            'bi-chat-quote-fill' => 'Opinie',
            'bi-arrow-right'     => 'Strzałka',
        ],
    ];
}

/** HTML ikony: klasa Bootstrap Icons albo emoji/tekst. */
function icon_html(string $icon, string $extraClass = ''): string
{
    $icon = trim($icon);
    if ($icon === '') return '';
    if (preg_match('~^bi-[a-z0-9-]+$~', $icon)) {
        return '<i class="bi ' . e($icon) . ' ' . e($extraClass) . '" aria-hidden="true"></i>';
    }
    return '<span class="icon-emoji ' . e($extraClass) . '" aria-hidden="true">' . e(mb_substr($icon, 0, 4)) . '</span>';
}

/** Domyślna ikona dla typu kafelka. */
function icon_for_type(string $type): string
{
    return match ($type) {
        'page'    => 'bi-file-text',
        'gallery' => 'bi-images',
        'email'   => 'bi-envelope-fill',
        'phone'   => 'bi-telephone-fill',
        'link'    => 'bi-box-arrow-up-right',
        default   => '',
    };
}
