<?php require_once __DIR__ . '/shell_safe.php';
/**
 * Wykrywanie i kryptograficzna walidacja podpisu elektronicznego plików.
 * Wydzielone z ezd.php, by używać w module umów (contracts/) bez migracji EZD.
 * Brak zależności od bazy — same funkcje pomocnicze (OpenSSL).
 */

// ── Wykrywanie podpisu elektronicznego w plikach ─────────────────────────────

const EZD_SIG_EXTS = ['pdf','xml','p7s','p7m','pkcs7','xades','asice','asics','sig'];

/** Pomocnik: wyciąga literalny string ze słownika podpisu PDF: /Klucz (wartość). */
function _ezd_pdf_str(string $data, string $key): ?string {
    if (preg_match('/\/' . $key . '\s*\(((?:[^()\\\\]|\\\\.)*)\)/', $data, $m)) {
        $s = trim(preg_replace('/\\\\([()\\\\])/', '$1', $m[1]));
        return $s !== '' ? $s : null;
    }
    return null;
}

/**
 * Heurystyczne wykrycie podpisu elektronicznego pliku + najlepsze dostępne dane.
 * Obsługa: PAdES (PDF), XAdES/XML-DSig (XML), CAdES/PKCS#7 (.p7s/.p7m), ASiC.
 * @return array{signed:bool,type:string,signer:?string,signed_at:?string,reason:?string,location:?string,note:?string}
 */
function ezd_signature_info(string $path, string $name): array {
    $res = ['signed'=>false,'type'=>'','signer'=>null,'signed_at'=>null,'reason'=>null,'location'=>null,'note'=>null];
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!is_file($path)) return $res;

    // Pliki będące samym kontenerem podpisu
    $sigExts = [
        'p7s'=>'CAdES / PKCS#7','p7m'=>'CAdES / PKCS#7','pkcs7'=>'PKCS#7',
        'xades'=>'XAdES','asice'=>'ASiC-E','asics'=>'ASiC-S','sig'=>'Podpis elektroniczny',
    ];
    if (isset($sigExts[$ext])) { $res['signed'] = true; $res['type'] = $sigExts[$ext]; }

    // Czytanie zawartości (do 2 MB w całości; większe — początek + koniec)
    $size = (int)@filesize($path);
    if ($size > 0 && $size <= 2*1024*1024) {
        $data = (string)@file_get_contents($path);
    } else {
        $fh = @fopen($path, 'rb'); $data = '';
        if ($fh) { $data = (string)fread($fh, 524288); if ($size > 524288) { @fseek($fh, -524288, SEEK_END); $data .= "\n" . (string)fread($fh, 524288); } fclose($fh); }
    }
    if ($data === '') return $res;

    // PAdES (podpisany PDF)
    if ($ext === 'pdf' || strncmp($data, '%PDF', 4) === 0) {
        if (preg_match('/\/ByteRange\s*\[/', $data) && preg_match('/\/(Sig|SubFilter|Contents)/', $data)) {
            $res['signed'] = true;
            if (preg_match('/\/SubFilter\s*\/([A-Za-z0-9.]+)/', $data, $m)) {
                $sf = $m[1];
                $res['type'] = str_contains($sf,'ETSI.CAdES') ? 'PAdES (ETSI.CAdES)'
                    : (str_contains($sf,'adbe.pkcs7') ? 'PAdES (adbe.pkcs7)'
                    : (str_contains($sf,'ETSI.RFC3161') ? 'Znacznik czasu (PAdES-T)' : 'PAdES (' . $sf . ')'));
            } else { $res['type'] = 'PAdES'; }
            $res['signer']   = _ezd_pdf_str($data, 'Name');
            $res['reason']   = _ezd_pdf_str($data, 'Reason');
            $res['location'] = _ezd_pdf_str($data, 'Location');
            $mdate = _ezd_pdf_str($data, 'M');
            if ($mdate && preg_match("/D:(\d{4})(\d{2})(\d{2})(\d{2})?(\d{2})?(\d{2})?/", $mdate, $d)) {
                $res['signed_at'] = "$d[1]-$d[2]-$d[3]" . (!empty($d[4]) ? " {$d[4]}:" . ($d[5] ?? '00') : '');
            }
            $cnt = preg_match_all('/\/ByteRange\s*\[/', $data);
            if ($cnt > 1) $res['note'] = 'Liczba podpisów: ' . $cnt;
        }
    }
    // XAdES / XML-DSig
    elseif ($ext === 'xml' || str_contains($data, '<ds:Signature') || preg_match('/<Signature[\s>]/', $data)) {
        if (preg_match('/<(ds:)?Signature[\s>]/', $data) || stripos($data, 'XAdES') !== false) {
            $res['signed'] = true;
            $res['type'] = stripos($data, 'XAdES') !== false ? 'XAdES' : 'XML-DSig';
            if (preg_match('/<(?:ds:)?X509SubjectName>([^<]+)</', $data, $m)) $res['signer'] = trim($m[1]);
            if (preg_match('/<(?:xades:)?SigningTime>([^<]+)</', $data, $m)) $res['signed_at'] = substr(trim($m[1]), 0, 19);
        }
    }

    // ASiC / inny ZIP z podpisami w META-INF
    if (!$res['signed'] && in_array($ext, ['asice','asics','zip'], true)
        && str_contains($data, 'META-INF/') && (str_contains($data, 'signature') || str_contains($data, 'signatures'))) {
        $res['signed'] = true; $res['type'] = 'ASiC';
    }

    return $res;
}

