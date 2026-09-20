<?php
/**
 * includes/email_templates.php — Silnik konfigurowalnych szablonów maili systemowych.
 *
 * Maile systemowe (powitalny, przypomnienie o wygaśnięciu umowy, ZUS, kod
 * odzyskiwania) miały dotąd treść zaszytą w kodzie. Ten moduł pozwala
 * administratorowi nadpisać temat i treść HTML każdego z nich w wizualnym
 * edytorze (admin/email_templates.php), zachowując bezpieczny fallback do
 * domyślnej treści, jeśli nadpisanie nie istnieje.
 *
 * Składnia podstawień: {{nazwa}}  (spacje wokół nazwy są tolerowane).
 * Wartości podstawiane są surowo — strona wywołująca odpowiada za ew. escaping,
 * dokładnie jak w dotychczasowych heredocach.
 */

require_once __DIR__ . '/db.php';

// ── Helper: domyślne opakowanie HTML maila ───────────────────────────────────
function _email_tpl_default_wrap(string $inner_html, string $preheader = ''): string {
    if (function_exists('_feer_email_tpl')) {
        return _feer_email_tpl($inner_html, $preheader);
    }
    $org  = defined('ORG_NAME') ? htmlspecialchars(ORG_NAME, ENT_QUOTES, 'UTF-8') : '';
    $year = date('Y');
    $pre  = $preheader ? '<div style="display:none;max-height:0;overflow:hidden;font-size:1px;line-height:1px;color:#f1f5f9">' . htmlspecialchars($preheader, ENT_QUOTES, 'UTF-8') . '&nbsp;</div>' : '';
    return '<!DOCTYPE html><html lang="pl"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
         . '<body style="margin:0;padding:0;background:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif">'
         . $pre
         . '<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background:#f1f5f9;padding:28px 16px"><tr><td align="center">'
         . '<table width="100%" cellpadding="0" cellspacing="0" style="max-width:580px">'
         . '<tr><td style="background:#1e293b;padding:14px 32px;border-radius:8px 8px 0 0"><span style="color:#e2e8f0;font-size:13px;font-weight:600;letter-spacing:.03em">' . $org . '</span></td></tr>'
         . '<tr><td style="background:#ffffff;padding:28px 32px;color:#1e293b;font-size:15px;line-height:1.65">' . $inner_html . '</td></tr>'
         . '<tr><td style="background:#f8fafc;padding:14px 32px;border-top:1px solid #e2e8f0;border-radius:0 0 8px 8px;color:#94a3b8;font-size:11px;text-align:center">' . $org . ' &middot; ' . $year . '</td></tr>'
         . '</table></td></tr></table></body></html>';
}

// ── Migracja ────────────────────────────────────────────────────────────────
function email_tpl_migrate(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        db()->exec("CREATE TABLE IF NOT EXISTS email_templates (
            key_       TEXT PRIMARY KEY,
            subject    TEXT NOT NULL DEFAULT '',
            body_html  TEXT NOT NULL DEFAULT '',
            enabled    INTEGER NOT NULL DEFAULT 1,
            updated_at DATETIME,
            updated_by INTEGER
        )");
    } catch (\Throwable $e) {}
}

/**
 * Rejestr znanych maili systemowych.
 * Każdy wpis: label, group, icon, description, subject (domyślny temat),
 * body (domyślna treść HTML z {{placeholderami}}), vars => [name => [label, sample]].
 *
 * KAŻDY szablon w rejestrze jest realnie podpięty w kodzie wysyłki — edycja
 * w panelu zmienia faktycznie wysyłane wiadomości.
 */
