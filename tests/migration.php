<?php
// End-to-end test of the migration wizard against a simulated cPanel account served over real HTTP.
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/FakeCpanel.php';

use SeekaHost\Forwarding\Migration\Migrator;
use SeekaHost\Forwarding\Panels\PanelFactory;
use SeekaHost\Forwarding\Schema;
use SeekaHost\Forwarding\Service;
use WHMCS\Database\Capsule;

$pass = 0; $fail = 0;
function ok($c, $l) { global $pass, $fail; if ($c) { $pass++; } else { $fail++; echo "FAIL: $l\n"; } }

$work = sys_get_temp_dir() . '/shfwd_mig_' . getmypid();
@mkdir($work . '/home', 0777, true);
FakeCpanel::reset($work . '/home', $work . '/state.json');
$port = 8095;
$proc = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, __DIR__ . '/migration_router.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $work, ['SHFWD_STATE' => $work . '/state.json']);
usleep(500000);
register_shutdown_function(function () use ($proc) { proc_terminate($proc); });

PanelFactory::$factory = function ($server) { return new FakeCpanel($server); };
Migrator::$wait = 3;
Migrator::$interval = 0;
// Apache rebuild lag: a changed domain answers with the server default page for a while.
SeekaHost\Forwarding\Migration\Prober::$impl = function ($ip, $host, $path) {
    $base = strpos($host, 'www.') === 0 ? substr($host, 4) : $host;
    if (!empty($GLOBALS['shfwd_stale'][$base])) {
        $GLOBALS['shfwd_stale'][$base]--;
        return [200, '', false];
    }
    $impl = SeekaHost\Forwarding\Migration\Prober::$impl;
    SeekaHost\Forwarding\Migration\Prober::$impl = null;
    $r = SeekaHost\Forwarding\Migration\Prober::probe($ip, $host, $path);
    SeekaHost\Forwarding\Migration\Prober::$impl = $impl;
    return $r;
};
require dirname(__DIR__) . '/modules/addons/seekahost_forwarding/seekahost_forwarding.php';
seekahost_forwarding_activate();
Capsule::table('tblclients')->insert(['id' => 1, 'firstname' => 'Ann', 'lastname' => 'A', 'email' => 'a@x.com']);
$sid = Capsule::table(Schema::T_SERVERS)->insertGetId(['name' => 'S', 'type' => 'cpanel', 'hostname' => 'h', 'username' => 'root', 'account' => 'fwduser',
    'web_ip' => '127.0.0.1:' . $port, 'mail_host' => '', 'is_default' => 1, 'active' => 1]);
$id = 100;
foreach (['a.com', 'b.com', 'c.com', 'e.com', 'f.com', 'g.com', 'h.com', 'k.com'] as $d) {
    Capsule::table('tbldomains')->insert(['id' => ++$id, 'userid' => 1, 'domain' => $d, 'status' => 'Active', 'emailforwarding' => 0]);
}
$st = &FakeCpanel::$st;
$st['parked'] = ['a.com' => true, 'b.com' => true, 'c.com' => true, 'd.com' => true, 'f.com' => true, 'g.com' => true, 'h.com' => true, 'k.com' => true];
$st['addon'] = ['e.com' => ['label' => 'ecom', 'dir' => 'public_html/olde']];
@mkdir($work . '/home/public_html/olde', 0777, true);
$st['redirects'] = [
    ['domain' => 'a.com', 'sourceurl' => '/', 'destination' => 'https://dest-a.com/', 'type' => 'permanent', 'wildcard' => 1, 'matchwww' => 1],
    ['domain' => 'b.com', 'sourceurl' => '/blog', 'destination' => 'https://blog.example.org', 'type' => 'temporary', 'wildcard' => 0, 'matchwww' => 0],
    ['domain' => 'e.com', 'sourceurl' => '/', 'destination' => 'https://e-dest.com/?ref=1', 'type' => 'permanent', 'wildcard' => 0, 'matchwww' => 1],
    ['domain' => 'f.com', 'sourceurl' => '/', 'destination' => 'https://f-dest.com/', 'type' => 'permanent', 'wildcard' => 0, 'matchwww' => 1],
    ['domain' => 'g.com', 'sourceurl' => '/', 'destination' => 'https://g-dest.com/', 'type' => 'temporary', 'wildcard' => 1, 'matchwww' => 0],
    ['domain' => 'h.com', 'sourceurl' => '/', 'destination' => 'https://h-dest.com/', 'type' => 'permanent', 'wildcard' => 0, 'matchwww' => 1],
    ['domain' => 'k.com', 'sourceurl' => '/', 'destination' => 'https://k-dest.com/', 'type' => 'permanent', 'wildcard' => 0, 'matchwww' => 1],
];
$st['fwd'] = [
    'a.com' => [['info@a.com', 'x@gmail.com'], ['sales@a.com', 'y@gmail.com'], ['sales@a.com', 'z@gmail.com']],
    'b.com' => [['old@b.com', ':fail: No such user']],
    'f.com' => [['info@f.com', 'f@gmail.com']],
    'h.com' => [['info@h.com', 'h@gmail.com']],
    'k.com' => [['info@k.com', 'k@gmail.com']],
];
$st['default'] = ['a.com' => 'catch@gmail.com', 'f.com' => 'fcatch@gmail.com'];
$st['dfwd'] = ['e.com' => 'e2.com'];
FakeCpanel::persist();

