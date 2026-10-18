<?php
declare(strict_types=1);

/**
 * modules/ti_licencje_dydaktyczne/lib/LicenseEnums.php — enumy modułu „Licencje dydaktyczne (AM5) i Office 365 (AM7)”.
 *
 * Nazwy z prefiksem License*, bo DeclarationStatus istnieje już w modules/ti_msues (oba moduły bywają ładowane razem).
 */

/** Obieg wniosku AM5/1: robocze → wszczęte (czeka na decyzję licencjodawcy, do 7 dni) → udzielona / odmowa / wycofany. */
enum LicenseRequestStatus: string
{
    case Draft     = 'draft';
    case Started   = 'started';
    case Granted   = 'granted';
    case Refused   = 'refused';
    case Withdrawn = 'withdrawn';

    public function label(): string
    {
        return match ($this) {
            self::Draft     => 'Wniosek roboczy',
            self::Started   => 'Wszczęty — czeka na decyzję licencjodawcy',
            self::Granted   => 'Licencja udzielona',
            self::Refused   => 'Odmowa licencjodawcy',
            self::Withdrawn => 'Wniosek wycofany',
        };
    }

    /** Kolor kafla Metro. */
    public function color(): string
    {
        return match ($this) {
            self::Draft     => '#647687',
            self::Started   => '#f0a30a',
            self::Granted   => '#008a00',
            self::Refused   => '#a20025',
            self::Withdrawn => '#76608a',
        };
    }

    public function editable(): bool
    {
        return $this === self::Draft;
    }
}

/** Stan licencji w puli (liczony — zapisywane są tylko udzielenie, zawieszenie i okres). */
enum LicenseStatus: string
{
    case Free      = 'free';
    case Assigned  = 'assigned';
    case Suspended = 'suspended';
    case Expired   = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Free      => 'Wolna',
            self::Assigned  => 'Przydzielona',
            self::Suspended => 'Zawieszona',
            self::Expired   => 'Wygasła',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Free      => '#1ba1e2',
            self::Assigned  => '#008a00',
            self::Suspended => '#fa6800',
            self::Expired   => '#647687',
        };
    }

    /**
     * Stan licencji udzielonej: wygasła (po period_to) > zawieszona > przydzielona (są aktywne przydziały) > wolna.
     * Dla wniosku nieudzielonego zwraca null (licencji jeszcze nie ma w puli).
     */
    public static function resolve(array $license, int $activeAssignments): ?self
    {
        if (($license['request_status'] ?? '') !== LicenseRequestStatus::Granted->value) return null;
        $to = trim((string)($license['period_to'] ?? ''));
        if ($to !== '' && $to < ldToday()) return self::Expired;
        if (!empty($license['suspended'])) return self::Suspended;
        return $activeAssignments > 0 ? self::Assigned : self::Free;
    }
}

/** Przydział licencji osobie: zaproszona (czeka na oświadczenia, do 7 dni) → złożone → zweryfikowane / odrzucone; cofnięty. */
enum LicenseAssignmentStatus: string
{
    case Invited   = 'invited';
    case Submitted = 'submitted';
    case Accepted  = 'accepted';
    case Rejected  = 'rejected';
    case Revoked   = 'revoked';

    public function label(): string
    {
        return match ($this) {
            self::Invited   => 'Czeka na oświadczenia',
            self::Submitted => 'Oświadczenia do weryfikacji',
            self::Accepted  => 'Zweryfikowane',
            self::Rejected  => 'Odrzucone — do ponownego złożenia',
            self::Revoked   => 'Cofnięty',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Invited   => '#f0a30a',
            self::Submitted => '#1ba1e2',
            self::Accepted  => '#008a00',
            self::Rejected  => '#a20025',
            self::Revoked   => '#647687',
        };
    }

    /** Przydział zajmuje miejsce w puli. */
    public function active(): bool
    {
        return $this !== self::Revoked;
    }

