<?php
/**
 * Web installer for ReserveIT.
 *
 * Builds config/config.php and (optionally) creates the database using schema.sql.
 * Use only during initial setup. If config.php already exists, you must confirm overwriting it.
 */

// Minimal bootstrapping (avoid loading config-dependent code)
define('APP_ROOT', dirname(__DIR__));
define('CONFIG_PATH', APP_ROOT . '/config');

require_once APP_ROOT . '/src/config_writer.php';

$configPath  = CONFIG_PATH . '/config.php';
$examplePath = CONFIG_PATH . '/config.example.php';
$schemaPath  = APP_ROOT . '/schema.sql';
$installedFlag = APP_ROOT . '/.installed';
$installedFlag = APP_ROOT . '/.installed';

function installer_load_array(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $data = require $path;
    return is_array($data) ? $data : [];
}

function installer_value(array $source, array $path, $fallback = '')
{
    $ref = $source;
    foreach ($path as $key) {
        if (!is_array($ref) || !array_key_exists($key, $ref)) {
            return $fallback;
        }
        $ref = $ref[$key];
    }
    return $ref === null ? $fallback : $ref;
}

function installer_h(string $val): string
{
    return htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
}

$existingConfig  = installer_load_array($configPath);
$defaultConfig   = installer_load_array($examplePath);
$prefillConfig   = $existingConfig ?: $defaultConfig;
$configExists    = is_file($configPath);

$definedValues = [
    'SNIPEIT_API_PAGE_LIMIT'   => defined('SNIPEIT_API_PAGE_LIMIT') ? SNIPEIT_API_PAGE_LIMIT : 12,
    'CATALOGUE_ITEMS_PER_PAGE' => defined('CATALOGUE_ITEMS_PER_PAGE') ? CATALOGUE_ITEMS_PER_PAGE : 12,
    'SNIPEIT_MAX_MODELS_FETCH' => defined('SNIPEIT_MAX_MODELS_FETCH') ? SNIPEIT_MAX_MODELS_FETCH : 1000,
];

$isAjax = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
    || (isset($_POST['ajax']) && $_POST['ajax'] == '1');
$messages = [];
$errors   = [];
$installLocked = is_file($installedFlag);
$installCompleted = false;
$redirectTo = null;

