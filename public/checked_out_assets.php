<?php
require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/auth.php';
require_once SRC_PATH . '/snipeit_client.php';
require_once SRC_PATH . '/db.php';
require_once SRC_PATH . '/footer.php';

function format_display_date($val): string
{
    if (is_array($val)) {
        $val = $val['datetime'] ?? ($val['date'] ?? '');
    }
    if (empty($val)) {
        return '';
    }
    try {
        $dt = new DateTime($val);
        return $dt->format('d/m/Y');
    } catch (Throwable $e) {
        return $val;
    }
}

$active  = basename($_SERVER['PHP_SELF']);
$isStaff = !empty($currentUser['is_admin']);

if (!$isStaff) {
    http_response_code(403);
    echo 'Access denied.';
    exit;
}

$tab = ($_GET['tab'] ?? 'all') === 'overdue' ? 'overdue' : 'all';
$error = '';
$assets = [];
$search = trim($_GET['q'] ?? '');

try {
    $assets = list_checked_out_assets($tab === 'overdue');
    if ($search !== '') {
        $q = mb_strtolower($search);
        $assets = array_values(array_filter($assets, function ($row) use ($q) {
            $fields = [
                $row['asset_tag'] ?? '',
                $row['name'] ?? '',
                $row['model']['name'] ?? '',
                $row['assigned_to'] ?? ($row['assigned_to_fullname'] ?? ''),
            ];
            foreach ($fields as $f) {
                if (is_array($f)) {
                    $f = implode(' ', $f);
                }
                if (mb_stripos((string)$f, $q) !== false) {
                    return true;
                }
            }
            return false;
        }));
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Checked Out Assets – ReserveIT</title>
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/style.css">
    <?= reserveit_theme_styles() ?>
</head>
<body class="p-4">
<div class="container">
    <div class="page-shell">
        <?= reserveit_logo_tag() ?>
        <div class="page-header">
            <h1>Checked Out Assets</h1>
            <div class="page-subtitle">
                Showing requestable assets currently checked out in Snipe-IT.
            </div>
        </div>

        <nav class="app-nav">
            <a href="index.php"
               class="app-nav-link <?= $active === 'index.php' ? 'active' : '' ?>">Dashboard</a>
            <a href="catalogue.php"
               class="app-nav-link <?= $active === 'catalogue.php' ? 'active' : '' ?>">Catalogue</a>
            <a href="my_bookings.php"
               class="app-nav-link <?= $active === 'my_bookings.php' ? 'active' : '' ?>">My bookings</a>
            <a href="staff_reservations.php"
               class="app-nav-link <?= $active === 'staff_reservations.php' ? 'active' : '' ?>">Booking History</a>
            <a href="staff_checkout.php"
               class="app-nav-link <?= $active === 'staff_checkout.php' ? 'active' : '' ?>">Checkout</a>
            <a href="quick_checkout.php"
               class="app-nav-link <?= $active === 'quick_checkout.php' ? 'active' : '' ?>">Quick Checkout</a>
            <a href="quick_checkin.php"
               class="app-nav-link <?= $active === 'quick_checkin.php' ? 'active' : '' ?>">Quick Checkin</a>
            <a href="checked_out_assets.php"
               class="app-nav-link <?= $active === 'checked_out_assets.php' ? 'active' : '' ?>">Checked Out Assets</a>
        </nav>

        <ul class="nav nav-tabs mb-3">
            <li class="nav-item">
                <a class="nav-link <?= $tab === 'all' ? 'active' : '' ?>"
                   href="?tab=all<?= $search !== '' ? '&q=' . urlencode($search) : '' ?>">All checked out</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?= $tab === 'overdue' ? 'active' : '' ?>"
                   href="?tab=overdue<?= $search !== '' ? '&q=' . urlencode($search) : '' ?>">Overdue</a>
            </li>
        </ul>

        <form method="get" class="row g-2 mb-3">
            <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
            <div class="col-md-6">
                <input type="text"
                       name="q"
                       value="<?= htmlspecialchars($search) ?>"
                       class="form-control"
                       placeholder="Filter by asset tag, name, model, or user">
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary">Filter</button>
            </div>
        </form>

        <?php if ($error): ?>
            <div class="alert alert-danger">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if (empty($assets) && !$error): ?>
            <div class="alert alert-secondary">
                No <?= $tab === 'overdue' ? 'overdue ' : '' ?>checked-out requestable assets.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-striped align-middle">
                    <thead>
                        <tr>
                            <th>Asset Tag</th>
                            <th>Name</th>
                            <th>Model</th>
                            <th>User</th>
                            <th>Assigned Since</th>
                            <th>Expected Check-in</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($assets as $a): ?>
                            <?php
                                $atag = $a['asset_tag'] ?? '';
                                $name = $a['name'] ?? '';
                                $model = $a['model']['name'] ?? '';
                                $user  = $a['assigned_to'] ?? ($a['assigned_to_fullname'] ?? '');
                                if (is_array($user)) {
                                    $user = $user['name'] ?? ($user['username'] ?? '');
                                }
                                $checkedOut = $a['_last_checkout_norm'] ?? ($a['last_checkout'] ?? '');
                                $expected   = $a['_expected_checkin_norm'] ?? ($a['expected_checkin'] ?? '');
                            ?>
                            <tr>
                                <td><?= h($atag) ?></td>
                                <td><?= h($name) ?></td>
                                <td><?= h($model) ?></td>
                                <td><?= h($user) ?></td>
                                <td><?= h(format_display_date($checkedOut)) ?></td>
                                <td class="<?= ($tab === 'overdue' ? 'text-danger fw-semibold' : '') ?>">
                                    <?= h(format_display_date($expected)) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php reserveit_footer(); ?>
</body>
</html>
