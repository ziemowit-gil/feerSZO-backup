<?php
declare(strict_types=1);

/**
 * modules/zlecenie_oswiadczenie/logic/OswController.php — trasy modułu „Oświadczenia zleceniobiorcy”.
 *
 * Tryby wejścia zleceniobiorcy (te same ekrany kreatora):
 *   'panel' — /umowa/{id}/…  — zalogowany zleceniobiorca swojej umowy (viewer_owns_contract; pracownik z dostępem do umowy też);
 *   'token' — /t/{token}/…   — link wygenerowany przez pracownika albo z publicznego kreatora (/publiczne);
 *             po przypięciu pakietu do konta oświadczeń link przestaje działać (wejście tylko po zalogowaniu);
 *   'account' — /konto/osw/{id}/… — zalogowane konto oświadczeń (OswAccount), wyłącznie własne pakiety.
 * Pracownik (admin/editor): lista, link dla nowej osoby, karta pakietu, weryfikacja skanów, pobieranie wydruków i skanów.
 */
require_once __DIR__ . '/OswService.php';
require_once __DIR__ . '/OswAccount.php';
require_once dirname(__DIR__) . '/lib/OswiadczenieDocx.php';
require_once dirname(__DIR__) . '/lib/OswiadczeniePdf.php';

final class OswController
{
    private const VIEW = __DIR__ . '/../view/';

    public function __construct(private string $mode = 'staff') {}

    // ── Kontekst zleceniobiorcy ───────────────────────────────────────────────

    /** @return array{osw: array, contract: ?array, base: string, back: ?string} */
    private function ctx(string|int $key): array
    {
        if ($this->mode === 'token') {
            $o = OswService::byToken((string)$key);
            if (!$o) $this->fail(404, 'Link do oświadczeń jest nieprawidłowy albo wygasł. Poproś Fundację o nowy link albo wypełnij oświadczenie ponownie.');
            if (!empty($o['account_confirmed_at'])) {
                $this->fail(403, 'To oświadczenie jest już na Twoim koncie — zaloguj się, aby je zobaczyć: ' . OswAccount::url('/konto/logowanie'));
            }
            $base = route('osw.t.home', ['token' => (string)$key]);
            return ['osw' => $o, 'contract' => OswService::contract((int)$o['contract_id']), 'base' => $base, 'back' => null];
        }
        if ($this->mode === 'account') {
            $acc = OswAccount::current();
            if (!$acc) { header('Location: ' . OswAccount::url('/konto/logowanie'), true, 303); exit; }
            $o = OswService::byId((int)$key);
            if (!$o || (int)$o['account_id'] !== (int)$acc['id'] || empty($o['account_confirmed_at'])) $this->fail(404, 'Nie znaleziono oświadczenia na Twoim koncie.');
            return ['osw' => $o, 'contract' => OswService::contract((int)$o['contract_id']), 'base' => route('osw.a.home', ['oid' => (int)$o['id']]),
                    'back' => OswAccount::url('/konto'), 'backLabel' => '← Moje oświadczenia'];
        }
        $c = OswService::contract((int)$key);
        if (!$c || !viewer_owns_contract('zlecenie', $c)) $this->fail(403, 'Brak dostępu do tej umowy.');
        $o = OswService::forContract((int)$c['id'], true, (int)(current_user()['id'] ?? 0));
        return ['osw' => $o, 'contract' => $c, 'base' => route('osw.p.home', ['cid' => (int)$c['id']]), 'back' => rtrim(APP_URL, '/') . '/panel/index.php'];
    }

    private function fail(int $code, string $msg): never
    {
        http_response_code($code);
        echo Router::view(self::VIEW . 'message.php', ['title' => 'Oświadczenia zleceniobiorcy', 'msg' => $msg]);
        exit;
    }

    private function uid(): int { return in_array($this->mode, ['token', 'account'], true) ? 0 : (int)(current_user()['id'] ?? 0); }

    private function ip(): string { return (string)($_SERVER['REMOTE_ADDR'] ?? ''); }

    // ── Kreator ───────────────────────────────────────────────────────────────

