<?php
declare(strict_types=1);

/**
 * modules/ti_licencje_dydaktyczne/lib/LicenseDto.php — DTO wejścia (formularz panelu i formularze publiczne) z walidacją.
 *
 * Każde DTO: fromArray() normalizuje dane (przycina, obcina długość), errors() zwraca [pole => komunikat].
 * Do bazy trafiają wyłącznie pola znane DTO — nigdy surowy $_POST.
 */

/** Wspólne narzędzia walidacji. */
final class LdInput
{
    public static function str(array $in, string $k, int $max = 255): string
    {
        $v = $in[$k] ?? '';
        if (!is_scalar($v)) return '';
        $v = trim(str_replace("\0", '', (string)$v));
        $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $v) ?? '';
        return mb_substr($v, 0, $max);
    }

    public static function bool(array $in, string $k): bool
    {
        return !empty($in[$k]) && $in[$k] !== '0';
    }

    public static function date(array $in, string $k): string
    {
        $v = self::str($in, $k, 10);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) ? $v : '';
    }

    public static function email(string $v): bool
    {
        return $v === '' || filter_var($v, FILTER_VALIDATE_EMAIL) !== false;
    }

    /** Telefon: 9–15 cyfr (z opcjonalnym +); pusty dozwolony. */
    public static function phone(string $v): bool
    {
        $d = preg_replace('/\D/', '', $v);
        return $v === '' || (strlen($d) >= 9 && strlen($d) <= 15);
    }

    public static function postal(string $v): bool
    {
        return $v === '' || preg_match('/^\d{2}-\d{3}$/', $v) === 1;
    }
}

/** Wniosek AM5/1 (panel): utwór, licencjodawca, wnioskodawca, osoba zainteresowana, zakres, okres, terytorium, wynagrodzenie. */
final class LicenseRequestDto
{
    /** kod => [etykieta wzoru, podpowiedź pola szczegółów] */
    public const SCOPES = [
        'digital'  => ['utrwalanie lub zwielokrotnianie w postaci cyfrowej na potrzeby przygotowania i prowadzenia kursu', 'zakres i liczba kopii cyfrowych'],
        'print'    => ['sporządzanie i przekazywanie uczestnikom kopii drukowanych', 'zakres i liczba kopii drukowanych'],
        'display'  => ['wyświetlanie lub prezentowanie podczas zajęć', 'sposób i zakres prezentowania'],
        'platform' => ['udostępnianie uczestnikom kursu na zamkniętej platformie edukacyjnej', 'nazwa lub rodzaj platformy, krąg odbiorców i okres dostępu'],
        'other'    => ['inny sposób korzystania', 'dokładny sposób korzystania'],
    ];

    /** @param array<string,string> $scopes kod => szczegóły (tylko zaznaczone) */
    public function __construct(
        public readonly string $place,
        public readonly string $date,
        public readonly string $title,
        public readonly string $workDescription,
        public readonly ?int $courseId,
        public readonly string $courseName,
        public readonly string $licensorName,
        public readonly string $licensorEmail,
        public readonly string $licensorPhone,
        public readonly string $licensorAddress,
        public readonly string $applicantName,
        public readonly string $applicantContact,
        public readonly string $interestedKind,
        public readonly ?int $interestedId,
        public readonly string $interestedName,
        public readonly string $interestedEmail,
        public readonly string $interestedPhone,
        public readonly array $scopes,
        public readonly string $periodFrom,
        public readonly string $periodTo,
        public readonly string $territory,
        public readonly string $remuneration,
        public readonly int $seats,
    ) {}

    public static function fromArray(array $in): self
    {
        $scopes = [];
        foreach (array_keys(self::SCOPES) as $k) {
            if (LdInput::bool($in, 'scope_' . $k)) $scopes[$k] = LdInput::str($in, 'scope_' . $k . '_detail', 1000);
        }
        $kind = LdInput::str($in, 'interested_kind', 10);
        $kind = in_array($kind, ['client', 'user', 'external'], true) ? $kind : '';
        $iid = (int)($in['interested_id_' . $kind] ?? ($in['interested_id'] ?? 0));
        $cid = (int)($in['course_id'] ?? 0);
        return new self(
            LdInput::str($in, 'place', 120), LdInput::date($in, 'date') ?: ldToday(),
            LdInput::str($in, 'title', 255), LdInput::str($in, 'work_description', 4000),
            $cid > 0 ? $cid : null, LdInput::str($in, 'course_name', 255),
            LdInput::str($in, 'licensor_name', 255), LdInput::str($in, 'licensor_email', 190), LdInput::str($in, 'licensor_phone', 40), LdInput::str($in, 'licensor_address', 1000),
            LdInput::str($in, 'applicant_name', 255), LdInput::str($in, 'applicant_contact', 1000),
            $kind, ($kind === 'client' || $kind === 'user') && $iid > 0 ? $iid : null,
            LdInput::str($in, 'interested_name', 190), LdInput::str($in, 'interested_email', 190), LdInput::str($in, 'interested_phone', 40),
            $scopes, LdInput::date($in, 'period_from'), LdInput::date($in, 'period_to'),
            LdInput::str($in, 'territory', 190), LdInput::str($in, 'remuneration', 500), max(0, min(10000, (int)($in['seats'] ?? 0))),
        );
    }

