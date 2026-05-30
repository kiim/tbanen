<?php
// Prevent direct HTTP access
if (!defined('TBANEN_ADMIN')) { http_response_code(403); exit; }

// ── Password ──────────────────────────────────────────────────────
// Default password: tbanen-oslo
// To change: php -r "echo password_hash('newpassword', PASSWORD_DEFAULT);"
// Then replace the hash below.
define('ADMIN_PASSWORD_HASH', '$2y$10$H0LXAgDgfsQldOu2Vv9/V.wPiOFbmW1JzbbeePpA3l5EhUb/q7MsO');

// ── Paths ─────────────────────────────────────────────────────────
define('ROOT',          dirname(__DIR__));
define('DATA_FILE',     ROOT . '/data/stations.json');
define('DATA_BACKUPS',  ROOT . '/data/backups/');
define('IMG_STATIONS',  ROOT . '/images/stations/');
define('IMG_ORIGINALS', ROOT . '/images/originals/');
define('IMG_THUMBS',    ROOT . '/images/thumbs/');
define('IMG_BASE_URL',  '/tbanen/images/');
