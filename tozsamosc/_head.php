<?php
/**
 * tozsamosc/_head.php — Wspólny layout (chrome) podsystemu Tożsamość.
 *
 * ODRĘBNY od chrome SZO (brak navbara/menu SZO). Wymaga zalogowanego usera
 * ($user / current_user()).
 *
 * Warstwa wizualna (2026-11-11, „Konto Microsoft” — wzór myaccount.microsoft.com, Fluent 2):
 *   • biały nagłówek 48 px: marka „System Tożsamości”, „Wróć do SZO”, awatar z menu konta;
 *   • lewe menu sekcji (260 px, sticky; poniżej 1024 px — szuflada z przyciskiem ☰);
 *   • treść na jasnym tle #faf9f8, białe karty z cieniem i zaokrągleniem 8 px, Segoe UI 14 px.
 *
 * Zmienne wejściowe:
 *   $PAGE_TITLE  — tytuł karty przeglądarki (opcjonalnie);
 *   $TZ_ACTIVE   — aktywna pozycja menu: 'konto' | 'mfa' | 'administracja' | 'uzytkownicy' | '';
 *   $TZ_SHELL    — tylko index.php: ['tabs' => [klucze sekcji], 'def' => domyślna, 'force' => bool].
 *                  Gdy podane, pozycje menu prowadzące do sekcji index.php przełączają je w miejscu
 *                  (Alpine `tab` / `go()` dostępne w całej treści strony), #hash zostaje w adresie.
 *
 * Tailwind (CDN, prefiks `tw-`, bez preflightu — każde `tw-border*` wymaga `tw-border-solid`)
 * i Bootstrap (formularze, offcanvasy, modale na stronach admin/ pod TZ_*_CHROME) zostają.
 * Klasy `.tz-*` poniżej są WSPÓLNYM kontraktem stron podsystemu (mfa.php, access.php, ldap.php,
 * step_up.php, ti_role.php, admin/*.php) — nie usuwać ani nie zmieniać nazw.
 */
if (!function_exists('current_user')) { http_response_code(500); die('layout: brak auth'); }
$__u    = current_user() ?? [];
$__org  = defined('ORG_NAME') ? ORG_NAME : '';
$__name = $__u['name'] ?? ($__u['email'] ?? 'Użytkownik');
$__init = mb_strtoupper(mb_substr($__name, 0, 1));
if (preg_match('/\s(\S)/u', $__name, $__m)) $__init .= mb_strtoupper($__m[1]);
$__title = isset($PAGE_TITLE) ? ($PAGE_TITLE . ' — ') : '';
$TZ_ACTIVE = $TZ_ACTIVE ?? '';
$TZ_SHELL  = $TZ_SHELL ?? [];
$__tabs    = (array)($TZ_SHELL['tabs'] ?? []);
$__is_admin = function_exists('is_admin') && is_admin();
$__script = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
$__home   = APP_URL . '/tozsamosc/index.php';

// Kod IKA w menu tylko dla kont, które go mają (ta sama reguła co sekcja w index.php).
$__has_ika = false;
try { $__has_ika = !empty((db_one("SELECT cpc_code FROM users WHERE id=?", [(int)($__u['id'] ?? 0)]) ?? [])['cpc_code']); } catch (\Throwable $e) {}

// Strony administracyjne podsystemu (plik => [etykieta, ikona]) — grupa „Administracja” w menu.
$__admin_items = [
    'uzytkownicy.php' => ['Użytkownicy',          'bi-people'],
    'role.php'        => ['Role i uprawnienia',   'bi-diagram-3'],
    'moduly.php'      => ['Uprawnienia modułów',  'bi-grid-3x3-gap'],
    'audit.php'       => ['Dziennik zdarzeń',     'bi-journal-text'],
    'ustawienia.php'  => ['Ustawienia logowania', 'bi-sliders'],
    'saml.php'        => ['SAML / SSO',           'bi-box-arrow-in-right'],
    'ldap.php'        => ['Katalog LDAP',         'bi-diagram-2'],
    'sync.php'        => ['Synchronizacja kont',  'bi-arrow-repeat'],
    'ika_admin.php'   => ['Kody IKA',             'bi-123'],
    'ezd_macierz.php' => ['Macierz dostępu EZD',  'bi-table'],
    'api_keys.php'    => ['Klucze API',           'bi-key'],
];
$__admin_active = in_array($TZ_ACTIVE, ['users', 'uzytkownicy', 'administracja'], true)
    || isset($__admin_items[$__script]) || in_array($__script, ['users.php', 'access.php'], true);

