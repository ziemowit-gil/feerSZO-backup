<?php
declare(strict_types=1);

/**
 * modules/dhosting_migrator/lib/DhostingGraphMail.php — odczyt poczty z Microsoft 365 (Graph, app-only) do kopiowania na dhosting.
 *
 * Własny klient zamiast M365Graph, bo potrzebujemy: surowego MIME (GET /messages/{id}/$value) zapisywanego strumieniowo
 * do pliku (M365Graph::http_get zawsze dekoduje JSON), obsługi 429/503 z Retry-After (M365Graph jej nie ma) i nagłówka
 * Prefer: IdType="ImmutableId" (identyfikatory wiadomości nie zmieniają się przy przeniesieniu — wznawianie bez duplikatów).
 * Token: client_credentials z tych samych ustawień co M365Graph (m365_tenant_id, m365_graph_client_id, m365_graph_client_secret).
 * Uprawnienie aplikacji: Mail.Read (application) — bez niego Graph zwraca 403.
 *
 * Testy: stałe DHM_GRAPH_BASE / DHM_LOGIN_BASE przestawiają adresy na atrapę.
 */
require_once dirname(__DIR__, 3) . '/includes/m365.php';

class DhostingGraphException extends RuntimeException {}
/** Graph chwilowo niedostępny (sieć, 5xx, długi 429) — ponowić później. */
final class DhostingGraphUnavailable extends DhostingGraphException {}
/** Brak uprawnień / konto nie istnieje — bez zmiany konfiguracji ponowienie nic nie da (ale i tak ponawiamy co 6 h). */
final class DhostingGraphDenied extends DhostingGraphException {}
/** Wiadomość zniknęła w trakcie (404) — pomijamy ją. */
final class DhostingGraphGone extends DhostingGraphException {}
/**
 * Graph nie umie oddać MIME tej jednej wiadomości („500: MIME content conversion failed.” — zaproszenia z kalendarza,
 * wiadomości uszkodzone albo nietypowe). Błąd stały, nie chwilowy: nie ponawiamy, składamy wiadomość sami (buildMime).
 */
final class DhostingGraphUnconvertible extends DhostingGraphException {}

final class DhostingGraphMail
{
    private ?string $token = null;
    private int $tokenExp = 0;
    private string $base;
    private string $loginBase;

    /** Foldery pomijane przy kopiowaniu (nazwy dobrze znane w Graph). */
    public const SKIP_WELL_KNOWN = ['outbox', 'conversationhistory', 'syncissues', 'scheduled', 'searchfolders'];
    /** Foldery specjalne mapowane na odpowiedniki IMAP. */
    public const SPECIAL = ['inbox', 'sentitems', 'drafts', 'deleteditems', 'junkemail', 'archive'];

    public function __construct()
    {
        $this->base = rtrim(defined('DHM_GRAPH_BASE') ? (string)DHM_GRAPH_BASE : 'https://graph.microsoft.com/v1.0', '/');
        $this->loginBase = rtrim(defined('DHM_LOGIN_BASE') ? (string)DHM_LOGIN_BASE : 'https://login.microsoftonline.com', '/');
    }

    public static function configured(): bool
    {
        return m365_setting('m365_enabled') === '1' && m365_setting('m365_graph_client_id') !== ''
            && m365_setting('m365_graph_client_secret') !== '' && (m365_setting('m365_tenant_id') !== '' || (defined('MS_TENANT_ID') && MS_TENANT_ID !== ''));
    }

    /** Wszystkie foldery poczty (rekurencyjnie): [['id','name','path'=>[nazwy od korzenia],'total','special'=>?string]]. */
    public function folders(string $user): array
    {
        $u = rawurlencode($user);
        $special = [];
        foreach (array_merge(self::SPECIAL, self::SKIP_WELL_KNOWN) as $wk) {
            try {
                $f = $this->getJson("/users/{$u}/mailFolders/{$wk}?\$select=id");
                if (!empty($f['id'])) $special[(string)$f['id']] = $wk;
            } catch (DhostingGraphGone $e) { /* folderu nie ma w tej skrzynce */ }
        }
        $out = [];
        $walk = function (string $url, array $parentPath, bool $skipParent) use (&$walk, &$out, $special, $u) {
            while ($url !== '') {
                $r = $this->getJson($url);
                foreach ($r['value'] ?? [] as $f) {
                    $id = (string)$f['id'];
                    $wk = $special[$id] ?? null;
                    $skip = $skipParent || ($wk !== null && in_array($wk, self::SKIP_WELL_KNOWN, true));
                    $path = array_merge($parentPath, [(string)$f['displayName']]);
                    if (!$skip) $out[] = ['id' => $id, 'name' => (string)$f['displayName'], 'path' => $path,
                                          'total' => (int)($f['totalItemCount'] ?? 0), 'special' => $wk];
                    if ((int)($f['childFolderCount'] ?? 0) > 0) {
                        $walk("/users/{$u}/mailFolders/" . rawurlencode($id) . "/childFolders?\$top=100&\$select=id,displayName,totalItemCount,childFolderCount", $path, $skip);
                    }
                }
                $url = (string)($r['@odata.nextLink'] ?? '');
            }
        };
        $walk("/users/{$u}/mailFolders?\$top=100&\$select=id,displayName,totalItemCount,childFolderCount", [], false);
        return $out;
    }

