<?php

namespace SeekaHost\Forwarding\Panels;

class Ftp
{
    public static function upload($host, $port, $user, $pass, $ssl, $dir, array $files)
    {
        if (!function_exists('ftp_connect')) {
            throw new \RuntimeException('The PHP FTP extension is not installed on the WHMCS server.');
        }
        $conn = $ssl ? @ftp_ssl_connect($host, (int) $port, 20) : @ftp_connect($host, (int) $port, 20);
        if (!$conn) {
            throw new \RuntimeException('FTP connection to ' . $host . ':' . $port . ' failed.');
        }
        try {
            if (!@ftp_login($conn, $user, $pass)) {
                throw new \RuntimeException('FTP login failed for user ' . $user . '.');
            }
            ftp_pasv($conn, true);

            $path = '';
            foreach (array_filter(explode('/', trim($dir, '/')), 'strlen') as $segment) {
                $path .= ($path === '' ? '' : '/') . $segment;
                @ftp_mkdir($conn, $path); // fails harmlessly when it already exists
            }

            foreach ($files as $name => $content) {
                $stream = fopen('php://temp', 'r+');
                fwrite($stream, $content);
                rewind($stream);
                $ok = @ftp_fput($conn, trim($dir, '/') . '/' . $name, $stream, FTP_BINARY);
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
