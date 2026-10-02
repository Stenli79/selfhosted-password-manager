<?php
declare(strict_types=1);

// ─── Paths ────────────────────────────────────────────────────────────────────
define('BASE_PATH',    __DIR__);
define('DB_PATH',      BASE_PATH . '/db/vault.db');
define('INC_PATH',     BASE_PATH . '/inc');
define('UI_PATH',      BASE_PATH . '/ui');

// ─── Session ──────────────────────────────────────────────────────────────────
define('SESSION_TIMEOUT',    300*10); // 5 minutes inactivity (DEBUG STATE: 50 min)
define('VAULT_IDLE_TIMEOUT', 300/5);    // 5 minutes — vault auto-lock on idle (independent of session) (DEBUG STATE: 1 min)

// ─── Lockout ──────────────────────────────────────────────────────────────────
define('MAX_FAILED_ATTEMPTS', 3);
define('LOCKOUT_DURATION',    1800); // 30 minutes in seconds

// ─── Argon2id Parameters (password hashing / verification only) ───────────────
define('ARGON2_MEMORY_COST', 65536); // 64 MB
define('ARGON2_TIME_COST',   4);
define('ARGON2_THREADS',     2);

// ─── PBKDF2-SHA256 Parameters (key derivation: password → KEK) ───────────────
define('PBKDF2_ALGO',       'sha256');
define('PBKDF2_ITERATIONS', 600000);

// ─── AES-256-GCM ──────────────────────────────────────────────────────────────
define('AES_CIPHER',   'aes-256-gcm');
define('AES_KEY_SIZE', 32);
define('AES_IV_SIZE',  12);
define('AES_TAG_SIZE', 16);

// ─── Autoload /inc classes ────────────────────────────────────────────────────
spl_autoload_register(function (string $class): void {
    $file = INC_PATH . '/' . $class . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});