function rec($d) { return Capsule::table(Schema::T_MIGRATION)->where('domain', $d)->first(); }
function fwdOf($d) { return Capsule::table(Schema::T_DOMAINS)->where('domain', $d)->first(); }
function http($host, $path) { return array_slice(SeekaHost\Forwarding\Migration\Prober::probe('127.0.0.1:' . $GLOBALS['port'], $host, $path), 0, 2); }

ok(http('a.com', '/x/y?q=1') === [301, 'https://dest-a.com/x/y?q=1'], 'router emulates cPanel wildcard: ' . json_encode(http('a.com', '/x/y?q=1')));

// ---- discover + scan
$m = Migrator::forServer($sid);
list($n) = $m->discover();
ok($n === 9, "discovered 9 (got $n)");
list($done, $left) = $m->scanBatch(50);
ok($done === 9 && $left === 0, 'scanned all');
$a = rec('a.com'); $am = Migrator::decode($a, 'mapping');
ok(!$a->blocking && $am['urls'][0]['www_mode'] === 'both' && $am['urls'][0]['wildcard'] === 1 && $am['urls'][0]['type'] === '301', 'a.com url mapped: ' . json_encode($am['urls']));
ok($am['emails'] === ['info' => ['x@gmail.com'], 'sales' => ['y@gmail.com', 'z@gmail.com']] && $am['catchall'] === 'catch@gmail.com', 'a.com email mapped');
ok(rec('b.com')->blocking && strpos(rec('b.com')->issues, 'fail') !== false, 'b.com blocked (fail forwarder)');
ok(rec('d.com')->blocking && strpos(rec('d.com')->issues, 'Not found in WHMCS') !== false, 'd.com blocked (not in WHMCS)');
ok(Migrator::decode(rec('c.com'), 'mapping')['parked'] === true, 'c.com parked-only');
$em = Migrator::decode(rec('e.com'), 'mapping');
ok($em['urls'][0]['preserve_query'] === 0 && $em['domain_forward'] === 'e2.com' && rec('e.com')->kind === 'addon', 'e.com addon mapped');
ok(Migrator::decode(rec('g.com'), 'mapping')['urls'][0]['www_mode'] === 'nowww', 'g.com www mode from probes');
ok(empty(FakeCpanel::$calls) || !array_intersect(['Park::unpark', 'AddonDomain::addaddondomain', 'Mime::delete_redirect'], FakeCpanel::$calls), 'scan made no changes');

