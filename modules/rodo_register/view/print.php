<?php
/**
 * Wydruk raportu RODO — samodzielna strona A4 (bez menu SZO).
 * Zmienne: $typ, $tytul, $org, $kto, $kiedy, $rows.
 */
$wide = in_array($typ, ['czynnosci', 'zbiory', 'naruszenia', 'dostep'], true);
$tak = fn($v) => $v ? 'tak' : 'nie';
$th = fn(array $c) => '<tr>' . implode('', array_map(fn($x) => '<th scope="col">' . h($x) . '</th>', $c)) . '</tr>';
?><!doctype html>
<html lang="pl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($tytul) ?></title>
<style>
  @page { size: A4 <?= $wide ? 'landscape' : 'portrait' ?>; margin: 12mm; }
  body { font: 10pt/1.35 Arial, Helvetica, sans-serif; color: #111; margin: 0; padding: 16px; background: #fff; }
  h1 { font-size: 14pt; margin: 0 0 2px; } h2 { font-size: 11pt; margin: 14px 0 4px; } .meta { color: #444; font-size: 9pt; margin-bottom: 10px; }
  table { width: 100%; border-collapse: collapse; } th, td { border: 1px solid #555; padding: 3px 5px; vertical-align: top; text-align: left; font-size: 8.5pt; }
  th { background: #e9e9e9; } tr { page-break-inside: avoid; } thead { display: table-header-group; }
  .group td { background: #f3f3f3; font-weight: bold; } .sign { display: flex; gap: 60px; margin-top: 36px; font-size: 9pt; } .sign div { border-top: 1px solid #000; padding-top: 3px; min-width: 220px; }
  .bar { margin-bottom: 12px; } .bar button { font-size: 11pt; padding: 6px 14px; cursor: pointer; }
  @media print { .bar { display: none; } body { padding: 0; } }
</style></head><body>
<div class="bar"><button type="button" onclick="window.print()">Drukuj</button> <span style="color:#555;font-size:9pt">— w oknie drukowania wybierz „Zapisz jako PDF”, aby otrzymać plik.</span></div>
<h1><?= h($tytul) ?></h1>
<div class="meta"><?= h($org) ?> · stan na <?= h($kiedy) ?><?= $kto !== '' ? ' · wygenerował(a): ' . h($kto) : '' ?></div>

<?php if ($typ === 'czynnosci'): ?>
<table><thead><?= $th(['Lp.', 'Zbiór / czynność', 'Forma zbioru', 'Rola', 'Cel', 'Podstawa prawna', 'Kategorie osób', 'Kategorie danych', 'Dane szczególne', 'Odbiorcy', 'Transfer poza EOG', 'Termin usunięcia', 'Środki bezpieczeństwa']) ?></thead><tbody>
<?php foreach ($rows as $i => $a): ?><tr><td><?= $i + 1 ?></td><td><strong><?= h($a['zbior']) ?></strong></td><td><?= h(RODO_FORMY[$a['forma']] ?? $a['forma']) ?><br><small><?= h($a['lokalizacja']) ?></small></td><td><?= h($a['rola']) ?></td><td><?= h($a['cel']) ?></td><td><?= h($a['podstawa']) ?></td><td><?= h($a['kategorie_osob']) ?></td><td><?= h($a['kategorie_danych']) ?></td><td><?= $tak($a['szczegolne']) ?></td><td><?= h($a['odbiorcy']) ?></td><td><?= h($a['transfer_poza_eog'] ?: '—') ?></td><td><?= h($a['retencja']) ?></td><td><?= h($a['srodki']) ?></td></tr><?php endforeach; ?>
</tbody></table>

<?php elseif ($typ === 'zbiory'): $cur = null; ?>
<table><thead><?= $th(['Lp.', 'Zbiór', 'Miejsce przechowywania', 'Moduły / tabele w SZO', 'Kategorie danych', 'Dane szczególne', 'Właściciel', 'Status']) ?></thead><tbody>
<?php foreach ($rows as $i => $a): if ($cur !== $a['forma']): $cur = $a['forma']; ?><tr class="group"><td colspan="8">Forma zbioru: <?= h(RODO_FORMY[$cur] ?? $cur) ?></td></tr><?php endif; ?>
<tr><td><?= $i + 1 ?></td><td><strong><?= h($a['zbior']) ?></strong></td><td><?= h($a['lokalizacja']) ?></td><td><?= h($a['moduly']) ?></td><td><?= h($a['kategorie_danych']) ?></td><td><?= $tak($a['szczegolne']) ?></td><td><?= h($a['wlasciciel'] ?: '—') ?></td><td><?= h($a['status']) ?></td></tr><?php endforeach; ?>
</tbody></table>

<?php elseif ($typ === 'podmioty'): ?>
<table><thead><?= $th(['Lp.', 'Podmiot', 'Zakres powierzenia', 'Kraj', 'Podstawa transferu', 'Umowa powierzenia', 'Data umowy']) ?></thead><tbody>
<?php foreach ($rows as $i => $p): ?><tr><td><?= $i + 1 ?></td><td><strong><?= h($p['nazwa']) ?></strong></td><td><?= h($p['zakres']) ?></td><td><?= h($p['kraj']) ?></td><td><?= h($p['transfer_podstawa'] ?: '—') ?></td><td><?= h($p['umowa_status']) ?></td><td><?= h($p['umowa_data'] ?: '—') ?></td></tr><?php endforeach; ?>
</tbody></table>

<?php elseif ($typ === 'naruszenia'): ?>
<table><thead><?= $th(['Nr', 'Stwierdzono', 'Opis naruszenia', 'Kategorie danych / osób', 'Liczba osób', 'Skutki', 'Środki zaradcze', 'Ryzyko', 'Zgłoszono do UODO', 'Powiadomiono osoby', 'Status']) ?></thead><tbody>
<?php foreach ($rows as $b): ?><tr><td><?= (int)$b['id'] ?></td><td><?= h($b['wykryto_at']) ?></td><td><?= h($b['opis']) ?></td><td><?= h($b['kategorie_danych']) ?></td><td><?= h($b['liczba_osob'] ?? '—') ?></td><td><?= h($b['skutki']) ?></td><td><?= h($b['srodki_zaradcze']) ?></td><td><?= h($b['ryzyko']) ?></td><td><?= h($b['zgloszono_uodo_at'] ?: ($b['uzasadnienie_braku_zgloszenia'] ? 'nie — ' . $b['uzasadnienie_braku_zgloszenia'] : 'nie')) ?></td><td><?= h($b['powiadomiono_osoby_at'] ?: '—') ?></td><td><?= h(RODO_BREACH_STATUS[$b['status']][0] ?? $b['status']) ?></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="11">Brak zarejestrowanych naruszeń.</td></tr><?php endif; ?>
</tbody></table>

<?php elseif ($typ === 'wnioski'): ?>
<table><thead><?= $th(['Nr', 'Rodzaj wniosku', 'Kanał', 'Data wpłynięcia', 'Termin odpowiedzi', 'Termin przedłużony', 'Tożsamość zweryfikowana', 'Data zamknięcia', 'Status']) ?></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><?= (int)$r['id'] ?></td><td><?= h(RODO_REQUEST_TYPES[$r['typ']] ?? $r['typ']) ?></td><td><?= h($r['kanal']) ?></td><td><?= h($r['data_wplywu']) ?></td><td><?= h($r['termin']) ?></td><td><?= $tak($r['termin_przedluzony']) ?></td><td><?= h($r['tozsamosc_zweryfikowana_at'] ? substr($r['tozsamosc_zweryfikowana_at'], 0, 10) : 'nie') ?></td><td><?= h($r['zamkniete_at'] ? substr($r['zamkniete_at'], 0, 10) : '—') ?></td><td><?= h(RODO_REQUEST_STATUS[$r['status']][0] ?? $r['status']) ?></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="9">Brak wniosków.</td></tr><?php endif; ?>
</tbody></table>
<p class="meta">Raport nie zawiera danych wnioskodawców.</p>

<?php elseif ($typ === 'dostep'): ?>
<h2>1. Role w systemie i moduły, do których dają dostęp</h2>
<table><thead><?= $th(['Rola', 'Opis', 'Aktywnych kont', 'Moduły (poziom dostępu)', 'Zbiory danych objęte dostępem']) ?></thead><tbody>
<?php foreach ($access['roles'] as $r): $zb = []; foreach ($r['modules'] as $m => $_) array_push($zb, ...(RODO_MODULE_ZBIORY[$m] ?? [])); $zb = array_unique($zb); sort($zb); ?>
<tr><td><strong><?= h($r['display']) ?></strong><br><small><?= h($r['name']) ?></small></td><td><?= h($r['opis']) ?></td><td><?= (int)$r['users'] ?></td>
<td><?php if ($r['is_admin']): ?>wszystkie moduły i ustawienia<?php else: foreach ($r['modules'] as $m => $lvl): ?><?= h($m) ?>: <?= h($lvl) ?><br><?php endforeach; if (!$r['modules']) echo '—'; endif; ?></td>
<td><?= $r['is_admin'] ? 'wszystkie zbiory' : h(implode('; ', $zb) ?: '—') ?></td></tr>
<?php endforeach; ?></tbody></table>

<h2>2. Zbiory danych i kto może je przetwarzać</h2>
<table><thead><?= $th(['Zbiór', 'Role z dostępem (moduł: poziom)', 'Osoby (z roli lub uprawnień indywidualnych) — bez administratorów']) ?></thead><tbody>
<?php foreach ($access['by_zbior'] as $z => $b): ?><tr><td><strong><?= h($z) ?></strong></td>
<td><?php foreach ($b['roles'] as $rn => $lvl): ?><?= h($rn) ?><?= $lvl !== 'pełny dostęp' ? ' — ' . h($lvl) : ' — pełny dostęp' ?><br><?php endforeach; if (!$b['roles']) echo '—'; ?></td>
<td><?= h(implode(', ', $b['users']) ?: '—') ?></td></tr><?php endforeach; ?></tbody></table>

<h2>3. Ewidencja osób upoważnionych do przetwarzania</h2>
<table><thead><?= $th(['Lp.', 'Osoba', 'Rola', 'Konto', 'Uprawnienia indywidualne', 'Role i flagi cząstkowe', 'Zbiory (łącznie)']) ?></thead><tbody>
<?php foreach ($access['users'] as $i => $u): ?><tr><td><?= $i + 1 ?></td><td><?= h($u['label']) ?></td><td><?= h($u['role']) ?></td><td><?= $u['active'] ? 'aktywne' : 'nieaktywne' ?></td>
<td><?= h(implode('; ', $u['direct']) ?: '—') ?></td><td><?= h(implode('; ', $u['special']) ?: '—') ?></td>
<td><?= !empty($u['all']) ? 'wszystkie zbiory' : h(implode('; ', $u['zbiory']) ?: '—') ?></td></tr><?php endforeach; ?>
<?php if (!$access['users']): ?><tr><td colspan="7">Brak kont.</td></tr><?php endif; ?></tbody></table>
<p class="meta">Mapowanie moduł → zbiór jest orientacyjne (stała RODO_MODULE_ZBIORY w modules/rodo_register/logic/rodoRegister.php) — zweryfikuj je z IOD. Dostęp do pojedynczych umów (contract_access), skrzynek pocztowych i spraw EZD może być węższy lub szerszy niż wynika z roli.</p>

<?php else: ?>
<table><thead><?= $th(['Zbiór', 'Tabela.kolumna daty', 'Okres (mies.)', 'Wierszy', 'Po terminie', 'Uwagi']) ?></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td><?= h($r['zbior']) ?></td><td><?= h($r['tabela'] . '.' . $r['kolumna_daty']) ?></td><td><?= (int)$r['miesiecy'] ?></td><td><?= $r['blad'] ? h($r['blad']) : (int)$r['razem'] ?></td><td><?= $r['blad'] ? '—' : (int)$r['kandydatow'] ?></td><td><?= h($r['uwagi']) ?></td></tr><?php endforeach; ?>
</tbody></table>
<?php endif; ?>

<div class="sign"><div>Sporządził(a)</div><div>Zatwierdził(a) — data i podpis</div></div>
</body></html>
