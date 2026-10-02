<?php
try {
    $entries = Vault::listEntries();
} catch (Exception $e) {
    $entries = [];
    $error   = $e->getMessage();
}
?>

<div class="vault-list">

    <div class="vault-list-header">
        <div class="vault-list-title">
            <h2>Vault</h2>
            <span class="entry-count"><?= count($entries) ?> <?= count($entries) === 1 ? 'entry' : 'entries' ?></span>
        </div>
        <div class="vault-list-actions">
            <form method="POST" action="index.php" style="display:contents">
                <input type="hidden" name="action" value="lock_vault">
                <button type="submit" class="btn btn-outline btn-sm">
                    <i class="fa-solid fa-lock"></i> Lock Vault
                </button>
            </form>
            <a href="index.php?action=vault&new=1" class="btn btn-primary btn-sm">
                <i class="fa-solid fa-plus"></i> Add Entry
            </a>
        </div>
    </div>

    <?php if (empty($entries)): ?>
        <div class="empty-state">
            <div class="empty-icon">
                <i class="fa-regular fa-folder-open"></i>
            </div>
            <p>No entries yet.</p>
            <a href="index.php?action=vault&new=1" class="btn btn-primary">
                <i class="fa-solid fa-plus"></i> Add your first entry
            </a>
        </div>
    <?php else: ?>

        <?php /* Hidden delete forms — one per entry, outside the grid */ ?>
        <?php foreach ($entries as $entry): ?>
            <form id="del-<?= $entry['id'] ?>" method="POST" style="display:none">
                <input type="hidden" name="action" value="delete_entry">
                <input type="hidden" name="id"     value="<?= $entry['id'] ?>">
            </form>
        <?php endforeach; ?>

        <div class="row g-3">
            <?php foreach ($entries as $entry): ?>
                <div class="col-12 col-md-6 col-lg-4 col-xl-3">
                <div class="entry-card">

                    <!-- ── Header: icon · title / url · actions ── -->
                    <div class="entry-card-header">
                        <div class="entry-site-icon">
                            <i class="fa-solid fa-globe"></i>
                        </div>
                        <div class="entry-meta-top">
                            <div class="entry-title-row">
                                <span class="entry-name" title="<?= htmlspecialchars($entry['title']) ?>">
                                    <?= htmlspecialchars($entry['title']) ?>
                                </span>
                                <div class="entry-actions">
                                    <a href="index.php?action=vault&edit=<?= $entry['id'] ?>"
                                       class="btn-icon-action btn-icon-edit" title="Edit">
                                        <i class="fa-solid fa-pen"></i>
                                    </a>
                                    <button type="button"
                                            class="btn-icon-action btn-icon-danger"
                                            title="Delete"
                                            data-bs-toggle="modal"
                                            data-bs-target="#deleteModal"
                                            data-entry-id="<?= $entry['id'] ?>"
                                            data-entry-name="<?= htmlspecialchars($entry['title']) ?>">
                                        <i class="fa-solid fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                            <?php if (!empty($entry['url'])): ?>
                                <a href="<?= htmlspecialchars($entry['url']) ?>"
                                   target="_blank" rel="noopener noreferrer"
                                   class="entry-url" title="<?= htmlspecialchars($entry['url']) ?>">
                                    <i class="fa-solid fa-link"></i>
                                    <span><?= htmlspecialchars($entry['url']) ?></span>
                                </a>
                            <?php else: ?>
                                <span class="entry-url-empty">No URL</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="entry-card-divider"></div>

                    <!-- ── Body: username · password · hint ── -->
                    <div class="entry-card-body">

                        <div class="entry-field">
                            <i class="fa-solid fa-user field-icon"></i>
                            <span class="field-value field-copyable"
                                  data-copy="<?= htmlspecialchars($entry['username']) ?>"
                                  data-label="Username"
                                  title="Click to copy">
                                <?= htmlspecialchars($entry['username']) ?>
                            </span>
                            <button type="button" class="btn-field-action copy-btn"
                                    data-copy="<?= htmlspecialchars($entry['username']) ?>"
                                    data-label="Username"
                                    title="Copy username">
                                <i class="fa-regular fa-copy"></i>
                            </button>
                        </div>

                        <div class="entry-field">
                            <i class="fa-solid fa-key field-icon"></i>
                            <span class="field-value password-masked"
                                  data-password="<?= htmlspecialchars($entry['password']) ?>">
                                ••••••••••••
                            </span>
                            <button type="button" class="btn-field-action toggle-password" title="Show password">
                                <i class="fa-solid fa-eye"></i>
                            </button>
                            <button type="button" class="btn-field-action copy-btn"
                                    data-copy="<?= htmlspecialchars($entry['password']) ?>"
                                    data-label="Password"
                                    title="Copy password">
                                <i class="fa-regular fa-copy"></i>
                            </button>
                        </div>

                        <?php if (!empty($entry['hint'])): ?>
                            <div class="entry-field entry-hint-field">
                                <i class="fa-regular fa-lightbulb field-icon hint-icon"
                                   title="Hold to reveal hint"></i>
                                <span class="field-value hint-value"
                                      data-hint="<?= htmlspecialchars($entry['hint']) ?>">
                                    Hold to reveal
                                </span>
                            </div>
                        <?php endif; ?>

                    </div>

                    <!-- ── Footer: last updated ── -->
                    <div class="entry-card-footer">
                        <i class="fa-regular fa-clock"></i>
                        <span>Updated <?= date('d M Y', strtotime($entry['updated_at'])) ?></span>
                    </div>

                </div><!-- /.entry-card -->
                </div><!-- /.col -->
            <?php endforeach; ?>
        </div><!-- /.row -->

    <?php endif; ?>