    /** Osoba może (ponownie) złożyć oświadczenia przez portal. */
    public function canSubmit(): bool
    {
        return $this === self::Invited || $this === self::Rejected;
    }
}

/** Status pojedynczego oświadczenia AM5/2 lub AM5/3. */
enum LicenseDeclarationStatus: string
{
    case Pending    = 'pending';
    case Approved   = 'approved';
    case Rejected   = 'rejected';
    case Superseded = 'superseded';

    public function label(): string
    {
        return match ($this) {
            self::Pending    => 'Do weryfikacji',
            self::Approved   => 'Zatwierdzone (audyt MSUES)',
            self::Rejected   => 'Odrzucone',
            self::Superseded => 'Zastąpione nowszym',
        };
    }
}

/** Rodzina wzorów (rodzaj sprawy): AM5 — licencja na materiały dydaktyczne, AM7 — licencja / dostęp do Office 365. */
enum LicenseForm: string
{
    case Am5 = 'AM5';
    case Am7 = 'AM7';

    public function label(): string
    {
        return match ($this) {
            self::Am5 => 'Licencja na materiały dydaktyczne',
            self::Am7 => 'Licencja / dostęp do Office 365',
        };
    }

    public function requestDoc(): LicenseDoc
    {
        return $this === self::Am5 ? LicenseDoc::Am5Request : LicenseDoc::Am7Request;
    }

    /** Oświadczenia licencjobiorcy składane w portalu po przyznaniu. @return array{0:LicenseDoc,1:LicenseDoc} */
    public function declarationDocs(): array
    {
        return $this === self::Am5 ? [LicenseDoc::Am5Participant, LicenseDoc::Am5NonTransfer] : [LicenseDoc::Am7Participant, LicenseDoc::Am7NonTransfer];
    }

    /** Kto rozstrzyga: AM5 — zewnętrzny licencjodawca (portal / papier), AM7 — placówka (biuro w panelu, decyzja w PDF). */
    public function decidedByOffice(): bool
    {
        return $this === self::Am7;
    }

    public static function of(array $license): self
    {
        return self::tryFrom((string)($license['form_kind'] ?? '')) ?? self::Am5;
    }
}

/** Wzory dokumentów: AM5/1–3 (materiały dydaktyczne) i AM7/1–5 (Office 365). Wartość = symbol wzoru. */
enum LicenseDoc: string
{
    case Am5Request     = 'AM5/1';   // Wniosek o udzielenie licencji (z decyzją licencjodawcy)
    case Am5Participant = 'AM5/2';   // Oświadczenie licencjobiorcy – kursanta
    case Am5NonTransfer = 'AM5/3';   // Oświadczenie o nieodstępowaniu licencji i nieudostępnianiu materiałów
    case Am7Request     = 'AM7/1';   // Wniosek o udostępnienie licencji Office 365 do celów dydaktycznych
    case Am7Grant       = 'AM7/2';   // Decyzja o przyznaniu licencji lub dostępu do Office 365
    case Am7Refusal     = 'AM7/3';   // Decyzja o odmowie
    case Am7Participant = 'AM7/4';   // Krótkie oświadczenie licencjobiorcy — kursanta
    case Am7NonTransfer = 'AM7/5';   // Oświadczenie o nieodstępowaniu licencji i nieudostępnianiu konta

    public function symbol(): string
    {
        return $this->value;
    }

    /** Postać do adresów i nazw plików: AM7-2. */
    public function slug(): string
    {
        return str_replace('/', '-', $this->value);
    }

    public static function fromSlug(string $slug): ?self
    {
        return self::tryFrom(str_replace('-', '/', strtoupper($slug)));
    }

    public function form(): LicenseForm
    {
        return str_starts_with($this->value, 'AM7') ? LicenseForm::Am7 : LicenseForm::Am5;
    }

