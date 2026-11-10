<?php

namespace SeekaHost\Forwarding;

use SeekaHost\Forwarding\Panels\PanelFactory;
use WHMCS\Database\Capsule;

/**
 * Business logic shared by the client area, admin area, API and cron.
 *
 * Domain status:
 *   pending   - not on any server (nothing configured yet)
 *   active    - present on the server with every stored rule applied
 *   error     - a provisioning/sync step failed; retried by cron
 *   suspended - removed automatically (ineligible); restored automatically when eligible again
 *   disabled  - removed by an administrator; only an administrator "Sync" restores it
 *   migrating - rules imported by the migration wizard; the old module still serves the domain
 *
 * Validation problems throw \InvalidArgumentException (safe to show to clients);
 * server problems throw \RuntimeException (logged, admins notified).
 */
class Service
{
    const URL_TYPES = ['301' => 'Permanent (301)', '302' => 'Temporary (302)', '303' => 'See Other (303)', 'masked' => 'Masked (Frame)'];
    const WWW_MODES = ['both' => 'With or without www.', 'www' => 'Only with www.', 'nowww' => 'Only without www.'];

    /* ---------------------------------------------------------------- lookup */

    public static function domainRow($domainId)
    {
        return Capsule::table('tbldomains')->where('id', (int) $domainId)->first();
    }

    /** @return array [bool eligible, string reason, string code] */
    public static function eligibility($domainRow)
    {
        if (!$domainRow) {
            return [false, 'Domain not found.', 'missing'];
        }
        if ($domainRow->status !== 'Active') {
            return [false, 'Forwarding is available for active domains only.', 'status'];
        }
        if (Settings::get('access_mode') !== 'all' && empty($domainRow->emailforwarding)) {
            return [false, 'Domain & Email Forwarding is not enabled for this domain. You can add it from the domain addons page.', 'addon'];
        }
        return [true, '', ''];
    }

    public static function hasHosting($domainRow)
    {
        return Capsule::table('tblhosting')
            ->where('userid', $domainRow->userid)
            ->whereIn('domain', array_unique([$domainRow->domain, strtolower($domainRow->domain)]))
            ->whereIn('domainstatus', ['Active', 'Suspended'])
            ->exists();
    }

    /** Eligibility including the "hide when hosting exists" rule. @return array [ok, reason, code] */
    public static function access($domainRow)
    {
        $result = self::eligibility($domainRow);
        if ($result[0] && Settings::on('hide_if_hosting') && self::hasHosting($domainRow)) {
            return [false, 'This domain has a hosting service with us. Please manage forwarding from your hosting control panel.', 'hosting'];
        }
        return $result;
    }

    public static function visibleToClient($domainRow)
    {
        return self::access($domainRow)[0];
    }

    public static function policy($fwd)
    {
        return Tld::policy($fwd->domain);
    }

