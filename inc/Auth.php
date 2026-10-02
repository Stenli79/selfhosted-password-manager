<?php
declare(strict_types=1);

class Auth
{
    // ─── Session ──────────────────────────────────────────────────────────────

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
                'secure'   => true,
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
            session_start();
        }
    }

    public static function checkSessionTimeout(): void
    {
        if (!isset($_SESSION['authenticated'])) {
            return;
        }

        if (isset($_SESSION['last_activity'])) {
            $idle = time() - $_SESSION['last_activity'];
            if ($idle > SESSION_TIMEOUT) {
                self::logout();
                header('Location: index.php?timeout=1');
                exit;
            }
        }

        $_SESSION['last_activity'] = time();
    }

    public static function isAuthenticated(): bool
    {
        return !empty($_SESSION['authenticated']);
    }

    public static function requireAuth(): void
    {
        if (!self::isAuthenticated()) {
            header('Location: index.php');
            exit;
        }
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_destroy();
    }

    // ─── Master Login ─────────────────────────────────────────────────────────
    // Checks username first. If wrong, skips password_verify entirely.
    // Always shows generic error — never reveals which field failed.

    public static function attemptMasterLogin(string $username, string $password): bool
    {
        $target = 'master';

        if (self::isLocked($target)) {
            return false;
        }

        $db     = Database::getInstance();
        $config = $db->fetch(
            'SELECT master_username, master_password_hash FROM config WHERE id = 1'
        );

        // Username check — strict case-sensitive comparison
        if (!$config || $username !== $config['master_username']) {
            self::recordAttempt($target, 'failed');
            return false;
        }

        // Password check — only reached if username matched
        if (!Crypto::verifyPassword($password, $config['master_password_hash'])) {
            self::recordAttempt($target, 'failed');
            return false;
        }

        self::recordAttempt($target, 'success');
        $_SESSION['authenticated'] = true;
        $_SESSION['last_activity'] = time();

        return true;
    }

    // ─── Vault Unlock ─────────────────────────────────────────────────────────

    public static function attemptVaultUnlock(string $password): bool
    {
        $target = 'vault';

        if (self::isLocked($target)) {
            return false;
        }

        $db     = Database::getInstance();
        $config = $db->fetch(
            'SELECT password_hash, argon2id_salt, wrapped_dek, wrapped_dek_nonce, wrapped_dek_tag
             FROM config WHERE id = 1'
        );

        if (!$config) {
            return false;
        }

        if (!Crypto::verifyPassword($password, $config['password_hash'])) {
            self::recordAttempt($target, 'failed');
            return false;
        }

        try {
            $kek = Crypto::deriveKEK($password, $config['argon2id_salt']);
            $dek = Crypto::unwrapDEK(
                $config['wrapped_dek'],
                $config['wrapped_dek_nonce'],
                $config['wrapped_dek_tag'],
                $kek
            );
        } catch (RuntimeException $e) {
            self::recordAttempt($target, 'failed');
            return false;
        }

        self::recordAttempt($target, 'success');

        // Store DEK in session — raw binary, base64-encoded for session storage
        $_SESSION['dek'] = base64_encode($dek);

        return true;
    }

    public static function isVaultUnlocked(): bool
    {
        return !empty($_SESSION['dek']);
    }

    public static function lockVault(): void
    {
        unset($_SESSION['dek']);
    }

    // Returns raw binary DEK — throws if vault not unlocked
    public static function getDEK(): string
    {
        if (!self::isVaultUnlocked()) {
            throw new RuntimeException('Vault is not unlocked.');
        }
        return base64_decode($_SESSION['dek']);
    }

    // ─── Lockout ──────────────────────────────────────────────────────────────

    public static function isLocked(string $target): bool
    {
        $db  = Database::getInstance();
        $row = $db->fetch(
            'SELECT locked_until FROM lockout WHERE target = ?',
            [$target]
        );

        if (!$row || $row['locked_until'] === null) {
            return false;
        }

        if (strtotime($row['locked_until']) > time()) {
            return true;
        }

        // Lockout expired — clear it
        $db->run(
            'UPDATE lockout SET failed_attempts = 0, locked_until = NULL WHERE target = ?',
            [$target]
        );

        return false;
    }

    public static function getLockoutRemainingSeconds(string $target): int
    {
        $db  = Database::getInstance();
        $row = $db->fetch(
            'SELECT locked_until FROM lockout WHERE target = ?',
            [$target]
        );

        if (!$row || $row['locked_until'] === null) {
            return 0;
        }

        $remaining = strtotime($row['locked_until']) - time();
        return max(0, (int) $remaining);
    }

    private static function recordAttempt(string $target, string $event): void
    {
        $db  = Database::getInstance();
        $ip  = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $now = date('Y-m-d H:i:s');

        $row = $db->fetch('SELECT * FROM lockout WHERE target = ?', [$target]);

        if (!$row) {
            $db->run(
                'INSERT INTO lockout (target, failed_attempts, last_failed_at, locked_until) VALUES (?, 0, NULL, NULL)',
                [$target]
            );
            $row = ['failed_attempts' => 0, 'locked_until' => null];
        }

        $lockedUntil = null;

        if ($event === 'success') {
            $attempts = 0;
            $db->run(
                'UPDATE lockout SET failed_attempts = 0, locked_until = NULL WHERE target = ?',
                [$target]
            );
        } else {
            $attempts = (int) $row['failed_attempts'] + 1;

            if ($attempts >= MAX_FAILED_ATTEMPTS) {
                $lockedUntil = date('Y-m-d H:i:s', time() + LOCKOUT_DURATION);
                $event       = 'locked';
                $db->run(
                    'UPDATE lockout SET failed_attempts = ?, last_failed_at = ?, locked_until = ? WHERE target = ?',
                    [$attempts, $now, $lockedUntil, $target]
                );
            } else {
                $db->run(
                    'UPDATE lockout SET failed_attempts = ?, last_failed_at = ? WHERE target = ?',
                    [$attempts, $now, $target]
                );
            }
        }

        $db->run(
            'INSERT INTO access_log (target, event, ip_address, failed_attempts, locked_until, created_at)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $target,
                $event,
                $ip,
                $attempts ?? 0,
                $lockedUntil,
                $now,
            ]
        );
    }
}
