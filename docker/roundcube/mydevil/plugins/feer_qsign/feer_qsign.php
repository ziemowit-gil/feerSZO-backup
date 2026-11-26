<?php
/**
 * feer_qsign — „Podpisz certyfikatem kwalifikowanym” w oknie pisania (S/MIME, CAdES).
 *
 * Klucz kwalifikowany nigdy nie trafia na serwer. Wysyłka jest dwuetapowa:
 *  1. Wyślij z zaznaczoną opcją: message_ready buduje kanoniczną część MIME (te same bajty,
 *     które pójdą w multipart/signed), zapisuje ją w temp_dir i przerywa wysyłkę poleceniem
 *     JS z jednorazowym tokenem.
 *  2. Przeglądarka pobiera część (akcja entity), przekazuje ją agentowi SzoCert na
 *     127.0.0.1:52118 („szocert podpis”), który otwiera ją w PEM-HEART (rSign); użytkownik
 *     składa podpis zewnętrzny CAdES. Wynik (CMS) wraca akcją store — serwer sprawdza go
 *     openssl_cms_verify na zapisanych bajtach i wymaga certyfikatu kwalifikowanego.
 *  3. Ponowne Wyślij z tokenem: message_ready składa multipart/signed z ZAPISANEJ części
 *     (klasy mimeshield: EntityBuilder, SmimeMessage — stałe bajty dla SMTP i Wysłanych).
 *
 * Z mimeshield: nie wolno naraz zaznaczyć podpisu/szyfrowania mimeshield (dwa podpisy) —
 * JS odznacza, serwer odmawia. Plugin musi być PO mimeshield na liście plugins.
 *
 * PAdES załączników: przycisk „Podpisz załączniki PDF (PAdES)” w oknie „Podpis cyfrowy” podpisuje
 * każdy PDF z okna pisania od razu (przed wysyłką) przez agenta i PEM-HEART. Serwer sprawdza
 * ostatni podpis w PDF (/ByteRange obejmuje cały plik, CMS zgodny, certyfikat kwalifikowany)
 * i podmienia plik załącznika na miejscu (filesystem_attachments: path). Działa z S/MIME.
 *
 * Konfiguracja (config.inc.php):
 *   feer_qsign_users          — loginy z dostępem do opcji (pusta lista = wszyscy)
 *
 * Opcja jest w oknie „Podpis cyfrowy” (feer_smime_ui). Z agentem łączy się dopiero po jej
 * zaznaczeniu, więc pytanie przeglądarki o sieć lokalną widzi tylko ten, kto jej używa.
 *   feer_qsign_agent          — adres agenta (domyślnie http://127.0.0.1:52118)
 *   feer_qsign_require_qualified — wymagaj qcStatements w certyfikacie (domyślnie true)
 */
class feer_qsign extends rcube_plugin
{
    public $task = 'mail';

    private const MAX_BYTES = 40 * 1024 * 1024;
    private const TTL = 7200;
    private const QC_OID = '1.3.6.1.5.5.7.1.3';

    private rcmail $rc;
    private ?\Mail_mime $signed = null;

    public function init(): void
    {
        $this->rc = rcmail::get_instance();
        if (!$this->allowed()) {
            return;
        }
        $this->add_texts('localization/', true);

        if ($this->rc->action === 'compose') {
            $this->add_hook('template_container', [$this, 'template_container']);
            $this->include_script('feer_qsign.js');
            $this->include_stylesheet('feer_qsign.css');
            $this->rc->output->set_env('feer_qsign_agent', (string) $this->rc->config->get('feer_qsign_agent', 'http://127.0.0.1:52118'));
        }
        $this->add_hook('message_ready', [$this, 'message_ready']);
        $this->add_hook('message_before_send', [$this, 'message_before_send']);
        $this->add_hook('message_sent', [$this, 'message_sent']);
        $this->register_action('plugin.feer_qsign-entity', [$this, 'action_entity']);
        $this->register_action('plugin.feer_qsign-store', [$this, 'action_store']);
        $this->register_action('plugin.feer_qsign-pdfs', [$this, 'action_pdfs']);
        $this->register_action('plugin.feer_qsign-pdf', [$this, 'action_pdf']);
        $this->register_action('plugin.feer_qsign-pdfstore', [$this, 'action_pdfstore']);
    }

