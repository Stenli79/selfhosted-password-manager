<?php
Auth::requireAuth();

$action  = $_GET['action'] ?? $_POST['action'] ?? '';
$editId  = isset($_GET['edit']) ? (int) $_GET['edit'] : null;
$saved   = isset($_GET['saved']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Manager</title>
    <script>(function(){var t=localStorage.getItem('pm-theme')||'dark';document.documentElement.setAttribute('data-theme',t);})();</script>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="stylesheet" href="ui/assets/style.css">
</head>
<body>

<nav class="navbar navbar-expand-md" id="mainNav">
    <a class="nav-brand" href="index.php">
        <i class="fa-solid fa-shield-halved"></i>
        <span>Password Manager</span>
    </a>

    <button class="hamburger-btn d-md-none" type="button"
            data-bs-toggle="collapse" data-bs-target="#navContent"
            aria-controls="navContent" aria-expanded="false" aria-label="Toggle navigation">
        <i class="fa-solid fa-bars"></i>
    </button>

    <div class="collapse navbar-collapse" id="navContent">
        <div class="nav-links">
            <a href="index.php" class="nav-link-item <?= $action === '' ? 'active' : '' ?>">
                <i class="fa-solid fa-gauge-high"></i><span>Dashboard</span>
            </a>
            <?php if (Auth::isVaultUnlocked()): ?>
                <a href="index.php?action=vault" class="nav-link-item <?= $action === 'vault' ? 'active' : '' ?>">
                    <i class="fa-solid fa-key"></i><span>Vault</span>
                </a>
                <a href="index.php?action=import" class="nav-link-item <?= $action === 'import' ? 'active' : '' ?>">
                    <i class="fa-solid fa-file-import"></i><span>Import</span>
                </a>
            <?php endif; ?>
            <a href="index.php?action=access_log" class="nav-link-item <?= $action === 'access_log' ? 'active' : '' ?>">
                <i class="fa-solid fa-clock-rotate-left"></i><span>History</span>
            </a>
        </div>

        <div class="nav-actions">
            <span class="session-timer" id="sessionTimer" title="Session timeout countdown"></span>
            <button class="btn-theme-toggle" id="themeToggle" title="Toggle light / dark theme">
                <i class="fa-solid fa-sun" id="themeIcon"></i>
            </button>
            <a href="index.php?logout=1" class="btn btn-outline btn-sm">
                <i class="fa-solid fa-right-from-bracket"></i> Logout
            </a>
        </div>
    </div>
</nav>

<main class="main-content container-xxl">

    <?php if (!empty($error)): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <?php if ($saved): ?>
        <div class="alert alert-success">Entry saved successfully.</div>
    <?php endif; ?>

    <?php if ($action === 'access_log'): ?>
        <?php require __DIR__ . '/access_log.php'; ?>

    <?php elseif ($action === 'import' && Auth::isVaultUnlocked()): ?>
        <?php require __DIR__ . '/import.php'; ?>

    <?php elseif ($action === 'vault' && Auth::isVaultUnlocked() && $editId !== null): ?>
        <?php require __DIR__ . '/vault_entry.php'; ?>

    <?php elseif ($action === 'vault' && Auth::isVaultUnlocked() && isset($_GET['new'])): ?>
        <?php require __DIR__ . '/vault_entry.php'; ?>

    <?php elseif ($action === 'vault' && Auth::isVaultUnlocked()): ?>
        <?php require __DIR__ . '/vault_list.php'; ?>

    <?php else: ?>
        <?php require __DIR__ . '/dashboard.php'; ?>

    <?php endif; ?>

</main>

<!-- ── Copy toast ── -->
<div class="toast-container position-fixed bottom-0 end-0 p-3" style="z-index:9999;">
    <div id="copyToast" class="toast align-items-center border-0"
         role="alert" aria-atomic="true" data-bs-delay="1600">
        <div class="toast-body" id="copyToastMsg">
            <i class="fa-solid fa-check me-1"></i> Copied!
        </div>
    </div>
</div>

<!-- ── Delete confirmation modal ── -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-delete">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title">
                    <i class="fa-solid fa-triangle-exclamation text-danger me-2"></i>Delete Entry
                </h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-1">Delete <strong id="deleteEntryName"></strong>?</p>
                <p class="mb-0 modal-warn">This cannot be undone.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline btn-sm" data-bs-dismiss="modal">Cancel</button>
                <button type="button" id="confirmDeleteBtn" class="btn btn-danger btn-sm">
                    <i class="fa-solid fa-trash"></i> Delete
                </button>
            </div>
        </div>
    </div>
</div>

<?php if (Auth::isVaultUnlocked()): ?>
<!-- ── Vault auto-lock overlay ── -->
<div id="vaultLockOverlay" class="vault-lock-overlay" style="display:none;" aria-modal="true" role="dialog" aria-label="Vault locked">
    <div class="vault-lock-box">
        <div class="vault-lock-icon">
            <i class="fa-solid fa-lock"></i>
        </div>
        <h5 class="vault-lock-title">Vault Locked</h5>
        <p class="vault-lock-desc">Locked due to inactivity. Enter your vault password to continue.</p>
        <div id="vaultLockError" class="vault-lock-error" style="display:none;"></div>
        <form id="vaultUnlockForm" autocomplete="off">
            <div class="vault-lock-input-row">
                <input type="password" id="vaultUnlockPassword" placeholder="Vault password" aria-label="Vault password">
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-unlock"></i> Unlock
                </button>
            </div>
        </form>
        <a href="index.php?logout=1" class="vault-lock-logout">Log out instead</a>
    </div>
</div>
<?php endif; ?>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>

<script>
(function () {
    const timeout = <?= SESSION_TIMEOUT ?>;
    let remaining = timeout;
    const el = document.getElementById('sessionTimer');

    function update() {
        if (!el) return;
        const m = Math.floor(remaining / 60);
        const s = remaining % 60;
        el.textContent = `Session: ${m}:${s.toString().padStart(2, '0')}`;
        if (remaining <= 60) el.classList.add('timer-warning');
        if (remaining <= 30) el.classList.add('timer-danger');
        if (remaining <= 0) window.location.href = 'index.php?timeout=1';
        remaining--;
    }

    update();
    setInterval(update, 1000);

    ['click', 'keydown', 'mousemove'].forEach(evt => {
        document.addEventListener(evt, () => { remaining = timeout; }, { passive: true });
    });
})();

// ── Global toast helper ───────────────────────────────────────────────────────
window.showToast = function (msg) {
    const el = document.getElementById('copyToast');
    if (!el) return;
    document.getElementById('copyToastMsg').innerHTML =
        '<i class="fa-solid fa-check me-1"></i> ' + msg;
    bootstrap.Toast.getOrCreateInstance(el).show();
};

// ── Theme switcher ────────────────────────────────────────────────────────────
(function () {
    function applyTheme(theme) {
        document.documentElement.setAttribute('data-theme', theme);
        localStorage.setItem('pm-theme', theme);
        const icon = document.getElementById('themeIcon');
        if (icon) icon.className = theme === 'light' ? 'fa-solid fa-moon' : 'fa-solid fa-sun';
    }

    const saved = localStorage.getItem('pm-theme') || 'dark';
    applyTheme(saved);

    const btn = document.getElementById('themeToggle');
    if (btn) {
        btn.addEventListener('click', () => {
            const curr = document.documentElement.getAttribute('data-theme') || 'dark';
            applyTheme(curr === 'dark' ? 'light' : 'dark');
        });
    }
})();

// ── Delete modal wiring ───────────────────────────────────────────────────────
(function () {
    const modal = document.getElementById('deleteModal');
    if (!modal) return;
    let pendingFormId = null;

    modal.addEventListener('show.bs.modal', e => {
        const btn = e.relatedTarget;
        if (!btn) return;
        document.getElementById('deleteEntryName').textContent = btn.dataset.entryName || '';
        pendingFormId = 'del-' + (btn.dataset.entryId || '');
    });

    document.getElementById('confirmDeleteBtn').addEventListener('click', () => {
        if (pendingFormId) document.getElementById(pendingFormId)?.submit();
    });
})();
</script>

<?php if (Auth::isVaultUnlocked()): ?>
<script>
// ── Vault idle auto-lock ───────────────────────────────────────────────────────
(function () {
    const idleLimit = <?= VAULT_IDLE_TIMEOUT ?>;
    let idle        = 0;
    let overlayShown = false;

    const overlay = document.getElementById('vaultLockOverlay');
    const form    = document.getElementById('vaultUnlockForm');
    const errorEl = document.getElementById('vaultLockError');

    function showOverlay() {
        overlayShown = true;
        overlay.style.display = 'flex';
        setTimeout(() => document.getElementById('vaultUnlockPassword')?.focus(), 100);
    }

    ['click', 'keydown', 'mousemove', 'scroll', 'touchstart'].forEach(evt => {
        document.addEventListener(evt, () => { if (!overlayShown) idle = 0; }, { passive: true });
    });

    setInterval(() => {
        if (overlayShown) return;
        idle++;
        if (idle >= idleLimit) showOverlay();
    }, 1000);

    if (!form) return;

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        const pw  = document.getElementById('vaultUnlockPassword').value;
        const btn = form.querySelector('button[type="submit"]');

        errorEl.style.display = 'none';
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';

        fetch('index.php', {
            method:  'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body:    'action=vault_unlock_ajax&password=' + encodeURIComponent(pw)
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) { window.location.reload(); return; }
            errorEl.style.display = 'block';
            if (data.locked) {
                const mins = Math.ceil(data.remaining / 60);
                errorEl.textContent = `Too many failed attempts. Vault locked for ${mins} minute(s).`;
            } else {
                errorEl.textContent = 'Invalid password. Please try again.';
            }
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-unlock"></i> Unlock';
            const pwInput = document.getElementById('vaultUnlockPassword');
            pwInput.value = '';
            pwInput.focus();
        })
        .catch(() => {
            errorEl.style.display = 'block';
            errorEl.textContent = 'Connection error. Please try again.';
            btn.disabled = false;
            btn.innerHTML = '<i class="fa-solid fa-unlock"></i> Unlock';
        });
    });
})();
</script>
<?php endif; ?>

</body>
</html>
