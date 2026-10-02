<?php
$db = Database::getInstance();

$filterTarget = $_GET['target'] ?? 'all';
$filterEvent  = $_GET['event']  ?? 'all';
$page         = max(1, (int) ($_GET['page'] ?? 1));
$perPage      = 25;
$offset       = ($page - 1) * $perPage;

$targets = ['all', 'master', 'vault'];
$events  = ['all', 'success', 'failed', 'locked'];

$filterTarget = in_array($filterTarget, $targets, true) ? $filterTarget : 'all';
$filterEvent  = in_array($filterEvent,  $events,  true) ? $filterEvent  : 'all';

$where  = [];
$params = [];

if ($filterTarget !== 'all') {
    $where[]  = 'target = ?';
    $params[] = $filterTarget;
}
if ($filterEvent !== 'all') {
    $where[]  = 'event = ?';
    $params[] = $filterEvent;
}

$whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total      = (int) ($db->fetch("SELECT COUNT(*) as cnt FROM access_log {$whereClause}", $params)['cnt'] ?? 0);
$totalPages = (int) ceil($total / $perPage);

$rows = $db->fetchAll(
    "SELECT * FROM access_log {$whereClause} ORDER BY created_at DESC LIMIT {$perPage} OFFSET {$offset}",
    $params
);

$lockouts = $db->fetchAll('SELECT * FROM lockout ORDER BY target ASC');

function eventBadge(string $event): string {
    return match($event) {
        'success' => '<span class="badge badge-success">success</span>',
        'failed'  => '<span class="badge badge-danger">failed</span>',
        'locked'  => '<span class="badge badge-locked">locked</span>',
        default   => '<span class="badge badge-neutral">' . htmlspecialchars($event) . '</span>',
    };
}

function targetLabel(string $target): string {
    return match($target) {
        'master' => '<span class="target-label target-master">Master</span>',
        'vault'  => '<span class="target-label target-vault">Vault</span>',
        default  => htmlspecialchars($target),
    };
}

function buildUrl(array $overrides): string {
    $params = array_merge([
        'action' => 'access_log',
        'target' => $_GET['target'] ?? 'all',
        'event'  => $_GET['event']  ?? 'all',
        'page'   => $_GET['page']   ?? 1,
    ], $overrides);
    return 'index.php?' . http_build_query($params);
}
?>

