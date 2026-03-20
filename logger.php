<?php
/**
 * logger.php — Shared SQLite logging for the ASR Backoffice.
 * Included by proxy.php and send-email.php.
 * Logging failures are silenced so they never break the main flow.
 */

define('BACKOFFICE_DB_PATH', __DIR__ . '/db/logs.sqlite');

function backoffice_get_db(): PDO
{
    $dir = __DIR__ . '/db';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $pdo = new PDO('sqlite:' . BACKOFFICE_DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode=WAL');
    $pdo->exec('PRAGMA foreign_keys=ON');

    backoffice_init_db($pdo);
    return $pdo;
}

function backoffice_init_db(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS transcriptions (
            id               INTEGER PRIMARY KEY AUTOINCREMENT,
            created_at       DATETIME DEFAULT (datetime('now')),
            filename         TEXT     NOT NULL,
            file_size        INTEGER,
            task             TEXT     DEFAULT 'transcribe',
            language         TEXT,
            duration_seconds REAL,
            success          INTEGER  DEFAULT 1,
            error_message    TEXT,
            ip_address       TEXT,
            text_length      INTEGER
        );

        CREATE TABLE IF NOT EXISTS emails (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            created_at    DATETIME DEFAULT (datetime('now')),
            recipient     TEXT     NOT NULL,
            files_count   INTEGER  DEFAULT 1,
            method        TEXT,
            success       INTEGER  DEFAULT 1,
            error_message TEXT,
            ip_address    TEXT
        );
    ");
}

function log_transcription(array $data): void
{
    try {
        $pdo  = backoffice_get_db();
        $stmt = $pdo->prepare("
            INSERT INTO transcriptions
                (filename, file_size, task, language, duration_seconds,
                 success, error_message, ip_address, text_length)
            VALUES
                (:filename, :file_size, :task, :language, :duration_seconds,
                 :success, :error_message, :ip_address, :text_length)
        ");
        $stmt->execute([
            ':filename'         => $data['filename']         ?? '',
            ':file_size'        => $data['file_size']        ?? null,
            ':task'             => $data['task']             ?? 'transcribe',
            ':language'         => $data['language']         ?? null,
            ':duration_seconds' => $data['duration_seconds'] ?? null,
            ':success'          => empty($data['success'])   ? 0 : 1,
            ':error_message'    => $data['error_message']    ?? null,
            ':ip_address'       => $_SERVER['REMOTE_ADDR']   ?? null,
            ':text_length'      => $data['text_length']      ?? null,
        ]);
    } catch (Throwable $e) {
        error_log('[backoffice logger] log_transcription: ' . $e->getMessage());
    }
}

function log_email(array $data): void
{
    try {
        $pdo  = backoffice_get_db();
        $stmt = $pdo->prepare("
            INSERT INTO emails
                (recipient, files_count, method, success, error_message, ip_address)
            VALUES
                (:recipient, :files_count, :method, :success, :error_message, :ip_address)
        ");
        $stmt->execute([
            ':recipient'     => $data['recipient']   ?? '',
            ':files_count'   => $data['files_count'] ?? 1,
            ':method'        => $data['method']      ?? null,
            ':success'       => empty($data['success']) ? 0 : 1,
            ':error_message' => $data['error_message'] ?? null,
            ':ip_address'    => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) {
        error_log('[backoffice logger] log_email: ' . $e->getMessage());
    }
}
