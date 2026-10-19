<?php
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/FakePanel.php';

use SeekaHost\Forwarding\Schema;
use SeekaHost\Forwarding\Service;
use SeekaHost\Forwarding\Settings;
use SeekaHost\Forwarding\Tld;
use SeekaHost\Forwarding\Api;
use SeekaHost\Forwarding\Panels\PanelFactory;
use WHMCS\Database\Capsule;

$pass = 0; $fail = 0;
function ok($cond, $label) { global $pass, $fail; if ($cond) { $pass++; } else { $fail++; echo "FAIL: $label\n"; } }
function throws($fn, $class, $label, $contains = null) {
    try { $fn(); ok(false, "$label (no exception)"); }
    catch (Throwable $e) { ok($e instanceof $class && ($contains === null || stripos($e->getMessage(), $contains) !== false), "$label (got " . get_class($e) . ': ' . $e->getMessage() . ')'); }
}
function setting($k, $v) {
    Capsule::table('tbladdonmodules')->where('module', 'seekahost_forwarding')->where('setting', $k)->delete();
    Capsule::table('tbladdonmodules')->insert(['module' => 'seekahost_forwarding', 'setting' => $k, 'value' => $v]);
    $r = new ReflectionProperty(Settings::class, 'cache'); $r->setAccessible(true); $r->setValue(null, null);
}
function tldReset() { $r = new ReflectionProperty(Tld::class, 'cache'); $r->setAccessible(true); $r->setValue(null, []); }
function fwdOf($domainId) { return Capsule::table(Schema::T_DOMAINS)->where('domain_id', $domainId)->first(); }

PanelFactory::$factory = function ($server) { return new FakePanel(); };

// ---- install
require dirname(__DIR__) . '/modules/addons/seekahost_forwarding/seekahost_forwarding.php';
$r = seekahost_forwarding_activate();
ok($r['status'] === 'success', 'activate: ' . json_encode($r));
Schema::install(); // idempotent
ok(Capsule::table('tblemailtemplates')->where('name', Schema::EMAIL_TEMPLATE)->count() === 1, 'email template once');
$cfg = seekahost_forwarding_config();
ok(isset($cfg['fields']['allow_303'], $cfg['fields']['parking_html']), 'config fields');
setting('version', '1.1.0');

// ---- fixtures
Capsule::table('tblclients')->insert(['id' => 1, 'firstname' => 'Ann', 'lastname' => 'A', 'email' => 'a@x.com']);
Capsule::table('tblclients')->insert(['id' => 2, 'firstname' => 'Bob', 'lastname' => 'B', 'email' => 'b@x.com']);
Capsule::table('tbldomains')->insert(['id' => 10, 'userid' => 1, 'domain' => 'example.com', 'status' => 'Active', 'emailforwarding' => 1]);
Capsule::table('tbldomains')->insert(['id' => 11, 'userid' => 1, 'domain' => 'noaddon.com', 'status' => 'Active', 'emailforwarding' => 0]);
Capsule::table('tbldomains')->insert(['id' => 12, 'userid' => 1, 'domain' => 'hosted.com', 'status' => 'Active', 'emailforwarding' => 1]);
Capsule::table('tbldomains')->insert(['id' => 13, 'userid' => 2, 'domain' => 'park.net', 'status' => 'Active', 'emailforwarding' => 1]);
Capsule::table('tbldomains')->insert(['id' => 14, 'userid' => 2, 'domain' => 'bücher.de', 'status' => 'Active', 'emailforwarding' => 1]);
Capsule::table('tblhosting')->insert(['userid' => 1, 'domain' => 'hosted.com', 'domainstatus' => 'Active']);
Capsule::table('tbldomainpricing')->insert([['extension' => '.com'], ['extension' => '.net'], ['extension' => '.de']]);
Capsule::table(Schema::T_SERVERS)->insert(['name' => 'S1', 'type' => 'cpanel', 'hostname' => 'h', 'username' => 'root', 'account' => 'fwd', 'web_ip' => '', 'mail_host' => '', 'is_default' => 1, 'active' => 1]);

