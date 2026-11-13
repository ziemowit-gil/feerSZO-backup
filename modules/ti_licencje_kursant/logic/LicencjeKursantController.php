<?php
declare(strict_types=1);

/**
 * modules/ti_licencje_kursant/logic/LicencjeKursantController.php — panel zarządzania licencjami (kursant.feer.org.pl/licencje/).
 *
 * Jeden panel w stylu Portalu Kontrahenta (menu po lewej, sekcje na jednej stronie), własne logowanie (LicencjePanelAuth: login „l” + 8 cyfr
 * i hasło; uczestnik z panelu TI wchodzi bez ponownego logowania). Konto w panelu licencji zakłada kadra w panelu dydaktyka. Prostym językiem:
 *   • subskrypcje Claude i NordVPN (modules/claude_billing) — włączenie (dane do faktury + zgoda; Claude wymaga konta @edu.feer.org.pl —
 *     propozycja i wniosek, NordVPN — licencja z puli Fundacji), proforma / faktura, płatność Przelewy24 / przelew / Szybki przelew, rezygnacja,
 *   • licencje na Office (AM7), materiały dydaktyczne (AM5), moje sprawy (AM11–24, ANT1), programy i klucze (moduł Licencje),
 *   • produkty i usługi refakturowane przez FEER (modules/ti_products) — liczone osobno od subskrypcji, konto Microsoft 365 FEER,
 *   • moje konto: login, zmiana hasła, dane kontaktowe.
 * Pierwsze wejście: brakujący e-mail / telefon trzeba podać (telefon potwierdza kod SMS). Każda operacja wymaga kodu jednorazowego
 * (SMS, gdy nie wyjdzie — e-mail): confirmed() odkłada operację, /potwierdz sprawdza kod i wykonuje ją (LicencjeKod).
 * Wyniki: string = HTML, null = przekierowanie.
 */
require_once dirname(__DIR__, 2) . '/claude_billing/logic/claudeBilling.php';
require_once dirname(__DIR__, 2) . '/license_login/logic/licenseLogin.php';
require_once __DIR__ . '/LicencjePanelAuth.php';
require_once __DIR__ . '/LicencjeKod.php';
require_once __DIR__ . '/LicencjeId.php';
require_once dirname(__DIR__, 2) . '/claude_billing/logic/LicenceRequest.php';
require_once dirname(__DIR__, 2) . '/claude_billing/logic/PriceChange.php';
require_once dirname(__DIR__, 2) . '/claude_billing/logic/LicenceCard.php';   // płatności kartą (zgoda, podpięcie, wycofanie)
require_once dirname(__DIR__, 2) . '/ti_poczta_stud/logic/PocztaStud.php';     // poczta @stud.feer.org.pl (sdata.net.pl)

final class LicencjeKursantController
{
    private const VIEW = __DIR__ . '/../view/';
    public const OP_NAMES = ['AM11' => 'Licencja opłacana przez Fundację', 'AM12' => 'Zwrot kosztów licencji', 'AM13' => 'Licencja opłacana przez kogoś innego',
        'AM20' => 'Zmiana płatnika licencji', 'AM21' => 'Zmiana planu licencji', 'AM22' => 'Zawieszenie licencji', 'AM23' => 'Odebranie licencji',
        'AM24' => 'Przedłużenie licencji', 'ANT1' => 'Wniosek o dostęp do Claude'];
    public const OP_STATUS = ['signing' => 'czeka na Twój podpis (link w e-mailu i SMS-ie)', 'pending' => 'czeka na decyzję',
        'approved' => 'zatwierdzone, Dział IT wykonuje', 'executed' => 'gotowe', 'rejected' => 'odmowa', 'cancelled' => 'wycofane'];
    public const AM7_STATUS = ['draft' => 'wniosek w przygotowaniu', 'started' => 'wniosek złożony, czeka na decyzję', 'granted' => 'przyznana',
        'refused' => 'odmowa', 'withdrawn' => 'wniosek wycofany'];
    public const AM5_STATUS = ['invited' => 'czeka na Twoje oświadczenie (link w e-mailu)', 'submitted' => 'oświadczenie wysłane, sprawdzamy',
        'accepted' => 'masz dostęp', 'rejected' => 'oświadczenie do poprawy (szczegóły w e-mailu)', 'revoked' => 'dostęp cofnięty'];

    private static function org(): string
    {
        return (string)(org_setting('org_name') ?: (defined('ORG_NAME') ? ORG_NAME : 'Fundacja FEER'));
    }

    /** Zalogowana osoba (z sesji panelu licencji); bez logowania — na stronę logowania. */
    private function me(): array
    {
        $u = LicencjePanelAuth::user();
        if (!$u) { header('Location: ' . route('lk.login')); exit; }
        $cid = (int)$u['cid'];
        $c = db_one("SELECT name, email, phone FROM k30_clients WHERE id=?", [$cid]) ?: ['name' => '', 'email' => '', 'phone' => ''];
        $sid = (int)($u['sid'] ?? 0) ?: (int)(db_one("SELECT id FROM k30_ti_student_accounts WHERE client_id=? ORDER BY id DESC LIMIT 1", [$cid])['id'] ?? 0);
        return ['cid' => $cid, 'sid' => $sid, 'name' => (string)$c['name'], 'email' => (string)$c['email'], 'phone' => (string)($c['phone'] ?? ''), 'ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''),
                'via' => (string)$u['via'], 'fromTi' => !empty($u['sid']), 'imp' => (string)($u['imp'] ?? '')];
    }

    private function log(array $me, string $action, string $text): void
    {
        if ($me['sid'] > 0 && function_exists('ti_account_log')) ti_account_log($me['sid'], $action, $text);
    }

    /** Podstrony panelu: klucz => [trasa, tytuł]. Produkty płatne (claude, nordvpn) mają podstronę lk.licence. */
    public const PAGES = ['office' => ['lk.office', 'Licencje na Office'], 'programy' => ['lk.programs', 'Programy i klucze'], 'materialy' => ['lk.materials', 'Materiały dydaktyczne'],
        'sprawy' => ['lk.cases', 'Moje sprawy'], 'produkty' => ['lk.products', 'Produkty i usługi'], 'rozliczenia' => ['lk.billing', 'Rozliczenia'], 'dysk' => ['lk.drive', 'Dysk Online'], 'poczta' => ['lk.mail', 'Poczta kursanta'], 'konto' => ['lk.account', 'Moje konto']];

    /** Adres podstrony dla klucza (produkt płatny, strona albo '' = start). */
    public static function pageUrl(string $key): string
    {
        if (isset(ClaudeBilling::PRODUCTS[$key])) return route('lk.licence', ['product' => $key]);
        if (isset(self::PAGES[$key])) return route(self::PAGES[$key][0]);
        return route('lk.index');
    }

    private function flash(string $type, string $msg, string $anchor = ''): ?string
    {
        $_SESSION['lk_flash'] = [$type, $msg];
        header('Location: ' . self::pageUrl($anchor));
        return null;
    }

    // ── Potwierdzanie operacji kodem ────────────────────────────────────────────

    /** Operacje, które można wykonać po kodzie (whitelista metod kontrolera). */
    private const CONFIRMABLE = ['wizardPost', 'edu', 'buyer', 'cancel', 'password', 'priceDecide', 'cardPost', 'cardRemove', 'officePlan', 'driveJoin', 'mailReveal'];

    /**
     * Brama kodu: true — operacja potwierdzona, wykonuj; false — kod wysłany (albo błąd), przekierowanie już ustawione (handler zwraca null).
     * Wywoływać PO walidacji formularza, żeby kod nie szedł do błędnych danych.
     */
    private function confirmed(string $method, array $args, string $what, string $anchor): bool
    {
        $sig = $method . '|' . implode('|', $args);
        if (($_SESSION['lk_op_ok'] ?? '') === $sig) { unset($_SESSION['lk_op_ok']); return true; }
        $me = $this->me();
        $post = $_POST; unset($post['_token'], $post['code']);
        $_SESSION['lk_op'] = ['sig' => $sig, 'method' => $method, 'args' => $args, 'post' => $post, 'what' => $what, 'anchor' => $anchor, 'cid' => $me['cid'], 'at' => time()];
        $r = LicencjeKod::send('op', $me['cid'], $me['phone'], $me['email'], $what);
        if (!$r['ok']) { unset($_SESSION['lk_op']); $this->flash('err', $r['error'], $anchor); return false; }
        header('Location: ' . route('lk.confirm'));
        return false;
    }