// ---- import (dry run)
$ids = Capsule::table(Schema::T_MIGRATION)->whereIn('domain', ['a.com', 'b.com', 'c.com', 'e.com', 'f.com', 'g.com', 'h.com', 'k.com'])->pluck('id')->all();
list($imp) = $m->import($ids);
ok($imp === 7, "imported 7 (b.com blocked) got $imp");
ok(fwdOf('a.com')->status === 'migrating' && Capsule::table(Schema::T_EMAIL)->where('fwd_domain_id', fwdOf('a.com')->id)->count() === 2, 'a.com rules stored, status migrating');
ok(isset(FakeCpanel::$st['parked']['a.com']), 'server unchanged after import');
Service::cron();
ok(fwdOf('a.com')->status === 'migrating' && isset(FakeCpanel::$st['parked']['a.com']), 'cron ignores migrating');
try { Service::sync(fwdOf('a.com')); ok(false, 'sync blocked'); } catch (InvalidArgumentException $e) { ok(true, 'sync blocked'); }

// ---- move a, c, e
$opts = ['billing' => 'flag', 'accept_diff' => false];
$left = $m->moveBatch([rec('a.com')->id, rec('c.com')->id, rec('e.com')->id], $opts, 5);
ok($left === [], 'batch done');
ok(rec('a.com')->state === 'migrated', 'a.com migrated: ' . rec('a.com')->message);
ok(!isset(FakeCpanel::$st['parked']['a.com']) && strpos(FakeCpanel::$st['addon']['a.com']['dir'], 'seekahost_fwd/a.com') !== false, 'a.com now our addon');
ok(http('a.com', '/x/y?q=1') === [301, 'https://dest-a.com/x/y?q=1'] && http('www.a.com', '/') === [301, 'https://dest-a.com/'], 'a.com behaves the same');
$pairs = array_map(function ($p) { return implode('>', $p); }, FakeCpanel::$st['fwd']['a.com']); sort($pairs);
ok($pairs === ['info@a.com>x@gmail.com', 'sales@a.com>y@gmail.com', 'sales@a.com>z@gmail.com'] && FakeCpanel::$st['default']['a.com'] === 'catch@gmail.com', 'a.com email restored: ' . json_encode(FakeCpanel::$st['fwd']['a.com']));
ok(!array_filter(FakeCpanel::$st['redirects'], function ($r) { return $r['domain'] === 'a.com'; }), 'old a.com redirect cleaned up');
ok(fwdOf('a.com')->status === 'active', 'a.com active');
ok((bool) array_filter($GLOBALS['localapi'], function ($c) { return $c[0] === 'UpdateClientDomain' && $c[1]['emailforwarding'] === true && $c[1]['autorecalc'] === false; }), 'billing flag applied');
ok(rec('c.com')->state === 'migrated' && fwdOf('c.com')->parked == 1, 'c.com migrated as parked: ' . rec('c.com')->message);
ok(rec('e.com')->state === 'migrated' && FakeCpanel::$st['dfwd']['e.com'] === 'e2.com' && http('e.com', '/?a=b') === [301, 'https://e-dest.com/?ref=1'], 'e.com addon migrated: ' . rec('e.com')->message . ' ' . json_encode(http('e.com', '/?a=b')));

// ---- failure during provisioning -> automatic rollback
FakeCpanel::$failOn['Fileman::save_file_content'] = 'disk quota exceeded';
$m->moveBatch([rec('f.com')->id], $opts, 5);
unset(FakeCpanel::$failOn['Fileman::save_file_content']);
ok(rec('f.com')->state === 'rolled_back', 'f.com rolled back: ' . rec('f.com')->message);
ok(isset(FakeCpanel::$st['parked']['f.com']) && !isset(FakeCpanel::$st['addon']['f.com']), 'f.com parked again');
ok(http('f.com', '/') === [301, 'https://f-dest.com/'], 'f.com redirect works again');
ok(FakeCpanel::$st['fwd']['f.com'] === [['info@f.com', 'f@gmail.com']] && FakeCpanel::$st['default']['f.com'] === 'fcatch@gmail.com', 'f.com email restored: ' . json_encode([FakeCpanel::$st['fwd']['f.com'] ?? null, FakeCpanel::$st['default']['f.com'] ?? null]));
ok(fwdOf('f.com')->status === 'migrating', 'f.com back to migrating');
$m->moveBatch([rec('f.com')->id], $opts, 5);
ok(rec('f.com')->state === 'migrated', 'f.com retry migrated: ' . rec('f.com')->message);

