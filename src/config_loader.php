<?php
// src/config_loader.php
// Centralised configuration loader with backward-compatible search paths.

function load_config(): array
{
    static $config = null;

    if (is_array($config)) {
        return $config;
    }

    $candidates = [
        CONFIG_PATH . '/config.php',
        APP_ROOT . '/config.php', // legacy path
    ];

    foreach ($candidates as $path) {
        if (is_file($path)) {
            $config = require $path;
            return is_array($config) ? $config : [];
        }
    }

    throw new RuntimeException('Config file not found. Place config.php in config/ (preferred) or project root.');
}
