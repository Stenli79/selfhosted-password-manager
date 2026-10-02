<?php
$isEdit = ($editId !== null);
$entry  = [];

if ($isEdit) {
    try {
        $entry = Vault::getEntry($editId);
    } catch (Exception $e) {
        header('Location: index.php?action=vault');
        exit;
    }
}

$title    = $entry['title']    ?? '';
$url      = $entry['url']      ?? '';
$username = $entry['username'] ?? '';
$password = $entry['password'] ?? '';
$hint     = $entry['hint']     ?? '';
?>

<div class="vault-entry-form">

    <div class="form-page-header">
        <a href="index.php?action=vault" class="back-link">
            <i class="fa-solid fa-arrow-left"></i> Back to Vault
        </a>
        <h2><?= $isEdit ? 'Edit Entry' : 'New Entry' ?></h2>
    </div>

    <?php if (!empty($error)): ?>
        <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="POST" autocomplete="off">
        <input type="hidden" name="action" value="<?= $isEdit ? 'update_entry' : 'add_entry' ?>">
        <?php if ($isEdit): ?>
            <input type="hidden" name="id" value="<?= $editId ?>">
        <?php endif; ?>

        <div class="form-group">
            <label for="title">Title <span class="req">*</span></label>
            <input type="text" id="title" name="title"
                   value="<?= htmlspecialchars($title) ?>"
                   required placeholder="e.g. Gmail, GitHub, Netflix" autofocus>
        </div>

        <div class="form-group">
            <label for="url">URL <span class="optional">optional</span></label>
            <input type="url" id="url" name="url"
                   value="<?= htmlspecialchars($url) ?>"
                   placeholder="https://example.com">
        </div>

        <div class="form-group">
            <label for="username">Username / Email <span class="req">*</span></label>
            <input type="text" id="username" name="username"
                   value="<?= htmlspecialchars($username) ?>"
                   required placeholder="username or email">
        </div>

        <div class="form-group">
            <label for="password">Password <span class="req">*</span></label>
            <div class="input-with-action">
                <input type="password" id="password" name="password"
                       value="<?= htmlspecialchars($password) ?>"
                       required placeholder="password">
                <button type="button" class="btn btn-outline btn-sm" id="togglePassword" title="Show / hide password">
                    <i class="fa-solid fa-eye" id="togglePwdIcon"></i>
                </button>
                <button type="button" class="btn btn-outline btn-sm" id="generatePassword" title="Generate strong password">
                    <i class="fa-solid fa-rotate"></i> Generate
                </button>
            </div>
            <div class="password-strength" id="passwordStrength"></div>
        </div>

        <div class="form-group">
            <label for="hint">Hint <span class="optional">optional — stored in plaintext</span></label>
            <input type="text" id="hint" name="hint"
                   value="<?= htmlspecialchars($hint) ?>"
                   placeholder="A hint only you understand">
        </div>

        <div class="form-actions">
            <a href="index.php?action=vault" class="btn btn-outline">
                <i class="fa-solid fa-xmark"></i> Cancel
            </a>
            <button type="submit" class="btn btn-primary">
                <i class="fa-solid <?= $isEdit ? 'fa-floppy-disk' : 'fa-plus' ?>"></i>
                <?= $isEdit ? 'Save Changes' : 'Add Entry' ?>
            </button>
        </div>
    </form>
</div>

<script>
const pwdInput  = document.getElementById('password');
const toggleBtn = document.getElementById('togglePassword');
const toggleIcon = document.getElementById('togglePwdIcon');

toggleBtn.addEventListener('click', () => {
    const isHidden = pwdInput.type === 'password';
    pwdInput.type = isHidden ? 'text' : 'password';
    toggleIcon.className = isHidden ? 'fa-solid fa-eye-slash' : 'fa-solid fa-eye';
});

document.getElementById('generatePassword').addEventListener('click', () => {
    const charset = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()-_=+[]{}';
    const array   = new Uint32Array(20);
    crypto.getRandomValues(array);
    const pwd = Array.from(array, n => charset[n % charset.length]).join('');
    pwdInput.value = pwd;
    pwdInput.type  = 'text';
    toggleIcon.className = 'fa-solid fa-eye-slash';
    updateStrength(pwd);
});

function updateStrength(pwd) {
    const el = document.getElementById('passwordStrength');
    if (!pwd) { el.innerHTML = ''; return; }
    let score = 0;
    if (pwd.length >= 12) score++;
    if (pwd.length >= 20) score++;
    if (/[a-z]/.test(pwd) && /[A-Z]/.test(pwd)) score++;
    if (/[0-9]/.test(pwd)) score++;
    if (/[^a-zA-Z0-9]/.test(pwd)) score++;
    const levels = ['', 'Weak', 'Fair', 'Good', 'Strong', 'Very Strong'];
    const colors = ['', '#e74c3c', '#e67e22', '#f1c40f', '#2ecc71', '#27ae60'];
    const width  = (score / 5 * 100) + '%';
    el.innerHTML = `
        <div class="strength-bar">
            <div class="strength-fill" style="width:${width};background:${colors[score]}"></div>
        </div>
        <span class="strength-label" style="color:${colors[score]}">${levels[score] || 'Weak'}</span>
    `;
}

pwdInput.addEventListener('input', () => updateStrength(pwdInput.value));
updateStrength(pwdInput.value);
</script>
