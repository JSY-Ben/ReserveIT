<?php
/**
 * Global configuration for the Snipe-IT booking app.
 *
 * Edit the values below to match your environment.
 */

/**
 * Paging / limits for catalogue + Snipe-IT API
 * These run as soon as config.php is required.
 */
if (!defined('SNIPEIT_API_PAGE_LIMIT')) {
    define('SNIPEIT_API_PAGE_LIMIT', 12); // or whatever default you want
}

if (!defined('CATALOGUE_ITEMS_PER_PAGE')) {
    define('CATALOGUE_ITEMS_PER_PAGE', SNIPEIT_API_PAGE_LIMIT);
}

/**
 * Maximum number of models that will ever be fetched from Snipe-IT
 * in one catalogue request (safety cap).
 *
 * This is used so sorting can be done globally before pagination.
 */
if (!defined('SNIPEIT_MAX_MODELS_FETCH')) {
    define('SNIPEIT_MAX_MODELS_FETCH', 1000);
}

// ---------------------------------------------------------------------
// Main config array (keep your existing values here)
// ---------------------------------------------------------------------
return [

    'db_booking' => [
        'host'     => 'localhost',
        'port'     => 3306,
        'dbname'   => '',
        'username' => '',
        'password' => '',      // keep your existing password
        'charset'  => 'utf8mb4',
    ],

    'ldap' => [
        'host'          => 'ldaps://',
        'base_dn'       => '',
        'bind_dn'       => '',
        'bind_password' => '', // keep your existing password
        'ignore_cert'   => true,
    ],

    'snipeit' => [
        'base_url'  => '',
        'api_token' => '',     // keep your existing token
        'verify_ssl' => false,
    ],

    'auth' => [
        'staff_group_cn' => '',
    ],

    'app' => [
        'timezone' => 'Europe/Jersey',
        'debug'    => true,
    ],
];