    /** Numer części wzoru (po ukośniku). */
    public function part(): string
    {
        return substr($this->value, 4);
    }

    public function title(): string
    {
        return match ($this) {
            self::Am5Request     => 'Wniosek o udzielenie licencji na korzystanie z materiałów w celach dydaktycznych',
            self::Am5Participant => 'Oświadczenie licencjobiorcy – kursanta',
            self::Am5NonTransfer => 'Oświadczenie o nieodstępowaniu licencji i nieudostępnianiu materiałów',
            self::Am7Request     => 'Wniosek o udostępnienie licencji Office 365 do celów dydaktycznych',
            self::Am7Grant       => 'Decyzja o przyznaniu licencji lub dostępu do Office 365',
            self::Am7Refusal     => 'Decyzja o odmowie przyznania licencji lub dostępu do Office 365',
            self::Am7Participant => 'Krótkie oświadczenie licencjobiorcy — kursanta',
            self::Am7NonTransfer => 'Oświadczenie o nieodstępowaniu licencji i nieudostępnianiu konta',
        };
    }

    public function short(): string
    {
        return match ($this) {
            self::Am5Request, self::Am7Request         => 'Wniosek',
            self::Am7Grant                              => 'Decyzja o przyznaniu',
            self::Am7Refusal                            => 'Decyzja o odmowie',
            self::Am5Participant, self::Am7Participant => 'Oświadczenie kursanta',
            self::Am5NonTransfer                        => 'Oświadczenie o nieodstępowaniu',
            self::Am7NonTransfer                        => 'Oświadczenie o nieudostępnianiu konta',
        };
    }
}

/** Rodzaj powiadomienia — każdy ma kanały domyślne i zapasowe (fallback). */
enum LicenseNotificationType: string
{
    case RequestStarted       = 'request_started';        // do osoby zainteresowanej: ktoś wszczął proces (e-mail + SMS)
    case LicensorRequest      = 'licensor_request';       // do licencjodawcy: wniosek + link do decyzji
    case DecisionAdmin        = 'decision_admin';         // alert: licencjodawca podjął decyzję
    case DecisionInterested   = 'decision_interested';    // do osoby zainteresowanej: decyzja licencjodawcy
    case LicenseAssigned      = 'license_assigned';       // do licencjobiorcy: przyznano licencję, złóż oświadczenia (do 7 dni)
    case DeclarationConfirm   = 'declaration_confirm';    // do licencjobiorcy: potwierdzenie złożenia oświadczeń
    case DeclarationAdmin     = 'declaration_admin';      // alert: nowe oświadczenia do weryfikacji
    case DeclarationDecided   = 'declaration_decided';    // do licencjobiorcy: wynik weryfikacji
    case Reminder             = 'reminder';               // przypomnienie o terminie 7 dni
    case RequestSigned        = 'request_signed';         // alert: kursant podpisał wniosek AM7 — do decyzji
    case HelpdeskTicket       = 'helpdesk_ticket';        // zlecenie dla Helpdesku IT po decyzji AM7 (log)

    public function label(): string
    {
        return match ($this) {
            self::RequestStarted     => 'Wszczęcie procesu — osoba zainteresowana',
            self::LicensorRequest    => 'Wniosek do licencjodawcy',
            self::DecisionAdmin      => 'Decyzja licencjodawcy — alert',
            self::DecisionInterested => 'Decyzja licencjodawcy — osoba zainteresowana',
            self::LicenseAssigned    => 'Przyznanie licencji',
            self::DeclarationConfirm => 'Potwierdzenie oświadczeń',
            self::DeclarationAdmin   => 'Nowe oświadczenia — alert',
            self::DeclarationDecided => 'Wynik weryfikacji oświadczeń',
            self::Reminder           => 'Przypomnienie o terminie',
            self::RequestSigned      => 'Wniosek podpisany — alert',
            self::HelpdeskTicket     => 'Zlecenie Helpdesku IT',
        };
    }
}
