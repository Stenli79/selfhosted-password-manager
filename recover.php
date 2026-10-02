#!/usr/bin/env php
<?php
declare(strict_types=1);

// ═══════════════════════════════════════════════════════════════════════════════
//  VAULT EMERGENCY RECOVERY SCRIPT
//  Run from terminal: php recover.php vault.db
//
//  Requirements: PHP 7.4+ with openssl and sqlite3 extensions
//  No web server needed. Nothing is written to disk.
// ═══════════════════════════════════════════════════════════════════════════════

const AES_CIPHER         = 'aes-256-gcm';
const AES_KEY_SIZE       = 32;
const AES_IV_SIZE        = 12;
const AES_TAG_SIZE       = 16;
const ARGON2_TIME_COST   = 4;
const ARGON2_MEMORY_COST = 65536; // 64 MB

// ── ANSI colors ───────────────────────────────────────────────────────────────
const C_RESET  = "\033[0m";
const C_BOLD   = "\033[1m";
const C_DIM    = "\033[2m";
const C_RED    = "\033[31m";
const C_GREEN  = "\033[32m";
const C_YELLOW = "\033[33m";
const C_CYAN   = "\033[36m";
const C_WHITE  = "\033[97m";

// ─────────────────────────────────────────────────────────────────────────────

function out(string $line = ''): void   { echo $line . PHP_EOL; }
function bold(string $s): string        { return C_BOLD  . $s . C_RESET; }
function dim(string $s): string         { return C_DIM   . $s . C_RESET; }
function red(string $s): string         { return C_RED   . $s . C_RESET; }
function green(string $s): string       { return C_GREEN . $s . C_RESET; }
function yellow(string $s): string      { return C_YELLOW . $s . C_RESET; }
function cyan(string $s): string        { return C_CYAN  . $s . C_RESET; }

function prompt(string $question): string
{
    echo $question;
    return trim(fgets(STDIN));
}

function promptHidden(string $question): string
{
    echo $question;
    // Disable terminal echo for sensitive input
    if (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
        system('stty -echo');
        $value = trim(fgets(STDIN));
        system('stty echo');
        echo PHP_EOL;
    } else {
        $value = trim(fgets(STDIN));
    }
    return $value;
}

function abort(string $message): never
{
    out(red('✖  ' . $message));
    exit(1);
}

function separator(): void
{
    out(dim(str_repeat('─', 72)));
}

// ─────────────────────────────────────────────────────────────────────────────

function parseDEK(string $input): string
{
    $input = trim($input);

    // Hex: 64 hex characters = 32 bytes
    if (preg_match('/^[0-9a-fA-F]{64}$/', $input)) {
        return hex2bin($input);
    }

    // Base64: 44 chars with possible padding = 32 bytes
    $decoded = base64_decode($input, strict: true);
    if ($decoded !== false && strlen($decoded) === AES_KEY_SIZE) {
        return $decoded;
    }

    abort(
        "Invalid DEK format.\n" .
        "   Expected: 64-character hex  OR  44-character Base64.\n" .
        "   Got:      " . strlen($input) . " characters."
    );
}

// ─────────────────────────────────────────────────────────────────────────────

function decryptField(
    string $ciphertext,
    string $nonce,
    string $tag,
    string $dek
): string {
    $plaintext = openssl_decrypt(
        base64_decode($ciphertext),
        AES_CIPHER,
        $dek,
        OPENSSL_RAW_DATA,
        base64_decode($nonce),
        base64_decode($tag)
    );

    if ($plaintext === false) {
        abort(
            "Decryption failed.\n" .
            "   The DEK you provided does not match the data."
        );
    }

    return $plaintext;
}

// ─────────────────────────────────────────────────────────────────────────────

function printEntry(int $index, array $entry): void
{
    out();
    out(C_WHITE . bold("  [$index] " . $entry['title']) . C_RESET);

    if (!empty($entry['url'])) {
        out(dim("       URL      ") . $entry['url']);
    }

    out(dim("       Username ") . $entry['username']);
    out(dim("       Password ") . bold($entry['password']));

    if (!empty($entry['hint'])) {
        out(dim("       Hint     ") . $entry['hint']);
    }

    out(dim("       Added    ") . $entry['created_at']);
}

// ─────────────────────────────────────────────────────────────────────────────

function unwrapDEKFromDB(PDO $pdo, string $password): string
{
    $config = $pdo->query(
        'SELECT password_hash, argon2id_salt, wrapped_dek, wrapped_dek_nonce, wrapped_dek_tag
         FROM config WHERE id = 1'
    )->fetch();

    if (!$config) {
        abort('Config row not found. Is this a valid vault database?');
    }

    if (!password_verify($password, $config['password_hash'])) {
        abort('Wrong vault password.');
    }

    $rawSalt = base64_decode($config['argon2id_salt']);
    if (strlen($rawSalt) < SODIUM_CRYPTO_PWHASH_SALTBYTES) {
        $rawSalt = substr(hash('sha256', $rawSalt, true), 0, SODIUM_CRYPTO_PWHASH_SALTBYTES);
    } else {
        $rawSalt = substr($rawSalt, 0, SODIUM_CRYPTO_PWHASH_SALTBYTES);
    }

    $kek = sodium_crypto_pwhash(
        AES_KEY_SIZE,
        $password,
        $rawSalt,
        ARGON2_TIME_COST,
        ARGON2_MEMORY_COST * 1024,
        SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
    );

    $dek = openssl_decrypt(
        base64_decode($config['wrapped_dek']),
        AES_CIPHER,
        $kek,
        OPENSSL_RAW_DATA,
        base64_decode($config['wrapped_dek_nonce']),
        base64_decode($config['wrapped_dek_tag'])
    );

    if ($dek === false) {
        abort('DEK unwrapping failed. Database may be corrupted.');
    }

    return $dek;
}

