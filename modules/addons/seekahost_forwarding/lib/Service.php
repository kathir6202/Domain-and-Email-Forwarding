<?php

namespace SeekaHost\Forwarding;

use SeekaHost\Forwarding\Panels\PanelFactory;
use WHMCS\Database\Capsule;

/**
 * Business logic shared by the client area, admin area and cron.
 * Validation problems throw \InvalidArgumentException (safe to show to clients);
 * server problems throw \RuntimeException (logged, admins notified).
 */
class Service
{
    const URL_TYPES = ['301' => 'Permanent (301)', '302' => 'Temporary (302)', 'masked' => 'Masked (Frame)'];

    /* ---------------------------------------------------------------- lookup */

    public static function domainRow($domainId)
    {
        return Capsule::table('tbldomains')->where('id', (int) $domainId)->first();
    }

    /** @return array [bool eligible, string reason] */
    public static function eligibility($domainRow)
    {
        if (!$domainRow) {
            return [false, 'Domain not found.'];
        }
        if ($domainRow->status !== 'Active') {
            return [false, 'Forwarding is available for active domains only.'];
        }
        if (Settings::get('access_mode') !== 'all' && empty($domainRow->emailforwarding)) {
            return [false, 'Domain & Email Forwarding is not enabled for this domain. You can add it from the domain addons page.'];
        }
        return [true, ''];
    }

    public static function hasHosting($domainRow)
    {
        return Capsule::table('tblhosting')
            ->where('userid', $domainRow->userid)
            ->where('domain', $domainRow->domain)
            ->whereIn('domainstatus', ['Active', 'Suspended'])
            ->exists();
    }

    /** Whether the client should see/use forwarding for this domain. */
    public static function visibleToClient($domainRow)
    {
        list($ok) = self::eligibility($domainRow);
        if (!$ok) {
            return false;
        }
        return !(Settings::on('hide_if_hosting') && self::hasHosting($domainRow));
    }

    public static function fwdDomain($domainRow, $create = true)
    {
        $fwd = Capsule::table(Schema::T_DOMAINS)->where('domain_id', $domainRow->id)->first();
        if (!$fwd && $create) {
            $now = date('Y-m-d H:i:s');
            Capsule::table(Schema::T_DOMAINS)->insert([
                'domain_id' => $domainRow->id,
                'client_id' => $domainRow->userid,
                'domain' => strtolower($domainRow->domain),
                'status' => 'pending',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $fwd = Capsule::table(Schema::T_DOMAINS)->where('domain_id', $domainRow->id)->first();
        }
        return $fwd;
    }

    public static function reload($fwd)
    {
        return Capsule::table(Schema::T_DOMAINS)->where('id', $fwd->id)->first();
    }

    public static function urls($fwd)
    {
        return Capsule::table(Schema::T_URL)->where('fwd_domain_id', $fwd->id)->orderBy('source_path')->get()->all();
    }

    public static function emails($fwd)
    {
        return Capsule::table(Schema::T_EMAIL)->where('fwd_domain_id', $fwd->id)->orderBy('local_part')->get()->all();
    }

    public static function limits($fwd)
    {
        return [
            'url' => $fwd->url_limit !== null ? (int) $fwd->url_limit : Settings::int('url_limit'),
            'email' => $fwd->email_limit !== null ? (int) $fwd->email_limit : Settings::int('email_limit'),
        ];
    }

    public static function server($fwd)
    {
        $q = Capsule::table(Schema::T_SERVERS)->where('active', 1);
        if ($fwd && $fwd->server_id) {
            $server = Capsule::table(Schema::T_SERVERS)->where('id', $fwd->server_id)->first();
            if ($server) {
                return $server;
            }
        }
        $server = (clone $q)->where('is_default', 1)->first();
        return $server ?: $q->orderBy('id')->first();
    }

    private static function panel($fwd)
    {
        $server = self::server($fwd);
        if (!$server) {
            throw new \RuntimeException('No forwarding server is configured.');
        }
        return [$server, PanelFactory::make($server)];
    }

    private static function ref($fwd)
    {
        return $fwd->remote_ref ? (json_decode($fwd->remote_ref, true) ?: []) : [];
    }

    private static function touch($fwd, array $data)
    {
        $data['updated_at'] = date('Y-m-d H:i:s');
        Capsule::table(Schema::T_DOMAINS)->where('id', $fwd->id)->update($data);
    }

    /** Run a remote call, recording failures. */
    private static function remote($fwd, $action, callable $fn)
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            self::touch($fwd, ['last_error' => substr($e->getMessage(), 0, 2000)]);
            Logger::error($action, $fwd->domain . ': ' . $e->getMessage(), $fwd->client_id, $fwd->domain_id);
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }
    }

