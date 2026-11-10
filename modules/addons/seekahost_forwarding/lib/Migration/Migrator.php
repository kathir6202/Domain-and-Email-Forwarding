<?php

namespace SeekaHost\Forwarding\Migration;

use SeekaHost\Forwarding\Logger;
use SeekaHost\Forwarding\Panels\Cpanel;
use SeekaHost\Forwarding\Panels\PanelFactory;
use SeekaHost\Forwarding\Schema;
use SeekaHost\Forwarding\Service;
use SeekaHost\Forwarding\Settings;
use SeekaHost\Forwarding\Validator;
use WHMCS\Database\Capsule;

/**
 * Migration wizard engine: moves domains configured by a previous forwarding module
 * (parked/addon domains with cPanel redirects and email routing) into this module,
 * on the same cPanel account, one domain at a time with live verification and rollback.
 *
 * Record states:
 *   discovered      found on the account, not scanned yet
 *   scanned         read-only scan done (mapping + issues stored)
 *   imported        rules copied into this module (domain status "migrating"); server untouched
 *   migrated        moved and verified
 *   failed          move aborted before anything changed on the server
 *   rolled_back     move failed and the old setup was restored and verified
 *   rollback_failed move failed and the old setup could NOT be fully restored (admin action needed)
 *   skipped         ignored by the admin
 */
class Migrator
{
    const PROBE_SUFFIX = '/shfwd-migration-probe';
    const BILLING = [
        'flag' => 'Tick the Email Forwarding addon on migrated domains (no price change)',
        'flag_charge' => 'Tick the Email Forwarding addon and add its price to the renewal amount',
        'all' => 'Switch Access Mode to "All active domains" (free for everyone)',
        'none' => 'Change nothing (domains without the addon will be removed by the daily cron in addon mode)',
    ];

    private $server;
    /** Seconds to wait for cPanel to rebuild and reload Apache after a domain is added or removed. */
    public static $wait = 150;
    /** Seconds between checks while waiting. */
    public static $interval = 5;

    /** @var CpanelLegacy */
    private $legacy;
    /** @var Cpanel */
    private $panel;
    private $ip;
    /** Non-blocking note from the last pre-flight check, for the admin page. */
    public $notice = '';

    public function __construct($server)
    {
        if (!$server || $server->type !== 'cpanel') {
            Validator::fail('The migration wizard currently supports cPanel/WHM servers only.');
        }
        $panel = PanelFactory::make($server);
        if (!$panel instanceof Cpanel) {
            Validator::fail('The selected server is not a cPanel server.');
        }
        $this->server = $server;
        $this->panel = $panel;
        $this->legacy = new CpanelLegacy($panel);
        $this->ip = $server->web_ip !== '' ? $server->web_ip : gethostbyname($server->hostname);
    }

    public static function forServer($serverId)
    {
        return new self(Capsule::table(Schema::T_SERVERS)->where('id', (int) $serverId)->first());
    }

    /* --------------------------------------------------------------- records */

    public static function decode($rec, $field)
    {
        $v = $rec && $rec->$field ? json_decode($rec->$field, true) : null;
        return is_array($v) ? $v : [];
    }

    private static function save($rec, array $data)
    {
        foreach (['issues', 'snapshot', 'mapping', 'result'] as $f) {
            if (array_key_exists($f, $data) && is_array($data[$f])) {
                $data[$f] = json_encode($data[$f]);
            }
        }
        $data['updated_at'] = date('Y-m-d H:i:s');
        Capsule::table(Schema::T_MIGRATION)->where('id', $rec->id)->update($data);
    }

    private static function reload($rec)
    {
        return Capsule::table(Schema::T_MIGRATION)->where('id', $rec->id)->first();
    }

    /* -------------------------------------------------------------- discover */

    /** @return array [new or refreshed records, global redirects found] */
    public function discover()
    {
        $inv = $this->legacy->inventory();
        $ours = Capsule::table(Schema::T_DOMAINS)->whereIn('status', ['active', 'error'])->pluck('domain')->all();
        $candidates = [];
        foreach ($inv['parked'] as $d) {
            $candidates[$d] = ['parked', null];
        }
        foreach ($inv['addon'] as $d => $row) {
            $dir = (string) CpanelLegacy::field($row, ['reldir', 'dir']);
            if (strpos($dir, 'seekahost_fwd/') !== false) {
                continue; // already managed by this module
            }
            $candidates[$d] = ['addon', $row];
        }

        $count = 0;
        $now = date('Y-m-d H:i:s');
        foreach ($candidates as $d => $info) {
            if ($d === $inv['main'] || in_array($d, $ours, true)) {
                continue;
            }
            $existing = Capsule::table(Schema::T_MIGRATION)->where('domain', $d)->first();
            if ($existing && !in_array($existing->state, ['discovered', 'scanned', 'skipped'], true)) {
                continue; // in progress or done: never reset automatically
            }
            $data = [
                'server_id' => $this->server->id,
                'kind' => $info[0],
                'state' => $existing && $existing->state === 'skipped' ? 'skipped' : 'discovered',
                'snapshot' => json_encode(['addon' => $info[1]]),
                'updated_at' => $now,
            ];
            if ($existing) {
                Capsule::table(Schema::T_MIGRATION)->where('id', $existing->id)->update($data);
            } else {
                Capsule::table(Schema::T_MIGRATION)->insert($data + ['domain' => $d, 'created_at' => $now]);
            }
            $count++;
        }
        $global = count($this->legacy->globalRedirects());
        Logger::log('migration', 'Discovered ' . $count . ' domain(s) on cPanel account ' . $this->server->account . ' (server ' . $this->server->name . ')');
        return [$count, $global];
    }

