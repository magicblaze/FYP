<?php
// Gmail Configuration
define('GMAIL_USERNAME', 'www.wongmankit30@gmail.com');
define('GMAIL_PASSWORD', 'Rayomnd876897100'); // 使用Gmail应用专用密码
define('GMAIL_FROM_NAME', 'Management Team');
define('GMAIL_FROM_EMAIL', 'www.wongmankit30@gmail.com');
define('GMAIL_REPLY_TO', 'www.wongmankit30@gmail.com');

// SMTP Configuration
define('SMTP_HOST', 'smtp.gmail.com');
define('SMTP_PORT', 587);
define('SMTP_ENCRYPTION', 'tls'); // tls or ssl

// 邮件发送函数（使用PHP内置mail()函数）
function sendEmailSimple($to, $subject, $message) {
    $headers = "From: " . GMAIL_FROM_NAME . " <" . GMAIL_FROM_EMAIL . ">\r\n";
    $headers .= "Reply-To: " . GMAIL_REPLY_TO . "\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion();
    
    return mail($to, $subject, $message, $headers);
}

// 发送HTML邮件
function sendHTMLEmail($to, $subject, $htmlContent, $textContent = '') {
    if(empty($textContent)) {
        $textContent = strip_tags($htmlContent);
    }
    
    $boundary = md5(time());
    
    $headers = "From: " . GMAIL_FROM_NAME . " <" . GMAIL_FROM_EMAIL . ">\r\n";
    $headers .= "Reply-To: " . GMAIL_REPLY_TO . "\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/alternative; boundary=\"" . $boundary . "\"\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion();
    
    $message = "--" . $boundary . "\r\n";
    $message .= "Content-Type: text/plain; charset=\"UTF-8\"\r\n";
    $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $message .= chunk_split(base64_encode($textContent)) . "\r\n";
    
    $message .= "--" . $boundary . "\r\n";
    $message .= "Content-Type: text/html; charset=\"UTF-8\"\r\n";
    $message .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $message .= chunk_split(base64_encode($htmlContent)) . "\r\n";
    
    $message .= "--" . $boundary . "--";
    
    return mail($to, $subject, $message, $headers);
}
?>