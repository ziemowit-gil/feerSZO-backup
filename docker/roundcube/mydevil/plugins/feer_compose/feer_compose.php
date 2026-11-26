<?php
/**
 * feer_compose — układ okna pisania jak w Outlooku: „Wyślij” na górze po lewej od pól Od/Do/Temat,
 * zwarte pola, edytor na całą wysokość, S/MIME (mimeshield) w pasku pod tematem z jasnym
 * komunikatem i linkiem „Wydaj certyfikat FEER”, gdy brak certyfikatu. Przenosi oryginalne
 * elementy (nie kopie), więc działanie przycisków się nie zmienia. Wygląd: feer_theme.css.
 */
class feer_compose extends rcube_plugin
{
    public $task = 'mail';

    public function init(): void
    {
        $rc = rcmail::get_instance();
        if ($rc->action !== 'compose') {
            return;
        }
        $this->add_texts('localization/', true);
        $this->include_script('feer_compose.js');
        $hosts = (array) $rc->config->get('feer_cert_hosts', []);
        $canIssue = in_array('feer_cert', (array) $rc->config->get('plugins', []), true)
            && (!$hosts || in_array((string) ($_SESSION['storage_host'] ?? ''), $hosts, true));
        $rc->output->set_env('feer_cert_url', $canIssue ? './?_task=settings&_action=plugin.feer_cert' : '');
    }
}
