<?php
/**
 * feer_theme — warstwa wizualna webmaila (skin Elastic) w barwach modułu Poczta
 * głównej aplikacji (paleta z poczta/includes/header_poczta.php: granat
 * #1e3a8a, niebieski #1d4ed8, focus #facc15, font system-ui). Webmail bywa
 * osadzany w iframe (crm/webmail.php), więc ma wyglądać jak przedłużenie
 * modułu, a nie jak obca aplikacja.
 *
 * Celowo TYLKO nakładka CSS na wbudowany skin Elastic (nie fork skina) —
 * arkusz pluginu ładuje się PO arkuszu skina, więc wygrywa przy równej
 * specyficzności, jest o rzędy wielkości mniejszy w utrzymaniu i przeżywa
 * aktualizacje obrazu roundcube/roundcubemail. Szczegóły i pułapki: feer_theme.css.
 *
 * Bez $this->task — arkusz ma się wczytywać na każdym zadaniu (login, mail,
 * kontakty, ustawienia).
 */

class feer_theme extends rcube_plugin
{
    /**
     * Cache-buster arkusza. BUMPOWAĆ po każdej zmianie feer_theme.css —
     * inaczej przeglądarki użytkowników zostaną na starej wersji (plugin jest
     * montowany read-only, więc mtime pliku w kontenerze nie jest wskazówką
     * dla przeglądarki, a Roundcube nie wersjonuje arkuszy pluginów).
     */
    private const ASSET_VERSION = '2';

    public function init(): void
    {
        $this->include_stylesheet('feer_theme.css?v=' . self::ASSET_VERSION);
    }
}