    /* ------------------------------------------------------------ provisioning */

    public static function ensureProvisioned($fwd)
    {
        if ($fwd->status === 'active') {
            return $fwd;
        }
        list($server, $panel) = self::panel($fwd);
        $ref = self::remote($fwd, 'provision', function () use ($panel, $fwd) {
            return $panel->addDomain($fwd->domain);
        });
        self::touch($fwd, [
            'server_id' => $server->id,
            'remote_ref' => json_encode($ref),
            'status' => 'active',
            'last_error' => null,
        ]);
        $fwd = self::reload($fwd);
        Logger::log('provision', $fwd->domain . ' added to server ' . $server->name, 'info', $fwd->client_id, $fwd->domain_id);

        self::deployWeb($fwd);
        if (Settings::on('auto_ssl')) {
            try {
                self::requestSsl($fwd);
            } catch (\Throwable $e) {
                // DNS is often not pointed yet; the daily cron retries.
            }
        }
        return self::reload($fwd);
    }

    public static function deployWeb($fwd)
    {
        list(, $panel) = self::panel($fwd);
        $files = Generator::build($fwd->domain, self::urls($fwd));
        self::remote($fwd, 'deploy_web', function () use ($panel, $fwd, $files) {
            $panel->deployFiles($fwd->domain, self::ref($fwd), $files);
        });
        self::touch($fwd, ['last_sync' => date('Y-m-d H:i:s'), 'last_error' => null]);
    }

    /** Push every stored rule to the server (repair / re-activation). */
    public static function sync($fwd)
    {
        if ($fwd->status !== 'active') {
            self::touch($fwd, ['status' => 'pending']);
            $fwd = self::ensureProvisioned(self::reload($fwd));
        } else {
            self::deployWeb($fwd);
        }
        list(, $panel) = self::panel($fwd);
        $ref = self::ref($fwd);
        foreach (self::emails($fwd) as $row) {
            $dests = self::splitDestinations($row->destinations);
            self::remote($fwd, 'sync_email', function () use ($panel, $fwd, $ref, $row, $dests) {
                $panel->syncEmailForwarder($fwd->domain, $ref, $row->local_part, [], $dests);
            });
        }
        if ($fwd->catchall) {
            self::remote($fwd, 'sync_catchall', function () use ($panel, $fwd, $ref) {
                $panel->setCatchAll($fwd->domain, $ref, $fwd->catchall);
            });
        }
        Logger::log('sync', $fwd->domain . ' fully synchronised', 'info', $fwd->client_id, $fwd->domain_id);
        return self::reload($fwd);
    }

    /** Remove the domain from the server but keep its rules (restored by sync()). */
    public static function suspend($fwd, $reason)
    {
        if ($fwd->status === 'active' || $fwd->status === 'error') {
            list(, $panel) = self::panel($fwd);
            $ref = self::ref($fwd);
            foreach (self::emails($fwd) as $row) {
                try {
                    $panel->syncEmailForwarder($fwd->domain, $ref, $row->local_part, self::splitDestinations($row->destinations), []);
                } catch (\Throwable $e) {
                    // Removing the domain below cleans up anyway.
                }
            }
            self::remote($fwd, 'suspend', function () use ($panel, $fwd, $ref) {
                $panel->removeDomain($fwd->domain, $ref);
            });
        }
        self::touch($fwd, ['status' => 'suspended', 'remote_ref' => null, 'ssl_status' => null]);
        Logger::log('suspend', $fwd->domain . ' removed from server: ' . $reason, 'info', $fwd->client_id, $fwd->domain_id);
    }