    public function home(string|int $key): ?string
    {
        $x = $this->ctx($key);
        if ($x['osw']['status'] === 'roboczy') return Router::redirect($x['base'] . '/krok/' . max(1, (int)$x['osw']['step']));
        return Router::view(self::VIEW . 'done.php', $x + [
            'docs' => OswService::docs((int)$x['osw']['id']), 'a' => OswService::answers($x['osw']),
            'flash' => $this->takeFlash(), 'mode' => $this->mode,
        ]);
    }

    public function step(string|int $key, int $n): ?string
    {
        $x = $this->ctx($key);
        if (!isset(OswForm::STEPS[$n])) return Router::redirect($x['base']);
        if ($x['osw']['status'] !== 'roboczy') return Router::redirect($x['base']);
        if ($n > (int)$x['osw']['step']) return Router::redirect($x['base'] . '/krok/' . (int)$x['osw']['step']);
        $a = OswService::answers($x['osw']);
        if ($n === 1 && empty($a['plec']) && !empty($a['pesel'])) $a['plec'] = OswForm::plecFromPesel((string)$a['pesel']);
        if ($n === 5) {
            $a['posiadacz'] ??= OswForm::nameFirst((string)($a['nazwisko_imiona'] ?? ''));
            $a['zlb_miejscowosc'] ??= (string)($a['adr_miejscowosc'] ?? '');
            if (empty($a['bank_nazwa']) && !empty($a['nrb'])) $a['bank_nazwa'] = OswForm::bankName((string)$a['nrb']);
        }
        auth_start();   // link z tokenem: sesja (błędy kroku, CSRF) nie musi być jeszcze uruchomiona
        $errors = $_SESSION['_osw_err'][$x['osw']['id']] ?? [];
        unset($_SESSION['_osw_err'][$x['osw']['id']]);
        return Router::view(self::VIEW . 'wizard.php', $x + ['n' => $n, 'a' => $a, 'errors' => $errors, 'flash' => $this->takeFlash(), 'mode' => $this->mode]);
    }

    public function saveStep(string|int $key, int $n): ?string
    {
        $x = $this->ctx($key);
        if (!isset(OswForm::STEPS[$n]) || $x['osw']['status'] !== 'roboczy') return Router::redirect($x['base']);
        if ($n === 6) return $this->submit($key);
        $back = isset($_POST['wstecz']);
        $errors = OswService::saveStep($x['osw'], $n, $_POST);
        if ($back) return Router::redirect($x['base'] . '/krok/' . max(1, $n - 1));
        if ($errors) {
            $_SESSION['_osw_err'][$x['osw']['id']] = $errors;
            return Router::redirect($x['base'] . '/krok/' . $n . '#bledy');
        }
        return Router::redirect($x['base'] . '/krok/' . ($n + 1));
    }

    public function submit(string|int $key): ?string
    {
        $x = $this->ctx($key);
        if (isset($_POST['wstecz'])) return Router::redirect($x['base'] . '/krok/5');
        if ($x['osw']['status'] !== 'roboczy') return Router::redirect($x['base']);
        $r = OswService::submit($x['osw'], $_POST, $this->ip(), $this->uid());
        if (!$r['ok']) {
            $_SESSION['_osw_err'][$x['osw']['id']] = $r['errors'];
            return Router::redirect($x['base'] . '/krok/' . $r['step'] . '#bledy');
        }
        $this->flash('success', 'Oświadczenia zostały złożone. Teraz pobierz je, wydrukuj, podpisz i prześlij skany.');
        return Router::redirect($x['base']);
    }

    public function reopen(string|int $key): ?string
    {
        $x = $this->ctx($key);
        if (!OswService::reopen($x['osw'], $this->uid())) {
            $this->flash('warning', 'Oświadczenia są już zweryfikowane przez Fundację — zmianę danych zgłoś opiekunowi umowy.');
            return Router::redirect($x['base']);
        }
        return Router::redirect($x['base'] . '/krok/1');
    }

    public function print(string|int $key, string $kind, string $fmt): ?string
    {
        $x = $this->ctx($key);
        $docs = OswService::docs((int)$x['osw']['id']);
        if (!isset($docs[$kind])) $this->fail(404, 'Najpierw wypełnij i złóż oświadczenia w kreatorze.');
        return $this->streamPrint($x['osw'], $x['contract'], $docs[$kind], $fmt, $x['base'], true);
    }

