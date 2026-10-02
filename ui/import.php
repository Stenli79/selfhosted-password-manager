<?php
const IMPORT_MAX_ROWS      = 100;
const IMPORT_SESSION_KEY   = 'import_preview';
const IMPORT_REQUIRED_COLS = ['Title', 'URL', 'Username', 'Password', 'Hint'];

$step        = 1;
$fatalErrors = [];
$warnings    = [];
$validRows   = [];
$preview     = null;

// ─── Handle Step 2 — Confirm import ──────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['import_confirm'] ?? '') === '1') {
    $preview = $_SESSION[IMPORT_SESSION_KEY] ?? null;

    if (!$preview || empty($preview['valid_rows'])) {
        $fatalErrors[] = 'Session expired or no valid rows found. Please upload the file again.';
    } else {
        $imported      = 0;
        $skippedErrors = [];

        foreach ($preview['valid_rows'] as $row) {
            try {
                Vault::addEntry(
                    $row['title'],
                    $row['username'],
                    $row['password'],
                    $row['url'],
                    $row['hint']
                );
                $imported++;
            } catch (Exception $e) {
                $skippedErrors[] = $row['title'] . ' — ' . $e->getMessage();
            }
        }

        unset($_SESSION[IMPORT_SESSION_KEY]);

        include __DIR__ . '/import_result.php';
        return;
    }
}

// ─── Handle Step 1 — File upload and validation ───────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $fatalErrors[] = 'File upload failed. Error code: ' . $file['error'];
    } else {
        $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $mimeType  = mime_content_type($file['tmp_name']);

        if ($extension !== 'csv' || !in_array($mimeType, ['text/plain', 'text/csv', 'application/csv', 'application/octet-stream'], true)) {
            $fatalErrors[] = 'Invalid file type. Only .csv files are accepted.';
        } else {
            $content = file_get_contents($file['tmp_name']);

            if (!mb_check_encoding($content, 'UTF-8')) {
                $fatalErrors[] = 'File encoding is not UTF-8. Please re-save your CSV as UTF-8 and upload again.';
            } else {
                $lines = array_values(array_filter(
                    explode("\n", str_replace(["\r\n", "\r"], "\n", $content)),
                    fn($l) => trim($l) !== ''
                ));

                if (empty($lines)) {
                    $fatalErrors[] = 'The file is empty.';
                } else {
                    $headerLine = array_map('trim', str_getcsv($lines[0]));

                    if ($headerLine !== IMPORT_REQUIRED_COLS) {
                        $fatalErrors[] = 'Header row is missing or incorrect. '
                            . 'Expected: <code>' . implode(', ', IMPORT_REQUIRED_COLS) . '</code> '
                            . '— got: <code>' . htmlspecialchars(implode(', ', $headerLine)) . '</code>. '
                            . 'Column names are case-sensitive.';
                    } else {
                        $dataLines = array_slice($lines, 1);

                        if (count($dataLines) > IMPORT_MAX_ROWS) {
                            $fatalErrors[] = 'File contains ' . count($dataLines) . ' data rows. '
                                . 'Maximum allowed is ' . IMPORT_MAX_ROWS . ' rows per import.';
                        } else {
                            $db = Database::getInstance();

                            foreach ($dataLines as $lineNum => $line) {
                                $rowNum = $lineNum + 2;
                                $cols   = array_map('trim', str_getcsv($line));
                                while (count($cols) < 5) $cols[] = '';

                                [$title, $url, $username, $password, $hint] = $cols;

                                if ($title === '') {
                                    $warnings[] = ['row' => $rowNum, 'type' => 'error',
                                        'message' => "Row {$rowNum}: Title is empty — row skipped."];
                                    continue;
                                }

                                if ($username === '') {
                                    $warnings[] = ['row' => $rowNum, 'type' => 'error',
                                        'message' => "Row {$rowNum} ({$title}): Username is empty — row skipped."];
                                    continue;
                                }

                                if ($password === '') {
                                    $warnings[] = ['row' => $rowNum, 'type' => 'warning',
                                        'message' => "Row {$rowNum} ({$title}): Password is empty — row skipped."];
                                    continue;
                                }

                                // Duplicate detection by title
                                $dup = $db->fetch('SELECT id FROM vault WHERE title = ?', [$title]);
                                if ($dup) {
                                    $warnings[] = ['row' => $rowNum, 'type' => 'duplicate',
                                        'message' => "Row {$rowNum} ({$title}): An entry with this title already exists — row skipped."];
                                    continue;
                                }

                                $validRows[] = [
                                    'row'      => $rowNum,
                                    'title'    => $title,
                                    'url'      => $url,
                                    'username' => $username,
                                    'password' => $password,
                                    'hint'     => $hint,
                                ];
                            }
                        }
                    }
                }
            }
        }
    }

    if (empty($fatalErrors)) {
        $_SESSION[IMPORT_SESSION_KEY] = [
            'valid_rows' => $validRows,
            'warnings'   => $warnings,
        ];
        $step = 2;
    }
}

