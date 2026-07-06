<?php
/**
 * feer_theme — nakładka kolorystyczna skina Elastic dopasowana do barw FEER SZO
 * (ta sama para kolorów co domyślne `sidebar_color`/`volunteer_color` w
 * admin/org_settings.php głównej aplikacji — tu wpisana na trwałe, bo Roundcube
 * działa w osobnym kontenerze bez dostępu do bazy głównej apki).
 *
 * Celowo TYLKO nakładka CSS na wbudowany skin Elastic (nie forka skina) — mniej
 * kodu do utrzymania i przetrwa aktualizacje obrazu roundcube/roundcubemail.
 * Bez $this->task — ma się wczytywać na każdym zadaniu (login, mail, ustawienia).
 */

class feer_theme extends rcube_plugin
{
    public function init(): void
    {
        $this->include_stylesheet('feer_theme.css');
    }
}