    /* ------------------------------------------------------------------ scan */

    /** @return array [scanned now, still waiting] */
    public function scanBatch($limit = 10)
    {
        $rows = Capsule::table(Schema::T_MIGRATION)->where('server_id', $this->server->id)
            ->where('state', 'discovered')->orderBy('domain')->limit($limit)->get()->all();
        foreach ($rows as $rec) {
            $this->scan($rec);
        }
        $left = Capsule::table(Schema::T_MIGRATION)->where('server_id', $this->server->id)->where('state', 'discovered')->count();
        return [count($rows), $left];
    }

    public function scan($rec)
    {
        $d = $rec->domain;
        $addon = self::decode($rec, 'snapshot');
        $addonRow = isset($addon['addon']) ? $addon['addon'] : null;
        try {
            $live = $this->readLive($d, $rec->kind, $addonRow);
        } catch (\Throwable $e) {
            self::save($rec, ['state' => 'scanned', 'blocking' => 1, 'issues' => ['Could not read the current setup: ' . $e->getMessage()]]);
            return;
        }
        list($mapping, $issues, $blocking) = $this->map($d, $live);

        $domainRow = Capsule::table('tbldomains')
            ->whereIn('domain', array_unique([$d, Validator::toUnicode($d)]))
            ->orderBy('id', 'desc')->first();
        $domainId = null;
        $clientId = null;
        if (!$domainRow) {
            $blocking = true;
            $issues[] = 'Not found in WHMCS (no domain record), so it cannot be linked to a client.';
        } else {
            $domainId = $domainRow->id;
            $clientId = $domainRow->userid;
            if ($domainRow->status !== 'Active') {
                $blocking = true;
                $issues[] = 'Domain status in WHMCS is "' . $domainRow->status . '"; only Active domains are migrated.';
            }
            if (Settings::on('hide_if_hosting') && Service::hasHosting($domainRow)) {
                $issues[] = 'The client has a hosting service for this domain; with "Hide When Hosting Exists" on, the daily cron will take it off the forwarding server.';
            }
            $fwd = Capsule::table(Schema::T_DOMAINS)->where('domain_id', $domainRow->id)->first();
            if ($fwd && (Service::hasContent($fwd) || Service::isPlaced($fwd))) {
                $blocking = true;
                $issues[] = 'This domain already has forwarding configured in this module.';
            }
        }

        self::save($rec, [
            'state' => 'scanned',
            'domain_id' => $domainId,
            'client_id' => $clientId,
            'blocking' => $blocking ? 1 : 0,
            'issues' => $issues,
            'snapshot' => $live,
            'mapping' => $mapping,
            'message' => null,
        ]);
    }

    private function readLive($d, $kind, $addonRow)
    {
        $redirects = $this->legacy->redirectsFor($d);
        $live = [
            'kind' => $kind,
            'addon' => $addonRow,
            'redirects' => $redirects,
            'forwarders' => $this->legacy->forwarders($d),
            'domain_forwarder' => $this->legacy->domainForwarder($d),
            'default_address' => $this->legacy->defaultAddress($d),
        ];
        $live['probes'] = $this->probeAll($d, self::probePaths($redirects));
        return $live;
    }

    private static function srcOf(array $r)
    {
        return '/' . ltrim((string) CpanelLegacy::field($r, ['sourceurl', 'source', 'displaysourceurl'], '/'), '/');
    }

    private static function probePaths(array $redirects)
    {
        $paths = ['/'];
        foreach ($redirects as $r) {
            $src = self::srcOf($r);
            $paths[] = $src;
            $paths[] = rtrim($src, '/') . self::PROBE_SUFFIX . '?x=1';
        }
        return array_values(array_unique($paths));
    }

    private function probeAll($d, array $paths)
    {
        $out = [];
        foreach ([$d, 'www.' . $d] as $host) {
            foreach ($paths as $p) {
                $r = Prober::probe($this->ip, $host, $p);
                $out[] = ['host' => $host, 'path' => $p, 'code' => (int) $r[0], 'location' => (string) $r[1], 'module' => !empty($r[2])];
            }
        }
        return $out;
    }

