<?php
// This file is included by import.php after a successful confirm POST.
// Variables available: $imported (int), $skippedLocked (array)
?>
<div class="import-page">
    <div class="page-header">
        <div>
            <h2>Import Complete</h2>
        </div>
        <a href="index.php" class="btn btn-outline btn-sm">← Dashboard</a>
    </div>

    <div class="import-summary-block import-summary--success">
        <div class="import-block-title">
            ✅ <?= $imported ?> <?= $imported === 1 ? 'entry was' : 'entries were' ?> imported successfully.
        </div>
    </div>

    <?php if (!empty($skippedLocked)): ?>
        <div class="import-warnings-block">
            <div class="import-block-title">
                ⚠️ <?= count($skippedLocked) ?> <?= count($skippedLocked) === 1 ? 'entry' : 'entries' ?> could not be imported
            </div>
            <ul class="import-warning-list">
                <?php foreach ($skippedLocked as $msg): ?>
                    <li class="import-warning-item import-warning--warning">
                        <?= htmlspecialchars($msg) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="import-confirm-row">
        <a href="index.php?action=import" class="btn btn-outline">Import another file</a>
        <a href="index.php" class="btn btn-primary">Go to Dashboard</a>
    </div>
</div>
