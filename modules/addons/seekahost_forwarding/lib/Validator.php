<?php

namespace SeekaHost\Forwarding;

class Validator
{
    public static function fail($message)
    {
        throw new \InvalidArgumentException($message);
    }

    public static function path($path)
    {
        $path = trim((string) $path);
        if ($path === '' || $path === '/') {
            return '/';
        }
        $path = '/' . trim($path, '/');
        if (strlen($path) > 190 || !preg_match('#^(/[A-Za-z0-9\-._~%!$&\'()*+,;=:@]+)+$#', $path)) {
            self::fail('Source path contains invalid characters.');
        }
        return $path;
    }

    public static function url($url)
    {
        $url = trim((string) $url);
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'http://' . $url;
        }
        $parts = parse_url($url);
        if (!$parts || empty($parts['host']) || preg_match('/[\s\r\n"<>]/', $url) || strlen($url) > 2000
            || !filter_var($url, FILTER_VALIDATE_URL)) {
            self::fail('Please enter a valid destination URL (http:// or https://).');
        }
        return $url;
    }

    public static function localPart($local)
    {
        $local = strtolower(trim((string) $local));
        if (!preg_match('/^[a-z0-9](?:[a-z0-9._+\-]{0,62}[a-z0-9])?$/', $local) || strpos($local, '..') !== false) {
            self::fail('Invalid email alias. Use letters, numbers, dot, dash, underscore or plus.');
        }
        return $local;
    }

    /** @return string[] */
    public static function destinations($input, $max, $ownAddress = null)
    {
        $list = preg_split('/[\s,;]+/', strtolower(trim((string) $input)), -1, PREG_SPLIT_NO_EMPTY);
        $list = array_values(array_unique($list));
        if (!$list) {
            self::fail('Enter at least one destination email address.');
        }
        if ($max > 0 && count($list) > $max) {
            self::fail('A maximum of ' . $max . ' destination address(es) is allowed.');
        }
        foreach ($list as $email) {
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                self::fail('Invalid destination email address: ' . $email);
            }
            if ($ownAddress !== null && $email === strtolower($ownAddress)) {
                self::fail('An alias cannot forward to itself.');
            }
        }
        return $list;
    }

    public static function text($value, $max)
    {
        $value = trim(strip_tags((string) $value));
        return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
    }
}