    private function allowed(): bool
    {
        if (empty($_SESSION['user_id'])) {
            return false;
        }
        $users = array_map('strtolower', (array) $this->rc->config->get('feer_qsign_users', []));
        return !$users || in_array(strtolower((string) $this->rc->get_user_name()), $users, true);
    }

    // ── okno pisania ────────────────────────────────────────────────────────

    public function template_container(array $p): array
    {
        if (($p['name'] ?? '') !== 'composeoptions') {
            return $p;
        }
        $chk = new html_checkbox(['name' => '_feer_qsign', 'id' => 'feer-qsign', 'value' => '1', 'class' => 'form-check-input']);
        $row = html::div(['id' => 'feer-qsign-row', 'class' => 'form-group form-check row feer-qsign-row'],
            html::label(['for' => 'feer-qsign', 'class' => 'col-form-label col-6'], rcube::Q($this->gettext('option')))
            . html::div('form-check col-6', $chk->show(''))
        )
        . html::div(['id' => 'feer-qsign-status', 'class' => 'feer-qsign-status', 'aria-live' => 'polite', 'hidden' => 'hidden'], '')
        . html::tag('input', ['type' => 'hidden', 'name' => '_feer_qsign_token', 'id' => 'feer-qsign-token', 'value' => ''])
        . html::div(['id' => 'feer-pades-row', 'class' => 'feer-pades-row'],
            html::tag('button', ['type' => 'button', 'id' => 'feer-pades-btn', 'class' => 'btn btn-secondary feer-pades-btn'], rcube::Q($this->gettext('pades_button')))
            . html::div(['id' => 'feer-pades-status', 'class' => 'feer-qsign-status', 'aria-live' => 'polite', 'hidden' => 'hidden'], ''));
        $p['content'] = ($p['content'] ?? '') . $row;
        return $p;
    }

    // ── wysyłka ─────────────────────────────────────────────────────────────

    public function message_ready(array $p): array
    {
        $saveonly = !empty($_GET['_saveonly']);
        $draft = !empty($_POST['_draft']) && !$saveonly;
        if ($draft || $saveonly || !rcube_utils::get_input_value('_feer_qsign', rcube_utils::INPUT_POST)) {
            return $p; // szkic zapisuje się bez podpisu
        }
        if (rcube_utils::get_input_value('_mimeshield_sign', rcube_utils::INPUT_POST)
            || rcube_utils::get_input_value('_mimeshield_encrypt', rcube_utils::INPUT_POST)
            || $this->isSmime($p['message'])) {
            $this->abort('conflict');
        }
        if (!class_exists('\MimeShield\Mime\EntityBuilder') || !class_exists('\MimeShield\Mime\SmimeMessage')) {
            $this->abort('nomimeshield');
        }

        $composeId = (string) rcube_utils::get_input_string('_id', rcube_utils::INPUT_POST);
        $token = (string) rcube_utils::get_input_string('_feer_qsign_token', rcube_utils::INPUT_POST);
        $job = $token !== '' ? $this->job($token) : null;

        // 2. etap: podpis gotowy i sprawdzony
        if ($job && !empty($job['verified']) && $job['compose'] === $composeId) {
            $inner = @file_get_contents($this->path($token, 'eml'));
            $der = @file_get_contents($this->path($token, 'p7s'));
            if ($inner === false || $der === false || hash('sha256', $inner) !== $job['sha']) {
                $this->abort('expired');
            }
            $s = \MimeShield\Mime\EntityBuilder::clearSigned($inner, $der, $job['micalg']);
            $p['message'] = new \MimeShield\Mime\SmimeMessage($p['message'], ['Content-Type' => $s['contentType']], $s['body'], true, false);
            $this->signed = $p['message'];
            rcube::write_log('feer_qsign', sprintf('user %s: wysyłka podpisana (%s, %s)', $this->rc->get_user_name(), $job['signer'], $job['micalg']));
            return $p;
        }

        // 1. etap: zbuduj część do podpisu i poproś przeglądarkę o podpis
        $msg = $p['message'];
        \MimeShield\Mime\EntityBuilder::prepareOriginal($msg, true);
        $inner = \MimeShield\Mime\EntityBuilder::innerEntity($msg);
        if (strlen($inner) > self::MAX_BYTES) {
            $this->abort('toolarge');
        }
        $this->cleanup();
        $token = bin2hex(random_bytes(16));
        $dir = $this->dir();
        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
            $this->abort('internal');
        }
        if (@file_put_contents($this->path($token, 'eml'), $inner) === false) {
            $this->abort('internal');
        }
        @chmod($this->path($token, 'eml'), 0600);
        $_SESSION['feer_qsign'][$token] = [
            'compose' => $composeId, 'time' => time(), 'sha' => hash('sha256', $inner), 'verified' => false,
        ];