    public function confirmForm(): string
    {
        $me = $this->me();
        $op = $_SESSION['lk_op'] ?? null;
        if (!$op || (int)$op['cid'] !== $me['cid']) { header('Location: ' . route('lk.index')); exit; }
        $err = $_SESSION['lk_code_err'] ?? ''; unset($_SESSION['lk_code_err']);
        return Router::view(self::VIEW . 'confirm.php', ['me' => $me, 'op' => $op, 'otp' => LicencjeKod::pending('op'), 'err' => $err, 'org' => self::org(),
            'flash' => $this->takeFlash()]);
    }

    public function confirmPost(): ?string
    {
        $me = $this->me();
        $op = $_SESSION['lk_op'] ?? null;
        if (!$op || (int)$op['cid'] !== $me['cid'] || !in_array($op['method'], self::CONFIRMABLE, true)) return $this->flash('err', 'Nie ma nic do potwierdzenia. Zacznij od nowa.');
        if (time() - (int)$op['at'] > LicencjeKod::TTL) { unset($_SESSION['lk_op']); return $this->flash('err', 'Minęło ponad 10 minut. Zacznij od nowa.', $op['anchor']); }
        $e = LicencjeKod::verify('op', (string)($_POST['code'] ?? ''));
        if ($e !== '') { $_SESSION['lk_code_err'] = $e; header('Location: ' . route('lk.confirm')); return null; }
        unset($_SESSION['lk_op']);
        $this->log($me, 'license_panel_code', 'Potwierdzono kodem: ' . $op['what'] . '.');
        $_SESSION['lk_op_ok'] = $op['sig'];
        $_POST = $op['post'];
        return $this->{$op['method']}(...$op['args']);
    }

    public function confirmResend(): ?string
    {
        $me = $this->me();
        $op = $_SESSION['lk_op'] ?? null;
        if (!$op) return $this->flash('err', 'Nie ma nic do potwierdzenia.');
        $prev = LicencjeKod::pending('op');
        if ($prev && time() - (int)$prev['sent'] < LicencjeKod::RESEND) {
            $_SESSION['lk_code_err'] = 'Nowy kod możesz wysłać za ' . (LicencjeKod::RESEND - (time() - (int)$prev['sent'])) . ' s.';
        } else {
            unset($_SESSION['lk_otp']['op']);
            $r = LicencjeKod::send('op', $me['cid'], $me['phone'], $me['email'], $op['what']);
            $_SESSION[$r['ok'] ? 'lk_flash' : 'lk_code_err'] = $r['ok'] ? ['ok', 'Wysłaliśmy nowy kod.'] : $r['error'];
        }
        header('Location: ' . route('lk.confirm'));
        return null;
    }

    public function confirmCancel(): ?string
    {
        $op = $_SESSION['lk_op'] ?? null;
        unset($_SESSION['lk_op'], $_SESSION['lk_otp']['op']);
        return $this->flash('info', 'Anulowano. Nic się nie zmieniło.', (string)($op['anchor'] ?? ''));
    }

    private function takeFlash(): ?array
    {
        $f = $_SESSION['lk_flash'] ?? null; unset($_SESSION['lk_flash']);
        return $f;
    }

    // ── Dane kontaktowe (pierwsze wejście i zmiana w „Moje konto”) ─────────────

    public function contactForm(): string
    {
        $me = $this->me();
        $c = LicencjeKod::contact($me['cid']);
        $pending = $_SESSION['lk_contact_new'] ?? null;
        if ($pending && (int)$pending['cid'] !== $me['cid']) $pending = null;
        $err = $_SESSION['lk_contact_err'] ?? []; unset($_SESSION['lk_contact_err']);
        return Router::view(self::VIEW . 'contact.php', ['me' => $me, 'c' => $c, 'required' => LicencjeKod::needsContact($me['cid']), 'pending' => $pending,
            'otp' => LicencjeKod::pending('contact'), 'err' => $err, 'org' => self::org(), 'flash' => $this->takeFlash()]);
    }

    public function contactPost(): ?string
    {
        $me = $this->me();
        $email = trim((string)($_POST['email'] ?? '')); $phone = trim((string)($_POST['phone'] ?? ''));
        $err = [];
        if (!LicencjeKod::validEmail($email)) $err['email'] = 'Wpisz adres e-mail, np. jan.kowalski@gmail.com.';
        if (!LicencjeKod::validPhone($phone)) $err['phone'] = 'Wpisz numer komórki: 9 cyfr, np. 600 100 200. Numer zagraniczny wpisz z +.';
        if ($err) { $_SESSION['lk_contact_err'] = $err + ['_old' => ['email' => $email, 'phone' => $phone]]; header('Location: ' . route('lk.contact')); return null; }
        unset($_SESSION['lk_otp']['contact']);
        $_SESSION['lk_contact_new'] = ['cid' => $me['cid'], 'email' => $email, 'phone' => LicencjeKod::normPhone($phone)];
        $r = LicencjeKod::send('contact', $me['cid'], $phone, $email, 'numer telefonu w panelu licencji');
        if (!$r['ok']) { $_SESSION['lk_contact_err'] = ['_form' => $r['error'], '_old' => ['email' => $email, 'phone' => $phone]]; unset($_SESSION['lk_contact_new']); }
        header('Location: ' . route('lk.contact'));
        return null;
    }

    public function contactCode(): ?string
    {
        $me = $this->me();
        $new = $_SESSION['lk_contact_new'] ?? null;
        if (!empty($_POST['back'])) { unset($_SESSION['lk_contact_new'], $_SESSION['lk_otp']['contact']); header('Location: ' . route('lk.contact')); return null; }
        if (!$new || (int)$new['cid'] !== $me['cid']) { header('Location: ' . route('lk.contact')); return null; }
        $e = LicencjeKod::verify('contact', (string)($_POST['code'] ?? ''));
        if ($e !== '') { $_SESSION['lk_contact_err'] = ['code' => $e]; header('Location: ' . route('lk.contact')); return null; }
        $first = LicencjeKod::contact($me['cid'])['confirmed'] === null;
        LicencjeKod::confirm($me['cid'], $new['email'], $new['phone'], 'panel licencji (kod SMS)');
        unset($_SESSION['lk_contact_new']);
        $this->log($me, 'license_panel_contact', 'Dane kontaktowe w panelu licencji: ' . $new['email'] . ', ' . $new['phone'] . ' (potwierdzone kodem).');
        return $this->flash('ok', $first ? 'Dane zapisane. Możesz już zarządzać licencjami. Każdą zmianę potwierdzasz kodem SMS.' : 'Nowe dane kontaktowe zapisane.', $first ? '' : 'konto');
    }

    // ── Zmiana ceny: decyzja ────────────────────────────────────────────────────

    /** Decyzja w panelu (zalogowany uczestnik, pełnoletni) — po kodzie. */
    public function priceDecide(string $id): ?string
    {
        $me = $this->me();
        $a = PriceChange::answer((int)$id);
        if (!$a || (int)$a['client_id'] !== $me['cid'] || $a['recipient'] !== 'uczestnik') return $this->flash('err', 'Nie znaleziono zmiany ceny.');
        $p = ClaudeBilling::product((string)$a['change']['product']);
        $d = (string)($_POST['decision'] ?? '');
        try { PriceChange::validate($a, $d, $me['name']); } catch (\Throwable $e) { return $this->flash('err', $e->getMessage(), $p); }
        if (!$this->confirmed('priceDecide', [(string)(int)$id], ($d === 'accept' ? 'akceptacja' : 'rezygnacja') . ' nowej ceny ' . ClaudeBilling::P($p)['label'], $p)) return null;
        PriceChange::decideAnswer($a, $d, $me['name'], $me['ip'], 'panel licencji');
        $this->log($me, 'license_price_answer', 'Zmiana ceny ' . ClaudeBilling::P($p)['label'] . ': ' . ($d === 'accept' ? 'akceptacja' : 'rezygnacja') . '.');
        $from = ClaudeBilling::dayMonth((string)$a['change']['effective_from']);
        return $this->flash('ok', $d === 'accept' ? 'Zapisane: akceptujesz nową cenę od ' . $from . '.' : 'Zapisane: rezygnujesz. Dostęp kończy się przed ' . $from . '. Do tego dnia płacisz starą cenę.', $p);
    }

    /** Strona z linku w e-mailu (uczestnik albo opiekun). */
    public function priceLink(string $token): string
    {
        $a = PriceChange::byToken($token);
        $st = $_SESSION['lk_price'][hash('sha256', $token)] ?? null;
        $err = $_SESSION['lk_price_err'] ?? ''; unset($_SESSION['lk_price_err']);
        return Router::view(self::VIEW . 'price.php', ['a' => $a, 'token' => $token, 'st' => $st, 'otp' => $a ? LicencjeKod::pending('price:' . $a['id']) : null,
            'cmp' => $a ? PriceChange::compare($a['change']) : null, 'err' => $err, 'org' => self::org(), 'flash' => $this->takeFlash()]);
    }