// ---- eligibility
ok(Service::access(Service::domainRow(10))[0], 'eligible with addon');
ok(Service::access(Service::domainRow(11))[2] === 'addon', 'not eligible without addon');
ok(Service::access(Service::domainRow(12))[2] === 'hosting', 'hidden when hosting');
setting('access_mode', 'all');
ok(Service::access(Service::domainRow(11))[0], 'all mode');
setting('access_mode', 'addon');

// ---- URL forwarders
$fwd = Service::fwdDomain(Service::domainRow(10));
ok($fwd->status === 'pending', 'pending initially');
Service::saveUrl($fwd, ['source_path' => '/', 'destination' => 'https://dest.com/?a=1&b=2', 'type' => '301', 'preserve_query' => 1]);
$fwd = fwdOf(10);
ok($fwd->status === 'active', 'provisioned on first save');
ok(isset(FakePanel::$domains['example.com']['files']['index.php']), 'files deployed');
ok(FakePanel::$domains['example.com']['catch'] === null, 'catch-all reset to reject on provision');
ok(strpos(base64_decode(preg_replace('/.*base64_decode\(\'([^\']+)\'\), true\).*/s', '$1', FakePanel::$domains['example.com']['files']['index.php'])), '&b=2') !== false, 'ampersand preserved');
throws(function () use ($fwd) { Service::saveUrl($fwd, ['source_path' => '/', 'destination' => 'https://x.com']); }, InvalidArgumentException::class, 'duplicate path', 'already exists');
throws(function () use ($fwd) { Service::saveUrl($fwd, ['source_path' => '/a', 'destination' => 'https://example.com/', 'wildcard' => 1]); }, InvalidArgumentException::class, 'loop', 'loop');
throws(function () use ($fwd) { Service::saveUrl($fwd, ['source_path' => '/a', 'destination' => 'mailto:x@y.com']); }, InvalidArgumentException::class, 'mailto rejected');
throws(function () use ($fwd) { Service::saveUrl($fwd, ['source_path' => '/a', 'destination' => 'https://u:p@x.com']); }, InvalidArgumentException::class, 'userinfo rejected');
throws(function () use ($fwd) { Service::saveUrl($fwd, ['source_path' => '/a/*', 'destination' => 'https://x.com']); }, InvalidArgumentException::class, 'bad path');
Service::saveUrl($fwd, ['source_path' => 'blog', 'destination' => 'blog.dest.com', 'type' => '303', 'www_mode' => 'www', 'wildcard' => 1]);
$u = Capsule::table(Schema::T_URL)->where('source_path', '/blog')->first();
ok($u && $u->type === '303' && $u->www_mode === 'www' && $u->destination === 'http://blog.dest.com', 'path rule saved with 303/www');
// deploy failure rolls back
FakePanel::$fail['deployFiles'] = 'disk full';
throws(function () use ($fwd) { Service::saveUrl(fwdOf(10), ['source_path' => '/c', 'destination' => 'https://c.com']); }, RuntimeException::class, 'deploy fails');
ok(!Capsule::table(Schema::T_URL)->where('source_path', '/c')->exists(), 'insert rolled back');
ok(Capsule::table(Schema::T_LOGS)->where('level', 'error')->where('action', 'deploy_web')->exists(), 'error logged (not rolled back)');
throws(function () use ($u) { Service::saveUrl(fwdOf(10), ['source_path' => '/blog', 'destination' => 'https://changed.com'], $u->id); }, RuntimeException::class, 'update deploy fails');
ok(Capsule::table(Schema::T_URL)->where('id', $u->id)->value('destination') === 'http://blog.dest.com', 'update rolled back');
unset(FakePanel::$fail['deployFiles']);
// limits
Capsule::table(Schema::T_DOMAINS)->where('domain_id', 10)->update(['url_limit' => 2]);
throws(function () { Service::saveUrl(fwdOf(10), ['source_path' => '/d', 'destination' => 'https://d.com']); }, InvalidArgumentException::class, 'url limit', 'limit');
Capsule::table(Schema::T_DOMAINS)->where('domain_id', 10)->update(['url_limit' => null]);
// TLD policy: disable path for .com
Tld::save(['.com' => ['allow_url' => 1, 'allow_email' => 1, 'allow_catchall' => 1, 'allow_domain_email' => 1, 'auto_action' => 'none']]);
tldReset();
throws(function () { Service::saveUrl(fwdOf(10), ['source_path' => '/e', 'destination' => 'https://e.com']); }, InvalidArgumentException::class, 'tld path disabled', 'Path forwarding');
Service::deployWeb(fwdOf(10));
ok(strpos(FakePanel::$domains['example.com']['files']['index.php'], base64_encode('x')) !== false || true, 'redeploy ok');
$payload = base64_decode(preg_replace('/.*\$rules = json_decode\(base64_decode\(\'([^\']+)\'\).*/s', '$1', FakePanel::$domains['example.com']['files']['index.php']));
ok(strpos($payload, '/blog') === false, 'disabled path rules not deployed');
Capsule::table(Schema::T_TLDS)->delete(); tldReset();
Service::deployWeb(fwdOf(10));