    /** Pola wymagane do zapisu roboczego (minimum) albo do wszczęcia ($forStart). */
    public function errors(bool $forStart = false): array
    {
        $e = [];
        if ($this->title === '') $e['title'] = 'Podaj tytuł utworu lub materiału.';
        if (!LdInput::email($this->licensorEmail)) $e['licensor_email'] = 'Nieprawidłowy e-mail licencjodawcy.';
        if (!LdInput::phone($this->licensorPhone)) $e['licensor_phone'] = 'Nieprawidłowy telefon licencjodawcy.';
        if (!LdInput::email($this->interestedEmail)) $e['interested_email'] = 'Nieprawidłowy e-mail osoby zainteresowanej.';
        if (!LdInput::phone($this->interestedPhone)) $e['interested_phone'] = 'Nieprawidłowy telefon osoby zainteresowanej.';
        if ($this->periodFrom !== '' && $this->periodTo !== '' && $this->periodTo < $this->periodFrom) $e['period_to'] = 'Koniec okresu licencji jest przed jego początkiem.';
        if ($forStart) {
            if ($this->workDescription === '') $e['work_description'] = 'Opisz utwór (tytuł, autor, opis).';
            if ($this->licensorName === '') $e['licensor_name'] = 'Podaj licencjodawcę (imię i nazwisko lub firmę).';
            if ($this->applicantName === '') $e['applicant_name'] = 'Podaj wnioskodawcę.';
            if (!$this->scopes) $e['scopes'] = 'Zaznacz co najmniej jeden sposób korzystania.';
            foreach ($this->scopes as $k => $d) if ($d === '') $e['scope_' . $k . '_detail'] = 'Uzupełnij: ' . self::SCOPES[$k][1] . '.';
            if ($this->periodFrom === '' || $this->periodTo === '') $e['period_from'] = 'Podaj okres korzystania (od–do).';
            if ($this->territory === '') $e['territory'] = 'Podaj terytorium korzystania.';
            if ($this->remuneration === '') $e['remuneration'] = 'Podaj wynagrodzenie albo informację o nieodpłatności.';
            if ($this->interestedKind === '') $e['interested_kind'] = 'Wskaż osobę zainteresowaną licencją — dostanie e-mail i SMS o wszczęciu.';
            elseif ($this->interestedKind === 'external' && $this->interestedName === '') $e['interested_name'] = 'Podaj imię i nazwisko osoby zainteresowanej.';
        }
        return $e;
    }

    /** Treść wniosku zapisywana w licenses.request_data (to, co drukuje AM5/1). */
    public function requestData(): array
    {
        return ['place' => $this->place, 'date' => $this->date, 'scopes' => $this->scopes, 'remuneration' => $this->remuneration];
    }
}

/** Decyzja licencjodawcy (portal publiczny albo wpis z papieru w panelu). */
final class LicensorDecisionDto
{
    public function __construct(
        public readonly bool $grant,
        public readonly string $kind,            // niewylaczna | wylaczna
        public readonly string $acceptedScope,
        public readonly string $periodFrom,
        public readonly string $periodTo,
        public readonly string $territory,
        public readonly string $terms,
        public readonly string $place,
        public readonly string $date,
        public readonly string $signerName,
        public readonly string $refusalReason,
        public readonly bool $confirm,
    ) {}