// Menu „Moje konto”: [klucz sekcji index.php | null, etykieta, ikona, adres zewnętrzny | null, aktywna?]
$__nav_me = [
    ['przeglad',       'Przegląd',            'bi-house',              null, false],
    ['podstawowe',     'Dane konta',          'bi-person-vcard',       null, false],
    ['bezpieczenstwo', 'Zabezpieczenia',      'bi-shield-lock',        null, false],
    [null,             'Metody weryfikacji',  'bi-shield-check',       APP_URL . '/tozsamosc/mfa.php', $TZ_ACTIVE === 'mfa'],
    ['urzadzenia',     'Urządzenia i logowania', 'bi-laptop',          null, false],
    ['uslugi',         'Usługi',              'bi-grid',               null, false],
];
if ($__has_ika) $__nav_me[] = ['ika', 'Kod IKA', 'bi-123', null, false];
$__nav_me[] = ['rejestr', 'Moje dane (RODO)',   'bi-file-earmark-lock', null, false];
$__nav_me[] = ['opanelu', 'Pomoc i informacje', 'bi-question-circle',   null, false];

/** Pozycja menu: w index.php (sekcje) przełącza sekcję w miejscu, gdzie indziej prowadzi do index.php#klucz. */
$__nav_a = function (?string $key, string $label, string $icon, ?string $href, bool $on, bool $sub = false) use ($__tabs, $__home): void {
    $cls = 'tzn-a' . ($sub ? ' tzn-a--sub' : '');
    if ($key !== null && in_array($key, $__tabs, true)) {
        echo '<a class="' . $cls . '" href="#' . h($key) . '" @click.prevent="go(\'' . h($key) . '\')"'
           . ' :class="{ on: tab === \'' . h($key) . '\' }" :aria-current="tab === \'' . h($key) . '\' ? \'page\' : null">';
    } else {
        $url = $href ?? ($__home . '#' . $key);
        echo '<a class="' . $cls . ($on ? ' on' : '') . '" href="' . h($url) . '"' . ($on ? ' aria-current="page"' : '') . '>';
    }
    echo '<i class="bi ' . h($icon) . '" aria-hidden="true"></i><span>' . h($label) . '</span></a>';
};
?>
<!doctype html>
<html lang="pl">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= h($__title) ?>System Tożsamości · <?= h($__org) ?></title>
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
        integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <script>
    // Powłoka podsystemu: szuflada menu (mobile) + sekcje index.php (jedna naraz, #hash w adresie —
    // przekierowania po zapisie używają #bezpieczenstwo, #uslugi, #ika). Rejestrowana przed startem Alpine.
    document.addEventListener('alpine:init', function () {
      Alpine.data('tzShell', function (cfg) {
        cfg = cfg || {};
        return {
          nav: false, tabs: cfg.tabs || [], tab: cfg.def || '',
          init: function () {
            var self = this;
            window.addEventListener('resize', function () { if (window.innerWidth >= 1024) self.nav = false; });
            if (!this.tabs.length) return;
            var h = (location.hash || '').slice(1);
            if (!cfg.force && this.tabs.indexOf(h) >= 0) this.tab = h;
            else if (history.replaceState && (cfg.force || h !== '') && h !== this.tab) history.replaceState(null, '', '#' + this.tab);
            window.addEventListener('hashchange', function () {
              var h2 = (location.hash || '').slice(1);
              if (self.tabs.indexOf(h2) >= 0) self.go(h2);
            });
          },
          // go('bezpieczenstwo', 'haslo') — przełącza sekcję i rozwija w niej ustawienie (zdarzenie tz-sec).
          go: function (t, sub) {
            if (this.tabs.indexOf(t) < 0) return;
            this.tab = t; this.nav = false;
            if (history.replaceState && location.hash !== '#' + t) history.replaceState(null, '', '#' + t);
            this.$nextTick(function () {
              if (sub) { window.dispatchEvent(new CustomEvent('tz-sec', { detail: sub })); return; }
              window.scrollTo({ top: 0 });
              var hd = document.getElementById(t + '-h');
              if (hd) hd.focus({ preventScroll: true });
            });
          }
        };
      });
    });
  </script>
  <!-- Alpine.js — wtyczka focus MUSI ładować się przed rdzeniem (rejestruje się na obiekcie Alpine przed jego startem). -->
  <script src="https://cdn.jsdelivr.net/npm/@alpinejs/focus@3/dist/cdn.min.js" defer></script>
  <script src="https://cdn.jsdelivr.net/npm/alpinejs@3/dist/cdn.min.js" defer></script>
  <!-- Tailwind — prefiks tw-, bez preflightu (jak w includes/header.php): zero kolizji z Bootstrapem. -->
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      prefix: 'tw-',
      corePlugins: { preflight: false },
    };
  </script>
  <style>
    /* ══ Tokeny (Fluent 2 / konto Microsoft). Kontrasty: tekst #242424/#616161 na bieli i #faf9f8 ≥ 5,9:1,
          akcent #0f6cbd na bieli 5,4:1, biały na #0f6cbd 5,4:1, obramowanie pól #8a8a8a 3,4:1. ══ */
    :root{
      --tz:#0f6cbd;--tz-strong:#115ea3;--tz-press:#0c3b5e;--tz-50:#ebf3fc;--tz-100:#cfe4fa;--tz-ink:#0f548c;
      --tz-line:#e0e0e0;--tz-line-2:#d1d1d1;--tz-input:#8a8a8a;
      --tz-fg:#242424;--tz-fg2:#424242;--tz-muted:#616161;
      --tz-canvas:#faf9f8;--tz-card:#fff;--tz-hover:#f5f5f5;--tz-sel:#ebebeb;
      --tz-ok:#107c10;--tz-ok-ink:#0e700e;--tz-warn:#bc4b09;--tz-warn-ink:#8a3707;--tz-err:#c50f1f;--tz-err-ink:#b10e1c;
      --tz-shadow:0 0 2px rgba(0,0,0,.12),0 2px 4px rgba(0,0,0,.14);
      --tz-shadow-8:0 0 2px rgba(0,0,0,.12),0 4px 8px rgba(0,0,0,.14);
      --tz-shadow-16:0 0 2px rgba(0,0,0,.12),0 8px 16px rgba(0,0,0,.14);
      --tz-r:4px;--tz-r-lg:8px;--tz-focus:#242424;
      --tz-font:"Segoe UI","Segoe UI Web (West European)",-apple-system,BlinkMacSystemFont,Roboto,"Helvetica Neue",Arial,sans-serif;
    }
    [x-cloak]{display:none!important}
    html{scroll-behavior:smooth}
    @media (prefers-reduced-motion: reduce){html{scroll-behavior:auto}*,*::before,*::after{transition:none!important;animation:none!important}}
    body{margin:0;background:var(--tz-canvas);color:var(--tz-fg);font-family:var(--tz-font);font-size:14px;line-height:1.43;min-height:100vh;-webkit-font-smoothing:antialiased}
    a:focus-visible,button:focus-visible,summary:focus-visible,[tabindex]:not([tabindex="-1"]):focus-visible{outline:3px solid var(--tz-focus);outline-offset:2px}
    .tz-skip{position:absolute;left:-999px;top:0}
    .tz-skip:focus{left:1rem;top:.5rem;z-index:1090;background:#fff;color:var(--tz-fg);padding:.55rem 1rem;border-radius:var(--tz-r);font-weight:600;box-shadow:var(--tz-shadow-16);outline:3px solid var(--tz-focus)}

    /* ══ Nagłówek (suite bar) ══ */
    .tzh{position:sticky;top:0;z-index:1030;height:48px;display:flex;align-items:center;gap:.35rem;padding:0 .75rem 0 .5rem;background:#fff;border-bottom:1px solid var(--tz-line)}
    .tzh-burger{display:inline-flex;align-items:center;justify-content:center;width:44px;height:44px;border:0;background:transparent;border-radius:var(--tz-r);color:var(--tz-fg);font-size:1.4rem;cursor:pointer}
    .tzh-burger:hover{background:var(--tz-hover)}
    @media (min-width:1024px){.tzh-burger{display:none}}
    .tzh-brand{display:flex;align-items:center;gap:.6rem;text-decoration:none;color:var(--tz-fg);padding:.3rem .45rem;border-radius:var(--tz-r)}
    .tzh-brand:hover{color:var(--tz-fg);background:var(--tz-hover)}
    .tzh-mark{width:28px;height:28px;border-radius:var(--tz-r);background:var(--tz);color:#fff;display:flex;align-items:center;justify-content:center;font-size:.95rem;flex-shrink:0}
    .tzh-name{font-size:16px;font-weight:600;white-space:nowrap}
    .tzh-org{font-size:14px;color:var(--tz-muted);padding-left:.75rem;margin-left:.15rem;border-left:1px solid var(--tz-line-2);white-space:nowrap;line-height:20px}
    @media (max-width:640px){.tzh-org{display:none}}
    .tzh-sp{flex:1}
    .tzh-lnk{display:inline-flex;align-items:center;gap:.45rem;min-height:36px;padding:0 .75rem;border-radius:var(--tz-r);color:var(--tz-fg);text-decoration:none;font-weight:600;white-space:nowrap}
    .tzh-lnk:hover{background:var(--tz-hover);color:var(--tz-fg)}
    @media (max-width:640px){.tzh-lnk span{display:none}.tzh-lnk{min-width:44px;justify-content:center}}
    .tzh-acc{position:relative}
    .tzh-ava-btn{border:0;background:transparent;padding:6px;border-radius:50%;cursor:pointer;display:flex}
    .tzh-ava-btn:hover .tzh-ava{box-shadow:0 0 0 2px var(--tz-line-2)}
    .tzh-ava{width:32px;height:32px;border-radius:50%;background:var(--tz-100);color:var(--tz-ink);font-weight:600;font-size:13px;display:flex;align-items:center;justify-content:center;flex-shrink:0;letter-spacing:.02em}
    .tzh-ava--lg{width:48px;height:48px;font-size:17px}
    .tzh-menu{position:absolute;right:0;top:calc(100% + 4px);width:300px;max-width:calc(100vw - 1.5rem);background:#fff;border-radius:var(--tz-r-lg);box-shadow:var(--tz-shadow-16);padding:.4rem;z-index:1040}
    .tzh-menu-who{display:flex;gap:.75rem;align-items:center;padding:.6rem .6rem .8rem;border-bottom:1px solid var(--tz-line);margin-bottom:.3rem;min-width:0}
    .tzh-menu-who b{display:block;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .tzh-menu-who small{display:block;color:var(--tz-muted);font-size:12px;word-break:break-all}
    .tzh-menu a{display:flex;align-items:center;gap:.65rem;min-height:36px;padding:.4rem .6rem;border-radius:var(--tz-r);color:var(--tz-fg);text-decoration:none}
    .tzh-menu a:hover{background:var(--tz-hover);color:var(--tz-fg)}
    .tzh-menu a i{color:var(--tz-muted);width:1.1rem;text-align:center}
    .tzh-menu hr{margin:.3rem 0;border:0;border-top:1px solid var(--tz-line);opacity:1}

    /* ══ Powłoka: lewe menu + kolumna treści ══ */
    .tzs-body{display:flex;min-height:calc(100vh - 48px)}
    .tzn{flex:0 0 260px;width:260px;background:#fff;border-right:1px solid var(--tz-line);position:sticky;top:48px;height:calc(100vh - 48px);overflow-y:auto;overscroll-behavior:contain}
    .tzn-in{padding:.75rem .5rem 1.5rem}
    .tzn-lab{font-size:12px;font-weight:600;color:var(--tz-muted);padding:.85rem .75rem .35rem;margin:0}
    .tzn-list{list-style:none;margin:0;padding:0}
    .tzn-a,.tzn-gbtn{position:relative;display:flex;align-items:center;gap:.75rem;width:100%;min-height:40px;padding:.45rem .75rem;border:0;border-radius:var(--tz-r);background:transparent;color:var(--tz-fg);text-decoration:none;font:inherit;font-size:14px;text-align:left;cursor:pointer}
    .tzn-a i,.tzn-gbtn i{font-size:1.1rem;width:1.25rem;text-align:center;color:var(--tz-fg2);flex-shrink:0}
    .tzn-a:hover,.tzn-gbtn:hover{background:var(--tz-hover);color:var(--tz-fg)}
    .tzn-a.on,.tzn-a[aria-current="page"]{background:var(--tz-sel);font-weight:600}
    .tzn-a.on::before,.tzn-a[aria-current="page"]::before{content:"";position:absolute;left:0;top:10px;bottom:10px;width:3px;border-radius:2px;background:var(--tz)}
    .tzn-a.on i,.tzn-a[aria-current="page"] i{color:var(--tz)}
    .tzn-a--sub{padding-left:2.75rem;min-height:36px}
    .tzn-a--sub i{font-size:.95rem}
    .tzn-gbtn .tzn-chev{margin-left:auto;font-size:.75rem;width:auto;transition:transform .15s}
    .tzn-gbtn[aria-expanded="true"] .tzn-chev{transform:rotate(180deg)}
    .tzn-sep{height:1px;background:var(--tz-line);margin:.6rem .75rem}
    .tzn-scrim{display:none}
    .tzs-col{flex:1;min-width:0;display:flex;flex-direction:column}
    .tz-wrap{width:100%;max-width:1120px;margin:0 auto;padding:1.75rem 2rem 2.5rem;flex:1}
    .tz-wrap:focus{outline:none}
    @media (max-width:640px){.tz-wrap{padding:1.25rem 1rem 2rem}}
    @media (max-width:1023.98px){
      .tzn{position:fixed;left:0;top:48px;bottom:0;height:auto;width:280px;z-index:1035;transform:translateX(-100%);visibility:hidden;transition:transform .18s ease,visibility 0s linear .18s}
      .tzn.is-open{transform:none;visibility:visible;box-shadow:var(--tz-shadow-16);transition:transform .18s ease,visibility 0s}
      .tzn-scrim{display:block;position:fixed;top:48px;right:0;bottom:0;left:0;background:rgba(0,0,0,.4);z-index:1034}
    }
    .tzf{width:100%;max-width:1120px;margin:0 auto;padding:1rem 2rem 1.75rem;color:var(--tz-muted);font-size:12px;display:flex;flex-wrap:wrap;gap:.35rem 1.25rem;border-top:1px solid var(--tz-line)}
    .tzf a{color:var(--tz-muted)}
    .tzf a:hover{color:var(--tz-fg)}
    @media (max-width:640px){.tzf{padding:1rem}}

    /* ══ Bootstrap w tej szacie (strony mfa.php, admin/*.php pod TZ_*_CHROME) ══ */
    .tz{--bs-link-color-rgb:15,108,189;--bs-link-hover-color-rgb:17,94,163;--bs-primary-rgb:15,108,189;--bs-border-radius:4px;--bs-border-radius-sm:4px;--bs-border-radius-lg:8px;--bs-body-font-family:var(--tz-font)}
    .tz .btn{border-radius:var(--tz-r);font-weight:600}
    .tz .btn-primary{--bs-btn-bg:#0f6cbd;--bs-btn-border-color:#0f6cbd;--bs-btn-hover-bg:#115ea3;--bs-btn-hover-border-color:#115ea3;--bs-btn-active-bg:#0c3b5e;--bs-btn-active-border-color:#0c3b5e;--bs-btn-disabled-bg:#0f6cbd;--bs-btn-disabled-border-color:#0f6cbd}
    .tz .btn-outline-primary{--bs-btn-color:#0f6cbd;--bs-btn-border-color:#0f6cbd;--bs-btn-hover-bg:#0f6cbd;--bs-btn-hover-border-color:#0f6cbd;--bs-btn-active-bg:#115ea3;--bs-btn-active-border-color:#115ea3}
    .tz .text-primary{color:var(--tz)!important}
    .tz .form-control,.tz .form-select{border:1px solid var(--tz-input);border-radius:var(--tz-r);min-height:32px;font-size:14px;color:var(--tz-fg);box-shadow:none}
    .tz .form-control:hover,.tz .form-select:hover{border-color:var(--tz-muted)}
    .tz .form-control:focus,.tz .form-select:focus{border-color:var(--tz);box-shadow:inset 0 -2px 0 var(--tz);outline:2px solid var(--tz);outline-offset:1px}
    .tz .input-group-text{border-color:var(--tz-input);background:var(--tz-hover);border-radius:var(--tz-r)}
    .tz .form-label{font-weight:600;font-size:14px;color:var(--tz-fg)}
    .tz .alert{border-radius:var(--tz-r)}
    .tz .modal-content{border-radius:var(--tz-r-lg);border:0;box-shadow:var(--tz-shadow-16)}
    .tz .lbl-en{font-size:12px;color:var(--tz-muted);font-weight:400;display:block;margin-top:.1rem}

    /* ══ Kontrakt .tz-* (wspólny dla stron podsystemu) ══ */
    .tz-h{margin-bottom:1.25rem}
    .tz-h h1{font-size:28px;line-height:36px;font-weight:600;margin:0;color:var(--tz-fg)}
    .tz-h h1 .bi{color:var(--tz);font-size:.85em}
    .tz-h p{color:var(--tz-muted);margin:.25rem 0 0;font-size:14px}
    .tz-card{background:var(--tz-card);border:0;border-radius:var(--tz-r-lg);box-shadow:var(--tz-shadow);overflow:hidden;margin-bottom:1rem}
    .tz-card__hd{padding:.9rem 1.25rem;border-bottom:1px solid var(--tz-line);background:#fff;display:flex;align-items:center;gap:.6rem;font-weight:600;font-size:16px;color:var(--tz-fg)}
    .tz-card__hd i{color:var(--tz)}
    .tz-card__bd{padding:1.25rem}
    .tz-id{background:#fff;padding:1.1rem 1.25rem;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;border-bottom:1px solid var(--tz-line)}
    .tz-id .tz-ava{width:48px;height:48px;border-radius:50%;background:var(--tz-100);color:var(--tz-ink);display:flex;align-items:center;justify-content:center;font-weight:600;font-size:1rem;flex-shrink:0}
    .tz-uid{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-weight:600;font-size:1.3rem;letter-spacing:.03em;line-height:1;color:var(--tz-fg)}
    .tz-dl{display:grid;grid-template-columns:repeat(1,1fr)}
    @media (min-width:576px){.tz-dl{grid-template-columns:repeat(2,1fr)}}
    @media (min-width:992px){.tz-dl{grid-template-columns:repeat(3,1fr)}}
    .tz-dl>div{padding:.75rem 1.25rem;border-top:1px solid var(--tz-line)}
    .tz-dl dt{font-size:12px;color:var(--tz-muted);margin:0;font-weight:400}
    .tz-dl dd{font-weight:600;margin:.15rem 0 0;font-size:14px;word-break:break-word;color:var(--tz-fg)}
    .tz-note{background:var(--tz-hover);border-top:1px solid var(--tz-line);padding:.6rem 1.25rem;font-size:12px;color:var(--tz-muted);display:flex;gap:.4rem;align-items:flex-start}
    .tz-btn{display:inline-flex;align-items:center;justify-content:center;gap:.4rem;min-height:32px;padding:.3rem 1rem;background:var(--tz);color:#fff;border:1px solid var(--tz);border-radius:var(--tz-r);font-weight:600;font-size:14px;line-height:1.3;text-decoration:none;cursor:pointer;transition:background .1s,border-color .1s}
    .tz-btn:hover{background:var(--tz-strong);border-color:var(--tz-strong);color:#fff}
    .tz-btn:active{background:var(--tz-press);border-color:var(--tz-press)}
    .tz-btn--ghost{background:#fff;color:var(--tz-fg);border:1px solid var(--tz-line-2)}
    .tz-btn--ghost:hover{background:var(--tz-hover);border-color:#c7c7c7;color:var(--tz-fg)}
    .tz-btn--ghost:active{background:#e0e0e0;border-color:#b3b3b3}
    .tz-btn--subtle{background:transparent;color:var(--tz-fg);border-color:transparent}
    .tz-btn--subtle:hover{background:var(--tz-hover);border-color:transparent;color:var(--tz-fg)}
    .tz-btn--danger{background:#fff;color:var(--tz-err-ink);border:1px solid var(--tz-line-2)}
    .tz-btn--danger:hover{background:#fdf3f4;border-color:#eeacb2;color:var(--tz-err-ink)}
    .tz-btn--sm{min-height:28px;padding:.15rem .7rem;font-size:13px}
    .tz-btn:disabled,.tz-btn[aria-disabled="true"]{background:#f0f0f0;border-color:#e0e0e0;color:#7a7a7a;cursor:not-allowed}
    .tz-badge{display:inline-flex;align-items:center;gap:.25rem;font-size:12px;font-weight:600;line-height:1;padding:.28rem .55rem;border-radius:999px;border:1px solid transparent;white-space:nowrap}
    .tz-badge--ok{background:#f1faf1;color:var(--tz-ok-ink);border-color:#9fd89f}
    .tz-badge--warn{background:#fff9f5;color:var(--tz-warn-ink);border-color:#fdcfb4}
    .tz-badge--off{background:var(--tz-hover);color:var(--tz-fg2);border-color:var(--tz-line-2)}
    .tz-badge--info{background:var(--tz-50);color:var(--tz-ink);border-color:#b4d6fa}
    .tz-badge--err{background:#fdf3f4;color:var(--tz-err-ink);border-color:#eeacb2}
    .tz-otp{font-size:1.5rem;letter-spacing:.45rem;text-align:center;font-weight:600;font-family:ui-monospace,monospace}
    .tz-req{list-style:none;padding:0;margin:.5rem 0 0;display:grid;grid-template-columns:1fr;gap:.35rem;font-size:14px}
    @media (min-width:576px){.tz-req{grid-template-columns:1fr 1fr}}
    .tz-req li{color:var(--tz-muted);display:flex;align-items:center;gap:.4rem}
    .tz-req li.ok{color:var(--tz-ok-ink)}
    .tz-req li .dot{display:inline-block;width:1.1em;text-align:center;font-weight:700}
    .tz-meter{height:4px;border-radius:2px;background:var(--tz-line);overflow:hidden;margin-top:.5rem}
    .tz-meter>span{display:block;height:100%;width:0;background:var(--tz-err);transition:width .3s,background-color .3s}
    .tz-subnav{position:sticky;top:48px;z-index:5;background:var(--tz-canvas);padding:.35rem 0 0;margin-bottom:1.25rem;border-bottom:1px solid var(--tz-line)}
    .tz-subnav .seg{display:flex;gap:.25rem;flex-wrap:wrap}
    .tz-subnav a{position:relative;font-size:14px;padding:.55rem .85rem;border-radius:var(--tz-r) var(--tz-r) 0 0;text-decoration:none;color:var(--tz-fg2);display:inline-flex;align-items:center;gap:.4rem}
    .tz-subnav a:hover{background:var(--tz-hover);color:var(--tz-fg)}
    .tz-subnav a.on{color:var(--tz-fg);font-weight:600}
    .tz-subnav a.on::after{content:"";position:absolute;left:.85rem;right:.85rem;bottom:-1px;height:3px;border-radius:2px;background:var(--tz)}
    .tz-panel{display:none}.tz-panel.active{display:block}
    .tz-svc{display:flex;align-items:center;gap:.85rem;padding:.8rem 0;border-top:1px solid var(--tz-line)}
    .tz-svc:first-child{border-top:0}
    .tz-svc__ico,.tz-tile__ico{width:40px;height:40px;border-radius:var(--tz-r-lg);background:var(--tz);color:#fff;display:flex;align-items:center;justify-content:center;font-size:1.15rem;flex-shrink:0}
    .tz-tiles{display:grid;gap:1rem;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));margin:.2rem 0 .75rem}
    .tz-tile{display:block;text-align:left;background:#fff;border:0;border-radius:var(--tz-r-lg);box-shadow:var(--tz-shadow);padding:1.1rem;text-decoration:none;color:inherit;transition:box-shadow .12s}
    .tz-tile:hover{box-shadow:var(--tz-shadow-8);color:inherit}
    .tz-tile__ico{margin-bottom:.6rem}
    .tz-app-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:1rem;margin:.5rem 0}
    .tz-app{position:relative;border-radius:var(--tz-r-lg);overflow:hidden;text-decoration:none;color:#fff;display:flex;flex-direction:column;min-height:148px;padding:1rem 1.1rem .9rem;box-shadow:var(--tz-shadow)}
    .tz-app:hover{filter:brightness(1.06);color:#fff}
    .tz-app-ic{width:40px;height:40px;border-radius:var(--tz-r-lg);background:rgba(255,255,255,.2);display:flex;align-items:center;justify-content:center;font-size:1.25rem;flex-shrink:0}
    .tz-app-title{font-size:15px;font-weight:600;line-height:1.25;margin-top:.6rem}
    .tz-app-desc{font-size:12px;color:rgba(255,255,255,.88);margin-top:.2rem;flex:1;line-height:1.35}
    .tz-app-foot{display:flex;align-items:center;gap:.4rem;margin-top:.7rem}
    .tz-app-cta{font-size:12px;font-weight:600;color:#fff;display:flex;align-items:center;gap:.25rem;text-decoration:none}
    .tz-app-cta:hover{color:#fff;text-decoration:underline}
    /* Miękkie znaczniki „bg-X bg-opacity-15 text-X” ze stron admin/ (Metody logowania, Moduły) — bez app.css
       Bootstrap nie zna bg-opacity-15: pełne tło w kolorze tekstu = szary pasek bez napisu */
    .bg-opacity-15{--bs-bg-opacity:.15}
    .badge.bg-opacity-15.text-secondary{color:#3f3f3f!important}
    .badge.bg-opacity-15.text-warning{color:#9a4000!important}
    .badge.bg-opacity-15.text-info{color:#0e7490!important}
    .badge.bg-opacity-15.text-success{color:#006b00!important}
    .badge.bg-opacity-15.text-primary{color:#1d4ed8!important}
    .badge.bg-opacity-15.text-danger{color:#a20025!important}
  </style>
</head>
<body>
<a href="#tz-main" class="tz-skip">Przejdź do treści</a>
<div class="tzs" x-data="tzShell(<?= h(json_encode(['tabs' => $__tabs, 'def' => (string)($TZ_SHELL['def'] ?? ''), 'force' => !empty($TZ_SHELL['force'])])) ?>)" @keydown.escape.window="nav = false">

<header class="tzh">
  <button type="button" class="tzh-burger" @click="nav = !nav" :aria-expanded="nav ? 'true' : 'false'" aria-controls="tz-nav" aria-label="Menu Systemu Tożsamości">
    <i class="bi" :class="nav ? 'bi-x-lg' : 'bi-list'" aria-hidden="true"></i>
  </button>
  <a href="<?= h($__home) ?>" class="tzh-brand">
    <span class="tzh-mark" aria-hidden="true"><i class="bi bi-person-badge"></i></span>
    <span class="tzh-name">System Tożsamości</span>
  </a>
  <?php if ($__org !== ''): ?><span class="tzh-org"><?= h($__org) ?></span><?php endif; ?>
  <span class="tzh-sp"></span>
  <a class="tzh-lnk" href="<?= APP_URL ?>/portal.php"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i><span>Wróć do SZO</span></a>
  <div class="tzh-acc" x-data="{ o: false }" @click.outside="o = false" @keydown.escape.stop="if (o) { o = false; $refs.accBtn.focus(); }">
    <button type="button" class="tzh-ava-btn" x-ref="accBtn" @click="o = !o" :aria-expanded="o ? 'true' : 'false'" aria-controls="tz-accmenu" aria-label="Twoje konto: <?= h($__name) ?>">
      <span class="tzh-ava" aria-hidden="true"><?= h($__init) ?></span>
    </button>
    <div id="tz-accmenu" class="tzh-menu" x-show="o" x-cloak x-transition.opacity.duration.100ms @click="if ($event.target.closest('a')) o = false">
      <div class="tzh-menu-who">
        <span class="tzh-ava tzh-ava--lg" aria-hidden="true"><?= h($__init) ?></span>
        <div class="tw-min-w-0"><b><?= h($__name) ?></b><small><?= h($__u['email'] ?? '') ?></small></div>
      </div>
      <a href="<?= h($__home) ?>#przeglad"><i class="bi bi-person-circle" aria-hidden="true"></i>Moje konto</a>
      <a href="<?= h($__home) ?>#bezpieczenstwo"><i class="bi bi-shield-lock" aria-hidden="true"></i>Zabezpieczenia</a>
      <a href="<?= APP_URL ?>/tozsamosc/mfa.php"><i class="bi bi-shield-check" aria-hidden="true"></i>Metody weryfikacji</a>
      <hr>
      <a href="<?= APP_URL ?>/portal.php"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>Wróć do SZO</a>
      <a href="<?= APP_URL ?>/auth/logout.php"><i class="bi bi-box-arrow-right" aria-hidden="true"></i>Wyloguj się</a>
    </div>
  </div>
</header>

<div class="tzs-body">
  <div class="tzn-scrim" x-show="nav" x-cloak @click="nav = false" aria-hidden="true"></div>
  <nav id="tz-nav" class="tzn" :class="nav ? 'is-open' : ''" aria-label="Menu Systemu Tożsamości" x-trap.noscroll="nav">
    <div class="tzn-in">
      <p class="tzn-lab" id="tzn-me">Moje konto</p>
      <ul class="tzn-list" aria-labelledby="tzn-me">
        <?php foreach ($__nav_me as [$__k, $__l, $__i, $__href, $__on]): ?>
        <li><?php $__nav_a($__k, $__l, $__i, $__href, $__on); ?></li>
        <?php endforeach; ?>
      </ul>

      <?php if ($__is_admin): $__adm_open = $__admin_active; ?>
      <div class="tzn-sep" role="presentation"></div>
      <div x-data="{ open: <?= $__adm_open ? 'true' : 'false' ?> }">
        <button type="button" class="tzn-gbtn" @click="open = !open" :aria-expanded="open ? 'true' : 'false'" aria-expanded="<?= $__adm_open ? 'true' : 'false' ?>" aria-controls="tzn-adm">
          <i class="bi bi-shield-fill-gear" aria-hidden="true"></i><span>Administracja</span><i class="bi bi-chevron-down tzn-chev" aria-hidden="true"></i>
        </button>
        <ul id="tzn-adm" class="tzn-list" x-show="open"<?= $__adm_open ? '' : ' style="display:none"' ?>>
          <li><?php $__nav_a('administracja', 'Przegląd', 'bi-speedometer2', null, false, true); ?></li>
          <?php foreach ($__admin_items as $__f => [$__l, $__i]):
              $__on = ($__script === $__f) || ($__f === 'uzytkownicy.php' && ($__script === 'users.php' || in_array($TZ_ACTIVE, ['users', 'uzytkownicy'], true))); ?>
          <li><?php $__nav_a(null, $__l, $__i, APP_URL . '/tozsamosc/' . $__f, $__on, true); ?></li>
          <?php endforeach; ?>
          <li><?php $__nav_a(null, 'Panel administracyjny SZO', 'bi-gear', APP_URL . '/admin/index.php', false, true); ?></li>
        </ul>
      </div>
      <?php endif; ?>

      <div class="tzn-sep" role="presentation"></div>
      <ul class="tzn-list">
        <li><?php $__nav_a(null, 'Helpdesk', 'bi-headset', APP_URL . '/helpdesk/index.php', false); ?></li>
        <li><?php $__nav_a(null, 'Wróć do SZO', 'bi-box-arrow-up-right', APP_URL . '/portal.php', false); ?></li>
      </ul>
    </div>
  </nav>

  <div class="tzs-col">
<main id="tz-main" class="tz tz-wrap" tabindex="-1">