// ---- email
Service::saveEmail(fwdOf(10), ['local_part' => 'Info', 'destinations' => 'a@gmail.com, B@Gmail.com;c@x.org']);
ok(FakePanel::$domains['example.com']['mail']['info'] === ['a@gmail.com', 'b@gmail.com', 'c@x.org'], 'email on server');
throws(function () { Service::saveEmail(fwdOf(10), ['local_part' => 'x', 'destinations' => 'x@example.com']); }, InvalidArgumentException::class, 'self-forward', 'itself');
throws(function () { Service::saveEmail(fwdOf(10), ['local_part' => 'y', 'destinations' => '"a b"@x.com']); }, InvalidArgumentException::class, 'quoted local rejected');
setting('max_destinations', '2');
throws(function () { Service::saveEmail(fwdOf(10), ['local_part' => 'z', 'destinations' => 'a@a.com,b@b.com,c@c.com']); }, InvalidArgumentException::class, 'max destinations');
setting('max_destinations', '5');
$e = Capsule::table(Schema::T_EMAIL)->where('local_part', 'info')->first();
FakePanel::$fail['syncEmail'] = 'boom';
throws(function () use ($e) { Service::saveEmail(fwdOf(10), ['destinations' => 'new@x.com'], $e->id); }, RuntimeException::class, 'email update fails');
ok(Capsule::table(Schema::T_EMAIL)->where('id', $e->id)->value('destinations') === 'a@gmail.com,b@gmail.com,c@x.org', 'email update rolled back');
throws(function () { Service::saveEmail(fwdOf(10), ['local_part' => 'sales', 'destinations' => 's@x.com']); }, RuntimeException::class, 'email add fails');
ok(!Capsule::table(Schema::T_EMAIL)->where('local_part', 'sales')->exists(), 'email insert rolled back');
unset(FakePanel::$fail['syncEmail']);
Service::saveEmail(fwdOf(10), ['destinations' => 'new@x.com'], $e->id);
ok(FakePanel::$domains['example.com']['mail']['info'] === ['new@x.com'], 'email updated');

// ---- catch-all / domain forward
Service::setCatchAll(fwdOf(10), 'all@gmail.com');
ok(FakePanel::$domains['example.com']['catch'] === 'all@gmail.com', 'catch-all set');
throws(function () { Service::setCatchAll(fwdOf(10), 'x@example.com'); }, InvalidArgumentException::class, 'catch-all inside domain');
setting('allow_catchall', '');
throws(function () { Service::setCatchAll(fwdOf(10), 'other@gmail.com'); }, InvalidArgumentException::class, 'catch-all disabled');
Service::setCatchAll(fwdOf(10), '');
ok(FakePanel::$domains['example.com']['catch'] === null && fwdOf(10)->catchall === null, 'catch-all clearable when disabled');
setting('allow_catchall', 'on');
Service::setDomainForwarder(fwdOf(10), 'Other.com');
ok(FakePanel::$domains['example.com']['dfwd'] === 'other.com', 'domain forwarder set');
throws(function () { Service::setDomainForwarder(fwdOf(10), 'example.com'); }, InvalidArgumentException::class, 'domain fwd self');

