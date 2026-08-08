<?php
// Copy this file to config.php, fill in DB_PASS, and upload config.php via FTP/File Manager.
// config.php is gitignored — it should never be committed.
//
// This is the SAME MyDataWorld database T-Minus uses — reuse that account's
// DB_USER/DB_PASS here rather than provisioning a new database.

define('DB_HOST', 'localhost');
define('DB_NAME', 'PUT_YOUR_ACCOUNT_PREFIX_HERE_MyDataWorld');
define('DB_USER', 'PUT_YOUR_DB_USERNAME_HERE');
define('DB_PASS', 'PUT_YOUR_DB_PASSWORD_HERE');

// How long a login session stays valid.
define('SESSION_LIFETIME_DAYS', 30);
