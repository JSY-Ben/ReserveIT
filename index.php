<?php
require 'auth.php';
require 'db.php';
require_once __DIR__ . '/footer.php';

$active  = basename($_SERVER['PHP_SELF']);
$isStaff = !empty($currentUser['is_admin']);
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Equipment Booking – Dashboard</title>
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="style.css">
</head>
<body class="p-4">
<div class="container">
    <div class="page-shell">
        <?= reserveit_logo_tag() ?>
        <div class="page-header">
            <h1>Equipment Booking</h1>
            <div class="page-subtitle">
                Browse bookable equipment, manage your basket, and review your bookings.
            </div>
        </div>

        <nav class="app-nav">
            <a href="index.php"
               class="app-nav-link <?= $active === 'index.php' ? 'active' : '' ?>">Dashboard</a>
            <a href="catalogue.php"
           class="app-nav-link <?= $active === 'catalogue.php' ? 'active' : '' ?>">Catalogue</a>
        <a href="my_bookings.php"
           class="app-nav-link <?= $active === 'my_bookings.php' ? 'active' : '' ?>">My bookings</a>
        <?php if ($isStaff): ?>
            <a href="staff_reservations.php"
               class="app-nav-link <?= $active === 'staff_reservations.php' ? 'active' : '' ?>">Booking History</a>
            <a href="staff_checkout.php"
               class="app-nav-link <?= $active === 'staff_checkout.php' ? 'active' : '' ?>">Checkout</a>
            <a href="quick_checkout.php"
               class="app-nav-link <?= $active === 'quick_checkout.php' ? 'active' : '' ?>">Quick Checkout</a>
            <a href="quick_checkin.php"
               class="app-nav-link <?= $active === 'quick_checkin.php' ? 'active' : '' ?>">Quick Checkin</a>
        <?php endif; ?>
    </nav>

        <div class="top-bar mb-3">
            <div class="top-bar-user">
                Logged in as:
                <strong><?= h(trim(($currentUser['first_name'] ?? '') . ' ' . ($currentUser['last_name'] ?? ''))) ?></strong>
                (<?= h($currentUser['email'] ?? '') ?>)
            </div>
            <div class="top-bar-actions">
                <a href="basket.php" class="btn btn-outline-primary">
                    View basket
                </a>
                <a href="logout.php" class="btn btn-link btn-sm">Log out</a>
            </div>
        </div>

        <div class="row g-3">
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-body d-flex flex-column">
                        <h5 class="card-title">Browse equipment</h5>
                        <p class="card-text">
                            View the catalogue of equipment models available for students to book.
                            Add items to your basket and request them for specific dates.
                        </p>
                        <a href="catalogue.php" class="btn btn-primary mt-auto">
                            Go to catalogue
                        </a>
                    </div>
                </div>
            </div>

            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-body d-flex flex-column">
                        <h5 class="card-title">My bookings</h5>
                        <p class="card-text">
                            See all of your upcoming and past bookings, including which models you
                            requested, and cancel future bookings where allowed.
                        </p>
                        <a href="my_bookings.php" class="btn btn-outline-primary mt-auto">
                            View my bookings
                        </a>
                    </div>
                </div>
            </div>

            <?php if ($isStaff): ?>
                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-body d-flex flex-column">
                            <h5 class="card-title">Booking History</h5>
                            <p class="card-text">
                                See all past and current bookings, and coordinate which assets to hand out.
                            </p>
                            <a href="staff_reservations.php" class="btn btn-outline-primary mt-auto">
                                View booking history
                            </a>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-body d-flex flex-column">
                            <h5 class="card-title">Reservation Checkout</h5>
                            <p class="card-text">
                                Select a booking for today and check out specific assets against its models.
                            </p>
                            <a href="staff_checkout.php" class="btn btn-outline-primary mt-auto">
                                Go to reservation checkout
                            </a>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-body d-flex flex-column">
                            <h5 class="card-title">Quick Checkout</h5>
                            <p class="card-text">
                                Perform ad-hoc bulk checkouts via Snipe-IT without selecting a reservation.
                            </p>
                            <a href="quick_checkout.php" class="btn btn-outline-primary mt-auto">
                                Go to quick checkout
                            </a>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-body d-flex flex-column">
                            <h5 class="card-title">Quick Checkin</h5>
                            <p class="card-text">
                                Scan asset tags to check items back in via Snipe-IT (quick scan style).
                            </p>
                            <a href="quick_checkin.php" class="btn btn-outline-primary mt-auto">
                                Go to quick checkin
                            </a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <div class="mt-4">
            <div class="alert alert-secondary mb-0">
                Need help or something is missing from the catalogue? Please contact staff.
            </div>
        </div>
    </div>
</div>
<?php reserveit_footer(); ?>
</body>
</html>