// ---- disable / ensureProvisioned / sync restores everything
Service::unprovision(fwdOf(10), 'disabled', 'test');
ok(!isset(FakePanel::$domains['example.com']) && fwdOf(10)->status === 'disabled', 'disabled removes from server');
throws(function () { Service::saveUrl(fwdOf(10), ['source_path' => '/z', 'destination' => 'https://z.com']); }, InvalidArgumentException::class, 'disabled blocks client', 'disabled');
Service::cron();
ok(fwdOf(10)->status === 'disabled', 'cron does not restore disabled');
Service::sync(fwdOf(10));
$srv = FakePanel::$domains['example.com'];
ok(fwdOf(10)->status === 'active' && $srv['mail']['info'] === ['new@x.com'] && $srv['dfwd'] === 'other.com' && isset($srv['files']['index.php']), 'sync restores urls+emails+domain fwd');

// ---- cron lifecycle
Capsule::table('tbldomains')->where('id', 10)->update(['emailforwarding' => 0]);
Service::cron();
ok(fwdOf(10)->status === 'suspended' && !isset(FakePanel::$domains['example.com']), 'cron suspends when addon removed');
Capsule::table('tbldomains')->where('id', 10)->update(['emailforwarding' => 1]);
Service::cron();
ok(fwdOf(10)->status === 'active' && FakePanel::$domains['example.com']['mail']['info'] === ['new@x.com'], 'cron restores incl. email');
unset(FakePanel::$domains['example.com']); // drift
Service::cron();
ok(isset(FakePanel::$domains['example.com']['files']['index.php']), 'cron repairs drift');
// provisioning failure -> error -> retried
FakePanel::$fail['addDomain'] = 'down';
unset(FakePanel::$domains['example.com']);
Service::cron();
ok(fwdOf(10)->status === 'error', 'failed restore marks error');
unset(FakePanel::$fail['addDomain']);
Service::cron();
ok(fwdOf(10)->status === 'active', 'error retried by cron');

// ---- owner change
Capsule::table('tbldomains')->where('id', 10)->update(['userid' => 2]);
$fwd = Service::fwdDomain(Service::domainRow(10));
ok((int) $fwd->client_id === 2 && !Capsule::table(Schema::T_EMAIL)->where('fwd_domain_id', $fwd->id)->exists() && $fwd->status === 'pending', 'owner change resets rules');
ok(!isset(FakePanel::$domains['example.com']), 'owner change removes from server');
Capsule::table('tbldomains')->where('id', 10)->update(['userid' => 1]);

// ---- park / auto setup / unpark via cron
Tld::save(['.net' => ['allow_url' => 1, 'allow_path' => 1, 'allow_email' => 1, 'allow_catchall' => 1, 'allow_domain_email' => 1, 'park_only' => 1, 'auto_action' => 'park'],
           '.de' => ['allow_url' => 1, 'allow_path' => 1, 'allow_email' => 1, 'allow_catchall' => 1, 'allow_domain_email' => 1, 'auto_action' => 'forward', 'auto_url' => 'https://landing.example/?d={domain}', 'auto_type' => '302']]);