    /** Remove from server and delete all module data for the domain. */
    public static function purge($fwd)
    {
        try {
            self::suspend($fwd, 'purge');
        } catch (\Throwable $e) {
            // Still purge local data.
        }
        Capsule::table(Schema::T_URL)->where('fwd_domain_id', $fwd->id)->delete();
        Capsule::table(Schema::T_EMAIL)->where('fwd_domain_id', $fwd->id)->delete();
        Capsule::table(Schema::T_DOMAINS)->where('id', $fwd->id)->delete();
        Logger::log('purge', $fwd->domain . ' forwarding data deleted', 'info', $fwd->client_id, $fwd->domain_id);
    }

    public static function requestSsl($fwd)
    {
        list(, $panel) = self::panel($fwd);
        try {
            $status = self::remote($fwd, 'ssl', function () use ($panel, $fwd) {
                return $panel->requestSsl($fwd->domain, self::ref($fwd));
            });
        } catch (\RuntimeException $e) {
            self::touch($fwd, ['ssl_status' => 'failed']);
            throw $e;
        }
        self::touch($fwd, ['ssl_status' => $status ?: 'requested']);
        Logger::log('ssl', 'SSL ' . ($status ?: 'requested') . ' for ' . $fwd->domain, 'info', $fwd->client_id, $fwd->domain_id);
    }

    /* ------------------------------------------------------------- URL rules */

