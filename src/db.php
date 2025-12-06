<?php
/**
 * db.php
 *
 * Single PDO connection for the booking app database (snipeit_reservations).
 * This file no longer connects to the live Snipe-IT database at all.
 */

require_once __DIR__ . '/bootstrap.php';

$config = load_config();

if (!is_array($config) || empty($config['db_booking'])) {
    throw new RuntimeException('Booking database configuration (db_booking) is missing in config.php');
}

$db = $config['db_booking'];

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $db['host'],
    $db['port'],
    $db['dbname'],
    $db['charset'] ?? 'utf8mb4'
);

try {
    $pdo = new PDO(
        $dsn,
        $db['username'],
        $db['password'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    throw new RuntimeException('Could not connect to booking database: ' . $e->getMessage(), 0, $e);
}

/**
 * Helpers to support legacy table/column names during the student → user rename.
 */
if (!function_exists('reserveit_table_exists')) {
    function reserveit_table_exists(PDO $pdo, string $table): bool
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t");
        $stmt->execute([':t' => $table]);
        return (int)$stmt->fetchColumn() > 0;
    }
}

if (!function_exists('reserveit_column_exists')) {
    function reserveit_column_exists(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM information_schema.columns
            WHERE table_schema = DATABASE()
              AND table_name = :t
              AND column_name = :c
        ");
        $stmt->execute([':t' => $table, ':c' => $column]);
        return (int)$stmt->fetchColumn() > 0;
    }
}

if (!function_exists('reserveit_users_table_name')) {
    function reserveit_users_table_name(PDO $pdo): string
    {
        return reserveit_table_exists($pdo, 'users') ? 'users' : 'students';
    }
}

if (!function_exists('reserveit_reservation_user_fields')) {
    /**
     * Return column names for reservation user id/name/email, handling legacy student_* columns.
     */
    function reserveit_reservation_user_fields(PDO $pdo): array
    {
        $hasUserId   = reserveit_column_exists($pdo, 'reservations', 'user_id');
        $hasUserName = reserveit_column_exists($pdo, 'reservations', 'user_name');
        $hasUserMail = reserveit_column_exists($pdo, 'reservations', 'user_email');

        return [
            'id'    => $hasUserId ? 'user_id' : 'student_id',
            'name'  => $hasUserName ? 'user_name' : 'student_name',
            'email' => $hasUserMail ? 'user_email' : 'student_email',
        ];
    }
}
