// Fałszywy skaner eSCL (HTTP) — test klienta i składania PDF bez fizycznego urządzenia.
import assert from 'node:assert/strict';
import http from 'node:http';
import type { AddressInfo } from 'node:net';
import { PDFDocument } from 'pdf-lib';
import { capabilities, scan, type Scanner } from '../src/main/services/escl';
import { pagesToPdf } from '../src/main/services/scans';

const JPEG = Buffer.from('/9j/4AAQSkZJRgABAQAASABIAAD/4QBMRXhpZgAATU0AKgAAAAgAAYdpAAQAAAABAAAAGgAAAAAAA6ABAAMAAAABAAEAAKACAAQAAAABAAAAEKADAAQAAAABAAAAEAAAAAD/7QA4UGhvdG9zaG9wIDMuMAA4QklNBAQAAAAAAAA4QklNBCUAAAAAABDUHYzZjwCyBOmACZjs+EJ+/8AAEQgAEAAQAwEiAAIRAQMRAf/EAB8AAAEFAQEBAQEBAAAAAAAAAAABAgMEBQYHCAkKC//EALUQAAIBAwMCBAMFBQQEAAABfQECAwAEEQUSITFBBhNRYQcicRQygZGhCCNCscEVUtHwJDNicoIJChYXGBkaJSYnKCkqNDU2Nzg5OkNERUZHSElKU1RVVldYWVpjZGVmZ2hpanN0dXZ3eHl6g4SFhoeIiYqSk5SVlpeYmZqio6Slpqeoqaqys7S1tre4ubrCw8TFxsfIycrS09TV1tfY2drh4uPk5ebn6Onq8fLz9PX29/j5+v/EAB8BAAMBAQEBAQEBAQEAAAAAAAABAgMEBQYHCAkKC//EALURAAIBAgQEAwQHBQQEAAECdwABAgMRBAUhMQYSQVEHYXETIjKBCBRCkaGxwQkjM1LwFWJy0QoWJDThJfEXGBkaJicoKSo1Njc4OTpDREVGR0hJSlNUVVZXWFlaY2RlZmdoaWpzdHV2d3h5eoKDhIWGh4iJipKTlJWWl5iZmqKjpKWmp6ipqrKztLW2t7i5usLDxMXGx8jJytLT1NXW19jZ2uLj5OXm5+jp6vLz9PX29/j5+v/bAEMAAgICAgICAwICAwUDAwMFBgUFBQUGCAYGBgYGCAoICAgICAgKCgoKCgoKCgwMDAwMDA4ODg4ODw8PDw8PDw8PD//bAEMBAgICBAQEBwQEBxALCQsQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEBAQEP/dAAQAAf/aAAwDAQACEQMRAD8A/Wiiiiv1Q+LP/9k=', 'base64');
const CAPS = `<?xml version="1.0"?><scan:ScannerCapabilities xmlns:scan="http://schemas.hp.com/imaging/escl/2011/05/03" xmlns:pwg="http://www.pwg.org/schemas/2010/12/sm">
<pwg:Version>2.0</pwg:Version>
<scan:Platen><scan:PlatenInputCaps><scan:MaxWidth>2550</scan:MaxWidth><scan:MaxHeight>3507</scan:MaxHeight>
<scan:SettingProfiles><scan:SettingProfile><scan:ColorModes><scan:ColorMode>RGB24</scan:ColorMode><scan:ColorMode>Grayscale8</scan:ColorMode></scan:ColorModes>
<scan:SupportedResolutions><scan:DiscreteResolutions><scan:DiscreteResolution><scan:XResolution>200</scan:XResolution><scan:YResolution>200</scan:YResolution></scan:DiscreteResolution><scan:DiscreteResolution><scan:XResolution>300</scan:XResolution><scan:YResolution>300</scan:YResolution></scan:DiscreteResolution></scan:DiscreteResolutions></scan:SupportedResolutions>
</scan:SettingProfile></scan:SettingProfiles></scan:PlatenInputCaps></scan:Platen>
<scan:Adf><scan:AdfSimplexInputCaps><scan:MaxWidth>2550</scan:MaxWidth><scan:MaxHeight>3507</scan:MaxHeight></scan:AdfSimplexInputCaps><scan:AdfDuplexInputCaps/></scan:Adf>
</scan:ScannerCapabilities>`;

let served = 0, deleted = false, lastBody = '';
const srv = http.createServer((req, res) => {
  const chunks: Buffer[] = [];
  req.on('data', (c: Buffer) => chunks.push(c));
  req.on('end', () => {
    if (req.url === '/eSCL/ScannerCapabilities') { res.writeHead(200, { 'content-type': 'text/xml' }); return res.end(CAPS); }
    if (req.method === 'POST' && req.url === '/eSCL/ScanJobs') { lastBody = Buffer.concat(chunks).toString(); served = 0; res.writeHead(201, { location: '/eSCL/ScanJobs/42' }); return res.end(); }
    if (req.url === '/eSCL/ScanJobs/42/NextDocument') {
      if (served++ < 3) { res.writeHead(200, { 'content-type': 'image/jpeg' }); return res.end(JPEG); }
      res.writeHead(404); return res.end();
    }
    if (req.method === 'DELETE' && req.url === '/eSCL/ScanJobs/42') { deleted = true; res.writeHead(200); return res.end(); }
    res.writeHead(500); res.end();
  });
});

srv.listen(0, '127.0.0.1', async () => {
  const port = (srv.address() as AddressInfo).port;
  const s: Scanner = { id: 'x', name: 'Fake', host: '127.0.0.1', port, secure: false, root: 'eSCL' };
  try {
    const caps = await capabilities(s);
    assert.deepEqual(caps.sources, ['Platen', 'Feeder']);
    assert.equal(caps.duplex, true);
    assert.deepEqual(caps.resolutions, [200, 300]);
    const feeder = await scan(s, { source: 'Feeder', duplex: true, color: 'RGB24', dpi: 300 }, caps);
    assert.equal(feeder.length, 3, 'ADF: 3 strony do 404');
    assert.match(lastBody, /<pwg:InputSource>Feeder<\/pwg:InputSource>/);
    assert.match(lastBody, /<scan:Duplex>true<\/scan:Duplex>/);
    const platen = await scan(s, { source: 'Platen', duplex: false, color: 'Grayscale8', dpi: 200 }, caps);
    assert.equal(platen.length, 1, 'szyba: jedna strona');
    assert.doesNotMatch(lastBody, /Duplex/);
    await new Promise((r) => setTimeout(r, 50));
    assert.ok(deleted, 'zlecenie sprzątnięte (DELETE)');
    const pdf = await PDFDocument.load(await pagesToPdf(feeder, 300));
    assert.equal(pdf.getPageCount(), 3);
    console.log('ESCL OK');
  } catch (e) { console.error(e); process.exitCode = 1; } finally { srv.close(); }
});