    public static function saveUrl($fwd, array $in, $id = null)
    {
        $type = isset($in['type']) ? (string) $in['type'] : '301';
        if (!isset(self::URL_TYPES[$type])
            || ($type === '302' && !Settings::on('allow_302'))
            || ($type === 'masked' && !Settings::on('allow_masked'))) {
            Validator::fail('This forwarding type is not available.');
        }
        $path = Validator::path(isset($in['source_path']) ? $in['source_path'] : '/');
        if ($path !== '/' && !Settings::on('allow_path')) {
            Validator::fail('Path forwarding is not available; use "/" as the source.');
        }
        $wildcard = !empty($in['wildcard']);
        if ($wildcard && !Settings::on('allow_wildcard')) {
            Validator::fail('Wildcard forwarding is not available.');
        }
        $dest = Validator::url(isset($in['destination']) ? $in['destination'] : '');
        $destHost = strtolower((string) parse_url($dest, PHP_URL_HOST));
        if ($destHost === $fwd->domain || $destHost === 'www.' . $fwd->domain) {
            Validator::fail('The destination cannot be the same domain (redirect loop).');
        }

        $dup = Capsule::table(Schema::T_URL)->where('fwd_domain_id', $fwd->id)->where('source_path', $path);
        if ($id) {
            $dup->where('id', '!=', (int) $id);
        }
        if ($dup->exists()) {
            Validator::fail('A forwarder for ' . $path . ' already exists.');
        }

        $row = [
            'source_path' => $path,
            'destination' => $dest,
            'type' => $type,
            'wildcard' => $wildcard ? 1 : 0,
            'preserve_query' => !empty($in['preserve_query']) ? 1 : 0,
            'title' => $type === 'masked' ? Validator::text(isset($in['title']) ? $in['title'] : '', 255) : null,
            'meta_description' => $type === 'masked' ? Validator::text(isset($in['meta_description']) ? $in['meta_description'] : '', 1000) : null,
            'meta_keywords' => $type === 'masked' ? Validator::text(isset($in['meta_keywords']) ? $in['meta_keywords'] : '', 1000) : null,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $fwd = self::ensureProvisioned($fwd);

        Capsule::connection()->transaction(function () use ($fwd, $row, $id) {
            if ($id) {
                $updated = Capsule::table(Schema::T_URL)->where('fwd_domain_id', $fwd->id)->where('id', (int) $id)->update($row);
                if (!$updated && !Capsule::table(Schema::T_URL)->where('fwd_domain_id', $fwd->id)->where('id', (int) $id)->exists()) {
                    Validator::fail('Forwarder not found.');
                }
            } else {
                $limit = self::limits($fwd)['url'];
                if ($limit > 0 && Capsule::table(Schema::T_URL)->where('fwd_domain_id', $fwd->id)->count() >= $limit) {
                    Validator::fail('You have reached the limit of ' . $limit . ' URL forwarder(s) for this domain.');
                }
                $row['fwd_domain_id'] = $fwd->id;
                $row['created_at'] = $row['updated_at'];
                Capsule::table(Schema::T_URL)->insert($row);
            }
            self::deployWeb($fwd);
        });

        $summary = $fwd->domain . $path . ' -> ' . $dest . ' (' . self::URL_TYPES[$type] . ($wildcard ? ', wildcard' : '') . ')';
        Logger::log($id ? 'url_update' : 'url_add', $summary, 'info', $fwd->client_id, $fwd->domain_id);
        Notifier::client($fwd->domain_id, $id ? 'URL forwarder updated' : 'URL forwarder added', $summary);
    }

    public static function deleteUrl($fwd, $id)
    {
        $row = Capsule::table(Schema::T_URL)->where('fwd_domain_id', $fwd->id)->where('id', (int) $id)->first();
        if (!$row) {
            Validator::fail('Forwarder not found.');
        }
        Capsule::connection()->transaction(function () use ($fwd, $row) {
            Capsule::table(Schema::T_URL)->where('id', $row->id)->delete();
            if ($fwd->status === 'active') {
                self::deployWeb($fwd);
            }
        });
        $summary = $fwd->domain . $row->source_path . ' -> ' . $row->destination;
        Logger::log('url_delete', $summary, 'info', $fwd->client_id, $fwd->domain_id);
        Notifier::client($fwd->domain_id, 'URL forwarder removed', $summary);
    }

    /* ----------------------------------------------------------- email rules */

    public static function splitDestinations($value)
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $value))));
    }

    public static function saveEmail($fwd, array $in, $id = null)
    {
        $existing = null;
        if ($id) {
            $existing = Capsule::table(Schema::T_EMAIL)->where('fwd_domain_id', $fwd->id)->where('id', (int) $id)->first();
            if (!$existing) {
                Validator::fail('Forwarder not found.');
            }
            $local = $existing->local_part;
        } else {
            $local = Validator::localPart(isset($in['local_part']) ? $in['local_part'] : '');
            if (Capsule::table(Schema::T_EMAIL)->where('fwd_domain_id', $fwd->id)->where('local_part', $local)->exists()) {
                Validator::fail($local . '@' . $fwd->domain . ' already exists.');
            }
            $limit = self::limits($fwd)['email'];
            if ($limit > 0 && Capsule::table(Schema::T_EMAIL)->where('fwd_domain_id', $fwd->id)->count() >= $limit) {
                Validator::fail('You have reached the limit of ' . $limit . ' email forwarder(s) for this domain.');
            }
        }
        $address = $local . '@' . $fwd->domain;
        $new = Validator::destinations(isset($in['destinations']) ? $in['destinations'] : '', Settings::int('max_destinations'), $address);
        $old = $existing ? self::splitDestinations($existing->destinations) : [];

        $fwd = self::ensureProvisioned($fwd);
        list(, $panel) = self::panel($fwd);
        $ref = self::ref($fwd);
        self::remote($fwd, 'email_save', function () use ($panel, $fwd, $ref, $local, $old, $new) {
            $panel->syncEmailForwarder($fwd->domain, $ref, $local, $old, $new);
        });

        $now = date('Y-m-d H:i:s');
        if ($existing) {
            Capsule::table(Schema::T_EMAIL)->where('id', $existing->id)->update(['destinations' => implode(',', $new), 'updated_at' => $now]);
        } else {
            Capsule::table(Schema::T_EMAIL)->insert([
                'fwd_domain_id' => $fwd->id,
                'local_part' => $local,
                'destinations' => implode(',', $new),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        $summary = $address . ' -> ' . implode(', ', $new);
        Logger::log($existing ? 'email_update' : 'email_add', $summary, 'info', $fwd->client_id, $fwd->domain_id);
        Notifier::client($fwd->domain_id, $existing ? 'Email forwarder updated' : 'Email forwarder added', $summary);
    }

    public static function deleteEmail($fwd, $id)
    {
        $row = Capsule::table(Schema::T_EMAIL)->where('fwd_domain_id', $fwd->id)->where('id', (int) $id)->first();
        if (!$row) {
            Validator::fail('Forwarder not found.');
        }
        if ($fwd->status === 'active') {
            list(, $panel) = self::panel($fwd);
            $ref = self::ref($fwd);
            self::remote($fwd, 'email_delete', function () use ($panel, $fwd, $ref, $row) {
                $panel->syncEmailForwarder($fwd->domain, $ref, $row->local_part, self::splitDestinations($row->destinations), []);
            });
        }
        Capsule::table(Schema::T_EMAIL)->where('id', $row->id)->delete();
        $summary = $row->local_part . '@' . $fwd->domain;
        Logger::log('email_delete', $summary, 'info', $fwd->client_id, $fwd->domain_id);
        Notifier::client($fwd->domain_id, 'Email forwarder removed', $summary);
    }

    public static function setCatchAll($fwd, $destination)
    {
        if (!Settings::on('allow_catchall')) {
            Validator::fail('Catch-all forwarding is not available.');
        }
        $dest = null;
        if (trim((string) $destination) !== '') {
            $dest = Validator::destinations($destination, 1)[0];
            if (substr($dest, -strlen('@' . $fwd->domain)) === '@' . $fwd->domain) {
                Validator::fail('The catch-all destination must be outside ' . $fwd->domain . '.');
            }
        }
        $fwd = self::ensureProvisioned($fwd);
        list(, $panel) = self::panel($fwd);
        $ref = self::ref($fwd);
        self::remote($fwd, 'catchall', function () use ($panel, $fwd, $ref, $dest) {
            $panel->setCatchAll($fwd->domain, $ref, $dest);
        });
        self::touch($fwd, ['catchall' => $dest]);
        $summary = '*@' . $fwd->domain . ' -> ' . ($dest ?: 'disabled (bounce)');
        Logger::log('catchall', $summary, 'info', $fwd->client_id, $fwd->domain_id);
        Notifier::client($fwd->domain_id, 'Catch-all updated', $summary);
    }

    /* ------------------------------------------------------------------ cron */

    public static function cron()
    {
        foreach (Capsule::table(Schema::T_DOMAINS)->get()->all() as $fwd) {
            try {
                $domainRow = self::domainRow($fwd->domain_id);
                if (!$domainRow) {
                    self::purge($fwd);
                    continue;
                }
                list($eligible, $reason) = self::eligibility($domainRow);
                if (!$eligible && in_array($fwd->status, ['active', 'error'], true)) {
                    self::suspend($fwd, $reason);
                    continue;
                }
                if ($eligible && $fwd->status === 'suspended') {
                    $hasRules = Capsule::table(Schema::T_URL)->where('fwd_domain_id', $fwd->id)->exists()
                        || Capsule::table(Schema::T_EMAIL)->where('fwd_domain_id', $fwd->id)->exists()
                        || $fwd->catchall;
                    if ($hasRules) {
                        $fwd = self::sync($fwd);
                    }
                }
                if ($eligible && $fwd->status === 'active' && Settings::on('auto_ssl')
                    && !in_array($fwd->ssl_status, ['issued', 'requested'], true)) {
                    self::requestSsl($fwd);
                }
            } catch (\Throwable $e) {
                // Already logged by remote(); continue with the next domain.
            }
        }
    }
}
