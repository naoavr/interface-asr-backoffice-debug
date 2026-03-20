<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
// ============================================================
// CONFIGURAÇÃO SMTP
// ============================================================
define('SMTP_HOST',     'mail.atena.3rhost.pt');
define('SMTP_PORT',     465);
define('SMTP_USER',     'demo@atena.3rhost.pt');
define('SMTP_PASSWORD', 'iJ9kQ4oK5j');
define('SMTP_FROM',     'demo@atena.3rhost.pt');
define('EMAIL_SUBJECT', 'Resultado da Transcrição Whisper ASR');
// ============================================================

require_once __DIR__ . '/logger.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

// Ler JSON
$data    = json_decode(file_get_contents('php://input'), true);
$to      = isset($data['to']) ? trim($data['to']) : '';
$results = isset($data['results']) ? $data['results'] : [];
$html    = isset($data['html']) ? $data['html'] : '';

if (empty($to)) {
    http_response_code(400);
    echo json_encode(['error' => 'Email destinatário em falta']);
    exit();
}

// Corpo texto simples (fallback) – mantém estrutura antiga
$body = "Resultado(s) da transcrição Whisper ASR:\n\n";
foreach ($results as $r) {
    $name = isset($r['name']) ? $r['name'] : 'sem_nome';
    $text = isset($r['text']) ? $r['text'] : '';
    $body .= "=== " . $name . " ===\n" . $text . "\n\n";
}

// Se não vier HTML do front-end, gera um básico a partir de $body
if (empty($html)) {
    $htmlSafe = nl2br(htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    $html = '<pre style="font-family:system-ui,-apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif;font-size:13px;">'
          . $htmlSafe
          . '</pre>';
}

// ============================================================
// ENVIO COM PHPMailer (se disponível)
// ============================================================
$phpmailerPath = __DIR__ . '/phpmailer/PHPMailer.php';

if (file_exists($phpmailerPath)) {
    require $phpmailerPath;
    require __DIR__ . '/phpmailer/SMTP.php';
    require __DIR__ . '/phpmailer/Exception.php';

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS; // SSL porta 465
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom(SMTP_FROM);
        $mail->addAddress($to);
        $mail->Subject = EMAIL_SUBJECT;

        // Enviar como HTML + alternativa texto
        $mail->isHTML(true);
        $mail->Body    = $html;
        $mail->AltBody = $body;

        $mail->send();
        log_email([
            'recipient'   => $to,
            'files_count' => count($results),
            'method'      => 'PHPMailer',
            'success'     => true,
        ]);
        echo json_encode(['status' => 'ok']);
    } catch (Exception $e) {
        log_email([
            'recipient'     => $to,
            'files_count'   => count($results),
            'method'        => 'PHPMailer',
            'success'       => false,
            'error_message' => $mail->ErrorInfo,
        ]);
        http_response_code(500);
        echo json_encode(['error' => $mail->ErrorInfo]);
    }

// ============================================================
// FALLBACK mail() SE PHPMailer NÃO EXISTIR
// ============================================================
} else {
    $headers  = "From: " . SMTP_FROM . "\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";

    if (mail($to, EMAIL_SUBJECT, $html, $headers)) {
        log_email([
            'recipient'   => $to,
            'files_count' => count($results),
            'method'      => 'mail()',
            'success'     => true,
        ]);
        echo json_encode(['status' => 'ok']);
    } else {
        log_email([
            'recipient'     => $to,
            'files_count'   => count($results),
            'method'        => 'mail()',
            'success'       => false,
            'error_message' => 'Falha ao enviar email via mail()',
        ]);
        http_response_code(500);
        echo json_encode(['error' => 'Falha ao enviar email via mail()']);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

// Ler JSON
$data    = json_decode(file_get_contents('php://input'), true);
$to      = isset($data['to']) ? trim($data['to']) : '';
$results = isset($data['results']) ? $data['results'] : [];
$html    = isset($data['html']) ? $data['html'] : '';

if (empty($to)) {
    http_response_code(400);
    echo json_encode(['error' => 'Email destinatário em falta']);
    exit();
}

// Corpo texto simples (fallback) – mantém estrutura antiga
$body = "Resultado(s) da transcrição Whisper ASR:\n\n";
foreach ($results as $r) {
    $name = isset($r['name']) ? $r['name'] : 'sem_nome';
    $text = isset($r['text']) ? $r['text'] : '';
    $body .= "=== " . $name . " ===\n" . $text . "\n\n";
}

// Se não vier HTML do front-end, gera um básico a partir de $body
if (empty($html)) {
    $htmlSafe = nl2br(htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    $html = '<pre style="font-family:system-ui,-apple-system,BlinkMacSystemFont,\'Segoe UI\',sans-serif;font-size:13px;">'
          . $htmlSafe
          . '</pre>';
}

// ============================================================
// ENVIO COM PHPMailer (se disponível)
// ============================================================
$phpmailerPath = __DIR__ . '/phpmailer/PHPMailer.php';

if (file_exists($phpmailerPath)) {
    require $phpmailerPath;
    require __DIR__ . '/phpmailer/SMTP.php';
    require __DIR__ . '/phpmailer/Exception.php';

    $mail = new PHPMailer\PHPMailer\PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS; // SSL porta 465
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';

        $mail->setFrom(SMTP_FROM);
        $mail->addAddress($to);
        $mail->Subject = EMAIL_SUBJECT;

        // Enviar como HTML + alternativa texto
        $mail->isHTML(true);
        $mail->Body    = $html;
        $mail->AltBody = $body;

        $mail->send();
        echo json_encode(['status' => 'ok']);
    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['error' => $mail->ErrorInfo]);
    }

// ============================================================
// FALLBACK mail() SE PHPMailer NÃO EXISTIR
// ============================================================
} else {
    $headers  = "From: " . SMTP_FROM . "\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";

    if (mail($to, EMAIL_SUBJECT, $html, $headers)) {
        echo json_encode(['status' => 'ok']);
    } else {
        http_response_code(500);
        echo json_encode(['error' => 'Falha ao enviar email via mail()']);
    }
}