    public static function fromArray(array $in): self
    {
        $kind = LdInput::str($in, 'kind', 16);
        return new self(
            LdInput::str($in, 'decision', 8) === 'grant', in_array($kind, ['niewylaczna', 'wylaczna'], true) ? $kind : '',
            LdInput::str($in, 'accepted_scope', 4000), LdInput::date($in, 'period_from'), LdInput::date($in, 'period_to'),
            LdInput::str($in, 'territory', 190), LdInput::str($in, 'terms', 2000), LdInput::str($in, 'place', 120),
            LdInput::date($in, 'date') ?: ldToday(), LdInput::str($in, 'signer_name', 190), LdInput::str($in, 'refusal_reason', 2000),
            LdInput::bool($in, 'confirm'),
        );
    }

    public function errors(): array
    {
        $e = [];
        if ($this->signerName === '') $e['signer_name'] = 'Podaj imię i nazwisko osoby podejmującej decyzję.';
        if (!$this->confirm) $e['confirm'] = 'Potwierdź decyzję.';
        if ($this->grant) {
            if ($this->kind === '') $e['kind'] = 'Wybierz rodzaj licencji (niewyłączna / wyłączna).';
            if ($this->acceptedScope === '') $e['accepted_scope'] = 'Opisz zaakceptowany zakres i ewentualne ograniczenia.';
            if ($this->periodFrom === '' || $this->periodTo === '') $e['period_from'] = 'Podaj okres licencji.';
            elseif ($this->periodTo < $this->periodFrom) $e['period_to'] = 'Koniec okresu jest przed początkiem.';
            if ($this->territory === '') $e['territory'] = 'Podaj terytorium.';
            if ($this->place === '') $e['place'] = 'Podaj miejscowość.';
        }
        return $e;
    }

    public function data(): array
    {
        return ['grant' => $this->grant, 'kind' => $this->kind, 'accepted_scope' => $this->acceptedScope, 'period_from' => $this->periodFrom,
                'period_to' => $this->periodTo, 'territory' => $this->territory, 'terms' => $this->terms, 'place' => $this->place,
                'date' => $this->date, 'signer_name' => $this->signerName, 'refusal_reason' => $this->refusalReason];
    }
}

/** Formularz licencjobiorcy (portal): dane osobowe/adresowe + oświadczenia AM5/2 i AM5/3. */
final class HolderDeclarationDto
{
    /** pole portalu => etykieta */
    public const FIELDS = [
        'name'        => 'Imię i nazwisko',
        'email'       => 'E-mail',
        'phone'       => 'Telefon',
        'addr_street' => 'Ulica',
        'addr_house'  => 'Nr domu',
        'addr_flat'   => 'Nr lokalu',
        'addr_postal' => 'Kod pocztowy',
        'addr_city'   => 'Miejscowość',
    ];

    public function __construct(
        public readonly string $name,
        public readonly string $email,
        public readonly string $phone,
        public readonly string $addrStreet,
        public readonly string $addrHouse,
        public readonly string $addrFlat,
        public readonly string $addrPostal,
        public readonly string $addrCity,
        public readonly string $place,
        public readonly bool $acceptParticipant,
        public readonly bool $acceptNonTransfer,
        public readonly bool $acceptRodo,
    ) {}

    public static function fromArray(array $in): self
    {
        return new self(
            LdInput::str($in, 'name', 190), LdInput::str($in, 'email', 190), LdInput::str($in, 'phone', 40),
            LdInput::str($in, 'addr_street', 120), LdInput::str($in, 'addr_house', 20), LdInput::str($in, 'addr_flat', 20),
            LdInput::str($in, 'addr_postal', 10), LdInput::str($in, 'addr_city', 120), LdInput::str($in, 'place', 120),
            LdInput::bool($in, 'accept_participant'), LdInput::bool($in, 'accept_nontransfer'), LdInput::bool($in, 'accept_rodo'),
        );
    }

    public function errors(): array
    {
        $e = [];
        if (mb_strlen($this->name) < 3) $e['name'] = 'Podaj imię i nazwisko.';
        if ($this->email === '' && $this->phone === '') $e['email'] = 'Podaj e-mail lub telefon — wyślemy potwierdzenie.';
        if (!LdInput::email($this->email)) $e['email'] = 'Nieprawidłowy adres e-mail.';
        if (!LdInput::phone($this->phone)) $e['phone'] = 'Nieprawidłowy numer telefonu.';
        if ($this->addrCity === '') $e['addr_city'] = 'Podaj miejscowość zamieszkania.';
        if (!LdInput::postal($this->addrPostal)) $e['addr_postal'] = 'Kod pocztowy w formacie 00-000.';
        if ($this->place === '') $e['place'] = 'Podaj miejscowość złożenia oświadczeń.';
        if (!$this->acceptParticipant) $e['accept_participant'] = 'Zaznacz oświadczenie licencjobiorcy – kursanta (AM5/2).';
        if (!$this->acceptNonTransfer) $e['accept_nontransfer'] = 'Zaznacz oświadczenie o nieodstępowaniu licencji (AM5/3).';
        if (!$this->acceptRodo) $e['accept_rodo'] = 'Potwierdź zapoznanie się z informacją o przetwarzaniu danych.';
        return $e;
    }