<div class="access-log-page">

    <div class="page-header">
        <div>
            <h2>History</h2>
            <p>All login attempts. Newest first.</p>
        </div>
        <a href="index.php" class="btn btn-outline btn-sm">
            <i class="fa-solid fa-arrow-left"></i> Dashboard
        </a>
    </div>

    <!-- ── Live lockout status ──────────────────────────────────────────────── -->
    <div class="lockout-status-bar">
        <?php foreach ($lockouts as $lk): ?>
            <?php
            $isLocked  = $lk['locked_until'] && strtotime($lk['locked_until']) > time();
            $remaining = $isLocked ? strtotime($lk['locked_until']) - time() : 0;
            $label     = match($lk['target']) {
                'master' => 'Master',
                'vault'  => 'Vault',
                default  => $lk['target'],
            };
            ?>
            <div class="lockout-pill <?= $isLocked ? 'lockout-pill--locked' : 'lockout-pill--ok' ?>">
                <span class="lockout-pill-name"><?= $label ?></span>
                <?php if ($isLocked): ?>
                    <span class="lockout-pill-status">
                        <i class="fa-solid fa-ban"></i> Locked <?= ceil($remaining / 60) ?>m
                    </span>
                <?php else: ?>
                    <span class="lockout-pill-status">
                        <i class="fa-solid fa-circle-check"></i> OK
                        <?php if ((int)$lk['failed_attempts'] > 0): ?>
                            <span class="lockout-pill-attempts"><?= $lk['failed_attempts'] ?> failed</span>
                        <?php endif; ?>
                    </span>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- ── Filters ─────────────────────────────────────────────────────────── -->
    <div class="log-filters">
        <div class="filter-group">
            <span class="filter-label">Target</span>
            <?php foreach ($targets as $t): ?>
                <a href="<?= buildUrl(['target' => $t, 'page' => 1]) ?>"
                   class="filter-btn <?= $filterTarget === $t ? 'filter-btn--active' : '' ?>">
                    <?= $t === 'all' ? 'All' : ucfirst($t) ?>
                </a>
            <?php endforeach; ?>
        </div>
        <div class="filter-group">
            <span class="filter-label">Event</span>
            <?php foreach ($events as $e): ?>
                <a href="<?= buildUrl(['event' => $e, 'page' => 1]) ?>"
                   class="filter-btn <?= $filterEvent === $e ? 'filter-btn--active' : '' ?>">
                    <?= ucfirst($e === 'all' ? 'All' : $e) ?>
                </a>
            <?php endforeach; ?>
        </div>
        <div class="filter-total"><?= $total ?> <?= $total === 1 ? 'entry' : 'entries' ?></div>
    </div>

    <!-- ── Table ───────────────────────────────────────────────────────────── -->
    <?php if (empty($rows)): ?>
        <div class="empty-state">
            <div class="empty-icon"><i class="fa-regular fa-rectangle-list"></i></div>
            <p>No log entries match the current filters.</p>
        </div>
    <?php else: ?>
        <div class="log-table-wrap">
            <table class="log-table">
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Target</th>
                        <th>Event</th>
                        <th>IP Address</th>
                        <th>Failed count</th>
                        <th>Locked until</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $row): ?>
                        <tr class="log-row log-row--<?= htmlspecialchars($row['event']) ?>">
                            <td class="log-time">
                                <?= date('d M Y', strtotime($row['created_at'])) ?>
                                <span class="log-time-clock"><?= date('H:i:s', strtotime($row['created_at'])) ?></span>
                            </td>
                            <td><?= targetLabel($row['target']) ?></td>
                            <td><?= eventBadge($row['event']) ?></td>
                            <td class="log-ip"><?= htmlspecialchars($row['ip_address'] ?? '—') ?></td>
                            <td class="log-attempts">
                                <?php if ((int)$row['failed_attempts'] > 0): ?>
                                    <span class="attempts-count"><?= $row['failed_attempts'] ?></span>
                                <?php else: ?>
                                    <span class="log-dim">—</span>
                                <?php endif; ?>
                            </td>
                            <td class="log-locked">
                                <?php if (!empty($row['locked_until'])): ?>
                                    <span class="locked-until-val"><?= date('H:i d M', strtotime($row['locked_until'])) ?></span>
                                <?php else: ?>
                                    <span class="log-dim">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="<?= buildUrl(['page' => $page - 1]) ?>" class="page-btn">
                        <i class="fa-solid fa-chevron-left"></i>
                    </a>
                <?php endif; ?>
                <?php
                $start = max(1, $page - 2);
                $end   = min($totalPages, $page + 2);
                ?>
                <?php if ($start > 1): ?>
                    <a href="<?= buildUrl(['page' => 1]) ?>" class="page-btn">1</a>
                    <?php if ($start > 2): ?><span class="page-ellipsis">…</span><?php endif; ?>
                <?php endif; ?>
                <?php for ($i = $start; $i <= $end; $i++): ?>
                    <a href="<?= buildUrl(['page' => $i]) ?>"
                       class="page-btn <?= $i === $page ? 'page-btn--active' : '' ?>"><?= $i ?></a>
                <?php endfor; ?>
                <?php if ($end < $totalPages): ?>
                    <?php if ($end < $totalPages - 1): ?><span class="page-ellipsis">…</span><?php endif; ?>
                    <a href="<?= buildUrl(['page' => $totalPages]) ?>" class="page-btn"><?= $totalPages ?></a>
                <?php endif; ?>
                <?php if ($page < $totalPages): ?>
                    <a href="<?= buildUrl(['page' => $page + 1]) ?>" class="page-btn">
                        <i class="fa-solid fa-chevron-right"></i>
                    </a>
                <?php endif; ?>
                <span class="page-info">Page <?= $page ?> of <?= $totalPages ?></span>
            </div>
        <?php endif; ?>
    <?php endif; ?>

</div>
