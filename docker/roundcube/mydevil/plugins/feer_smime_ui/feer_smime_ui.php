<?php
/**
 * feer_smime_ui — zwięzły wygląd S/MIME (mimeshield) w podglądzie wiadomości i oknie pisania.
 *
 * Okno pisania: przycisk „Podpis cyfrowy” na pasku narzędzi otwiera okno z opcjami S/MIME
 * (#mimeshield-compose, także wiersz feer_qsign). Pola wracają do ukrytego miejsca w formularzu
 * po zamknięciu okna, więc wysyłka ich nie gubi.
 *
 * Podgląd: zamiast plakietki z opisem i dużej ramki nad treścią jest sama kłódka obok nagłówków:
 * zielona zamknięta = podpis prawidłowy, certyfikat zaufany, adres zgodny (i poprawnie odszyfrowana),
 * czerwona otwarta z wykrzyknikiem = cokolwiek innego (podpis nieprawidłowy, niezaufany urząd,
 * niezgodny adres, błąd odszyfrowania, zaszyfrowana bez podpisu, tylko część podpisana).
 * Wiadomość bez S/MIME: nic. Kliknięcie kłódki pokazuje/ukrywa szczegóły mimeshield.
 *
 * Ocena „zielona” = VerificationResult::level() === 'ok' z mimeshield (1.0.3). Wyłączone sprawdzanie
 * unieważnień (mimeshield_revocation = 'off') level() traktuje jako OK; ostrzeżenie „nie sprawdzono
 * unieważnienia” dokłada dopiero MessageUi, więc tu go nie ma (zostaje w szczegółach).
 * Korzysta z wewnętrznego API mimeshield (services()->incoming()->statuses()) — po aktualizacji
 * mimeshield sprawdzić; każdy wyjątek = brak kłódki, plakietka mimeshield zostaje widoczna.
 *
 * Kolejność w config.inc.php: PO mimeshield.
 */
class feer_smime_ui extends rcube_plugin
{
    public $task = 'mail';

    /** czy kłódka została wstawiona (CSS chowa plakietkę mimeshield tylko wtedy) */
    private bool $rendered = false;

    public function init(): void
    {
        $rc = rcmail::get_instance();
        if (!in_array($rc->action, ['show', 'preview', 'compose'], true)) {
            return;
        }
        $this->include_stylesheet('feer_smime_ui.css');
        $this->add_texts('localization/', true);
        $this->include_script('feer_smime_ui.js');
        if ($rc->action === 'compose') {
            // Opcje S/MIME (mimeshield + feer_qsign) w wyskakującym oknie pod przyciskiem paska narzędzi.
            $this->add_button([
                'command' => 'plugin.feer_smime_ui-security',
                'type' => 'link',
                'class' => 'feer-smime-btn',
                'classact' => 'feer-smime-btn',
                'innerclass' => 'inner',
                'label' => 'feer_smime_ui.security',
                'title' => 'feer_smime_ui.security_title',
                'tabindex' => '2',
                'aria-haspopup' => 'dialog',
            ], 'toolbar');
            return;
        }
        $this->add_hook('template_object_messageheaders', [$this, 'messageheaders']);
    }

    public function messageheaders(array $p): array
    {
        if (!empty($p['valueof']) || !empty($p['valueOf']) || $this->rendered) {
            return $p;
        }
        $state = $this->state();
        if ($state === null) {
            return $p;
        }
        [$ok, $label] = $state;
        $this->rendered = true;
        $rc = rcmail::get_instance();
        $rc->output->set_env('feer_smime_lock', $ok ? 'ok' : 'problem');

        $btn = html::tag('button', [
            'type' => 'button',
            'class' => 'feer-smime-lock ' . ($ok ? 'feer-smime-ok' : 'feer-smime-problem'),
            'title' => $label,
            'aria-expanded' => 'false',
            'data-label' => $label,
        ], $this->icon($ok) . html::span('voice', rcube::Q($label)));

        $p['content'] = ($p['content'] ?? '') . html::div(['class' => 'feer-smime-lockbox', 'role' => 'status'], $btn);
        return $p;
    }

    /**
     * [czy OK, opis] albo null, gdy wiadomość nie ma S/MIME (albo mimeshield niedostępny).
     */
    private function state(): ?array
    {
        try {
            $ms = rcmail::get_instance()->plugins->get_plugin('mimeshield');
            if (!$ms || !method_exists($ms, 'services')) {
                return null;
            }
            $svc = $ms->services();
            if (!$svc->hasIncoming()) {
                return null;
            }
            $statuses = $svc->incoming(false)->statuses();
            if (!$statuses) {
                return null;
            }
            $ok = true;
            $encrypted = false;
            $signer = '';
            foreach ($statuses as $st) {
                $encrypted = $encrypted || $st->decryption === true;
                $sig = $st->signature;
                if ($sig !== null && $signer === '' && $sig->signer !== null) {
                    $signer = (string) $sig->signer->displayName();
                }
                $partOk = !is_string($st->decryption)
                    && !$st->notDecrypted
                    && !$st->partial
                    && $st->signatureError === null
                    && $sig !== null
                    && $sig->level() === 'ok'
                    && !($st->decryption === true && $st->unauthenticated && !$sig->cryptoValid());
                $ok = $ok && $partOk;
            }
            if ($ok) {
                $label = $this->gettext($encrypted ? 'lock_ok_enc' : 'lock_ok', ['signer' => $signer]);
            } else {
                $label = $this->gettext('lock_problem');
            }
            return [$ok, $label];
        } catch (\Throwable $e) {
            rcube::raise_error(['code' => 500, 'message' => 'feer_smime_ui: ' . $e->getMessage()], true, false);
            return null;
        }
    }

    private function icon(bool $ok): string
    {
        // Kształt różni oba stany niezależnie od koloru (WCAG 1.4.1): zamknięta z ✓ / otwarta z !
        $shackle = $ok
            ? '<path d="M7 10V7a5 5 0 0 1 10 0v3" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/>'
            : '<path d="M7 10V7a5 5 0 0 1 9.6-2" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/>';
        $mark = $ok
            ? '<path d="M8.6 15.4l2.3 2.3 4.6-4.8" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>'
            : '<path d="M12 12.6v3.2" stroke="#fff" stroke-width="2.2" stroke-linecap="round"/><circle cx="12" cy="18.6" r="1.2" fill="#fff"/>';
        return '<svg class="feer-smime-icon" viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false">'
            . $shackle . '<rect x="4" y="10" width="16" height="12" rx="2.5" fill="currentColor"/>' . $mark . '</svg>';
    }
}