/** Czy dostępne jest narzędzie CLI openssl (do rozpakowania CMS i weryfikacji integralności). */
function _ezd_openssl_cli(): ?string {
    static $bin = false;
    if ($bin !== false) return $bin;
    $bin = null;
    if (function_exists('shell_exec') && !in_array('shell_exec', array_map('trim', explode(',', (string)ini_get('disable_functions'))), true)) {
        $v = @shell_exec('openssl version 2>/dev/null');
        if (is_string($v) && stripos($v, 'openssl') !== false) $bin = 'openssl';
    }
    return $bin;
}

function _ezd_x509_summary(string $pem): ?array {
    if (!function_exists('openssl_x509_parse')) return null;
    $info = @openssl_x509_parse($pem);
    if (!is_array($info)) return null;
    $now = time();
    $vf  = $info['validFrom_time_t'] ?? null;
    $vt  = $info['validTo_time_t'] ?? null;
    $subj = $info['subject'] ?? [];
    $iss  = $info['issuer'] ?? [];
    return [
        'cn'        => $subj['CN'] ?? ($subj['OU'] ?? ($subj['O'] ?? '—')),
        'o'         => $subj['O'] ?? '',
        'sn'        => trim((($subj['SN'] ?? '') . ' ' . ($subj['GN'] ?? ''))),
        'issuer'    => $iss['CN'] ?? ($iss['O'] ?? '—'),
        'serial'    => $info['serialNumberHex'] ?? (string)($info['serialNumber'] ?? ''),
        'from'      => $vf ? date('Y-m-d', $vf) : '',
        'to'        => $vt ? date('Y-m-d', $vt) : '',
        'valid_now' => ($vf && $vt) ? ($now >= $vf && $now <= $vt) : null,
        'days_left' => $vt ? (int)floor(($vt - $now) / 86400) : null,
    ];
}

/**
 * Kryptograficzna walidacja podpisu: certyfikat(y) podpisującego (CN, wystawca,
 * ważność) + integralność (czy dokument nie zmieniony od podpisania — PAdES/CMS).
 * Wymaga OpenSSL (rozszerzenie PHP do parsowania certów; CLI do CMS/integralności).
 * Degraduje się łagodnie z czytelnym komunikatem.
 * @return array{supported:bool,method:string,integrity:string,certs:array,messages:array,openssl_php:bool,openssl_cli:bool}
 */
