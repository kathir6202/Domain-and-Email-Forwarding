<?php

namespace SeekaHost\Forwarding\Panels;

class Ftp
{
    private static function connect($host, $port, $user, $pass, $ssl)
    {
        if (!function_exists('ftp_connect')) {
            throw new \RuntimeException('The PHP FTP extension is not installed on the WHMCS server.');
        }
        if ($ssl && !function_exists('ftp_ssl_connect')) {
            throw new \RuntimeException('PHP was built without FTP over TLS support (ftp_ssl_connect).');
        }
        $conn = $ssl ? @ftp_ssl_connect($host, (int) $port, 20) : @ftp_connect($host, (int) $port, 20);
        if (!$conn) {
            throw new \RuntimeException('FTP connection to ' . $host . ':' . $port . ' failed.');
        }
        if (!@ftp_login($conn, $user, $pass)) {
            @ftp_close($conn);
            throw new \RuntimeException('FTP login failed for user ' . $user . ($ssl ? ' (TLS)' : '') . '.');
        }
        if (defined('FTP_USEPASVADDRESS')) {
            // Servers behind NAT often announce a private address in PASV replies.
            @ftp_set_option($conn, FTP_USEPASVADDRESS, false);
        }
        ftp_pasv($conn, true);
        return $conn;
    }

    public static function test($host, $port, $user, $pass, $ssl)
    {
        $conn = self::connect($host, $port, $user, $pass, $ssl);
        $ok = @ftp_pwd($conn) !== false;
        @ftp_close($conn);
        if (!$ok) {
            throw new \RuntimeException('FTP login succeeded but the server did not respond to PWD.');
        }
    }

    public static function upload($host, $port, $user, $pass, $ssl, $dir, array $files, array $delete = [])
    {
        $conn = self::connect($host, $port, $user, $pass, $ssl);
        try {
            $path = '';
            foreach (array_filter(explode('/', trim($dir, '/')), 'strlen') as $segment) {
                $path .= ($path === '' ? '' : '/') . $segment;
                @ftp_mkdir($conn, $path); // fails harmlessly when it already exists
            }
            $base = trim($dir, '/');
            foreach ($delete as $name) {
                @ftp_delete($conn, $base . '/' . $name);
            }
            foreach ($files as $name => $content) {
                $stream = fopen('php://temp', 'r+');
                fwrite($stream, $content);
                rewind($stream);
                $ok = @ftp_fput($conn, $base . '/' . $name, $stream, FTP_BINARY);
                fclose($stream);
                if (!$ok) {
                    throw new \RuntimeException('FTP upload of ' . $name . ' to ' . $dir . ' failed.');
                }
            }
        } finally {
            @ftp_close($conn);
        }
    }
}
