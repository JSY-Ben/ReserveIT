<?php
// footer.php
// Shared footer renderer for ReserveIT pages.

require_once __DIR__ . '/bootstrap.php';

/**
 * Cache config and expose helper functions for shared UI elements.
 */
if (!function_exists('reserveit_cached_config')) {
    function reserveit_cached_config(?array $cfg = null): array
    {
        static $cachedConfig = null;

        if ($cfg !== null) {
            return $cfg;
        }

        if ($cachedConfig === null) {
            try {
                $cachedConfig = load_config();
            } catch (Throwable $e) {
                $cachedConfig = [];
            }
        }

        return $cachedConfig ?? [];
    }
}

/**
 * Normalize a hex color string to #rrggbb.
 */
if (!function_exists('reserveit_normalize_hex_color')) {
    function reserveit_normalize_hex_color(?string $color, string $fallback): string
    {
        $fallback = ltrim($fallback, '#');
        $candidate = trim((string)$color);

        if (preg_match('/^#?([0-9a-fA-F]{6})$/', $candidate, $m)) {
            $hex = strtolower($m[1]);
        } elseif (preg_match('/^#?([0-9a-fA-F]{3})$/', $candidate, $m)) {
            $hex = strtolower($m[1]);
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        } else {
            $hex = strtolower($fallback);
        }

        return '#' . $hex;
    }
}

/**
 * Convert #rrggbb to [r, g, b].
 */
if (!function_exists('reserveit_color_to_rgb')) {
    function reserveit_color_to_rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }
}

/**
 * Adjust lightness: positive to lighten, negative to darken.
 */
if (!function_exists('reserveit_adjust_lightness')) {
    function reserveit_adjust_lightness(string $hex, float $ratio): string
    {
        $ratio = max(-1.0, min(1.0, $ratio));
        [$r, $g, $b] = reserveit_color_to_rgb($hex);

        $adjust = static function (int $channel) use ($ratio): int {
            if ($ratio >= 0) {
                return (int)round($channel + (255 - $channel) * $ratio);
            }
            return (int)round($channel * (1 + $ratio));
        };

        $nr = str_pad(dechex($adjust($r)), 2, '0', STR_PAD_LEFT);
        $ng = str_pad(dechex($adjust($g)), 2, '0', STR_PAD_LEFT);
        $nb = str_pad(dechex($adjust($b)), 2, '0', STR_PAD_LEFT);

        return '#' . $nr . $ng . $nb;
    }
}

if (!function_exists('reserveit_primary_color')) {
    function reserveit_primary_color(?array $cfg = null): string
    {
        $config = reserveit_cached_config($cfg);
        $raw    = $config['app']['primary_color'] ?? '#660000';

        return reserveit_normalize_hex_color($raw, '#660000');
    }
}

if (!function_exists('reserveit_theme_styles')) {
    function reserveit_theme_styles(?array $cfg = null): string
    {
        $primary      = reserveit_primary_color($cfg);
        $primarySoft  = reserveit_adjust_lightness($primary, 0.3);   // subtle gradient partner
        $primaryStrong = reserveit_adjust_lightness($primary, -0.08); // slightly deeper for contrast

        [$r, $g, $b]          = reserveit_color_to_rgb($primary);
        [$rs, $gs, $bs]       = reserveit_color_to_rgb($primaryStrong);
        [$rl, $gl, $bl]       = reserveit_color_to_rgb($primarySoft);

        $style = <<<CSS
<style>
:root {
    --primary: {$primary};
    --primary-strong: {$primaryStrong};
    --primary-soft: {$primarySoft};
    --primary-rgb: {$r}, {$g}, {$b};
    --primary-strong-rgb: {$rs}, {$gs}, {$bs};
    --primary-soft-rgb: {$rl}, {$gl}, {$bl};
    --accent: var(--primary-strong);
    --accent-2: var(--primary-soft);
}
</style>
CSS;

        return $style;
    }
}

if (!function_exists('reserveit_render_nav')) {
    /**
     * Render the main app navigation. Highlights the active page and hides staff-only items for non-staff users.
     */
    function reserveit_render_nav(string $active, bool $isStaff): string
    {
        $links = [
            ['href' => 'index.php',          'label' => 'Dashboard',           'staff' => false],
            ['href' => 'catalogue.php',      'label' => 'Catalogue',           'staff' => false],
            ['href' => 'my_bookings.php',    'label' => 'My Reservations',     'staff' => false],
            ['href' => 'reservations.php',   'label' => 'Reservations',        'staff' => true],
            ['href' => 'quick_checkout.php', 'label' => 'Quick Checkout',      'staff' => true],
            ['href' => 'quick_checkin.php',  'label' => 'Quick Checkin',       'staff' => true],
            ['href' => 'settings.php',       'label' => 'Settings',            'staff' => true],
        ];

        $html = '<nav class="app-nav">';
        foreach ($links as $link) {
            if ($link['staff'] && !$isStaff) {
                continue;
            }

            $href    = htmlspecialchars($link['href'], ENT_QUOTES, 'UTF-8');
            $label   = htmlspecialchars($link['label'], ENT_QUOTES, 'UTF-8');
            $classes = 'app-nav-link' . ($active === $link['href'] ? ' active' : '');

            $html .= '<a href="' . $href . '" class="' . $classes . '">' . $label . '</a>';
        }
        $html .= '</nav>';

        return $html;
    }
}

if (!function_exists('reserveit_footer')) {
    function reserveit_footer(): void
    {
        $versionFile = APP_ROOT . '/version.txt';
        $versionRaw  = is_file($versionFile) ? trim((string)@file_get_contents($versionFile)) : '';
        $version     = $versionRaw !== '' ? $versionRaw : 'dev';
        $versionEsc  = htmlspecialchars($version, ENT_QUOTES, 'UTF-8');

        echo '<script src="assets/nav.js"></script>';
        echo '<footer class="text-center text-muted mt-4 small">'
            . 'ReserveIT Version ' . $versionEsc . ' - Created by '
            . '<a href="https://www.linkedin.com/in/ben-pirozzolo-76212a88" target="_blank" rel="noopener noreferrer">Ben Pirozzolo</a>'
            . '</footer>';
    }
}

if (!function_exists('reserveit_logo_tag')) {
    function reserveit_logo_tag(?array $cfg = null): string
    {
        $cfg = reserveit_cached_config($cfg);

        $logoUrl = '';
        if (isset($cfg['app']['logo_url']) && trim($cfg['app']['logo_url']) !== '') {
            $logoUrl = trim($cfg['app']['logo_url']);
        }

        if ($logoUrl === '') {
            return '';
        }

        $urlEsc = htmlspecialchars($logoUrl, ENT_QUOTES, 'UTF-8');
        return '<div class="app-logo text-center mb-3">'
            . '<img src="' . $urlEsc . '" alt="ReserveIT logo" style="max-height:80px; width:auto; height:auto; max-width:100%; object-fit:contain;">'
            . '</div>';
    }
}
