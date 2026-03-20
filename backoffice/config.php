<?php
/**
 * backoffice/config.php — Backoffice credentials.
 *
 * Default login:  admin / admin
 * CHANGE THE PASSWORD HASH before deploying to production!
 *
 * Generate a new hash with:
 *   php -r "echo password_hash('your_password', PASSWORD_BCRYPT);"
 */

define('BACKOFFICE_USER',      'admin');
define('BACKOFFICE_PASS_HASH', '$2y$10$P1/h/scGnm0/M2eGIaz56uoSxWOQrPBYpbVJlwr2QvFZ7bQAgMx7S'); // admin

define('WHISPER_API_URL',  'http://10.0.1.250:9000');
define('WHISPER_HEALTH_TIMEOUT', 5); // seconds