    public function priceLinkPost(string $token): ?string
    {
        $a = PriceChange::byToken($token);
        $back = function (string $e = '') use ($token) { if ($e !== '') $_SESSION['lk_price_err'] = $e; header('Location: ' . route('lk.price.link', ['token' => $token])); return null; };
        if (!$a) return $back('Link jest nieważny.');
        $k = hash('sha256', $token); $purpose = 'price:' . $a['id'];
        if (($_POST['step'] ?? '') === 'code') {
            $st = $_SESSION['lk_price'][$k] ?? null;
            if (!$st) return $back('Wybierz decyzję jeszcze raz.');
            $e = LicencjeKod::verify($purpose, (string)($_POST['code'] ?? ''));
            if ($e !== '') return $back($e);
            try { PriceChange::decide($token, $st['decision'], $st['name'], (string)($_SERVER['REMOTE_ADDR'] ?? '')); }
            catch (\Throwable $e) { return $back($e->getMessage()); }
            unset($_SESSION['lk_price'][$k]);
            $_SESSION['lk_flash'] = ['ok', 'Decyzja zapisana.'];
            return $back();
        }
        if (($_POST['step'] ?? '') === 'back') { unset($_SESSION['lk_price'][$k], $_SESSION['lk_otp'][$purpose]); return $back(); }
        $d = (string)($_POST['decision'] ?? ''); $name = trim((string)($_POST['name'] ?? ''));
        try { PriceChange::validate($a, $d, $name); } catch (\Throwable $e) { return $back($e->getMessage()); }
        $t = PriceChange::codeTarget($a);
        unset($_SESSION['lk_otp'][$purpose]);
        $r = LicencjeKod::send($purpose, (int)$a['client_id'], $t['phone'], $t['email'], ($d === 'accept' ? 'akceptacja' : 'rezygnacja') . ' nowej ceny');
        if (!$r['ok']) return $back($r['error']);
        $_SESSION['lk_price'][$k] = ['decision' => $d, 'name' => $name];
        return $back();
    }

    // ── Logowanie ───────────────────────────────────────────────────────────────

    public function loginForm(): string
    {
        if (LicencjePanelAuth::user()) { header('Location: ' . route('lk.index')); exit; }
        $err = $_SESSION['lk_login_err'] ?? ''; $old = $_SESSION['lk_login_old'] ?? ''; unset($_SESSION['lk_login_err'], $_SESSION['lk_login_old']);
        return Router::view(self::VIEW . 'login.php', ['err' => $err, 'old' => $old, 'org' => self::org(), 'flash' => $this->takeFlash(),
            'adminOn' => LicencjePanelAuth::admin() !== null, 'adminSzo' => LicencjePanelAuth::adminLoginUrl('szo'), 'adminMs' => LicencjePanelAuth::adminLoginUrl('ms365')]);
    }

    public function loginPost(): ?string
    {
        $login = trim((string)($_POST['login'] ?? '')); $pass = (string)($_POST['password'] ?? '');
        $back = function (string $e) use ($login) { $_SESSION['lk_login_err'] = $e; $_SESSION['lk_login_old'] = $login; header('Location: ' . route('lk.login')); return null; };
        if ($login === '' || $pass === '') return $back('Wpisz login i hasło.');
        if (LicencjePanelAuth::throttled($login)) return $back('Było za dużo nieudanych prób. Spróbuj ponownie za 15 minut.');
        $cid = LicenseLogin::verify($login, $pass);
        if (!$cid) { LicencjePanelAuth::recordFail($login); return $back('Login albo hasło się nie zgadza. Sprawdź je i spróbuj jeszcze raz.'); }
        LicencjePanelAuth::login($cid, 'login ' . strtolower($login));
        $sid = (int)(db_one("SELECT id FROM k30_ti_student_accounts WHERE client_id=? ORDER BY id DESC LIMIT 1", [$cid])['id'] ?? 0);
        if ($sid > 0 && function_exists('ti_account_log')) ti_account_log($sid, 'license_panel_login', 'Logowanie do panelu licencji (' . strtolower($login) . ').');
        header('Location: ' . route('lk.index'));
        return null;
    }

    public function logout(): ?string
    {
        if (LicencjePanelAuth::endImpersonation() !== null) { header('Location: ' . route('lk.admin')); return null; }   // podgląd administratora: powrót do jego części
        LicencjePanelAuth::logout();
        header('Location: ' . route('lk.login'));
        return null;
    }

    // ── Panel ───────────────────────────────────────────────────────────────────

    public function index(): string { return $this->render('start'); }
    public function office(): string { return $this->render('office'); }
    public function programs(): string { return $this->render('programy'); }
    public function materials(): string { return $this->render('materialy'); }
    public function cases(): string { return $this->render('sprawy'); }

    /** Podgląd sprawy AM (op = operacja AM11–AM24, lic = wniosek AM5/AM7) albo zgłoszenia AM… (hd) z korespondencją. */
    public function caseView(string $kind, string $id): string
    {
        $me = $this->me();
        require_once __DIR__ . '/LicencjeSprawy.php';
        require_once dirname(__DIR__, 3) . '/includes/helpdesk.php';   // hd_sanitize_body — bezpieczne wyświetlenie wiadomości
        $case = in_array($kind, ['op', 'lic'], true) ? LicencjeSprawy::case($me['cid'], $kind, (int)$id) : null;
        $tid = $kind === 'hd' ? (int)$id : (int)($case['ticket'] ?? 0);
        $ticket = $tid ? LicencjeSprawy::ticket($me['cid'], $tid) : null;
        if (!$case && !$ticket) { $this->flash('err', 'Nie znaleziono sprawy.', 'sprawy'); exit; }
        return $this->render('sprawa', ['sprawa' => $case, 'ticket' => $ticket, 'msgs' => $ticket ? LicencjeSprawy::messages((int)$ticket['id']) : [], 'caseKind' => $kind, 'caseId' => (int)$id]);
    }

    /** Odpowiedź uczestnika w zgłoszeniu AM… (korespondencja). */
    public function caseReply(string $id): ?string
    {
        $me = $this->me();
        require_once __DIR__ . '/LicencjeSprawy.php';
        $kind = in_array((string)($_POST['kind'] ?? 'hd'), ['op', 'lic', 'hd'], true) ? (string)$_POST['kind'] : 'hd';
        $back = route('lk.case', ['kind' => $kind, 'id' => (int)($_POST['back_id'] ?? $id)]);
        try {
            $msg = LicencjeSprawy::reply($me['cid'], (int)$id, (string)($_POST['body'] ?? ''), $me['name']);
            $this->log($me, 'case_reply', 'Odpowiedź w zgłoszeniu #' . (int)$id . '.');
            $_SESSION['lk_flash'] = ['ok', $msg];
        } catch (\Throwable $e) { $_SESSION['lk_flash'] = ['err', $e->getMessage()]; }
        header('Location: ' . $back);
        return null;
    }
    public function products(): string { return $this->render('produkty'); }
    public function account(): string { return $this->render('konto'); }
    public function licence(string $product): string
    {
        if (!isset(ClaudeBilling::PRODUCTS[$product])) { header('Location: ' . route('lk.index')); exit; }
        return $this->render($product);
    }

    /** Pobranie dokumentu: symulacja (SYM-…) → PDF z SZO; prawdziwy → podgląd w Fakturowni. */
    public function document(string $id, string $doc): ?string
    {
        $me = $this->me();
        $ch = ClaudeBilling::charge((int)$id);
        if (!$ch || (int)$ch['client_id'] !== $me['cid'] || !in_array($doc, ['proforma', 'order', 'invoice'], true)) { http_response_code(404); return 'Nie znaleziono dokumentu.'; }
        require_once dirname(__DIR__, 2) . '/claude_billing/logic/ClaudeInvoice.php';
        if (ClaudeInvoice::isSim((string)$ch[$doc . '_number']) && !ClaudeBilling::isVirtual($me['cid']) && $doc !== 'proforma') { http_response_code(404); return 'Nie znaleziono dokumentu.'; }   // symulacje faktur widzi tylko konto testowe; proforma — podgląd
        try { ClaudeInvoice::sendPdf(ClaudeInvoice::pdf((int)$id, $doc)); }
        catch (\Throwable $e) {
            $url = $doc === 'order' ? '' : (string)$ch[$doc . '_view_url'];
            if ($url !== '') { header('Location: ' . $url); return null; }   // PDF z Fakturowni chwilowo niedostępny — podgląd online
            error_log('lk pdf: ' . $e->getMessage());
            return $this->flash('err', 'Nie udało się pobrać PDF. Spróbuj za chwilę.', ClaudeBilling::productOf($ch));
        }
    }

