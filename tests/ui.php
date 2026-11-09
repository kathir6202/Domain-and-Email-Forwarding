<?php
// usage: php ui.php <smarty-autoload>
$smartyAutoload = $argv[1];
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/FakePanel.php';
require_once $smartyAutoload;

use SeekaHost\Forwarding\Schema;
use SeekaHost\Forwarding\Service;
use SeekaHost\Forwarding\Panels\PanelFactory;
use SeekaHost\Forwarding\Admin\AdminController;
use SeekaHost\Forwarding\Client\ClientController;
use WHMCS\Database\Capsule;

eval('namespace WHMCS\Config; class Setting { public static function getValue($k) { return "https://billing.example.com/"; } }');
PanelFactory::$factory = function ($s) { return new FakePanel(); };
require dirname(__DIR__) . '/modules/addons/seekahost_forwarding/seekahost_forwarding.php';
require dirname(__DIR__) . '/modules/addons/seekahost_forwarding/hooks.php';
seekahost_forwarding_activate();
Capsule::table('tbladdonmodules')->insert(['module' => 'seekahost_forwarding', 'setting' => 'api_enabled', 'value' => 'on']);
Capsule::table('tblclients')->insert(['id' => 1, 'firstname' => 'Ann', 'lastname' => 'A', 'email' => 'a@x.com']);
Capsule::table('tbldomains')->insert(['id' => 10, 'userid' => 1, 'domain' => 'example.com', 'status' => 'Active', 'emailforwarding' => 1]);
Capsule::table('tbldomains')->insert(['id' => 11, 'userid' => 1, 'domain' => 'noaddon.com', 'status' => 'Active', 'emailforwarding' => 0]);
Capsule::table('tbldomainpricing')->insert([['extension' => '.com'], ['extension' => '.net']]);
Capsule::table(Schema::T_SERVERS)->insert(['name' => 'S1', 'type' => 'cpanel', 'hostname' => 'h', 'username' => 'root', 'account' => 'fwd', 'web_ip' => '203.0.113.5', 'mail_host' => 'mail.h', 'is_default' => 1, 'active' => 1]);
$fwd = Service::fwdDomain(Service::domainRow(10));
Service::saveUrl($fwd, ['source_path' => '/', 'destination' => 'https://dest.com/?a=1&b=2', 'type' => 'masked', 'title' => 'T "q"', 'preserve_query' => 1]);
Service::saveEmail(Service::reload($fwd), ['local_part' => 'info', 'destinations' => 'a@b.com,c@d.com']);
Service::setCatchAll(Service::reload($fwd), 'all@b.com');
SeekaHost\Forwarding\Api::createToken('t1', '');
Capsule::table(Schema::T_MIGRATION)->insert(['server_id' => 1, 'domain' => 'old-<x>.com', 'kind' => 'parked', 'state' => 'scanned', 'blocking' => 1,
    'issues' => json_encode(['Issue <x>']), 'mapping' => json_encode(['urls' => [['source_path' => '/']], 'emails' => ['info' => ['a@b.com']], 'catchall' => null, 'domain_forward' => null, 'parked' => false]),
    'snapshot' => json_encode(['redirects' => []]), 'message' => 'msg <x>']);

$errors = 0;
function check($html, $label, array $mustContain = []) {
    global $errors;
    foreach ($mustContain as $needle) {
        if (strpos($html, $needle) === false) { $errors++; echo "MISSING in $label: $needle\n"; }
    }
    if (preg_match('/<x>/', $html)) { $errors++; echo "XSS in $label\n"; }
}

