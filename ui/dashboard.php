<?php
$db = Database::getInstance();

// ── Stat card data ────────────────────────────────────────────────────────────
$entryCount = (int) ($db->fetch('SELECT COUNT(*) as cnt FROM vault')['cnt'] ?? 0);

$totalLogins = (int) ($db->fetch(
    "SELECT COUNT(*) as cnt FROM access_log WHERE target = 'master' AND event = 'success'"
)['cnt'] ?? 0);

$lastLoginRow = $db->fetch(
    "SELECT created_at FROM access_log WHERE target = 'master' AND event = 'success'
     ORDER BY created_at DESC LIMIT 1"
);
$lastLogin = $lastLoginRow ? date('Y-m-d H:i', strtotime($lastLoginRow['created_at'])) : '—';

$vaultUnlocked = Auth::isVaultUnlocked();
$vaultLocked   = Auth::isLocked('vault');
$remaining     = $vaultLocked ? Auth::getLockoutRemainingSeconds('vault') : 0;

// ── Recent activity ───────────────────────────────────────────────────────────
$recentActivity = $db->fetchAll(
    "SELECT * FROM access_log ORDER BY created_at DESC LIMIT 5"
);
?>

<div class="dashboard">

    <!-- ── Stat cards ──────────────────────────────────────────────────────── -->
    <div class="stat-cards row g-3 mb-4">

        <div class="col-12 col-sm-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-card-icon stat-icon-entries">
                    <i class="fa-solid fa-key"></i>
                </div>
                <div class="stat-card-body">
                    <div class="stat-card-value"><?= $entryCount ?></div>
                    <div class="stat-card-label">Vault Entries</div>
                </div>
            </div>
        </div>

        <div class="col-12 col-sm-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-card-icon <?= $vaultUnlocked ? 'stat-icon-unlocked' : 'stat-icon-locked' ?>">
                    <i class="fa-solid <?= $vaultUnlocked ? 'fa-lock-open' : 'fa-lock' ?>"></i>
                </div>
                <div class="stat-card-body">
                    <div class="stat-card-value <?= $vaultUnlocked ? 'value-unlocked' : 'value-locked' ?>">
                        <?= $vaultUnlocked ? 'Unlocked' : 'Locked' ?>
                    </div>
                    <div class="stat-card-label">Vault Status</div>
                </div>
            </div>
        </div>

        <div class="col-12 col-sm-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-card-icon stat-icon-logins">
                    <i class="fa-solid fa-rotate"></i>
                </div>
                <div class="stat-card-body">
                    <div class="stat-card-value"><?= $totalLogins ?></div>
                    <div class="stat-card-label">Total Logins</div>
                </div>
            </div>
        </div>

        <div class="col-12 col-sm-6 col-lg-3">
            <div class="stat-card">
                <div class="stat-card-icon stat-icon-time">
                    <i class="fa-regular fa-clock"></i>
                </div>
                <div class="stat-card-body">
                    <div class="stat-card-value stat-card-date"><?= $lastLogin ?></div>
                    <div class="stat-card-label">Last Login</div>
                </div>
            </div>
        </div>

    </div>

    <!-- ── Main grid ───────────────────────────────────────────────────────── -->
    <div class="dashboard-grid row g-3">

        <!-- Vault Access panel -->
        <div class="col-12 col-lg-5"><div class="dek-key-block">
            <div class="dash-panel-title">
                <span class="dash-panel-icon"><i class="fa-solid fa-database"></i></span> Vault Access
            </div>

            <?php if ($vaultLocked): ?>
                <div class="alert alert-error" style="margin-bottom:16px;">
                    Vault is locked due to too many failed attempts.
                    Try again in <strong><?= ceil($remaining / 60) ?> minute(s)</strong>.
                </div>
            <?php elseif ($vaultUnlocked): ?>
                <div class="vault-status-msg vault-status-ok">
                    <i class="fa-solid fa-circle-check"></i> Vault is unlocked. You can view and manage your passwords.
                </div>
                <a href="index.php?action=vault" class="btn btn-primary btn-full dash-btn">
                    <i class="fa-solid fa-key"></i> Browse Vault
                </a>
                <a href="index.php?action=vault&new=1" class="btn btn-outline btn-full dash-btn">
                    <i class="fa-solid fa-plus"></i> Add New Entry
                </a>
                <form method="POST" style="margin-top:8px;">
                    <input type="hidden" name="action" value="lock_vault">
                    <button type="submit" class="btn-lock-level btn-full-width">
                        <i class="fa-solid fa-lock"></i> Lock Vault
                    </button>
                </form>
            <?php else: ?>
                <div class="vault-status-msg vault-status-locked">
                    <i class="fa-solid fa-lock"></i> Vault is locked. Enter your vault password to unlock.
                </div>
                <?php if (!empty($error) && str_contains($error, 'credentials')): ?>
                    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
                <?php endif; ?>
                <form method="POST" autocomplete="off" style="margin-top:16px;">
                    <input type="hidden" name="action" value="unlock_vault">
                    <div class="form-group">
                        <label for="vault_password">Vault Password</label>
                        <input type="password" id="vault_password" name="password"
                               required autofocus placeholder="Enter vault password">
                    </div>
                    <button type="submit" class="btn btn-primary btn-full">
                        <i class="fa-solid fa-unlock"></i> Unlock Vault
                    </button>
                </form>
            <?php endif; ?>
        </div></div>

        <!-- Recent Activity panel -->
        <div class="col-12 col-lg-7"><div class="dash-panel h-100">
            <div class="dash-panel-title" style="justify-content:space-between;">
                <span>
                    <span class="dash-panel-icon"><i class="fa-solid fa-clock-rotate-left"></i></span>
                    Recent Activity
                </span>
                <a href="index.php?action=access_log" class="btn btn-outline btn-sm">View All</a>
            </div>

            <?php if (empty($recentActivity)): ?>
                <p style="color:var(--text-muted);font-size:14px;">No activity yet.</p>
            <?php else: ?>
                <div class="log-table-wrap">
                    <table class="log-table activity-table">
                        <thead>
                            <tr>
                                <th>Event</th>
                                <th>Target</th>
                                <th>Time</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentActivity as $row): ?>
                                <tr class="log-row log-row--<?= htmlspecialchars($row['event']) ?>">
                                    <td>
                                        <?php
                                        echo match($row['event']) {
                                            'success' => '<span class="badge badge-success">success</span>',
                                            'failed'  => '<span class="badge badge-danger">failed</span>',
                                            'locked'  => '<span class="badge badge-locked">locked</span>',
                                            default   => '<span class="badge badge-neutral">' . htmlspecialchars($row['event']) . '</span>',
                                        };
                                        ?>
                                    </td>
                                    <td>
                                        <?php
                                        echo match($row['target']) {
                                            'master' => '<span class="target-label target-master">Master</span>',
                                            'vault'  => '<span class="target-label target-vault">Vault</span>',
                                            default  => htmlspecialchars($row['target']),
                                        };
                                        ?>
                                    </td>
                                    <td class="log-time">
                                        <?= date('Y-m-d H:i', strtotime($row['created_at'])) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div></div>

    </div>
</div>