    /** Przejście do portalu płatności (/platnosci) jednym kliknięciem — tam wszystkie opłaty uczestnika (zajęcia, licencje, inne). */
    public function portal(): ?string
    {
        $me = $this->me();
        if (ClaudeBilling::isMinor($me['cid'])) return $this->flash('err', 'Płatności osoby niepełnoletniej prowadzi opiekun w panelu rodzica.');
        require_once dirname(__DIR__, 3) . '/modules/payment_portal/logic/paymentPortal.php';
        $this->log($me, 'license_panel_portal', 'Przejście z panelu licencji do portalu płatności.');
        if (!pp_portal_login_participant($me['cid'], 'panel licencji')) return $this->flash('err', 'Portal płatności jest dla tego konta niedostępny. Napisz do biura.');
        header('Location: /platnosci/');   // ten sam host — ciasteczko sesji portalu (ścieżka /platnosci) zostaje po tej stronie
        exit;
    }

    /** Konto testowe: symulowana wpłata (bez Przelewy24) — dalej zamówienie i faktura w symulacji. */
    public function simPay(string $id): ?string
    {
        $me = $this->me();
        $ch = ClaudeBilling::charge((int)$id);
        if (!$ch || (int)$ch['client_id'] !== $me['cid']) { http_response_code(404); return 'Nie znaleziono opłaty.'; }
        $p = ClaudeBilling::productOf($ch);
        if (!ClaudeBilling::isVirtual($me['cid'])) return $this->flash('err', 'Symulacja wpłaty działa tylko na koncie testowym.', $p);
        try {
            ClaudeBilling::markPaid((int)$id, 'symulacja (konto testowe)', 'SYM-' . date('YmdHis'), ['name' => $me['name'], 'via' => 'panel licencji']);
            ClaudeBilling::afterPayment();
        } catch (\Throwable $e) { return $this->flash('err', $e->getMessage(), $p); }
        $ch = ClaudeBilling::charge((int)$id);
        return $this->flash('ok', 'Symulacja: wpłata ' . ClaudeBilling::zl((float)$ch['amount_pln']) . ' zaksięgowana. Zamówienie ' . ($ch['order_number'] ?: '(w przygotowaniu)') . ' i faktura ' . ($ch['invoice_number'] ?: '(w przygotowaniu)') . ' są do pobrania.', $p);
    }

