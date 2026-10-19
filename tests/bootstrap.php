<?php
// Minimal WHMCS stand-in for exercising the module outside WHMCS.
error_reporting(E_ALL);
ini_set('display_errors', '1');
set_error_handler(function ($no, $str, $file, $line) {
    if (!(error_reporting() & $no)) return true;
    if (strpos($file, '/vendor/') !== false || ($no & (E_DEPRECATED | E_USER_DEPRECATED) && getenv('IGNORE_DEPRECATED'))) return true;
    throw new ErrorException($str, 0, $no, $file, $line);
});
define('WHMCS', true);
define('ROOTDIR', __DIR__);
require __DIR__ . '/vendor/autoload.php';

use Illuminate\Database\Capsule\Manager;

class_alias(Manager::class, 'WHMCS\Database\Capsule');
$capsule = new Manager();
$capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
$capsule->setAsGlobal();
$capsule->bootEloquent();
$s = Manager::schema();
$s->create('tbladdonmodules', function ($t) { $t->string('module'); $t->string('setting'); $t->text('value')->nullable(); });
$s->create('tbldomains', function ($t) { $t->increments('id'); $t->integer('userid'); $t->string('domain'); $t->string('status'); $t->boolean('emailforwarding')->default(0); });
$s->create('tblhosting', function ($t) { $t->increments('id'); $t->integer('userid'); $t->string('domain'); $t->string('domainstatus'); });
$s->create('tblclients', function ($t) { $t->increments('id'); $t->string('firstname'); $t->string('lastname'); $t->string('companyname')->default(''); $t->string('email'); });
$s->create('tbldomainpricing', function ($t) { $t->increments('id'); $t->string('extension'); $t->integer('order')->default(0); });
$s->create('tblemailtemplates', function ($t) { $t->increments('id'); foreach (['type','name','subject','message','attachments','fromname','fromemail','language','copyto','blind_copy_to'] as $c) { $t->text($c)->nullable(); } $t->boolean('disabled')->default(0); $t->boolean('custom')->default(0); $t->boolean('plaintext')->default(0); $t->dateTime('created_at')->nullable(); $t->dateTime('updated_at')->nullable(); });
// Patch SHOW INDEX (MySQL only) - Schema::ensureIndex swallows errors.

$GLOBALS['localapi'] = [];
$GLOBALS['hooks'] = [];
function encrypt($v) { return 'enc:' . base64_encode($v); }
function decrypt($v) { return base64_decode(substr($v, 4)); }
function localAPI($cmd, $params) { $GLOBALS['localapi'][] = [$cmd, $params]; return ['result' => 'success']; }
function logModuleCall() {}
function check_token($ns = '') { return true; }
function generate_token($t = '') { return 'tok'; }
function add_hook($name, $prio, $fn) { $GLOBALS['hooks'][$name][] = $fn; }
function checkContactPermission($p, $noRedirect = false) { return !empty($GLOBALS['perm_ok']); }

require dirname(__DIR__) . '/modules/addons/seekahost_forwarding/lib/bootstrap.php';
