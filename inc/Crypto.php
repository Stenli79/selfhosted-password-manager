<?php
declare(strict_types=1);

class Crypto
{
    // ─── Password Hashing (for verification only, no key derivation) ──────────

    public static function hashPassword(string $password): string
    {
        $hash = password_hash($password, PASSWORD_ARGON2ID, [
            'memory_cost' => ARGON2_MEMORY_COST,
            'time_cost'   => ARGON2_TIME_COST,
            'threads'     => ARGON2_THREADS,
        ]);

        if ($hash === false) {
            throw new RuntimeException('Password hashing failed.');
        }

        return $hash;
    }

    public static function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    // ─── Key Derivation (Master Password → KEK) ───────────────────────────────

    // Generates a cryptographically random salt for Argon2id KDF.
    public static function generateSalt(): string
    {
        return base64_encode(random_bytes(32));
    }

    // Derives a 256-bit Key Encryption Key (KEK) from a password + salt.
    // Uses hash_pbkdf2 with Argon2id-equivalent iterations via a raw Argon2id call.
    public static function deriveKEK(string $password, string $salt): string
    {
        // We use hash_pbkdf2 is NOT used here — we use Argon2id properly via
        // a raw call with sodium if available, otherwise openssl-based approach.
        // PHP's native password_hash Argon2id is for verification (variable output).
        // For deterministic key derivation we use sodium_crypto_pwhash.

        if (!extension_loaded('sodium')) {
            throw new RuntimeException('sodium extension is required for key derivation.');
        }

        $rawSalt = base64_decode($salt);
        if (strlen($rawSalt) < SODIUM_CRYPTO_PWHASH_SALTBYTES) {
            // Pad or hash down to the required 16 bytes
            $rawSalt = substr(hash('sha256', $rawSalt, true), 0, SODIUM_CRYPTO_PWHASH_SALTBYTES);
        } else {
            $rawSalt = substr($rawSalt, 0, SODIUM_CRYPTO_PWHASH_SALTBYTES);
        }

        $key = sodium_crypto_pwhash(
            AES_KEY_SIZE,             // 32 bytes output
            $password,
            $rawSalt,
            ARGON2_TIME_COST,         // opslimit
            ARGON2_MEMORY_COST * 1024, // memlimit in bytes
            SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13
        );

        return $key; // raw binary, 32 bytes
    }

    // ─── DEK Generation ───────────────────────────────────────────────────────

    public static function generateDEK(): string
    {
        return random_bytes(AES_KEY_SIZE); // raw binary, 32 bytes
    }

    // ─── DEK Wrap / Unwrap (AES-256-GCM) ─────────────────────────────────────

    // Encrypts the DEK using the KEK. Returns [ciphertext, nonce, tag] all base64.
    public static function wrapDEK(string $dek, string $kek): array
    {
        $nonce = random_bytes(AES_IV_SIZE);
        $tag   = '';

        $ciphertext = openssl_encrypt(
            $dek,
            AES_CIPHER,
            $kek,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',       // no AAD
            AES_TAG_SIZE
        );

        if ($ciphertext === false) {
            throw new RuntimeException('DEK wrapping failed.');
        }

        return [
            'ciphertext' => base64_encode($ciphertext),
            'nonce'      => base64_encode($nonce),
            'tag'        => base64_encode($tag),
        ];
    }

    // Decrypts the wrapped DEK using the KEK. Returns raw binary DEK.
    public static function unwrapDEK(string $wrappedDek, string $nonce, string $tag, string $kek): string
    {
        $dek = openssl_decrypt(
            base64_decode($wrappedDek),
            AES_CIPHER,
            $kek,
            OPENSSL_RAW_DATA,
            base64_decode($nonce),
            base64_decode($tag)
        );

        if ($dek === false) {
            throw new RuntimeException('DEK unwrapping failed. Wrong password or corrupted data.');
        }

        return $dek;
    }

    // ─── Data Encrypt / Decrypt (AES-256-GCM using DEK) ──────────────────────

    // Encrypts a plaintext string. Returns [ciphertext, nonce, tag] all base64.
    public static function encrypt(string $plaintext, string $dek): array
    {
        $nonce = random_bytes(AES_IV_SIZE);
        $tag   = '';

        $ciphertext = openssl_encrypt(
            $plaintext,
            AES_CIPHER,
            $dek,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            '',
            AES_TAG_SIZE
        );

        if ($ciphertext === false) {
            throw new RuntimeException('Encryption failed.');
        }

        return [
            'ciphertext' => base64_encode($ciphertext),
            'nonce'      => base64_encode($nonce),
            'tag'        => base64_encode($tag),
        ];
    }

    // Decrypts a ciphertext. Returns plaintext string.
    public static function decrypt(
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
            throw new RuntimeException('Decryption failed. Data may be corrupted or tampered with.');
        }

        return $plaintext;
    }
}
