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
<html><body style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px;color:#212529">
<div style="background:linear-gradient(135deg,{{accent}},{{accent}}cc);padding:22px 26px;border-radius:10px 10px 0 0">
  <h2 style="color:#fff;margin:0;font-size:1.15rem">🔑 Dane logowania — {{org}}</h2>
</div>
<div style="border:1px solid #dee2e6;border-top:none;padding:26px;border-radius:0 0 10px 10px">
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
</div></body></html>
HTML;

    // — Przypomnienie o wygaśnięciu umowy ————————————————————————
    $expiry_body = <<<'HTML'
<!DOCTYPE html>
<html lang="pl">
<head><meta charset="UTF-8"><title>Przypomnienie o wygaśnięciu umowy</title></head>
<body style="margin:0;padding:0;background:#f4f6f8;font-family:Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f8;padding:32px 0;">
    <tr><td align="center">
      <table width="600" cellpadding="0" cellspacing="0"
             style="background:#ffffff;border-radius:8px;overflow:hidden;
                    box-shadow:0 2px 8px rgba(0,0,0,.08);max-width:600px;">
        <tr>
          <td style="background:{{accent}};padding:24px 32px;">
            <p style="margin:0;font-size:13px;color:rgba(255,255,255,.8);text-transform:uppercase;
                      letter-spacing:.05em;">System zarządzania umowami</p>
            <h1 style="margin:6px 0 0;font-size:22px;color:#ffffff;font-weight:700;">
              Przypomnienie o wygasającej umowie
            </h1>
          </td>
        </tr>
        <tr>
          <td style="padding:32px;">
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
          </td>
        </tr>
        <tr>
          <td style="background:#f8f9fa;padding:16px 32px;border-top:1px solid #e9ecef;">
            <p style="margin:0;font-size:12px;color:#adb5bd;text-align:center;">
              Wiadomość wygenerowana automatycznie przez system zarządzania umowami NGO.<br>
              Prosimy nie odpowiadać na tę wiadomość.
            </p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;

    // — Przypomnienie ZUS ———————————————————————————————————————
    $zus_body = <<<'HTML'
<!DOCTYPE html>
<html lang="pl">
<head><meta charset="UTF-8"><title>Przypomnienie ZUS</title></head>
<body style="margin:0;padding:0;background:#f4f6f8;font-family:Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f8;padding:32px 0;">
    <tr><td align="center">
      <table width="600" cellpadding="0" cellspacing="0"
             style="background:#ffffff;border-radius:8px;overflow:hidden;
                    box-shadow:0 2px 8px rgba(0,0,0,.08);max-width:600px;">
        <tr>
          <td style="background:{{accent}};padding:24px 32px;">
            <p style="margin:0;font-size:13px;color:rgba(255,255,255,.8);text-transform:uppercase;
                      letter-spacing:.05em;">System zarządzania umowami</p>
            <h1 style="margin:6px 0 0;font-size:22px;color:#ffffff;font-weight:700;">
              Przypomnienie ZUS: {{tytul}}
            </h1>
          </td>
        </tr>
        <tr>
          <td style="padding:32px;">
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
          </td>
        </tr>
        <tr>
          <td style="background:#f8f9fa;padding:16px 32px;border-top:1px solid #e9ecef;">
            <p style="margin:0;font-size:12px;color:#adb5bd;text-align:center;">
              Wiadomość wygenerowana automatycznie przez system zarządzania umowami NGO.<br>
              Prosimy nie odpowiadać na tę wiadomość.
            </p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;

    // — Okresowa weryfikacja wolontariusza niepełnoletniego ——————————————
    $minor_verif_body = <<<'HTML'
