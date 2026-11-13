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
    if (!empty($GLOBALS['shfwd_stale'][$base]) && microtime(true) < $GLOBALS['shfwd_stale'][$base]) {
        usleep(20000);
        return [200, '', false]; // Apache still serving its default page
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
FakeCpanel::$lag = 1;
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
    $m->preflight();
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

// Addon domain whose internal name changed after the scan (e.g. restored by hand / older rollback)
Capsule::table('tbldomains')->insert(['id' => ++$id, 'userid' => 1, 'domain' => 'r.com', 'status' => 'Active', 'emailforwarding' => 0]);
FakeCpanel::$st['addon']['r.com'] = ['label' => 'r.com', 'dir' => 'public_html/r.com'];
@mkdir($work . '/home/public_html/r.com', 0777, true);
FakeCpanel::$st['redirects'][] = ['domain' => 'r.com', 'sourceurl' => '/', 'destination' => 'https://r-dest.org/', 'type' => 'permanent', 'wildcard' => 0, 'matchwww' => 1];
FakeCpanel::persist();
$m = Migrator::forServer($sid);
$m->discover();
$m->scanBatch(50);
$m->import([rec('r.com')->id]);
FakeCpanel::$st['addon']['r.com']['label'] = 'r'; // now r.main.com instead of r.com.main.com
FakeCpanel::persist();
Capsule::table(Schema::T_URL)->where('fwd_domain_id', fwdOf('r.com')->id)->update(['destination' => 'https://wrong.example/']);
$m->moveBatch([rec('r.com')->id], $opts, 1);
ok(rec('r.com')->state === 'rolled_back' && FakeCpanel::$st['addon']['r.com']['label'] === 'r', 'changed addon name: detach uses the live key, rollback restores the live name: ' . rec('r.com')->message);
Capsule::table(Schema::T_URL)->where('fwd_domain_id', fwdOf('r.com')->id)->update(['destination' => 'https://r-dest.org/']);
$m->moveBatch([rec('r.com')->id], $opts, 1);
ok(rec('r.com')->state === 'migrated' && http('www.r.com', '/') === [301, 'https://r-dest.org/'], 'changed addon name migrates: ' . rec('r.com')->message);
unset(FakeCpanel::$st['parked']['p.com']); FakeCpanel::persist();
$m->moveBatch([rec('p.com')->id], $opts, 1);
ok(rec('p.com')->state === 'failed' && strpos(rec('p.com')->message, 'not parked') !== false, 'missing domain: clean failure, nothing changed');

// ================= v1.3: in-place addon moves, verifying state, keep unrouted mail
$v13 = ['ip.com', 'ipx.com', 'v.com', 'vx.com', 'kd.com', 'nk.com'];
foreach ($v13 as $d) {
    Capsule::table('tbldomains')->insert(['id' => ++$id, 'userid' => 1, 'domain' => $d, 'status' => 'Active', 'emailforwarding' => 0]);
}
$origHt = "RewriteEngine on\nRewriteCond %{HTTP_HOST} ^ip\\.com$\nRewriteRule ^/?$ \"https://ip-dest.org/\" [R=301,L]\n\n"
    . "# php -- BEGIN cPanel-generated handler, do not edit\n<IfModule mime_module>\n  AddHandler application/x-httpd-ea-php82 .php\n</IfModule>\n# php -- END cPanel-generated handler, do not edit\n";
foreach (['ip.com', 'ipx.com'] as $d) {
    FakeCpanel::$st['addon'][$d] = ['label' => $d, 'dir' => 'public_html/' . $d];
    @mkdir($work . '/home/public_html/' . $d, 0777, true);
    file_put_contents($work . '/home/public_html/' . $d . '/.htaccess', $origHt);
    file_put_contents($work . '/home/public_html/' . $d . '/php.ini', 'display_errors=Off');
}
foreach (['v.com', 'vx.com', 'kd.com', 'nk.com'] as $d) {
    FakeCpanel::$st['parked'][$d] = true;
}
FakeCpanel::$st['default']['kd.com'] = 'fwduser';
FakeCpanel::$st['default']['nk.com'] = 'fwduser';
foreach ($v13 as $d) {
    FakeCpanel::$st['redirects'][] = ['domain' => $d, 'sourceurl' => '/', 'destination' => 'https://' . $d . '-dest.org/', 'type' => 'permanent', 'wildcard' => 0, 'matchwww' => 1];
}
FakeCpanel::persist();
$m = Migrator::forServer($sid);
$m->discover();
$m->scanBatch(50);
$m->import(Capsule::table(Schema::T_MIGRATION)->whereIn('domain', $v13)->pluck('id')->all());
ok(count(array_filter($v13, function ($d) { return rec($d)->state === 'imported'; })) === 6, 'v1.3 domains imported');

// 1. Addon in place: no domain removal, no Apache lag, cPanel blocks kept
FakeCpanel::$lag = 100;
FakeCpanel::$calls = [];
$t0 = microtime(true);
$m->moveBatch([rec('ip.com')->id], $opts, 1);
ok(rec('ip.com')->state === 'migrated' && strpos(rec('ip.com')->message, 'in place') !== false, 'ip.com migrated in place: ' . rec('ip.com')->message);
ok(!in_array('AddonDomain::deladdondomain', FakeCpanel::$calls, true) && !in_array('AddonDomain::addaddondomain', FakeCpanel::$calls, true), 'in place: domain never removed or re-added');
ok(microtime(true) - $t0 < 2.5, 'in place: no waiting for Apache');
$ht = file_get_contents($work . '/home/public_html/ip.com/.htaccess');
ok(strpos($ht, 'Generated by SeekaHost') !== false && strpos($ht, 'cPanel-generated handler') !== false, 'in place: cPanel PHP handler block kept in .htaccess');
ok(http('ip.com', '/') === [301, 'https://ip.com-dest.org/'] && http('www.ip.com', '/') === [301, 'https://ip.com-dest.org/'], 'ip.com redirect served by module');

// 1b. In-place rollback restores the original files exactly
Capsule::table(Schema::T_URL)->where('fwd_domain_id', fwdOf('ipx.com')->id)->update(['destination' => 'https://wrong.example/']);
$m->moveBatch([rec('ipx.com')->id], $opts, 1);
ok(rec('ipx.com')->state === 'rolled_back', 'ipx.com in-place rollback verified: ' . rec('ipx.com')->message);
ok(file_get_contents($work . '/home/public_html/ipx.com/.htaccess') === $origHt && !is_file($work . '/home/public_html/ipx.com/index.php')
    && is_file($work . '/home/public_html/ipx.com/php.ini'), 'ipx.com original .htaccess restored, module index.php removed, old files intact');
ok(fwdOf('ipx.com')->status === 'migrating' && isset(FakeCpanel::$st['addon']['ipx.com']), 'ipx.com still on the account, ready to retry');

// 2. Parked domain while Apache is slow: stays "verifying", finished by the cron
$m->moveBatch([rec('v.com')->id], $opts, 1);
ok(rec('v.com')->state === 'verifying' && fwdOf('v.com')->status === 'active', 'v.com left verifying (no rollback): ' . rec('v.com')->message);
ok(isset(FakeCpanel::$st['addon']['v.com']) && !isset(FakeCpanel::$st['parked']['v.com']), 'v.com new setup kept');
Migrator::verifyPendingAll();
ok(rec('v.com')->state === 'verifying' && strpos(rec('v.com')->message, 'Still waiting') !== false, 'cron: still waiting while Apache is slow');
$GLOBALS['shfwd_stale'] = []; // Apache has reloaded
Migrator::verifyPendingAll();
ok(rec('v.com')->state === 'migrated', 'cron finished v.com: ' . rec('v.com')->message);
ok(!array_filter(FakeCpanel::$st['redirects'], function ($r) { return $r['domain'] === 'v.com'; }), 'v.com old redirect cleaned up after verification');

// 2b. Verifying, then a real difference -> rollback
Capsule::table(Schema::T_URL)->where('fwd_domain_id', fwdOf('vx.com')->id)->update(['destination' => 'https://wrong.example/']);
$m->moveBatch([rec('vx.com')->id], $opts, 1);
ok(rec('vx.com')->state === 'verifying', 'vx.com verifying');
FakeCpanel::$lag = 0;
$GLOBALS['shfwd_stale'] = [];
list($done) = $m->recheck(rec('vx.com'));
ok(!$done && rec('vx.com')->state === 'rolled_back' && isset(FakeCpanel::$st['parked']['vx.com']) && http('vx.com', '/') === [301, 'https://vx.com-dest.org/'], 'vx.com mismatch rolled back via Re-check: ' . rec('vx.com')->message);

// D. Keep unrouted mail as before
$m->moveBatch([rec('kd.com')->id], $opts + ['keep_default' => true], 1);
ok(rec('kd.com')->state === 'migrated' && FakeCpanel::$st['default']['kd.com'] === 'fwduser' && fwdOf('kd.com')->keep_default === 'fwduser', 'kd.com unrouted mail kept: ' . json_encode(FakeCpanel::$st['default']['kd.com'] ?? null) . ' ' . rec('kd.com')->message);
Service::sync(fwdOf('kd.com'));
ok(FakeCpanel::$st['default']['kd.com'] === 'fwduser', 'kept default survives a later Sync');
$m->moveBatch([rec('nk.com')->id], $opts, 1);
ok(rec('nk.com')->state === 'migrated' && strpos(FakeCpanel::$st['default']['nk.com'], ':fail:') === 0, 'without D unknown addresses are rejected');
Service::setCatchAll(fwdOf('kd.com'), 'all@gmail.com');
ok(fwdOf('kd.com')->keep_default === null && FakeCpanel::$st['default']['kd.com'] === 'all@gmail.com', 'customer catch-all replaces the kept default');

// ================= v1.4: short steps, interrupted moves, folder safety, parking fallback
$v14 = ['web.com', 'sh1.com', 'sh2.com', 'int.com', 'int2.com', 'int3.com', 'int4.com', 'pp.com', 'rs.com'];
foreach ($v14 as $d) {
    Capsule::table('tbldomains')->insert(['id' => ++$id, 'userid' => 1, 'domain' => $d, 'status' => 'Active', 'emailforwarding' => 0]);
}
FakeCpanel::$st['addon']['web.com'] = ['label' => 'web.com', 'dir' => 'public_html/web.com'];
@mkdir($work . '/home/public_html/web.com', 0777, true);
file_put_contents($work . '/home/public_html/web.com/index.html', '<h1>a real site</h1>');
FakeCpanel::$st['addon']['sh1.com'] = ['label' => 'sh1.com', 'dir' => 'public_html/shared'];
FakeCpanel::$st['addon']['sh2.com'] = ['label' => 'sh2.com', 'dir' => 'public_html/shared'];
@mkdir($work . '/home/public_html/shared', 0777, true);
foreach (['int.com', 'int2.com', 'int3.com', 'int4.com', 'pp.com', 'rs.com'] as $d) {
    FakeCpanel::$st['parked'][$d] = true;
}
foreach (['web.com', 'sh1.com', 'sh2.com', 'int.com', 'int2.com', 'int3.com', 'int4.com', 'rs.com'] as $d) {
    FakeCpanel::$st['redirects'][] = ['domain' => $d, 'sourceurl' => '/', 'destination' => 'https://' . $d . '-dest.org/', 'type' => 'permanent', 'wildcard' => 0, 'matchwww' => 1];
}
FakeCpanel::$st['redirects'][] = ['domain' => 'pp.com', 'sourceurl' => '/blog', 'destination' => 'https://blog.pp-dest.org/', 'type' => 'permanent', 'wildcard' => 0, 'matchwww' => 1];
FakeCpanel::persist();
$m = Migrator::forServer($sid);
$m->discover();
$m->scanBatch(50);

// Risk 1: in-place moves blocked for website folders and shared folders
ok(rec('web.com')->blocking && strpos(rec('web.com')->issues, 'website files') !== false, 'web.com (real website) blocked: ' . rec('web.com')->issues);
ok(rec('sh1.com')->blocking && strpos(rec('sh1.com')->issues, 'shared with sh2.com') !== false, 'shared folder blocked: ' . rec('sh1.com')->issues);
ok(!rec('int.com')->blocking, 'parked domains not affected by the folder check');
$m->import(Capsule::table(Schema::T_MIGRATION)->whereIn('domain', ['int.com', 'int2.com', 'int3.com', 'int4.com', 'pp.com', 'rs.com'])->pluck('id')->all());

// C: pre-flight cleans up its own folder and older leftovers
@mkdir($work . '/home/public_html/seekahost_fwd/shfwd-preflight-old0000000.com', 0777, true);
ok($m->preflight() === '', 'pre-flight ok');
ok(!glob($work . '/home/public_html/seekahost_fwd/shfwd-preflight-*'), 'pre-flight folders cleaned up: ' . json_encode(glob($work . '/home/public_html/seekahost_fwd/shfwd-preflight-*')));

// C: a request cut off after provisioning -> Resume finishes it
Migrator::$haltAfter = 'provision';
$m->moveBatch([rec('int.com')->id], $opts, 1);
Migrator::$haltAfter = null;
ok(rec('int.com')->state === 'moving' && !Migrator::isInterrupted(rec('int.com')), 'int.com stuck in moving (not yet flagged)');
Capsule::table(Schema::T_MIGRATION)->where('domain', 'int.com')->update(['updated_at' => date('Y-m-d H:i:s', time() - 600)]);
ok(Migrator::isInterrupted(rec('int.com')), 'int.com flagged interrupted');
list($okR, $msgR) = $m->resume(rec('int.com'));
ok($okR && rec('int.com')->state === 'migrated' && http('int.com', '/') === [301, 'https://int.com-dest.org/'], 'Resume finished int.com: ' . $msgR);

// C: cut off after provisioning -> Undo restores the old setup
Migrator::$haltAfter = 'provision';
$m->moveBatch([rec('int2.com')->id], $opts, 1);
Migrator::$haltAfter = null;
Capsule::table(Schema::T_MIGRATION)->where('domain', 'int2.com')->update(['updated_at' => date('Y-m-d H:i:s', time() - 600)]);
list($okU, $msgU) = $m->undoInterrupted(rec('int2.com'));
ok($okU && rec('int2.com')->state === 'rolled_back' && isset(FakeCpanel::$st['parked']['int2.com']) && !isset(FakeCpanel::$st['addon']['int2.com'])
    && http('int2.com', '/') === [301, 'https://int2.com-dest.org/'], 'Undo restored int2.com: ' . $msgU);

// C: interrupted by an older version (row still "imported", domain already moved) -> Resume
$f3 = fwdOf('int3.com');
$legacy = new SeekaHost\Forwarding\Migration\CpanelLegacy(new FakeCpanel(Capsule::table(Schema::T_SERVERS)->where('id', $sid)->first()));
$legacy->detach('int3.com', 'parked', null);
Capsule::table(Schema::T_DOMAINS)->where('id', $f3->id)->update(['status' => 'pending']);
Service::provision(Service::reload($f3));
ok(rec('int3.com')->state === 'imported' && Migrator::isInterrupted(rec('int3.com')), 'legacy interrupted move detected (like transcendcbd.net)');
list($okL, $msgL) = $m->resume(rec('int3.com'));
ok($okL && rec('int3.com')->state === 'migrated', 'Resume completed the legacy interrupted move: ' . $msgL);

// C: cut off right after unparking -> Resume provisions and verifies
Migrator::$haltAfter = 'detach';
$m->moveBatch([rec('int4.com')->id], $opts, 1);
Migrator::$haltAfter = null;
ok(!isset(FakeCpanel::$st['parked']['int4.com']) && !isset(FakeCpanel::$st['addon']['int4.com']), 'int4.com detached and nothing else');
Capsule::table(Schema::T_MIGRATION)->where('domain', 'int4.com')->update(['updated_at' => date('Y-m-d H:i:s', time() - 600)]);
list($okD, $msgD) = $m->resume(rec('int4.com'));
ok($okD && rec('int4.com')->state === 'migrated' && http('int4.com', '/') === [301, 'https://int4.com-dest.org/'], 'Resume after detach: ' . $msgD);

// C: a rollback whose old setup is not live yet becomes "restoring", finished later
Capsule::table(Schema::T_URL)->where('fwd_domain_id', fwdOf('rs.com')->id)->update(['destination' => 'https://wrong.example/']);
FakeCpanel::$lag = 0;
$GLOBALS['shfwd_stale'] = [];
$m->moveBatch([rec('rs.com')->id], $opts, 1); // mismatch found immediately -> rollback
ok(in_array(rec('rs.com')->state, ['rolled_back', 'restoring'], true), 'rs.com rolled back: ' . rec('rs.com')->state);
Capsule::table(Schema::T_MIGRATION)->where('domain', 'rs.com')->update(['state' => 'rolled_back']);
FakeCpanel::$lag = 100; // web server slow to load the restored setup
$m->moveBatch([rec('rs.com')->id], $opts, 1);
ok(rec('rs.com')->state === 'verifying', 'rs.com verifying (slow web server)');
$GLOBALS['shfwd_stale'] = []; // new setup live -> mismatch -> rollback, old setup slow to come back
FakeCpanel::$lag = 100;
Migrator::verifyPendingAll();
ok(rec('rs.com')->state === 'restoring', 'rs.com restoring while the old setup is not live: ' . rec('rs.com')->message);
$GLOBALS['shfwd_stale'] = [];
FakeCpanel::$lag = 0;
Migrator::verifyPendingAll();
ok(rec('rs.com')->state === 'rolled_back' && http('rs.com', '/') === [301, 'https://rs.com-dest.org/'], 'rs.com restored and verified later: ' . rec('rs.com')->message);

// Risk 3: unmatched paths show the parking page
$m->moveBatch([rec('pp.com')->id], $opts, 1);
ok(rec('pp.com')->state === 'migrated', 'pp.com migrated: ' . rec('pp.com')->message);
ok(http('pp.com', '/blog') === [301, 'https://blog.pp-dest.org/'] && http('pp.com', '/')[0] === 200 && http('pp.com', '/nothing-here')[0] === 404, 'parking page for unmatched paths (200 on /, 404 elsewhere)');

// Risk 2: a failing .htaccess read never blocks a normal deploy
FakeCpanel::$failOn['Fileman::get_file_content'] = 'Unexpected cPanel answer';
Service::saveUrl(fwdOf('a.com'), ['source_path' => '/shop', 'destination' => 'https://shop.example.org/']);
unset(FakeCpanel::$failOn['Fileman::get_file_content']);
ok(http('a.com', '/shop') === [301, 'https://shop.example.org/'], 'normal deploy works even if reading .htaccess fails');

// ---- access mode switch
Migrator::setAccessModeAll();
ok(SeekaHost\Forwarding\Settings::get('access_mode') === 'all', 'access mode all');

foreach (Capsule::table(Schema::T_MIGRATION)->orderBy("domain")->get() as $r) { echo str_pad($r->domain, 8), str_pad($r->state, 16), substr((string) $r->message, 0, 140), "\n"; }
echo "MIGRATION PASS $pass FAIL $fail\n";