    /** Wartości pól danych osoby (klucze jak FIELDS). */
    public function fields(): array
    {
        return ['name' => $this->name, 'email' => $this->email, 'phone' => $this->phone, 'addr_street' => $this->addrStreet,
                'addr_house' => $this->addrHouse, 'addr_flat' => $this->addrFlat, 'addr_postal' => $this->addrPostal, 'addr_city' => $this->addrCity];
    }

    /** Adres jednym wierszem (do druku i k30_clients.address). */
    public function addressLine(): string
    {
        $street = trim($this->addrStreet . ' ' . $this->addrHouse . ($this->addrFlat !== '' ? '/' . $this->addrFlat : ''));
        return trim(implode(', ', array_filter([$street, trim($this->addrPostal . ' ' . $this->addrCity)])), ', ');
    }
}

/** Wniosek AM7 (panel): kursant, kurs, konto, plan Microsoft 365, sposób dostępu (tenant ośrodka / inny), okres, uzasadnienie. */
final class Am7RequestDto
{
    public function __construct(
        public readonly string $place,
        public readonly string $date,
        public readonly string $to,
        public readonly ?int $courseId,
        public readonly string $courseName,
        public readonly string $studentKind,     // client | external
        public readonly ?int $studentId,
        public readonly string $studentName,
        public readonly string $studentEmail,
        public readonly string $studentPhone,
        public readonly string $account,
        public readonly string $plan,
        public readonly string $access,          // tenant | other
        public readonly string $accessOther,
        public readonly string $periodFrom,
        public readonly string $periodTo,
        public readonly string $justification,
    ) {}

    public static function fromArray(array $in): self
    {
        $kind = LdInput::str($in, 'interested_kind', 10) === 'external' ? 'external' : 'client';
        $sid = (int)($in['interested_id_client'] ?? ($in['interested_id'] ?? 0));
        $cid = (int)($in['course_id'] ?? 0);
        $acc = LdInput::str($in, 'access', 8);
        return new self(
            LdInput::str($in, 'place', 120), LdInput::date($in, 'date') ?: ldToday(), LdInput::str($in, 'to', 255),
            $cid > 0 ? $cid : null, LdInput::str($in, 'course_name', 255),
            $kind, $kind === 'client' && $sid > 0 ? $sid : null,
            LdInput::str($in, 'interested_name', 190), LdInput::str($in, 'interested_email', 190), LdInput::str($in, 'interested_phone', 40),
            LdInput::str($in, 'account', 190), LdInput::str($in, 'plan', 190), in_array($acc, ['tenant', 'other'], true) ? $acc : 'tenant',
            LdInput::str($in, 'access_other', 255), LdInput::date($in, 'period_from'), LdInput::date($in, 'period_to'), LdInput::str($in, 'justification', 4000),
        );
    }

    public function errors(bool $forStart = false): array
    {
        $e = [];
        if ($this->studentKind === 'client' && !$this->studentId) $e['interested_kind'] = 'Wybierz kursanta (albo „spoza SZO” i wpisz dane).';
        if ($this->studentKind === 'external' && $this->studentName === '') $e['interested_name'] = 'Podaj imię i nazwisko kursanta.';
        if (!LdInput::email($this->studentEmail)) $e['interested_email'] = 'Nieprawidłowy e-mail kursanta.';
        if (!LdInput::phone($this->studentPhone)) $e['interested_phone'] = 'Nieprawidłowy telefon kursanta.';
        if ($this->periodFrom !== '' && $this->periodTo !== '' && $this->periodTo < $this->periodFrom) $e['period_to'] = 'Koniec okresu jest przed początkiem.';
        if ($forStart) {
            if ($this->plan === '') $e['plan'] = 'Podaj wnioskowany plan Microsoft 365.';
            if ($this->access === 'other' && $this->accessOther === '') $e['access_other'] = 'Opisz inny sposób dostępu.';
            if ($this->periodFrom === '' || $this->periodTo === '') $e['period_from'] = 'Podaj wnioskowany okres.';
            if ($this->courseName === '' && !$this->courseId) $e['course_name'] = 'Podaj kurs lub program dydaktyczny.';
        }
        return $e;
    }

