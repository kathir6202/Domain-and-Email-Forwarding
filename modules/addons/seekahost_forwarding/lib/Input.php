<?php

namespace SeekaHost\Forwarding;

/**
 * WHMCS HTML-encodes request input during bootstrap; decode it before
 * validating or storing (URLs with "&", passwords with special characters...).
 */
class Input
{
    public static function decode($value)
    {
        if (is_array($value)) {
            return array_map([__CLASS__, 'decode'], $value);
        }
        $value = (string) $value;
        if (class_exists('\WHMCS\Input\Sanitize') && method_exists('\WHMCS\Input\Sanitize', 'decode')) {
            return \WHMCS\Input\Sanitize::decode($value);
        }
        return html_entity_decode($value, ENT_QUOTES, 'UTF-8');
    }

    /** Decoded copy of $_POST (or another array). */
    public static function all(array $source = null)
    {
        return self::decode($source === null ? $_POST : $source);
    }

    public static function get($key, $default = '', array $source = null)
    {
        $source = $source === null ? $_REQUEST : $source;
        return isset($source[$key]) ? self::decode($source[$key]) : $default;
    }
}