    public function upload(string|int $key, string $kind): ?string
    {
        $x = $this->ctx($key);
        $docs = OswService::docs((int)$x['osw']['id']);
        if (!isset($docs[$kind])) return Router::redirect($x['base']);
        try {
            if ($this->mode !== 'panel' && !OswAccount::hit('upload', $this->ip())) throw new RuntimeException('Zbyt wiele przesłanych plików z tego adresu. Spróbuj ponownie za godzinę.');
            OswService::storeScan($docs[$kind], $_FILES['skan'] ?? [], $this->ip(), $this->uid(), (int)$x['osw']['contract_id']);
            $msg = 'Dziękujemy — skan „' . OswService::KINDS[$kind][0] . '” został przesłany. Fundacja go sprawdzi.';
            if ($this->mode === 'token' && ($mail = OswAccount::onScanUploaded((array)OswService::byId((int)$x['osw']['id'])))) {
                $msg .= ' Na adres ' . $mail . ' wysłaliśmy link do Twojego konta — ustaw hasło, aby w każdej chwili sprawdzić status i ponownie wydrukować oświadczenie.';
            }
            $this->flash('success', $msg);
        } catch (\Throwable $e) {
            $this->flash('danger', $e->getMessage());
        }
        return Router::redirect($x['base'] . '#dok-' . $kind);
    }

    /** Ponowne wysłanie linku do konta (publiczny kreator, po przesłaniu skanu; najwyżej co 10 minut). */
    public function resendAccount(string|int $key): ?string
    {
        $x = $this->ctx($key);
        $mail = OswAccount::onScanUploaded($x['osw']);
        $this->flash($mail ? 'success' : 'warning', $mail ? 'Link do konta wysłaliśmy na adres ' . $mail . '. Sprawdź też folder SPAM.' : 'Najpierw prześlij skan podpisanego oświadczenia.');
        return Router::redirect($x['base']);
    }

    // ── Publiczny kreator i konto oświadczeń ─────────────────────────────────

    public function publicStart(): string
    {
        auth_start();
        $resume = (string)($_SESSION['_osw_public'] ?? '');
        $o = $resume !== '' ? OswService::byToken($resume) : null;
        return Router::view(self::VIEW . 'public.php', ['title' => 'Oświadczenia zleceniobiorcy', 'resume' => $o && empty($o['account_confirmed_at']) ? route('osw.t.home', ['token' => $resume]) : null,
                                                        'flash' => $this->takeFlash(), 'back' => null]);
    }

    public function publicCreate(): ?string
    {
        if (trim((string)($_POST['website'] ?? '')) !== '') return Router::redirect(route('osw.public'));   // pułapka na boty
        if (!OswAccount::hit('start', $this->ip())) {
            $this->flash('danger', 'Z tego adresu rozpoczęto już wiele oświadczeń. Spróbuj ponownie później albo skontaktuj się z Fundacją.');
            return Router::redirect(route('osw.public'));
        }
        $o = OswService::startPublic($this->ip());
        $_SESSION['_osw_public'] = $o['token'];
        return Router::redirect(route('osw.t.step', ['token' => $o['token'], 'n' => 1]));
    }

    public function accountHome(): ?string
    {
        $acc = OswAccount::current();
        if (!$acc) return Router::redirect(OswAccount::url('/konto/logowanie'));
        return Router::view(self::VIEW . 'account.php', ['title' => 'Moje oświadczenia', 'acc' => $acc, 'rows' => OswAccount::pakiety((int)$acc['id']),
                                                         'flash' => $this->takeFlash(), 'back' => null]);
    }

    public function loginForm(): ?string
    {
        if (OswAccount::current()) return Router::redirect(OswAccount::url('/konto'));
        return Router::view(self::VIEW . 'accountAuth.php', ['title' => 'Logowanie — oświadczenia', 'screen' => 'login', 'flash' => $this->takeFlash(), 'back' => null]);
    }

    public function login(): ?string
    {
        $err = OswAccount::login((string)($_POST['email'] ?? ''), (string)($_POST['password'] ?? ''), $this->ip());
        if ($err !== '') { $this->flash('danger', $err); return Router::redirect(OswAccount::url('/konto/logowanie')); }
        return Router::redirect(OswAccount::url('/konto'));
    }

    public function logout(): ?string
    {
        OswAccount::logout();
        $this->flash('info', 'Wylogowano.');
        return Router::redirect(OswAccount::url('/konto/logowanie'));
    }

