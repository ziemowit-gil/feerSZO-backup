<?php
/**
 * Backend książki „Fundacja FEER” (feer_directory): rcube_addressbook tylko do odczytu
 * na tablicy osób z SZO (wzór: wbudowany plugin example_addressbook).
 */
class feer_directory_backend extends rcube_addressbook
{
    public $primary_key = 'ID';
    public $readonly = true;
    public $groups = false;
    public $coltypes = ['name', 'firstname', 'surname', 'email', 'phone', 'jobtitle', 'department', 'organization'];

    /** @var array<string, array<string, mixed>> rekordy wg ID */
    private array $records = [];
    private $filter;
    private $result;

    public function __construct(array $people)
    {
        $this->ready = true;
        foreach ($people as $p) {
            $id = 'feer' . (int) ($p['id'] ?? 0);
            $rec = [
                'ID'           => $id,
                'name'         => (string) ($p['name'] ?? ''),
                'firstname'    => (string) ($p['first_name'] ?? ''),
                'surname'      => (string) ($p['last_name'] ?? ''),
                'email:work'   => [(string) ($p['email'] ?? '')],
                'organization' => 'Fundacja FEER',
            ];
            if (!empty($p['title'])) {
                $rec['jobtitle'] = (string) $p['title'];
            }
            if (!empty($p['unit'])) {
                $rec['department'] = (string) $p['unit'];
            }
            if (!empty($p['phone'])) {
                $rec['phone:work'] = [(string) $p['phone']];
            }
            $this->records[$id] = $rec;
        }
    }

    public function get_name()
    {
        return 'Fundacja FEER';
    }

    public function set_search_set($filter): void
    {
        $this->filter = $filter;
    }

    public function get_search_set()
    {
        return $this->filter;
    }

    public function reset(): void
    {
        $this->result = null;
        $this->filter = null;
    }

    public function list_groups($search = null, $mode = 0)
    {
        return [];
    }

    public function list_records($cols = null, $subset = 0, $nocount = false)
    {
        $recs = $this->filter !== null ? $this->filter : array_values($this->records);
        return $this->result = $this->slice($recs, $subset);
    }

    public function search($fields, $value, $mode = 0, $select = true, $nocount = false, $required = [])
    {
        $values = (array) $value;
        $fields = $fields === '*' || $fields === ['*']
            ? ['name', 'firstname', 'surname', 'email', 'jobtitle', 'department']
            : (array) $fields;
        $strict = ($mode & rcube_addressbook::SEARCH_STRICT) !== 0;
        $prefix = ($mode & rcube_addressbook::SEARCH_PREFIX) !== 0;

        $hits = [];
        foreach ($this->records as $rec) {
            $ok = false;
            foreach ($fields as $i => $f) {
                $needle = mb_strtolower((string) ($values[$i] ?? $values[0] ?? ''));
                if ($needle === '') {
                    continue;
                }
                foreach ($this->field_values($rec, (string) $f) as $hay) {
                    $hay = mb_strtolower($hay);
                    if ($strict ? $hay === $needle
                        : ($prefix ? (mb_strpos($hay, $needle) === 0 || mb_strpos($hay, ' ' . $needle) !== false)
                                   : mb_strpos($hay, $needle) !== false)) {
                        $ok = true;
                        break 2;
                    }
                }
            }
            if ($ok) {
                foreach ((array) $required as $req) {
                    if (!$this->field_values($rec, (string) $req)) {
                        $ok = false;
                        break;
                    }
                }
            }
            if ($ok) {
                $hits[] = $rec;
            }
        }

        $this->filter = $hits;
        return $select ? $this->list_records() : $this->slice($hits, 0, true);
    }

    public function count()
    {
        $n = $this->filter !== null ? count($this->filter) : count($this->records);
        return new rcube_result_set($n, ($this->list_page - 1) * $this->page_size);
    }

    public function get_result()
    {
        return $this->result;
    }

    public function get_record($id, $assoc = false)
    {
        $rec = $this->records[(string) $id] ?? null;
        if (!$rec) {
            return false;
        }
        $this->result = new rcube_result_set(1);
        $this->result->add($rec);
        return $assoc ? $rec : $this->result;
    }

    /** Wartości pola (email/phone z podtypami „:work”). */
    private function field_values(array $rec, string $f): array
    {
        $out = [];
        foreach ($rec as $k => $v) {
            if ($k === $f || strpos($k, $f . ':') === 0) {
                foreach ((array) $v as $x) {
                    if ((string) $x !== '') {
                        $out[] = (string) $x;
                    }
                }
            }
        }
        return $out;
    }

    private function slice(array $recs, int $subset = 0, bool $all = false): rcube_result_set
    {
        $start = ($this->list_page - 1) * $this->page_size;
        $res = new rcube_result_set(count($recs), $start);
        $part = $all ? $recs : array_slice($recs, $start, $subset > 0 ? $subset : $this->page_size);
        foreach ($part as $rec) {
            $res->add($rec);
        }
        return $res;
    }
}
