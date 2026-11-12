<?php
/**
 * modules/user_merge/lib/userMergeDetect.php — wykrywanie zduplikowanych kont użytkowników (Tożsamość).
 *
 * Dwa konta to możliwy duplikat, gdy mają:
 *   • identyczny PESEL (same cyfry; users.rpts_pesel) — sygnał MOCNY,
 *   • identyczne imię i nazwisko bez względu na wielkość liter i białe znaki — sygnał SŁABY (imiennicy!).
 * Konta zgodne nazwiskiem, ale z RÓŻNYMI numerami PESEL, nie są łączone — to różne osoby.
 * Powiązania są przechodnie (A–B po PESEL-u, B–C po nazwisku → jedna grupa „Wykryto 3 osoby”).
 *
 * PESEL bywa zaszyfrowany (modules/field_crypto) — dlatego grupujemy w PHP na wartościach odczytanych przez db()
 * (odszyfrowanych przezroczyście), a nie GROUP BY w SQL. Pomijamy serwis@local i konta już scalone.
 * Nic nie scala się samo — wynik tylko proponuje scalenie administratorowi.
 */

require_once __DIR__ . '/userMergeSchema.php';

/** Same cyfry PESEL-u albo '' (gdy nie ma 11 cyfr). */
function userMergePeselKey(?string $pesel): string {
    $d = preg_replace('/\D+/', '', (string)$pesel);
    return strlen($d) === 11 ? $d : '';
}

/**
 * Klucz „imię i nazwisko”: małe litery, bez białych znaków. Z first_name + last_name, a gdy któregoś brak — z name
 * (bez dopisku po „|”). Wymaga co najmniej dwóch członów — pojedyncze słowo („Administrator”) nie tworzy duplikatów.
 */
function userMergeNameKey(array $u): string {
    $first = trim((string)($u['first_name'] ?? ''));
    $last  = trim((string)($u['last_name'] ?? ''));
    if ($first !== '' && $last !== '') {
        $full = $first . ' ' . $last;
    } else {
        $full = trim((string)preg_replace('/\|.*$/su', '', (string)($u['name'] ?? '')));
    }
    $parts = preg_split('/\s+/u', $full, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (count($parts) < 2) return '';
    return mb_strtolower(implode('', $parts));
}

/** Oba konta mają PESEL i są to różne numery — na pewno różne osoby. */
function userMergePeselConflict(array $a, array $b): bool {
    $pa = userMergePeselKey($a['rpts_pesel'] ?? '');
    $pb = userMergePeselKey($b['rpts_pesel'] ?? '');
    return $pa !== '' && $pb !== '' && $pa !== $pb;
}

/** Konta mają ten sam PESEL (sygnał mocny). */
function userMergePeselMatch(array $a, array $b): bool {
    $pa = userMergePeselKey($a['rpts_pesel'] ?? '');
    return $pa !== '' && $pa === userMergePeselKey($b['rpts_pesel'] ?? '');
}

/**
 * Grupy możliwych duplikatów.
 * @return array{groups: array<string, array{id:string, members:int[], strong:bool, reasons:string[]}>, byUser: array<int,string>}
 *   strong = w grupie jest choć jedna para zgodna PESEL-em; reasons ⊆ ['pesel','name'].
 */
function userMergeGroups(bool $refresh = false): array {
    static $cache = null;
    if ($cache !== null && !$refresh) return $cache;
    userMergeMigrate();

    $rows = db_all("SELECT id, name, first_name, last_name, email, rpts_pesel FROM users
                    WHERE email <> 'serwis@local' AND merged_into_id IS NULL");
    $users = [];
    foreach ($rows as $r) $users[(int)$r['id']] = $r;

    // Union-find
    $parent = [];
    $find = function (int $x) use (&$parent, &$find): int {
        if (!isset($parent[$x])) $parent[$x] = $x;
        if ($parent[$x] !== $x) $parent[$x] = $find($parent[$x]);
        return $parent[$x];
    };
    $union = function (int $a, int $b) use (&$parent, $find): void {
        $ra = $find($a); $rb = $find($b);
        if ($ra !== $rb) $parent[max($ra, $rb)] = min($ra, $rb);
    };

    $byPesel = []; $byName = [];
    foreach ($users as $id => $u) {
        $p = userMergePeselKey($u['rpts_pesel'] ?? '');
        if ($p !== '') $byPesel[$p][] = $id;
        $n = userMergeNameKey($u);
        if ($n !== '') $byName[$n][] = $id;
    }

    $pairs = [];       // [konto, powód] — powody grup liczone po połączeniu (korzeń znany dopiero na końcu)
    foreach ($byPesel as $ids) {
        if (count($ids) < 2) continue;
        for ($i = 1; $i < count($ids); $i++) { $union($ids[0], $ids[$i]); $pairs[] = [$ids[0], 'pesel']; }
    }
    foreach ($byName as $ids) {
        if (count($ids) < 2) continue;
        $n = count($ids);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                if (userMergePeselConflict($users[$ids[$i]], $users[$ids[$j]])) continue;   // imiennicy z różnym PESEL-em
                $union($ids[$i], $ids[$j]);
                $pairs[] = [$ids[$i], 'name'];
            }
        }
    }

    $groups = []; $byUser = [];
    foreach (array_keys($parent) as $id) {
        $root = $find($id);
        $gid = 'g' . $root;
        $groups[$gid] ??= ['id' => $gid, 'members' => [], 'strong' => false, 'reasons' => []];
        $groups[$gid]['members'][] = $id;
        $byUser[$id] = $gid;
    }
    foreach ($pairs as [$id, $why]) {
        $gid = $byUser[$id] ?? null;
        if ($gid === null) continue;
        if (!in_array($why, $groups[$gid]['reasons'], true)) $groups[$gid]['reasons'][] = $why;
        if ($why === 'pesel') $groups[$gid]['strong'] = true;
    }
    foreach ($groups as $gid => &$g) {
        if (count($g['members']) < 2) { unset($groups[$gid]); continue; }
        sort($g['members']);
    }
    unset($g);
    $byUser = array_filter($byUser, fn($gid) => isset($groups[$gid]));

    return $cache = ['groups' => $groups, 'byUser' => $byUser];
}

/** Grupa duplikatów, do której należy konto, albo null. */
function userMergeGroupOf(int $uid): ?array {
    $all = userMergeGroups();
    $gid = $all['byUser'][$uid] ?? null;
    return $gid !== null ? $all['groups'][$gid] : null;
}

/** Etykieta przycisku/alertu: „Wykryto 2 osoby”, „Wykryto 5 osób”. */
function userMergeLabel(int $n): string {
    $mod10 = $n % 10; $mod100 = $n % 100;
    $word = ($mod10 >= 2 && $mod10 <= 4 && ($mod100 < 12 || $mod100 > 14)) ? 'osoby' : 'osób';
    return 'Wykryto ' . $n . ' ' . $word;
}