<!DOCTYPE html>
<html lang="pl">
<head><meta charset="UTF-8"><title>Okresowa weryfikacja wolontariatu</title></head>
<body style="margin:0;padding:0;background:#f4f6f8;font-family:Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f8;padding:32px 0;">
    <tr><td align="center">
      <table width="600" cellpadding="0" cellspacing="0"
             style="background:#ffffff;border-radius:8px;overflow:hidden;
                    box-shadow:0 2px 8px rgba(0,0,0,.08);max-width:600px;">
        <tr>
          <td style="background:{{accent}};padding:24px 32px;">
            <p style="margin:0;font-size:13px;color:rgba(255,255,255,.8);text-transform:uppercase;
                      letter-spacing:.05em;">System zarządzania umowami</p>
            <h1 style="margin:6px 0 0;font-size:22px;color:#ffffff;font-weight:700;">
              Okresowa weryfikacja wolontariatu
            </h1>
          </td>
        </tr>
        <tr>
          <td style="padding:32px;">
            <p style="margin:0 0 16px;font-size:15px;color:#333333;">
              Szanowni Państwo{{greeting}},
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
          </td>
        </tr>
        <tr>
          <td style="background:#f8f9fa;padding:16px 32px;border-top:1px solid #e9ecef;">
            <p style="margin:0;font-size:12px;color:#adb5bd;text-align:center;">
              Wiadomość wygenerowana automatycznie przez system zarządzania umowami NGO.<br>
              Prosimy nie odpowiadać na tę wiadomość.
            </p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;

    // — Okresowa weryfikacja wolontariusza niepełnoletniego — powiadomienie admina —
    $minor_verif_admin_body = <<<'HTML'
<!DOCTYPE html>
<html lang="pl">
<head><meta charset="UTF-8"><title>Do wysłania: pismo ws. weryfikacji</title></head>
<body style="margin:0;padding:0;background:#f4f6f8;font-family:Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f6f8;padding:32px 0;">
    <tr><td align="center">
      <table width="600" cellpadding="0" cellspacing="0"
             style="background:#ffffff;border-radius:8px;overflow:hidden;
                    box-shadow:0 2px 8px rgba(0,0,0,.08);max-width:600px;">
        <tr>
          <td style="background:{{accent}};padding:24px 32px;">
            <p style="margin:0;font-size:13px;color:rgba(255,255,255,.8);text-transform:uppercase;
                      letter-spacing:.05em;">System zarządzania umowami</p>
            <h1 style="margin:6px 0 0;font-size:22px;color:#ffffff;font-weight:700;">
              Wymagane pismo — weryfikacja wolontariusza niepełnoletniego
            </h1>
          </td>
        </tr>
        <tr>
          <td style="padding:32px;">
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
          </td>
        </tr>
        <tr>
          <td style="background:#f8f9fa;padding:16px 32px;border-top:1px solid #e9ecef;">
            <p style="margin:0;font-size:12px;color:#adb5bd;text-align:center;">
              Wiadomość wygenerowana automatycznie przez system zarządzania umowami NGO.<br>
              Prosimy nie odpowiadać na tę wiadomość.
            </p>
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;

    // — Kod odzyskiwania dostępu ————————————————————————————————
    $recovery_body = <<<'HTML'
<html><body style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px;color:#212529">
<div style="background:linear-gradient(135deg,#0ea5e9,#0284c7);padding:22px 26px;border-radius:10px 10px 0 0">
  <h2 style="color:#fff;margin:0;font-size:1.15rem">🔐 Kod odzyskiwania dostępu — {{org}}</h2>
</div>
<div style="border:1px solid #dee2e6;border-top:none;padding:26px;border-radius:0 0 10px 10px">
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
</div></body></html>
HTML;

    // ── TI: powiadomienie dydaktyczne (materiał / zadanie) ————————————
    $ti_dydaktyka_body = <<<'HTML'
<html><body style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px;color:#212529">
<div style="background:linear-gradient(135deg,#4338ca,#6366f1);padding:22px 26px;border-radius:10px 10px 0 0">
  <h2 style="color:#fff;margin:0;font-size:1.1rem">📚 {{org}} — aktualizacja w panelu kursanta</h2>
</div>
<div style="border:1px solid #dee2e6;border-top:none;padding:26px;border-radius:0 0 10px 10px">
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
</div></body></html>
HTML;

    // ── TI: prośba o odwołanie lekcji (do prowadzącego) ───────────────
    $ti_cancel_req_body = <<<'HTML'
