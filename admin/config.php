<?php
// Prevent direct HTTP access
if (!defined('TBANEN_ADMIN')) { http_response_code(403); exit; }

// ── Password ──────────────────────────────────────────────────────
// Default password: tbanen-oslo
// To change: php -r "echo password_hash('newpassword', PASSWORD_DEFAULT);"
// Then replace the hash below.
define('ADMIN_PASSWORD_HASH', '$2y$10$BJQzTh0IpDXvnxtQ3/DSy.v4y7ek9A91Vtpo8osW9nN9YKpT5mv/G');

// ── Paths ─────────────────────────────────────────────────────────
define('ROOT',          dirname(__DIR__));
define('DATA_FILE',     ROOT . '/data/stations.json');
define('DATA_BACKUPS',  ROOT . '/data/backups/');
define('IMG_STATIONS',  ROOT . '/images/stations/');   // large 2400px (also served to frontend)
define('IMG_LARGE',     ROOT . '/images/large/');      // alias kept for clarity
define('IMG_MEDIUM',    ROOT . '/images/medium/');     // 1400px
define('IMG_ORIGINALS', ROOT . '/images/originals/');
define('IMG_THUMBS',    ROOT . '/images/thumbs/');     // 500px admin + list
define('IMG_BASE_URL',  '/tbanen/images/');
