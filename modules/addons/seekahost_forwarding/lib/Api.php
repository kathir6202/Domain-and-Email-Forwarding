<?php

namespace SeekaHost\Forwarding;

use WHMCS\Database\Capsule;

/**
 * Token-authenticated JSON API (modules/addons/seekahost_forwarding/api.php).
 * Tokens are stored as SHA-256 hashes and can be restricted to IP addresses/CIDR ranges.
 */
class Api
{
    /* ---------------------------------------------------------------- tokens */

    /** @return array [plainToken, id] */
    public static function createToken($name, $ips)
    {
        $name = Validator::text($name, 100);
        if ($name === '') {
            Validator::fail('Token name is required.');
        }
        $token = 'shfwd_' . bin2hex(random_bytes(24));
        $id = Capsule::table(Schema::T_TOKENS)->insertGetId([
            'name' => $name,
            'token_hash' => hash('sha256', $token),
            'token_hint' => substr($token, 0, 12),
            'allowed_ips' => self::normaliseIps($ips),
            'active' => 1,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        Logger::log('api_token', 'API token "' . $name . '" created');
        return [$token, $id];
    }

    public static function updateToken($id, $ips, $active)
    {
        Capsule::table(Schema::T_TOKENS)->where('id', (int) $id)->update([
            'allowed_ips' => self::normaliseIps($ips),
            'active' => $active ? 1 : 0,
        ]);
        Logger::log('api_token', 'API token #' . (int) $id . ' updated');
    }

    public static function deleteToken($id)
    {
        Capsule::table(Schema::T_TOKENS)->where('id', (int) $id)->delete();
        Logger::log('api_token', 'API token #' . (int) $id . ' revoked');
    }

    private static function normaliseIps($ips)
    {
        $out = [];
        foreach (preg_split('/[\s,;]+/', (string) $ips, -1, PREG_SPLIT_NO_EMPTY) as $ip) {
            $base = explode('/', $ip)[0];
            if (!filter_var($base, FILTER_VALIDATE_IP)) {
                Validator::fail('Invalid IP address or range: ' . $ip);
            }
            $out[] = $ip;
        }
        return implode(', ', $out);
    }

    public static function ipAllowed($ip, $allowed)
    {
        $allowed = trim((string) $allowed);
        if ($allowed === '') {
            return true;
        }
        foreach (preg_split('/[\s,]+/', $allowed, -1, PREG_SPLIT_NO_EMPTY) as $rule) {
            if (strpos($rule, '/') === false) {
                if ($rule === $ip) {
                    return true;
                }
                continue;
            }
            list($subnet, $bits) = explode('/', $rule, 2);
            $ipBin = @inet_pton($ip);
            $subBin = @inet_pton($subnet);
            if ($ipBin === false || $subBin === false || strlen($ipBin) !== strlen($subBin)) {
                continue;
            }
            $bits = (int) $bits;
            $bytes = intdiv($bits, 8);
            $rem = $bits % 8;
            if (substr($ipBin, 0, $bytes) !== substr($subBin, 0, $bytes)) {
                continue;
            }
            if ($rem === 0) {
                return true;
            }
            $mask = chr((0xff << (8 - $rem)) & 0xff);
            if ((ord($ipBin[$bytes]) & ord($mask)) === (ord($subBin[$bytes]) & ord($mask))) {
                return true;
            }
        }
        return false;
    }

    /** @return object token row */
    public static function authenticate($token, $ip)
    {
        if (!Settings::on('api_enabled')) {
            throw new ApiException('The API is disabled.', 403);
        }
        $token = trim((string) $token);
        if ($token === '') {
            throw new ApiException('Missing API token.', 401);
        }
        $row = Capsule::table(Schema::T_TOKENS)->where('token_hash', hash('sha256', $token))->where('active', 1)->first();
        if (!$row) {
            Logger::log('api_auth', 'Rejected API request with an invalid token from ' . $ip, 'warning', null, null, 'api');
            throw new ApiException('Invalid API token.', 401);
        }
        if (!self::ipAllowed($ip, $row->allowed_ips)) {
            Logger::log('api_auth', 'API token "' . $row->name . '" used from disallowed IP ' . $ip, 'warning', null, null, 'api');
            throw new ApiException('IP address not allowed.', 403);
        }
        Capsule::table(Schema::T_TOKENS)->where('id', $row->id)->update(['last_used_at' => date('Y-m-d H:i:s'), 'last_ip' => $ip]);
        return $row;
    }

    /* --------------------------------------------------------------- actions */

    private static function fwdFrom(array $p)
    {
        $q = Capsule::table('tbldomains');
        if (!empty($p['domain_id'])) {
            $q->where('id', (int) $p['domain_id']);
        } elseif (!empty($p['domain'])) {
            $q->where('domain', Validator::toAscii($p['domain']));
        } else {
            throw new ApiException('domain_id or domain is required.', 400);
        }
        if (!empty($p['client_id'])) {
            $q->where('userid', (int) $p['client_id']);
        }
        $row = $q->orderBy('id', 'desc')->first();
        if (!$row) {
            throw new ApiException('Domain not found.', 404);
        }
        list($ok, $reason) = Service::eligibility($row);
        if (!$ok) {
            throw new ApiException($reason, 409);
        }
        return Service::fwdDomain($row);
    }

    private static function urlRow($r)
    {
        return [
            'id' => (int) $r->id,
            'source_path' => $r->source_path,
            'destination' => $r->destination,
            'type' => $r->type,
            'www_mode' => $r->www_mode,
            'wildcard' => (bool) $r->wildcard,
            'preserve_query' => (bool) $r->preserve_query,
            'title' => $r->title,
            'meta_description' => $r->meta_description,
            'meta_keywords' => $r->meta_keywords,
        ];
    }

    public static function handle($action, array $p)
    {
        switch ($action) {
            case 'domains':
                if (empty($p['client_id'])) {
                    throw new ApiException('client_id is required.', 400);
                }
                $out = [];
                foreach (Capsule::table('tbldomains')->where('userid', (int) $p['client_id'])->orderBy('domain')->get()->all() as $row) {
                    list($ok, $reason) = Service::access($row);
                    $fwd = Capsule::table(Schema::T_DOMAINS)->where('domain_id', $row->id)->first();
                    $out[] = [
                        'domain_id' => (int) $row->id,
                        'domain' => $row->domain,
                        'eligible' => $ok,
                        'reason' => $reason,
                        'status' => $fwd ? $fwd->status : null,
                        'parked' => $fwd ? (bool) $fwd->parked : false,
                        'url_forwarders' => $fwd ? Capsule::table(Schema::T_URL)->where('fwd_domain_id', $fwd->id)->count() : 0,
                        'email_forwarders' => $fwd ? Capsule::table(Schema::T_EMAIL)->where('fwd_domain_id', $fwd->id)->count() : 0,
                        'catchall' => $fwd ? $fwd->catchall : null,
                        'limits' => $fwd ? Service::limits($fwd) : ['url' => Settings::int('url_limit'), 'email' => Settings::int('email_limit')],
                    ];
                }
                return ['domains' => $out];

            case 'redirects':
                $fwd = self::fwdFrom($p);
                return ['domain' => $fwd->domain, 'redirects' => array_map([__CLASS__, 'urlRow'], Service::urls($fwd))];

            case 'redirect_create':
            case 'redirect_update':
                $fwd = self::fwdFrom($p);
                $p += ['preserve_query' => 1];
                Service::saveUrl($fwd, $p, $action === 'redirect_update' ? (int) (isset($p['id']) ? $p['id'] : 0) ?: -1 : null);
                return ['redirects' => array_map([__CLASS__, 'urlRow'], Service::urls($fwd))];

            case 'redirect_delete':
                $fwd = self::fwdFrom($p);
                Service::deleteUrl($fwd, isset($p['id']) ? (int) $p['id'] : 0);
                return ['deleted' => true];

            case 'email_forwarders':
                $fwd = self::fwdFrom($p);
                $out = [];
                foreach (Service::emails($fwd) as $e) {
                    $out[] = ['id' => (int) $e->id, 'address' => $e->local_part . '@' . $fwd->domain, 'destinations' => Service::splitDestinations($e->destinations)];
                }
                return ['domain' => $fwd->domain, 'email_forwarders' => $out, 'catchall' => $fwd->catchall, 'domain_forward' => $fwd->domain_forward];

            case 'email_forwarder_create':
            case 'email_forwarder_update':
                $fwd = self::fwdFrom($p);
                if (isset($p['destinations']) && is_array($p['destinations'])) {
                    $p['destinations'] = implode(',', $p['destinations']);
                }
                Service::saveEmail($fwd, $p, $action === 'email_forwarder_update' ? (int) (isset($p['id']) ? $p['id'] : 0) ?: -1 : null);
                return self::handle('email_forwarders', $p);

            case 'email_forwarder_delete':
                $fwd = self::fwdFrom($p);
                Service::deleteEmail($fwd, isset($p['id']) ? (int) $p['id'] : 0);
                return ['deleted' => true];

            case 'catchall_set':
                $fwd = self::fwdFrom($p);
                Service::setCatchAll($fwd, isset($p['destination']) ? $p['destination'] : '');
                return ['catchall' => Service::reload($fwd)->catchall];
        }
        throw new ApiException('Unknown action.', 400);
    }
}