    public function confirmForm(): string
    {
        $t = (string)($_GET['t'] ?? '');
        $acc = OswAccount::byToken($t);
        return Router::view(self::VIEW . 'accountAuth.php', ['title' => 'Konto z oświadczeniem', 'screen' => 'confirm', 'token' => $t, 'acc' => $acc,
                                                             'flash' => $this->takeFlash(), 'back' => null]);
    }

    public function confirm(): ?string
    {
        $t = (string)($_POST['t'] ?? '');
        $acc = OswAccount::byToken($t);
        $err = OswAccount::confirm($t, (string)($_POST['password'] ?? ''), (string)($_POST['password2'] ?? ''));
        if ($err !== '') { $this->flash('danger', $err); return Router::redirect(OswAccount::url('/konto/potwierdz', ['t' => $t])); }
        if (OswAccount::current()) { $this->flash('success', 'Gotowe — to Twoje konto z oświadczeniami.'); return Router::redirect(OswAccount::url('/konto')); }
        $this->flash('success', 'Oświadczenie dodano do Twojego konta' . ($acc ? ' (' . $acc['email'] . ')' : '') . '. Zaloguj się.');
        return Router::redirect(OswAccount::url('/konto/logowanie'));
    }

    public function resetForm(): string
    {
        return Router::view(self::VIEW . 'accountAuth.php', ['title' => 'Nie pamiętam hasła', 'screen' => 'reset', 'flash' => $this->takeFlash(), 'back' => null]);
    }

    public function reset(): ?string
    {
        OswAccount::requestReset((string)($_POST['email'] ?? ''), $this->ip());
        $this->flash('info', 'Jeśli konto z tym adresem istnieje, wysłaliśmy na nie link do ustawienia nowego hasła (ważny 2 godziny).');
        return Router::redirect(OswAccount::url('/konto/logowanie'));
    }

    // ── Pracownik ─────────────────────────────────────────────────────────────

    public function index(): string
    {
        $f = preg_replace('/[^a-z]/', '', (string)($_GET['f'] ?? '')) ?: 'weryfikacja';
        $counts = OswService::dashboardCounts();
        if (!isset($counts[$f])) $f = 'weryfikacja';
        $rows = OswService::listAll();
        if ($f === 'powiazanie')  $rows = array_values(array_filter($rows, fn($r) => $r['status'] === 'zlozony' && (int)$r['contract_id'] === 0));
        if ($f === 'wypelnianie') $rows = array_values(array_filter($rows, fn($r) => $r['status'] === 'roboczy'));
        return Router::view(self::VIEW . 'staffIndex.php', ['rows' => $rows, 'newLink' => $_SESSION['_osw_newlink'] ?? null,
                                                            'f' => $f, 'counts' => $counts,
                                                            'queue' => in_array($f, ['powiazanie', 'wypelnianie'], true) ? [] : OswService::docQueue($f)]
            + (function () { unset($_SESSION['_osw_newlink']); return []; })());
    }

    public function inviteNew(): ?string
    {
        try {
            $o = OswService::inviteNew((string)($_POST['name'] ?? ''), (string)($_POST['email'] ?? ''), $this->uid());
            $_SESSION['_osw_newlink'] = ['url' => route('osw.t.home', ['token' => $o['token']]), 'name' => $o['invite_name'], 'email' => $o['invite_email']];
        } catch (\Throwable $e) {
            flash_set('danger', $e->getMessage());
        }
        return Router::redirect(route('osw.index'));
    }

    public function contractLink(int $cid): ?string
    {
        $c = OswService::contract($cid);
        if (!$c || !viewer_owns_contract('zlecenie', $c)) $this->fail(403, 'Brak dostępu do tej umowy.');
        OswService::linkForContract($cid, $this->uid());
        flash_set('success', 'Link do kreatora oświadczeń jest gotowy — skopiuj go i przekaż zleceniobiorcy.');
        return Router::redirect(rtrim(APP_URL, '/') . '/contracts/zlecenie/view.php?id=' . $cid . '&tab=oswiadczenie');
    }