// ═══════════════════════════════════════════════════════════════════════════════
//  MAIN
// ═══════════════════════════════════════════════════════════════════════════════

// ── Header ────────────────────────────────────────────────────────────────────
out();
out(bold(C_WHITE . '  🛡️ VAULT — EMERGENCY RECOVERY' . C_RESET));
out(dim('  Standalone decryption tool. Nothing is written to disk.'));
separator();

// ── Check extensions ──────────────────────────────────────────────────────────
foreach (['openssl', 'sqlite3', 'pdo_sqlite', 'sodium'] as $ext) {
    if (!extension_loaded($ext)) {
        abort("Required PHP extension missing: {$ext}");
    }
}

// ── Locate database ───────────────────────────────────────────────────────────
$dbPath = $argv[1] ?? null;

if (!$dbPath) {
    $dbPath = prompt(yellow('  Database path') . ' [default: vault.db]: ');
    if ($dbPath === '') {
        $dbPath = 'vault.db';
    }
}

if (!file_exists($dbPath)) {
    abort("Database file not found: {$dbPath}");
}

out(green('  ✔') . "  Database: " . realpath($dbPath));
separator();

// ── Connect ───────────────────────────────────────────────────────────────────
try {
    $pdo = new PDO('sqlite:' . $dbPath, null, null, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    abort('Cannot open database: ' . $e->getMessage());
}

// Verify expected tables exist
$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('vault', $tables, true)) {
    abort("This does not look like a Vault database (missing 'vault' table).");
}

// ── Acquire DEK ───────────────────────────────────────────────────────────────
$entryCount = (int) $pdo->query('SELECT COUNT(*) FROM vault')->fetchColumn();

out();
out(bold('  Vault contains ') . cyan((string) $entryCount) . bold(' entries. How do you want to unlock?'));
out();
out('   ' . cyan('[1]') . '  Enter vault password  ' . dim('(auto-derive DEK from database)'));
out('   ' . cyan('[2]') . '  Paste raw DEK directly ' . dim('(Base64 or Hex backup)'));
out();

$modeInput = prompt(yellow('  Your choice') . ' [1/2]: ');

if ($modeInput !== '1' && $modeInput !== '2') {
    abort('Invalid choice. Enter 1 or 2.');
}

separator();

if ($modeInput === '1') {
    $vaultPassword = promptHidden(yellow('  Vault password') . ': ');
    if ($vaultPassword === '') {
        abort('No password entered.');
    }
    $dek = unwrapDEKFromDB($pdo, $vaultPassword);
    out(green('  ✔') . '  DEK unwrapped successfully (' . strlen($dek) . ' bytes).');
} else {
    out();
    out(dim('  Paste your Base64 (44 chars) or Hex (64 chars) key:'));
    out();
    $rawInput = promptHidden(yellow('  DEK') . ': ');
    if ($rawInput === '') {
        abort('No DEK provided.');
    }
    $dek = parseDEK($rawInput);
    out(green('  ✔') . '  DEK accepted (' . strlen($dek) . ' bytes).');
}

separator();

// ── Fetch entries ─────────────────────────────────────────────────────────────
$stmt = $pdo->prepare('SELECT * FROM vault ORDER BY title ASC');
$stmt->execute();
$rows = $stmt->fetchAll();

if (empty($rows)) {
    out();
    out(yellow('  The vault is empty — no entries to decrypt.'));
    out();
    exit(0);
}

out();
out(bold("  Decrypted Entries") . dim("  (" . count($rows) . " total)"));
separator();

$index = 1;
$errors = 0;

foreach ($rows as $row) {
    try {
        $row['username'] = decryptField(
            $row['username_encrypted'],
            $row['username_nonce'],
            $row['username_tag'],
            $dek
        );
        $row['password'] = decryptField(
            $row['password_encrypted'],
            $row['password_nonce'],
            $row['password_tag'],
            $dek
        );

        printEntry($index++, $row);

    } catch (Throwable) {
        out(red("  ✖  Failed to decrypt '{$row['title']}'. Wrong DEK?"));
        $errors++;
    }
}

// ── Footer ────────────────────────────────────────────────────────────────────
out();
separator();

if ($errors > 0) {
    out(yellow("  ⚠  {$errors} entry/entries could not be decrypted."));
} else {
    out(green('  ✔  All entries decrypted successfully.'));
}

out(dim('  This output exists only in your terminal. Close it when done.'));
out();
exit(0);
