<?php

namespace SeekaHost\Forwarding;

use WHMCS\Database\Capsule;

/**
 * Per-TLD permissions. Global module settings are master switches; a TLD row
 * can only narrow them. TLDs without a row get the global behaviour.
 */
class Tld
{
    const AUTO_ACTIONS = ['none' => 'None', 'park' => 'Park only', 'forward' => 'Create URL forwarder'];

    private static $cache = [];

    public static function of($domain)
    {
        $domain = strtolower((string) $domain);
        $pos = strpos($domain, '.');
        return $pos === false ? '' : substr($domain, $pos);
    }

    /** TLDs sold in WHMCS (e.g. ".com"), sorted. */
    public static function sold()
    {
        return Capsule::table('tbldomainpricing')->orderBy('order')->orderBy('extension')->pluck('extension')->all();
    }

    public static function row($tld)
    {
        if (!array_key_exists($tld, self::$cache)) {
            self::$cache[$tld] = Capsule::table(Schema::T_TLDS)->where('tld', $tld)->first();
        }
        return self::$cache[$tld];
    }

    /**
     * @return array url, path, wildcard, email, domain_email, catchall, park_only, auto_action, auto_url, auto_type
     */
    public static function policy($domain)
    {
        $row = self::row(self::of($domain));
        $flag = function ($column) use ($row) {
            return $row ? (bool) $row->$column : true;
        };
        $parkOnly = $row ? (bool) $row->park_only : false;
        $url = !$parkOnly && $flag('allow_url');
        $email = !$parkOnly && $flag('allow_email');

        return [
            'url' => $url,
            'path' => $url && $flag('allow_path') && Settings::on('allow_path'),
            'wildcard' => $url && Settings::on('allow_wildcard'),
            'email' => $email,
            'domain_email' => !$parkOnly && $flag('allow_domain_email') && Settings::on('allow_domain_email'),
            'catchall' => !$parkOnly && $flag('allow_catchall') && Settings::on('allow_catchall'),
            'park_only' => $parkOnly,
            'auto_action' => $row ? $row->auto_action : 'none',
            'auto_url' => $row ? (string) $row->auto_url : '',
            'auto_type' => $row ? $row->auto_type : '301',
        ];
    }

    public static function save(array $rows)
    {
        $now = date('Y-m-d H:i:s');
        foreach ($rows as $tld => $in) {
            $tld = strtolower(trim($tld));
            if ($tld === '' || $tld[0] !== '.') {
                continue;
            }
            $action = isset($in['auto_action'], self::AUTO_ACTIONS[$in['auto_action']]) ? $in['auto_action'] : 'none';
            $autoUrl = trim(isset($in['auto_url']) ? (string) $in['auto_url'] : '');
            if ($action === 'forward') {
                try {
                    Validator::url(str_replace('{domain}', 'example.com', $autoUrl));
                } catch (\InvalidArgumentException $e) {
                    Validator::fail('Automatic forwarder URL for ' . $tld . ' is not a valid http(s) URL.');
                }
            }
            $data = [
                'allow_url' => !empty($in['allow_url']) ? 1 : 0,
                'allow_path' => !empty($in['allow_path']) ? 1 : 0,
                'allow_email' => !empty($in['allow_email']) ? 1 : 0,
                'allow_domain_email' => !empty($in['allow_domain_email']) ? 1 : 0,
                'allow_catchall' => !empty($in['allow_catchall']) ? 1 : 0,
                'park_only' => !empty($in['park_only']) ? 1 : 0,
                'auto_action' => $action,
                'auto_url' => $autoUrl,
                'auto_type' => isset($in['auto_type']) && in_array($in['auto_type'], ['301', '302', '303', 'masked'], true) ? $in['auto_type'] : '301',
                'updated_at' => $now,
            ];
            if (Capsule::table(Schema::T_TLDS)->where('tld', $tld)->exists()) {
                Capsule::table(Schema::T_TLDS)->where('tld', $tld)->update($data);
            } else {
                Capsule::table(Schema::T_TLDS)->insert(array_merge(['tld' => $tld], $data));
            }
        }
        self::$cache = [];
    }
}
