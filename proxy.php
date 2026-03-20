<?php
require_once __DIR__ . '/logger.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$task     = $_POST['task']     ?? 'transcribe';
$language = $_POST['language'] ?? '';
$output   = 'json'; // mudado de txt para json — resolve chunked encoding vazio

$url = 'http://10.0.1.250:9000/asr?encode=true&task=' . urlencode($task) . '&output=' . urlencode($output);
if ($language) $url .= '&language=' . urlencode($language);

if (empty($_FILES['audio_file']['tmp_name'])) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['detail' => 'Ficheiro não recebido pelo proxy']);
    exit;
}

$filename  = $_FILES['audio_file']['name'] ?? '';
$file_size = $_FILES['audio_file']['size'] ?? null;

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => [
        'audio_file' => new CURLFile(
            $_FILES['audio_file']['tmp_name'],
            $_FILES['audio_file']['type'],
            $filename
        )
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 600,
    CURLOPT_ENCODING       => '', // aceita gzip/deflate/chunked automaticamente
]);

$start     = microtime(true);
$response  = curl_exec($ch);
$duration  = round(microtime(true) - $start, 3);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    log_transcription([
        'filename'         => $filename,
        'file_size'        => $file_size,
        'task'             => $task,
        'language'         => $language ?: null,
        'duration_seconds' => $duration,
        'success'          => false,
        'error_message'    => 'cURL: ' . $curlError,
    ]);
    http_response_code(502);
    header('Content-Type: application/json');
    echo json_encode(['detail' => 'Erro de ligação ao Whisper: ' . $curlError]);
    exit;
}

http_response_code($httpCode);
header('Content-Type: application/json');

// output=json — Whisper devolve {"text": "..."} directamente
$decoded = json_decode($response, true);
$success = ($httpCode >= 200 && $httpCode < 300);

if ($decoded !== null) {
    log_transcription([
        'filename'         => $filename,
        'file_size'        => $file_size,
        'task'             => $task,
        'language'         => $language ?: null,
        'duration_seconds' => $duration,
        'success'          => $success,
        'error_message'    => $success ? null : ($decoded['detail'] ?? "HTTP $httpCode"),
        'text_length'      => isset($decoded['text']) ? mb_strlen($decoded['text']) : null,
    ]);
    echo $response;
} else {
    // fallback caso devolva texto puro
    $text = trim($response);
    log_transcription([
        'filename'         => $filename,
        'file_size'        => $file_size,
        'task'             => $task,
        'language'         => $language ?: null,
        'duration_seconds' => $duration,
        'success'          => $success,
        'error_message'    => $success ? null : "HTTP $httpCode",
        'text_length'      => $success ? mb_strlen($text) : null,
    ]);
    echo json_encode(['text' => $text]);
}