function email_tpl_registry(): array
{
    static $reg = null;
    if ($reg !== null) return $reg;

    // — Powitalny / dane logowania ————————————————————————————————
    $welcome_body = <<<'HTML'
<p>Cześć, <strong>{{name}}</strong>!</p>
<p>{{intro}}</p>
<div style="background:#fff8e1;border-left:3px solid #f59e0b;border-radius:4px;padding:10px 14px;margin:14px 0;font-size:.88em">
  Hasło zostało wygenerowane przez administratora. Po zalogowaniu możesz je zmienić w <strong>Mój panel</strong>.
</div>
{{login_block}}
<div style="margin:20px 0;text-align:center">
  <a href="{{login_url}}" style="background:{{accent}};color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;display:inline-block;font-weight:600">
    Zaloguj się →
  </a>
</div>
<p style="font-size:.82em;color:#6c757d;margin-top:20px;padding-top:12px;border-top:1px solid #dee2e6">
  Wiadomość wysłana automatycznie przez system {{org}}.
</p>
HTML;

    // — Przypomnienie o wygaśnięciu umowy ————————————————————————
    $expiry_body = <<<HTML
<p style="margin:0 0 16px;font-size:15px;color:#333333;">
  Dzień dobry{{greeting}},
</p>
<p style="margin:0 0 24px;font-size:15px;color:#333333;">
  {{urgency_text}}
  Prosimy o podjęcie stosownych działań.
</p>
<table width="100%" cellpadding="0" cellspacing="0"
       style="background:#f8f9fa;border-radius:6px;border-left:4px solid {{accent}};
              padding:0;margin-bottom:24px;">
  <tr><td style="padding:20px 24px;">
    <table width="100%" cellpadding="0" cellspacing="0">
      <tr>
        <td style="padding:5px 0;font-size:13px;color:#6c757d;width:160px;">Typ umowy</td>
        <td style="padding:5px 0;font-size:14px;color:#212529;font-weight:600;">{{type_label}}</td>
      </tr>
      <tr>
        <td style="padding:5px 0;font-size:13px;color:#6c757d;">Numer umowy</td>
        <td style="padding:5px 0;font-size:14px;color:#212529;font-weight:600;">{{numer}}</td>
      </tr>
      <tr>
        <td style="padding:5px 0;font-size:13px;color:#6c757d;">Osoba</td>
        <td style="padding:5px 0;font-size:14px;color:#212529;">{{osoba}}</td>
      </tr>
      <tr>
        <td style="padding:5px 0;font-size:13px;color:#6c757d;">Data zakończenia</td>
        <td style="padding:5px 0;font-size:14px;color:{{accent}};font-weight:700;">{{data_zakonczenia}}</td>
      </tr>
      <tr>
        <td style="padding:5px 0;font-size:13px;color:#6c757d;">Pozostało</td>
        <td style="padding:5px 0;font-size:14px;color:{{accent}};font-weight:700;">{{days_left}} {{days_label}}</td>
      </tr>
    </table>
  </td></tr>
</table>
<p style="margin:0 0 8px;font-size:14px;color:#495057;">
  Zaloguj się do systemu, aby sprawdzić szczegóły umowy i podjąć działania
  (przedłużenie, zakończenie lub anulowanie).
</p>
HTML;

    // — Przypomnienie ZUS ———————————————————————————————————————
    $zus_body = <<<HTML
<p style="margin:0 0 24px;font-size:15px;color:#333333;">
  {{pilnosc}} Należy {{akcja}} zleceniobiorcę zgodnie z umową.
</p>
<table width="100%" cellpadding="0" cellspacing="0"
       style="background:#f8f9fa;border-radius:6px;border-left:4px solid {{accent}};
              margin-bottom:24px;">
  <tr><td style="padding:20px 24px;">
    <table width="100%" cellpadding="0" cellspacing="0">
      <tr>
        <td style="padding:5px 0;font-size:13px;color:#6c757d;width:160px;">Umowa zlecenie</td>
        <td style="padding:5px 0;font-size:14px;color:#212529;font-weight:600;">{{numer}}</td>
      </tr>
      <tr>
        <td style="padding:5px 0;font-size:13px;color:#6c757d;">Zleceniobiorca</td>
        <td style="padding:5px 0;font-size:14px;color:#212529;">{{osoba}}</td>
      </tr>
      <tr>
        <td style="padding:5px 0;font-size:13px;color:#6c757d;">Termin (ustawowy 7 dni)</td>
        <td style="padding:5px 0;font-size:14px;color:{{accent}};font-weight:700;">{{deadline}}</td>
      </tr>
    </table>
  </td></tr>
</table>
<p style="margin:0 0 8px;font-size:14px;color:#495057;">
  <a href="{{url}}" style="color:{{accent}};font-weight:600;">Otwórz umowę w systemie</a>,
  dokonaj zgłoszenia w ZUS i odnotuj datę w karcie umowy (sekcja ZUS), aby zakończyć przypomnienia.
</p>
HTML;

    // — Okresowa weryfikacja wolontariusza niepełnoletniego ——————————————
    $minor_verif_body = <<<HTML
<p style="margin:0 0 16px;font-size:15px;color:#333333;">
  Szanowni Państwo,
</p>
<p style="margin:0 0 24px;font-size:15px;color:#333333;">
  W związku z okresową weryfikacją wolontariatu uprzejmie informujemy, że zostanie
  do Pani/Pana nadane pismo w sprawie wolontariusza niepełnoletniego
  <strong>{{osoba}}</strong>.
</p>
<table width="100%" cellpadding="0" cellspacing="0"
       style="background:#f8f9fa;border-radius:6px;border-left:4px solid {{accent}};
              padding:0;margin-bottom:24px;">
  <tr><td style="padding:20px 24px;">
    <table width="100%" cellpadding="0" cellspacing="0">
      <tr>
        <td style="padding:5px 0;font-size:13px;color:#6c757d;width:160px;">Wolontariusz</td>
        <td style="padding:5px 0;font-size:14px;color:#212529;font-weight:600;">{{osoba}}</td>
      </tr>
      <tr>
        <td style="padding:5px 0;font-size:13px;color:#6c757d;">Opiekun</td>
        <td style="padding:5px 0;font-size:14px;color:#212529;">{{opiekun_nazwa}}</td>
      </tr>
      <tr>
        <td style="padding:5px 0;font-size:13px;color:#6c757d;">Numer umowy</td>
        <td style="padding:5px 0;font-size:14px;color:#212529;font-weight:600;">{{numer}}</td>
      </tr>
      <tr>
        <td style="padding:5px 0;font-size:13px;color:#6c757d;">Data weryfikacji</td>
        <td style="padding:5px 0;font-size:14px;color:#212529;">{{data_weryfikacji}}</td>
      </tr>
    </table>
  </td></tr>
</table>
<p style="margin:0 0 8px;font-size:14px;color:#495057;">
  Weryfikacja przeprowadzana jest cyklicznie (co 90 dni) i ma na celu potwierdzenie
  aktualności danych oraz zgody na dalszy udział w wolontariacie. Pismo, o którym mowa
  powyżej, zostanie przekazane odrębnie.
</p>
HTML;

    // — Okresowa weryfikacja wolontariusza niepełnoletniego — powiadomienie admina —
    $minor_verif_admin_body = <<<HTML
<p style="margin:0 0 24px;font-size:15px;color:#333333;">
  Do opiekuna wolontariusza niepełnoletniego <strong>{{osoba}}</strong> wysłano właśnie
  zapowiedź okresowej weryfikacji wolontariatu. Zgodnie z jej treścią, do opiekuna
  powinno zostać nadane <strong>pismo w sprawie weryfikacji</strong> — prosimy o jego
  przygotowanie i wysłanie.
</p>
<table width="100%" cellpadding="0" cellspacing="0"
       style="background:#f8f9fa;border-radius:6px;border-left:4px solid {{accent}};
              padding:0;margin-bottom:24px;">
  <tr><td style="padding:20px 24px;">
    <table width="100%" cellpadding="0" cellspacing="0">
      <tr>
        <td style="padding:5px 0;font-size:13px;color:#6c757d;width:160px;">Wolontariusz</td>
        <td style="padding:5px 0;font-size:14px;color:#212529;font-weight:600;">{{osoba}}</td>
      </tr>
      <tr>
        <td style="padding:5px 0;font-size:13px;color:#6c757d;">Numer umowy</td>
        <td style="padding:5px 0;font-size:14px;color:#212529;font-weight:600;">{{numer}}</td>
      </tr>
      <tr>
        <td style="padding:5px 0;font-size:13px;color:#6c757d;">Opiekun</td>
        <td style="padding:5px 0;font-size:14px;color:#212529;">{{opiekun_nazwa}} &lt;{{opiekun_email}}&gt;</td>
      </tr>
      <tr>
        <td style="padding:5px 0;font-size:13px;color:#6c757d;">Data powiadomienia</td>
        <td style="padding:5px 0;font-size:14px;color:#212529;">{{data_weryfikacji}}</td>
      </tr>
    </table>
  </td></tr>
</table>
<p style="margin:0 0 8px;font-size:14px;color:#495057;">
  <a href="{{url}}" style="color:{{accent}};font-weight:600;">Otwórz umowę w systemie</a>
</p>
HTML;

    // — Potwierdzenie oświadczenia o rezygnacji/rozwiązaniu porozumienia wolontariackiego —
    $termination_confirmation_body = <<<HTML
<p style="margin:0 0 16px;font-size:15px;color:#333333;">
  Dzień dobry, <strong>{{osoba}}</strong>,
</p>
<p style="margin:0 0 24px;font-size:15px;color:#333333;">
  Potwierdzamy przyjęcie oświadczenia dotyczącego rezygnacji / rozwiązania porozumienia
  wolontariackiego z dnia <strong>{{data_oswiadczenia}}</strong>.
</p>
<table width="100%" cellpadding="0" cellspacing="0"
       style="background:#f8f9fa;border-radius:6px;border-left:4px solid {{accent}};
              padding:0;margin-bottom:24px;">
  <tr><td style="padding:20px 24px;">
    <table width="100%" cellpadding="0" cellspacing="0">
      <tr>
        <td style="padding:5px 0;font-size:13px;color:#6c757d;width:170px;">Numer porozumienia</td>
        <td style="padding:5px 0;font-size:14px;color:#212529;font-weight:600;">{{numer}}</td>
      </tr>
      <tr>
        <td style="padding:5px 0;font-size:13px;color:#6c757d;">Powód</td>
        <td style="padding:5px 0;font-size:14px;color:#212529;">{{powod}}</td>
      </tr>
      <tr>
        <td style="padding:5px 0;font-size:13px;color:#6c757d;">Tryb rozwiązania</td>
        <td style="padding:5px 0;font-size:14px;color:#212529;">{{tryb}}</td>
      </tr>
      <tr>
        <td style="padding:5px 0;font-size:13px;color:#6c757d;">Data zakończenia współpracy</td>
        <td style="padding:5px 0;font-size:14px;color:{{accent}};font-weight:700;">{{data_zakonczenia}}</td>
      </tr>
    </table>
  </td></tr>
</table>
<p style="margin:0 0 8px;font-size:14px;color:#495057;">
  Wniosek zostanie rozpatrzony przez administratora — porozumienie zostanie formalnie
  rozwiązane dopiero po jego akceptacji. O decyzji poinformujemy odrębnym e-mailem.
  Szczegóły znajdziesz <a href="{{url}}" style="color:{{accent}};font-weight:600;">w systemie</a>.
</p>
HTML;

    // — Przypomnienie milowe — zbliża się/nastąpił koniec okresu wypowiedzenia —
    $termination_milestone_body = <<<HTML
<p style="margin:0 0 16px;font-size:15px;color:#333333;">
  Dzień dobry, <strong>{{osoba}}</strong>,
</p>
<p style="margin:0 0 24px;font-size:15px;color:#333333;">
  {{urgency_text}}
</p>
<table width="100%" cellpadding="0" cellspacing="0"
       style="background:#f8f9fa;border-radius:6px;border-left:4px solid {{accent}};
              padding:0;margin-bottom:24px;">
  <tr><td style="padding:20px 24px;">
    <table width="100%" cellpadding="0" cellspacing="0">
      <tr>
        <td style="padding:5px 0;font-size:13px;color:#6c757d;width:170px;">Numer porozumienia</td>
        <td style="padding:5px 0;font-size:14px;color:#212529;font-weight:600;">{{numer}}</td>
      </tr>
      <tr>
        <td style="padding:5px 0;font-size:13px;color:#6c757d;">Data zakończenia współpracy</td>
        <td style="padding:5px 0;font-size:14px;color:{{accent}};font-weight:700;">{{data_zakonczenia}}</td>
      </tr>
    </table>
  </td></tr>
</table>
<p style="margin:0 0 8px;font-size:14px;color:#495057;">
  <a href="{{url}}" style="color:{{accent}};font-weight:600;">Otwórz porozumienie w systemie</a>
</p>
HTML;

    // — Zaproszenie opiekuna do odnowienia zgody na wolontariat małoletniego —
    $guardian_consent_body = <<<'HTML'
<html><body style="font-family:sans-serif;max-width:640px;margin:0 auto;padding:24px;color:#212529;line-height:1.6">
  <p style="margin:0 0 4px;font-weight:700">{{org_nazwa}}</p>
  <p style="margin:0;font-size:.92em;color:#495057">
    {{org_adres}}<br>
    e-mail: {{org_email}}<br>
    tel: {{org_telefon}}
  </p>
  <p style="margin:18px 0 0;font-size:.85em;color:#6c757d">Znak sprawy: <strong>{{znak_sprawy}}</strong></p>
  <p style="margin:4px 0 24px;font-size:.9em;color:#495057">{{miejscowosc}}, dnia {{data_pisma}} r.</p>

  <p style="margin:0 0 20px">
    Do:<br>
    Przedstawiciel ustawowy (rodzic/opiekun)<br>
    małoletniego/małoletniej <strong>{{dziecko}}</strong>
  </p>

  <p style="margin:0 0 20px"><strong>Dotyczy:</strong> Wyrażenie zgody na udział dziecka w wolontariacie</p>

  <p>Szanowni Państwo,</p>
  <p>
    w związku z kontynuacją przez Państwa dziecko, <strong>{{dziecko}}</strong>, świadczeń w ramach
    wolontariatu na rzecz naszej Fundacji, zwracamy się z uprzejmą prośbą o dopełnienie
    niezbędnych formalności w formie online.
  </p>
  <p>
    Bezpieczeństwo i transparentność naszych działań są dla nas priorytetem. Zgodnie
    z obowiązującymi przepisami prawa, w tym Kodeksu cywilnego oraz Ogólnego Rozporządzenia
    o Ochronie Danych (RODO), do dalszego udziału Państwa dziecka w wolontariacie niezbędne
    jest regularne, składane co 6 miesięcy, potwierdzenie Państwa zgody. Obejmuje ona zarówno
    zgodę na sam wolontariat, jak i na przetwarzanie danych osobowych w celach z nim związanych.
  </p>
  <p>
    Aby maksymalnie ułatwić ten proces, przygotowaliśmy dla Państwa możliwość złożenia
    odpowiedniego oświadczenia woli w formie elektronicznej („na klik").
  </p>

  <p style="font-weight:700;margin:24px 0 8px">Jak wyrazić zgodę krok po kroku:</p>
  <ol style="padding-left:20px">
    <li style="margin-bottom:8px">Prosimy o zalogowanie się na Państwa konto w naszym systemie pod adresem:
      <a href="{{login_url}}" style="color:{{accent}}">{{login_url}}</a>.</li>
    <li style="margin-bottom:8px">Po zalogowaniu, prosimy przejść do sekcji o nazwie „Zgody i Oświadczenia".</li>
    <li style="margin-bottom:8px">Prosimy o dokładne zapoznanie się z treścią „Oświadczenia przedstawiciela
      ustawowego", które pojawi się na ekranie.</li>
    <li style="margin-bottom:8px">Wyrażenie zgody następuje poprzez zaznaczenie pola (checkbox) o treści:
      „Oświadczam, że zapoznałem/am się z treścią Oświadczenia..." a następnie kliknięcie przycisku
      „Potwierdzam i wyrażam zgodę".</li>
  </ol>

  <p>Potwierdzenie przez Państwa zgody zostanie automatycznie zapisane w naszym systemie, co
  stanowi prawnie wiążące oświadczenie woli.</p>

  <p>W razie jakichkolwiek pytań lub problemów technicznych, jesteśmy do Państwa dyspozycji pod
  adresem e-mail: {{kontakt_email}} lub numerem telefonu: {{kontakt_telefon}}.</p>

  <p style="margin-top:28px">Z wyrazami szacunku,</p>
  <p style="font-weight:700;margin:2px 0 0">Zespół Fundacji Edukacji Empatii Rozwoju "FEER"</p>

  <p style="margin-top:24px;padding-top:14px;border-top:1px solid #dee2e6;font-size:.78em;color:#adb5bd">
    Wiadomość wygenerowana automatycznie przez system zarządzania umowami NGO.
  </p>
</body></html>
HTML;

    // — Kod odzyskiwania dostępu ————————————————————————————————
    $recovery_body = <<<'HTML'
<p>Cześć <strong>{{name}}</strong>,</p>
<p>Twój kod odzyskiwania dostępu do systemu <strong>{{org}}</strong>:</p>
<div style="text-align:center;margin:24px 0">
  <span style="display:inline-block;font-family:monospace;font-size:2rem;letter-spacing:.35em;
               background:#f1f5f9;border:2px dashed #94a3b8;border-radius:8px;padding:12px 28px;font-weight:900">
    {{code}}
  </span>
</div>
<p style="color:#64748b;font-size:.88rem">
  Zachowaj ten kod w bezpiecznym miejscu — będzie potrzebny do odzyskania hasła,
  jeśli nie pamiętasz numeru umowy.
</p>
<p style="font-size:.82em;color:#6c757d;margin-top:20px;padding-top:12px;border-top:1px solid #dee2e6">
  Wiadomość wysłana automatycznie przez system {{org}}.
</p>
HTML;

    // ── TI: powiadomienie dydaktyczne (materiał / zadanie) ————————————
    $ti_dydaktyka_body = <<<'HTML'
<p>Cześć <strong>{{name}}</strong>,</p>
<p>{{body_html}}</p>
<div style="margin:20px 0;text-align:center">
  <a href="{{url}}" style="background:#4338ca;color:#fff;padding:11px 26px;border-radius:8px;text-decoration:none;display:inline-block;font-weight:600">
    Otwórz panel kursanta →
  </a>
</div>
<p style="font-size:.8em;color:#6c757d;margin-top:20px;padding-top:12px;border-top:1px solid #dee2e6">
  Wiadomość automatyczna z systemu {{org}}. Powiadomienia możesz wyłączyć w Ustawieniach.
</p>
HTML;

    // ── TI: prośba o odwołanie lekcji (do prowadzącego) ───────────────
    $ti_cancel_req_body = <<<'HTML'
<p>Dzień dobry,</p>
<p><strong>{{client_name}}</strong> prosi o odwołanie udziału w lekcji
   <strong>{{course_name}}</strong> ({{when}}).</p>
{{reason_block}}
<p>Prośba czeka na Twoje potwierdzenie w panelu dydaktyka.</p>
<div style="margin:20px 0;text-align:center">
  <a href="{{url}}" style="background:#d97706;color:#fff;padding:11px 26px;border-radius:8px;text-decoration:none;display:inline-block;font-weight:600">
    Otwórz panel dydaktyka →
  </a>
</div>
<p style="font-size:.8em;color:#6c757d;margin-top:20px;padding-top:12px;border-top:1px solid #dee2e6">
  Wiadomość automatyczna z systemu {{org}}.
</p>
HTML;

    // ── TI: decyzja prowadzącego ws. odwołania (do kursanta) ─────────
    $ti_cancel_decision_body = <<<'HTML'
<p>Dzień dobry,</p>
<p>{{lead_html}}</p>
<div style="margin:20px 0;text-align:center">
  <a href="{{url}}" style="background:#4338ca;color:#fff;padding:11px 26px;border-radius:8px;text-decoration:none;display:inline-block;font-weight:600">
    Otwórz panel kursanta →
  </a>
</div>
<p style="font-size:.8em;color:#6c757d;margin-top:20px;padding-top:12px;border-top:1px solid #dee2e6">
  Wiadomość automatyczna z systemu {{org}}.
</p>
HTML;

    // ── TI: nowa ocena (do kursanta) ──────────────────────────────────
    $ti_grade_body = <<<'HTML'
<p>Dzień dobry,</p>
<p>W kursie <strong>{{course_name}}</strong> wystawiono ocenę:</p>
<div style="text-align:center;margin:20px 0">
  <span style="display:inline-block;font-size:2rem;font-weight:900;font-family:monospace;
               background:#f0fdf4;border:2px solid #86efac;border-radius:10px;padding:10px 30px;color:#166534">
    {{value}}
  </span>
  {{category_block}}
</div>
{{description_block}}
<div style="margin:20px 0;text-align:center">
  <a href="{{url}}" style="background:#0891b2;color:#fff;padding:11px 26px;border-radius:8px;text-decoration:none;display:inline-block;font-weight:600">
    Zobacz oceny w panelu kursanta →
  </a>
</div>
<p style="font-size:.8em;color:#6c757d;margin-top:20px;padding-top:12px;border-top:1px solid #dee2e6">
  Wiadomość automatyczna z systemu {{org}}.
</p>
HTML;

    // ── TI: nieobecność bez zgłoszenia (no-show) ─────────────────────
    $ti_no_show_body = <<<'HTML'
<p>Dzień dobry,</p>
<p>Informujemy, że <strong>{{client_name}}</strong> nie pojawił/a się na zajęciach kursu <strong>{{course_name}}</strong> w dniu <strong>{{when}}</strong>.</p>
<table style="border-collapse:collapse;margin:16px 0">
  <tr><td style="padding:3px 20px 3px 0;color:#555;white-space:nowrap">Sposób rozliczenia:</td><td><strong>{{billing_label}}</strong></td></tr>
  {{reason_row}}
</table>
<p>Jeśli nieobecność była spowodowana nagłą sytuacją, prosimy o kontakt.</p>
<p><a href="{{url}}">Panel kursanta</a></p>
<hr style="border:none;border-top:1px solid #ddd;margin:20px 0">
<p style="font-size:.8em;color:#888">Wiadomość automatyczna — {{org}}.</p>
HTML;

    // ── TI: zatwierdzony numer konta do wpłat (do kursanta) ───────────
    $ti_payment_account_body = <<<'HTML'
<p>Dzień dobry, <strong>{{osoba}}</strong>,</p>
<p>Kierownik zatwierdził numer konta do wpłat za zajęcia i szkolenia w ramach {{org}}.</p>
<table width="100%" cellpadding="0" cellspacing="0"
       style="background:#f8f9fa;border-radius:6px;border-left:4px solid {{accent}};
              padding:0;margin:16px 0 20px;">
  <tr><td style="padding:18px 22px;">
    <table width="100%" cellpadding="0" cellspacing="0">
      <tr>
        <td style="padding:5px 0;font-size:13px;color:#6c757d;width:150px;">Numer konta</td>
        <td style="padding:5px 0;font-size:15px;color:#212529;font-weight:700;font-family:monospace">{{numer_konta}}</td>
      </tr>
      <tr>
        <td style="padding:5px 0;font-size:13px;color:#6c757d;">Rodzaj rachunku</td>
        <td style="padding:5px 0;font-size:14px;color:#212529;">{{typ_konta}}</td>
      </tr>
    </table>
  </td></tr>
</table>
<p style="margin:0 0 8px;font-size:14px;color:#495057;">
  Wszelkie wpłaty związane z zajęciami i szkoleniami prosimy wnosić <strong>wyłącznie na ten rachunek</strong>.
</p>
<div style="margin:20px 0;text-align:center">
  <a href="{{link}}" style="background:{{accent}};color:#fff;padding:11px 26px;border-radius:8px;text-decoration:none;display:inline-block;font-weight:600">
    Sprawdź aktualny numer konta →
  </a>
</div>
<p style="font-size:.8em;color:#6c757d">
  Link działa również później — jeśli numer konta się zmieni, zawsze zobaczysz pod nim aktualny.
</p>
<p style="font-size:.8em;color:#6c757d;margin-top:20px;padding-top:12px;border-top:1px solid #dee2e6">
  Wiadomość automatyczna z systemu {{org}}.
</p>
HTML;

    // ── Link weryfikacyjny numeru konta (do kontrahenta) ──────────────
    $sprawdz_konto_kontrahent_body = <<<'HTML'
<p>Dzień dobry, <strong>{{osoba}}</strong>,</p>
<p>
  W związku z rozliczeniami w ramach współpracy z {{org}} przesyłamy spersonalizowany link,
  pod którym można w każdej chwili zweryfikować <strong>oficjalny numer konta</strong> organizacji do wpłat.
</p>
<div style="margin:20px 0;text-align:center">
  <a href="{{link}}" style="background:{{accent}};color:#fff;padding:11px 26px;border-radius:8px;text-decoration:none;display:inline-block;font-weight:600">
    Sprawdź numer konta →
  </a>
</div>
<p style="margin:0 0 8px;font-size:14px;color:#495057;">
  Zalecamy skorzystanie z tego linku przed każdą wpłatą, zwłaszcza jeśli numer konta w otrzymanej
  fakturze lub innej wiadomości budzi jakiekolwiek wątpliwości — to najprostszy sposób ochrony przed
  próbami podmiany numeru konta.
</p>
<p style="font-size:.8em;color:#6c757d;margin-top:20px;padding-top:12px;border-top:1px solid #dee2e6">
  Wiadomość wysłana na prośbę/w związku ze współpracą z {{org}}.
</p>
HTML;

    // ── TI: rozliczenie miesięczne (do kursanta) ──────────────────────
    $ti_billing_body = <<<'HTML'
<p>Dzień dobry,</p>
<p>W związku z realizacją zajęć przesyłamy zestawienie należności.
   To automatyczny komunikat z systemu {{org}}.</p>
<p>Faktura została wysłana osobno przez system fakturujący
   (a w przypadku indywidualnego kodu rozliczeń – przez opiekuna konta).</p>
<ul style="margin:14px 0;padding-left:20px">
  <li>Beneficjent: <strong>{{client_name}}</strong></li>
  <li>Kod grupy: <strong>{{group_code}}</strong></li>
</ul>
<p>Poniżej znajduje się szczegółowe wyliczenie kwot:</p>
<div style="background:#f8f9fa;border-left:3px solid #059669;border-radius:4px;padding:14px 18px;margin:14px 0">
  <ul style="margin:0;padding-left:20px">
    <li>Tytuł należności / Okres: {{period_title}} – <strong>{{amount_main}}</strong></li>
    <li>Opłaty dodatkowe: {{extra_desc}} – <strong>{{extra_amount}}</strong></li>
    <li>Razem do zapłaty: <strong>{{total}}</strong></li>
  </ul>
</div>
<p>Prosimy o wpłatę powyższej kwoty na rachunek bankowy wskazany na fakturze:
   na konto ogólne (z końcówką 0002) lub na indywidualny numer rachunku Beneficjenta –
   w zależności od tego, kto jest płatnikiem.</p>
<p>W tytule przelewu prosimy wpisać: <strong>„{{transfer_title}}”</strong>.</p>
<p>W razie pytań chętnie pomożemy.</p>
<p style="margin-top:18px">Z poważaniem,<br>{{org}}</p>
<p style="font-size:.85em;color:#495057">
  Szczegóły i historia rozliczeń w panelu kursanta:
  <a href="{{portal}}" style="color:#059669">{{portal}}</a>
</p>
<p style="font-size:.8em;color:#6c757d;margin-top:20px;padding-top:12px;border-top:1px solid #dee2e6">
  Wiadomość wygenerowana automatycznie.
</p>
HTML;

    // ── TI: nowa wiadomość od prowadzącego (do kursanta) ──────────────
    $ti_message_body = <<<'HTML'
<p>Cześć <strong>{{name}}</strong>,</p>
<p>Masz nową wiadomość w panelu kursanta:</p>
<div style="border-left:3px solid #2563eb;padding:8px 14px;color:#333;margin:12px 0;background:#f8f9fa;border-radius:0 4px 4px 0">
  <strong>{{subject}}</strong><br>{{preview_html}}
</div>
<div style="margin:20px 0;text-align:center">
  <a href="{{url}}" style="background:#1d4ed8;color:#fff;padding:11px 26px;border-radius:8px;text-decoration:none;display:inline-block;font-weight:600">
    Przeczytaj i odpowiedz →
  </a>
</div>
<p style="font-size:.8em;color:#6c757d;margin-top:20px;padding-top:12px;border-top:1px solid #dee2e6">
  Wiadomość automatyczna z systemu {{org}}. Powiadomienia możesz wyłączyć w Ustawieniach.
</p>
HTML;

    // ── TI: odpowiedź kursanta (do prowadzącego) ──────────────────────
    $ti_message_reply_body = <<<'HTML'
<p>Kursant <strong>{{student_name}}</strong> odpowiedział w panelu:</p>
<div style="border-left:3px solid #16a34a;padding:8px 14px;color:#333;margin:12px 0;background:#f8f9fa;border-radius:0 4px 4px 0">
  {{preview_html}}
</div>
<div style="margin:20px 0;text-align:center">
  <a href="{{url}}" style="background:#16a34a;color:#fff;padding:11px 26px;border-radius:8px;text-decoration:none;display:inline-block;font-weight:600">
    Otwórz wątek →
  </a>
</div>
<p style="font-size:.8em;color:#6c757d;margin-top:20px;padding-top:12px;border-top:1px solid #dee2e6">
  Wiadomość automatyczna z systemu {{org}}.
</p>
HTML;

    // ── Raport brakujących szkoleń wolontariuszy ─────────────────────
    $szkolenia_body = <<<'HTML'
<p style="margin:0 0 16px;font-size:15px;color:#333333;">
  Dzień dobry{{greeting}},
</p>
<p style="margin:0 0 16px;font-size:15px;color:#333333;">
  Poniżej zestawienie wolontariuszy z aktywnymi umowami, którzy do końca <strong>{{month}}</strong>
  nie ukończyli wymaganych szkoleń. Łącznie: <strong>{{count}}</strong> os.
</p>
<table width="100%" cellpadding="0" cellspacing="0"
       style="border-collapse:collapse;margin-bottom:24px;border:1px solid #e2e8f0;border-radius:6px;overflow:hidden">
  <thead>
    <tr style="background:#f1f5f9">
      <th style="padding:9px 12px;text-align:left;font-size:12px;color:#475569;font-weight:600;border-bottom:2px solid #e2e8f0">Wolontariusz</th>
      <th style="padding:9px 12px;text-align:left;font-size:12px;color:#475569;font-weight:600;border-bottom:2px solid #e2e8f0;white-space:nowrap">Nr umowy</th>
      <th style="padding:9px 12px;text-align:left;font-size:12px;color:#475569;font-weight:600;border-bottom:2px solid #e2e8f0">Brakujące szkolenia</th>
    </tr>
  </thead>
  <tbody>
    {{rows_html}}
  </tbody>
</table>
<p style="margin:0 0 16px;font-size:14px;color:#495057;">
  Prosimy o kontakt z wymienionymi osobami w celu ustalenia terminu uzupełnienia szkoleń.
</p>
<div style="margin:20px 0;text-align:center">
  <a href="{{app_url}}" style="background:#1d4ed8;color:#fff;padding:11px 26px;border-radius:8px;text-decoration:none;display:inline-block;font-weight:600">
    Przejdź do Szkoleń →
  </a>
</div>
<p style="font-size:.82em;color:#6c757d;margin-top:20px;padding-top:12px;border-top:1px solid #dee2e6">
  Wiadomość wysłana automatycznie przez system. Raport generowany raz w miesiącu.
</p>
HTML;

    // — Nowy rachunek w systemie (umowa zlecenie) ————————————————
    $zlecenie_rachunek_body = <<<'HTML'
<p style="margin:0 0 16px">Dzień dobry,</p>
<p style="margin:0 0 16px">
  Informujemy, że w systemie został wygenerowany nowy rachunek.
</p>
<p style="margin:0 0 12px">Możesz przejść do niego bezpośrednio pod poniższym adresem:</p>
<div style="margin:20px 0;text-align:center">
  <a href="{{url}}" style="background:#2563eb;color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;display:inline-block;font-weight:600">
    Otwórz rachunek →
  </a>
</div>
<p style="margin:0 0 16px;font-size:.85em;color:#6c757d;word-break:break-all">{{url}}</p>
<p style="margin:0 0 16px">
  Prosimy o pobranie dokumentu oraz dopełnienie dalszych kroków związanych z jego rozliczeniem.
  Rachunek należy wydrukować, podpisać odręcznie i dostarczyć jednym z dwóch sposobów:
</p>
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f8fafc;border-left:3px solid #2563eb;border-radius:4px;margin:0 0 16px">
  <tr><td style="padding:14px 18px;font-size:14px;color:#334155">
    <strong>Opcja 1 — szybciej:</strong> wgraj skan podpisanego rachunku bezpośrednio pod
    <a href="{{url}}" style="color:#1d4ed8">powyższym linkiem</a>, a oryginał dostarcz w ciągu
    {{oryginal_days}} dni{{oryginal_deadline}}.
    <br><br>
    <strong>Opcja 2:</strong> w ciągu {{skan_days}} dni{{skan_deadline}} prześlij skan podpisanego
    rachunku na adres <a href="mailto:{{skan_email}}" style="color:#1d4ed8">{{skan_email}}</a>,
    a następnie w ciągu {{oryginal_days}} dni{{oryginal_deadline}} dostarcz oryginał{{adres}}.
  </td></tr>
</table>
<p style="margin:0 0 16px">W razie pytań lub problemów technicznych pozostajemy do dyspozycji.</p>
<p style="margin:0">Z poważaniem,<br><strong>{{org}}</strong></p>
HTML;

    $base = defined('APP_URL') ? rtrim(APP_URL, '/') : '';

    $reg = [
        'welcome' => [
            'label'       => 'Powitalny — dane logowania',
            'group'       => 'Konta',
            'icon'        => 'bi-envelope-heart',
            'auto'        => false,
            'description' => 'Wysyłany przy zakładaniu konta wolontariusza i przy ponownej wysyłce danych logowania.',
            'subject'     => 'Twoje dane logowania — {{org}}',
            'body'        => $welcome_body,
            'vars'        => [
                'org'         => ['label' => 'Nazwa organizacji',        'sample' => 'Fundacja FEER'],
                'name'        => ['label' => 'Imię i nazwisko odbiorcy', 'sample' => 'Jan Kowalski'],
                'intro'       => ['label' => 'Tekst wprowadzający',      'sample' => 'Poniżej znajdziesz dane logowania do portalu wolontariusza.'],
                'accent'      => ['label' => 'Kolor akcentu (hex)',      'sample' => '#1d6ef9'],
                'login_block' => ['label' => 'Blok z e-mailem i hasłem (HTML)', 'sample' => '<table style="background:#f8f9fa;border-radius:8px;padding:16px;width:100%;margin:16px 0;border-collapse:collapse"><tr><td style="padding:5px 14px;color:#6c757d;width:130px;font-size:.9em">Adres e-mail</td><td style="padding:5px 14px"><strong>jan@example.org</strong></td></tr><tr><td style="padding:5px 14px;color:#6c757d;font-size:.9em">Hasło</td><td style="padding:5px 14px"><strong style="font-family:monospace;font-size:1.15em;letter-spacing:.05em">Ab3xK9mP2qLt</strong></td></tr></table>'],
                'login_url'   => ['label' => 'Adres strony logowania',   'sample' => $base . '/auth/login.php'],
            ],
        ],

        'contract_expiry' => [
            'label'       => 'Przypomnienie — wygaśnięcie umowy',
            'group'       => 'Umowy',
            'icon'        => 'bi-calendar-x',
            'auto'        => true,
            'description' => 'Cykliczne przypomnienie wysyłane do opiekuna umowy zbliżającej się do terminu zakończenia.',
            'subject'     => 'Przypomnienie: {{type_label}} {{numer}} wygasa za {{days_left}} {{days_label}}',
            'body'        => $expiry_body,
            'vars'        => [
                'accent'           => ['label' => 'Kolor akcentu (zależny od pilności)', 'sample' => '#fd7e14'],
                'greeting'         => ['label' => 'Zwrot grzecznościowy (np. ", Anna Nowak")', 'sample' => ', <strong>Anna Nowak</strong>'],
                'urgency_text'     => ['label' => 'Tekst pilności',     'sample' => 'Umowa wygasa za <strong>7 dni</strong>.'],
                'type_label'       => ['label' => 'Typ umowy',          'sample' => 'Umowa zlecenie'],
                'numer'            => ['label' => 'Numer umowy',        'sample' => 'UZ/2026/014'],
                'osoba'            => ['label' => 'Osoba',              'sample' => 'Jan Kowalski'],
                'data_zakonczenia' => ['label' => 'Data zakończenia',   'sample' => '30.06.2026'],
                'days_left'        => ['label' => 'Liczba dni',         'sample' => '7'],
                'days_label'       => ['label' => 'Odmiana „dni"',      'sample' => 'dni'],
            ],
        ],

        'zus_reminder' => [
            'label'       => 'Przypomnienie — ZUS (zlecenie)',
            'group'       => 'Umowy',
            'icon'        => 'bi-clipboard-pulse',
            'auto'        => true,
            'description' => 'Przypomnienie o zgłoszeniu/wyrejestrowaniu zleceniobiorcy w ZUS w ustawowym terminie.',
            'subject'     => 'ZUS: {{tytul}} — zlecenie {{numer}} ({{osoba}})',
            'body'        => $zus_body,
            'vars'        => [
                'accent'   => ['label' => 'Kolor akcentu (zależny od pilności)', 'sample' => '#dc3545'],
                'tytul'    => ['label' => 'Tytuł czynności',  'sample' => 'Zgłoszenie do ubezpieczeń'],
                'akcja'    => ['label' => 'Akcja (czasownik)', 'sample' => 'zgłosić'],
                'numer'    => ['label' => 'Numer umowy',      'sample' => 'UZ/2026/014'],
                'osoba'    => ['label' => 'Zleceniobiorca',   'sample' => 'Jan Kowalski'],
                'deadline' => ['label' => 'Termin (data)',    'sample' => '20.06.2026'],
                'pilnosc'  => ['label' => 'Tekst pilności',   'sample' => 'Pozostały <strong>3 dni</strong>.'],
                'url'      => ['label' => 'Link do umowy',    'sample' => $base . '/contracts/zlecenie/view.php?id=14'],
            ],
        ],

        'zlecenie_rachunek_new' => [
            'label'       => 'Nowy rachunek w systemie (do zleceniobiorcy)',
            'group'       => 'Umowy',
            'icon'        => 'bi-receipt',
            'auto'        => false,
            'description' => 'Wysyłany po dodaniu rachunku w zakładce „Rachunki” umowy zlecenie — z linkiem do pobrania dokumentu.',
            'subject'     => 'Nowy rachunek w systemie – {{org}}',
            'body'        => $zlecenie_rachunek_body,
            'vars'        => [
                'org'            => ['label' => 'Nazwa organizacji',   'sample' => 'Fundacja Edukacji Empatii Rozwoju „FEER”'],
                'name'           => ['label' => 'Imię i nazwisko zleceniobiorcy', 'sample' => 'Jan Kowalski'],
                'numer'          => ['label' => 'Numer umowy',         'sample' => 'UZ/2026/014'],
                'numer_rachunku' => ['label' => 'Numer rachunku',      'sample' => '1/2026'],
                'okres'          => ['label' => 'Okres rachunku',      'sample' => 'czerwiec 2026'],
                'url'            => ['label' => 'Link do rachunku',    'sample' => $base . '/contracts/zlecenie/rachunek_pobierz.php?token=abc123'],
                'skan_email'     => ['label' => 'Adres na skan rachunku', 'sample' => 'fundacja@feer.org.pl'],
                'skan_days'      => ['label' => 'Termin na skan (dni)',   'sample' => '2'],
                'skan_deadline'  => ['label' => 'Data graniczna skanu (fragment „ (do …)” lub pusty)', 'sample' => ' (do 24.08.2026)'],
                'oryginal_days'  => ['label' => 'Termin na oryginał (dni)', 'sample' => '7'],
                'oryginal_deadline' => ['label' => 'Data graniczna oryginału (fragment „ (do …)” lub pusty)', 'sample' => ' (do 29.08.2026)'],
                'adres'          => ['label' => 'Adres siedziby (fragment „ na adres: …” lub pusty)', 'sample' => ' na adres: ul. Przykładowa 1, Warszawa'],
            ],
        ],

        'minor_volunteer_periodic_verification' => [
            'label'       => 'Okresowa weryfikacja — wolontariusz niepełnoletni',
            'group'       => 'Umowy',
            'icon'        => 'bi-shield-check',
            'auto'        => true,
            'description' => 'Cykliczne (co 90 dni) powiadomienie opiekuna wolontariusza niepełnoletniego o zbliżającym się piśmie w sprawie okresowej weryfikacji wolontariatu.',
            'subject'     => 'Okresowa weryfikacja wolontariatu — {{osoba}}',
            'body'        => $minor_verif_body,
            'vars'        => [
                'accent'           => ['label' => 'Kolor akcentu',       'sample' => '#0ea5e9'],
                'osoba'            => ['label' => 'Wolontariusz (niepełnoletni)', 'sample' => 'Jan Kowalski'],
                'opiekun_nazwa'    => ['label' => 'Imię i nazwisko opiekuna', 'sample' => 'Anna Nowak'],
                'numer'            => ['label' => 'Numer umowy',         'sample' => 'W/2026/014'],
                'data_weryfikacji' => ['label' => 'Data weryfikacji',    'sample' => '05.07.2026'],
            ],
        ],

        'minor_volunteer_periodic_verification_admin' => [
            'label'       => 'Okresowa weryfikacja — powiadomienie admina',
            'group'       => 'Umowy',
            'icon'        => 'bi-envelope-exclamation',
            'auto'        => true,
            'description' => 'Wysyłane do administratorów razem z powiadomieniem opiekuna — przypomina o konieczności przygotowania i wysłania pisma.',
            'subject'     => 'Do wysłania: pismo ws. weryfikacji wolontariusza niepełnoletniego — {{osoba}}',
            'body'        => $minor_verif_admin_body,
            'vars'        => [
                'accent'           => ['label' => 'Kolor akcentu',       'sample' => '#0ea5e9'],
                'osoba'            => ['label' => 'Wolontariusz (niepełnoletni)', 'sample' => 'Jan Kowalski'],
                'numer'            => ['label' => 'Numer umowy',         'sample' => 'W/2026/014'],
                'opiekun_nazwa'    => ['label' => 'Imię i nazwisko opiekuna', 'sample' => 'Anna Kowalska'],
                'opiekun_email'    => ['label' => 'E-mail opiekuna',     'sample' => 'anna.kowalska@example.com'],
                'data_weryfikacji' => ['label' => 'Data weryfikacji',    'sample' => '05.07.2026'],
                'url'              => ['label' => 'Link do umowy',       'sample' => $base . '/contracts/wolontariat/view.php?id=14'],
            ],
        ],

        'termination_confirmation' => [
            'label'       => 'Potwierdzenie — rezygnacja/rozwiązanie porozumienia wolontariackiego',
            'group'       => 'Umowy',
            'icon'        => 'bi-file-earmark-x',
            'auto'        => false,
            'description' => 'Wysyłane do wolontariusza natychmiast po przyjęciu oświadczenia o rezygnacji lub rozwiązaniu porozumienia — niezależnie od tego, czy złożył je sam, czy pracownik w jego imieniu.',
            'subject'     => 'Potwierdzenie przyjęcia oświadczenia — porozumienie {{numer}}',
            'body'        => $termination_confirmation_body,
            'vars'        => [
                'accent'            => ['label' => 'Kolor akcentu',                'sample' => '#0d6efd'],
                'osoba'             => ['label' => 'Wolontariusz',                 'sample' => 'Jan Kowalski'],
                'numer'             => ['label' => 'Numer porozumienia',           'sample' => 'W/2026/014'],
                'data_oswiadczenia' => ['label' => 'Data sporządzenia oświadczenia','sample' => '18.09.2026'],
                'powod'             => ['label' => 'Powód rezygnacji/rozwiązania', 'sample' => 'Zmiana miejsca zamieszkania.'],
                'tryb'              => ['label' => 'Tryb rozwiązania (opis)',      'sample' => 'Standardowy (14 dni) — okres wypowiedzenia: 14 dni'],
                'data_zakonczenia'  => ['label' => 'Data zakończenia współpracy',  'sample' => '02.10.2026'],
                'url'               => ['label' => 'Link do porozumienia',         'sample' => $base . '/contracts/wolontariat/view.php?id=14'],
                'org'               => ['label' => 'Nazwa organizacji',           'sample' => 'Fundacja FEER'],
            ],
        ],

        'termination_milestone' => [
            'label'       => 'Przypomnienie — upływa okres wypowiedzenia',
            'group'       => 'Umowy',
            'icon'        => 'bi-alarm',
            'auto'        => true,
            'description' => 'Cykliczne przypomnienie dla wolontariusza, którego okres wypowiedzenia porozumienia zbliża się do końca (7, 1 dzień) lub upłynął dzisiaj.',
            'subject'     => 'Przypomnienie: {{urgency_short}} — porozumienie {{numer}}',
            'body'        => $termination_milestone_body,
            'vars'        => [
                'accent'           => ['label' => 'Kolor akcentu (zależny od pilności)', 'sample' => '#fd7e14'],
                'osoba'            => ['label' => 'Wolontariusz',              'sample' => 'Jan Kowalski'],
                'numer'            => ['label' => 'Numer porozumienia',        'sample' => 'W/2026/014'],
                'urgency_text'     => ['label' => 'Tekst pilności',            'sample' => 'Okres wypowiedzenia porozumienia upływa za <strong>1 dzień</strong>.'],
                'urgency_short'    => ['label' => 'Krótki tekst pilności (temat)', 'sample' => 'okres wypowiedzenia upływa za 1 dzień'],
                'data_zakonczenia' => ['label' => 'Data zakończenia współpracy', 'sample' => '02.10.2026'],
                'url'              => ['label' => 'Link do porozumienia',       'sample' => $base . '/contracts/wolontariat/view.php?id=14'],
            ],
        ],

        'guardian_consent_renewal' => [
            'label'       => 'Zaproszenie — odnowienie zgody opiekuna na wolontariat',
            'group'       => 'Umowy',
            'icon'        => 'bi-file-earmark-check',
            'auto'        => true,
            'description' => 'Wysyłane co 6 miesięcy do przedstawiciela ustawowego małoletniego wolontariusza — zaprasza do złożenia/odnowienia zgody „na klik" w panelu.',
            'subject'     => 'Odnowienie zgody na wolontariat — {{dziecko}} (znak sprawy {{znak_sprawy}})',
            'body'        => $guardian_consent_body,
            'vars'        => [
                'accent'          => ['label' => 'Kolor akcentu',           'sample' => '#1D4ED8'],
                'org_nazwa'       => ['label' => 'Nazwa organizacji',       'sample' => 'Fundacja Edukacji Empatii Rozwoju "FEER"'],
                'org_adres'       => ['label' => 'Adres organizacji',       'sample' => 'ul. Barbackiego 28/18, 33-300 Nowy Sącz'],
                'org_email'       => ['label' => 'E-mail organizacji',      'sample' => 'kontakt@feer.org.pl'],
                'org_telefon'     => ['label' => 'Telefon organizacji',     'sample' => '+48 123 456 789'],
                'znak_sprawy'     => ['label' => 'Znak sprawy (EZD)',       'sample' => 'WOL.3.2026'],
                'miejscowosc'     => ['label' => 'Miejscowość wysłania',    'sample' => 'Nowy Sącz'],
                'data_pisma'      => ['label' => 'Data wysłania pisma',     'sample' => '05.07.2026'],
                'dziecko'         => ['label' => 'Imię i nazwisko dziecka', 'sample' => 'Jan Kowalski'],
                'login_url'       => ['label' => 'Link do logowania',      'sample' => $base . '/auth/login.php'],
                'kontakt_email'   => ['label' => 'E-mail kontaktowy (pytania)', 'sample' => 'kontakt@feer.org.pl'],
                'kontakt_telefon' => ['label' => 'Telefon kontaktowy (pytania)', 'sample' => '+48 123 456 789'],
            ],
        ],

        'szkolenia_brakujace' => [
            'label'       => 'Raport — brakujące szkolenia wolontariuszy',
            'group'       => 'Szkolenia',
            'icon'        => 'bi-calendar2-check',
            'auto'        => true,
            'description' => 'Miesięczny raport wysyłany do opiekunów: lista wolontariuszy, którzy nie ukończyli wymaganych szkoleń (BHP, szkolenia TidyCal).',
            'subject'     => 'Brakujące szkolenia wolontariuszy — {{month}}',
            'body'        => $szkolenia_body,
            'vars'        => [
                'greeting'  => ['label' => 'Zwrot grzecznościowy',              'sample' => ', <strong>Anna Nowak</strong>'],
                'month'     => ['label' => 'Miesiąc raportu',                   'sample' => 'lipca 2026'],
                'count'     => ['label' => 'Liczba wolontariuszy z brakami',     'sample' => '4'],
                'rows_html' => ['label' => 'Wiersze tabeli HTML (generowane)',   'sample' => ''],
                'app_url'   => ['label' => 'Link do modułu Szkolenia',           'sample' => $base . '/szkolenia/'],
            ],
        ],

        'recovery_code' => [
            'label'       => 'Kod odzyskiwania dostępu',
            'group'       => 'Konta',
            'icon'        => 'bi-shield-lock',
            'auto'        => false,
            'description' => 'Wysyłany, gdy wolontariusz poprosi o odzyskanie dostępu do konta portalu.',
            'subject'     => 'Kod odzyskiwania dostępu — {{org}}',
            'body'        => $recovery_body,
            'vars'        => [
                'org'  => ['label' => 'Nazwa organizacji',        'sample' => 'Fundacja FEER'],
                'name' => ['label' => 'Imię i nazwisko odbiorcy', 'sample' => 'Jan Kowalski'],
                'code' => ['label' => 'Kod odzyskiwania',         'sample' => '48210736'],
            ],
        ],

        // ── TI — Zajęcia ─────────────────────────────────────────────────

        'ti_dydaktyka' => [
            'label'       => 'TI: powiadomienie dydaktyczne',
            'group'       => 'TI — Zajęcia',
            'icon'        => 'bi-book',
            'auto'        => true,
            'description' => 'Powiadomienie dla kursanta o nowym materiale lub zadaniu domowym w panelu.',
            'subject'     => '{{org}}: {{subject}}',
            'body'        => $ti_dydaktyka_body,
            'vars'        => [
                'org'       => ['label' => 'Nazwa organizacji',     'sample' => 'Dydaktyka TI'],
                'name'      => ['label' => 'Imię kursanta',         'sample' => 'Anna'],
                'subject'   => ['label' => 'Temat powiadomienia',   'sample' => 'nowy materiał do kursu'],
                'body_html' => ['label' => 'Treść powiadomienia (HTML)', 'sample' => 'Dodano nowy materiał: <strong>Wprowadzenie do pracy z klawiaturą</strong>.'],
                'url'       => ['label' => 'Link do panelu kursanta', 'sample' => $base . '/karty30/ti/kursant/index.php'],
            ],
        ],

        'ti_cancel_req' => [
            'label'       => 'TI: prośba o odwołanie lekcji (do prowadzącego)',
            'group'       => 'TI — Zajęcia',
            'icon'        => 'bi-calendar-x',
            'auto'        => true,
            'description' => 'E-mail do prowadzącego informujący o prośbie kursanta o odwołanie udziału w lekcji.',
            'subject'     => '{{org}}: prośba o odwołanie lekcji — {{when}}',
            'body'        => $ti_cancel_req_body,
            'vars'        => [
                'org'          => ['label' => 'Nazwa organizacji',  'sample' => 'Dydaktyka TI'],
                'client_name'  => ['label' => 'Imię i nazwisko kursanta', 'sample' => 'Jan Kowalski'],
                'course_name'  => ['label' => 'Nazwa kursu',        'sample' => 'Kurs obsługi komputera'],
                'when'         => ['label' => 'Data i godzina lekcji', 'sample' => '30.06.2026 o 10:00'],
                'reason_block' => ['label' => 'Blok z powodem odwołania (HTML lub pusty)', 'sample' => '<p>Powód: problemy zdrowotne</p>'],
                'url'          => ['label' => 'Link do panelu dydaktyka', 'sample' => $base . '/karty30/ti/dydaktyk/index.php'],
            ],
        ],

        'ti_no_show' => [
            'label'       => 'TI: nieobecność bez zgłoszenia (do rodzica/kursanta)',
            'group'       => 'TI — Zajęcia',
            'icon'        => 'bi-dash-circle',
            'auto'        => true,
            'description' => 'Powiadomienie o niepojawieniu się beneficjenta na zajęciach wraz z informacją o sposobie rozliczenia.',
            'subject'     => '{{org}}: nieobecność na zajęciach — {{when}}',
            'body'        => $ti_no_show_body,
            'vars'        => [
                'org'           => ['label' => 'Nazwa organizacji',          'sample' => 'Dydaktyka TI'],
                'client_name'   => ['label' => 'Imię i nazwisko kursanta',   'sample' => 'Jan Kowalski'],
                'course_name'   => ['label' => 'Nazwa kursu',                'sample' => 'Kurs obsługi komputera'],
                'when'          => ['label' => 'Data i godzina lekcji',      'sample' => '30.06.2026 o 10:00'],
                'billing_label' => ['label' => 'Sposób rozliczenia',         'sample' => 'cała lekcja (2 h)'],
                'reason_row'    => ['label' => 'Wiersz tabeli z opisem (HTML lub pusty)', 'sample' => ''],
                'url'           => ['label' => 'Link do panelu kursanta',    'sample' => $base . '/karty30/ti/kursant/index.php?tab=lekcje'],
            ],
        ],

        'ti_cancel_decision' => [
            'label'       => 'TI: decyzja o odwołaniu (do kursanta)',
            'group'       => 'TI — Zajęcia',
            'icon'        => 'bi-check2-circle',
            'auto'        => true,
            'description' => 'Powiadomienie dla kursanta o potwierdzeniu lub odrzuceniu prośby o odwołanie udziału w lekcji.',
            'subject'     => '{{org}}: {{subject_suffix}}',
            'body'        => $ti_cancel_decision_body,
            'vars'        => [
                'org'           => ['label' => 'Nazwa organizacji',          'sample' => 'Dydaktyka TI'],
                'subject_suffix'=> ['label' => 'Końcówka tematu',             'sample' => 'potwierdzono odwołanie udziału — 30.06.2026'],
                'lead_html'     => ['label' => 'Główna treść (HTML)',          'sample' => 'Twoja prośba o odwołanie udziału w lekcji <strong>Kurs obsługi komputera</strong> (30.06.2026 o 10:00) została <strong>potwierdzona</strong>.'],
                'url'           => ['label' => 'Link do panelu kursanta',      'sample' => $base . '/karty30/ti/kursant/index.php?tab=lekcje'],
            ],
        ],

        'ti_payment_account' => [
            'label'       => 'TI: zatwierdzony numer konta do wpłat (do kursanta)',
            'group'       => 'TI — Zajęcia',
            'icon'        => 'bi-bank',
            'auto'        => false,
            'description' => 'Wysyłane do kursanta (i opiekuna małoletniego) po zatwierdzeniu przez kierownika numeru konta do wpłat za zajęcia i szkolenia (karty30/ti/dydaktyk/konta.php).',
            'subject'     => 'Numer konta do wpłat za zajęcia — {{org}}',
            'body'        => $ti_payment_account_body,
            'vars'        => [
                'accent'       => ['label' => 'Kolor akcentu',       'sample' => '#0d6efd'],
                'osoba'        => ['label' => 'Kursant',             'sample' => 'Jan Kowalski'],
                'numer_konta'  => ['label' => 'Numer konta',         'sample' => 'PL61 1090 1014 0000 0712 1981 2874'],
                'typ_konta'    => ['label' => 'Rodzaj rachunku',     'sample' => 'Rachunek organizacji: Konto główne (dla TI)'],
                'link'         => ['label' => 'Link do ponownego sprawdzenia numeru', 'sample' => $base . '/konto.php?t=abc123'],
                'org'          => ['label' => 'Nazwa organizacji',   'sample' => 'Dydaktyka TI'],
            ],
        ],

        'sprawdz_konto_kontrahent' => [
            'label'       => 'Link weryfikacyjny numeru konta (do kontrahenta)',
            'group'       => 'Umowy',
            'icon'        => 'bi-shield-lock',
            'auto'        => false,
            'description' => 'Wysyłany ręcznie przez pracownika (modules/sprawdz_konto/admin_send.php) — spersonalizowany link, pod którym kontrahent może zweryfikować oficjalny numer konta organizacji do wpłat (ochrona przed podmianą numeru konta na fakturze/w mailu).',
            'subject'     => 'Weryfikacja numeru konta do wpłat — {{org}}',
            'body'        => $sprawdz_konto_kontrahent_body,
            'vars'        => [
                'accent' => ['label' => 'Kolor akcentu',     'sample' => '#0d6efd'],
                'osoba'  => ['label' => 'Kontrahent',        'sample' => 'Jan Kowalski'],
                'link'   => ['label' => 'Link weryfikacyjny', 'sample' => $base . '/konto.php?t=abc123'],
                'org'    => ['label' => 'Nazwa organizacji', 'sample' => 'Fundacja FEER'],
            ],
        ],

        'ti_grade' => [
            'label'       => 'TI: nowa ocena (do kursanta)',
            'group'       => 'TI — Zajęcia',
            'icon'        => 'bi-award',
            'auto'        => true,
            'description' => 'Powiadomienie dla kursanta (i opiekuna małoletniego) o nowej lub zmienionej ocenie w e-dzienniku.',
            'subject'     => '{{org}}: nowa ocena — {{course_name}}',
            'body'        => $ti_grade_body,
            'vars'        => [
                'org'              => ['label' => 'Nazwa organizacji',  'sample' => 'Dydaktyka TI'],
                'course_name'      => ['label' => 'Nazwa kursu',        'sample' => 'Kurs obsługi komputera'],
                'value'            => ['label' => 'Ocena (tekst)',       'sample' => '5+'],
                'category_block'   => ['label' => 'Blok kategorii oceny (HTML lub pusty)', 'sample' => '<div style="font-size:.85em;color:#555;margin-top:4px">(zadanie domowe)</div>'],
                'description_block'=> ['label' => 'Blok opisu (HTML lub pusty)', 'sample' => '<p style="color:#555;font-size:.9em">Zadanie domowe: Wprowadzenie do internetu</p>'],
                'url'              => ['label' => 'Link do panelu kursanta', 'sample' => $base . '/karty30/ti/kursant/index.php?tab=oceny'],
            ],
        ],

        'ti_billing' => [
            'label'       => 'TI: rozliczenie miesięczne (do kursanta)',
            'group'       => 'TI — Zajęcia',
            'icon'        => 'bi-receipt',
            'auto'        => true,
            'description' => 'Zestawienie należności wysyłane po wystawieniu rozliczenia (faktura wystawiana osobno w systemie fakturującym).',
            'subject'     => 'Rozliczenie należności za zajęcia{{invoice_subject}} – {{surname}}',
            'body'        => $ti_billing_body,
            'vars'        => [
                'org'            => ['label' => 'Nazwa organizacji',      'sample' => 'Fundacja Edukacji Empatii Rozwoju „FEER”'],
                'client_name'    => ['label' => 'Imię i nazwisko beneficjenta', 'sample' => 'Jan Kowalski'],
                'surname'        => ['label' => 'Nazwisko beneficjenta',  'sample' => 'Kowalski'],
                'group_code'     => ['label' => 'Kod grupy',              'sample' => 'ANG.94826.Kowalski'],
                'invoice_subject'=> ['label' => 'Fragment tematu z numerem faktury (pusty, gdy brak faktury)', 'sample' => ' – faktura nr FV/123/2026'],
                'invoice_no'     => ['label' => 'Numer faktury',          'sample' => 'FV/123/2026'],
                'period_title'   => ['label' => 'Tytuł należności / okres', 'sample' => 'Angielski — sierpień 2026'],
                'amount_main'    => ['label' => 'Kwota za zajęcia',       'sample' => '200,00 PLN'],
                'extra_desc'     => ['label' => 'Opis opłat dodatkowych', 'sample' => 'brak'],
                'extra_amount'   => ['label' => 'Kwota opłat dodatkowych','sample' => '0,00 PLN'],
                'total'          => ['label' => 'Razem do zapłaty',       'sample' => '200,00 PLN'],
                'transfer_title' => ['label' => 'Tytuł przelewu',         'sample' => 'Faktura nr FV/123/2026 – Kowalski'],
                'portal'         => ['label' => 'URL panelu kursanta',    'sample' => $base . '/karty30/ti/kursant/index.php'],
            ],
        ],

        'ti_message' => [
            'label'       => 'TI: nowa wiadomość (do kursanta)',
            'group'       => 'TI — Zajęcia',
            'icon'        => 'bi-envelope',
            'auto'        => true,
            'description' => 'Powiadomienie dla kursanta o nowej wiadomości od prowadzącego w panelu kursanta.',
            'subject'     => '{{org}}: {{subject}}',
            'body'        => $ti_message_body,
            'vars'        => [
                'org'         => ['label' => 'Nazwa organizacji',    'sample' => 'Dydaktyka TI'],
                'name'        => ['label' => 'Imię kursanta',        'sample' => 'Anna'],
                'subject'     => ['label' => 'Temat wiadomości',     'sample' => 'Nowa wiadomość'],
                'preview_html'=> ['label' => 'Fragment treści wiadomości (HTML)', 'sample' => 'Proszę o zapoznanie się z materiałami przed następną lekcją.'],
                'url'         => ['label' => 'Link do panelu kursanta', 'sample' => $base . '/karty30/ti/kursant/index.php?tab=wiadomosci'],
            ],
        ],

        'ti_message_reply' => [
            'label'       => 'TI: odpowiedź kursanta (do prowadzącego)',
            'group'       => 'TI — Zajęcia',
            'icon'        => 'bi-reply',
            'auto'        => true,
            'description' => 'Powiadomienie do prowadzącego (ostatniego nadawcy w wątku) o odpowiedzi kursanta.',
            'subject'     => '{{org}}: odpowiedź kursanta — {{student_name}}',
            'body'        => $ti_message_reply_body,
            'vars'        => [
                'org'          => ['label' => 'Nazwa organizacji',  'sample' => 'Dydaktyka TI'],
                'student_name' => ['label' => 'Imię i nazwisko kursanta', 'sample' => 'Jan Kowalski'],
                'preview_html' => ['label' => 'Fragment odpowiedzi (HTML)', 'sample' => 'Zapoznałem się z materiałami, mam pytanie odnośnie ćwiczenia 3.'],
                'url'          => ['label' => 'Link do wątku wiadomości', 'sample' => $base . '/karty30/ti/messages.php?student=42'],
            ],
        ],

        // — Rekrutacja TI: zapowiedź startu zapisów na terminy ————————————
        'rk_round_open' => [
            'label'       => 'Rekrutacja TI — start zapisów',
            'group'       => 'TI — Zajęcia',
            'icon'        => 'bi-ticket-perforated',
            'auto'        => true,
            'description' => 'Zapowiedź tury zapisów na terminy za żetony. Każdy kursant dostaje osobisty link z tokenem (strona zapisów bez logowania). Wysyłka z pola „zapowiedź” tury albo ręcznie z panelu kierownika.',
            'subject'     => 'Zapisy na zajęcia — {{round}} — start {{opens_at}}',
            'body'        => <<<'HTML'
<p>Dzień dobry, <strong>{{name}}</strong>.</p>

<p>Ruszają <strong>zapisy na zajęcia</strong> w turze <strong>{{round}}</strong>.
Rejestracja otwiera się <strong>{{opens_at}}</strong> i trwa do {{closes_at}}.</p>

<div style="background:#f8f9fa;border-radius:8px;padding:12px 16px;margin:18px 0">
  <p style="margin:0 0 8px;font-weight:600">Jak zarezerwować zajęcia</p>
  <ol style="margin:0;padding-left:18px">
    <li>Otwórz swój osobisty link (przycisk poniżej) albo zaloguj się do panelu kursanta
        i wejdź w <em>Nauka &rsaquo; Zapisy na zajęcia</em>.</li>
    <li>Wybierz rodzaj zajęć i prowadzącego, a następnie pasujący termin z jego kalendarza.</li>
    <li>Kliknij <strong>„Rezerwuję”</strong> (pojedyncze zajęcia) albo
        <strong>„Ustal zajęcia na cały okres”</strong> — wtedy ten sam dzień tygodnia
        i godzina zostaną zarezerwowane co tydzień, aż do końca tury.</li>
  </ol>
  <p style="margin:8px 0 0;font-size:.9em;color:#6c757d">
    <strong>Ważne:</strong> data, którą Państwo wybiorą, to data <strong>pierwszych zajęć</strong> —
    przy rezerwacji na cały okres kolejne zajęcia odbywają się co tydzień o tej samej porze.
  </p>
</div>

<div style="background:#fff1e7;border-left:3px solid #c2410c;border-radius:4px;padding:12px 16px;margin:18px 0">
  <p style="margin:0 0 8px;font-weight:600">Zasady obowiązujące od tego roku</p>
  <ol style="margin:0;padding-left:18px">
    <li>Najpierw wybierasz <strong>rodzaj zajęć</strong>, potem <strong>prowadzącego</strong> i termin z jego kalendarza.</li>
    <li>Terminy wystawiają sami prowadzący — lista rośnie w trakcie tury.</li>
    <li>Każda rezerwacja kosztuje <strong>żetony</strong>. Twoje aktualne saldo: <strong>{{balance}}</strong>.</li>
    <li>Limit rezerwacji w tej turze: <strong>{{limit}}</strong>.</li>
    <li>Rezygnacja najpóźniej <strong>{{refund_h}} godz.</strong> przed zajęciami zwraca żetony w całości.</li>
    <li>Decyduje kolejność zgłoszeń — miejsce jest Twoje z chwilą potwierdzenia rezerwacji.</li>
  </ol>
</div>

<div style="background:#f8f9fa;border-radius:8px;padding:12px 16px;margin:18px 0">
  <p style="margin:0 0 8px;font-weight:600">Po co rejestracja żetonowa</p>
  <p style="margin:0 0 8px">
    Miejsc w grupach jest mniej niż chętnych, a dotychczas zdarzało się, że jedna osoba
    „na zapas” zajmowała miejsca w kilku grupach naraz i blokowała je innym. Żetony to
    porządkują: każda rezerwacja kosztuje, więc zapisujecie się Państwo na jedną–dwie grupy,
    w których faktycznie będziecie — a wolne miejsca zostają dla pozostałych.
  </p>
  <p style="margin:0">
    Żetony nie są pieniędzmi — to „bilety na zajęcia” przydzielone przez ośrodek na dany
    okres. Rezerwacja pobiera je od razu (miejsce jest wtedy gwarantowane), a odpowiednio
    wczesna rezygnacja zwraca w całości — można nimi opłacić inny termin. Saldo i historię
    widać w panelu kursanta (zakładka <em>Zapisy na zajęcia</em>) i na stronie z linku.
  </p>
</div>

{{rules_html}}

<div style="margin:24px 0;text-align:center">
  <a href="{{link}}" style="background:#c2410c;color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;display:inline-block;font-weight:600">
    Wybierz prowadzącego i termin &rarr;
  </a>
</div>

<p style="font-size:.8em;color:#6c757d;word-break:break-all">
  Jeśli przycisk nie działa, skopiuj ten adres do przeglądarki:<br>
  <a href="{{link}}">{{link}}</a>
</p>

<p style="font-size:.85em;color:#6c757d">
  Powyższy link jest przypisany do Ciebie — nie przekazuj go dalej. Wygasa po zamknięciu tury.
  Jeśli masz konto w panelu kursanta, zapisy znajdziesz również po zalogowaniu,
  w zakładce „Nauka &rsaquo; Zapisy na zajęcia&rdquo;.
</p>
HTML,
            'vars'        => [
                'org'        => ['label' => 'Nazwa organizacji',              'sample' => 'Dydaktyka TI'],
                'name'       => ['label' => 'Imię i nazwisko kursanta',       'sample' => 'Jan Kowalski'],
                'round'      => ['label' => 'Nazwa tury zapisów',             'sample' => 'Konsultacje 2026/Q4'],
                'opens_at'   => ['label' => 'Start zapisów',                  'sample' => '01.10.2026 12:00'],
                'closes_at'  => ['label' => 'Koniec zapisów',                 'sample' => '15.10.2026 23:59'],
                'balance'    => ['label' => 'Saldo żetonów kursanta',         'sample' => '6'],
                'limit'      => ['label' => 'Limit rezerwacji w turze',       'sample' => '2'],
                'refund_h'   => ['label' => 'Okno pełnego zwrotu (godziny)',  'sample' => '24'],
                'rules_html' => ['label' => 'Dodatkowe zasady tury (HTML)',   'sample' => ''],
                'link'       => ['label' => 'Osobisty link z tokenem',        'sample' => $base . '/karty30/ti/rekrutacja/t.php?t=a1b2c3d4e5f6.…'],
            ],
        ],

        // — Rekrutacja TI: zatwierdzenie rezerwacji małoletniego przez rodzica —
        'rk_parent_confirm' => [
            'label'       => 'Rekrutacja TI — zatwierdzenie rodzica',
            'group'       => 'TI — Zajęcia',
            'icon'        => 'bi-shield-check',
            'auto'        => true,
            'description' => 'Prośba do rodzica/opiekuna o zatwierdzenie rezerwacji terminu przez małoletniego kursanta. Rodzic dostaje też SMS informacyjny. Bez decyzji rezerwacja wygasa z pełnym zwrotem żetonów.',
            'subject'     => 'Prośba o zatwierdzenie rezerwacji — {{student}}',
            'body'        => <<<'HTML'
<p>{{guardian}},</p>

<p>kursant <strong>{{student}}</strong> zapisał(a) się na zajęcia i prosimy Państwa
o zatwierdzenie tej rezerwacji:</p>

<table style="background:#f8f9fa;border-radius:8px;width:100%;margin:16px 0;border-collapse:collapse">
  <tr><td style="padding:6px 14px;color:#6c757d;width:130px;font-size:.9em">Termin</td>
      <td style="padding:6px 14px"><strong>{{when}}</strong></td></tr>
  <tr><td style="padding:6px 14px;color:#6c757d;font-size:.9em">Prowadzący</td>
      <td style="padding:6px 14px">{{instructor}}</td></tr>
  <tr><td style="padding:6px 14px;color:#6c757d;font-size:.9em">Zajęcia</td>
      <td style="padding:6px 14px">{{subject}}</td></tr>
  <tr><td style="padding:6px 14px;color:#6c757d;font-size:.9em">Koszt</td>
      <td style="padding:6px 14px">{{tokens}} żeton(y) z puli kursanta</td></tr>
</table>

{{series_info}}

<p>Rezerwacja stanie się ostateczna po Państwa zgodzie. Bez decyzji wygaśnie
automatycznie po <strong>{{hours}} godzinach</strong> od zapisu, a żetony wrócą
w całości na konto kursanta.</p>

<div style="margin:24px 0;text-align:center">
  <a href="{{link}}" style="background:#c2410c;color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;display:inline-block;font-weight:600">
    Zatwierdź lub odrzuć rezerwację &rarr;
  </a>
</div>

<p style="font-size:.8em;color:#6c757d;word-break:break-all">
  Jeśli przycisk nie działa, skopiuj ten adres do przeglądarki:<br>
  <a href="{{link}}">{{link}}</a>
</p>

<p style="font-size:.85em;color:#6c757d">
  Link jest jednorazowy i przypisany do tej rezerwacji. Wiadomość wysłana automatycznie
  przez system {{org}} — o wysyłce informujemy również SMS-em.
</p>
HTML,
            'vars'        => [
                'org'        => ['label' => 'Nazwa organizacji',            'sample' => 'Dydaktyka TI'],
                'guardian'   => ['label' => 'Imię i nazwisko opiekuna',     'sample' => 'Anna Kowalska'],
                'student'    => ['label' => 'Imię i nazwisko kursanta',     'sample' => 'Jan Kowalski'],
                'when'       => ['label' => 'Termin zajęć',                 'sample' => '05.10.2026 16:00–17:00'],
                'instructor' => ['label' => 'Prowadzący',                   'sample' => 'Marek Nowak'],
                'subject'    => ['label' => 'Temat/rodzaj zajęć',           'sample' => 'konsultacja projektowa'],
                'tokens'     => ['label' => 'Koszt w żetonach',             'sample' => '1'],
                'hours'      => ['label' => 'Godziny na decyzję',           'sample' => '48'],
                'series_info'=> ['label' => 'Blok informacji o serii (HTML, pusty dla pojedynczych)', 'sample' => ''],
                'link'       => ['label' => 'Link zatwierdzenia (token)',   'sample' => $base . '/karty30/ti/rekrutacja/potwierdz.php?t=…'],
            ],
        ],

        // — Rekrutacja TI: wpis na zajęcia do zatwierdzenia (prowadzący) ————
        'rk_pending_instructor' => [
            'label'       => 'Rekrutacja TI — wpis do zatwierdzenia (prowadzący)',
            'group'       => 'TI — Zajęcia',
            'icon'        => 'bi-person-check',
            'auto'        => true,
            'description' => 'Informacja dla prowadzącego o nowym wpisie na jego zajęcia, czekającym na zatwierdzenie (pierwszy stopień). Wysyłana z SMS-em (fallback e-mail).',
            'subject'     => 'Wpis na zajęcia do zatwierdzenia — {{student}}, {{when}}',
            'body'        => <<<'HTML'
<p>Dzień dobry, <strong>{{name}}</strong>.</p>
<p>Jest nowy wpis na Twoje zajęcia i czeka na Twoje zatwierdzenie:</p>
<table style="background:#f8f9fa;border-radius:8px;width:100%;margin:16px 0;border-collapse:collapse">
  <tr><td style="padding:6px 14px;color:#6c757d;width:130px;font-size:.9em">Kursant</td><td style="padding:6px 14px"><strong>{{student}}</strong></td></tr>
  <tr><td style="padding:6px 14px;color:#6c757d;font-size:.9em">Termin</td><td style="padding:6px 14px"><strong>{{when}}</strong></td></tr>
  <tr><td style="padding:6px 14px;color:#6c757d;font-size:.9em">Zajęcia</td><td style="padding:6px 14px">{{subject}}</td></tr>
  <tr><td style="padding:6px 14px;color:#6c757d;font-size:.9em">Tura</td><td style="padding:6px 14px">{{round}}</td></tr>
</table>
<p>Po Twoim zatwierdzeniu wpis trafi jeszcze do kierownika. Do decyzji miejsce jest
wstępnie zarezerwowane, a żetony kursanta pobrane.</p>
<div style="margin:24px 0;text-align:center">
  <a href="{{panel_url}}" style="background:#c2410c;color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;display:inline-block;font-weight:600">
    Zatwierdź w panelu &rarr;
  </a>
</div>
<p style="font-size:.8em;color:#6c757d;word-break:break-all">Jeśli przycisk nie działa: <a href="{{panel_url}}">{{panel_url}}</a></p>
HTML,
            'vars'        => [
                'org'        => ['label' => 'Nazwa organizacji',        'sample' => 'Dydaktyka TI'],
                'name'       => ['label' => 'Imię i nazwisko prowadzącego', 'sample' => 'Marek Nowak'],
                'student'    => ['label' => 'Imię i nazwisko kursanta', 'sample' => 'Jan Kowalski'],
                'when'       => ['label' => 'Termin (z liczbą serii)',  'sample' => '05.10.2026 16:00–17:00 (seria: 12 terminów)'],
                'instructor' => ['label' => 'Prowadzący terminu',       'sample' => 'Marek Nowak'],
                'subject'    => ['label' => 'Temat/rodzaj zajęć',       'sample' => 'konsultacja projektowa'],
                'round'      => ['label' => 'Nazwa tury',               'sample' => 'Semestr Z 2026 K1-482'],
                'panel_url'  => ['label' => 'Link do panelu zatwierdzeń', 'sample' => $base . '/karty30/ti/dydaktyk/rekrutacja.php'],
            ],
        ],

        // — Rekrutacja TI: wpis na zajęcia do zatwierdzenia (kierownik) ————
        'rk_pending_staff' => [
            'label'       => 'Rekrutacja TI — wpis do zatwierdzenia (kierownik)',
            'group'       => 'TI — Zajęcia',
            'icon'        => 'bi-person-gear',
            'auto'        => true,
            'description' => 'Informacja dla kierownika: prowadzący zatwierdził wpis na zajęcia, potrzebne zatwierdzenie końcowe. Adres i telefon kierownika ustawia się w Zapisach na zajęcia → Ustawienia.',
            'subject'     => 'Wpis na zajęcia — zatwierdzenie końcowe: {{student}}, {{when}}',
            'body'        => <<<'HTML'
<p>Dzień dobry.</p>
<p>Prowadzący <strong>{{instructor}}</strong> zatwierdził wpis na zajęcia —
czeka on teraz na zatwierdzenie końcowe kierownika:</p>
<table style="background:#f8f9fa;border-radius:8px;width:100%;margin:16px 0;border-collapse:collapse">
  <tr><td style="padding:6px 14px;color:#6c757d;width:130px;font-size:.9em">Kursant</td><td style="padding:6px 14px"><strong>{{student}}</strong></td></tr>
  <tr><td style="padding:6px 14px;color:#6c757d;font-size:.9em">Termin</td><td style="padding:6px 14px"><strong>{{when}}</strong></td></tr>
  <tr><td style="padding:6px 14px;color:#6c757d;font-size:.9em">Zajęcia</td><td style="padding:6px 14px">{{subject}}</td></tr>
  <tr><td style="padding:6px 14px;color:#6c757d;font-size:.9em">Tura</td><td style="padding:6px 14px">{{round}}</td></tr>
</table>
<div style="margin:24px 0;text-align:center">
  <a href="{{panel_url}}" style="background:#c2410c;color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;display:inline-block;font-weight:600">
    Zatwierdź w panelu &rarr;
  </a>
</div>
<p style="font-size:.8em;color:#6c757d;word-break:break-all">Jeśli przycisk nie działa: <a href="{{panel_url}}">{{panel_url}}</a></p>
HTML,
            'vars'        => [
                'org'        => ['label' => 'Nazwa organizacji',        'sample' => 'Dydaktyka TI'],
                'name'       => ['label' => 'Adresat',                  'sample' => 'Kierownik'],
                'student'    => ['label' => 'Imię i nazwisko kursanta', 'sample' => 'Jan Kowalski'],
                'when'       => ['label' => 'Termin (z liczbą serii)',  'sample' => '05.10.2026 16:00–17:00'],
                'instructor' => ['label' => 'Prowadzący terminu',       'sample' => 'Marek Nowak'],
                'subject'    => ['label' => 'Temat/rodzaj zajęć',       'sample' => 'konsultacja projektowa'],
                'round'      => ['label' => 'Nazwa tury',               'sample' => 'Semestr Z 2026 K1-482'],
                'panel_url'  => ['label' => 'Link do panelu zatwierdzeń', 'sample' => $base . '/karty30/ti/dydaktyk/rekrutacja.php?tab=zapisy'],
            ],
        ],

        // — Rekrutacja TI: zwolniło się miejsce (lista oczekujących) ————————
        'rk_waitlist_slot_free' => [
            'label'       => 'Rekrutacja TI — zwolniło się miejsce (lista oczekujących)',
            'group'       => 'TI — Zajęcia',
            'icon'        => 'bi-bell',
            'auto'        => true,
            'description' => 'Wysyłane do pierwszej osoby z listy oczekujących, gdy na wcześniej pełnym terminie zwolni się miejsce (ktoś zrezygnował). Miejsce NIE jest rezerwowane automatycznie — trzeba zapisać się samodzielnie w panelu.',
            'subject'     => 'Zwolniło się miejsce — {{when}}',
            'body'        => <<<'HTML'
<p>Dzień dobry, <strong>{{name}}</strong>.</p>
<p>Byłeś(-aś) na liście oczekujących na termin, który był pełny — właśnie zwolniło się na nim miejsce:</p>
<table style="background:#f8f9fa;border-radius:8px;width:100%;margin:16px 0;border-collapse:collapse">
  <tr><td style="padding:6px 14px;color:#6c757d;width:130px;font-size:.9em">Termin</td><td style="padding:6px 14px"><strong>{{when}}</strong></td></tr>
  <tr><td style="padding:6px 14px;color:#6c757d;font-size:.9em">Zajęcia</td><td style="padding:6px 14px">{{subject}}</td></tr>
</table>
<p>Miejsce <strong>nie jest</strong> zarezerwowane automatycznie — może je zająć ktoś inny, jeśli będzie szybszy.
Zaloguj się i zapisz, dopóki jest wolne:</p>
<div style="margin:24px 0;text-align:center">
  <a href="{{panel_url}}" style="background:#c2410c;color:#fff;padding:12px 28px;border-radius:8px;text-decoration:none;display:inline-block;font-weight:600">
    Zapisz się teraz &rarr;
  </a>
</div>
<p style="font-size:.8em;color:#6c757d;word-break:break-all">Jeśli przycisk nie działa: <a href="{{panel_url}}">{{panel_url}}</a></p>
HTML,
            'vars'        => [
                'org'       => ['label' => 'Nazwa organizacji',   'sample' => 'Dydaktyka TI'],
                'name'      => ['label' => 'Imię i nazwisko kursanta', 'sample' => 'Jan Kowalski'],
                'when'      => ['label' => 'Termin',              'sample' => '05.10.2026 16:00–17:00'],
                'subject'   => ['label' => 'Temat/rodzaj zajęć',  'sample' => 'konsultacja projektowa'],
                'panel_url' => ['label' => 'Link do zapisów w panelu kursanta', 'sample' => $base . '/karty30/ti/kursant/index.php?tab=zapisy'],
            ],
        ],
    ];

    return $reg;
}

/** Czy klucz istnieje w rejestrze. */
function email_tpl_exists(string $key): bool
{
    return array_key_exists($key, email_tpl_registry());
}

/**
 * Czy szablon jest wysyłany automatycznie (cron). Dla takich szablonów
 * przełącznik „aktywny" faktycznie wstrzymuje wysyłkę. Maile inicjowane
 * ręcznie przez administratora ignorują flagę (zawsze wychodzą).
 */
function email_tpl_is_auto(string $key): bool
{
    $reg = email_tpl_registry();
    return !empty($reg[$key]['auto']);
}

/**
 * Zwraca aktualny szablon (nadpisanie z bazy lub domyślny z rejestru).
 * @return array{subject:string, body:string, enabled:bool, is_custom:bool}|null
 */
function email_tpl_get(string $key): ?array
{
    $reg = email_tpl_registry();
    if (!isset($reg[$key])) return null;
    email_tpl_migrate();

    $def = $reg[$key];
    $row = null;
    try {
        $row = db_one("SELECT subject, body_html, enabled FROM email_templates WHERE key_=?", [$key]);
    } catch (\Throwable $e) {}

    if ($row) {
        return [
            'subject'   => $row['subject'] !== '' ? $row['subject'] : $def['subject'],
            'body'      => $row['body_html'] !== '' ? $row['body_html'] : $def['body'],
            'enabled'   => (int)$row['enabled'] === 1,
            'is_custom' => true,
        ];
    }
    return [
        'subject'   => $def['subject'],
        'body'      => $def['body'],
        'enabled'   => true,
        'is_custom' => false,
    ];
}

/** Podstawia {{nazwa}} (spacje tolerowane) wartościami z $vars. */
function email_tpl_substitute(string $tpl, array $vars): string
{
    return preg_replace_callback('/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/', function ($m) use ($vars) {
        return array_key_exists($m[1], $vars) ? (string)$vars[$m[1]] : $m[0];
    }, $tpl);
}

/**
 * Renderuje mail systemowy: zwraca temat i treść HTML z podstawionymi zmiennymi.
 * Fallback do domyślnej treści, gdy szablon nie istnieje w bazie.
 * Treści wewnętrzne (bez DOCTYPE/html) owijane są automatycznie przez
 * _email_tpl_default_wrap(); pełne szablony HTML (np. guardian_consent) przechodzą bez zmian.
 *
 * @return array{subject:string, html:string, enabled:bool}
 */
function email_tpl_render(string $key, array $vars = []): array
{
    $t = email_tpl_get($key);
    if ($t === null) {
        return ['subject' => '', 'html' => '', 'enabled' => true];
    }
    $subj    = email_tpl_substitute($t['subject'], $vars);
    $html    = email_tpl_substitute($t['body'], $vars);
    $trimmed = ltrim($html);
    if (stripos($trimmed, '<!DOCTYPE') !== 0 && stripos($trimmed, '<html') !== 0) {
        $html = _email_tpl_default_wrap($html, $subj);
    }
    return ['subject' => $subj, 'html' => $html, 'enabled' => $t['enabled']];
}

/** Zapisuje nadpisanie szablonu. */
function email_tpl_save(string $key, string $subject, string $body, bool $enabled, ?int $user_id = null): bool
{
    if (!email_tpl_exists($key)) return false;
    email_tpl_migrate();
    db()->prepare(
        "INSERT INTO email_templates (key_, subject, body_html, enabled, updated_at, updated_by)
         VALUES (?,?,?,?,datetime('now','localtime'),?)
         ON CONFLICT(key_) DO UPDATE SET
            subject=excluded.subject, body_html=excluded.body_html,
            enabled=excluded.enabled, updated_at=excluded.updated_at, updated_by=excluded.updated_by"
    )->execute([$key, $subject, $body, $enabled ? 1 : 0, $user_id]);
    return true;
}

/** Przywraca szablon do domyślnej treści (usuwa nadpisanie). */
function email_tpl_reset(string $key): void
{
    email_tpl_migrate();
    try {
        db()->prepare("DELETE FROM email_templates WHERE key_=?")->execute([$key]);
    } catch (\Throwable $e) {}
}

/** Mapuje zmienne rejestru na ich przykładowe wartości (do podglądu / testu). */
function email_tpl_sample_vars(string $key): array
{
    $reg = email_tpl_registry();
    if (!isset($reg[$key])) return [];
    $out = [];
    foreach ($reg[$key]['vars'] as $name => $meta) {
        $out[$name] = $meta['sample'] ?? '';
    }
    return $out;
}