if ($step === 2 && isset($_SESSION[IMPORT_SESSION_KEY])) {
    $preview   = $_SESSION[IMPORT_SESSION_KEY];
    $validRows = $preview['valid_rows'];
    $warnings  = $preview['warnings'];
}
?>

<?php if ($step === 1): ?>

<div class="import-page">
    <div class="page-header">
        <div>
            <h2>Import Passwords</h2>
            <p>Upload a CSV file to import entries into your vault.</p>
        </div>
        <a href="index.php" class="btn btn-outline btn-sm">
            <i class="fa-solid fa-arrow-left"></i> Dashboard
        </a>
    </div>

    <?php if (!empty($fatalErrors)): ?>
        <div class="import-fatal-block">
            <div class="import-block-title">
                <i class="fa-solid fa-circle-xmark"></i> Import cannot proceed
            </div>
            <?php foreach ($fatalErrors as $err): ?>
                <div class="import-fatal-msg"><?= $err ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="import-format-card">
        <div class="import-format-title">
            <i class="fa-solid fa-table-list"></i> Required File Format
        </div>
        <div class="import-format-table-wrap">
            <table class="import-format-table">
                <thead>
                    <tr><th>Column</th><th>Required</th><th>Notes</th></tr>
                </thead>
                <tbody>
                    <tr><td><code>Title</code></td><td><span class="req-badge req-yes">Mandatory</span></td><td>Empty rows are skipped</td></tr>
                    <tr><td><code>URL</code></td><td><span class="req-badge req-no">Optional</span></td><td>Leave empty if not needed</td></tr>
                    <tr><td><code>Username</code></td><td><span class="req-badge req-yes">Mandatory</span></td><td>Email or username</td></tr>
                    <tr><td><code>Password</code></td><td><span class="req-badge req-yes">Mandatory</span></td><td>Empty rows are skipped with a warning</td></tr>
                    <tr><td><code>Hint</code></td><td><span class="req-badge req-no">Optional</span></td><td>Stored in plaintext</td></tr>
                </tbody>
            </table>
        </div>

        <div class="import-format-example">
            <div class="import-format-example-label">Example CSV</div>
            <pre class="import-csv-preview">Title,URL,Username,Password,Hint
Gmail,https://gmail.com,john@email.com,MyPass123,work mail
GitHub,https://github.com,johndoe,gh_secret,
Forum,,john_d,forum_pass,old account</pre>
        </div>

        <div class="import-format-rules">
            <div class="import-rule import-rule--info">
                <i class="fa-solid fa-circle-info"></i>
                The <strong>first row must be the header</strong> with exact column names as shown. Column names are case-sensitive.
            </div>
            <div class="import-rule import-rule--info">
                <i class="fa-solid fa-circle-info"></i>
                Maximum <strong><?= IMPORT_MAX_ROWS ?> data rows</strong> per import (excluding header).
            </div>
            <div class="import-rule import-rule--warn">
                <i class="fa-solid fa-triangle-exclamation"></i>
                File must be encoded in <strong>UTF-8</strong>. Non-UTF-8 files will be rejected.
            </div>
            <div class="import-rule import-rule--warn">
                <i class="fa-solid fa-triangle-exclamation"></i>
                Entries with a <strong>Title that already exists</strong> in the vault will be skipped.
            </div>
        </div>
    </div>

    <div class="import-upload-card">
        <form method="POST" enctype="multipart/form-data" autocomplete="off">
            <div class="form-group">
                <label for="csv_file">Select CSV File</label>
                <input type="file" id="csv_file" name="csv_file" accept=".csv" required>
                <p class="form-hint">Only .csv files are accepted.</p>
            </div>
            <button type="submit" class="btn btn-primary">
                <i class="fa-solid fa-magnifying-glass"></i> Validate &amp; Preview
            </button>
        </form>
    </div>