// ---- verification mismatch -> rollback
Capsule::table(Schema::T_URL)->where('fwd_domain_id', fwdOf('g.com')->id)->update(['destination' => 'https://wrong.example/']);
$m->moveBatch([rec('g.com')->id], $opts, 5);
ok(rec('g.com')->state === 'rolled_back' && strpos(rec('g.com')->message, 'Verification failed') !== false, 'g.com mismatch rolled back: ' . rec('g.com')->message);
ok(http('g.com', '/p') === [302, 'https://g-dest.com/p'], 'g.com old behaviour back');

// ---- changed since scan -> nothing touched
FakeCpanel::$st['fwd']['h.com'][] = ['new@h.com', 'n@gmail.com']; FakeCpanel::persist();
$m->moveBatch([rec('h.com')->id], $opts, 5);
ok(rec('h.com')->state === 'failed' && strpos(rec('h.com')->message, 'changed since the scan') !== false && isset(FakeCpanel::$st['parked']['h.com']), 'h.com changed: not touched');
ok(Migrator::cancel([rec('h.com')->id]) === 1 && !fwdOf('h.com') && rec('h.com')->state === 'scanned', 'cancel import removes rules');

// ---- rollback that cannot complete
FakeCpanel::$failOn['Fileman::save_file_content'] = 'disk';
FakeCpanel::$failOn['Park::park'] = 'park refused';
$m->moveBatch([rec('k.com')->id], $opts, 5);
FakeCpanel::$failOn = [];
ok(rec('k.com')->state === 'rollback_failed' && strpos(rec('k.com')->message, 'ROLLBACK INCOMPLETE') !== false, 'k.com rollback_failed flagged: ' . rec('k.com')->message);
ok((bool) array_filter($GLOBALS['localapi'], function ($c) { return $c[0] === 'SendAdminEmail' && strpos($c[1]['custommessage'], 'k.com') !== false; }), 'admin alerted for incomplete rollback');

// ================= fixes: rebuild wait, pre-flight, exact label, re-check / mark restored
$more = ['m.com', 'n.com', 'p.com', 'q.com'];
foreach ($more as $d) {
    Capsule::table('tbldomains')->insert(['id' => ++$id, 'userid' => 1, 'domain' => $d, 'status' => 'Active', 'emailforwarding' => 0]);
}
FakeCpanel::$st['parked']['m.com'] = true;
FakeCpanel::$st['parked']['p.com'] = true;
FakeCpanel::$st['parked']['q.com'] = true;
FakeCpanel::$st['addon']['n.com'] = ['label' => 'n.com', 'dir' => 'public_html/n.com'];
@mkdir($work . '/home/public_html/n.com', 0777, true);
foreach (['m.com', 'n.com', 'p.com', 'q.com'] as $d) {
    FakeCpanel::$st['redirects'][] = ['domain' => $d, 'sourceurl' => '/', 'destination' => 'https://' . $d . '-dest.org/', 'type' => 'permanent', 'wildcard' => 0, 'matchwww' => 1];
}
FakeCpanel::persist();
$m = Migrator::forServer($sid); // new admin request: fresh view of the account
$m->discover();
$m->scanBatch(50);
$m->import(Capsule::table(Schema::T_MIGRATION)->whereIn('domain', $more)->pluck('id')->all());
ok(rec('m.com')->state === 'imported' && rec('n.com')->state === 'imported', 'more domains imported');

// Fix 1: Apache lag after the move must not cause a false rollback
FakeCpanel::$lag = 12;
$t = microtime(true);
$m->moveBatch([rec('m.com')->id], $opts, 1);
ok(rec('m.com')->state === 'migrated', 'm.com migrated despite Apache lag: ' . rec('m.com')->message);
ok(Migrator::decode(rec('m.com'), 'result')['after'][0]['module'] === true, 'verification saw the new setup (marker)');

