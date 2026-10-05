import assert from 'node:assert/strict';
import { fmtBytes, fmtDate, fmtDateTime, fmtPln, isoOf, parseServerDate } from '../src/shared/locale';

const norm = (s: string) => s.replace(/[  ]/g, ' ');
assert.match(norm(fmtDateTime('2026-10-05 21:30')), /5 paź 2026.*21:30/, 'data i godzina po polsku');
assert.match(norm(fmtDate('2026-01-31')), /31 sty 2026/);
assert.equal(norm(fmtPln(48000)), '48 000 zł');
assert.equal(norm(fmtBytes(3_200_000)), '3,1 MB', 'przecinek dziesiętny');
assert.equal(norm(fmtBytes(182_000)), '178 KB');
assert.equal(fmtDateTime('to nie data'), 'to nie data', 'nieparsowalne zwracane bez zmian');
assert.equal(fmtDateTime(null), '');
assert.ok(parseServerDate('2026-10-05T19:30:00Z'));
assert.equal(isoOf('zle'), undefined);
console.log('LOCALE OK');