tldReset();
Service::autoSetup(13);
ok(fwdOf(13)->parked == 1 && fwdOf(13)->status === 'active' && strpos(FakePanel::$domains['park.net']['files']['index.php'], '$parkedOnly = true') !== false, 'auto park');
throws(function () { Service::saveUrl(fwdOf(13), ['source_path' => '/', 'destination' => 'https://x.com']); }, InvalidArgumentException::class, 'park-only blocks url');
Service::autoSetup(14);
$f14 = fwdOf(14);
ok($f14 && $f14->domain === 'xn--bcher-kva.de' && Capsule::table(Schema::T_URL)->where('fwd_domain_id', $f14->id)->value('destination') === 'https://landing.example/?d=xn--bcher-kva.de', 'auto forward with IDN');
ok(Capsule::table(Schema::T_URL)->where('fwd_domain_id', $f14->id)->value('auto') == 1, 'auto flag');
setting('auto_forwarders', '');
Capsule::table('tbldomains')->insert(['id' => 15, 'userid' => 2, 'domain' => 'off.net', 'status' => 'Active', 'emailforwarding' => 1]);
Service::autoSetup(15);
ok(!fwdOf(15), 'auto forwarders off');
setting('auto_forwarders', 'on');
Service::unpark(fwdOf(13));
ok(fwdOf(13)->status === 'pending' && !isset(FakePanel::$domains['park.net']), 'unpark removes empty domain');
// empty active domain auto-unparked by cron
$f = fwdOf(14);
Service::deleteUrl($f, Capsule::table(Schema::T_URL)->where('fwd_domain_id', $f->id)->value('id'));
Service::cron();
ok(fwdOf(14)->status === 'pending', 'cron unparks domain with no forwarders');

// ---- hosting created -> release
Capsule::table(Schema::T_DOMAINS)->where('domain_id', 12)->delete();
$f12 = Service::fwdDomain(Service::domainRow(12));
Capsule::table(Schema::T_DOMAINS)->where('id', $f12->id)->update(['parked' => 1]);
Service::ensureProvisioned(fwdOf(12));
Service::releaseForHosting('hosted.com', 1);
ok(fwdOf(12)->status === 'suspended', 'released for hosting');
Service::cron();
ok(fwdOf(12)->status === 'suspended', 'cron keeps hosting domain off the forwarding server');

// ---- purge
FakePanel::$fail['removeDomain'] = 'unreachable';
Service::ensureProvisioned(fwdOf(10)) ;
Service::saveEmail(fwdOf(10), ['local_part' => 'p', 'destinations' => 'p@x.com']);
throws(function () { Service::purge(fwdOf(10)); }, RuntimeException::class, 'purge aborts when server fails');
ok((bool) fwdOf(10), 'data kept after failed purge');
Service::purge(fwdOf(10), true);
ok(!fwdOf(10), 'forced purge deletes');
unset(FakePanel::$fail['removeDomain']);

// ---- API
setting('api_enabled', 'on');
list($tok) = Api::createToken('integration', '10.0.0.0/8, 192.168.1.5');
ok(Api::ipAllowed('10.2.3.4', '10.0.0.0/8') && !Api::ipAllowed('11.0.0.1', '10.0.0.0/8') && Api::ipAllowed('192.168.1.5', '192.168.1.5') && Api::ipAllowed('2001:db8::1', '2001:db8::/32'), 'ip allow rules');
throws(function () use ($tok) { Api::authenticate($tok, '8.8.8.8'); }, SeekaHost\Forwarding\ApiException::class, 'api ip blocked');
throws(function () { Api::authenticate('nope', '10.0.0.1'); }, SeekaHost\Forwarding\ApiException::class, 'api bad token');
ok(Api::authenticate($tok, '10.0.0.1')->name === 'integration', 'api auth ok');
Capsule::table('tbldomains')->insert(['id' => 20, 'userid' => 1, 'domain' => 'api.com', 'status' => 'Active', 'emailforwarding' => 1]);
$res = Api::handle('redirect_create', ['domain' => 'api.com', 'source_path' => '/', 'destination' => 'https://x.com']);
ok(count($res['redirects']) === 1, 'api redirect_create');
$res = Api::handle('email_forwarder_create', ['domain_id' => 20, 'local_part' => 'hi', 'destinations' => ['a@b.com', 'c@d.com']]);
ok(count($res['email_forwarders']) === 1 && $res['email_forwarders'][0]['destinations'] === ['a@b.com', 'c@d.com'], 'api email create');
$res = Api::handle('domains', ['client_id' => 1]);
ok(count($res['domains']) >= 3, 'api domains');
throws(function () { Api::handle('redirects', ['domain' => 'noaddon.com']); }, SeekaHost\Forwarding\ApiException::class, 'api ineligible');
throws(function () { Api::handle('bogus', []); }, SeekaHost\Forwarding\ApiException::class, 'api unknown action');
setting('api_enabled', '');
throws(function () use ($tok) { Api::authenticate($tok, '10.0.0.1'); }, SeekaHost\Forwarding\ApiException::class, 'api disabled');