// Fix 1 + 3: real mismatch on a dotted-label addon: rollback waits for the old setup and re-adds the exact label
Capsule::table(Schema::T_URL)->where('fwd_domain_id', fwdOf('n.com')->id)->update(['destination' => 'https://wrong.example/']);
$m->moveBatch([rec('n.com')->id], $opts, 1);
ok(rec('n.com')->state === 'rolled_back', 'n.com rolled back and verified despite lag: ' . rec('n.com')->message);
ok(isset(FakeCpanel::$st['addon']['n.com']) && FakeCpanel::$st['addon']['n.com']['label'] === 'n.com' && FakeCpanel::$st['addon']['n.com']['dir'] === 'public_html/n.com', 'n.com re-added with exact original label/dir: ' . json_encode(FakeCpanel::$st['addon']['n.com'] ?? null));
ok(SeekaHost\Forwarding\Migration\CpanelLegacy::originalLabel(['subdomain' => 'uniquebusinessidea.net', 'rootdomain' => 'x.com', 'fullsubdomain' => 'uniquebusinessidea.net.x.com']) === 'uniquebusinessidea.net', 'originalLabel keeps dots');
FakeCpanel::$lag = 0;

// Fix 2: pre-flight stops before any domain is touched
FakeCpanel::$failOn['AddonDomain::addaddondomain'] = 'You have exceeded the maximum allowed FTP (File Transfer Protocol) accounts.';
try {
    $m->moveBatch([rec('p.com')->id], $opts, 1);
    ok(false, 'pre-flight should block');
} catch (InvalidArgumentException $e) {
    ok(strpos($e->getMessage(), 'Pre-flight check failed') !== false, 'pre-flight blocks on limits');
}
ok(rec('p.com')->state === 'imported' && isset(FakeCpanel::$st['parked']['p.com']), 'p.com untouched by failed pre-flight');
FakeCpanel::$failOn['AddonDomain::addaddondomain'] = 'Sorry, the domain is not registered';
ok(strpos($m->preflight(), 'could not run') !== false, 'pre-flight non-limit error is only a note');
unset(FakeCpanel::$failOn['AddonDomain::addaddondomain']);
ok($m->preflight() === '' && !array_filter(array_keys(FakeCpanel::$st['addon']), function ($d) { return strpos($d, 'shfwd-preflight-') === 0; }), 'pre-flight cleans up its test domain');
ok(!empty(FakeCpanel::$st['ftp_deleted']), 'pre-flight removes its FTP account');

// Fix 4: re-check after a manual restore, and mark restored
ok(rec('k.com')->state === 'rollback_failed', 'k.com still rollback_failed');
list($okRc) = $m->recheck(rec('k.com'));
ok(!$okRc && strpos(rec('k.com')->message, 'not parked') !== false, 're-check reports k.com not restored: ' . rec('k.com')->message);
FakeCpanel::$st['parked']['k.com'] = true; // admin restores it by hand
FakeCpanel::$st['fwd']['k.com'] = [['info@k.com', 'k@gmail.com']];
FakeCpanel::persist();
list($okRc) = $m->recheck(rec('k.com'));
ok($okRc && rec('k.com')->state === 'rolled_back', 're-check confirms manual restore: ' . rec('k.com')->message);
$m->moveBatch([rec('k.com')->id], $opts, 1);
ok(rec('k.com')->state === 'migrated', 'k.com migrated after re-check: ' . rec('k.com')->message);
Capsule::table(Schema::T_MIGRATION)->where('domain', 'q.com')->update(['state' => 'rollback_failed']);
ok(Migrator::markRestored([rec('q.com')->id]) === 1 && rec('q.com')->state === 'rolled_back', 'mark restored');
$m->moveBatch([rec('q.com')->id], $opts, 1);
ok(rec('q.com')->state === 'migrated' && http('q.com', '/') === [301, 'https://q.com-dest.org/'], 'q.com migrated after mark restored');

// ---- access mode switch
Migrator::setAccessModeAll();
ok(SeekaHost\Forwarding\Settings::get('access_mode') === 'all', 'access mode all');

foreach (Capsule::table(Schema::T_MIGRATION)->orderBy("domain")->get() as $r) { echo str_pad($r->domain, 8), str_pad($r->state, 16), substr((string) $r->message, 0, 140), "\n"; }
echo "MIGRATION PASS $pass FAIL $fail\n";