function installer_test_db(array $db): string
{
    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $db['host'] ?? 'localhost',
        (int)($db['port'] ?? 3306),
        $db['dbname'] ?? '',
        $db['charset'] ?? 'utf8mb4'
    );

    $pdo = new PDO($dsn, $db['username'] ?? '', $db['password'] ?? '', [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    $row = $pdo->query('SELECT 1')->fetchColumn();
    if ((int)$row !== 1) {
        throw new Exception('Connected but validation query failed.');
    }
    return 'Database connection succeeded.';
}

function installer_test_snipe(array $snipe): string
{
    if (!function_exists('curl_init')) {
        throw new Exception('PHP cURL extension is not installed.');
    }
    $base   = rtrim($snipe['base_url'] ?? '', '/');
    $token  = $snipe['api_token'] ?? '';
    $verify = !empty($snipe['verify_ssl']);

    if ($base === '' || $token === '') {
        throw new Exception('Base URL or API token is missing.');
    }

    $url = $base . '/api/v1/models?limit=1';
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_SSL_VERIFYPEER => $verify,
        CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 4,
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new Exception('cURL error: ' . $err);
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode($raw, true);
    if ($code >= 400) {
        $msg = $decoded['message'] ?? $raw;
        throw new Exception('HTTP ' . $code . ': ' . $msg);
    }
    return 'Snipe-IT API reachable (HTTP ' . $code . ').';
}

function installer_test_ldap(array $ldap): string
{
    if (!function_exists('ldap_connect')) {
        throw new Exception('PHP LDAP extension is not installed.');
    }

    $host    = $ldap['host'] ?? '';
    $baseDn  = $ldap['base_dn'] ?? '';
    $bindDn  = $ldap['bind_dn'] ?? '';
    $bindPwd = $ldap['bind_password'] ?? '';
    $ignore  = !empty($ldap['ignore_cert']);

    if ($host === '') {
        throw new Exception('LDAP host is missing.');
    }

    if ($ignore && function_exists('ldap_set_option')) {
        @ldap_set_option(null, LDAP_OPT_X_TLS_REQUIRE_CERT, LDAP_OPT_X_TLS_ALLOW);
    }

    $conn = @ldap_connect($host);
    if (!$conn) {
        throw new Exception('Could not connect to LDAP host.');
    }
    @ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
    @ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
    if (defined('LDAP_OPT_NETWORK_TIMEOUT')) {
        @ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, 5);
    }

    $bindOk = $bindDn !== ''
        ? @ldap_bind($conn, $bindDn, $bindPwd)
        : @ldap_bind($conn);

    if ($bindOk === false) {
        $err = function_exists('ldap_error') ? @ldap_error($conn) : 'Unknown LDAP error';
        throw new Exception('Bind failed: ' . $err);
    }

    if ($baseDn !== '') {
        $search = @ldap_search($conn, $baseDn, '(objectClass=*)', ['dn'], 0, 1, 3);
        if ($search === false) {
            $err = function_exists('ldap_error') ? @ldap_error($conn) : 'Unknown LDAP error';
            throw new Exception('Search failed: ' . $err);
        }
    }

    @ldap_unbind($conn);
    return 'LDAP connection and bind succeeded.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$installLocked) {
    $post = static function (string $key, $fallback = '') {
        return trim($_POST[$key] ?? $fallback);
    };

    $action = $_POST['action'] ?? 'save';

    if ($configExists && !isset($_POST['overwrite_ok']) && $action === 'save') {
        $errors[] = 'config.php already exists. Check "Overwrite existing config.php" to proceed.';
    } else {
        $dbHost    = $post('db_host', 'localhost');
        $dbPort    = (int)$post('db_port', '3306');
        $dbName    = $post('db_name', 'reserveit');
        $dbUser    = $post('db_username', '');
        $dbPassRaw = $_POST['db_password'] ?? '';
        $dbPass    = $dbPassRaw;
        $dbCharset = $post('db_charset', 'utf8mb4');

        $snipeUrl     = $post('snipe_base_url', '');
        $snipeTokenRaw = $_POST['snipe_api_token'] ?? '';
        $snipeToken    = $snipeTokenRaw;
        $snipeVerify   = isset($_POST['snipe_verify_ssl']);

        $ldapHost   = $post('ldap_host', 'ldaps://');
        $ldapBase   = $post('ldap_base_dn', '');
        $ldapBind   = $post('ldap_bind_dn', '');
        $ldapPassRaw = $_POST['ldap_bind_password'] ?? '';
        $ldapPass    = $ldapPassRaw;
        $ldapIgnore  = isset($_POST['ldap_ignore_cert']);

        // Defaults for omitted settings
        $staffCns   = [];
        $timezone   = 'Europe/Jersey';
        $debug      = false;
        $logoUrl    = '';
        $primary    = '#660000';
        $missed     = 60;
        $pageLimit  = $definedValues['SNIPEIT_API_PAGE_LIMIT'];
        $cataloguePP = $definedValues['CATALOGUE_ITEMS_PER_PAGE'];
        $maxModels  = $definedValues['SNIPEIT_MAX_MODELS_FETCH'];

        $newConfig = $defaultConfig;
        $newConfig['db_booking'] = [
            'host'     => $dbHost,
            'port'     => $dbPort,
            'dbname'   => $dbName,
            'username' => $dbUser,
            'password' => $dbPass,
            'charset'  => $dbCharset,
        ];
        $newConfig['snipeit'] = [
            'base_url'   => $snipeUrl,
            'api_token'  => $snipeToken,
            'verify_ssl' => $snipeVerify,
        ];
        $newConfig['ldap'] = [
            'host'          => $ldapHost,
            'base_dn'       => $ldapBase,
            'bind_dn'       => $ldapBind,
            'bind_password' => $ldapPass,
            'ignore_cert'   => $ldapIgnore,
        ];
        $newConfig['auth']['staff_group_cn'] = $staffCns;
        $newConfig['app'] = [
            'timezone'              => $timezone,
            'debug'                 => $debug,
            'logo_url'              => $logoUrl,
            'primary_color'         => $primary,
            'missed_cutoff_minutes' => $missed,
        ];

        if ($isAjax && $action !== 'save') {
            try {
                if ($action === 'test_db') {
                    $messages[] = installer_test_db($newConfig['db_booking']);
                } elseif ($action === 'test_api') {
                    $messages[] = installer_test_snipe($newConfig['snipeit']);
                } elseif ($action === 'test_ldap') {
                    $messages[] = installer_test_ldap($newConfig['ldap']);
                } else {
                    $errors[] = 'Unknown test action.';
                }
            } catch (Throwable $e) {
                $errors[] = $e->getMessage();
            }

            header('Content-Type: application/json');
            echo json_encode([
                'ok'       => empty($errors),
                'messages' => $messages,
                'errors'   => $errors,
            ]);
            exit;
        }

        if (!is_dir(CONFIG_PATH)) {
            @mkdir(CONFIG_PATH, 0755, true);
        }

        $content = reserveit_build_config_file($newConfig, [
            'SNIPEIT_API_PAGE_LIMIT'   => $pageLimit,
            'CATALOGUE_ITEMS_PER_PAGE' => $cataloguePP,
            'SNIPEIT_MAX_MODELS_FETCH' => $maxModels,
        ]);

        if (@file_put_contents($configPath, $content, LOCK_EX) === false) {
            $errors[] = 'Failed to write config.php. Check permissions on the config/ directory.';
        } else {
            $messages[] = 'Config file written to config/config.php.';
            $prefillConfig = $newConfig;
            $configExists  = true;
        }

        $setupDb = isset($_POST['setup_db']);
        if (!$errors && $setupDb) {
            $dsnBase = sprintf('mysql:host=%s;port=%d;charset=%s', $dbHost, $dbPort, $dbCharset);
            try {
                $pdo = new PDO($dsnBase, $dbUser, $dbPass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                ]);

                $dbNameEsc = str_replace('`', '``', $dbName);
                $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbNameEsc}` CHARACTER SET {$dbCharset} COLLATE utf8mb4_unicode_ci");
                $pdo->exec("USE `{$dbNameEsc}`");

                if (!is_file($schemaPath)) {
                    throw new RuntimeException("schema.sql not found at {$schemaPath}");
                }

                $schemaSql = file_get_contents($schemaPath);
                $pdo->exec($schemaSql);

                $messages[] = "Database '{$dbName}' is ready.";
            } catch (Throwable $e) {
                $errors[] = 'Database setup failed: ' . installer_h($e->getMessage());
            }
        }

        // Mark installation complete if everything succeeded.
        if (!$errors) {
            @file_put_contents($installedFlag, "Installed on " . date(DATE_ATOM) . "\n");
            $installLocked = true;
            $installCompleted = true;
            $redirectTo = 'index.php';
            $messages[] = 'Installation complete. Please delete public/install.php (or restrict access) now.';
            if (!headers_sent()) {
                header('Refresh: 3; url=' . $redirectTo);
            }
        }
    }
}

// Prefill values for the form
$pref = static function (array $path, $fallback = '') use ($prefillConfig) {
    return installer_value($prefillConfig, $path, $fallback);
};
$staffPref = $pref(['auth', 'staff_group_cn'], []);
if (!is_array($staffPref)) {
    $staffPref = [];
}
$staffText = implode("\n", $staffPref);

?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>ReserveIT – Web Installer</title>
    <link rel="stylesheet"
          href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/style.css">
    <style>
        body { background: #f7f9fc; }
        .installer-page {
            max-width: 960px;
            margin: 0 auto;
        }
    </style>
</head>
<body class="p-4">
<div class="container installer-page">
    <div class="page-shell">
        <div class="page-header">
            <h1>ReserveIT Installer</h1>
            <div class="page-subtitle">
                Create config.php and initialise the database. For production security, remove or protect this file after setup.
            </div>
        </div>

        <?php if ($installLocked): ?>
            <div class="alert alert-info">
                Installation already completed. Remove the <code>.installed</code> file in the project root to rerun the installer.
            </div>
        <?php endif; ?>

        <?php if ($configExists && !$installLocked): ?>
            <div class="alert alert-warning">
                A config file already exists at <code><?= installer_h($configPath) ?></code>. To overwrite, tick the checkbox below.
            </div>
        <?php endif; ?>

        <?php if ($messages): ?>
            <div class="alert alert-success">
                <?= implode('<br>', array_map('installer_h', $messages)) ?>
            </div>
        <?php endif; ?>

        <?php if ($errors): ?>
            <div class="alert alert-danger">
                <?= implode('<br>', $errors) ?>
            </div>
        <?php endif; ?>

        <?php if (!$installLocked): ?>
        <form method="post" action="install.php" class="row g-3" id="installer-form">
            <?php if ($configExists): ?>
                <div class="col-12">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="overwrite_ok" id="overwrite_ok">
                        <label class="form-check-label" for="overwrite_ok">Overwrite existing config.php</label>
                    </div>
                </div>
            <?php endif; ?>

            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title mb-1">Database</h5>
                        <p class="text-muted small mb-3">Booking app database connection (not the Snipe-IT DB). Installer will create the database and tables.</p>
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Host</label>
                                <input type="text" name="db_host" class="form-control" value="<?= installer_h($pref(['db_booking', 'host'], 'localhost')) ?>" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Port</label>
                                <input type="number" name="db_port" class="form-control" value="<?= (int)$pref(['db_booking', 'port'], 3306) ?>" required>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Database name</label>
                                <input type="text" name="db_name" class="form-control" value="<?= installer_h($pref(['db_booking', 'dbname'], 'reserveit')) ?>" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Username</label>
                                <input type="text" name="db_username" class="form-control" value="<?= installer_h($pref(['db_booking', 'username'], '')) ?>" required>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Password</label>
                                <input type="password" name="db_password" class="form-control">
                            </div>
                            <div class="col-md-2">
                                <label class="form-label">Charset</label>
                                <input type="text" name="db_charset" class="form-control" value="<?= installer_h($pref(['db_booking', 'charset'], 'utf8mb4')) ?>">
                            </div>
                            <div class="col-12">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" id="setup_db" name="setup_db" checked>
                                    <label class="form-check-label" for="setup_db">Create database and run schema.sql</label>
                                </div>
                            </div>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mt-3">
                            <div class="small text-muted" id="db-test-result"></div>
                            <button type="button" class="btn btn-outline-primary btn-sm" data-test-action="test_db" data-target="db-test-result">Test database connection</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title mb-1">Snipe-IT API</h5>
                        <p class="text-muted small mb-3">Connection details for your Snipe-IT instance.</p>
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Base URL</label>
                                <input type="text" name="snipe_base_url" class="form-control" value="<?= installer_h($pref(['snipeit', 'base_url'], '')) ?>">
                                <div class="form-text">Example: https://snipeit.example.com</div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">API token</label>
                                <input type="password" name="snipe_api_token" class="form-control">
                            </div>
                            <div class="col-md-4 d-flex align-items-end">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="snipe_verify_ssl" id="snipe_verify_ssl" <?= $pref(['snipeit', 'verify_ssl'], false) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="snipe_verify_ssl">Verify SSL certificate</label>
                                </div>
                            </div>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mt-3">
                            <div class="small text-muted" id="api-test-result"></div>
                            <button type="button" class="btn btn-outline-primary btn-sm" data-test-action="test_api" data-target="api-test-result">Test Snipe-IT API</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title mb-1">LDAP / Active Directory</h5>
                        <p class="text-muted small mb-3">Authentication lookup settings.</p>
                        <div class="row g-3">
                            <div class="col-md-4">
                                <label class="form-label">LDAP host (e.g. ldaps://host)</label>
                                <input type="text" name="ldap_host" class="form-control" value="<?= installer_h($pref(['ldap', 'host'], 'ldaps://')) ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Base DN</label>
                                <input type="text" name="ldap_base_dn" class="form-control" value="<?= installer_h($pref(['ldap', 'base_dn'], '')) ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Bind DN (service account)</label>
                                <input type="text" name="ldap_bind_dn" class="form-control" value="<?= installer_h($pref(['ldap', 'bind_dn'], '')) ?>">
                            </div>
                            <div class="col-md-4">
                                <label class="form-label">Bind password</label>
                                <input type="password" name="ldap_bind_password" class="form-control">
                            </div>
                            <div class="col-md-4 d-flex align-items-end">
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="ldap_ignore_cert" id="ldap_ignore_cert" <?= $pref(['ldap', 'ignore_cert'], true) ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="ldap_ignore_cert">Ignore SSL certificate errors</label>
                                </div>
                            </div>
                        </div>
                        <div class="d-flex justify-content-between align-items-center mt-3">
                            <div class="small text-muted" id="ldap-test-result"></div>
                            <button type="button" class="btn btn-outline-primary btn-sm" data-test-action="test_ldap" data-target="ldap-test-result">Test LDAP connection</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <!-- Non-essential settings removed; defaults will be generated in config.php -->
            </div>

            <div class="col-12 d-flex justify-content-end">
                <button type="submit" name="action" value="save" class="btn btn-primary">Generate config &amp; install</button>
            </div>
        </form>
        <?php else: ?>
        <div class="alert alert-secondary mt-3 mb-0">
            Installer is locked. Remove the <code>.installed</code> file to run again.
        </div>
        <?php endif; ?>
    </div>
</div>
</body>
<?php if (!$installLocked): ?>
<script>
(function () {
    const form = document.getElementById('installer-form');
    if (!form) return;

    const clearStatus = (el) => {
        if (!el) return;
        el.textContent = '';
        el.classList.remove('text-success', 'text-danger');
        el.classList.add('text-muted');
    };

    const setStatus = (el, text, isError) => {
        if (!el) return;
        el.textContent = text;
        el.classList.remove('text-muted');
        el.classList.toggle('text-success', !isError);
        el.classList.toggle('text-danger', isError);
    };

    form.querySelectorAll('[data-test-action]').forEach((btn) => {
        btn.addEventListener('click', (e) => {
            e.preventDefault();
            const action = btn.getAttribute('data-test-action');
            const targetId = btn.getAttribute('data-target');
            const target = targetId ? document.getElementById(targetId) : null;
            clearStatus(target);
            setStatus(target, 'Testing...', false);
            btn.disabled = true;

            const fd = new FormData(form);
            fd.set('action', action);
            fd.set('ajax', '1');

            const actionUrl = (form.getAttribute('action') || window.location.href).split('#')[0];
            const controller = new AbortController();
            const timeout = setTimeout(() => controller.abort(), 8000);

            fetch(actionUrl, {
                method: 'POST',
                body: fd,
                headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
                credentials: 'same-origin',
                signal: controller.signal
            })
                .then(async (res) => {
                    clearTimeout(timeout);
                    if (!res.ok) {
                        const text = await res.text().catch(() => '');
                        throw new Error(text || 'Request failed');
                    }
                    try {
                        return await res.json();
                    } catch (_) {
                        const text = await res.text().catch(() => '');
                        throw new Error(text || 'Invalid response');
                    }
                })
                .then((data) => {
                    const errs = Array.isArray(data.errors) ? data.errors : [];
                    const msgs = Array.isArray(data.messages) ? data.messages : [];
                    if (errs.length) {
                        setStatus(target, errs.join(' | '), true);
                    } else if (msgs.length) {
                        setStatus(target, msgs.join(' | '), false);
                    } else {
                        setStatus(target, 'No response received.', true);
                    }
                })
                .catch((err) => {
                    clearTimeout(timeout);
                    if (err.name === 'AbortError') {
                        setStatus(target, 'Request timed out. Please check the host/URL.', true);
                    } else {
                        setStatus(target, err.message || 'Test failed.', true);
                    }
                })
                .finally(() => {
                    btn.disabled = false;
                });
        });
    });
})();
</script>
<?php endif; ?>
</html>
