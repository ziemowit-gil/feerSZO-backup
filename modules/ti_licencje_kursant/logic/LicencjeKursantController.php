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
require_once dirname(__DIR__, 2) . '/claude_billing/logic/PriceChange.php';

final class LicencjeKursantController
{
    private const VIEW = __DIR__ . '/../view/';
    public const OP_NAMES = ['AM11' => 'Licencja opłacana przez Fundację', 'AM12' => 'Zwrot kosztów licencji', 'AM13' => 'Licencja opłacana przez kogoś innego',
        'AM20' => 'Zmiana płatnika licencji', 'AM21' => 'Zmiana planu licencji', 'AM22' => 'Zawieszenie licencji', 'AM23' => 'Odebranie licencji',
        'AM24' => 'Przedłużenie licencji', 'ANT1' => 'Wniosek o dostęp do Claude'];
    public const OP_STATUS = ['signing' => 'czeka na Twoje potwierdzenie — link przyszedł e-mailem i SMS-em', 'pending' => 'czeka na decyzję',
        'approved' => 'zatwierdzone — Dział IT to teraz wykonuje', 'executed' => 'gotowe', 'rejected' => 'odmowa', 'cancelled' => 'wycofane'];
    public const AM7_STATUS = ['draft' => 'wniosek w przygotowaniu', 'started' => 'wniosek złożony — czekamy na decyzję', 'granted' => 'przyznana',
        'refused' => 'odmowa', 'withdrawn' => 'wniosek wycofany'];
    public const AM5_STATUS = ['invited' => 'czekamy na Twoje oświadczenie — link przyszedł e-mailem', 'submitted' => 'oświadczenie wysłane — sprawdzamy je',
        'accepted' => 'masz dostęp', 'rejected' => 'oświadczenie do poprawy — sprawdź e-mail', 'revoked' => 'dostęp cofnięty'];

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
                'via' => (string)$u['via'], 'fromTi' => !empty($u['sid'])];
    }

    private function log(array $me, string $action, string $text): void
    {
        if ($me['sid'] > 0 && function_exists('ti_account_log')) ti_account_log($me['sid'], $action, $text);
    }

    private function flash(string $type, string $msg, string $anchor = ''): ?string
    {
        $_SESSION['lk_flash'] = [$type, $msg];
        header('Location: ' . route('lk.index') . ($anchor !== '' ? '#' . $anchor : ''));
        return null;
    }

    // ── Potwierdzanie operacji kodem ────────────────────────────────────────────

    /** Operacje, które można wykonać po kodzie (whitelista metod kontrolera). */
    private const CONFIRMABLE = ['activate', 'edu', 'buyer', 'cancel', 'password', 'priceDecide'];

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
        if (!$op || (int)$op['cid'] !== $me['cid'] || !in_array($op['method'], self::CONFIRMABLE, true)) return $this->flash('err', 'Nie ma operacji do potwierdzenia — zacznij jeszcze raz.');
        if (time() - (int)$op['at'] > LicencjeKod::TTL) { unset($_SESSION['lk_op']); return $this->flash('err', 'Minęło ponad 10 minut — zacznij operację jeszcze raz.', $op['anchor']); }
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
        if (!$op) return $this->flash('err', 'Nie ma operacji do potwierdzenia.');
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
        return $this->flash('info', 'Anulowano — nic nie zmieniliśmy.', (string)($op['anchor'] ?? ''));
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
        if (!LicencjeKod::validPhone($phone)) $err['phone'] = 'Wpisz numer komórki — 9 cyfr, np. 600 100 200 (numer zagraniczny: z +).';
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
        return $this->flash('ok', $first ? 'Dziękujemy — dane zapisane. Możesz już zarządzać licencjami. Każdą zmianę potwierdzisz kodem z SMS-a.' : 'Zapisaliśmy nowe dane kontaktowe.', $first ? '' : 'konto');
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
        if (!$this->confirmed('priceDecide', [(string)(int)$id], ($d === 'accept' ? 'akceptacja' : 'rezygnacja') . ' — nowa cena ' . ClaudeBilling::P($p)['label'], $p)) return null;
        PriceChange::decideAnswer($a, $d, $me['name'], $me['ip'], 'panel licencji');
        $this->log($me, 'license_price_answer', 'Zmiana ceny ' . ClaudeBilling::P($p)['label'] . ': ' . ($d === 'accept' ? 'akceptacja' : 'rezygnacja') . '.');
        $from = ClaudeBilling::dayMonth((string)$a['change']['effective_from']);
        return $this->flash('ok', $d === 'accept' ? 'Zapisaliśmy: akceptujesz nową cenę od ' . $from . '.' : 'Zapisaliśmy: rezygnujesz. Dostęp zakończy się przed ' . $from . ' — do tego dnia płacisz po starej cenie.', $p);
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
            $_SESSION['lk_flash'] = ['ok', 'Dziękujemy — zapisaliśmy decyzję.'];
            return $back();
        }
        if (($_POST['step'] ?? '') === 'back') { unset($_SESSION['lk_price'][$k], $_SESSION['lk_otp'][$purpose]); return $back(); }
        $d = (string)($_POST['decision'] ?? ''); $name = trim((string)($_POST['name'] ?? ''));
        try { PriceChange::validate($a, $d, $name); } catch (\Throwable $e) { return $back($e->getMessage()); }
        $t = PriceChange::codeTarget($a);
        unset($_SESSION['lk_otp'][$purpose]);
        $r = LicencjeKod::send($purpose, (int)$a['client_id'], $t['phone'], $t['email'], ($d === 'accept' ? 'akceptacja' : 'rezygnacja') . ' — nowa cena');
        if (!$r['ok']) return $back($r['error']);
        $_SESSION['lk_price'][$k] = ['decision' => $d, 'name' => $name];
        return $back();
    }

    // ── Logowanie ───────────────────────────────────────────────────────────────

    public function loginForm(): string
    {
        if (LicencjePanelAuth::user()) { header('Location: ' . route('lk.index')); exit; }
        $err = $_SESSION['lk_login_err'] ?? ''; $old = $_SESSION['lk_login_old'] ?? ''; unset($_SESSION['lk_login_err'], $_SESSION['lk_login_old']);
        return Router::view(self::VIEW . 'login.php', ['err' => $err, 'old' => $old, 'org' => self::org()]);
    }

    public function loginPost(): ?string
    {
        $login = trim((string)($_POST['login'] ?? '')); $pass = (string)($_POST['password'] ?? '');
        $back = function (string $e) use ($login) { $_SESSION['lk_login_err'] = $e; $_SESSION['lk_login_old'] = $login; header('Location: ' . route('lk.login')); return null; };
        if ($login === '' || $pass === '') return $back('Wpisz login i hasło.');
        if (LicencjePanelAuth::throttled($login)) return $back('Za dużo nieudanych prób — spróbuj ponownie za 15 minut.');
        $cid = LicenseLogin::verify($login, $pass);
        if (!$cid) { LicencjePanelAuth::recordFail($login); return $back('Nieprawidłowy login lub hasło.'); }
        LicencjePanelAuth::login($cid, 'login ' . strtolower($login));
        $sid = (int)(db_one("SELECT id FROM k30_ti_student_accounts WHERE client_id=? ORDER BY id DESC LIMIT 1", [$cid])['id'] ?? 0);
        if ($sid > 0 && function_exists('ti_account_log')) ti_account_log($sid, 'license_panel_login', 'Logowanie do panelu licencji (' . strtolower($login) . ').');
        header('Location: ' . route('lk.index'));
        return null;
    }

    public function logout(): ?string
    {
        LicencjePanelAuth::logout();
        header('Location: ' . route('lk.login'));
        return null;
    }

    // ── Panel ───────────────────────────────────────────────────────────────────

    public function index(): string
    {
        $me = $this->me();
        $cid = $me['cid'];
        $paid = [];
        foreach (array_keys(ClaudeBilling::PRODUCTS) as $p) {
            try {
                $v = ClaudeBilling::participantView($cid, $p);
                $v['fromPool'] = false;
                if (!$v['hasLicense'] && !$v['sub']) continue;   // nie dotyczy tej osoby (NordVPN: tylko gdy licencja jest przypisana do uczestnika)
                $v['edu'] = !empty(ClaudeBilling::P($p)['requiresEdu']) ? ['account' => ClaudeBilling::eduAccount($cid), 'request' => ClaudeBilling::eduRequest($cid)] : null;
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
        $flash = $_SESSION['lk_flash'] ?? null; $err = $_SESSION['lk_errors'] ?? []; $old = $_SESSION['lk_old'] ?? [];
        unset($_SESSION['lk_flash'], $_SESSION['lk_errors'], $_SESSION['lk_old']);
        return Router::view(self::VIEW . 'index.php', ['me' => $me, 'paid' => $paid, 'software' => $software, 'termOk' => $termOk, 'office' => $office, 'am5' => $am5,
            'ops' => $ops, 'products' => $products, 'prodMonthly' => round($prodMonthly, 2), 'feerUpn' => $feerUpn, 'p24on' => p24_enabled(),
            'login' => LicenseLogin::get($cid), 'hasPassword' => LicenseLogin::hasPassword($cid), 'minor' => ClaudeBilling::isMinor($cid),
            'contact' => LicencjeKod::contact($cid), 'eduAccount' => ClaudeBilling::eduAccount($cid),
            'flash' => $flash, 'err' => $err, 'old' => $old, 'org' => self::org()]);
    }

    /** Dawny adres zakładki — produkty są teraz sekcją jednego panelu. */
    public function products(): ?string
    {
        header('Location: ' . route('lk.index') . '#produkty');
        return null;
    }

    public function activate(string $product): ?string
    {
        $me = $this->me();
        $p = ClaudeBilling::product($product);
        $L = ClaudeBilling::P($p)['label'];
        [$buyer, $err] = ClaudeBilling::buyerFromInput($_POST);
        if (empty($_POST['accept'])) $err['accept'] = 'Zaznacz, że akceptujesz zasady opłat.';
        if ($err) {
            $_SESSION['lk_errors'] = [$p => $err]; $_SESSION['lk_old'] = [$p => $buyer + ['accept' => !empty($_POST['accept'])]];
            return $this->flash('err', 'Popraw zaznaczone pola (' . count($err) . ').', $p);
        }
        $el = ClaudeBilling::eligibility($me['cid'], $p);
        if (!$el['ok']) return $this->flash('err', $el['reason'], $p);
        if (!$this->confirmed('activate', [$p], 'włączenie ' . $L, $p)) return null;
        try {
            $sub = ClaudeBilling::activate($me['cid'], ['name' => $me['name'], 'via' => 'panel licencji', 'ip' => $me['ip']], false, null, true, null, $buyer, $p);
            $this->log($me, $p . '_activate', 'Włączono ' . $L . ' (#' . (int)$sub['id'] . ').');
            $first = db_one("SELECT amount_pln, due_on FROM claude_charges WHERE subscription_id=? ORDER BY id LIMIT 1", [(int)$sub['id']]);
            return $this->flash('ok', $L . ' włączony. Dział IT dostał zgłoszenie' . ($sub['helpdesk_number'] ? ' ' . $sub['helpdesk_number'] : '') . ' i uruchomi Twoje konto — zwykle w ciągu jednego dnia roboczego.'
                . ($first ? ' Pierwsza płatność: ' . ClaudeBilling::zl((float)$first['amount_pln']) . ', do ' . ClaudeBilling::dayMonth($first['due_on']) . '.' : ''), $p);
        } catch (DomainException | InvalidArgumentException $e) {
            return $this->flash('err', $e->getMessage(), $p);
        } catch (\Throwable $e) {
            error_log('lk activate: ' . $e);
            return $this->flash('err', 'Nie udało się włączyć ' . $L . '. Spróbuj za chwilę albo napisz do biura.', $p);
        }
    }

    /** Prośba o konto @edu.feer.org.pl (wymagane do Claude). */
    public function edu(): ?string
    {
        $me = $this->me();
        if (ClaudeBilling::eduRequest($me['cid'])) return $this->flash('info', 'Prośba o konto @' . ClaudeBilling::EDU_DOMAIN . ' już jest u Działu IT.', 'claude');
        if (!$this->confirmed('edu', [], 'prośba o konto @' . ClaudeBilling::EDU_DOMAIN, 'claude')) return null;
        try {
            $r = ClaudeBilling::requestEdu($me['cid'], ['name' => $me['name'], 'via' => 'panel licencji']);
            $this->log($me, 'edu_request', 'Wniosek o konto @' . ClaudeBilling::EDU_DOMAIN . ': ' . ($r['proposed'] ?? '') . '.');
            return $this->flash('ok', 'Poprosiliśmy Dział IT o konto ' . ($r['proposed'] ?? '') . (!empty($r['helpdesk_number']) ? ' (zgłoszenie ' . $r['helpdesk_number'] . ')' : '') . '. Gdy będzie gotowe, włączysz Claude tutaj.', 'claude');
        } catch (\Throwable $e) {
            return $this->flash('err', $e->getMessage(), 'claude');
        }
    }

    public function buyer(string $product): ?string
    {
        $me = $this->me();
        $p = ClaudeBilling::product($product);
        $sub = ClaudeBilling::forClient($me['cid'], false, $p);
        if (!$sub || !in_array($sub['status'], ['active', 'suspended'], true)) return $this->flash('err', 'Nie masz włączonego ' . ClaudeBilling::P($p)['label'] . '.', $p);
        [, $pre] = ClaudeBilling::buyerFromInput($_POST);
        if ($pre) { $_SESSION['lk_errors'] = [$p => $pre]; $_SESSION['lk_old'] = [$p => $_POST]; return $this->flash('err', 'Popraw dane do faktury (' . count($pre) . ').', $p); }
        if (!$this->confirmed('buyer', [$p], 'zmiana danych do faktury', $p)) return null;
        $err = ClaudeBilling::saveBuyer((int)$sub['id'], $_POST, $me['name'] . ' (panel licencji)');
        if ($err) { $_SESSION['lk_errors'] = [$p => $err]; $_SESSION['lk_old'] = [$p => $_POST]; return $this->flash('err', 'Popraw dane do faktury (' . count($err) . ').', $p); }
        return $this->flash('ok', 'Zapisaliśmy dane do faktury. Jeśli jakiś dokument na nie czekał — właśnie się wystawił.', $p);
    }

    public function cancel(string $product): ?string
    {
        $me = $this->me();
        $p = ClaudeBilling::product($product);
        $L = ClaudeBilling::P($p)['label'];
        if (empty($_POST['confirm'])) return $this->flash('err', 'Zaznacz, że na pewno chcesz zrezygnować.', $p);
        $sub = ClaudeBilling::forClient($me['cid'], false, $p);
        if (!$sub || !in_array($sub['status'], ['active', 'suspended'], true)) return $this->flash('err', 'Nie masz włączonego ' . $L . '.', $p);
        if (!$this->confirmed('cancel', [$p], 'rezygnacja z ' . $L, $p)) return null;
        try {
            ClaudeBilling::end((int)$sub['id'], 'rezygnacja w panelu licencji', ['name' => $me['name'], 'via' => 'panel licencji']);
            $this->log($me, $p . '_cancel', 'Rezygnacja z ' . $L . ' (#' . (int)$sub['id'] . ').');
            return $this->flash('ok', 'Zrezygnowałeś/aś z ' . $L . '. Nie dostaniesz kolejnych faktur.', $p);
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
        if ($ch['status'] === 'paid') return $this->flash('ok', 'Dziękujemy — wpłata ' . ClaudeBilling::zl((float)$ch['amount_pln']) . ' za ' . $L . ' dotarła. Fakturę wystawimy automatycznie.', $p);
        return $this->flash('info', 'Czekamy na potwierdzenie płatności z Przelewy24 — zwykle trwa to chwilę. Odśwież stronę za minutę. Jeśli płatność się nie udała, spróbuj ponownie.', $p);
    }

    /** Zmiana hasła do panelu licencji. */
    public function password(): ?string
    {
        $me = $this->me();
        $p1 = (string)($_POST['new'] ?? ''); $p2 = (string)($_POST['new2'] ?? '');
        if (!LicenseLogin::get($me['cid'])) return $this->flash('err', 'Nie masz jeszcze loginu do panelu licencji — zakłada go biuro.', 'konto');
        if (mb_strlen($p1) < 10) return $this->flash('err', 'Hasło musi mieć co najmniej 10 znaków.', 'konto');
        if ($p1 !== $p2) return $this->flash('err', 'Hasła się różnią — wpisz to samo dwa razy.', 'konto');
        if (!$this->confirmed('password', [], 'zmiana hasła do panelu licencji', 'konto')) return null;
        LicenseLogin::setPassword($me['cid'], $p1);
        $this->log($me, 'license_panel_password', 'Zmiana hasła do panelu licencji.');
        return $this->flash('ok', 'Zapisaliśmy nowe hasło do panelu licencji.', 'konto');
    }
}