</div>

<script>
// ── Toggle password visibility ────────────────────────────────────────────────
document.querySelectorAll('.toggle-password').forEach(btn => {
    btn.addEventListener('click', () => {
        const field    = btn.closest('.entry-field');
        const masked   = field.querySelector('.password-masked');
        const icon     = btn.querySelector('i');
        const isHidden = masked.textContent.includes('•');

        masked.textContent = isHidden ? masked.dataset.password : '••••••••••••';
        icon.classList.toggle('fa-eye',       !isHidden);
        icon.classList.toggle('fa-eye-slash',  isHidden);
        btn.title = isHidden ? 'Hide password' : 'Show password';
    });
});

// ── Copy to clipboard — fires showToast (defined in layout.php) ───────────────
function copyText(text, label) {
    navigator.clipboard.writeText(text).then(() => {
        if (typeof showToast === 'function') showToast(label + ' copied!');
    });
}

document.querySelectorAll('.copy-btn').forEach(btn => {
    btn.addEventListener('click', () => copyText(btn.dataset.copy, btn.dataset.label || 'Copied'));
});

document.querySelectorAll('.field-copyable').forEach(el => {
    el.addEventListener('click', () => copyText(el.dataset.copy, el.dataset.label || 'Copied'));
});

// ── Hint: reveal on hold, hide on release ────────────────────────────────────
document.querySelectorAll('.entry-hint-field').forEach(row => {
    const icon = row.querySelector('.hint-icon');
    const val  = row.querySelector('.hint-value');

    function reveal() {
        val.textContent = val.dataset.hint;
        val.classList.add('revealed');
        icon.style.color = 'var(--warning)';
    }
    function hide() {
        val.textContent = 'Hold to reveal';
        val.classList.remove('revealed');
        icon.style.color = '';
    }

    icon.addEventListener('mousedown',  reveal);
    icon.addEventListener('mouseup',    hide);
    icon.addEventListener('mouseleave', hide);
    icon.addEventListener('touchstart', reveal, { passive: true });
    icon.addEventListener('touchend',   hide);
});
</script>
