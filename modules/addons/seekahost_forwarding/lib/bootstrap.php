<?php

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

if (!defined('SEEKAHOST_FWD_VERSION')) {
    define('SEEKAHOST_FWD_VERSION', '1.2.0');
    define('SEEKAHOST_FWD_MODULE', 'seekahost_forwarding');

    spl_autoload_register(function ($class) {
        $prefix = 'SeekaHost\\Forwarding\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }
        $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    });
}
