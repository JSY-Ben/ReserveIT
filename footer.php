<?php
// footer.php
// Shared footer renderer for ReserveIT pages.

if (!function_exists('reserveit_footer')) {
    function reserveit_footer(): void
    {
        $versionFile = __DIR__ . '/version.txt';
        $versionRaw  = is_file($versionFile) ? trim((string)@file_get_contents($versionFile)) : '';
        $version     = $versionRaw !== '' ? $versionRaw : 'dev';
        $versionEsc  = htmlspecialchars($version, ENT_QUOTES, 'UTF-8');

        echo '<footer class="text-center text-muted mt-4 small">'
            . 'ReserveIT Version ' . $versionEsc . ' - Created by '
            . '<a href="https://www.linkedin.com/in/ben-pirozzolo-76212a88" target="_blank" rel="noopener noreferrer">Ben Pirozzolo</a>'
            . '</footer>';
    }
}

if (!function_exists('reserveit_logo_tag')) {
    function reserveit_logo_tag(?array $cfg = null): string
    {
        static $cachedConfig = null;
        if ($cfg === null) {
            if ($cachedConfig === null && is_file(__DIR__ . '/config.php')) {
                $cachedConfig = require __DIR__ . '/config.php';
            }
            $cfg = $cachedConfig ?? [];
        }

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