// ---- admin pages
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SESSION = [];
foreach (['dashboard', 'domains', 'domain', 'servers', 'server', 'tlds', 'api', 'migration', 'logs'] as $action) {
    $_GET = $_REQUEST = ['module' => 'seekahost_forwarding', 'action' => $action];
    if ($action === 'domain') { $_GET['fid'] = $_REQUEST['fid'] = 1; }
    if ($action === 'server') { $_GET['id'] = $_REQUEST['id'] = 1; }
    ob_start();
    try { (new AdminController(['modulelink' => 'addonmodules.php?module=seekahost_forwarding']))->dispatch(); }
    catch (Throwable $e) { $errors++; echo "ADMIN $action threw: " . $e->getMessage() . ' @' . $e->getFile() . ':' . $e->getLine() . "\n"; }
    $html = ob_get_clean();
    $expect = ['dashboard' => ['Recent Activity'], 'domains' => ['example.com', 'Manage'], 'domain' => ['URL Forwarders', 'info@example.com', 'all@b.com'],
        'servers' => ['S1'], 'server' => ['Verify SSL Certificate'], 'tlds' => ['.com', '.net', 'Automatic action'], 'api' => ['api.php', 't1'], 'migration' => ['Discover domains', 'old-&lt;x&gt;.com', 'Issue &lt;x&gt;', '4. Move selected', 'Planned rules'], 'logs' => ['url_add']];
    check($html, "admin:$action", $expect[$action]);
}
$_GET = $_REQUEST = ['module' => 'seekahost_forwarding', 'action' => 'domains', 'q' => 'Ann', 'status' => 'active'];
ob_start(); (new AdminController(['modulelink' => 'm']))->dispatch(); check(ob_get_clean(), 'admin:search', ['example.com']);

// ---- hooks
$out = $GLOBALS['hooks']['AdminClientDomainsTabFields'][0](['id' => 10]);
check(implode('', $out), 'hook:admintab', ['Active', '1 URL']);
$_REQUEST = ['id' => 10];
$GLOBALS['perm_ok'] = true;
$box = $GLOBALS['hooks']['ClientAreaDomainDetailsOutput'][0]([]);
check($box, 'hook:box', ['Manage Forwarding']);

// ---- client pages through Smarty
$smarty = new Smarty();
$smarty->setTemplateDir(dirname(__DIR__) . '/modules/addons/seekahost_forwarding/');
$smarty->setCompileDir(sys_get_temp_dir() . '/shfwd_smarty_' . md5($smartyAutoload));
@mkdir($smarty->getCompileDir());
$smarty->enableSecurity();
$_SESSION['uid'] = 1;
$cases = [
    'manage' => [['id' => 10], ['Domain (URL) Forwarding', 'info@example.com', 'all@b.com', 'Masked (Frame)', 'Request SSL', 'www. Handling', '203.0.113.5']],
    'edit_url' => [['id' => 10, 'edit_url' => 1], ['Edit URL Forwarder', 'T &quot;q&quot;']],
    'edit_email' => [['id' => 10, 'edit_email' => 1], ['Edit Email Forwarder', 'a@b.com, c@d.com']],
    'list' => [[], ['Manage Forwarding']],
    'noaddon' => [['id' => 11], ['View Domain Addons']],
    'notmine' => [['id' => 999], ['Domain not found']],
];
foreach ($cases as $name => $case) {
    $_GET = $_REQUEST = $case[0];
    $_SESSION['shfwd_flash'] = ['type' => 'success', 'message' => 'Saved <x>'];
    try {
        $r = (new ClientController(['modulelink' => 'index.php?m=seekahost_forwarding']))->dispatch();
        $smarty->clearAllAssign();
        $smarty->assign($r['vars']);
        $smarty->assign('token', 'tok');
        $html = $smarty->fetch($r['templatefile'] . '.tpl');
        check($html, "client:$name", $case[1]);
    } catch (Throwable $e) { $errors++; echo "CLIENT $name threw: " . $e->getMessage() . "\n"; }
}
$GLOBALS['perm_ok'] = false;
$_GET = $_REQUEST = ['id' => 10];
$r = (new ClientController(['modulelink' => 'm']))->dispatch();
if (strpos((string) $r['vars']['error'], 'permission') === false) { $errors++; echo "permission check missing\n"; }

echo ($errors ? "UI ERRORS: $errors" : 'UI OK') . ' (Smarty ' . Smarty::SMARTY_VERSION . ")\n";
