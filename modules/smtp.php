<?php
if (!defined('ABSPATH')) exit;
// Send email via SMTP

add_action('phpmailer_init', function ($phpmailer) {
    $phpmailer->isSMTP(); // Set mailer to use SMTP
    $phpmailer->Host       = 'smtp.gmail.com'; // Specify main and backup SMTP servers
    $phpmailer->SMTPAuth   = true; // Enable SMTP authentication
    $phpmailer->Username   = 'webmaster@utm.my'; // SMTP username
    $phpmailer->Password   = 'tbwm ppgy bwau fjit'; // SMTP password
    $phpmailer->SMTPSecure = 'tls'; // Enable TLS encryption, `ssl` also accepted
    $phpmailer->Port       = 587; // TCP port to connect to
});