    /**
     * Strona wiadomości folderu (najstarsze najpierw). $next = @odata.nextLink z poprzedniej strony albo ''.
     * $since (znacznik czasu, kopia przyrostowa): tylko wiadomości odebrane od tej chwili — filtr na tym samym polu co sortowanie.
     */
    public function messagesPage(string $user, string $folderId, string $next = '', ?int $since = null): array
    {
        $filter = $since ? '&$filter=receivedDateTime%20ge%20' . gmdate('Y-m-d\\TH:i:s\\Z', $since) : '';
        $url = $next !== '' ? $next : '/users/' . rawurlencode($user) . '/mailFolders/' . rawurlencode($folderId)
            . '/messages?$top=50&$select=id,internetMessageId,subject,receivedDateTime,sentDateTime,isRead,isDraft,flag' . $filter . '&$orderby=receivedDateTime%20asc';
        $r = $this->getJson($url);
        return ['items' => $r['value'] ?? [], 'next' => (string)($r['@odata.nextLink'] ?? '')];
    }

    /** Pełny MIME wiadomości do pliku $file. */
    public function downloadMime(string $user, string $msgId, string $file): int
    {
        $url = $this->base . '/users/' . rawurlencode($user) . '/messages/' . rawurlencode($msgId) . '/$value';
        for ($try = 0; ; $try++) {
            $fh = fopen($file, 'wb');
            if (!$fh) throw new RuntimeException('Nie można utworzyć pliku tymczasowego.');
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => true, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 600,
                CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->token(), 'Prefer: IdType="ImmutableId"'],
                CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$retryAfter) {
                    if (stripos($h, 'Retry-After:') === 0) $retryAfter = (int)trim(substr($h, 12));
                    return strlen($h);
                },
            ]);
            $retryAfter = 0;
            $ok = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            unset($ch);
            fclose($fh);
            if ($ok === false) throw new DhostingGraphUnavailable('Microsoft 365 niedostępny: ' . $err);
            if ($code >= 200 && $code < 300) return (int)filesize($file);
            $body = (string)@file_get_contents($file, false, null, 0, 2000);
            if ($code >= 500 && self::isConversionError($body)) {
                throw new DhostingGraphUnconvertible('Microsoft 365 nie oddał oryginału wiadomości (' . $code . ': MIME content conversion failed).');
            }
            if ($this->retryable($code, $retryAfter, $try)) continue;
            $this->fail($code, $body, $retryAfter);
        }
    }

    /**
     * Wiadomość złożona z danych Graph, gdy oryginału MIME nie da się pobrać (DhostingGraphUnconvertible): nagłówki
     * (Od, Do, DW, UDW, Odpowiedz do, Temat, Data, Message-ID), treść HTML albo tekst i załączniki-pliki. Załączniki-wiadomości
     * i odnośniki nie mają bajtów w Graph — trafiają do listy w nagłówku X-Migracja-Pominiete. Zapis do $file, zwraca rozmiar.
     */
    /** Jak wyszło ostatnie buildMime(): 'full' (pełna treść), 'partial' (bez pełnej treści — tylko początek), 'noattach' (bez załączników). */
    public string $lastRebuild = '';

    /**
     * Próby po kolei (polecenie 2026-11-13 „podejmij próbę odtworzenia”): treść HTML → treść jako tekst → bez treści (tylko początek
     * wiadomości, bodyPreview) → minimalny zestaw pól. $withAttachments=false: wersja bez załączników (gdy serwer odrzucił wiadomość
     * jako za dużą) — nazwy pominiętych plików w treści i w nagłówku. Niedostępny Graph / brak uprawnień / zniknięta wiadomość — wyjątek dalej.
     */
    public function buildMime(string $user, string $msgId, string $file, bool $withAttachments = true): int
    {
        $u = rawurlencode($user);
        $m = rawurlencode($msgId);
        $base = "/users/{$u}/messages/{$m}?\$select=";
        $hdrs = 'subject,from,sender,toRecipients,ccRecipients,bccRecipients,replyTo,receivedDateTime,sentDateTime,internetMessageId,hasAttachments';
        $tries = [
            [$hdrs . ',body', [], 'full'],
            [$hdrs . ',body', ['Prefer: outlook.body-content-type="text"'], 'full'],
            [$hdrs . ',bodyPreview', [], 'partial'],
            ['subject,from,receivedDateTime,sentDateTime,internetMessageId,hasAttachments,bodyPreview', [], 'partial'],
        ];
        $j = null; $mode = 'full'; $last = null;
        foreach ($tries as [$sel, $hd, $md]) {
            try { $j = $this->getJson($base . $sel, $hd); $mode = $md; break; }
            catch (DhostingGraphUnavailable | DhostingGraphDenied | DhostingGraphGone $e) { throw $e; }
            catch (DhostingGraphException $e) { $last = $e; }          // ta wersja nie przechodzi — próbujemy prostszej
        }
        if ($j === null) throw $last ?? new DhostingGraphException('Microsoft 365 nie oddał danych wiadomości.');
        $files = []; $skipped = []; $attachErr = '';
        if (!empty($j['hasAttachments'])) {
            try {
                $next = "/users/{$u}/messages/{$m}/attachments" . ($withAttachments ? '' : '?$select=name,size');
                while ($next !== '') {
                    $r = $this->getJson($next);
                    foreach ($r['value'] ?? [] as $a) {
                        $isFile = $withAttachments && str_ends_with((string)($a['@odata.type'] ?? ''), 'fileAttachment') && isset($a['contentBytes']);
                        if ($isFile) $files[] = $a; else $skipped[] = (string)($a['name'] ?? 'załącznik');
                    }
                    $next = (string)($r['@odata.nextLink'] ?? '');
                }
            } catch (DhostingGraphUnavailable | DhostingGraphDenied | DhostingGraphGone $e) { throw $e; }
            catch (DhostingGraphException $e) { $attachErr = 'załączników nie udało się pobrać'; $files = []; }
        }
        if (!$withAttachments && ($skipped || $attachErr !== '')) $mode = $mode === 'partial' ? 'partial' : 'noattach';
        if ($attachErr !== '' && $mode === 'full') $mode = 'noattach';
        $this->lastRebuild = $mode;
        // treść zastępcza: bez pełnej treści — początek wiadomości; bez załączników — lista pominiętych plików
        $note = [];
        if ($mode === 'partial') $note[] = 'Pełnej treści tej wiadomości nie udało się pobrać z Microsoft 365 przy przenoszeniu poczty. Poniżej jej początek.';
        if (!$withAttachments && $skipped) $note[] = 'Wiadomość była za duża dla nowej skrzynki, dlatego przeniesiono ją bez załączników (zostały w Microsoft 365): ' . implode(', ', $skipped) . '.';
        if ($attachErr !== '') $note[] = 'Załączników tej wiadomości nie udało się pobrać z Microsoft 365 — zostały w starej skrzynce.';
        if ($mode === 'partial') $j['body'] = ['contentType' => 'text', 'content' => implode("\n\n", $note) . "\n\n" . (string)($j['bodyPreview'] ?? '')];
        elseif ($note) {
            $isH = strtolower((string)($j['body']['contentType'] ?? 'text')) === 'html';
            $pre = $isH ? '<p style="border:1px solid #c00;padding:6px">' . htmlspecialchars(implode(' ', $note), ENT_QUOTES, 'UTF-8') . '</p>' : implode("\n", $note) . "\n\n";
            $j['body']['content'] = $pre . (string)($j['body']['content'] ?? '');
        }
        $enc = fn(string $s) => preg_match('/[^\x20-\x7E]/', $s) ? mb_encode_mimeheader($s, 'UTF-8', 'B', "\r\n ") : $s;
        $one = function (?array $r) use ($enc): string {
            $mail = trim((string)($r['emailAddress']['address'] ?? ''));
            $name = trim(str_replace(['"', "\r", "\n"], ['', ' ', ' '], (string)($r['emailAddress']['name'] ?? '')));
            if ($mail === '') return '';
            if ($name === '' || strcasecmp($name, $mail) === 0) return '<' . $mail . '>';
            // słowo zakodowane (RFC 2047) bez cudzysłowu; zwykłe ASCII — w cudzysłowie
            return (preg_match('/[^\x20-\x7E]/', $name) ? $enc($name) : '"' . $name . '"') . ' <' . $mail . '>';
        };
        $list = fn(array $rs) => implode(",\r\n ", array_values(array_filter(array_map($one, $rs))));
        $h = [];
        if ($v = $one($j['from'] ?? $j['sender'] ?? null)) $h[] = 'From: ' . $v;
        foreach (['To' => 'toRecipients', 'Cc' => 'ccRecipients', 'Bcc' => 'bccRecipients', 'Reply-To' => 'replyTo'] as $hn => $k) {
            if (($v = $list((array)($j[$k] ?? []))) !== '') $h[] = $hn . ': ' . $v;
        }
        $h[] = 'Subject: ' . $enc(str_replace(["\r", "\n"], ' ', (string)($j['subject'] ?? '')));
        $ts = strtotime((string)($j['sentDateTime'] ?? $j['receivedDateTime'] ?? '')) ?: time();
        $h[] = 'Date: ' . date('r', $ts);
        $mid = trim((string)($j['internetMessageId'] ?? ''));
        if ($mid !== '' && !preg_match('/[\r\n]/', $mid)) $h[] = 'Message-ID: ' . $mid;
        $h[] = 'MIME-Version: 1.0';
        $h[] = 'X-Migracja: ' . (['partial' => 'wiadomosc odtworzona czesciowo - bez pelnej tresci', 'noattach' => 'wiadomosc przeniesiona bez zalacznikow']
            [$mode] ?? 'wiadomosc odtworzona z danych Microsoft 365 (brak oryginalnego MIME)');
        if ($skipped) $h[] = 'X-Migracja-Pominiete: ' . $enc(mb_substr(implode(', ', $skipped), 0, 300));
        $isHtml = strtolower((string)($j['body']['contentType'] ?? 'text')) === 'html';
        $bodyPart = 'Content-Type: text/' . ($isHtml ? 'html' : 'plain') . '; charset=UTF-8' . "\r\n" . 'Content-Transfer-Encoding: base64' . "\r\n\r\n"
            . chunk_split(base64_encode((string)($j['body']['content'] ?? '')), 76, "\r\n");
        $fh = fopen($file, 'wb');
        if (!$fh) throw new RuntimeException('Nie można utworzyć pliku tymczasowego.');
        if (!$files) {
            fwrite($fh, implode("\r\n", $h) . "\r\n" . $bodyPart);
        } else {
            $b = '=_dhm_' . bin2hex(random_bytes(12));
            fwrite($fh, implode("\r\n", $h) . "\r\n" . 'Content-Type: multipart/mixed; boundary="' . $b . '"' . "\r\n\r\n"
                . '--' . $b . "\r\n" . $bodyPart);
            foreach ($files as $a) {
                $fn = str_replace(['"', "\r", "\n"], '', (string)($a['name'] ?? 'zalacznik'));
                $ct = preg_match('~^[\w.+-]+/[\w.+-]+$~', (string)($a['contentType'] ?? '')) ? (string)$a['contentType'] : 'application/octet-stream';
                $inline = !empty($a['isInline']) && !empty($a['contentId']);
                fwrite($fh, '--' . $b . "\r\n" . 'Content-Type: ' . $ct . '; name="' . $enc($fn) . '"' . "\r\n"
                    . 'Content-Transfer-Encoding: base64' . "\r\n"
                    . 'Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $enc($fn) . '"' . "\r\n"
                    . ($inline ? 'Content-ID: <' . trim((string)$a['contentId'], '<>') . '>' . "\r\n" : '') . "\r\n"
                    . chunk_split((string)preg_replace('/\s+/', '', (string)$a['contentBytes']), 76, "\r\n"));
            }
            fwrite($fh, '--' . $b . '--' . "\r\n");
        }
        fclose($fh);
        return (int)filesize($file);
    }

    // ── HTTP ───────────────────────────────────────────────────────────────────────────────────

    /** GET z JSON-em. $extraHeaders — np. Prefer: outlook.body-content-type="text" (odtwarzanie wiadomości bez HTML). */
    private function getJson(string $pathOrUrl, array $extraHeaders = []): array
    {
        $url = str_starts_with($pathOrUrl, 'http') ? $pathOrUrl : $this->base . $pathOrUrl;
        for ($try = 0; ; $try++) {
            $retryAfter = 0;
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 60,
                CURLOPT_HTTPHEADER => array_merge(['Authorization: Bearer ' . $this->token(), 'Accept: application/json', 'Prefer: IdType="ImmutableId"'], $extraHeaders),
                CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$retryAfter) {
                    if (stripos($h, 'Retry-After:') === 0) $retryAfter = (int)trim(substr($h, 12));
                    return strlen($h);
                },
            ]);
            $raw = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err = curl_error($ch);
            unset($ch);
            if ($raw === false) throw new DhostingGraphUnavailable('Microsoft 365 niedostępny: ' . $err);
            if ($code >= 200 && $code < 300) {
                $j = json_decode((string)$raw, true);
                if (!is_array($j)) throw new DhostingGraphUnavailable('Microsoft 365: nieczytelna odpowiedź.');
                return $j;
            }
            if ($code === 401 && $try === 0) { $this->token = null; continue; }
            if ($code >= 500 && self::isConversionError((string)$raw)) {   // stały błąd jednej wiadomości — bez ponowień, nie „awaria Microsoft 365”
                throw new DhostingGraphUnconvertible('Microsoft 365 nie oddał danych wiadomości (' . $code . ': MIME content conversion failed).');
            }
            if ($this->retryable($code, $retryAfter, $try)) continue;
            $this->fail($code, (string)$raw, $retryAfter);
        }
    }

    /** Odpowiedź Graph „MIME content conversion failed” (ErrorMimeContentConversionFailed) — dotyczy jednej wiadomości, nie całej usługi. */
    private static function isConversionError(string $body): bool
    {
        return (bool)preg_match('/MIME content conversion failed|MimeContentConversion|content conversion failed/i', $body);
    }

    /**
     * 429/500/502/503/504: krótkie Retry-After (albo 5, 10, 15 s) czekamy na miejscu, do 3 prób; długie — ponowienie później.
     * 500 i 502 (Bad Gateway) dopisane 13.11.2026 — Graph zwracał chwilowe 502 i zadanie od razu szło na ponowienie za 6 h.
     */
    private function retryable(int $code, int $retryAfter, int $try): bool
    {
        if (!in_array($code, [429, 500, 502, 503, 504], true) || $try >= 3) return false;
        $wait = $retryAfter > 0 ? $retryAfter : 5 * ($try + 1);
        if ($wait > 30) return false;
        sleep($wait);
        return true;
    }

    private function fail(int $code, string $body, int $retryAfter): never
    {
        $j = json_decode($body, true);
        $msg = is_array($j) ? (string)($j['error']['message'] ?? $j['error']['code'] ?? '') : '';
        $msg = mb_substr($msg !== '' ? $msg : 'HTTP ' . $code, 0, 300);
        if ($code === 404) throw new DhostingGraphGone('Microsoft 365: nie znaleziono (' . $msg . ').');
        if ($code === 400 || $code === 410) throw new DhostingGraphException('Microsoft 365: niepoprawne zapytanie (' . $code . ': ' . $msg . ').');
        if ($code === 401 || $code === 403) {
            throw new DhostingGraphDenied('Microsoft 365 odmówił dostępu do poczty (' . $code . ': ' . $msg . '). Aplikacja SZO potrzebuje uprawnienia aplikacji Mail.Read ze zgodą administratora.');
        }
        throw new DhostingGraphUnavailable('Microsoft 365 chwilowo niedostępny (' . $code . ': ' . $msg . ')' . ($retryAfter > 0 ? ', Retry-After ' . $retryAfter . ' s' : '') . '.');
    }

    private function token(): string
    {
        if ($this->token !== null && time() < $this->tokenExp - 60) return $this->token;
        $tenant = m365_setting('m365_tenant_id') ?: (defined('MS_TENANT_ID') ? (string)MS_TENANT_ID : '');
        $ch = curl_init($this->loginBase . '/' . rawurlencode($tenant) . '/oauth2/v2.0/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => 30,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'client_credentials', 'client_id' => m365_setting('m365_graph_client_id'),
                'client_secret' => m365_setting('m365_graph_client_secret'), 'scope' => 'https://graph.microsoft.com/.default',
            ]),
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        unset($ch);
        if ($raw === false || $code >= 500) throw new DhostingGraphUnavailable('Logowanie Microsoft niedostępne: ' . ($err ?: 'HTTP ' . $code));
        $j = json_decode((string)$raw, true);
        if (empty($j['access_token'])) throw new DhostingGraphDenied('Microsoft odrzucił dane aplikacji SZO: ' . mb_substr((string)($j['error_description'] ?? $j['error'] ?? 'HTTP ' . $code), 0, 200));
        $this->token = (string)$j['access_token'];
        $this->tokenExp = time() + (int)($j['expires_in'] ?? 3600);
        return $this->token;
    }
}