        $h = \MimeShield\Mime\SmimeMessage::rawHeaders($msg);
        $this->rc->output->command('plugin.feer_qsign_sign', [
            'token' => $token,
            'from' => (string) ($h['From'] ?? ''),
            'to' => trim(implode(', ', array_filter([(string) ($h['To'] ?? ''), (string) ($h['Cc'] ?? '')]))),
            'subject' => (string) ($h['Subject'] ?? ''),
            'size' => strlen($inner),
        ]);
        $this->rc->output->send('iframe');
        exit;
    }

    /** Net_SMTP: kropka na granicy porcji psuje podpis — to samo zabezpieczenie co w mimeshield. */
    public function message_before_send(array $p): array
    {
        $msg = $p['message'] ?? null;
        if ($this->signed === null || !$this->isSmime($msg) || !class_exists('\MimeShield\Mime\DotGuard')) {
            return $p;
        }
        $safe = \MimeShield\Mime\DotGuard::makeSafe(
            static fn (): string => (clone $msg)->txtHeaders(['Bcc' => null], true) . "\r\n" . $msg->body(),
            static fn () => $msg->padPreamble(1),
        );
        if (!$safe) {
            return ['abort' => true, 'result' => false, 'error' => ['label' => 'feer_qsign.internal', 'vars' => []]] + $p;
        }
        return $p;
    }

    public function message_sent(array $p): array
    {
        if ($this->signed !== null) {
            $token = (string) rcube_utils::get_input_string('_feer_qsign_token', rcube_utils::INPUT_POST);
            $this->forget($token);
        }
        return $p;
    }

    // ── akcje dla JS ────────────────────────────────────────────────────────

    /** Część MIME do podpisu (Base64). Tylko z nagłówkiem X-Roundcube-Request. */
    public function action_entity(): void
    {
        $this->requireAjax();
        $token = (string) rcube_utils::get_input_string('_qtoken', rcube_utils::INPUT_GET);
        $data = $this->job($token) ? @file_get_contents($this->path($token, 'eml')) : false;
        if ($data === false) {
            $this->json(404, ['ok' => false, 'error' => $this->gettext('expired')]);
        }
        $this->json(200, ['ok' => true, 'data_b64' => base64_encode($data)]);
    }

    /** Wynik z agenta: sprawdź CMS na zapisanych bajtach, zapamiętaj do 2. etapu. */
    public function action_store(): void
    {
        $this->requireAjax();
        $token = (string) rcube_utils::get_input_string('_qtoken', rcube_utils::INPUT_POST);
        $job = $this->job($token);
        $inner = $job ? @file_get_contents($this->path($token, 'eml')) : false;
        if ($inner === false) {
            $this->json(404, ['ok' => false, 'error' => $this->gettext('expired')]);
        }
        $der = base64_decode((string) ($_POST['_cms'] ?? ''), true);
        if ($der === false || $der === '') {
            $this->json(400, ['ok' => false, 'error' => $this->gettext('badsignature')]);
        }
        try {
            $info = $this->verify($inner, $der);
        } catch (\Throwable $e) {
            rcube::write_log('feer_qsign', 'user ' . $this->rc->get_user_name() . ': podpis odrzucony: ' . $e->getMessage());
            $this->json(422, ['ok' => false, 'error' => $e->getMessage()]);
        }
        file_put_contents($this->path($token, 'p7s'), $der);
        @chmod($this->path($token, 'p7s'), 0600);
        $_SESSION['feer_qsign'][$token] = array_merge($job, ['verified' => true] + $info);
        $this->json(200, ['ok' => true, 'signer' => $info['signer'], 'micalg' => $info['micalg']]);
    }

    // ── PAdES załączników ───────────────────────────────────────────────────

    /** Załączniki PDF bieżącego okna pisania. */
    public function action_pdfs(): void
    {
        $this->requireAjax();
        $out = [];
        foreach ($this->rc->list_uploaded_files($this->composeId()) as $a) {
            if (!$this->isPdf($a)) {
                continue;
            }
            $out[] = ['id' => (string) $a['id'], 'name' => (string) $a['name'], 'size' => (int) ($a['size'] ?? 0),
                'signed' => !empty($a['feer_pades'])];
        }
        $this->json(200, ['ok' => true, 'files' => $out]);
    }

    public function action_pdf(): void
    {
        $this->requireAjax();
        $a = $this->pdfAttachment((string) rcube_utils::get_input_string('_file', rcube_utils::INPUT_GET));
        $this->json(200, ['ok' => true, 'name' => (string) $a['name'], 'data_b64' => base64_encode($this->attachmentData($a))]);
    }

    /** Podpisany PDF z agenta: sprawdź podpis i podmień plik załącznika. */
    public function action_pdfstore(): void
    {
        $this->requireAjax();
        $a = $this->pdfAttachment((string) rcube_utils::get_input_string('_file', rcube_utils::INPUT_POST));
        $orig = $this->attachmentData($a);
        $pdf = base64_decode((string) ($_POST['_pdf'] ?? ''), true);
        if ($pdf === false || strncmp($pdf, '%PDF-', 5) !== 0) {
            $this->json(400, ['ok' => false, 'error' => $this->gettext('pades_bad')]);
        }
        try {
            [$content, $der] = $this->padesParts($pdf);
            $info = $this->verify($content, $der);
        } catch (\Throwable $e) {
            rcube::write_log('feer_qsign', 'user ' . $this->rc->get_user_name() . ': PAdES odrzucony: ' . $e->getMessage());
            $this->json(422, ['ok' => false, 'error' => $e->getMessage()]);
        }
        $path = (string) ($a['path'] ?? '');
        if ($path === '' || !is_file($path) || file_put_contents($path, $pdf) !== strlen($pdf)) {
            $this->json(500, ['ok' => false, 'error' => $this->gettext('internal')]);
        }
        $incremental = str_starts_with($pdf, $orig);
        $this->rc->update_uploaded_file($a['id'], ['size' => strlen($pdf), 'feer_pades' => $info['signer']]);
        rcube::write_log('feer_qsign', sprintf('user %s: PAdES %s (%s, %s)', $this->rc->get_user_name(), $a['name'], $info['signer'], $incremental ? 'przyrostowo' : 'NIEPRZYROSTOWO'));
        $this->json(200, ['ok' => true, 'signer' => $info['signer'], 'incremental' => $incremental, 'size' => strlen($pdf)]);
    }

    /**
     * Ostatni podpis PDF: [bajty z /ByteRange, CMS DER]. Podpis musi obejmować cały plik.
     *
     * @return array{0: string, 1: string}
     */
    private function padesParts(string $pdf): array
    {
        if (!preg_match_all('#/ByteRange\s*\[\s*(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s*\]#', $pdf, $m, PREG_SET_ORDER)) {
            throw new \RuntimeException($this->gettext('pades_nosig'));
        }
        [, $a, $b, $c, $d] = array_map('intval', end($m));
        if ($a !== 0 || $b <= 0 || $c <= $b || $c + $d !== strlen($pdf)) {
            throw new \RuntimeException($this->gettext('pades_partial'));
        }
        $hex = trim(substr($pdf, $b, $c - $b));
        if (!preg_match('/^<([0-9A-Fa-f\s]+)>$/', $hex, $hm)) {
            throw new \RuntimeException($this->gettext('pades_bad'));
        }
        $der = (string) hex2bin(preg_replace('/\s+/', '', $hm[1]));
        $der = substr($der, 0, $this->derLength($der)); // bez dopełnienia zerami
        return [substr($pdf, 0, $b) . substr($pdf, $c, $d), $der];
    }

    /** Długość pierwszego elementu DER (SEQUENCE CMS). */
    private function derLength(string $der): int
    {
        if (strlen($der) < 2 || ord($der[0]) !== 0x30) {
            throw new \RuntimeException($this->gettext('pades_bad'));
        }
        $l = ord($der[1]);
        if ($l < 0x80) {
            return 2 + $l;
        }
        $n = $l & 0x7f;
        if ($n < 1 || $n > 4 || strlen($der) < 2 + $n) {
            throw new \RuntimeException($this->gettext('pades_bad'));
        }
        $len = 0;
        for ($i = 0; $i < $n; $i++) {
            $len = ($len << 8) | ord($der[2 + $i]);
        }
        if (2 + $n + $len > strlen($der)) {
            throw new \RuntimeException($this->gettext('pades_bad'));
        }
        return 2 + $n + $len;
    }

    private function composeId(): string
    {
        $id = (string) rcube_utils::get_input_string('_id', rcube_utils::INPUT_GPC);
        if ($id === '' || empty($_SESSION['compose_data_' . $id])) {
            $this->json(404, ['ok' => false, 'error' => $this->gettext('expired')]);
        }
        return $id;
    }

    private function isPdf(array $a): bool
    {
        return strtolower((string) ($a['mimetype'] ?? '')) === 'application/pdf'
            || preg_match('/\.pdf$/i', (string) ($a['name'] ?? ''));
    }

    private function pdfAttachment(string $fileId): array
    {
        $group = $this->composeId();
        $a = $fileId !== '' ? $this->rc->get_uploaded_file($fileId) : null;
        if (!$a || (string) ($a['group'] ?? '') !== $group || !$this->isPdf($a)) {
            $this->json(404, ['ok' => false, 'error' => $this->gettext('pades_nofile')]);
        }
        return $a;
    }

    private function attachmentData(array $a): string
    {
        $a = $this->rc->plugins->exec_hook('attachment_get', $a);
        $data = !empty($a['data']) ? (string) $a['data'] : (!empty($a['path']) ? (string) @file_get_contents($a['path']) : '');
        if ($data === '' || strlen($data) > self::MAX_BYTES) {
            $this->json(422, ['ok' => false, 'error' => $this->gettext($data === '' ? 'pades_nofile' : 'toolarge')]);
        }
        return $data;
    }

    /**
     * @return array{micalg: string, signer: string}
     */
    private function verify(string $inner, string $der): array
    {
        $sd = \MimeShield\Crypto\CmsInspector::signedData($der);
        if (empty($sd['detached'])) {
            throw new \RuntimeException($this->gettext('notdetached'));
        }
        if (count($sd['signers'] ?? []) !== 1) {
            throw new \RuntimeException($this->gettext('badsignature'));
        }
        $micalg = \MimeShield\Crypto\CmsInspector::micalg((string) ($sd['digests'][0] ?? ''));
        if ($micalg === null) {
            throw new \RuntimeException($this->gettext('baddigest'));
        }

        $tmp = rtrim((string) $this->rc->config->get('temp_dir'), '/');
        $in = tempnam($tmp, 'fq');
        $sig = tempnam($tmp, 'fq');
        $signers = tempnam($tmp, 'fq');
        try {
            file_put_contents($in, $inner);
            file_put_contents($sig, $der);
            while (openssl_error_string() !== false) {
            }
            $ok = openssl_cms_verify($in, OPENSSL_CMS_DETACHED | OPENSSL_CMS_BINARY | OPENSSL_CMS_NOVERIFY,
                $signers, [], null, null, null, $sig, OPENSSL_ENCODING_DER);
            if ($ok !== true) {
                throw new \RuntimeException($this->gettext('badsignature') . ' (' . (string) openssl_error_string() . ')');
            }
            $pem = (string) file_get_contents($signers);
        } finally {
            @unlink($in);
            @unlink($sig);
            @unlink($signers);
        }

        $cert = openssl_x509_parse($pem);
        if (!$cert) {
            throw new \RuntimeException($this->gettext('badsignature'));
        }
        if (time() < (int) $cert['validFrom_time_t'] || time() > (int) $cert['validTo_time_t']) {
            throw new \RuntimeException($this->gettext('certexpired'));
        }
        $qualified = isset($cert['extensions']['qcStatements']) || isset($cert['extensions'][self::QC_OID]);
        if (!$qualified && $this->rc->config->get('feer_qsign_require_qualified', true)) {
            throw new \RuntimeException($this->gettext('notqualified'));
        }
        $signer = (string) ($cert['subject']['CN'] ?? '?');
        $issuer = (string) ($cert['issuer']['O'] ?? $cert['issuer']['CN'] ?? '');
        return ['micalg' => $micalg, 'signer' => $signer . ($issuer !== '' ? ' (' . $issuer . ')' : '')];
    }

    // ── pomocnicze ──────────────────────────────────────────────────────────

    private function isSmime($msg): bool
    {
        return class_exists('\MimeShield\Mime\SmimeMessage') && $msg instanceof \MimeShield\Mime\SmimeMessage;
    }

    private function requireAjax(): void
    {
        if (rcube_utils::request_header('X-Roundcube-Request') !== $this->rc->get_request_token()) {
            $this->json(403, ['ok' => false, 'error' => 'Request security check failed']);
        }
    }

    private function json(int $code, array $data): void
    {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    private function abort(string $label): void
    {
        $this->rc->output->show_message('feer_qsign.' . $label, 'error');
        $this->rc->output->command('plugin.feer_qsign_reset', []);
        $this->rc->output->send('iframe');
        exit;
    }

    private function job(string $token): ?array
    {
        if (!preg_match('/^[0-9a-f]{32}$/', $token)) {
            return null;
        }
        $job = $_SESSION['feer_qsign'][$token] ?? null;
        if (!is_array($job) || time() - (int) $job['time'] > self::TTL) {
            return null;
        }
        return $job;
    }

    private function dir(): string
    {
        return rtrim((string) $this->rc->config->get('temp_dir'), '/') . '/feer_qsign/' . (int) $_SESSION['user_id'];
    }

    private function path(string $token, string $ext): string
    {
        return $this->dir() . '/' . $token . '.' . $ext;
    }

    private function forget(string $token): void
    {
        if (preg_match('/^[0-9a-f]{32}$/', $token)) {
            @unlink($this->path($token, 'eml'));
            @unlink($this->path($token, 'p7s'));
            unset($_SESSION['feer_qsign'][$token]);
        }
    }

    /** Usuwa przeterminowane zadania tego użytkownika (sesja i pliki). */
    private function cleanup(): void
    {
        foreach ((array) ($_SESSION['feer_qsign'] ?? []) as $t => $j) {
            if (!is_array($j) || time() - (int) ($j['time'] ?? 0) > self::TTL) {
                $this->forget((string) $t);
                unset($_SESSION['feer_qsign'][$t]);
            }
        }
        foreach (glob($this->dir() . '/*.{eml,p7s}', GLOB_BRACE) ?: [] as $f) {
            if (time() - (int) @filemtime($f) > self::TTL) {
                @unlink($f);
            }
        }
    }
}
