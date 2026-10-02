<?php
declare(strict_types=1);

class Vault
{
    // ─── List all entries (decrypted) ─────────────────────────────────────────

    public static function listEntries(): array
    {
        $dek = Auth::getDEK();
        $db  = Database::getInstance();

        $rows = $db->fetchAll(
            'SELECT * FROM vault ORDER BY title ASC'
        );

        return array_map(fn($row) => self::decryptRow($row, $dek), $rows);
    }

    // ─── Get a single entry (decrypted) ───────────────────────────────────────

    public static function getEntry(int $id): array
    {
        $db  = Database::getInstance();
        $row = $db->fetch('SELECT * FROM vault WHERE id = ?', [$id]);

        if (!$row) {
            throw new RuntimeException("Entry #{$id} not found.");
        }

        $dek = Auth::getDEK();
        return self::decryptRow($row, $dek);
    }

    // ─── Add a new entry ──────────────────────────────────────────────────────

    public static function addEntry(
        string $title,
        string $username,
        string $password,
        string $url  = '',
        string $hint = ''
    ): int {
        $dek = Auth::getDEK();
        $db  = Database::getInstance();
        $now = date('Y-m-d H:i:s');

        $encUsername = Crypto::encrypt($username, $dek);
        $encPassword = Crypto::encrypt($password, $dek);

        $db->run(
            'INSERT INTO vault
                (title, url, hint,
                 username_encrypted, username_nonce, username_tag,
                 password_encrypted, password_nonce, password_tag,
                 created_at, updated_at)
             VALUES
                (?, ?, ?,
                 ?, ?, ?,
                 ?, ?, ?,
                 ?, ?)',
            [
                $title, $url, $hint,
                $encUsername['ciphertext'], $encUsername['nonce'], $encUsername['tag'],
                $encPassword['ciphertext'], $encPassword['nonce'], $encPassword['tag'],
                $now, $now,
            ]
        );

        return (int) $db->lastInsertId();
    }

    // ─── Update an existing entry ─────────────────────────────────────────────

    public static function updateEntry(
        int    $id,
        string $title,
        string $username,
        string $password,
        string $url  = '',
        string $hint = ''
    ): void {
        $db  = Database::getInstance();
        $row = $db->fetch('SELECT id FROM vault WHERE id = ?', [$id]);

        if (!$row) {
            throw new RuntimeException("Entry #{$id} not found.");
        }

        $dek = Auth::getDEK();
        $now = date('Y-m-d H:i:s');

        $encUsername = Crypto::encrypt($username, $dek);
        $encPassword = Crypto::encrypt($password, $dek);

        $db->run(
            'UPDATE vault SET
                title              = ?,
                url                = ?,
                hint               = ?,
                username_encrypted = ?,
                username_nonce     = ?,
                username_tag       = ?,
                password_encrypted = ?,
                password_nonce     = ?,
                password_tag       = ?,
                updated_at         = ?
             WHERE id = ?',
            [
                $title, $url, $hint,
                $encUsername['ciphertext'], $encUsername['nonce'], $encUsername['tag'],
                $encPassword['ciphertext'], $encPassword['nonce'], $encPassword['tag'],
                $now,
                $id,
            ]
        );
    }

    // ─── Delete an entry ──────────────────────────────────────────────────────

    public static function deleteEntry(int $id): void
    {
        $db = Database::getInstance();
        $db->run('DELETE FROM vault WHERE id = ?', [$id]);
    }

    // ─── Internal: decrypt a raw DB row ───────────────────────────────────────

    private static function decryptRow(array $row, string $dek): array
    {
        $row['username'] = Crypto::decrypt(
            $row['username_encrypted'],
            $row['username_nonce'],
            $row['username_tag'],
            $dek
        );
        $row['password'] = Crypto::decrypt(
            $row['password_encrypted'],
            $row['password_nonce'],
            $row['password_tag'],
            $dek
        );
        return $row;
    }
}