<html><body style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px;color:#212529">
<div style="background:linear-gradient(135deg,#d97706,#f59e0b);padding:22px 26px;border-radius:10px 10px 0 0">
  <h2 style="color:#fff;margin:0;font-size:1.1rem">⚠️ {{org}} — prośba o odwołanie lekcji</h2>
</div>
<div style="border:1px solid #dee2e6;border-top:none;padding:26px;border-radius:0 0 10px 10px">
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
</div></body></html>
HTML;

    // ── TI: decyzja prowadzącego ws. odwołania (do kursanta) ─────────
    $ti_cancel_decision_body = <<<'HTML'
<html><body style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px;color:#212529">
<div style="background:{{header_color}};padding:22px 26px;border-radius:10px 10px 0 0">
  <h2 style="color:#fff;margin:0;font-size:1.1rem">{{header_icon}} {{org}} — {{header_title}}</h2>
</div>
<div style="border:1px solid #dee2e6;border-top:none;padding:26px;border-radius:0 0 10px 10px">
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
</div></body></html>
HTML;

    // ── TI: nowa ocena (do kursanta) ──────────────────────────────────
    $ti_grade_body = <<<'HTML'
<html><body style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px;color:#212529">
<div style="background:linear-gradient(135deg,#0891b2,#06b6d4);padding:22px 26px;border-radius:10px 10px 0 0">
  <h2 style="color:#fff;margin:0;font-size:1.1rem">🎓 {{org}} — nowa ocena</h2>
</div>
<div style="border:1px solid #dee2e6;border-top:none;padding:26px;border-radius:0 0 10px 10px">
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
</div></body></html>
HTML;

    // ── TI: nieobecność bez zgłoszenia (no-show) ─────────────────────
    $ti_no_show_body = <<<'HTML'
<html><body style="font-family:sans-serif;font-size:15px;line-height:1.6;color:#222;max-width:600px;margin:0 auto;padding:24px">
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
</body></html>
HTML;

    // ── TI: rozliczenie miesięczne (do kursanta) ──────────────────────
    $ti_billing_body = <<<'HTML'
<html><body style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px;color:#212529">
<div style="background:linear-gradient(135deg,#059669,#10b981);padding:22px 26px;border-radius:10px 10px 0 0">
  <h2 style="color:#fff;margin:0;font-size:1.1rem">💳 Rozliczenie za {{period}} — {{org}}</h2>
</div>
<div style="border:1px solid #dee2e6;border-top:none;padding:26px;border-radius:0 0 10px 10px">
  <p>Dzień dobry{{name_suffix}},</p>
  <p>Wystawiliśmy rozliczenie za zajęcia ({{org}}) za okres <strong>{{period}}</strong>.</p>
  <div style="background:#f8f9fa;border-left:3px solid #059669;border-radius:4px;padding:14px 18px;margin:14px 0">
    {{details_html}}
  </div>
  <p style="font-size:.9em;color:#495057">
    Szczegóły i historia rozliczeń w panelu kursanta:
    <a href="{{portal}}" style="color:#059669">{{portal}}</a>
  </p>
  <div style="margin:20px 0;text-align:center">
    <a href="{{portal}}" style="background:#059669;color:#fff;padding:11px 26px;border-radius:8px;text-decoration:none;display:inline-block;font-weight:600">
      Otwórz panel kursanta →
    </a>
  </div>
  <p style="font-size:.8em;color:#6c757d;margin-top:20px;padding-top:12px;border-top:1px solid #dee2e6">
    Wiadomość wygenerowana automatycznie.
  </p>
</div></body></html>
HTML;

    // ── TI: nowa wiadomość od prowadzącego (do kursanta) ──────────────
    $ti_message_body = <<<'HTML'
<html><body style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px;color:#212529">
<div style="background:linear-gradient(135deg,#1d4ed8,#2563eb);padding:22px 26px;border-radius:10px 10px 0 0">
  <h2 style="color:#fff;margin:0;font-size:1.1rem">✉️ {{org}} — nowa wiadomość</h2>
