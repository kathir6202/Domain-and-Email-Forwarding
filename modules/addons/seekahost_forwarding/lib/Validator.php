<?php

namespace SeekaHost\Forwarding;

class Validator
{
    public static function fail($message)
    {
        throw new \InvalidArgumentException($message);
    }

    /** IDN -> ASCII (punycode) when the intl extension is available. */
    public static function toAscii($host)
    {
        $host = strtolower(trim((string) $host));
        if ($host !== '' && preg_match('/[^\x20-\x7e]/', $host) && function_exists('idn_to_ascii')) {
            $ascii = defined('INTL_IDNA_VARIANT_UTS46')
                ? idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46)
                : idn_to_ascii($host);
            if ($ascii !== false) {
                return $ascii;
            }
        }
        return $host;
    }

    /** ASCII (punycode) -> Unicode for display. */
    public static function toUnicode($host)
    {
        $host = (string) $host;
        if (strpos($host, 'xn--') !== false && function_exists('idn_to_utf8')) {
            $utf8 = defined('INTL_IDNA_VARIANT_UTS46')
                ? idn_to_utf8($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46)
                : idn_to_utf8($host);
            if ($utf8 !== false) {
                return $utf8;
            }
        }
        return $host;
    }

    /** Domain name as stored for panel calls: lowercase ASCII, strictly validated. */
    public static function domain($domain)
    {
        $domain = rtrim(self::toAscii($domain), '.');
        if (strlen($domain) > 253
            || !preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$/', $domain)) {
            self::fail('Invalid domain name: ' . $domain);
        }
        return $domain;
    }

    public static function path($path)
    {
        $path = trim((string) $path);
        if ($path === '' || $path === '/') {
            return '/';
        }
        $path = '/' . trim($path, '/');
        if (strlen($path) > 190 || !preg_match('#^(/[A-Za-z0-9\-._~%+@]+)+$#', $path)
            || preg_match('#/\.\.?(/|$)#', $path) || preg_match('/%(?![0-9A-Fa-f]{2})/', $path)) {
            self::fail('Source path may only contain letters, numbers and - . _ ~ % + @ separated by "/".');
        }
        return $path;
    }

    public static function url($url)
    {
        $url = trim((string) $url);
        if (preg_match('#^([a-z][a-z0-9+.\-]*):#i', $url, $m) && !preg_match('#^https?://#i', $url)) {
            if (!preg_match('/^[^:\/]+:\d+/', $url)) {
                self::fail('Only http:// and https:// destinations are supported.');
            }
        }
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'http://' . $url;
        }
        if (preg_match('/[\x00-\x20"<>\\\\]/', $url) || strlen($url) > 2000) {
            self::fail('Please enter a valid destination URL (http:// or https://).');
        }
        $parts = parse_url($url);
        if (!$parts || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            self::fail('Please enter a valid destination URL (http:// or https://).');
        }
        $asciiHost = self::toAscii($parts['host']);
        if ($asciiHost !== strtolower($parts['host'])) {
            $url = preg_replace('#^(https?://)' . preg_quote($parts['host'], '#') . '#i', '$1' . $asciiHost, $url);
        }
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
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

    public static function email($email)
    {
        $email = strtolower(trim((string) $email));
        $at = strrpos($email, '@');
        if ($at === false) {
            self::fail('Invalid email address: ' . $email);
        }
        $email = substr($email, 0, $at) . '@' . self::toAscii(substr($email, $at + 1));
        if (!preg_match('/^[a-z0-9!#$%&\'*+\/=?^_`{|}~.\-]{1,64}@([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z0-9-]{2,63}$/', $email)
            || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            self::fail('Invalid email address: ' . $email);
        }
        return $email;
    }

    /** @return string[] */
    public static function destinations($input, $max, $ownAddress = null)
    {
        $list = preg_split('/[\s,;]+/', trim((string) $input), -1, PREG_SPLIT_NO_EMPTY);
        $list = array_values(array_unique(array_map([__CLASS__, 'email'], $list)));
        if (!$list) {
            self::fail('Enter at least one destination email address.');
        }
        if ($max > 0 && count($list) > $max) {
            self::fail('A maximum of ' . $max . ' destination address(es) is allowed.');
        }
        if ($ownAddress !== null && in_array(strtolower($ownAddress), $list, true)) {
            self::fail('An alias cannot forward to itself.');
        }
        return $list;
    }

    public static function text($value, $max)
    {
        $value = trim(strip_tags((string) $value));
        return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
    }
}