    private static function probeFor(array $probes, $host, $path)
    {
        foreach ($probes as $p) {
            if ($p['host'] === $host && $p['path'] === $path) {
                return $p;
            }
        }
        return null;
    }

    /** Translate the legacy setup into this module's rules. @return array [mapping, issues, blocking] */
    private function map($d, array $live)
    {
        $issues = [];
        $blocking = false;
        $urls = [];
        foreach ($live['redirects'] as $r) {
            $srcRaw = self::srcOf($r);
            try {
                $src = Validator::path($srcRaw);
                $dest = Validator::url((string) CpanelLegacy::field($r, ['destination', 'targeturl', 'redirection']));
            } catch (\InvalidArgumentException $e) {
                $blocking = true;
                $issues[] = 'Redirect ' . $srcRaw . ': ' . $e->getMessage();
                continue;
            }
            $typeRaw = strtolower((string) CpanelLegacy::field($r, ['type', 'statuscode'], 'permanent'));
            $type = (strpos($typeRaw, 'temp') !== false || $typeRaw === '302' || $typeRaw === '307') ? '302' : '301';
            $wildRaw = CpanelLegacy::field($r, ['wildcard'], 0);
            $wild = $wildRaw === true || in_array(strtolower((string) $wildRaw), ['1', 'yes', 'on', 'true'], true);

            // www handling and status code from the live behaviour captured by the probes.
            $bare = self::probeFor($live['probes'], $d, $srcRaw);
            $www = self::probeFor($live['probes'], 'www.' . $d, $srcRaw);
            $bareR = $bare && Prober::isRedirect($bare['code']);
            $wwwR = $www && Prober::isRedirect($www['code']);
            if ($bareR && $wwwR) {
                $mode = 'both';
            } elseif ($bareR) {
                $mode = 'nowww';
            } elseif ($wwwR) {
                $mode = 'www';
            } else {
                $matchWww = CpanelLegacy::field($r, ['matchwww'], 0);
                $mode = CpanelLegacy::redirectDomain($r) === 'www.' . $d ? 'www' : (in_array((string) $matchWww, ['1', '2'], true) ? 'both' : 'nowww');
                $issues[] = 'Redirect ' . $src . ' did not answer during the scan; www handling was taken from the cPanel settings.';
            }
            $seen = $bareR ? $bare['code'] : ($wwwR ? $www['code'] : 0);
            $codeMap = [301 => '301', 302 => '302', 303 => '303', 307 => '302', 308 => '301'];
            if (isset($codeMap[$seen])) {
                $type = $codeMap[$seen];
            }
            $rule = [
                'source_path' => $src,
                'destination' => $dest,
                'type' => $type,
                'www_mode' => $mode,
                'wildcard' => $wild ? 1 : 0,
                // Apache keeps the visitor's query string unless the target has its own.
                'preserve_query' => strpos($dest, '?') === false ? 1 : 0,
            ];
            if (isset($urls[$src])) {
                $prev = $urls[$src];
                if ($prev['destination'] !== $dest || $prev['type'] !== $type || $prev['wildcard'] !== $rule['wildcard']) {
                    $blocking = true;
                    $issues[] = 'Two different redirects exist for path ' . $src . ' (e.g. separate www / non-www targets); combine them in cPanel, then re-scan.';
                } elseif ($prev['www_mode'] !== $mode) {
                    $urls[$src]['www_mode'] = 'both';
                }
                continue;
            }
            $urls[$src] = $rule;
        }

        $emails = [];
        foreach ($live['forwarders'] as $f) {
            $local = substr($f['dest'], 0, strrpos($f['dest'], '@'));
            try {
                $local = Validator::localPart($local);
            } catch (\InvalidArgumentException $e) {
                $blocking = true;
                $issues[] = 'Email alias ' . $f['dest'] . ' has a name this module cannot store.';
                continue;
            }
            if (!filter_var($f['forward'], FILTER_VALIDATE_EMAIL)) {
                $blocking = true;
                $issues[] = $f['dest'] . ' delivers to "' . $f['forward'] . '" (fail/blackhole/pipe/mailbox), which this module does not support. Change or remove it in cPanel, then re-scan.';
                continue;
            }
            $emails[$local][] = strtolower($f['forward']);
        }
        foreach ($emails as $l => $list) {
            $emails[$l] = array_values(array_unique($list));
        }

        $catchall = null;
        $raw = (string) $live['default_address'];
        if ($raw !== '' && filter_var($raw, FILTER_VALIDATE_EMAIL)) {
            $catchall = strtolower($raw);
            if (substr($catchall, -strlen('@' . $d)) === '@' . $d) {
                $blocking = true;
                $issues[] = 'Catch-all forwards to an address on the same domain (' . $catchall . '), which this module does not allow.';
            }
        } elseif ($raw !== '' && strpos($raw, ':fail:') !== 0 && $raw !== ':blackhole:') {
            $issues[] = 'Unrouted mail currently goes to "' . $raw . '" (the account mailbox); after migration it will be rejected.';
        }

        $domainForward = null;
        if (!empty($live['domain_forwarder'])) {
            try {
                $domainForward = Validator::domain($live['domain_forwarder']);
            } catch (\InvalidArgumentException $e) {
                $blocking = true;
                $issues[] = 'Domain email forwarder target "' . $live['domain_forwarder'] . '" is not a valid domain.';
            }
        }

        $parked = !$urls && !$emails && !$catchall && !$domainForward;
        if ($parked) {
            $issues[] = 'No forwarders found: the domain will be parked and show the module parking page.';
        }
        if ($live['redirects']) {
            $answered = array_filter($live['probes'], function ($p) {
                return $p['code'] > 0;
            });
            if (!$answered) {
                $blocking = true;
                $issues[] = 'The server did not answer HTTP requests on ' . $this->ip . ' (check the server Web IP), so the result could not be verified.';
            }
        }

        return [[
            'urls' => array_values($urls),
            'emails' => $emails,
            'catchall' => $catchall,
            'domain_forward' => $domainForward,
            'parked' => $parked,
        ], $issues, $blocking];
    }

