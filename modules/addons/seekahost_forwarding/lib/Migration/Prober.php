<?php

namespace SeekaHost\Forwarding\Migration;

/**
 * Plain-HTTP requests straight to the forwarding server's IP with a Host header,
 * so behaviour can be compared before/after a migration without relying on DNS.
 */
class Prober
{
    /** @var callable|null test hook: fn($ip, $host, $path) => [code, location, servedByModule] */
    public static $impl;

    /** @return array [int code (0 = no response), string location, bool served by this module] */
    public static function probe($ip, $host, $path)
    {
        if (self::$impl !== null) {
            return call_user_func(self::$impl, $ip, $host, $path) + [2 => false];
        }
        $location = '';
        $served = false;
        $ch = curl_init('http://' . $ip . $path);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ['Host: ' . $host, 'User-Agent: SeekaHost-Forwarding-Migration', 'X-Shfwd-Probe: 1'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_NOBODY => false,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$location, &$served) {
                if (stripos($line, 'Location:') === 0) {
                    $location = trim(substr($line, 9));
                } elseif (stripos($line, 'X-Shfwd-Served:') === 0) {
                    $served = true;
                }
                return strlen($line);
            },
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$code, $location, $served];
    }

    public static function isRedirect($code)
    {
        return in_array((int) $code, [301, 302, 303, 307, 308], true);
    }
}
