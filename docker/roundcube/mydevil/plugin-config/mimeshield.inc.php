<?php
// takenek/mimeshield 1.0.3 (Packagist, GPL-3.0) — S/MIME: podpis, weryfikacja, szyfrowanie, odszyfrowanie.
// Klucze prywatne użytkowników szyfrowane kluczem głównym spoza bazy i spoza katalogu Roundcube.
// KOPIA klucza głównego osobno od kopii bazy (bez niego zapisane klucze są nie do odczytania).
$config['mimeshield_master_key_file'] = '/usr/home/feer/roundcube/secrets/mimeshield.key';
$config['mimeshield_master_key_env'] = 'MIMESHIELD_MASTER_KEY';
$config['mimeshield_master_key_active'] = '';
$config['mimeshield_enable_signing'] = true;
$config['mimeshield_enable_encryption'] = true;
$config['mimeshield_sign_default'] = false;          // dobrowolne, użytkownik włącza w Preferencjach
$config['mimeshield_encrypt_default'] = false;
$config['mimeshield_options_lock'] = [];
$config['mimeshield_encrypt_to_self'] = true;        // nadawca odczyta swoją wysłaną wiadomość
$config['mimeshield_encrypt_drafts'] = true;
$config['mimeshield_bcc_mode'] = 'separate';
$config['mimeshield_decrypt_in_compose'] = true;
$config['mimeshield_require_encrypt_for_decrypted'] = false;
$config['mimeshield_cipher'] = 'aes-256-cbc';
$config['mimeshield_allowed_digests'] = ['sha256', 'sha384', 'sha512'];
$config['mimeshield_legacy_digests'] = ['sha1'];
$config['mimeshield_min_rsa_bits'] = 2048;
$config['mimeshield_ca_bundle'] = ['/usr/home/feer/roundcube/config/smime-roots.pem', '/usr/home/feer/roundcube/config/feer-root-ca.crt']; // 83 CA do poczty (Mozilla NSS, tools/smime_roots.py) + FEER Root CA
$config['mimeshield_use_system_ca'] = false;
// Pośrednie CA dokładane do budowy łańcucha (wiadomości bez pośredniego, własne podpisy): ca/smime-intermediates.pem
$config['mimeshield_intermediates'] = ['/usr/home/feer/roundcube/config/smime-intermediates.pem'];          // CA systemowe są do TLS, nie do poczty
$config['mimeshield_encrypt_untrusted'] = 'warn';     // certyfikat spoza zaufanych CA: zapis po potwierdzeniu
$config['mimeshield_revocation'] = 'off';
$config['mimeshield_max_message_size'] = 52428800;
$config['mimeshield_temp_dir'] = '/usr/home/feer/roundcube/tmp';
$config['mimeshield_pkcs12_legacy_cli'] = false;
$config['mimeshield_debug'] = false;