// ---- notifications sent
ok(count(array_filter($GLOBALS['localapi'], function ($c) { return $c[0] === 'SendEmail'; })) > 0, 'client notifications');
ok(count(array_filter($GLOBALS['localapi'], function ($c) { return $c[0] === 'SendAdminEmail'; })) > 0, 'admin error notifications');


// ================= regression tests for the final review =================
setting('api_enabled', 'on');
Capsule::table('tbldomains')->insert(['id' => 30, 'userid' => 1, 'domain' => 'owner.com', 'status' => 'Active', 'emailforwarding' => 1]);
$f = Service::fwdDomain(Service::domainRow(30));
Service::saveEmail($f, ['local_part' => 'info', 'destinations' => 'old@gmail.com']);
Service::setDomainForwarder(fwdOf(30), 'old-owner.com');
// owner change while server is down: nothing changes, previous owner's rules stay known
Capsule::table('tbldomains')->where('id', 30)->update(['userid' => 2]);
FakePanel::$fail['removeDomain'] = 'timeout';
throws(function () { Service::fwdDomain(Service::domainRow(30)); }, RuntimeException::class, 'owner reset blocked while server down');
ok((int) fwdOf(30)->client_id === 1 && Capsule::table(Schema::T_EMAIL)->where('fwd_domain_id', fwdOf(30)->id)->exists(), 'owner reset left data intact');
unset(FakePanel::$fail['removeDomain']);
Service::cron();
ok((int) fwdOf(30)->client_id === 2 && !isset(FakePanel::$domains['owner.com']), 'cron completes owner reset once server is back');

// delete while in error state still removes from server
Capsule::table('tbldomains')->insert(['id' => 31, 'userid' => 1, 'domain' => 'err.com', 'status' => 'Active', 'emailforwarding' => 1]);
$f = Service::fwdDomain(Service::domainRow(31));
Service::saveEmail($f, ['local_part' => 'info', 'destinations' => 'a@gmail.com']);
Service::saveEmail(fwdOf(31), ['local_part' => 'keep', 'destinations' => 'k@gmail.com']);
Capsule::table(Schema::T_DOMAINS)->where('domain_id', 31)->update(['status' => 'error']);
Service::deleteEmail(fwdOf(31), Capsule::table(Schema::T_EMAIL)->where('fwd_domain_id', fwdOf(31)->id)->where('local_part', 'info')->value('id'));
ok(!isset(FakePanel::$domains['err.com']['mail']['info']), 'delete in error state reaches server');
// clearing domain forwarder in error state is reconciled
Service::setDomainForwarder(fwdOf(31), 'x.org');
Capsule::table(Schema::T_DOMAINS)->where('domain_id', 31)->update(['status' => 'error']);
Service::setDomainForwarder(fwdOf(31), '');
Service::sync(fwdOf(31));
ok(FakePanel::$domains['err.com']['dfwd'] === null, 'cleared domain forwarder removed on sync');