    private function render(string $page, array $extra = []): string
    {
        $me = $this->me();
        $cid = $me['cid'];
        $paid = [];
        foreach (array_keys(ClaudeBilling::PRODUCTS) as $p) {
            try {
                $v = ClaudeBilling::participantView($cid, $p);
                $v['fromPool'] = false;
                if (!$v['sub'] || !in_array($v['sub']['status'], ['requested', 'active', 'suspended'], true)) $v['eligible'] = LicenceRequest::eligibility($cid, $p);   // wniosek z kreatora
                $v['orderable'] = $v['settings']['enabled'] && $v['settings']['priced'];
                if (!$v['hasLicense'] && !$v['sub'] && !$v['orderable']) continue;   // pokazujemy, co można zamówić, i to, co już jest   // nie dotyczy tej osoby (NordVPN: tylko gdy licencja jest przypisana do uczestnika)
                $v['edu'] = !empty(ClaudeBilling::P($p)['requiresEdu']) ? ['account' => ClaudeBilling::eduAccount($cid), 'request' => ClaudeBilling::eduRequest($cid)] : null;
                $v['payMode'] = $v['sub'] ? LicenceCard::mode($v['sub']) : (string)$v['settings']['pay_mode'];   // invoice | card
                $v['priceChange'] = $v['sub'] && in_array($v['sub']['status'], ['active', 'suspended'], true) ? PriceChange::openForSubscription((int)$v['sub']['id']) : null;
                if ($v['priceChange']) $v['priceCmp'] = PriceChange::compare($v['priceChange']['change']);
                $paid[$p] = $v;
            } catch (\Throwable $e) { error_log('lk ' . $p . ': ' . $e->getMessage()); }
        }
        $software = function_exists('k30_ti_client_licenses') ? k30_ti_client_licenses($cid) : [];
        require_once dirname(__DIR__, 3) . '/includes/ti_terms.php';
        $term = ti_term_get('licencje');
        $termOk = !$term || empty($term['is_active']) || ti_term_accepted($cid, 'licencje');
        $office = $am5 = $ops = [];
        try {
            $office = db_all("SELECT id, case_number, title, request_status, period_from, period_to, tenant_upn, tenant_kind, decided_at, helpdesk_number, created_at
                                FROM licenses WHERE interested_kind='client' AND interested_id=? AND COALESCE(form_kind,'AM5')='AM7' ORDER BY id DESC", [$cid]);
            $am5 = db_all("SELECT a.id, a.status, a.due_on, a.assigned_at, a.decided_at, l.title, l.case_number, l.period_from, l.period_to
                             FROM license_assignments a JOIN licenses l ON l.id=a.license_id
                            WHERE a.holder_kind='client' AND a.holder_id=? AND COALESCE(l.form_kind,'AM5')='AM5' ORDER BY a.id DESC", [$cid]);
            $ops = db_all("SELECT id, number, form, status, created_at FROM license_operations WHERE account_kind='client' AND account_id=? ORDER BY id DESC LIMIT 20", [$cid]);
        } catch (\Throwable $e) { /* moduł AM jeszcze bez tabel */ }
        // produkty i usługi refakturowane przez FEER — liczone osobno od subskrypcji
        require_once dirname(__DIR__, 2) . '/ti_products/logic/tiProducts.php';
        $products = array_values(array_filter(TiProducts::forClient($cid), fn($p) => $p['status'] !== 'archived'));
        $prodMonthly = 0.0;
        foreach ($products as $k => $pr) {
            $amt = (float)($pr['monthly_amount'] ?? 0); $disc = (float)($pr['discount_value'] ?? 0);
            $products[$k]['_after'] = $amt > 0 && $disc > 0 ? ($pr['discount_type'] === 'pct' ? round($amt * (1 - $disc / 100), 2) : max(0, round($amt - $disc, 2))) : $amt;
            if ($pr['status'] !== 'suspended') $prodMonthly += $products[$k]['_after'];
        }
        $feerUpn = '';
        if ($me['sid']) { try { $feerUpn = trim((string)(db_one("SELECT feer_ms_upn FROM k30_ti_student_accounts WHERE id=?", [$me['sid']])['feer_ms_upn'] ?? '')); } catch (\Throwable $e) {} }
        require_once dirname(__DIR__, 3) . '/includes/p24.php';
        // konto Office (inicjał.nazwisko@edu): na stronach Office i Claude sprawdzamy tenant EDU co najwyżej raz na 12 godzin (konto tej osoby wiąże się samo)
        require_once dirname(__DIR__, 2) . '/claude_billing/logic/LicenceOffice.php';
        if (in_array($page, ['office', 'claude'], true) && !ClaudeBilling::eduAccount($cid)) {
            $oa = LicenceOffice::get($cid);
            if (!$oa || !$oa['checked_at'] || strtotime((string)$oa['checked_at']) < time() - 43200) { try { LicenceOffice::check($cid, $me['name'] . ' (panel licencji)'); } catch (\Throwable $e) { error_log('lk office: ' . $e->getMessage()); } }
        }
        $flash = $_SESSION['lk_flash'] ?? null; $err = $_SESSION['lk_errors'] ?? []; $old = $_SESSION['lk_old'] ?? [];
        unset($_SESSION['lk_flash'], $_SESSION['lk_errors'], $_SESSION['lk_old']);
        return Router::view(self::VIEW . 'index.php', ['page' => $page, 'ids' => ['lic' => LicencjeId::licence($cid), 'ben' => LicencjeId::beneficiary($cid)], 'virtual' => ClaudeBilling::isVirtual($cid), 'me' => $me, 'paid' => $paid, 'software' => $software, 'termOk' => $termOk, 'office' => $office, 'am5' => $am5,
            'ops' => $ops, 'products' => $products, 'prodMonthly' => round($prodMonthly, 2), 'feerUpn' => $feerUpn, 'p24on' => p24_enabled(),
            'login' => LicenseLogin::get($cid), 'hasPassword' => LicenseLogin::hasPassword($cid), 'minor' => ClaudeBilling::isMinor($cid),
            'contact' => LicencjeKod::contact($cid), 'eduAccount' => ClaudeBilling::eduAccount($cid), 'officeAcc' => LicenceOffice::summary($cid),
            'card' => LicenceCard::active($cid), 'consents' => LicenceCard::consents($cid),
            'sprawy' => (function () use ($cid) { require_once __DIR__ . '/LicencjeSprawy.php'; return LicencjeSprawy::cases($cid); })(),
            'tickets' => in_array($page, ['sprawy', 'start'], true) ? LicencjeSprawy::tickets($cid) : [],
            'driveReq' => $page === 'dysk' ? self::driveRequest($cid) : null,
            'studMail' => PocztaStud::get($cid),   // hasło ($studPass) przychodzi tylko w $extra z mail() — tablica bazowa wygrywa przy „+”
            'accessMsgs' => (function () use ($cid) { require_once __DIR__ . '/LicencjeDostep.php'; return LicencjeDostep::forClient($cid); })(),
            'flash' => $flash, 'err' => $err, 'old' => $old, 'org' => self::org()] + $extra);
    }


    // ── Rozliczenia: karta (zgoda, podpięcie, wycofanie) i dane do faktur ──────────

    public function billing(): string { return $this->render('rozliczenia'); }

    private function openSub(int $cid, string $p): ?array
    {
        $sub = ClaudeBilling::forClient($cid, false, $p);
        return $sub && in_array($sub['status'], ['requested', 'active', 'suspended'], true) ? $sub : null;
    }

    /** Zgoda na płatności kartą (treść ustalona przez Fundację) i kwoty przed udzieleniem zgody. */
    public function cardForm(string $product): string
    {
        $me = $this->me();
        $p = ClaudeBilling::product($product);
        $sub = $this->openSub($me['cid'], $p);
        if (!$sub) { $this->flash('err', 'Najpierw złóż wniosek o ' . ClaudeBilling::P($p)['label'] . '.', $p); exit; }
        if (LicenceCard::mode($sub) !== 'card') { $this->flash('info', 'Za ' . ClaudeBilling::P($p)['label'] . ' płacisz przelewem po proformie.', 'rozliczenia'); exit; }
        $err = $_SESSION['lk_card_err'] ?? ''; unset($_SESSION['lk_card_err']);
        require_once dirname(__DIR__, 3) . '/includes/p24.php';
        return Router::view(self::VIEW . 'card.php', ['me' => $me, 'p' => $p, 'P' => ClaudeBilling::P($p), 'sub' => $sub, 't' => LicenceCard::texts($p), 'pv' => LicenceCard::preview($sub),
            'card' => LicenceCard::active($me['cid']), 'virtual' => ClaudeBilling::isVirtual($me['cid']), 'p24on' => p24_enabled(), 'err' => $err, 'org' => self::org(), 'flash' => $this->takeFlash()]);
    }

    /** „Udzielam zgody i przechodzę do płatności”: kod jednorazowy, zapis zgody (PDF do teczki), opłata weryfikacyjna w Przelewy24 (tylko karty). */
    public function cardPost(string $product): ?string
    {
        $me = $this->me();
        $p = ClaudeBilling::product($product);
        $sub = $this->openSub($me['cid'], $p);
        if (!$sub) return $this->flash('err', 'Najpierw złóż wniosek o ' . ClaudeBilling::P($p)['label'] . '.', $p);
        if (empty($_POST['zgoda'])) { $_SESSION['lk_card_err'] = 'Zaznacz pole zgody, żeby przejść do płatności.'; header('Location: ' . route('lk.card', ['product' => $p])); return null; }
        if (!$this->confirmed('cardPost', [$p], 'zgoda na płatności kartą (' . ClaudeBilling::P($p)['label'] . ')', 'rozliczenia')) return null;
        try {
            $back = LicencjePanelAuth::publicUrl() . '?_route=' . rawurlencode('/karta/' . $p . '/powrot');
            $r = LicenceCard::begin((int)$sub['id'], ['name' => $me['name'], 'via' => 'panel licencji', 'ip' => $me['ip'],
                'confirm' => 'kod jednorazowy wysłany na ' . LicencjeKod::mask((string)($me['phone'] ?: $me['email']))], $back);
            $this->log($me, 'card_consent', 'Zgoda na płatności kartą #' . $r['consent'] . ' (' . ClaudeBilling::P($p)['label'] . ').');
            if ($r['sim']) {
                $card = LicenceCard::active($me['cid']);
                return $this->flash('ok', 'Konto testowe: symulacja. Karta ' . LicenceCard::label($card) . ' podpięta, opłata weryfikacyjna i faktura w symulacji. Zgoda jest w Twoich dokumentach.', 'rozliczenia');
            }
            header('Location: ' . $r['url']);
            return null;
        } catch (DomainException | InvalidArgumentException $e) {
            return $this->flash('err', $e->getMessage(), 'rozliczenia');
        } catch (\Throwable $e) {
            error_log('lk karta: ' . $e);
            return $this->flash('err', 'Nie udało się rozpocząć płatności kartą. Spróbuj za chwilę albo napisz do biura.', 'rozliczenia');
        }
    }

    /** Powrót z Przelewy24 po opłacie weryfikacyjnej. Wynik przychodzi powiadomieniem — tu tylko stan. */
    public function cardReturn(string $product): ?string
    {
        $me = $this->me();
        $p = ClaudeBilling::product($product);
        require_once dirname(__DIR__, 3) . '/includes/p24.php';
        $last = db_one("SELECT pp.id FROM p24_payments pp JOIN claude_charges ch ON ch.id=pp.source_id WHERE pp.source_type='claude_charge' AND ch.client_id=? AND ch.kind='card' ORDER BY pp.id DESC LIMIT 1", [$me['cid']]);
        if ($last) { try { p24_reconcile_payment((int)$last['id']); ClaudeBilling::afterPayment(); } catch (\Throwable $e) {} }
        $card = LicenceCard::active($me['cid']);
        if ($card) return $this->flash('ok', 'Karta ' . LicenceCard::label($card) . ' podpięta. Opłaty za ' . ClaudeBilling::P($p)['label'] . ' pobieramy teraz automatycznie, faktury VAT przyjdą e-mailem.', 'rozliczenia');
        return $this->flash('info', 'Czekamy na potwierdzenie płatności z Przelewy24. Zwykle trwa to minutę. Odśwież tę stronę za chwilę.', 'rozliczenia');
    }

    /** Wycofanie zgody i odpięcie karty (kod jednorazowy). Nie jest rezygnacją z licencji. */
    public function cardRemove(): ?string
    {
        $me = $this->me();
        if (!LicenceCard::active($me['cid'])) return $this->flash('info', 'Nie masz podpiętej karty.', 'rozliczenia');
        if (!$this->confirmed('cardRemove', [], 'wycofanie zgody na płatności kartą', 'rozliczenia')) return null;
        try {
            $msg = LicenceCard::withdraw($me['cid'], ['name' => $me['name'] . ' (panel licencji)']);
            $this->log($me, 'card_withdraw', 'Wycofano zgodę na płatności kartą.');
            return $this->flash('ok', $msg, 'rozliczenia');
        } catch (\Throwable $e) { return $this->flash('err', $e->getMessage(), 'rozliczenia'); }
    }

    /** PDF własnej zgody na płatności kartą. */
    public function consentPdf(string $id): ?string
    {
        $me = $this->me();
        $z = LicenceCard::consent((int)$id);
        if (!$z || (int)$z['client_id'] !== $me['cid']) { http_response_code(404); return 'Nie znaleziono dokumentu.'; }
        $f = LicenceCard::consentFile((int)$id);
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $f['name'] . '"');
        header('Content-Length: ' . strlen($f['bytes']));
        echo $f['bytes'];
        exit;
    }

    /** Dawne bezpośrednie włączanie — teraz kreator wniosku. */
    public function activate(string $product): ?string
    {
        header('Location: ' . route('lk.wizard', ['product' => ClaudeBilling::product($product)]));
        return null;
    }

    // ── Kreator wniosku: 1. co dostajesz i za ile → 2. dane do faktury → 3. podsumowanie i zgoda (kod SMS) ──

    public function wizard(string $product): string
    {
        $me = $this->me();
        $p = ClaudeBilling::product($product);
        $el = LicenceRequest::eligibility($me['cid'], $p);
        if (!$el['ok']) { $this->flash('err', $el['reason'], $p); exit; }
        $step = max(1, min(3, (int)($_GET['krok'] ?? 1)));
        $w = $_SESSION['lk_wiz'][$p] ?? [];
        if ($step > 1 && empty($w['step1'])) $step = 1;
        if ($step > 2 && empty($w['buyer'])) $step = 2;
        $err = $_SESSION['lk_wiz_err'] ?? []; unset($_SESSION['lk_wiz_err']);
        return Router::view(self::VIEW . 'wizard.php', ['me' => $me, 'p' => $p, 'P' => ClaudeBilling::P($p), 's' => ClaudeBilling::settings($p), 'step' => $step, 'el' => $el,
            'buyer' => ($w['buyer'] ?? []) + ClaudeBilling::buyerDefaults($me['cid']), 'accept' => !empty($w['accept']), 'err' => $err, 'rate' => ClaudeBilling::lastRate($p),
            'projection' => ClaudeBilling::projection(null, $p), 'eduProposal' => !empty($el['needEdu']) ? ClaudeBilling::eduProposal($me['cid']) : '',
            'eduReq' => ClaudeBilling::eduRequest($me['cid']), 'org' => self::org(), 'flash' => $this->takeFlash()]);
    }

    public function wizardPost(string $product): ?string
    {
        $me = $this->me();
        $p = ClaudeBilling::product($product);
        $L = ClaudeBilling::P($p)['label'];
        $go = fn(int $k) => header('Location: ' . route('lk.wizard', ['product' => $p]) . '&krok=' . $k);
        $step = (int)($_POST['krok'] ?? 1);
        if (($_POST['dir'] ?? '') === 'back') { $go(max(1, $step - 1)); return null; }
        if ($step === 1) { $_SESSION['lk_wiz'][$p]['step1'] = 1; $go(2); return null; }
        if ($step === 2) {
            [$buyer, $err] = ClaudeBilling::buyerFromInput($_POST);
            $_SESSION['lk_wiz'][$p]['buyer'] = $buyer;
            if ($err) { $_SESSION['lk_wiz_err'] = $err; $go(2); return null; }
            $go(3); return null;
        }
        $w = $_SESSION['lk_wiz'][$p] ?? [];
        if (empty($w['buyer'])) { $go(2); return null; }
        if (empty($_POST['accept'])) { $_SESSION['lk_wiz_err'] = ['accept' => 'Zaznacz, że akceptujesz zasady opłat.']; $go(3); return null; }
        $_SESSION['lk_wiz'][$p]['accept'] = 1;
        $el = LicenceRequest::eligibility($me['cid'], $p);
        if (!$el['ok']) return $this->flash('err', $el['reason'], $p);
        if (!$this->confirmed('wizardPost', [$p], 'wniosek o ' . $L, $p)) return null;
        try {
            $sub = LicenceRequest::submit($me['cid'], $p, $w['buyer'], ['name' => $me['name'], 'via' => 'panel licencji (kreator)', 'ip' => $me['ip']]);
            unset($_SESSION['lk_wiz'][$p]);
            $this->log($me, $p . '_request', 'Wniosek o ' . $L . ' (#' . (int)$sub['id'] . ', zgłoszenie ' . $sub['helpdesk_number'] . ').');
            $ch = $sub['charge'];
            if (LicenceCard::mode($sub) === 'card') {   // płatność kartą: zaraz po wniosku zgoda i podpięcie karty (albo obciążenie już podpiętej)
                if (LicenceCard::active($me['cid'])) {
                    $x = $ch ? LicenceCard::chargeNow((int)$ch['id'], $me['name'] . ' (panel licencji)') : ['text' => ''];
                    return $this->flash('ok', 'Wniosek o ' . $L . ' złożony. ' . $x['text'] . ' Faktura VAT przyjdzie po wpłacie, potem Helpdesk włączy dostęp.', $p);
                }
                $_SESSION['lk_flash'] = ['ok', 'Wniosek o ' . $L . ' złożony. Teraz podepnij kartę: przeczytaj zgodę i przejdź do płatności.'];
                header('Location: ' . route('lk.card', ['product' => $p]));
                return null;
            }
            return $this->flash('ok', 'Wniosek o ' . $L . ' złożony' . ($sub['helpdesk_number'] ? ' (zgłoszenie ' . $sub['helpdesk_number'] . ')' : '') . '.'
                . ($ch ? ' Proforma ' . ($ch['proforma_number'] ?: '') . ': ' . ClaudeBilling::zl((float)$ch['amount_pln']) . ', zapłać do ' . ClaudeBilling::dayMonth($ch['due_on']) . '.' : '')
                . ' Po wpłacie Helpdesk wystawi fakturę VAT i włączy dostęp.', $p);
        } catch (DomainException | InvalidArgumentException $e) {
            return $this->flash('err', $e->getMessage(), $p);
        } catch (\Throwable $e) {
            error_log('lk wniosek: ' . $e);
            return $this->flash('err', 'Nie udało się złożyć wniosku. Spróbuj za chwilę albo napisz do biura.', $p);
        }
    }

    /** Prośba o konto @edu.feer.org.pl (wymagane do Claude). */
    /** Claude — drugi sposób: weryfikacja Claude for Nonprofit w Goodstack (warunek: konto Office EDU). Zapis w dzienniku i zgłoszenie dla FEER (raz). */
    public function goodstack(): ?string
    {
        $me = $this->me();
        $s = ClaudeBilling::settings('claude');
        if (!LicenceOffice::goodstackOn(ClaudeBilling::P('claude'), $s)) return $this->flash('err', 'Weryfikacja jest chwilowo niedostępna. Napisz do biura.', 'claude');   // brak linku albo poczta EDU wyłączona
        $edu = ClaudeBilling::eduAccount($me['cid']);
        if (!$edu) return $this->flash('err', 'Najpierw załóż konto Office (@' . ClaudeBilling::EDU_DOMAIN . '). Bez niego weryfikacja się nie uda.', 'office');
        $seen = db_one("SELECT 1 FROM claude_events WHERE kind='goodstack_start' AND text LIKE ?", ['%#' . $me['cid'] . ')%']);
        ClaudeBilling::event(null, null, 'goodstack_start', 'Weryfikacja Claude for Nonprofit (Goodstack): ' . $me['name'] . ', konto Office ' . $edu . ' (uczestnik #' . $me['cid'] . ').', $me['name']);
        $this->log($me, 'claude_goodstack', 'Przejście do weryfikacji Claude for Nonprofit (Goodstack).');
        if (!$seen && !ClaudeBilling::isVirtual($me['cid'])) {   // FEER potwierdza dostęp w Goodstack — zgłoszenie z danymi do potwierdzenia
            try {
                require_once dirname(__DIR__, 3) . '/includes/helpdesk.php';
                $c = ClaudeBilling::client($me['cid']) ?? ['name' => $me['name'], 'email' => ''];
                $desc = 'Uczestnik przechodzi weryfikację Claude for Nonprofit w Goodstack (drugi sposób dostępu do Claude, bez płatnej licencji FEER).'
                    . "
Osoba: " . $c['name'] . ' (uczestnik nr ' . $me['cid'] . ')' . "
Konto Office EDU: " . $edu . ($c['email'] ? "
E-mail: " . $c['email'] : '')
                    . "
Następny krok: potwierdź dostęp tej osoby w panelu Goodstack (FEER aprowizuje), potem zamknij zgłoszenie.";
                hd_ticket_quick_create(['id' => null, 'name' => (string)$c['name'], 'email' => (string)$c['email']], 'Claude for Nonprofit — weryfikacja Goodstack: ' . $c['name'], $desc, 'it_licencje_am', 'normalny', 'license_goodstack');
            } catch (\Throwable $e) { error_log('lk goodstack: ' . $e->getMessage()); }
        }
        header('Location: ' . $s['goodstack_url']);
        return null;
    }

    // ── Dysk Online (pilotaż, realizuje sdata.net.pl) ─────────────────────────

    public function drive(): string { return $this->render('dysk'); }

    /** Poczta @stud.feer.org.pl (sdata.net.pl): login, link do webmaila; hasło po kodzie jednorazowym. */
    public function mail(): string
    {
        $pass = $_SESSION['lk_stud_pass'] ?? null; unset($_SESSION['lk_stud_pass']);   // hasło pokazujemy raz, zaraz po potwierdzeniu kodem
        PocztaStud::noStoreHeaders();   // strona może pokazać hasło startowe — bez zapisu w pamięci podręcznej
        return $this->render('poczta', ['studPass' => PocztaStud::sessionTake($pass)]);   // w sesji leży zaszyfrowane, najwyżej 5 minut
    }

    public function mailReveal(): ?string
    {
        $me = $this->me();
        $acc = PocztaStud::get($me['cid']);
        if (!$acc) return $this->flash('err', 'Nie masz jeszcze poczty @' . PocztaStud::DOMAIN . '. Napisz do biura.', 'poczta');
        if (!$acc['hasPassword']) return $this->flash('info', !empty($acc['password_cleared_at'])
            ? 'Hasła startowego nie ma już w panelu. Jeśli go nie znasz, napisz do biura.'
            : 'Hasła do poczty jeszcze nie wpisaliśmy. Przekaże je biuro.', 'poczta');
        if (!$acc['canReveal']) return $this->flash('err', $acc['blockReason'], 'poczta');   // limit pokazań — bez wysyłania kodu
        if (!$this->confirmed('mailReveal', [], 'pokazanie hasla do poczty', 'poczta')) return null;
        $p = PocztaStud::revealPassword($me['cid'], 'Panel Licencji');
        if ($p === '') return $this->flash('err', 'Nie udało się odczytać hasła. Napisz do biura.', 'poczta');
        $_SESSION['lk_stud_pass'] = PocztaStud::sessionPut($p);   // w sesji tylko zaszyfrowane, do jednego wyświetlenia
        header('Location: ' . route('lk.mail'));
        return null;
    }

    /** Uczestnik ustawił własne hasło do poczty — hasło startowe znika z bazy (podgląd administratora blokuje lp.readonly). */
    public function mailDone(): ?string
    {
        $me = $this->me();
        return $this->flash('ok', PocztaStud::confirmChanged($me['cid'], 'Panel Licencji'), 'poczta');
    }

    /** Ostatnie zgłoszenie do pilotażu Dysku Online (60 dni): pakiet, data, numer zgłoszenia — po zgłoszeniu wybór pakietu jest zablokowany. */
    private static function driveRequest(int $cid): ?array
    {
        $e = db_one("SELECT text, created_at FROM claude_events WHERE kind='drive_join' AND text LIKE ? AND created_at >= datetime('now','-60 day') ORDER BY id DESC LIMIT 1", ['%#' . $cid . ')%']);
        if (!$e) return null;
        $plan = preg_match('/pakiet (.+?):/u', (string)$e['text'], $m) ? trim($m[1]) : '';
        $key = '';
        require_once __DIR__ . '/LicencjeDysk.php';
        foreach (LicencjeDysk::PLANS as $k => $p) if ($p['name'] === $plan) $key = $k;
        return ['plan' => $plan, 'key' => $key, 'at' => (string)$e['created_at'], 'ticket' => preg_match('/zgłoszenie (\S+?)\.$/u', (string)$e['text'], $m2) ? $m2[1] : ''];
    }

    /** Zgłoszenie udziału w pilotażu Dysku Online: kod SMS, zgłoszenie Helpdesk dla FEER (raz na 60 dni). Aktywną usługę biuro wpisuje w Usługach i produktach (dostawca sdata.net.pl). */
    public function driveJoin(): ?string
    {
        $me = $this->me();
        require_once __DIR__ . '/LicencjeDysk.php';
        $plans = LicencjeDysk::plans(true);
        $pk = (string)($_POST['plan'] ?? '');
        if (!isset($plans[$pk])) return $this->flash('err', 'Wybierz pakiet dysku.', 'dysk');
        $pl = $plans[$pk];
        $dup = db_one("SELECT 1 FROM claude_events WHERE kind='drive_join' AND text LIKE ? AND created_at >= datetime('now','-60 day')", ['%#' . $me['cid'] . ')%']);
        if ($dup) return $this->flash('info', 'Twoje zgłoszenie do pilotażu Dysku Online już do nas dotarło. Odezwiemy się.', 'dysk');
        if (!$this->confirmed('driveJoin', [], 'zgłoszenie do pilotażu Dysku Online (' . $pl['name'] . ', ' . LicencjeDysk::zl($pl['price']) . ' miesięcznie)', 'dysk')) return null;
        $num = '';
        if (!ClaudeBilling::isVirtual($me['cid'])) {
            try {
                require_once dirname(__DIR__, 3) . '/includes/helpdesk.php';
                $c = ClaudeBilling::client($me['cid']) ?? ['name' => $me['name'], 'email' => '', 'phone' => ''];
                $desc = 'Uczestnik zgłasza chęć udziału w pilotażu Dysku Online (usługę realizuje sdata.net.pl).' . "\nPakiet: " . $pl['name'] . ' (' . $pl['size'] . ', ' . LicencjeDysk::zl($pl['price']) . ' miesięcznie)'
                    . "\nZamówienie u dostawcy: " . $pl['order'] . "\nOsoba: " . $c['name'] . ' (uczestnik nr ' . $me['cid'] . ')'
                    . ($c['email'] ? "\nE-mail: " . $c['email'] : '') . ($c['phone'] ? "\nTelefon: " . $c['phone'] : '')
                    . "\nNastępny krok: załóż dysk w sdata.net.pl, przekaż dane logowania i wpisz usługę uczestnikowi w Ewidencji › Usługi i produkty (dostawca sdata.net.pl, nazwa „Dysk Online”).";
                $tid = hd_ticket_quick_create(['id' => null, 'name' => (string)$c['name'], 'email' => (string)$c['email']], 'Dysk Online ' . $pl['name'] . ' (pilotaż): ' . $c['name'], $desc, 'it_licencje_am', 'normalny', 'license_drive');
                $num = (string)(db_one("SELECT number FROM helpdesk_tickets WHERE id=?", [$tid])['number'] ?? '');
            } catch (\Throwable $e) { error_log('lk drive: ' . $e->getMessage()); }
        }
        ClaudeBilling::event(null, null, 'drive_join', 'Zgłoszenie do pilotażu Dysku Online, pakiet ' . $pl['name'] . ': ' . $me['name'] . ' (uczestnik #' . $me['cid'] . ')' . ($num !== '' ? ', zgłoszenie ' . $num : '') . '.', $me['name']);
        $this->log($me, 'drive_join', 'Zgłoszenie do pilotażu Dysku Online.');
        return $this->flash('ok', 'Zgłoszenie wysłane: ' . $pl['name'] . ($num !== '' ? ' (nr ' . $num . ')' : '') . '. Założymy dysk i prześlemy dane do logowania.', 'dysk');
    }

    /** Wniosek o aktywację planu Office A3 / A5, gdy plan nie ma jeszcze ceny (kreator działa dopiero po ustawieniu ceny): zgłoszenie do biura. */
    public function officePlan(): ?string
    {
        $me = $this->me();
        $plan = strtoupper((string)($_POST['plan'] ?? ''));
        $pl = LicenceOffice::PLANS[$plan] ?? null;
        if (!$pl || !$pl['product']) return $this->flash('err', 'Nie ma takiego planu.', 'office');
        $v = ClaudeBilling::participantView($me['cid'], $pl['product']);
        if ($v['settings']['enabled'] && $v['settings']['priced']) { header('Location: ' . route('lk.wizard', ['product' => $pl['product']])); return null; }
        $dup = db_one("SELECT 1 FROM claude_events WHERE kind='office_plan_request' AND text LIKE ? AND created_at >= datetime('now','-30 day')", ['%#' . $me['cid'] . ' ' . $plan . '%']);
        if ($dup) return $this->flash('info', 'Wniosek o ' . $pl['name'] . ' już do nas dotarł. Odezwiemy się z ceną.', 'office');
        if (!$this->confirmed('officePlan', [], 'wniosek o aktywację ' . $pl['name'], 'office')) return null;
        $num = '';
        if (!ClaudeBilling::isVirtual($me['cid'])) {
            try {
                require_once dirname(__DIR__, 3) . '/includes/helpdesk.php';
                $c = ClaudeBilling::client($me['cid']) ?? ['name' => $me['name'], 'email' => ''];
                $desc = 'Uczestnik prosi o aktywację planu ' . $pl['name'] . ' (Office ze zniżką edukacyjną).' . "
Osoba: " . $c['name'] . ' (uczestnik nr ' . $me['cid'] . ')'
                    . ($c['email'] ? "
E-mail: " . $c['email'] : '') . "
Konto Office: " . (ClaudeBilling::eduAccount($me['cid']) ?: 'brak')
                    . "
Następny krok: ustaw cenę planu w Panelu Licencji (Płatne licencje › Office " . $plan . ' › Ustawienia) i daj znać uczestnikowi — wtedy złoży wniosek w kreatorze.';
                $tid = hd_ticket_quick_create(['id' => null, 'name' => (string)$c['name'], 'email' => (string)$c['email']], 'Wniosek o aktywację ' . $pl['name'] . ': ' . $c['name'], $desc, 'it_licencje_am', 'normalny', 'license_office_plan');
                $num = (string)(db_one("SELECT number FROM helpdesk_tickets WHERE id=?", [$tid])['number'] ?? '');
            } catch (\Throwable $e) { error_log('lk office plan: ' . $e->getMessage()); }
        }
        ClaudeBilling::event(null, null, 'office_plan_request', 'Wniosek o aktywację ' . $pl['name'] . ' (uczestnik #' . $me['cid'] . ' ' . $plan . ')' . ($num !== '' ? ', zgłoszenie ' . $num : '') . '.', $me['name']);
        $this->log($me, 'office_plan_request', 'Wniosek o aktywację ' . $pl['name'] . '.');
        return $this->flash('ok', 'Wniosek o ' . $pl['name'] . ' wysłany' . ($num !== '' ? ' (nr ' . $num . ')' : '') . '. Odezwiemy się z ceną, potem włączymy plan.', 'office');
    }

    /** Konto Office (inicjał.nazwisko@edu): sprawdzenie w tenancie EDU, a gdy go nie ma, założenie (login e-mailem, hasło SMS-em). Potwierdzane kodem. */
    public function edu(): ?string
    {
        $me = $this->me();
        $back = in_array((string)($_POST['back'] ?? ''), ['office', 'claude'], true) ? (string)$_POST['back'] : 'office';
        require_once dirname(__DIR__, 2) . '/claude_billing/logic/LicenceOffice.php';
        if ($acc = ClaudeBilling::eduAccount($me['cid'])) return $this->flash('info', 'Masz już konto Office: ' . $acc . '.', $back);
        if (!$this->confirmed('edu', [], 'założenie konta Office', $back)) return null;
        try {
            $r = LicenceOffice::ensure($me['cid'], ['name' => $me['name'] . ' (panel licencji)', 'via' => 'panel licencji']);
            $this->log($me, 'office_account', 'Konto Office ' . $r['upn'] . ': ' . $r['text']);
            if (empty($r['ok'])) return $this->flash('err', $r['text'] . ' Napisz do biura, jeśli to się powtarza.', $back);
            if ($r['state'] === 'sim') return $this->flash('ok', 'Konto testowe: symulacja. Konto ' . $r['upn'] . ' widać w panelu, ale nie powstało w Microsoft 365 i nic nie wysłaliśmy.', $back);
            return $this->flash('ok', !empty($r['created']) ? 'Gotowe. Konto ' . $r['upn'] . ' założone. Login wysłaliśmy e-mailem, hasło tymczasowe SMS-em.' : 'Masz już konto ' . $r['upn'] . ' w Microsoft 365. Połączyliśmy je z panelem.', $back);
        } catch (\Throwable $e) {
            return $this->flash('err', $e->getMessage(), $back);
        }
    }

    public function buyer(string $product): ?string
    {
        $me = $this->me();
        $p = ClaudeBilling::product($product);
        $back = ($_POST['back'] ?? '') === 'rozliczenia' ? 'rozliczenia' : $p;   // formularz także na stronie „Rozliczenia”
        $sub = ClaudeBilling::forClient($me['cid'], false, $p);
        if (!$sub || !in_array($sub['status'], ['requested', 'active', 'suspended'], true)) return $this->flash('err', 'Nie masz włączonego ' . ClaudeBilling::P($p)['label'] . '.', $back);
        [, $pre] = ClaudeBilling::buyerFromInput($_POST);
        if ($pre) { $_SESSION['lk_errors'] = [$p => $pre]; $_SESSION['lk_old'] = [$p => $_POST]; return $this->flash('err', 'Popraw dane do faktury (' . count($pre) . ').', $back); }
        if (!$this->confirmed('buyer', [$p], 'zmiana danych do faktury', $back)) return null;
        $err = ClaudeBilling::saveBuyer((int)$sub['id'], $_POST, $me['name'] . ' (panel licencji)');
        if ($err) { $_SESSION['lk_errors'] = [$p => $err]; $_SESSION['lk_old'] = [$p => $_POST]; return $this->flash('err', 'Popraw dane do faktury (' . count($err) . ').', $back); }
        return $this->flash('ok', 'Dane do faktury zapisane. Jeśli dokument czekał na te dane, już się wystawił.', $back);
    }

    public function cancel(string $product): ?string
    {
        $me = $this->me();
        $p = ClaudeBilling::product($product);
        $L = ClaudeBilling::P($p)['label'];
        if (empty($_POST['confirm'])) return $this->flash('err', 'Zaznacz, że rezygnujesz.', $p);
        $sub = ClaudeBilling::forClient($me['cid'], false, $p);
        if (!$sub || !in_array($sub['status'], ['requested', 'active', 'suspended'], true)) return $this->flash('err', 'Nie masz włączonego ' . $L . '.', $p);
        $req = $sub['status'] === 'requested';
        if (!$this->confirmed('cancel', [$p], $req ? 'wycofanie wniosku o ' . $L : 'rezygnacja z ' . $L, $p)) return null;
        if ($req) {
            try { $msg = LicenceRequest::withdraw((int)$sub['id'], ['name' => $me['name'], 'via' => 'panel licencji']); $this->log($me, $p . '_withdraw', 'Wycofano wniosek o ' . $L . '.'); return $this->flash('ok', $msg, $p); }
            catch (\Throwable $e) { return $this->flash('err', $e->getMessage(), $p); }
        }
        try {
            ClaudeBilling::end((int)$sub['id'], 'rezygnacja w panelu licencji', ['name' => $me['name'], 'via' => 'panel licencji']);
            $this->log($me, $p . '_cancel', 'Rezygnacja z ' . $L . ' (#' . (int)$sub['id'] . ').');
            return $this->flash('ok', 'Zrezygnowałeś/aś z ' . $L . '. Kolejnych faktur nie będzie.', $p);
        } catch (\Throwable $e) {
            return $this->flash('err', $e->getMessage(), $p);
        }
    }

    /** Przelewy24 bezpośrednio za opłatę. */
    public function pay(string $id): ?string
    {
        $me = $this->me();
        $ch = ClaudeBilling::charge((int)$id);
        if (!$ch || (int)$ch['client_id'] !== $me['cid'] || (int)$ch['is_test']) { http_response_code(404); return 'Nie znaleziono opłaty.'; }
        try {
            header('Location: ' . ClaudeBilling::startP24((int)$id, $me['email'], route('lk.pay.return', ['id' => (int)$id])));
            return null;
        } catch (\Throwable $e) {
            return $this->flash('err', $e->getMessage(), ClaudeBilling::productOf($ch));
        }
    }

    /** Powrót z Przelewy24 — sprawdzamy status w bramce (powrót często wyprzedza powiadomienie). */
    public function payReturn(string $id): ?string
    {
        $me = $this->me();
        $ch = ClaudeBilling::charge((int)$id);
        if (!$ch || (int)$ch['client_id'] !== $me['cid']) { http_response_code(404); return 'Nie znaleziono opłaty.'; }
        require_once dirname(__DIR__, 3) . '/includes/p24.php';
        if ($ch['status'] === 'pending' && ($p24 = p24_payment_for('claude_charge', (int)$id))) { p24_reconcile_payment((int)$p24['id']); $ch = ClaudeBilling::charge((int)$id); }
        $p = ClaudeBilling::productOf($ch);
        $L = ClaudeBilling::P($p)['label'];
        if ($ch['status'] === 'paid') return $this->flash('ok', 'Wpłata ' . ClaudeBilling::zl((float)$ch['amount_pln']) . ' za ' . $L . ' dotarła. Faktura wystawi się automatycznie.', $p);
        return $this->flash('info', 'Przelewy24 jeszcze nie potwierdziły płatności. Odśwież stronę za minutę. Jeśli płatność się nie udała, zapłać ponownie.', $p);
    }

    /** Zmiana hasła do panelu licencji. */
    public function password(): ?string
    {
        $me = $this->me();
        $p1 = (string)($_POST['new'] ?? ''); $p2 = (string)($_POST['new2'] ?? '');
        if (!LicenseLogin::get($me['cid'])) return $this->flash('err', 'Nie masz loginu do panelu licencji. Zakłada go biuro.', 'konto');
        if (mb_strlen($p1) < 10) return $this->flash('err', 'Hasło musi mieć co najmniej 10 znaków.', 'konto');
        if ($p1 !== $p2) return $this->flash('err', 'Hasła się różnią. Wpisz dwa razy to samo.', 'konto');
        if (!$this->confirmed('password', [], 'zmiana hasła do panelu licencji', 'konto')) return null;
        LicenseLogin::setPassword($me['cid'], $p1);
        $this->log($me, 'license_panel_password', 'Zmiana hasła do panelu licencji.');
        return $this->flash('ok', 'Nowe hasło zapisane.', 'konto');
    }
}