    /* ---------------------------------------------------------------- import */

    /** Copy mapped rules into this module (status "migrating"); nothing changes on the server. */
    public function import(array $ids)
    {
        $done = 0;
        $errors = [];
        foreach (Capsule::table(Schema::T_MIGRATION)->whereIn('id', $ids)->where('server_id', $this->server->id)->get()->all() as $rec) {
            if ($rec->state !== 'scanned' || $rec->blocking) {
                continue;
            }
            try {
                $this->importOne($rec);
                $done++;
            } catch (\Throwable $e) {
                $errors[] = $rec->domain . ': ' . $e->getMessage();
            }
        }
        return [$done, $errors];
    }

    private function importOne($rec)
    {
        $map = self::decode($rec, 'mapping');
        $domainRow = Service::domainRow($rec->domain_id);
        if (!$domainRow) {
            Validator::fail('WHMCS domain record no longer exists.');
        }
        $fwd = Service::fwdDomain($domainRow);
        if (Service::hasContent($fwd) || Service::isPlaced($fwd)) {
            Validator::fail('Already has forwarding configured in this module.');
        }
        $now = date('Y-m-d H:i:s');
        Capsule::connection()->transaction(function () use ($fwd, $map, $rec, $now) {
            foreach ((array) $map['urls'] as $u) {
                Capsule::table(Schema::T_URL)->insert($u + [
                    'fwd_domain_id' => $fwd->id,
                    'auto' => 0,
                    'title' => null,
                    'meta_description' => null,
                    'meta_keywords' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            foreach ((array) $map['emails'] as $local => $dests) {
                Capsule::table(Schema::T_EMAIL)->insert([
                    'fwd_domain_id' => $fwd->id,
                    'local_part' => (string) $local,
                    'destinations' => implode(',', $dests),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            Capsule::table(Schema::T_DOMAINS)->where('id', $fwd->id)->update([
                'status' => 'migrating',
                'server_id' => $this->server->id,
                'remote_ref' => null,
                'catchall' => $map['catchall'],
                'domain_forward' => $map['domain_forward'],
                'parked' => !empty($map['parked']) ? 1 : 0,
                'updated_at' => $now,
            ]);
            self::save($rec, ['state' => 'imported', 'message' => 'Imported (dry run): rules stored, server unchanged.']);
        });
        Logger::log('migration', $rec->domain . ' imported (dry run)', 'info', $fwd->client_id, $fwd->domain_id);
    }

    /** Undo an import that has not been moved (or was rolled back). */
    public static function cancel(array $ids, $toState = 'scanned')
    {
        $n = 0;
        foreach (Capsule::table(Schema::T_MIGRATION)->whereIn('id', $ids)->get()->all() as $rec) {
            if (!in_array($rec->state, ['imported', 'failed', 'rolled_back'], true)) {
                continue;
            }
            $fwd = $rec->domain_id ? Capsule::table(Schema::T_DOMAINS)->where('domain_id', $rec->domain_id)->first() : null;
            if ($fwd && $fwd->status === 'migrating') {
                Capsule::table(Schema::T_URL)->where('fwd_domain_id', $fwd->id)->delete();
                Capsule::table(Schema::T_EMAIL)->where('fwd_domain_id', $fwd->id)->delete();
                Capsule::table(Schema::T_DOMAINS)->where('id', $fwd->id)->delete();
            }
            self::save($rec, ['state' => $toState, 'message' => 'Import cancelled.']);
            $n++;
        }
        return $n;
    }

    /* ------------------------------------------------------------------ move */

    public static function setAccessModeAll()
    {
        $q = Capsule::table('tbladdonmodules')->where('module', SEEKAHOST_FWD_MODULE)->where('setting', 'access_mode');
        if ($q->exists()) {
            $q->update(['value' => 'all']);
        } else {
            Capsule::table('tbladdonmodules')->insert(['module' => SEEKAHOST_FWD_MODULE, 'setting' => 'access_mode', 'value' => 'all']);
        }
        Settings::flush();
        Logger::log('migration', 'Access Mode switched to "All active domains" by the migration wizard');
    }

    /**
     * @param array $opts billing (flag|flag_charge|all|none), accept_diff (bool)
     * @return array remaining ids
     */
    public function moveBatch(array $ids, array $opts, $limit = 5)
    {
        $this->notice = $this->preflight();
        $batch = array_slice($ids, 0, $limit);
        foreach ($batch as $id) {
            $rec = Capsule::table(Schema::T_MIGRATION)->where('id', (int) $id)->where('server_id', $this->server->id)->first();
            if ($rec) {
                try {
                    $this->move($rec, $opts);
                } catch (\Throwable $e) {
                    self::save($rec, ['state' => 'failed', 'message' => 'Unexpected error: ' . $e->getMessage()]);
                }
            }
        }
        return array_values(array_slice($ids, $limit));
    }

    private static function fingerprint(array $live)
    {
        $redirects = [];
        foreach ((array) $live['redirects'] as $r) {
            $redirects[] = implode('|', [
                CpanelLegacy::redirectDomain($r), self::srcOf($r),
                CpanelLegacy::field($r, ['destination', 'targeturl', 'redirection']),
                CpanelLegacy::field($r, ['type', 'statuscode']), CpanelLegacy::field($r, ['wildcard'], 0),
            ]);
        }
        sort($redirects);
        $fw = [];
        foreach ((array) $live['forwarders'] as $f) {
            $fw[] = $f['dest'] . '>' . strtolower($f['forward']);
        }
        sort($fw);
        return md5(json_encode([$redirects, $fw, $live['domain_forwarder'], $live['default_address']]));
    }

    private static function fail($rec, $message)
    {
        self::save($rec, ['state' => 'failed', 'message' => $message]);
        Logger::log('migration', $rec->domain . ': ' . $message, 'warning', $rec->client_id, $rec->domain_id);
    }

    public function move($rec, array $opts)
    {
        if (!in_array($rec->state, ['imported', 'failed', 'rolled_back'], true)) {
            return;
        }
        $d = $rec->domain;
        $fwd = $rec->domain_id ? Capsule::table(Schema::T_DOMAINS)->where('domain_id', $rec->domain_id)->first() : null;
        if (!$fwd || $fwd->status !== 'migrating') {
            self::fail($rec, 'Not imported (or changed since import). Cancel and import again.');
            return;
        }
        $snap = self::decode($rec, 'snapshot');
        $map = self::decode($rec, 'mapping');

        // 1. Read the domain as cPanel has it right now (its internal name may differ from the scan,
        //    e.g. after a manual restore) and make sure its forwarding is still exactly what was scanned.
        try {
            $inv = $this->legacy->inventory();
            $addonRow = null;
            if ($rec->kind === 'addon') {
                if (!isset($inv['addon'][$d])) {
                    self::fail($rec, 'The addon domain is not on the cPanel account any more. Restore it (see the snapshot), then Re-scan. Nothing was changed.');
                    return;
                }
                $addonRow = $inv['addon'][$d];
            } elseif (!in_array($d, $inv['parked'], true)) {
                self::fail($rec, 'The domain is not parked on the cPanel account any more. Restore it (see the snapshot), then Re-scan. Nothing was changed.');
                return;
            }
            $live = $this->readLive($d, $rec->kind, $addonRow);
        } catch (\Throwable $e) {
            self::fail($rec, 'Could not read the current setup: ' . $e->getMessage() . ' Nothing was changed.');
            return;
        }
        if (self::fingerprint($live) !== self::fingerprint($snap)) {
            self::fail($rec, 'The setup on the server changed since the scan. Cancel the import, re-scan and import again. Nothing was changed.');
            return;
        }
        $result = ['before' => $live['probes'], 'addon_before' => $addonRow, 'started_at' => date('Y-m-d H:i:s')];

        // 2. Detach the legacy domain (its .htaccess redirects stay on disk for an instant rollback).
        try {
            $this->legacy->detach($d, $rec->kind, $addonRow);
        } catch (\Throwable $e) {
            self::fail($rec, 'Could not remove the old setup: ' . $e->getMessage() . ' Nothing was changed.');
            return;
        }

        // 3. Provision in this module and verify against the captured behaviour.
        try {
            Capsule::table(Schema::T_DOMAINS)->where('id', $fwd->id)->update(['status' => 'pending', 'server_id' => $this->server->id, 'remote_ref' => null]);
            Service::provision(Service::reload($fwd));
            list($after, $waited, $live2) = $this->awaitNew($d, $live);
            $result['waited_seconds'] = $waited;
            $diffs = self::compare($live['probes'], $after);
            if (!$live2) {
                $diffs[] = 'The new setup was not answering after ' . $waited . ' seconds (cPanel had not finished rebuilding Apache)';
            }
            $diffs = array_merge($diffs, $this->verifyEmail($d, $map));
            $result['after'] = $after;
            $result['diffs'] = $diffs;
            if ($diffs && empty($opts['accept_diff'])) {
                throw new \RuntimeException('Verification failed: ' . implode('; ', array_slice($diffs, 0, 5)));
            }
        } catch (\Throwable $e) {
            $this->rollback($rec, $fwd, $live, $result, $e->getMessage());
            return;
        }

        // 4. Success: clean up the inactive legacy redirects, apply the billing choice.
        $warnings = [];
        if ($rec->kind === 'parked') {
            foreach ($live['redirects'] as $r) {
                try {
                    $this->legacy->deleteRedirect($r);
                } catch (\Throwable $e) {
                    $warnings[] = 'Old redirect ' . self::srcOf($r) . ' is inactive but still listed in cPanel > Redirects; delete it there.';
                }
            }
        } elseif ($addonRow) {
            $warnings[] = 'The old document root (' . CpanelLegacy::field($addonRow, ['reldir', 'dir']) . ') was left on disk.';
        }
        if (!empty($result['diffs'])) {
            $warnings[] = 'Accepted differences: ' . implode('; ', $result['diffs']);
        }
        $this->applyBilling($rec, isset($opts['billing']) ? $opts['billing'] : 'none', $warnings);
        $result['warnings'] = $warnings;
        $result['finished_at'] = date('Y-m-d H:i:s');
        self::save($rec, [
            'state' => 'migrated',
            'result' => $result,
            'message' => 'Migrated and verified.' . ($warnings ? ' ' . count($warnings) . ' note(s).' : ''),
        ]);
        Logger::log('migration', $d . ' migrated and verified', 'info', $rec->client_id, $rec->domain_id);
    }

    private static function pause()
    {
        if (self::$interval > 0) {
            sleep(self::$interval);
        }
    }

    /** Every answered probe came from this module's script (i.e. the new Apache config is live). */
    private static function servedByModule(array $probes)
    {
        $answered = 0;
        foreach ($probes as $p) {
            if ($p['code'] > 0) {
                $answered++;
                if (empty($p['module'])) {
                    return false;
                }
            }
        }
        return $answered > 0;
    }

    private static function anyServedByModule(array $probes)
    {
        foreach ($probes as $p) {
            if (!empty($p['module'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Wait until the new setup answers (cPanel rebuilds Apache asynchronously).
     * @return array [probes, seconds waited, bool new setup live]
     */
    private function awaitNew($d, array $live)
    {
        $paths = self::probePaths($live['redirects']);
        $start = time();
        while (true) {
            $probes = $this->probeAll($d, $paths);
            if (self::servedByModule($probes)) {
                return [$probes, time() - $start, true];
            }
            if (time() - $start >= self::$wait) {
                return [$probes, time() - $start, false];
            }
            self::pause();
        }
    }

    /**
     * Wait until the old setup answers again exactly as captured.
     * @return array [probes, seconds waited]
     */
    private function awaitOld($d, array $live)
    {
        $paths = self::probePaths($live['redirects']);
        $start = time();
        while (true) {
            $probes = $this->probeAll($d, $paths);
            if (!self::compare($live['probes'], $probes) && !self::anyServedByModule($probes)) {
                return [$probes, time() - $start];
            }
            if (time() - $start >= self::$wait) {
                return [$probes, time() - $start];
            }
            self::pause();
        }
    }

    /**
     * Prove cPanel will accept an addon domain on this account before any domain is touched:
     * create and remove a throwaway addon domain (catches FTP / addon / subdomain limits).
     * Throws when cPanel refuses for a limit reason; returns a note for other refusals.
     */
    public function preflight()
    {
        $name = 'shfwd-preflight-' . substr(md5(uniqid('', true)), 0, 10) . '.com';
        try {
            $ref = $this->panel->addDomain($name);
        } catch (\Throwable $e) {
            if (preg_match('/exceed|maximum|quota|limit|not allowed|disk|reached/i', $e->getMessage())) {
                Logger::log('migration', 'Pre-flight check failed: ' . $e->getMessage(), 'error');
                Validator::fail('Pre-flight check failed, no domain was touched: cPanel refused to create an addon domain on account '
                    . $this->server->account . ' (' . $e->getMessage() . '). Raise the account limits (Max FTP Accounts, Max Addon Domains, '
                    . 'Max Subdomains) in WHM, then click Move again.');
            }
            return 'Pre-flight test could not run (' . $e->getMessage() . '); continuing.';
        }
        $note = '';
        try {
            $this->panel->removeDomain($name, $ref);
        } catch (\Throwable $e) {
            $note = 'The pre-flight test domain ' . $name . ' could not be removed (' . $e->getMessage() . '); remove it in cPanel > Domains.';
        }
        if (!empty($ref['sub'])) {
            $this->legacy->deleteFtpAccount($ref['sub']);
        }
        return $note;
    }

    /**
     * Check that the old setup is back and matches the snapshot (after a rollback that could not be verified).
     * @return array [bool ok, string message]
     */
    public function recheck($rec)
    {
        if (!in_array($rec->state, ['rollback_failed', 'rolled_back', 'failed'], true)) {
            return [false, $rec->domain . ': only failed or rolled-back domains can be re-checked.'];
        }
        $snap = self::decode($rec, 'snapshot');
        $d = $rec->domain;
        $problems = [];
        $fwd = $rec->domain_id ? Capsule::table(Schema::T_DOMAINS)->where('domain_id', $rec->domain_id)->first() : null;
        if ($fwd && Service::isPlaced($fwd)) {
            $problems[] = 'the new module setup is still registered for this domain';
        }
        $inv = $this->legacy->inventory();
        if ($rec->kind === 'parked' && !in_array($d, $inv['parked'], true)) {
            $problems[] = 'the domain is not parked on the account';
        }
        if ($rec->kind === 'addon') {
            if (!isset($inv['addon'][$d])) {
                $problems[] = 'the addon domain is missing from the account';
            } elseif (strpos((string) CpanelLegacy::field($inv['addon'][$d], ['reldir', 'dir']), 'seekahost_fwd/') !== false) {
                $problems[] = 'the addon domain still uses this module\'s folder';
            }
        }
        if (!$problems) {
            try {
                $live = $this->readLive($d, $rec->kind, isset($inv['addon'][$d]) ? $inv['addon'][$d] : null);
                if (self::fingerprint($live) !== self::fingerprint($snap)) {
                    $problems[] = 'its redirects or email routing differ from the snapshot';
                }
                foreach (self::compare($snap['probes'], $live['probes']) as $diff) {
                    $problems[] = $diff;
                }
            } catch (\Throwable $e) {
                $problems[] = 'could not read the current setup: ' . $e->getMessage();
            }
        }
        if ($problems) {
            $message = 'Re-check ' . date('Y-m-d H:i') . ': not restored yet: ' . implode('; ', $problems) . '.';
            self::save($rec, ['message' => $message]);
            return [false, $d . ': ' . $message];
        }
        if ($fwd) {
            Capsule::table(Schema::T_DOMAINS)->where('id', $fwd->id)->update(['status' => 'migrating', 'remote_ref' => null]);
        }
        $message = 'Re-check ' . date('Y-m-d H:i') . ': the old setup is live and matches the snapshot. You can move it again.';
        self::save($rec, ['state' => 'rolled_back', 'message' => $message]);
        Logger::log('migration', $d . ': ' . $message, 'info', $rec->client_id, $rec->domain_id);
        return [true, $d . ': ' . $message];
    }

    /** Admin confirms a rollback-failed domain was restored by hand. */
    public static function markRestored(array $ids)
    {
        $n = 0;
        foreach (Capsule::table(Schema::T_MIGRATION)->whereIn('id', $ids)->where('state', 'rollback_failed')->get()->all() as $rec) {
            $fwd = $rec->domain_id ? Capsule::table(Schema::T_DOMAINS)->where('domain_id', $rec->domain_id)->first() : null;
            if ($fwd && Service::isPlaced($fwd)) {
                continue; // the new setup is still on the server: not restored
            }
            if ($fwd) {
                Capsule::table(Schema::T_DOMAINS)->where('id', $fwd->id)->update(['status' => 'migrating', 'remote_ref' => null]);
            }
            self::save($rec, ['state' => 'rolled_back', 'message' => 'Marked as restored by an administrator on ' . date('Y-m-d H:i') . '.']);
            Logger::log('migration', $rec->domain . ' marked as restored by an administrator', 'warning', $rec->client_id, $rec->domain_id);
            $n++;
        }
        return $n;
    }

    /** @return string[] differences for every probe that redirected before */
    private static function compare(array $before, array $after)
    {
        $diffs = [];
        foreach ($before as $b) {
            if (!Prober::isRedirect($b['code'])) {
                continue;
            }
            $a = self::probeFor($after, $b['host'], $b['path']);
            if (!$a || $a['code'] !== $b['code'] || $a['location'] !== $b['location']) {
                $diffs[] = $b['host'] . $b['path'] . ': was ' . $b['code'] . ' ' . $b['location']
                    . ', now ' . ($a ? $a['code'] . ' ' . $a['location'] : 'no answer');
            }
        }
        return $diffs;
    }

    private function verifyEmail($d, array $map)
    {
        $diffs = [];
        $expected = [];
        foreach ((array) $map['emails'] as $local => $dests) {
            foreach ($dests as $dest) {
                $expected[] = $local . '@' . $d . '>' . $dest;
            }
        }
        $actual = [];
        foreach ($this->legacy->forwarders($d) as $f) {
            $actual[] = $f['dest'] . '>' . strtolower($f['forward']);
        }
        sort($expected);
        $actual = array_values(array_unique($actual));
        sort($actual);
        if ($expected !== $actual) {
            $missing = array_diff($expected, $actual);
            $extra = array_diff($actual, $expected);
            $diffs[] = 'Email forwarders differ' . ($missing ? '; missing: ' . implode(', ', $missing) : '') . ($extra ? '; unexpected: ' . implode(', ', $extra) : '');
        }
        $df = $this->legacy->domainForwarder($d);
        if ((string) $df !== (string) $map['domain_forward']) {
            $diffs[] = 'Domain email forwarder is "' . $df . '", expected "' . $map['domain_forward'] . '"';
        }
        if (!empty($map['catchall'])) {
            $now = strtolower($this->legacy->defaultAddress($d));
            if ($now !== $map['catchall']) {
                $diffs[] = 'Catch-all is "' . $now . '", expected "' . $map['catchall'] . '"';
            }
        }
        return $diffs;
    }

    private function rollback($rec, $fwd, array $live, array $result, $reason)
    {
        $steps = [];
        try {
            Service::unprovision(Service::reload($fwd), 'migrating', 'migration rollback');
        } catch (\Throwable $e) {
            $steps[] = 'Removing the new setup failed: ' . $e->getMessage();
        }
        Capsule::table(Schema::T_DOMAINS)->where('id', $fwd->id)->update(['status' => 'migrating', 'remote_ref' => null]);
        $reattached = true;
        try {
            $this->legacy->reattach($rec->domain, $rec->kind, isset($live['addon']) ? $live['addon'] : null);
        } catch (\Throwable $e) {
            if (!preg_match('/already|exists/i', $e->getMessage())) {
                $reattached = false;
                $steps[] = 'Re-adding the old domain failed: ' . $e->getMessage();
            }
        }
        try {
            $this->legacy->restoreEmail($rec->domain, $live);
        } catch (\Throwable $e) {
            $steps[] = 'Restoring email routing failed: ' . $e->getMessage();
        }
        list($back, $waited) = $reattached
            ? $this->awaitOld($rec->domain, $live)
            : [$this->probeAll($rec->domain, self::probePaths($live['redirects'])), 0];
        $diffs = self::compare($live['probes'], $back);
        $result['rollback_waited_seconds'] = $waited;
        $result['rollback'] = $back;
        $result['rollback_steps'] = $steps;
        $result['rollback_diffs'] = $diffs;
        $ok = !$steps && !$diffs;
        $message = 'Migration failed (' . $reason . '). '
            . ($ok ? 'The old setup was restored and verified; nothing else changed.'
                : 'ROLLBACK INCOMPLETE: ' . implode('; ', array_merge($steps, $diffs)) . ' Restore it manually using the snapshot below.');
        self::save($rec, ['state' => $ok ? 'rolled_back' : 'rollback_failed', 'result' => $result, 'message' => $message]);
        if ($ok) {
            Logger::log('migration', $rec->domain . ': ' . $message, 'warning', $rec->client_id, $rec->domain_id);
        } else {
            Logger::error('migration', $rec->domain . ': ' . $message, $rec->client_id, $rec->domain_id);
        }
    }

    private function applyBilling($rec, $billing, array &$warnings)
    {
        if ($billing === 'flag' || $billing === 'flag_charge') {
            try {
                $res = localAPI('UpdateClientDomain', [
                    'domainid' => (int) $rec->domain_id,
                    'emailforwarding' => true,
                    'autorecalc' => $billing === 'flag_charge',
                ]);
                if (isset($res['result']) && $res['result'] !== 'success') {
                    $warnings[] = 'Could not tick the Email Forwarding addon: ' . (isset($res['message']) ? $res['message'] : 'unknown error');
                }
            } catch (\Throwable $e) {
                $warnings[] = 'Could not tick the Email Forwarding addon: ' . $e->getMessage();
            }
            return;
        }
        if ($billing === 'none' && Settings::get('access_mode') !== 'all') {
            $row = Service::domainRow($rec->domain_id);
            if ($row && empty($row->emailforwarding)) {
                $warnings[] = 'The domain does not have the Email Forwarding addon; in addon mode the daily cron will remove its forwarding. Tick the addon or switch Access Mode.';
            }
        }
    }

    /* ----------------------------------------------------------------- admin */

    public static function skip(array $ids)
    {
        return Capsule::table(Schema::T_MIGRATION)->whereIn('id', $ids)->whereIn('state', ['discovered', 'scanned'])
            ->update(['state' => 'skipped', 'message' => 'Skipped by admin.', 'updated_at' => date('Y-m-d H:i:s')]);
    }

    public static function rescan(array $ids)
    {
        self::cancel($ids, 'discovered');
        return Capsule::table(Schema::T_MIGRATION)->whereIn('id', $ids)->whereIn('state', ['scanned', 'skipped', 'failed', 'rolled_back'])
            ->update(['state' => 'discovered', 'message' => null, 'updated_at' => date('Y-m-d H:i:s')]);
    }
}