</div>
<div style="border:1px solid #dee2e6;border-top:none;padding:26px;border-radius:0 0 10px 10px">
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
</div></body></html>
HTML;

    // ── TI: odpowiedź kursanta (do prowadzącego) ──────────────────────
    $ti_message_reply_body = <<<'HTML'
<html><body style="font-family:sans-serif;max-width:600px;margin:0 auto;padding:20px;color:#212529">
<div style="background:linear-gradient(135deg,#16a34a,#22c55e);padding:22px 26px;border-radius:10px 10px 0 0">
  <h2 style="color:#fff;margin:0;font-size:1.1rem">↩️ {{org}} — odpowiedź kursanta</h2>
</div>
<div style="border:1px solid #dee2e6;border-top:none;padding:26px;border-radius:0 0 10px 10px">
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
</div></body></html>
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
                'days_label'       => ['label' => 'Odmiana „dni”',      'sample' => 'dni'],
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
                'greeting'         => ['label' => 'Zwrot grzecznościowy (np. ", Anna Nowak")', 'sample' => ', <strong>Anna Nowak</strong>'],
                'osoba'            => ['label' => 'Wolontariusz (niepełnoletni)', 'sample' => 'Jan Kowalski'],
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
                'header_color'  => ['label' => 'Kolor nagłówka (hex CSS)',    'sample' => 'linear-gradient(135deg,#16a34a,#22c55e)'],
                'header_icon'   => ['label' => 'Emoji/ikona nagłówka',        'sample' => '✅'],
                'header_title'  => ['label' => 'Tytuł nagłówka',              'sample' => 'odwołanie potwierdzone'],
                'lead_html'     => ['label' => 'Główna treść (HTML)',          'sample' => 'Twoja prośba o odwołanie udziału w lekcji <strong>Kurs obsługi komputera</strong> (30.06.2026 o 10:00) została <strong>potwierdzona</strong>.'],
                'url'           => ['label' => 'Link do panelu kursanta',      'sample' => $base . '/karty30/ti/kursant/index.php?tab=lekcje'],
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
            'description' => 'Powiadomienie dla kursanta o wystawieniu rozliczenia miesięcznego za zajęcia TI.',
            'subject'     => 'Rozliczenie za {{period}} — {{org}}',
            'body'        => $ti_billing_body,
            'vars'        => [
                'org'         => ['label' => 'Nazwa organizacji',         'sample' => 'Dydaktyka TI'],
                'name_suffix' => ['label' => 'Imię odbiorcy z przecinkiem (np. ", Jan")', 'sample' => ', Jan'],
                'period'      => ['label' => 'Okres rozliczenia',         'sample' => 'czerwiec 2026'],
                'details_html'=> ['label' => 'Tabela szczegółów płatności (HTML)', 'sample' => '<table style="border-collapse:collapse;width:100%"><tr><td style="padding:4px 12px 4px 0;color:#555">Kwota do zapłaty:</td><td><strong>120,00 zł</strong></td></tr><tr><td style="padding:4px 12px 4px 0;color:#555">Termin płatności:</td><td><strong>15.07.2026</strong></td></tr><tr><td style="padding:4px 12px 4px 0;color:#555">Nr konta:</td><td>12 3456 7890 1234 5678</td></tr></table>'],
                'portal'      => ['label' => 'URL panelu kursanta',       'sample' => $base . '/karty30/ti/kursant/index.php'],
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
 *
 * @return array{subject:string, html:string, enabled:bool}
 */
function email_tpl_render(string $key, array $vars = []): array
{
    $t = email_tpl_get($key);
    if ($t === null) {
        return ['subject' => '', 'html' => '', 'enabled' => true];
    }
    return [
        'subject' => email_tpl_substitute($t['subject'], $vars),
        'html'    => email_tpl_substitute($t['body'], $vars),
        'enabled' => $t['enabled'],
    ];
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