    public static function fwdDomain($domainRow, $create = true)
    {
        $fwd = Capsule::table(Schema::T_DOMAINS)->where('domain_id', $domainRow->id)->first();
        if (!$fwd && $create) {
            $now = date('Y-m-d H:i:s');
            try {
                Capsule::table(Schema::T_DOMAINS)->insert([
                    'domain_id' => $domainRow->id,
                    'client_id' => $domainRow->userid,
                    'domain' => Validator::domain($domainRow->domain),
                    'status' => 'pending',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            } catch (\InvalidArgumentException $e) {
                throw $e;
            } catch (\Throwable $e) {
                // Concurrent insert: the row exists now.
            }
            $fwd = Capsule::table(Schema::T_DOMAINS)->where('domain_id', $domainRow->id)->first();
        }
        if ($fwd && (int) $fwd->client_id !== (int) $domainRow->userid) {
            $fwd = self::resetOwner($fwd, (int) $domainRow->userid);
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

    /** Whether anything must exist on the server for this domain. */
    public static function hasContent($fwd)
    {
        return $fwd->parked || $fwd->catchall || $fwd->domain_forward
            || Capsule::table(Schema::T_URL)->where('fwd_domain_id', $fwd->id)->exists()
            || Capsule::table(Schema::T_EMAIL)->where('fwd_domain_id', $fwd->id)->exists();
    }

    /**
     * @param bool $forProvision pick a usable (active) server for a new placement
     */
    public static function server($fwd, $forProvision = false)
    {
        if ($fwd && $fwd->server_id) {
            $server = Capsule::table(Schema::T_SERVERS)->where('id', $fwd->server_id)->first();
            if ($server && ($server->active || !$forProvision)) {
                return $server;
            }
        }
        $q = Capsule::table(Schema::T_SERVERS)->where('active', 1);
        $server = (clone $q)->where('is_default', 1)->first();
        return $server ?: $q->orderBy('id')->first();
    }

    public static function panelFor($fwd, $forProvision = false)
    {
        $server = self::server($fwd, $forProvision);
        if (!$server) {
            throw new \RuntimeException('No forwarding server is configured.');
        }
        return [$server, PanelFactory::make($server)];
    }

    /** The domain exists on a server (fully or partially configured). */
    public static function isPlaced($fwd)
    {
        return in_array($fwd->status, ['active', 'error'], true) && !empty($fwd->remote_ref);
    }

    /** Flag for a full re-sync by cron / admin Sync after a partial server change. */
    private static function markError($fwd, $message)
    {
        self::touch($fwd, ['status' => 'error', 'last_error' => substr($message, 0, 2000)]);
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
    private static function remote($fwd, $action, callable $fn, $notify = true)
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            if ($notify) {
                self::touch($fwd, ['last_error' => substr($action . ': ' . $e->getMessage(), 0, 2000)]);
            }
            Logger::error($action, $fwd->domain . ': ' . $e->getMessage(), $fwd->client_id, $fwd->domain_id, $notify);
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }
    }

    /* ------------------------------------------------------------ provisioning */

    /**
     * Place the domain on a server and apply every stored rule.
     * Leaves status "error" if any step fails, so cron retries it.
     */
    public static function provision($fwd)
    {
        if ($fwd->status === 'migrating') {
            Validator::fail('This domain is being migrated from the previous forwarding module. Finish or cancel it in the Migration tab first.');
        }
        list($server, $panel) = self::panelFor($fwd, !self::isPlaced($fwd) && $fwd->status !== 'active');
        try {
            $ref = self::remote($fwd, 'provision', function () use ($panel, $fwd) {
                return $panel->addDomain($fwd->domain);
            });
        } catch (\RuntimeException $e) {
            self::touch($fwd, ['status' => 'error']);
            throw $e;
        }
        self::touch($fwd, ['server_id' => $server->id, 'remote_ref' => json_encode($ref), 'status' => 'error']);
        $fwd = self::reload($fwd);

        self::pushAll($fwd, $panel, $ref);

        self::touch($fwd, ['status' => 'active', 'last_error' => null, 'last_sync' => date('Y-m-d H:i:s')]);
        $fwd = self::reload($fwd);
        Logger::log('provision', $fwd->domain . ' provisioned on server ' . $server->name, 'info', $fwd->client_id, $fwd->domain_id);

        if (Settings::on('auto_ssl') && !in_array($fwd->ssl_status, ['issued', 'requested'], true)) {
            try {
                self::requestSsl($fwd, true);
            } catch (\Throwable $e) {
                // DNS is often not pointed yet; the daily cron retries.
            }
        }
        return self::reload($fwd);
    }

    private static function pushAll($fwd, $panel, array $ref)
    {
        self::deployWeb($fwd, $panel, $ref);
        foreach (self::emails($fwd) as $row) {
            $dests = self::splitDestinations($row->destinations);
            self::remote($fwd, 'sync_email', function () use ($panel, $fwd, $ref, $row, $dests) {
                $panel->syncEmailForwarder($fwd->domain, $ref, $row->local_part, null, $dests);
            });
        }
        if ($panel->supports('domain_email')) {
            self::remote($fwd, 'sync_domain_forward', function () use ($panel, $fwd, $ref) {
                $panel->setDomainForwarder($fwd->domain, $ref, $fwd->domain_forward ?: null);
            });
        }
        if ($fwd->catchall) {
            self::remote($fwd, 'sync_catchall', function () use ($panel, $fwd, $ref) {
                $panel->setCatchAll($fwd->domain, $ref, $fwd->catchall);
            });
        } elseif (!empty($fwd->keep_default) && method_exists($panel, 'setDefaultAddressRaw')) {
            // Migrated domain: unrouted mail keeps going where it went before (e.g. the account mailbox).
            self::remote($fwd, 'sync_default_address', function () use ($panel, $fwd) {
                $panel->setDefaultAddressRaw($fwd->domain, $fwd->keep_default);
            });
        } else {
            try {
                // Never let unrouted mail land in the shared forwarding account.
                $panel->setCatchAll($fwd->domain, $ref, null);
            } catch (\Throwable $e) {
                Logger::log('catchall_reset', $fwd->domain . ': ' . $e->getMessage(), 'warning', $fwd->client_id, $fwd->domain_id);
            }
        }
    }

    public static function ensureProvisioned($fwd)
    {
        if ($fwd->status === 'active') {
            return $fwd;
        }
        if ($fwd->status === 'disabled') {
            Validator::fail('Forwarding for this domain has been disabled by our team. Please contact support.');
        }
        return self::provision($fwd);
    }

    /** Admin "Sync": re-apply everything (also re-enables a disabled domain). */
    public static function sync($fwd)
    {
        $fwd = self::provision($fwd);
        Logger::log('sync', $fwd->domain . ' fully synchronised', 'info', $fwd->client_id, $fwd->domain_id);
        return $fwd;
    }

    public static function deployWeb($fwd, $panel = null, $ref = null)
    {
        if ($panel === null) {
            list(, $panel) = self::panelFor($fwd);
        }
        $ref = $ref === null ? self::ref($fwd) : $ref;
        $policy = self::policy($fwd);
        $rules = [];
        foreach (self::urls($fwd) as $r) {
            if (!$policy['url'] || ($r->source_path !== '/' && !$policy['path'])) {
                continue;
            }
            if ($r->wildcard && !$policy['wildcard']) {
                $r = clone $r;
                $r->wildcard = 0;
            }
            $rules[] = $r;
        }
        $files = Generator::build($fwd->domain, $rules, Settings::text('parking_html'));
        self::remote($fwd, 'deploy_web', function () use ($panel, $fwd, $ref, $files) {
            $panel->deployFiles($fwd->domain, $ref, $files);
        });
        self::touch($fwd, ['last_sync' => date('Y-m-d H:i:s')]);
    }

    /**
     * Remove the domain (and its mail forwarders) from the server; rules stay in the database.
     * @param string $status suspended | disabled | pending
     */
    public static function unprovision($fwd, $status, $reason)
    {
        $onServer = in_array($fwd->status, ['active', 'error'], true) || $fwd->remote_ref;
        if ($onServer && $fwd->server_id) {
            list(, $panel) = self::panelFor($fwd);
            $ref = self::ref($fwd);
            foreach (self::emails($fwd) as $row) {
                try {
                    $panel->syncEmailForwarder($fwd->domain, $ref, $row->local_part, self::splitDestinations($row->destinations), []);
                } catch (\Throwable $e) {
                    // Removing the domain below cleans up anyway.
                }
            }
            try {
                if ($fwd->domain_forward && $panel->supports('domain_email')) {
                    $panel->setDomainForwarder($fwd->domain, $ref, null);
                }
                if ($fwd->catchall) {
                    $panel->setCatchAll($fwd->domain, $ref, null);
                }
            } catch (\Throwable $e) {
                // Best effort; the domain itself is removed below.
            }
            self::remote($fwd, 'unprovision', function () use ($panel, $fwd, $ref) {
                $panel->removeDomain($fwd->domain, $ref);
            });
        }
        self::touch($fwd, ['status' => $status, 'remote_ref' => null, 'ssl_status' => null, 'last_error' => null]);
        Logger::log('unprovision', $fwd->domain . ' removed from server (' . $status . '): ' . $reason, 'info', $fwd->client_id, $fwd->domain_id);
        return self::reload($fwd);
    }

    /** Backwards-compatible alias used by hooks/admin. */
    public static function suspend($fwd, $reason, $status = 'suspended')
    {
        return self::unprovision($fwd, $status, $reason);
    }

    /**
     * Remove from server and delete all module data for the domain.
     * Without $force a server failure aborts, so nothing is orphaned on the server.
     */
    public static function purge($fwd, $force = false)
    {
        try {
            self::unprovision($fwd, 'pending', 'purge');
        } catch (\Throwable $e) {
            if (!$force) {
                throw $e;
            }
        }
        Capsule::table(Schema::T_URL)->where('fwd_domain_id', $fwd->id)->delete();
        Capsule::table(Schema::T_EMAIL)->where('fwd_domain_id', $fwd->id)->delete();
        Capsule::table(Schema::T_DOMAINS)->where('id', $fwd->id)->delete();
        Logger::log('purge', $fwd->domain . ' forwarding data deleted' . ($force ? ' (forced)' : ''), 'info', $fwd->client_id, $fwd->domain_id);
    }

    /**
     * Domain moved to another client: wipe the previous owner's rules.
     * Throws (and changes nothing) while the server cannot be cleaned, so the
     * previous owner's forwarders can never stay live; callers/cron retry.
     */
    public static function resetOwner($fwd, $newClientId)
    {
        try {
            self::unprovision($fwd, 'pending', 'owner changed');
        } catch (\Throwable $e) {
            throw new \RuntimeException('Forwarding for ' . $fwd->domain . ' is being reset after an ownership change. Please try again later.', 0, $e);
        }
        Capsule::table(Schema::T_URL)->where('fwd_domain_id', $fwd->id)->delete();
        Capsule::table(Schema::T_EMAIL)->where('fwd_domain_id', $fwd->id)->delete();
        self::touch($fwd, ['client_id' => $newClientId, 'catchall' => null, 'domain_forward' => null, 'parked' => 0, 'status' => 'pending']);
        Logger::log('owner_change', $fwd->domain . ' moved from client #' . $fwd->client_id . ' to #' . $newClientId . '; forwarders reset', 'info', $newClientId, $fwd->domain_id, 'system');
        return self::reload($fwd);
    }

    public static function park($fwd)
    {
        self::touch($fwd, ['parked' => 1]);
        $fwd = self::reload($fwd);
        if ($fwd->status === 'active') {
            self::deployWeb($fwd);
            return $fwd;
        }
        return self::ensureProvisioned($fwd);
    }

    public static function unpark($fwd)
    {
        self::touch($fwd, ['parked' => 0]);
        $fwd = self::reload($fwd);
        if (in_array($fwd->status, ['active', 'error'], true) && !self::hasContent($fwd)) {
            $fwd = self::unprovision($fwd, 'pending', 'unparked');
        }
        return $fwd;
    }

    /** @param bool $quiet expected failures (DNS not pointed) are logged without alerts */
    public static function requestSsl($fwd, $quiet = false)
    {
        list(, $panel) = self::panelFor($fwd);
        self::touch($fwd, ['ssl_requested_at' => date('Y-m-d H:i:s')]);
        try {
            $status = self::remote($fwd, 'ssl', function () use ($panel, $fwd) {
                return $panel->requestSsl($fwd->domain, self::ref($fwd));
            }, !$quiet);
        } catch (\RuntimeException $e) {
            self::touch($fwd, ['ssl_status' => 'failed']);
            throw $e;
        }
        self::touch($fwd, ['ssl_status' => $status ?: 'requested']);
        Logger::log('ssl', 'SSL ' . ($status ?: 'requested') . ' for ' . $fwd->domain, 'info', $fwd->client_id, $fwd->domain_id);
    }

    /* ------------------------------------------------------------- URL rules */

    public static function saveUrl($fwd, array $in, $id = null, $auto = false)
    {
        $policy = self::policy($fwd);
        if (!$policy['url']) {
            Validator::fail('URL forwarding is not available for this domain.');
        }
        $type = isset($in['type']) ? (string) $in['type'] : '301';
        if (!isset(self::URL_TYPES[$type])
            || ($type === '302' && !Settings::on('allow_302'))
            || ($type === '303' && !Settings::on('allow_303'))
            || ($type === 'masked' && !Settings::on('allow_masked'))) {
            Validator::fail('This forwarding type is not available.');
        }
        $path = Validator::path(isset($in['source_path']) ? $in['source_path'] : '/');
        if ($path !== '/' && !$policy['path']) {
            Validator::fail('Path forwarding is not available for this domain; use "/" as the source.');
        }
        $wildcard = !empty($in['wildcard']);
        if ($wildcard && !$policy['wildcard']) {
            Validator::fail('Wildcard forwarding is not available.');
        }
        $www = isset($in['www_mode']) && isset(self::WWW_MODES[$in['www_mode']]) ? $in['www_mode'] : 'both';
        $dest = Validator::url(isset($in['destination']) ? $in['destination'] : '');
        $destHost = strtolower((string) parse_url($dest, PHP_URL_HOST));
        if ($destHost === $fwd->domain || $destHost === 'www.' . $fwd->domain) {
            $destPath = rtrim((string) parse_url($dest, PHP_URL_PATH), '/');
            $srcPath = rtrim($path, '/');
            if ($wildcard || $destPath === '' || strcasecmp($destPath, $srcPath) === 0
                || ($srcPath !== '' && stripos($destPath . '/', $srcPath . '/') === 0)) {
                Validator::fail('The destination would loop back to the same address.');
            }
        }

        $existing = null;
        if ($id) {
            $existing = Capsule::table(Schema::T_URL)->where('fwd_domain_id', $fwd->id)->where('id', (int) $id)->first();
            if (!$existing) {
                Validator::fail('Forwarder not found.');
            }
        }
        $dup = Capsule::table(Schema::T_URL)->where('fwd_domain_id', $fwd->id)->where('source_path', $path);
        if ($id) {
            $dup->where('id', '!=', (int) $id);
        }
        if ($dup->exists()) {
            Validator::fail('A forwarder for ' . $path . ' already exists.');
        }
        if (!$id) {
            $limit = self::limits($fwd)['url'];
            if (!$auto && $limit > 0 && Capsule::table(Schema::T_URL)->where('fwd_domain_id', $fwd->id)->count() >= $limit) {
                Validator::fail('You have reached the limit of ' . $limit . ' URL forwarder(s) for this domain.');
            }
        }

        $row = [
            'source_path' => $path,
            'destination' => $dest,
            'type' => $type,
            'www_mode' => $www,
            'wildcard' => $wildcard ? 1 : 0,
            'preserve_query' => !empty($in['preserve_query']) ? 1 : 0,
            'title' => $type === 'masked' ? Validator::text(isset($in['title']) ? $in['title'] : '', 255) : null,
            'meta_description' => $type === 'masked' ? Validator::text(isset($in['meta_description']) ? $in['meta_description'] : '', 1000) : null,
            'meta_keywords' => $type === 'masked' ? Validator::text(isset($in['meta_keywords']) ? $in['meta_keywords'] : '', 1000) : null,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $fwd = self::ensureProvisioned($fwd);

        // Write, deploy, and undo the write if the server rejects the deploy.
        if ($existing) {
            Capsule::table(Schema::T_URL)->where('id', $existing->id)->update($row);
            $undo = function () use ($existing) {
                Capsule::table(Schema::T_URL)->where('id', $existing->id)->update((array) $existing);
            };
        } else {
            $row['fwd_domain_id'] = $fwd->id;
            $row['auto'] = $auto ? 1 : 0;
            $row['created_at'] = $row['updated_at'];
            $newId = Capsule::table(Schema::T_URL)->insertGetId($row);
            $undo = function () use ($newId) {
                Capsule::table(Schema::T_URL)->where('id', $newId)->delete();
            };
        }
        try {
            self::deployWeb($fwd);
        } catch (\Throwable $e) {
            $undo();
            throw $e;
        }

        $summary = $fwd->domain . $path . ' -> ' . $dest . ' (' . self::URL_TYPES[$type] . ($wildcard ? ', wildcard' : '')
            . ($www !== 'both' ? ', ' . self::WWW_MODES[$www] : '') . ')';
        Logger::log($existing ? 'url_update' : ($auto ? 'url_auto' : 'url_add'), $summary, 'info', $fwd->client_id, $fwd->domain_id);
        if (!$auto) {
            Notifier::client($fwd->domain_id, $existing ? 'URL forwarder updated' : 'URL forwarder added', $summary);
        }
    }

    public static function deleteUrl($fwd, $id)
    {
        $row = Capsule::table(Schema::T_URL)->where('fwd_domain_id', $fwd->id)->where('id', (int) $id)->first();
        if (!$row) {
            Validator::fail('Forwarder not found.');
        }
        Capsule::table(Schema::T_URL)->where('id', $row->id)->delete();
        if (self::isPlaced($fwd)) {
            try {
                self::deployWeb($fwd);
            } catch (\Throwable $e) {
                Capsule::table(Schema::T_URL)->insert((array) $row);
                throw $e;
            }
        }
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
        if (!self::policy($fwd)['email']) {
            Validator::fail('Email forwarding is not available for this domain.');
        }
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

        // Persist first so a provision triggered here restores this alias too.
        $now = date('Y-m-d H:i:s');
        if ($existing) {
            Capsule::table(Schema::T_EMAIL)->where('id', $existing->id)->update(['destinations' => implode(',', $new), 'updated_at' => $now]);
            $undo = function () use ($existing) {
                Capsule::table(Schema::T_EMAIL)->where('id', $existing->id)->update((array) $existing);
            };
        } else {
            $newId = Capsule::table(Schema::T_EMAIL)->insertGetId([
                'fwd_domain_id' => $fwd->id,
                'local_part' => $local,
                'destinations' => implode(',', $new),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $undo = function () use ($newId) {
                Capsule::table(Schema::T_EMAIL)->where('id', $newId)->delete();
            };
        }

        $panel = null;
        $ref = [];
        try {
            if (self::isPlaced($fwd)) {
                list(, $panel) = self::panelFor($fwd);
                $ref = self::ref($fwd);
                $old = $existing ? self::splitDestinations($existing->destinations) : null;
                self::remote($fwd, 'email_save', function () use ($panel, $fwd, $ref, $local, $old, $new) {
                    $panel->syncEmailForwarder($fwd->domain, $ref, $local, $old, $new);
                });
            } else {
                self::ensureProvisioned($fwd);
            }
        } catch (\Throwable $e) {
            $undo();
            if ($panel !== null) {
                // The server may hold part of the change: put it back to the stored state.
                $restore = $existing ? self::splitDestinations($existing->destinations) : [];
                try {
                    $panel->syncEmailForwarder($fwd->domain, $ref, $local, null, $restore);
                } catch (\Throwable $ignored) {
                    self::markError($fwd, 'email_save: server state for ' . $address . ' may differ; re-sync required. ' . $e->getMessage());
                }
            }
            throw $e;
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
        if (self::isPlaced($fwd)) {
            list(, $panel) = self::panelFor($fwd);
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
        $dest = null;
        if (trim((string) $destination) !== '') {
            if (!self::policy($fwd)['catchall']) {
                Validator::fail('Catch-all forwarding is not available for this domain.');
            }
            $dest = Validator::email($destination);
            if (substr($dest, -strlen('@' . $fwd->domain)) === '@' . $fwd->domain) {
                Validator::fail('The catch-all destination must be outside ' . $fwd->domain . '.');
            }
        }
        if ($dest === $fwd->catchall) {
            return;
        }
        $previous = $fwd->catchall;
        // The customer now manages unrouted mail themselves.
        self::touch($fwd, ['catchall' => $dest, 'keep_default' => null]);
        $fwd = self::reload($fwd);
        try {
            if (self::isPlaced($fwd)) {
                list(, $panel) = self::panelFor($fwd);
                $ref = self::ref($fwd);
                self::remote($fwd, 'catchall', function () use ($panel, $fwd, $ref, $dest) {
                    $panel->setCatchAll($fwd->domain, $ref, $dest);
                });
            } elseif ($dest !== null) {
                self::ensureProvisioned($fwd);
            }
        } catch (\Throwable $e) {
            self::touch($fwd, ['catchall' => $previous]);
            if (self::isPlaced($fwd)) {
                self::markError($fwd, 'catchall: server state may differ; re-sync required. ' . $e->getMessage());
            }
            throw $e;
        }
        $summary = '*@' . $fwd->domain . ' -> ' . ($dest ?: 'disabled (reject)');
        Logger::log('catchall', $summary, 'info', $fwd->client_id, $fwd->domain_id);
        Notifier::client($fwd->domain_id, 'Catch-all updated', $summary);
    }

    public static function supportsDomainEmail($fwd)
    {
        $server = self::server($fwd, !self::isPlaced($fwd) && $fwd->status !== 'active');
        return $server && PanelFactory::make($server)->supports('domain_email');
    }

    /** Forward all mail for the domain to the same address at another domain (cPanel). */
    public static function setDomainForwarder($fwd, $destDomain)
    {
        $dest = null;
        if (trim((string) $destDomain) !== '') {
            if (!self::policy($fwd)['domain_email'] || !self::supportsDomainEmail($fwd)) {
                Validator::fail('Domain email forwarding is not available for this domain.');
            }
            $dest = Validator::domain($destDomain);
            if ($dest === $fwd->domain) {
                Validator::fail('A domain cannot forward its mail to itself.');
            }
        }
        if ($dest === $fwd->domain_forward) {
            return;
        }
        $previous = $fwd->domain_forward;
        self::touch($fwd, ['domain_forward' => $dest]);
        $fwd = self::reload($fwd);
        try {
            if (self::isPlaced($fwd)) {
                list(, $panel) = self::panelFor($fwd);
                $ref = self::ref($fwd);
                if ($panel->supports('domain_email')) {
                    self::remote($fwd, 'domain_forward', function () use ($panel, $fwd, $ref, $dest) {
                        $panel->setDomainForwarder($fwd->domain, $ref, $dest);
                    });
                }
            } elseif ($dest !== null) {
                self::ensureProvisioned($fwd);
            }
        } catch (\Throwable $e) {
            self::touch($fwd, ['domain_forward' => $previous]);
            if (self::isPlaced($fwd)) {
                self::markError($fwd, 'domain_forward: server state may differ; re-sync required. ' . $e->getMessage());
            }
            throw $e;
        }
        $summary = '@' . $fwd->domain . ' -> ' . ($dest ? '@' . $dest : 'disabled');
        Logger::log('domain_forward', $summary, 'info', $fwd->client_id, $fwd->domain_id);
        Notifier::client($fwd->domain_id, 'Domain email forwarding updated', $summary);
    }

    /* ------------------------------------------------------------ automation */

    /** Called after a domain is registered or transferred in. */
    public static function autoSetup($domainId)
    {
        if (!Settings::on('auto_forwarders')) {
            return;
        }
        $domainRow = self::domainRow($domainId);
        if (!$domainRow || !self::access($domainRow)[0]) {
            return;
        }
        $policy = Tld::policy($domainRow->domain);
        $action = $policy['auto_action'];
        if ($policy['park_only'] && $action !== 'none') {
            $action = 'park';
        }
        if ($action === 'none') {
            return;
        }
        $fwd = self::fwdDomain($domainRow);
        if ($action === 'park') {
            self::park($fwd);
            Logger::log('auto_park', $fwd->domain . ' parked automatically', 'info', $fwd->client_id, $fwd->domain_id, 'system');
            return;
        }
        if ($action === 'forward' && $policy['auto_url'] !== '' && !Capsule::table(Schema::T_URL)->where('fwd_domain_id', $fwd->id)->exists()) {
            self::saveUrl($fwd, [
                'source_path' => '/',
                'destination' => str_replace('{domain}', $fwd->domain, $policy['auto_url']),
                'type' => $policy['auto_type'],
                'preserve_query' => 1,
                'title' => Validator::toUnicode($fwd->domain),
            ], null, true);
        }
    }

    /** A hosting service is about to be created for this domain: free it on the forwarding server. */
    public static function releaseForHosting($domainName, $clientId)
    {
        if (!Settings::on('hide_if_hosting')) {
            return;
        }
        $fwd = Capsule::table(Schema::T_DOMAINS)
            ->where('domain', Validator::toAscii($domainName))
            ->where('client_id', (int) $clientId)
            ->first();
        if ($fwd && in_array($fwd->status, ['active', 'error'], true)) {
            self::unprovision($fwd, 'suspended', 'hosting service created');
        }
    }

    /* ------------------------------------------------------------------ cron */

    public static function cron()
    {
        // Log retention.
        Capsule::table(Schema::T_LOGS)->where('created_at', '<', date('Y-m-d H:i:s', strtotime('-180 days')))->delete();

        $serverDomains = [];
        foreach (Capsule::table(Schema::T_DOMAINS)->get()->all() as $fwd) {
            try {
                $domainRow = self::domainRow($fwd->domain_id);
                if (!$domainRow) {
                    self::purge($fwd);
                    continue;
                }
                $fwd = self::fwdDomain($domainRow, false);
                if (!$fwd || in_array($fwd->status, ['disabled', 'migrating'], true)) {
                    continue;
                }
                list($ok, $reason) = self::access($domainRow);
                $content = self::hasContent($fwd);

                if (!$ok) {
                    if (in_array($fwd->status, ['active', 'error'], true)) {
                        self::unprovision($fwd, 'suspended', $reason);
                    }
                    continue;
                }
                if (in_array($fwd->status, ['suspended', 'error', 'pending'], true)) {
                    if ($content) {
                        $fwd = self::provision($fwd);
                    } elseif ($fwd->status !== 'pending' || $fwd->remote_ref) {
                        $fwd = self::unprovision($fwd, 'pending', 'no forwarders');
                    }
                    continue;
                }
                // Active domain.
                if (!$content) {
                    self::unprovision($fwd, 'pending', 'no forwarders configured');
                    continue;
                }
                $sid = (int) $fwd->server_id;
                if (!array_key_exists($sid, $serverDomains)) {
                    try {
                        list(, $panel) = self::panelFor($fwd);
                        $serverDomains[$sid] = $panel->listDomains();
                    } catch (\Throwable $e) {
                        $serverDomains[$sid] = null;
                        Logger::log('cron', 'Could not list domains on server #' . $sid . ': ' . $e->getMessage(), 'error');
                    }
                }
                if (is_array($serverDomains[$sid]) && !in_array($fwd->domain, $serverDomains[$sid], true)) {
                    Logger::log('drift', $fwd->domain . ' was missing on the server; restoring', 'warning', $fwd->client_id, $fwd->domain_id);
                    self::touch($fwd, ['status' => 'error']);
                    $fwd = self::provision(self::reload($fwd));
                }
                if (Settings::on('auto_ssl') && !in_array($fwd->ssl_status, ['issued', 'requested'], true)
                    && DnsCheck::pointsHere($fwd->domain, self::server($fwd))) {
                    try {
                        self::requestSsl($fwd, true);
                    } catch (\Throwable $e) {
                        // Logged quietly by requestSsl(); retried tomorrow.
                    }
                }
            } catch (\Throwable $e) {
                Logger::log('cron', $fwd->domain . ': ' . $e->getMessage(), 'error', $fwd->client_id, $fwd->domain_id);
            }
        }
    }
}