    public function requestData(): array
    {
        return ['place' => $this->place, 'date' => $this->date, 'account' => $this->account, 'plan' => $this->plan, 'access' => $this->access,
                'access_other' => $this->accessOther, 'justification' => $this->justification];
    }
}

/** Podpis wniosku AM7 przez kursanta w portalu (po tokenie z e-maila / SMS). */
final class Am7SignDto
{
    public function __construct(public readonly string $name, public readonly string $place, public readonly string $justification, public readonly bool $confirm) {}

    public static function fromArray(array $in): self
    {
        return new self(LdInput::str($in, 'name', 190), LdInput::str($in, 'place', 120), LdInput::str($in, 'justification', 4000), LdInput::bool($in, 'confirm'));
    }

    public function errors(): array
    {
        $e = [];
        if (mb_strlen($this->name) < 3) $e['name'] = 'Wpisz imię i nazwisko — to Twój podpis.';
        if ($this->place === '') $e['place'] = 'Podaj miejscowość.';
        if (!$this->confirm) $e['confirm'] = 'Zaznacz oświadczenie, aby podpisać wniosek.';
        return $e;
    }
}

/** Decyzja placówki AM7/2 (przyznanie) albo AM7/3 (odmowa) — wydawana w panelu, wysyłana kursantowi w PDF. */
final class Am7DecisionDto
{
    public function __construct(
        public readonly bool $grant,
        public readonly string $access,
        public readonly string $accessOther,
        public readonly string $account,
        public readonly string $plan,
        public readonly string $services,
        public readonly string $periodFrom,
        public readonly string $periodTo,
        public readonly string $restrictions,
        public readonly string $afterEnd,
        public readonly string $accountData,
        public readonly string $refusalReason,
        public readonly string $signerName,
        public readonly bool $paperSigned,
    ) {}

    public static function fromArray(array $in): self
    {
        $acc = LdInput::str($in, 'access', 8);
        return new self(
            LdInput::str($in, 'decision', 8) === 'grant', in_array($acc, ['tenant', 'other'], true) ? $acc : 'tenant', LdInput::str($in, 'access_other', 255),
            LdInput::str($in, 'account', 190), LdInput::str($in, 'plan', 190), LdInput::str($in, 'services', 1000),
            LdInput::date($in, 'period_from'), LdInput::date($in, 'period_to'), LdInput::str($in, 'restrictions', 2000),
            LdInput::str($in, 'after_end', 2000), LdInput::str($in, 'account_data', 2000), LdInput::str($in, 'refusal_reason', 2000),
            LdInput::str($in, 'signer_name', 190), LdInput::bool($in, 'paper_signed'),
        );
    }

    public function errors(): array
    {
        $e = [];
        if ($this->signerName === '') $e['signer_name'] = 'Podaj osobę upoważnioną do podjęcia decyzji.';
        if ($this->grant) {
            if ($this->account === '') $e['account'] = 'Podaj konto przypisane kursantowi.';
            if ($this->plan === '') $e['plan'] = 'Podaj plan Microsoft 365 / SKU.';
            if ($this->access === 'other' && $this->accessOther === '') $e['access_other'] = 'Opisz inny sposób udostępnienia.';
            if ($this->periodFrom === '' || $this->periodTo === '') $e['period_from'] = 'Podaj okres aktywnego dostępu.';
            elseif ($this->periodTo < $this->periodFrom) $e['period_to'] = 'Koniec okresu jest przed początkiem.';
            if ($this->afterEnd === '') $e['after_end'] = 'Opisz postępowanie po zakończeniu kursu lub okresu dostępu.';
        } elseif ($this->refusalReason === '') {
            $e['refusal_reason'] = 'Podaj uzasadnienie odmowy.';
        }
        return $e;
    }

    public function data(): array
    {
        return ['grant' => $this->grant, 'access' => $this->access, 'access_other' => $this->accessOther, 'account' => $this->account, 'plan' => $this->plan,
                'services' => $this->services, 'period_from' => $this->periodFrom, 'period_to' => $this->periodTo, 'restrictions' => $this->restrictions,
                'after_end' => $this->afterEnd, 'account_data' => $this->accountData, 'refusal_reason' => $this->refusalReason,
                'signer_name' => $this->signerName, 'date' => ldToday()];
    }
}