    public function show(int $id): string
    {
        $o = OswService::byId($id);
        if (!$o) $this->fail(404, 'Nie znaleziono oświadczeń.');
        $c = OswService::contract((int)$o['contract_id']);
        if ($c && !viewer_owns_contract('zlecenie', $c)) $this->fail(403, 'Brak dostępu do tej umowy.');
        $a = OswService::answers($o);
        return Router::view(self::VIEW . 'staffShow.php', ['osw' => $o, 'contract' => $c, 'docs' => OswService::docs($id), 'a' => $a,
                                                           'account' => $o['account_id'] ? OswAccount::get((int)$o['account_id']) : null,
                                                           'candidates' => $c ? [] : OswService::contractCandidates($a)]);
    }

    public function verify(int $docId): ?string
    {
        [$doc, $o, $c] = $this->staffDoc($docId);
        try {
            OswService::verify($doc, ($_POST['decision'] ?? '') === 'ok', (string)($_POST['note'] ?? ''), (array)current_user());
            flash_set('success', ($_POST['decision'] ?? '') === 'ok' ? 'Skan zweryfikowany — plik dołączono do dokumentów umowy.' : 'Skan odrzucony — zleceniobiorca zobaczy powód i prześle go ponownie.');
        } catch (\Throwable $e) {
            flash_set('danger', $e->getMessage());
        }
        $back = (string)($_POST['back'] ?? '');
        if ($back === 'next') {   // ekran weryfikacji: od razu następny skan z kolejki
            $nx = OswService::nextToVerify($docId);
            return Router::redirect($nx ? route('osw.doc', ['id' => (int)$nx['id']]) : route('osw.index', ['f' => 'weryfikacja']));
        }
        return Router::redirect($back === 'umowa' && $c
            ? rtrim(APP_URL, '/') . '/contracts/zlecenie/view.php?id=' . (int)$c['id'] . '&tab=oswiadczenie'
            : route('osw.show', ['id' => (int)$o['id']]));
    }

    /** Pracownik: powiąż pakiet z umową (id z listy kandydatów albo numer umowy). */
    public function staffLink(int $id): ?string
    {
        $o = OswService::byId($id);
        if (!$o) $this->fail(404, 'Nie znaleziono oświadczeń.');
        try {
            $cid = (int)($_POST['contract_id'] ?? 0);
            $nr = trim((string)($_POST['numer'] ?? ''));
            if (!$cid && $nr !== '') $cid = (int)(db_one("SELECT id FROM umowy_zlecenie WHERE numer_umowy = ? OR nr_rejestru = ? ORDER BY id DESC LIMIT 1", [$nr, $nr])['id'] ?? 0);
            $c = OswService::contract($cid);
            if (!$c) throw new RuntimeException('Nie znaleziono umowy zlecenia' . ($nr !== '' ? ' o numerze ' . $nr : '') . '.');
            if (!viewer_owns_contract('zlecenie', $c)) throw new RuntimeException('Brak dostępu do tej umowy.');
            OswService::linkContract($o, $cid, $this->uid());
            flash_set('success', 'Oświadczenia powiązano z umową ' . $c['numer_umowy'] . '.');
        } catch (\Throwable $e) {
            flash_set('danger', $e->getMessage());
        }
        return Router::redirect(route('osw.show', ['id' => $id]));
    }

    /** Pracownik: utwórz projekt umowy zlecenia z oświadczeń. */
    public function staffProject(int $id): ?string
    {
        $o = OswService::byId($id);
        if (!$o) $this->fail(404, 'Nie znaleziono oświadczeń.');
        try {
            $cid = OswService::createProject($o, $this->uid());
            flash_set('success', 'Utworzono projekt umowy zlecenia z danych oświadczeń.');
            return Router::redirect(rtrim(APP_URL, '/') . '/contracts/zlecenie/view.php?id=' . $cid . '&tab=oswiadczenie');
        } catch (\Throwable $e) {
            flash_set('danger', $e->getMessage());
            return Router::redirect(route('osw.show', ['id' => $id]));
        }
    }

    /** Ekran weryfikacji: podgląd skanu obok danych z kreatora, decyzja, przejście do następnego. */
    public function docShow(int $docId): string
    {
        [$doc, $o, $c] = $this->staffDoc($docId);
        return Router::view(self::VIEW . 'staffDoc.php', ['doc' => $doc, 'osw' => $o, 'contract' => $c, 'a' => OswService::answers($o),
            'docs' => OswService::docs((int)$o['id']), 'next' => OswService::nextToVerify($docId),
            'pending' => OswService::dashboardCounts()['weryfikacja'][3]]);
    }

