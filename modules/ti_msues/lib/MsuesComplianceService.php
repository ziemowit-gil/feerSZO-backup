<?php
declare(strict_types=1);

/**
 * modules/ti_msues/lib/MsuesComplianceService.php — zgodność kadry z MSUES 2.0 i gotowość usługi do audytu.
 *
 *   validateTrainerReadiness($trainerId, $serviceId) — czy prowadzący może zostać dopuszczony do usługi. Gdy ustawienie
 *       organizacji require_trainer_formal_declaration = 1, brak zatwierdzonego oświadczenia (dla usługi albo ogólnego)
 *       BLOKUJE dopuszczenie (przypisanie prowadzącego / coProwadzącego w kurs.php) i paczkę audytową ZIP.
 *       Gdy wyłączone — wynik dopuszcza, a braki są tylko ostrzeżeniami.
 *   serviceReadiness($serviceId) — checklista każdej osoby z kadry usługi + wskaźnik gotowości (% spełnionych kryteriów wymaganych).
 */
require_once __DIR__ . '/DeclarationRepository.php';
require_once __DIR__ . '/MsuesStaffDocuments.php';
require_once __DIR__ . '/MsuesChecklist.php';

final class TrainerReadiness
{
    /** @param list<string> $reasons */
    public function __construct(
        public readonly bool $allowed,
        public readonly bool $enforced,
        public readonly ?array $declaration,
        public readonly array $reasons,
    ) {}

    public function message(): string
    {
        return implode(' ', $this->reasons);
    }

    public function toArray(): array
    {
        return ['allowed' => $this->allowed, 'enforced' => $this->enforced, 'declaration_id' => $this->declaration['id'] ?? null,
                'declaration_version' => $this->declaration['version'] ?? null, 'reasons' => $this->reasons];
    }
}

final class MsuesComplianceService
{
    public function __construct(
        private readonly DeclarationRepository $declarations = new DeclarationRepository(),
        private readonly MsuesStaffDocuments $documents = new MsuesStaffDocuments(),
    ) {}

    public function declarations(): DeclarationRepository { return $this->declarations; }
    public function documents(): MsuesStaffDocuments { return $this->documents; }

    /** Przełącznik wymagalności formalnego oświadczenia (ustawienie organizacji). */
    public static function declarationRequired(): bool
    {
        return msuesSetting(MSUES_SETTING_REQUIRE) === '1';
    }

    public static function setDeclarationRequired(bool $on, array $actor): void
    {
        org_setting_set(MSUES_SETTING_REQUIRE, $on ? '1' : '0');
        if (function_exists('audit_log')) { try { audit_log('msues.setting.require', ['on' => $on], $actor['id'] ?: null); } catch (\Throwable $e) {} }
    }

    public function validateTrainerReadiness(int $trainerId, int $serviceId): TrainerReadiness
    {
        $enforced = self::declarationRequired();
        $a = $this->declarations->approvedFor($trainerId, $serviceId ?: null);
        if ($a) return new TrainerReadiness(true, $enforced, $a, []);
        $u = MsuesSzoData::user($trainerId);
        $who = $u ? (string)$u['name'] : 'Prowadzący #' . $trainerId;
        $l = $this->declarations->latestFor($trainerId, $serviceId ?: null);
        $why = $who . ' nie ma zatwierdzonego oświadczenia o kwalifikacjach i kompetencjach (MSUES)'
             . ($l ? ' — bieżące jest w statusie „' . $l['statusEnum']->label() . '”.' : '.');
        return new TrainerReadiness(!$enforced, $enforced, null, [$why . ($enforced ? ' Organizacja wymaga go przed dopuszczeniem do usługi.' : '')]);
    }

    /**
     * Gotowość usługi do audytu.
     * @return array{service:array, staff:list<array>, score:int, total:int, done:int, ready:bool, blocked:bool, blockers:list<string>, enforced:bool}
     */
    public function serviceReadiness(int $serviceId): array
    {
        $course = MsuesSzoData::course($serviceId);
        if (!$course) throw new DomainException('Nie ma takiej usługi (grupy).');
        $staff = [];
        $total = 0; $done = 0; $blockers = [];
        foreach (MsuesSzoData::serviceStaff($serviceId) as $uid => $p) {
            $items = MsuesChecklist::evaluate($uid, $serviceId, $this->declarations, $this->documents);
            $req = array_filter($items, fn($i) => $i['required']);
            $ok  = array_filter($req, fn($i) => $i['ok']);
            $total += count($req); $done += count($ok);
            $r = $this->validateTrainerReadiness($uid, $serviceId);
            if (!$r->allowed) $blockers[] = $r->message();
            $staff[] = $p + ['items' => $items, 'required' => count($req), 'done' => count($ok), 'complete' => count($req) === count($ok), 'readiness' => $r->toArray()];
        }
        if (!$staff) $blockers[] = 'Usługa nie ma przypisanego prowadzącego.';
        $score = $total ? (int)floor(100 * $done / $total) : 0;
        return [
            'service' => ['id' => (int)$course['id'], 'name' => MsuesSzoData::courseLabel($course), 'is_active' => (int)$course['is_active'], 'closed_at' => $course['closed_at'] ?? null],
            'staff' => $staff, 'score' => $score, 'total' => $total, 'done' => $done,
            'ready' => $staff && $total === $done, 'blocked' => (bool)$blockers && (self::declarationRequired() || !$staff),
            'blockers' => $blockers, 'enforced' => self::declarationRequired(),
        ];
    }

    /** Skrót dla listy usług: [serviceId => [score, ready, staff_count]] — liczony na żądanie. */
    public function servicesOverview(array $services): array
    {
        $o = [];
        foreach ($services as $s) {
            try { $r = $this->serviceReadiness((int)$s['id']); } catch (\Throwable $e) { continue; }
            $o[(int)$s['id']] = ['score' => $r['score'], 'ready' => $r['ready'], 'blocked' => $r['blocked'], 'staff' => count($r['staff'])];
        }
        return $o;
    }
}

/**
 * Bramka dla miejsc, które dopuszczają prowadzącego do usługi (karty30/ti/dydaktyk/kurs.php: prowadzący, coProwadzący).
 * Zwraca komunikat blokady albo '' (dopuszczony / wymóg wyłączony). Nigdy nie rzuca — błąd modułu nie blokuje pracy.
 */
function msuesAssignGuard(int $trainerId, int $serviceId): string
{
    try {
        if (!MsuesComplianceService::declarationRequired()) return '';
        $r = (new MsuesComplianceService())->validateTrainerReadiness($trainerId, $serviceId);
        return $r->allowed ? '' : $r->message();
    } catch (\Throwable $e) {
        error_log('msuesAssignGuard: ' . $e->getMessage());
        return '';
    }
}
