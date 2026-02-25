<?php

class Auth {
    public static function requireRole($roles) {
        session_start();
        if (!in_array($_SESSION['role'] ?? '', (array)$roles)) {
            header('HTTP/1.1 403 Forbidden');
            die('Access denied');
        }
    }

    public static function checkCsrf($token) {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }

    public static function generateCsrf() {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }
}
