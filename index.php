<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

// ─── Redirect to setup if DB doesn't exist ────────────────────────────────────
if (!file_exists(DB_PATH)) {
    header('Location: setup.php');
    exit;
}

Auth::startSession();
Auth::checkSessionTimeout();

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$error  = '';

// ─── Master login ─────────────────────────────────────────────────────────────
if ($action === 'login') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $target   = 'master';

    if (Auth::isLocked($target)) {
        $remaining = Auth::getLockoutRemainingSeconds($target);
        $error = 'Too many failed attempts. Access is locked for ' . ceil($remaining / 60) . ' minute(s).';
    } elseif (Auth::attemptMasterLogin($username, $password)) {
        header('Location: index.php');
        exit;
    } else {
        if (Auth::isLocked($target)) {
            $error = 'Too many failed attempts. Access is locked for 30 minutes.';
        } else {
            $error = 'Invalid credentials. Please try again.';
        }
    }
}

// ─── Vault unlock ─────────────────────────────────────────────────────────────
if ($action === 'unlock_vault' && Auth::isAuthenticated()) {
    $password = $_POST['password'] ?? '';
    $target   = 'vault';

    if (Auth::isLocked($target)) {
        $remaining = Auth::getLockoutRemainingSeconds($target);
        $error = 'Too many failed attempts. Vault is locked for ' . ceil($remaining / 60) . ' minute(s).';
    } elseif (Auth::attemptVaultUnlock($password)) {
        header('Location: index.php');
        exit;
    } else {
        if (Auth::isLocked($target)) {
            $error = 'Too many failed attempts. Vault is locked for 30 minutes.';
        } else {
            $error = 'Invalid credentials. Please try again.';
        }
    }
}

// ─── Vault lock ───────────────────────────────────────────────────────────────
if ($action === 'lock_vault' && Auth::isAuthenticated()) {
    Auth::lockVault();
    header('Location: index.php');
    exit;
}

// ─── Logout ───────────────────────────────────────────────────────────────────
if ($action === 'logout' || isset($_GET['logout'])) {
    Auth::logout();
    header('Location: index.php');
    exit;
}

// ─── Vault CRUD (require auth + unlocked vault) ───────────────────────────────
if (Auth::isAuthenticated() && Auth::isVaultUnlocked()) {

    if ($action === 'add_entry') {
        try {
            Vault::addEntry(
                trim($_POST['title']    ?? ''),
                trim($_POST['username'] ?? ''),
                trim($_POST['password'] ?? ''),
                trim($_POST['url']      ?? ''),
                trim($_POST['hint']     ?? '')
            );
            header('Location: index.php?action=vault&saved=1');
            exit;
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }

    if ($action === 'update_entry') {
        $id = (int) ($_POST['id'] ?? 0);
        try {
            Vault::updateEntry(
                $id,
                trim($_POST['title']    ?? ''),
                trim($_POST['username'] ?? ''),
                trim($_POST['password'] ?? ''),
                trim($_POST['url']      ?? ''),
                trim($_POST['hint']     ?? '')
            );
            header('Location: index.php?action=vault&saved=1');
            exit;
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }

    if ($action === 'delete_entry') {
        $id = (int) ($_POST['id'] ?? 0);
        Vault::deleteEntry($id);
        header('Location: index.php?action=vault');
        exit;
    }
}

// ─── Vault unlock (AJAX — overlay) ───────────────────────────────────────────
if ($action === 'vault_unlock_ajax' && Auth::isAuthenticated()) {
    header('Content-Type: application/json');
    $password = $_POST['password'] ?? '';
    $target   = 'vault';

    if (Auth::isLocked($target)) {
        $remaining = Auth::getLockoutRemainingSeconds($target);
        echo json_encode(['success' => false, 'locked' => true, 'remaining' => $remaining]);
        exit;
    }

    if (Auth::attemptVaultUnlock($password)) {
        echo json_encode(['success' => true]);
        exit;
    }

    $locked    = Auth::isLocked($target);
    $remaining = $locked ? Auth::getLockoutRemainingSeconds($target) : 0;
    echo json_encode(['success' => false, 'locked' => $locked, 'remaining' => $remaining]);
    exit;
}

// ─── Render ───────────────────────────────────────────────────────────────────
if (!Auth::isAuthenticated()) {
    require UI_PATH . '/login.php';
} else {
    require UI_PATH . '/layout.php';
}