    public function scan(int $docId): ?string
    {
        [$doc] = $this->staffDoc($docId);
        $abs = $doc['scan_path'] ? UPLOAD_DIR . $doc['scan_path'] : '';
        if ($abs === '' || !is_file($abs)) $this->fail(404, 'Plik skanu nie jest dostępny.');
        $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: ' . (['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'png' => 'image/png'][$ext] ?? 'application/octet-stream'));
        header('Content-Length: ' . filesize($abs));
        header('Content-Disposition: inline; filename="' . rawurlencode((string)($doc['scan_name'] ?: basename($abs))) . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        readfile($abs);
        return null;
    }

    public function staffPrint(int $docId, string $fmt): ?string
    {
        [$doc, $o, $c] = $this->staffDoc($docId);
        $base = !empty($o['account_confirmed_at']) ? OswAccount::url('/konto/logowanie')
              : ($o['token'] ? route('osw.t.home', ['token' => $o['token']]) : ($c ? route('osw.p.home', ['cid' => (int)$c['id']]) : ''));
        return $this->streamPrint($o, $c, $doc, $fmt, $base, false);
    }

    /** @return array{0: array, 1: array, 2: ?array} */
    private function staffDoc(int $docId): array
    {
        $doc = OswService::doc($docId);
        $o = $doc ? OswService::byId((int)$doc['osw_id']) : null;
        if (!$doc || !$o) $this->fail(404, 'Nie znaleziono dokumentu.');
        $c = OswService::contract((int)$o['contract_id']);
        if ($c && !viewer_owns_contract('zlecenie', $c)) $this->fail(403, 'Brak dostępu do tej umowy.');
        return [$doc, $o, $c];
    }

    // ── Wydruki ───────────────────────────────────────────────────────────────

    private function streamPrint(array $o, ?array $c, array $doc, string $fmt, string $base, bool $mark): ?string
    {
        $a = OswService::answers($o);
        $umowa = OswService::umowaMeta($c);
        $who = trim((string)($a['nazwisko_imiona'] ?? ''));
        $slug = preg_replace('/[^A-Za-z0-9]+/', '_', iconv('UTF-8', 'ASCII//TRANSLIT', $who) ?: 'zleceniobiorca');
        try {
            if ($doc['kind'] === 'zus' && $fmt === 'docx') {
                $bytes = OswiadczenieDocx::fill($a, $umowa);
                $mime = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
                $name = 'Oswiadczenie_ZUS_PIT_' . $slug . '.docx';
            } elseif ($fmt === 'pdf') {
                $meta = ['uploadUrl' => $base, 'umowa' => $umowa['numer'], 'kto' => $who, 'numer' => (string)$doc['numer'],
                         'data' => substr((string)($o['submitted_at'] ?: date('Y-m-d')), 0, 10), 'filledAt' => $o['submitted_at'], 'ip' => ''];
                $bytes = $doc['kind'] === 'zus' ? OswiadczeniePdf::zus($a, $umowa, $meta) : OswiadczeniePdf::zlb($a, $meta);
                $mime = 'application/pdf';
                $name = ($doc['kind'] === 'zus' ? 'Oswiadczenie_ZUS_PIT_' : 'ZLB_Oswiadczenie_rachunek_bankowy_') . $slug . '.pdf';
            } else {
                $this->fail(404, 'Nieznany format wydruku.');
            }
        } catch (\Throwable $e) {
            error_log('[zlecenie_oswiadczenie] druk: ' . $e->getMessage());
            $this->fail(500, 'Nie udało się przygotować wydruku: ' . $e->getMessage());
        }
        if ($mark) OswService::markPrinted($doc);
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . strlen($bytes));
        header('Content-Disposition: ' . ($fmt === 'pdf' ? 'inline' : 'attachment') . '; filename="' . $name . '"');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        echo $bytes;
        return null;
    }

    // ── Pomocnicze ────────────────────────────────────────────────────────────

    private function flash(string $type, string $msg): void { auth_start(); $_SESSION['_osw_flash'] = [$type, $msg]; }

    private function takeFlash(): ?array
    {
        auth_start();
        $f = $_SESSION['_osw_flash'] ?? null;
        unset($_SESSION['_osw_flash']);
        return $f;
    }
}