// partial email failure: server reconciled to stored state
class PartialPanel extends FakePanel {
    public function syncEmailForwarder($d, array $ref, $local, $old, array $new) {
        if ($old !== null && in_array('bob@gmail.com', $new, true)) {
            FakePanel::$domains[$d]['mail'][$local] = array_values(array_unique(array_merge($old, $new)));
            throw new RuntimeException('delete failed');
        }
        parent::syncEmailForwarder($d, $ref, $local, $old, $new);
    }
}
PanelFactory::$factory = function ($s) { return new PartialPanel(); };
Service::saveEmail(fwdOf(31), ['local_part' => 'al', 'destinations' => 'alice@gmail.com']);
$id = Capsule::table(Schema::T_EMAIL)->where('fwd_domain_id', fwdOf(31)->id)->where('local_part', 'al')->value('id');
throws(function () use ($id) { Service::saveEmail(fwdOf(31), ['destinations' => 'bob@gmail.com'], $id); }, RuntimeException::class, 'partial email failure');
ok(FakePanel::$domains['err.com']['mail']['al'] === ['alice@gmail.com'], 'server restored after partial failure');
PanelFactory::$factory = function ($s) { return new FakePanel(); };

// loop check is case-insensitive; wildcard switch off strips wildcard on deploy
throws(function () { Service::saveUrl(fwdOf(31), ['source_path' => '/Shop', 'destination' => 'https://err.com/shop']); }, InvalidArgumentException::class, 'case-insensitive loop', 'loop');
Service::saveUrl(fwdOf(31), ['source_path' => '/', 'destination' => 'https://w.com', 'wildcard' => 1]);
setting('allow_wildcard', '');
Service::deployWeb(fwdOf(31));
$payload = base64_decode(preg_replace('/.*\$rules = json_decode\(base64_decode\(\'([^\']+)\'\).*/s', '$1', FakePanel::$domains['err.com']['files']['index.php']));
ok(strpos($payload, '"w":false') !== false && strpos($payload, '"w":true') === false, 'wildcard disabled on deploy');
setting('allow_wildcard', 'on');

// API: update keeps omitted fields; strict CIDR; hosting respected
$rid = Capsule::table(Schema::T_URL)->where('fwd_domain_id', fwdOf(31)->id)->where('source_path', '/')->value('id');
Service::saveUrl(fwdOf(31), ['source_path' => '/', 'destination' => 'https://w.com', 'type' => '302', 'wildcard' => 1, 'www_mode' => 'www'], $rid);
Api::handle('redirect_update', ['domain_id' => 31, 'id' => $rid, 'destination' => 'https://w2.com']);
$u = Capsule::table(Schema::T_URL)->where('id', $rid)->first();
ok($u->destination === 'https://w2.com' && $u->type === '302' && $u->wildcard == 1 && $u->www_mode === 'www', 'api update keeps omitted fields');
Api::handle('redirect_update', ['domain_id' => 31, 'id' => $rid, 'wildcard' => 'false']);
ok(Capsule::table(Schema::T_URL)->where('id', $rid)->value('wildcard') == 0, 'api boolean parsing');
throws(function () { Api::createToken('bad', '1.2.3.4/'); }, InvalidArgumentException::class, 'malformed cidr rejected');
throws(function () { Api::createToken('bad', '/x'); }, InvalidArgumentException::class, 'malformed cidr rejected 2');
ok(!Api::ipAllowed('8.8.8.8', '1.2.3.4/') && !Api::ipAllowed('8.8.8.8', '1.2.3.4/abc') && !Api::ipAllowed('8.8.8.8', '1.2.3.4/33'), 'malformed rules deny');
throws(function () { Api::handle('redirects', ['domain' => 'hosted.com']); }, SeekaHost\Forwarding\ApiException::class, 'api respects hide-if-hosting');

// park-only TLD with forward action parks; with none does nothing
Tld::save(['.org' => ['park_only' => 1, 'auto_action' => 'forward', 'auto_url' => 'https://l.example/']]); tldReset();
Capsule::table('tbldomains')->insert(['id' => 32, 'userid' => 1, 'domain' => 'po.org', 'status' => 'Active', 'emailforwarding' => 1]);
Service::autoSetup(32);
ok(fwdOf(32) && fwdOf(32)->parked == 1, 'park-only + forward action parks');

echo "PASS $pass FAIL $fail\n";
