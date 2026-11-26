<?php
/**
 * Jednorazowe utworzenie urzędu certyfikacji FEER (S/MIME) — uruchamiać na serwerze:
 *   php84 tools/feer_ca_init.php /usr/home/feer/roundcube/secrets/feer-ca
 * Tworzy: root.key (zaszyfrowany, hasło w root.pass), root.crt (10 lat),
 *         issuing.key (0400), issuing.crt (5 lat, pathlen 0, emailProtection), chain.pem, serial.
 * Nie nadpisuje istniejącego urzędu. Klucz i hasło roota: WYNIEŚĆ OFFLINE po utworzeniu.
 */
$dir = $argv[1] ?? '';
if ($dir === '' || is_file("$dir/root.crt")) { fwrite(STDERR, "Brak katalogu albo urząd już istnieje\n"); exit(1); }
@mkdir($dir, 0700, true); chmod($dir, 0700);
$org = 'Fundacja Edukacji Empatii Rozwoju FEER';
$cnf = "$dir/openssl.cnf";
file_put_contents($cnf, <<<CNF
[ req ]
distinguished_name = dn
[ dn ]
[ v3_root ]
basicConstraints = critical, CA:true
keyUsage = critical, keyCertSign, cRLSign
subjectKeyIdentifier = hash
[ v3_issuing ]
basicConstraints = critical, CA:true, pathlen:0
keyUsage = critical, keyCertSign, cRLSign
extendedKeyUsage = emailProtection
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid:always
CNF);
$conf = fn($sect) => ['config' => $cnf, 'x509_extensions' => $sect, 'digest_alg' => 'sha256'];
$newKey = fn() => openssl_pkey_new(['private_key_bits' => 4096, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$serial = fn() => (int) (random_int(1 << 30, PHP_INT_MAX >> 1));

// Root
$rootKey = $newKey();
$rootDn = ['countryName' => 'PL', 'organizationName' => $org, 'commonName' => 'FEER Root CA'];
$csr = openssl_csr_new($rootDn, $rootKey, $conf('v3_root'));
$root = openssl_csr_sign($csr, null, $rootKey, 3653, $conf('v3_root'), $serial());
$pass = bin2hex(random_bytes(32));
openssl_pkey_export($rootKey, $rootPem, $pass, ['config' => $cnf, 'encrypt_key_cipher' => OPENSSL_CIPHER_AES_256_CBC]);
file_put_contents("$dir/root.key", $rootPem); file_put_contents("$dir/root.pass", $pass);
openssl_x509_export($root, $rootCrt); file_put_contents("$dir/root.crt", $rootCrt);

// Wydający
$issKey = $newKey();
$issDn = ['countryName' => 'PL', 'organizationName' => $org, 'commonName' => 'FEER S/MIME CA 1'];
$csr = openssl_csr_new($issDn, $issKey, $conf('v3_issuing'));
$iss = openssl_csr_sign($csr, $root, $rootKey, 1826, $conf('v3_issuing'), $serial());
openssl_pkey_export($issKey, $issPem, null, ['config' => $cnf]);
file_put_contents("$dir/issuing.key", $issPem);
openssl_x509_export($iss, $issCrt); file_put_contents("$dir/issuing.crt", $issCrt);
file_put_contents("$dir/chain.pem", $issCrt . $rootCrt);

foreach (['root.key', 'root.pass', 'issuing.key'] as $f) chmod("$dir/$f", 0400);
foreach (['root.crt', 'issuing.crt', 'chain.pem'] as $f) chmod("$dir/$f", 0444);
echo "Urząd FEER utworzony w $dir\n";
echo "root:    ", openssl_x509_parse($root)['name'], " do ", date('Y-m-d', openssl_x509_parse($root)['validTo_time_t']), "\n";
echo "issuing: ", openssl_x509_parse($iss)['name'], " do ", date('Y-m-d', openssl_x509_parse($iss)['validTo_time_t']), "\n";
echo "SHA-256 root: ", openssl_x509_fingerprint($root, 'sha256'), "\n";