</div>

<?php else: ?>

<div class="import-page">
    <div class="page-header">
        <div>
            <h2>Import Preview</h2>
            <p>Review the validation results before confirming.</p>
        </div>
        <a href="index.php?action=import" class="btn btn-outline btn-sm">
            <i class="fa-solid fa-arrow-left"></i> Upload again
        </a>
    </div>

    <?php $hasValid = count($validRows) > 0; ?>

    <?php if ($hasValid): ?>
        <div class="import-summary-block import-summary--success">
            <div class="import-block-title">
                <i class="fa-solid fa-circle-check"></i>
                <?= count($validRows) ?> <?= count($validRows) === 1 ? 'entry' : 'entries' ?> ready to import
            </div>
            <?php if (!Auth::isVaultUnlocked()): ?>
                <div class="import-rule import-rule--warn" style="margin-top:12px;">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    The vault is not unlocked. Please
                    <a href="index.php">unlock the vault</a> before confirming the import.
                </div>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="import-fatal-block">
            <div class="import-block-title">
                <i class="fa-solid fa-circle-xmark"></i> No valid entries found
            </div>
            <div class="import-fatal-msg">
                All rows were skipped. Review the warnings below, fix your CSV, and upload again.
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($warnings)): ?>
        <div class="import-warnings-block">
            <div class="import-block-title">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <?= count($warnings) ?> <?= count($warnings) === 1 ? 'row was' : 'rows were' ?> skipped
            </div>
            <ul class="import-warning-list">
                <?php foreach ($warnings as $w): ?>
                    <li class="import-warning-item import-warning--<?= $w['type'] ?>">
                        <?= htmlspecialchars($w['message']) ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($hasValid): ?>
        <div class="import-preview-table-wrap">
            <div class="import-block-title" style="padding:0 0 12px;">Entries to be imported</div>
            <div class="log-table-wrap">
                <table class="log-table">
                    <thead>
                        <tr><th>Row</th><th>Title</th><th>Username</th><th>URL</th><th>Hint</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($validRows as $row): ?>
                            <tr>
                                <td class="log-dim"><?= $row['row'] ?></td>
                                <td><strong><?= htmlspecialchars($row['title']) ?></strong></td>
                                <td><?= htmlspecialchars($row['username']) ?></td>
                                <td class="log-ip"><?= htmlspecialchars($row['url'] ?: '—') ?></td>
                                <td class="log-dim"><?= htmlspecialchars($row['hint'] ?: '—') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="import-confirm-row">
            <a href="index.php?action=import" class="btn btn-outline">
                <i class="fa-solid fa-xmark"></i> Cancel — upload again
            </a>
            <?php if (Auth::isVaultUnlocked()): ?>
                <form method="POST">
                    <input type="hidden" name="import_confirm" value="1">
                    <button type="submit" class="btn btn-primary">
                        <i class="fa-solid fa-check"></i>
                        Confirm &amp; Import <?= count($validRows) ?> <?= count($validRows) === 1 ? 'entry' : 'entries' ?>
                    </button>
                </form>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="import-confirm-row">
            <a href="index.php?action=import" class="btn btn-primary">
                <i class="fa-solid fa-arrow-left"></i> Fix and upload again
            </a>
        </div>
    <?php endif; ?>

</div>

<?php endif; ?>
