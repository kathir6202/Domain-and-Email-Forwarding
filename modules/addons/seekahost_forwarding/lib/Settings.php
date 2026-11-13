<?php

namespace SeekaHost\Forwarding;

use WHMCS\Database\Capsule;

class Settings
{
    private static $cache;

    private static $defaults = [
        'access_mode' => 'addon',
        'hide_if_hosting' => 'on',
        'replace_native' => 'on',
        'url_limit' => '10',
        'email_limit' => '25',
        'max_destinations' => '5',
        'allow_302' => 'on',
        'allow_masked' => 'on',
        'allow_path' => 'on',
        'allow_wildcard' => 'on',
        'allow_catchall' => 'on',
        'auto_ssl' => 'on',
        'le_email' => '',
        'show_dns' => 'on',
        'notify_client' => 'on',
        'notify_admin_errors' => 'on',
        'delete_on_deactivate' => '',
        'allow_303' => 'on',
        'allow_domain_email' => 'on',
        'auto_forwarders' => 'on',
        'parking_html' => '',
        'api_enabled' => '',
        'migration_wait' => '10',
    ];

    public static function all()
    {
        if (self::$cache === null) {
            $rows = Capsule::table('tbladdonmodules')
                ->where('module', SEEKAHOST_FWD_MODULE)
                ->pluck('value', 'setting');
            $rows = is_array($rows) ? $rows : $rows->toArray();
            self::$cache = array_merge(self::$defaults, $rows);
        }
        return self::$cache;
    }

    public static function flush()
    {
        self::$cache = null;
    }

    public static function get($key)
    {
        $all = self::all();
        return isset($all[$key]) ? $all[$key] : null;
    }

    public static function on($key)
    {
        $v = self::get($key);
        return $v === 'on' || $v === '1' || $v === 1 || $v === true || $v === 'yes';
    }

    /** Text setting saved through the WHMCS config screen (may be HTML-encoded). */
    public static function text($key)
    {
        return Input::decode((string) self::get($key));
    }

    public static function int($key)
    {
        return max(0, (int) self::get($key));
    }
}
