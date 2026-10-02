<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

// ─── Guard: block setup if DB already exists ──────────────────────────────────
if (file_exists(DB_PATH)) {
    header('Location: index.php');
    exit;
}

Auth::startSession();

$error     = '';
$success   = false;
$dekBackup = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $masterUsername = trim($_POST['master_username']         ?? '');
    $masterPassword = $_POST['master_password']              ?? '';
    $masterConfirm  = $_POST['master_password_confirm']      ?? '';
    $vaultPassword  = $_POST['vault_password']               ?? '';
    $vaultConfirm   = $_POST['vault_password_confirm']       ?? '';

    // ── Validation ────────────────────────────────────────────────────────────
    if ($masterUsername === '') {
        $error = 'Master username is required.';
    } elseif (strlen($masterPassword) < 12) {
        $error = 'Master password must be at least 12 characters.';
    } elseif ($masterPassword !== $masterConfirm) {
        $error = 'Master password confirmation does not match.';
    } elseif (strlen($vaultPassword) < 8) {
        $error = 'Vault password must be at least 8 characters.';
    } elseif ($vaultPassword !== $vaultConfirm) {
        $error = 'Vault password confirmation does not match.';
    } else {
        try {
            // ── Create DB and schema ──────────────────────────────────────────
            $pdo = new PDO('sqlite:' . DB_PATH, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);

            $pdo->exec('PRAGMA journal_mode = WAL');

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS config (
                    id                   INTEGER PRIMARY KEY,
                    master_username      TEXT NOT NULL,
                    master_password_hash TEXT NOT NULL,
                    master_argon2id_salt TEXT NOT NULL,
                    password_hash        TEXT NOT NULL,
                    argon2id_salt        TEXT NOT NULL,
                    wrapped_dek          TEXT NOT NULL,
                    wrapped_dek_nonce    TEXT NOT NULL,
                    wrapped_dek_tag      TEXT NOT NULL,
                    created_at           DATETIME NOT NULL,
                    updated_at           DATETIME NOT NULL
                )
            ");

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS vault (
                    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
                    title               TEXT NOT NULL,
                    url                 TEXT,
                    hint                TEXT,
                    username_encrypted  TEXT NOT NULL,
                    username_nonce      TEXT NOT NULL,
                    username_tag        TEXT NOT NULL,
                    password_encrypted  TEXT NOT NULL,
                    password_nonce      TEXT NOT NULL,
                    password_tag        TEXT NOT NULL,
                    created_at          DATETIME NOT NULL,
                    updated_at          DATETIME NOT NULL
                )
            ");

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS access_log (
                    id              INTEGER PRIMARY KEY AUTOINCREMENT,
                    target          TEXT NOT NULL,
                    event           TEXT NOT NULL,
                    ip_address      TEXT,
                    failed_attempts INTEGER DEFAULT 0,
                    locked_until    DATETIME,
                    created_at      DATETIME NOT NULL
                )
            ");

            $pdo->exec("
                CREATE TABLE IF NOT EXISTS lockout (
                    id              INTEGER PRIMARY KEY AUTOINCREMENT,
                    target          TEXT NOT NULL UNIQUE,
                    failed_attempts INTEGER DEFAULT 0,
                    last_failed_at  DATETIME,
                    locked_until    DATETIME
                )
            ");

            // ── Crypto setup ──────────────────────────────────────────────────

            // Master — hash only, no KEK (master does not wrap any DEK)
            $masterHash = Crypto::hashPassword($masterPassword);
            $masterSalt = Crypto::generateSalt();

            // Vault — hash + KEK + DEK
            $vaultSalt = Crypto::generateSalt();
            $kek       = Crypto::deriveKEK($vaultPassword, $vaultSalt);
            $dek       = Crypto::generateDEK();
            $wrapped   = Crypto::wrapDEK($dek, $kek);
            $vaultHash = Crypto::hashPassword($vaultPassword);

            $now = date('Y-m-d H:i:s');

            $stmt = $pdo->prepare("
                INSERT INTO config (
                    id,
                    master_username, master_password_hash, master_argon2id_salt,
                    password_hash, argon2id_salt,
                    wrapped_dek, wrapped_dek_nonce, wrapped_dek_tag,
                    created_at, updated_at
                ) VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");

            $stmt->execute([
                $masterUsername, $masterHash, $masterSalt,
                $vaultHash, $vaultSalt,
                $wrapped['ciphertext'], $wrapped['nonce'], $wrapped['tag'],
                $now, $now,
            ]);

            // ── Seed lockout rows ─────────────────────────────────────────────
            foreach (['master', 'vault'] as $target) {
                $pdo->prepare(
                    'INSERT INTO lockout (target, failed_attempts) VALUES (?, 0)'
                )->execute([$target]);
            }

            // ── Capture DEK for one-time display ─────────────────────────────
            $dekBackup = [
                'base64' => base64_encode($dek),
                'hex'    => bin2hex($dek),
            ];

            $success = true;

        } catch (Exception $e) {
            if (file_exists(DB_PATH)) {
                unlink(DB_PATH);
            }
            $error = 'Setup failed: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup — Vault</title>
    <link rel="stylesheet" href="ui/assets/style.css">
</head>
<body class="setup-page">
<div class="setup-container">
    <div class="setup-header">
        <div class="vault-icon">🔐</div>
        <h1>Vault Setup</h1>
        <p>This runs once. Set all passwords carefully — they cannot be recovered.</p>
    </div>

    <?php if ($success): ?>

        <div class="alert alert-success">
            <strong>Setup complete.</strong> Your vault has been created.
        </div>

        <div class="dek-backup-panel">
            <div class="dek-backup-header">
                <span class="dek-backup-icon">⚠️</span>
                <div>
                    <h2>Back Up Your Encryption Key</h2>
                    <p>
                        This key is shown <strong>exactly once</strong>. It is not stored anywhere in plaintext.
                        Copy it now to a secure offline location (paper, encrypted drive).
                        If you lose it <em>and</em> your server fails, your vault cannot be recovered.
                    </p>
                </div>
            </div>

            <div class="dek-key-block">
                <div class="dek-key-label">Vault DEK — Data Encryption Key</div>
                <div class="dek-key-row">
                    <span class="dek-format-label">Base64</span>
                    <code class="dek-key-value" id="dekB64"><?= htmlspecialchars($dekBackup['base64']) ?></code>
                    <button type="button" class="btn btn-outline btn-sm" onclick="copyDek('dekB64', this)">Copy</button>
                </div>
                <div class="dek-key-row">
                    <span class="dek-format-label">Hex</span>
                    <code class="dek-key-value" id="dekHex"><?= htmlspecialchars($dekBackup['hex']) ?></code>
                    <button type="button" class="btn btn-outline btn-sm" onclick="copyDek('dekHex', this)">Copy</button>
                </div>
            </div>

            <div class="dek-confirm-row">
                <label class="dek-confirm-label">
                    <input type="checkbox" id="dekConfirm" onchange="toggleLoginBtn()">
                    I have copied the key to a secure offline location.
                </label>
            </div>

            <a href="index.php" class="btn btn-primary btn-full" id="loginBtn" disabled
               style="pointer-events:none;opacity:0.4;">
                Proceed to Login
            </a>
        </div>

        <script>
        function copyDek(id, btn) {
            navigator.clipboard.writeText(document.getElementById(id).textContent).then(() => {
                const orig = btn.textContent;
                btn.textContent = '✓ Copied';
                setTimeout(() => { btn.textContent = orig; }, 1800);
            });
        }
        function toggleLoginBtn() {
            const btn     = document.getElementById('loginBtn');
            const checked = document.getElementById('dekConfirm').checked;
            btn.disabled          = !checked;
            btn.style.pointerEvents = checked ? 'auto'  : 'none';
            btn.style.opacity       = checked ? '1'     : '0.4';
        }
        </script>

    <?php else: ?>

        <?php if ($error): ?>
            <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="POST" autocomplete="off">

            <div class="form-section">
                <h2>Master Credentials</h2>
                <p class="form-hint">Used to log in to the application UI only. Does not decrypt vault data.</p>
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" name="master_username" required autocomplete="off"
                           value="<?= htmlspecialchars($_POST['master_username'] ?? '') ?>">
                </div>
                <div class="form-group">
                    <label>Password <span class="req">min 12 chars</span></label>
                    <input type="password" name="master_password" required minlength="12">
                </div>
                <div class="form-group">
                    <label>Confirm Password</label>
                    <input type="password" name="master_password_confirm" required>
                </div>
            </div>

            <div class="form-section">
                <h2>Vault Password</h2>
                <p class="form-hint">Unlocks the encrypted vault. Derives the encryption key — keep it safe.</p>
                <div class="form-group">
                    <label>Password <span class="req">min 8 chars</span></label>
                    <input type="password" name="vault_password" required minlength="8">
                </div>
                <div class="form-group">
                    <label>Confirm Password</label>
                    <input type="password" name="vault_password_confirm" required>
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-full">Create Vault</button>
        </form>

    <?php endif; ?>
</div>
</body>
</html>
