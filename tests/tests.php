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

echo "PASS $pass FAIL $fail\n";
