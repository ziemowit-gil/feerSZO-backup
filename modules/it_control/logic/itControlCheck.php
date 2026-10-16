<?php
/**
 * modules/it_control/logic/itControlCheck.php — kontrakt sprawdzeń modułów kontroli IT.
 *
 * Każde sprawdzenie (check) to klasa dziedzicząca po ItControlCheck:
 *   - id()     — trwały identyfikator „suite.nazwa” (klucz w historii i ustawieniach),
 *   - suite()  — 'logs' (Kontrola Dzienników) albo 'ops' (Kontrola ogólna),
 *   - title()  — nazwa dla ludzi,
 *   - execute(ItControlContext, ItControlResult) — właściwa logika; wypełnia wynik.
 *
 * Runner (itControlRunner.php) mierzy czas i łapie wyjątki — sprawdzenie, które
 * rzuci wyjątkiem, kończy się FAIL z opisem błędu (nie zatrzymuje pozostałych).
 * Nowe sprawdzenie = nowa klasa w logic/checks/ + jedna linia w itControlRegistry.php.
 */

/** Statusy wyniku — kolejność = waga przy agregacji (najgorszy wygrywa). */
const ITC_STATUS_WEIGHT = ['SKIPPED' => 0, 'PASS' => 1, 'WARNING' => 2, 'FAIL' => 3];

/** Waga ważności znaleziska → status, jaki wymusza na wyniku. */
const ITC_SEVERITY = [
    'critical' => ['label' => 'krytyczne', 'status' => 'FAIL'],
    'high'     => ['label' => 'wysokie',   'status' => 'FAIL'],
    'medium'   => ['label' => 'średnie',   'status' => 'WARNING'],
    'low'      => ['label' => 'niskie',    'status' => 'WARNING'],
    'info'     => ['label' => 'informacja','status' => 'PASS'],
];

/** Najgorszy z podanych statusów. */
function itControlWorstStatus(array $statuses): string {
    $worst = 'SKIPPED';
    foreach ($statuses as $s) {
        if ((ITC_STATUS_WEIGHT[$s] ?? 0) > ITC_STATUS_WEIGHT[$worst]) $worst = $s;
    }
    return $worst;
}

final class ItControlResult
{
    public string $status = 'PASS';
    public string $summary = '';
    public int $eventsProcessed = 0;
    /** @var array<int,array{key:string,severity:string,title:string,detail:string,count:int}> */
    public array $findings = [];
    /** @var string[] */
    public array $recommendations = [];
    /** @var array<string,mixed> */
    public array $metrics = [];
    public int $durationMs = 0;

    public function __construct(public string $checkId = '', public string $title = '') {}

    /**
     * Dodaje znalezisko i podnosi status do poziomu wynikającego z ważności.
     * $key — stały identyfikator znaleziska w obrębie checka (bezpiecznik alertów
     * porównuje zestawy kluczy krytycznych znalezisk między przebiegami).
     */
    public function finding(string $key, string $severity, string $title, string $detail = '', int $count = 1): self {
        if (!isset(ITC_SEVERITY[$severity])) $severity = 'medium';
        $this->findings[] = compact('key', 'severity', 'title', 'detail', 'count');
        $this->raise(ITC_SEVERITY[$severity]['status']);
        return $this;
    }

    public function recommend(string $text): self {
        if (!in_array($text, $this->recommendations, true)) $this->recommendations[] = $text;
        return $this;
    }

    public function metric(string $name, mixed $value): self {
        $this->metrics[$name] = $value;
        return $this;
    }

    /** Podnosi status (nigdy nie obniża). */
    public function raise(string $status): self {
        $this->status = itControlWorstStatus([$this->status, $status]);
        return $this;
    }

    public function skip(string $why): self {
        $this->status = 'SKIPPED';
        $this->summary = $why;
        return $this;
    }

    public function criticalCount(): int {
        return count(array_filter($this->findings, fn($f) => $f['severity'] === 'critical'));
    }

    public function toArray(): array {
        return [
            'check_id' => $this->checkId, 'title' => $this->title, 'status' => $this->status,
            'summary' => $this->summary, 'events_processed' => $this->eventsProcessed,
            'findings' => $this->findings, 'recommendations' => $this->recommendations,
            'metrics' => $this->metrics, 'duration_ms' => $this->durationMs,
        ];
    }
}

/** Wspólny kontekst przebiegu: ustawienia, okno czasu, tryb próbny. */
final class ItControlContext
{
    public int $now;
    /** @var array<string,mixed> pamięć podręczna współdzielona przez checki jednego przebiegu */
    public array $cache = [];

    public function __construct(
        public array $settings,
        public bool $dryRun = false,
        ?int $now = null
    ) {
        $this->now = $now ?? time();
    }

    public function setting(string $key): mixed {
        return $this->settings[$key] ?? (ITC_DEFAULTS[$key] ?? null);
    }

    /** Początek okna analizy dzienników (unix). */
    public function windowStart(): int {
        return $this->now - max(1, (int)$this->setting('log_window_hours')) * 3600;
    }

    /** Okno w formacie daty SQL (czas lokalny — tak zapisują tabele dzienników). */
    public function windowStartSql(): string {
        return date('Y-m-d H:i:s', $this->windowStart());
    }

    /** Wynik raz policzony w przebiegu (np. odczyt plików dzienników współdzielony przez checki). */
    public function remember(string $key, callable $fn): mixed {
        if (!array_key_exists($key, $this->cache)) $this->cache[$key] = $fn();
        return $this->cache[$key];
    }
}

abstract class ItControlCheck
{
    abstract public function id(): string;
    abstract public function suite(): string;
    abstract public function title(): string;

    /** Krótki opis „co sprawdza” (strona ustawień). */
    public function description(): string { return ''; }

    abstract protected function execute(ItControlContext $ctx, ItControlResult $r): void;

    final public function run(ItControlContext $ctx): ItControlResult {
        $r = new ItControlResult($this->id(), $this->title());
        $t0 = hrtime(true);
        try {
            $this->execute($ctx, $r);
        } catch (\Throwable $e) {
            $r->finding('exception', 'high', 'Sprawdzenie zakończyło się błędem',
                get_class($e) . ': ' . mb_substr($e->getMessage(), 0, 500));
            $r->summary = 'Błąd wykonania sprawdzenia.';
            $r->recommend('Sprawdź konfigurację i logi PHP — sprawdzenie „' . $this->title() . '” nie mogło się wykonać.');
        }
        $r->durationMs = (int)((hrtime(true) - $t0) / 1e6);
        if ($r->summary === '') $r->summary = $r->status === 'PASS' ? 'Bez uwag.' : count($r->findings) . ' znalezisk(a).';
        return $r;
    }

    /** Zapytanie odporne na brak tabeli (moduł mógł nigdy nie zostać użyty). */
    protected function tryAll(string $sql, array $params = []): ?array {
        try { return db_all($sql, $params); } catch (\Throwable $e) { return null; }
    }

    protected function tryOne(string $sql, array $params = []): ?array {
        try { return db_one($sql, $params); } catch (\Throwable $e) { return null; }
    }
}
