<?php
// scripts/teams_overdue_reminder.php
// Cron-safe CLI script: find overdue Snipe-IT assets and send Teams chats
// to the users who hold them (and optionally log to stdout).
//
// Requirements:
// - Configure Microsoft Graph app with application permissions: Chat.Create, Chat.ReadWrite, User.Read.All
// - Environment variables:
//     GRAPH_TENANT_ID
//     GRAPH_CLIENT_ID
//     GRAPH_CLIENT_SECRET
//     GRAPH_SCOPE (optional, defaults to https://graph.microsoft.com/.default)
// - ReserveIT config present (load_config) and Snipe-IT credentials set.
//
// Usage (cron):
//     * * * * * GRAPH_TENANT_ID=... GRAPH_CLIENT_ID=... GRAPH_CLIENT_SECRET=... /usr/bin/php /path/to/scripts/teams_overdue_reminder.php >> /var/log/reserveit_teams_reminder.log 2>&1
//
// Azure setup (for chats):
// 1) Register an app in Entra ID → get Client ID and Tenant ID.
// 2) Create a client secret.
// 3) Add Microsoft Graph application permissions: Chat.Create, Chat.ReadWrite, User.Read.All.
// 4) Grant admin consent for those permissions.
// 5) Use the env vars above; GRAPH_SCOPE defaults to https://graph.microsoft.com/.default.

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

require_once __DIR__ . '/../src/bootstrap.php';
require_once SRC_PATH . '/snipeit_client.php';

// -------------------------------------------------------------------------
// Microsoft Graph helpers
// -------------------------------------------------------------------------
function graph_get_token(): string
{
    $tenant = getenv('GRAPH_TENANT_ID');
    $client = getenv('GRAPH_CLIENT_ID');
    $secret = getenv('GRAPH_CLIENT_SECRET');
    $scope  = getenv('GRAPH_SCOPE') ?: 'https://graph.microsoft.com/.default';

    if (!$tenant || !$client || !$secret) {
        throw new RuntimeException('GRAPH_TENANT_ID, GRAPH_CLIENT_ID, and GRAPH_CLIENT_SECRET must be set.');
    }

    $tokenUrl = "https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token";
    $ch = curl_init($tokenUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type'    => 'client_credentials',
            'client_id'     => $client,
            'client_secret' => $secret,
            'scope'         => $scope,
        ]),
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        throw new RuntimeException('Failed to request token: ' . curl_error($ch));
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($raw, true);
    if ($code >= 400 || !isset($data['access_token'])) {
        throw new RuntimeException('Token request failed: ' . ($data['error_description'] ?? $raw));
    }
    return $data['access_token'];
}

function graph_send_chat(string $token, string $userUpn, string $preview, string $body): void
{
    $chatUrl = "https://graph.microsoft.com/v1.0/chats";
    // Create a 1:1 chat and send a message
    $payload = [
        'chatType' => 'oneOnOne',
        'members' => [
            [
                '@odata.type' => '#microsoft.graph.aadUserConversationMember',
                'roles'       => ['owner'],
                'user@odata.bind' => "https://graph.microsoft.com/v1.0/users('" . addslashes($userUpn) . "')",
            ]
        ],
    ];

    $ch = curl_init($chatUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload),
    ]);
    $raw = curl_exec($ch);
    if ($raw === false) {
        throw new RuntimeException('Graph create chat failed: ' . curl_error($ch));
    }
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 300) {
        throw new RuntimeException('Graph create chat HTTP ' . $code . ': ' . $raw);
    }

    $data = json_decode($raw, true);
    $chatId = $data['id'] ?? null;
    if (!$chatId) {
        throw new RuntimeException('Could not obtain chat ID from Graph response.');
    }

    // Send message
    $msgUrl = "https://graph.microsoft.com/v1.0/chats/{$chatId}/messages";
    $msgPayload = [
        'body' => [
            'contentType' => 'text',
            'content'     => $body,
        ],
    ];

    $ch = curl_init($msgUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($msgPayload),
    ]);
    $rawMsg = curl_exec($ch);
    if ($rawMsg === false) {
        throw new RuntimeException('Graph send message failed: ' . curl_error($ch));
    }
    $msgCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($msgCode >= 300) {
        throw new RuntimeException('Graph send message HTTP ' . $msgCode . ': ' . $rawMsg);
    }
}

// -------------------------------------------------------------------------
// Build overdue list and send notifications
// -------------------------------------------------------------------------
try {
    $assets = list_checked_out_assets(true); // overdue only
} catch (Throwable $e) {
    fwrite(STDERR, "[error] Failed to load overdue assets: {$e->getMessage()}\n");
    exit(1);
}

if (empty($assets)) {
    echo "[info] No overdue assets found.\n";
    exit(0);
}

// Bucket assets by assigned user email
$buckets = [];
foreach ($assets as $a) {
    $assigned = $a['assigned_to'] ?? ($a['assigned_to_fullname'] ?? '');
    $email    = '';
    $name     = '';
    if (is_array($assigned)) {
        $email = $assigned['email'] ?? ($assigned['username'] ?? '');
        $name  = $assigned['name'] ?? ($assigned['username'] ?? ($assigned['email'] ?? ''));
    } elseif (is_string($assigned)) {
        $name = $assigned;
    }
    if ($email === '') {
        // Cannot notify without an email/UPN; skip
        continue;
    }
    $tag    = $a['asset_tag'] ?? '';
    $model  = $a['model']['name'] ?? '';
    $expRaw = $a['_expected_checkin_norm'] ?? ($a['expected_checkin'] ?? '');
    $exp    = $expRaw ? date('Y-m-d', strtotime($expRaw)) : 'unknown';

    $line = $model !== '' ? "{$tag} ({$model}) – due {$exp}" : "{$tag} – due {$exp}";

    if (!isset($buckets[$email])) {
        $buckets[$email] = [
            'name'   => $name !== '' ? $name : $email,
            'assets' => [],
        ];
    }
    $buckets[$email]['assets'][] = $line;
}

if (empty($buckets)) {
    echo "[info] No overdue assets with a notifiable user.\n";
    exit(0);
}

try {
    $token = graph_get_token();
} catch (Throwable $e) {
    fwrite(STDERR, "[error] Could not obtain Graph token: {$e->getMessage()}\n");
    exit(1);
}

$sent = 0;
$failed = 0;
foreach ($buckets as $email => $info) {
    $preview = 'Overdue assets reminder';
    $body    = "The following assets are overdue:\n- " . implode("\n- ", $info['assets']);
    try {
        graph_send_chat($token, $email, $preview, $body);
        $sent++;
        echo "[sent] {$email}\n";
    } catch (Throwable $e) {
        $failed++;
        fwrite(STDERR, "[error] {$email}: {$e->getMessage()}\n");
    }
}

echo "[done] Sent: {$sent}, Failed: {$failed}\n";