function ezd_validate_signature(string $path, string $name): array {
    $out = ['supported'=>false,'method'=>'','integrity'=>'n/d','certs'=>[],'messages'=>[],
            'openssl_php'=>function_exists('openssl_x509_parse'), 'openssl_cli'=>(bool)_ezd_openssl_cli()];
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!is_file($path)) { $out['messages'][] = 'Plik nie istnieje.'; return $out; }
    if (!$out['openssl_php']) { $out['messages'][] = 'Rozszerzenie OpenSSL PHP niedostępne — walidacja niemożliwa.'; return $out; }

    $cli  = _ezd_openssl_cli();
    $data = (string)@file_get_contents($path);
    if ($data === '') { $out['messages'][] = 'Nie można odczytać pliku.'; return $out; }

    $certPems = [];          // gotowe certy PEM do sparsowania
    $cmsTmp   = null;        // tymczasowy plik z CMS (DER/PEM)
    $cmsInform = 'DER';
    $byteRangeData = null;   // dane objęte podpisem (PAdES) — do integralności

    $tmpFiles = [];
    $mktmp = function(string $bin) use (&$tmpFiles): string {
        $t = tempnam(sys_get_temp_dir(), 'ezdsig_'); file_put_contents($t, $bin); $tmpFiles[] = $t; return $t;
    };

    if ($ext === 'pdf' || strncmp($data, '%PDF', 4) === 0) {
        $out['supported'] = true; $out['method'] = 'PAdES / CMS';
        // /Contents <hex> — bierzemy pierwszy (ostatni) podpis
        if (preg_match_all('/\/Contents\s*<([0-9A-Fa-f\s]+)>/', $data, $mm) && $mm[1]) {
            $hex = preg_replace('/\s+/', '', end($mm[1]));
            $der = @hex2bin(rtrim($hex, "0") . (strlen($hex) % 2 ? '0' : '')); // pad nieparzyste
            if ($der === false) $der = @hex2bin(substr($hex, 0, strlen($hex) - (strlen($hex) % 2)));
            if ($der) { $cmsTmp = $mktmp($der); $cmsInform = 'DER'; }
        }
        // ByteRange → dane do weryfikacji integralności
        if (preg_match('/\/ByteRange\s*\[\s*(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/', $data, $br)) {
            $byteRangeData = substr($data, (int)$br[1], (int)$br[2]) . substr($data, (int)$br[3], (int)$br[4]);
        }
    } elseif (in_array($ext, ['p7s','p7m','pkcs7'], true)) {
        $out['supported'] = true; $out['method'] = 'CMS / PKCS#7';
        $cmsInform = str_contains($data, '-----BEGIN') ? 'PEM' : 'DER';
        $cmsTmp = $mktmp($data);
    } elseif ($ext === 'xml' || str_contains($data, 'X509Certificate')) {
        $out['supported'] = true; $out['method'] = 'XAdES / XML-DSig';
        if (preg_match_all('#<(?:ds:)?X509Certificate>\s*([A-Za-z0-9+/=\s]+?)\s*</(?:ds:)?X509Certificate>#', $data, $mm)) {
            foreach ($mm[1] as $b64) {
                $b64 = preg_replace('/\s+/', '', $b64);
                $certPems[] = "-----BEGIN CERTIFICATE-----\n" . chunk_split($b64, 64, "\n") . "-----END CERTIFICATE-----\n";
            }
        }
    } else {
        $out['messages'][] = 'Format pliku nieobsługiwany przez walidator.';
        return $out;
    }

    // Z CMS → wyciągnij certyfikaty (CLI openssl pkcs7 -print_certs)
    if ($cmsTmp && $cli) {
        $pem = szo_shell($cli . ' pkcs7 -inform ' . $cmsInform . ' -in ' . escapeshellarg($cmsTmp) . ' -print_certs 2>/dev/null');
        if (!$pem) { // może to CMS (nie PKCS7 SignedData wrapper) — spróbuj cms
            $pem = szo_shell($cli . ' cms -inform ' . $cmsInform . ' -in ' . escapeshellarg($cmsTmp) . ' -cmsout -print 2>/dev/null');
        }
        if (is_string($pem) && preg_match_all('/-----BEGIN CERTIFICATE-----.+?-----END CERTIFICATE-----/s', $pem, $cm)) {
            foreach ($cm[0] as $c) $certPems[] = $c;
        }
    } elseif ($cmsTmp && !$cli) {
        $out['messages'][] = 'Brak narzędzia CLI openssl — nie można rozpakować certyfikatu z kontenera CMS (możliwa tylko weryfikacja XAdES).';
    }

    // Parsowanie certyfikatów (deduplikacja po numerze seryjnym)
    $seen = [];
    foreach ($certPems as $pem) {
        $s = _ezd_x509_summary($pem);
        if (!$s) continue;
        $key = $s['serial'] . '|' . $s['cn'];
        if (isset($seen[$key])) continue; $seen[$key] = 1;
        $out['certs'][] = $s;
    }

    // Weryfikacja integralności (PAdES detached) — czy dokument niezmieniony
    if ($cli && $cmsTmp && $byteRangeData !== null) {
        $contentTmp = $mktmp($byteRangeData);
        $rc = 1; $o = [];
        @exec($cli . ' cms -verify -inform ' . $cmsInform . ' -in ' . escapeshellarg($cmsTmp)
            . ' -content ' . escapeshellarg($contentTmp) . ' -binary -no_signer_cert_verify -noverify -out /dev/null 2>&1', $o, $rc);
        $out['integrity'] = ($rc === 0) ? 'verified' : 'unverified';
    } elseif ($byteRangeData !== null && !$cli) {
        $out['integrity'] = 'no_tool';
    }

    foreach ($tmpFiles as $t) @unlink($t);

    if (!$out['certs']) {
        $out['messages'][] = $cli
            ? 'Nie udało się odczytać certyfikatu z podpisu (nietypowy format kontenera).'
            : 'Odczyt certyfikatu wymaga narzędzia CLI openssl na serwerze.';
    }
    return $out;
}
