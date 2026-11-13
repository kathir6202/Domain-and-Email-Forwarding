<?php

namespace SeekaHost\Forwarding\Admin;

use SeekaHost\Forwarding\Api;
use SeekaHost\Forwarding\Input;
use SeekaHost\Forwarding\Logger;
use SeekaHost\Forwarding\Migration\Migrator;
use SeekaHost\Forwarding\Panels\PanelFactory;
use SeekaHost\Forwarding\Schema;
use SeekaHost\Forwarding\Service;
use SeekaHost\Forwarding\Settings;
use SeekaHost\Forwarding\Tld;
use SeekaHost\Forwarding\Validator;
use WHMCS\Database\Capsule;

class AdminController
{
    const PER_PAGE = 50;
    const STATUSES = ['pending', 'active', 'error', 'suspended', 'disabled', 'migrating'];
    const MIGRATION_STATES = [
        'discovered' => 'default', 'scanned' => 'info', 'imported' => 'primary', 'moving' => 'warning', 'verifying' => 'info',
        'restoring' => 'info', 'migrated' => 'success',
        'failed' => 'warning', 'rolled_back' => 'warning', 'rollback_failed' => 'danger', 'skipped' => 'default',
    ];

    private $link;
    private $in = [];

    public function __construct(array $vars)
    {
        $this->link = $vars['modulelink'];
    }

    private static function e($v)
    {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }

    private function url(array $params = [])
    {
        return $this->link . ($params ? '&' . http_build_query($params) : '');
    }

    private function token()
    {
        return '<input type="hidden" name="token" value="' . self::e(generate_token('plain')) . '">';
    }

    private function flash($type, $message)
    {
        $_SESSION['shfwd_admin_flash'][] = [$type, $message];
    }

    private function redirect(array $params)
    {
        header('Location: ' . $this->url($params));
        exit;
    }

    private function q($key, $default = '')
    {
        return Input::get($key, $default, $_GET);
    }

    public function dispatch()
    {
        $action = isset($_REQUEST['action']) ? (string) $_REQUEST['action'] : 'dashboard';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            check_token('WHMCS.admin.default');
            $this->in = Input::all();
            $this->handlePost($action);
        }

        $messages = isset($_SESSION['shfwd_admin_flash']) ? $_SESSION['shfwd_admin_flash'] : [];
        unset($_SESSION['shfwd_admin_flash']);

        echo '<style>.shfwd-a .nav{margin-bottom:15px}.shfwd-a td,.shfwd-a th{vertical-align:middle!important}'
            . '.shfwd-a .label,.shfwd-a .badge{font-size:11px}.shfwd-a form.inline{display:inline}.shfwd-a .form-inline .form-control{display:inline-block;width:auto}'
            . '.shfwd-a .well{padding:10px;background:#f7f7f7;border:1px solid #e3e3e3;border-radius:4px;margin-bottom:15px}</style><div class="shfwd-a">';
        $this->nav($action);
        foreach ($messages as $m) {
            echo '<div class="alert alert-' . ($m[0] === 'success' ? 'success' : 'danger') . '">' . self::e($m[1]) . '</div>';
        }

        switch ($action) {
            case 'domains':
                $this->domains();
                break;
            case 'domain':
                $this->domain();
                break;
            case 'servers':
                $this->servers();
                break;
            case 'server':
                $this->serverForm();
                break;
            case 'tlds':
                $this->tlds();
                break;
            case 'api':
                $this->api();
                break;
            case 'migration':
                $this->migration();
                break;
            case 'logs':
                $this->logs();
                break;
            default:
                $this->dashboard();
        }
        echo '</div>';
    }

    private function nav($action)
    {
        $tabs = ['dashboard' => 'Dashboard', 'domains' => 'Domains & Forwarders', 'servers' => 'Servers', 'tlds' => 'TLD Settings', 'api' => 'API', 'migration' => 'Migration', 'logs' => 'Logs'];
        $map = ['domain' => 'domains', 'server' => 'servers'];
        $current = isset($map[$action]) ? $map[$action] : $action;
        echo '<ul class="nav nav-tabs">';
        foreach ($tabs as $k => $label) {
            echo '<li class="nav-item' . ($current === $k ? ' active' : '') . '"><a class="nav-link' . ($current === $k ? ' active' : '')
                . '" href="' . self::e($this->url(['action' => $k])) . '">' . $label . '</a></li>';
        }
        echo '<li class="nav-item"><a class="nav-link" href="configaddonmods.php#seekahost_forwarding">Settings</a></li></ul>';
    }

    /* ------------------------------------------------------------------ POST */

    private function handlePost($action)
    {
        $op = isset($this->in['op']) ? $this->in['op'] : '';
        try {
            switch ($action) {
                case 'server':
                    $this->postServer($op);
                    break;
                case 'domain':
                case 'domains':
                    $this->postDomain($op);
                    break;
                case 'tlds':
                    Tld::save(isset($this->in['tld']) && is_array($this->in['tld']) ? $this->in['tld'] : []);
                    Logger::log('tld_save', 'TLD settings saved');
                    $this->flash('success', 'TLD settings saved.');
                    $this->redirect(['action' => 'tlds']);
                    break;
                case 'api':
                    $this->postApi($op);
                    break;
                case 'migration':
                    $this->postMigration($op);
                    break;
            }
        } catch (\InvalidArgumentException $e) {
            $this->flash('error', $e->getMessage());
        } catch (\Throwable $e) {
            $this->flash('error', 'Server error: ' . $e->getMessage());
        }
        $back = $_GET;
        unset($back['module']);
        $this->redirect($back);
    }

    private function postServer($op)
    {
        $in = $this->in;
        $id = isset($in['id']) ? (int) $in['id'] : 0;

        if ($op === 'delete') {
            if (Capsule::table(Schema::T_DOMAINS)->where('server_id', $id)->whereIn('status', ['active', 'error'])->exists()) {
                Validator::fail('This server still hosts forwarding domains. Move or remove them first.');
            }
            $name = Capsule::table(Schema::T_SERVERS)->where('id', $id)->value('name');
            Capsule::table(Schema::T_SERVERS)->where('id', $id)->delete();
            Logger::log('server_delete', 'Server "' . $name . '" deleted');
            $this->flash('success', 'Server deleted.');
            $this->redirect(['action' => 'servers']);
        }
        if ($op === 'test') {
            $server = Capsule::table(Schema::T_SERVERS)->where('id', $id)->first();
            if (!$server) {
                Validator::fail('Server not found.');
            }
            try {
                $message = PanelFactory::make($server)->testConnection();
                Logger::log('server_test', 'Server "' . $server->name . '": ' . $message);
                $this->flash('success', $message);
            } catch (\Throwable $e) {
                Logger::log('server_test', 'Server "' . $server->name . '" test failed: ' . $e->getMessage(), 'error');
                $this->flash('error', 'Test failed: ' . $e->getMessage());
            }
            $this->redirect(['action' => 'servers']);
        }

        // save
        $type = isset($in['type']) ? $in['type'] : '';
        if (!isset(PanelFactory::TYPES[$type])) {
            Validator::fail('Choose a server type.');
        }
        $data = [];
        foreach (['name', 'hostname', 'username', 'account', 'web_ip', 'mail_host', 'ftp_host', 'ftp_user'] as $f) {
            $data[$f] = trim(isset($in[$f]) ? (string) $in[$f] : '');
        }
        foreach (['ns1' => 'Nameserver 1', 'ns2' => 'Nameserver 2'] as $f => $label) {
            $ns = trim(isset($in[$f]) ? (string) $in[$f] : '');
            if ($ns !== '') {
                try {
                    $ns = Validator::domain($ns);
                } catch (\InvalidArgumentException $e) {
                    Validator::fail($label . ' must be a host name such as ns1.example.com.');
                }
            }
            $data[$f] = $ns;
        }
        if ($data['name'] === '' || $data['hostname'] === '' || $data['account'] === '') {
            Validator::fail('Name, hostname and account are required.');
        }
        if ($data['web_ip'] !== '' && !filter_var($data['web_ip'], FILTER_VALIDATE_IP)) {
            Validator::fail('Web IP must be a valid IP address.');
        }
        $data['type'] = $type;
        $data['port'] = !empty($in['port']) ? (int) $in['port'] : null;
        $data['ftp_port'] = !empty($in['ftp_port']) ? (int) $in['ftp_port'] : null;
        foreach (['secure', 'verify_ssl', 'ftp_ssl', 'active'] as $flag) {
            $data[$flag] = !empty($in[$flag]) ? 1 : 0;
        }

        $existing = $id ? Capsule::table(Schema::T_SERVERS)->where('id', $id)->first() : null;
        if ($id && !$existing) {
            Validator::fail('Server not found.');
        }
        // Never send stored credentials to a new host without re-entry.
        $panelChanged = $existing && (strtolower($existing->hostname) !== strtolower($data['hostname']) || $existing->type !== $data['type']);
        $oldFtpHost = $existing ? strtolower($existing->ftp_host ?: $existing->hostname) : '';
        $ftpChanged = $existing && ($oldFtpHost !== strtolower($data['ftp_host'] ?: $data['hostname']) || $existing->type !== $data['type']);
        $usesFtp = in_array($type, ['directadmin', 'plesk'], true);
        $entered = function ($key) use ($in) {
            return isset($in[$key]) && $in[$key] !== '';
        };
        if (($panelChanged || !$existing) && !$entered('password') && !$entered('api_token')) {
            Validator::fail($existing
                ? 'The hostname or type changed: please re-enter the password or API token.'
                : 'Enter a password or an API token.');
        }
        $ftpViaPanelPassword = ($data['ftp_user'] ?: $data['account']) === $data['username'];
        if ($usesFtp && $ftpChanged && !$entered('ftp_pass') && !($ftpViaPanelPassword && $entered('password'))) {
            Validator::fail('The FTP host changed: please re-enter the FTP password.');
        }
        foreach (['password' => $panelChanged, 'api_token' => $panelChanged, 'ftp_pass' => $ftpChanged] as $secret => $changed) {
            if ($entered($secret)) {
                $data[$secret] = encrypt($in[$secret]);
            } elseif ($changed) {
                $data[$secret] = null;
            }
        }
        $data['updated_at'] = date('Y-m-d H:i:s');

        if (!empty($in['is_default'])) {
            Capsule::table(Schema::T_SERVERS)->update(['is_default' => 0]);
            $data['is_default'] = 1;
        } elseif ($id) {
            $data['is_default'] = 0;
        }

        if ($id) {
            Capsule::table(Schema::T_SERVERS)->where('id', $id)->update($data);
        } else {
            $data['created_at'] = $data['updated_at'];
            if (!Capsule::table(Schema::T_SERVERS)->exists()) {
                $data['is_default'] = 1;
            }
            $id = Capsule::table(Schema::T_SERVERS)->insertGetId($data);
        }
        Logger::log('server_save', 'Server "' . $data['name'] . '" saved');
        $this->flash('success', 'Server saved. Use "Test" to verify the API and FTP connection.');
        $this->redirect(['action' => 'servers']);
    }

    private function postDomain($op)
    {
        $in = $this->in;
        if ($op === 'open') {
            $name = Validator::toAscii(isset($in['domain']) ? $in['domain'] : '');
            $row = Capsule::table('tbldomains')->where('domain', $name)->orderBy('id', 'desc')->first();
            if (!$row) {
                Validator::fail('Domain ' . $name . ' was not found in WHMCS.');
            }
            $this->redirect(['action' => 'domain', 'domainid' => $row->id]);
        }

        $fwd = Capsule::table(Schema::T_DOMAINS)->where('id', (int) (isset($in['fid']) ? $in['fid'] : 0))->first();
        if (!$fwd) {
            Validator::fail('Forwarding domain not found.');
        }
        $back = ['action' => 'domain', 'fid' => $fwd->id];
        $item = !empty($in['item']) ? (int) $in['item'] : null;

        switch ($op) {
            case 'limits':
                $data = [];
                foreach (['url_limit', 'email_limit'] as $f) {
                    $data[$f] = (isset($in[$f]) && $in[$f] !== '') ? max(0, (int) $in[$f]) : null;
                }
                if (!in_array($fwd->status, ['active', 'error'], true) && isset($in['server_id'])) {
                    $data['server_id'] = $in['server_id'] !== '' ? (int) $in['server_id'] : null;
                }
                Capsule::table(Schema::T_DOMAINS)->where('id', $fwd->id)->update($data);
                Logger::log('domain_settings', $fwd->domain . ': limits URL=' . var_export($data['url_limit'], true)
                    . ', email=' . var_export($data['email_limit'], true) . (isset($data['server_id']) ? ', server #' . $data['server_id'] : ''), 'info', $fwd->client_id, $fwd->domain_id);
                $this->flash('success', 'Domain settings saved.');
                break;
            case 'sync':
                Service::sync($fwd);
                $this->flash('success', 'Domain synchronised with the server.');
                break;
            case 'check':
                list(, $panel) = Service::panelFor($fwd);
                $present = in_array($fwd->domain, $panel->listDomains(), true);
                Logger::log('check', $fwd->domain . ' is ' . ($present ? '' : 'NOT ') . 'present on the server', $present ? 'info' : 'warning', $fwd->client_id, $fwd->domain_id);
                $this->flash($present ? 'success' : 'error', $fwd->domain . ($present ? ' is present on the server.' : ' is NOT present on the server. Use "Sync to Server" to restore it.'));
                break;
            case 'ssl':
                Service::requestSsl(Service::ensureProvisioned($fwd));
                $this->flash('success', 'SSL requested.');
                break;
            case 'park':
                Service::park($fwd);
                $this->flash('success', 'Domain parked on the server.');
                break;
            case 'unpark':
                Service::unpark($fwd);
                $this->flash('success', 'Domain unparked.');
                break;
            case 'disable':
                Service::unprovision($fwd, 'disabled', 'admin request');
                $this->flash('success', 'Domain removed from the server and disabled. Rules are kept; "Sync to Server" re-enables it.');
                break;
            case 'purge':
                Service::purge($fwd, !empty($in['force']));
                $this->flash('success', 'Forwarding data deleted.');
                $this->redirect(['action' => 'domains']);
                break;
            case 'save_url':
                Service::saveUrl($fwd, $in, $item);
                $this->flash('success', 'URL forwarder saved.');
                break;
            case 'delete_url':
                Service::deleteUrl($fwd, $item);
                $this->flash('success', 'URL forwarder deleted.');
                break;
            case 'save_email':
                Service::saveEmail($fwd, $in, $item);
                $this->flash('success', 'Email forwarder saved.');
                break;
            case 'delete_email':
                Service::deleteEmail($fwd, $item);
                $this->flash('success', 'Email forwarder deleted.');
                break;
            case 'catchall':
                Service::setCatchAll($fwd, isset($in['catchall']) ? $in['catchall'] : '');
                $this->flash('success', 'Catch-all saved.');
                break;
            case 'domain_forward':
                Service::setDomainForwarder($fwd, isset($in['domain_forward']) ? $in['domain_forward'] : '');
                $this->flash('success', 'Domain email forwarding saved.');
                break;
        }
        $this->redirect($back);
    }

    private function postApi($op)
    {
        $in = $this->in;
        if ($op === 'create') {
            list($token) = Api::createToken(isset($in['name']) ? $in['name'] : '', isset($in['allowed_ips']) ? $in['allowed_ips'] : '');
            $_SESSION['shfwd_new_token'] = $token;
            $this->flash('success', 'API token created. Copy it now; it will not be shown again.');
        } elseif ($op === 'update') {
            Api::updateToken((int) $in['id'], isset($in['allowed_ips']) ? $in['allowed_ips'] : '', !empty($in['active']));
            $this->flash('success', 'API token updated.');
        } elseif ($op === 'delete') {
            Api::deleteToken((int) $in['id']);
            $this->flash('success', 'API token revoked.');
        }
        $this->redirect(['action' => 'api']);
    }

    /* ----------------------------------------------------------------- pages */

    private function dashboard()
    {
        $servers = Capsule::table(Schema::T_SERVERS)->where('active', 1)->count();
        if (!$servers) {
            echo '<div class="alert alert-warning">No forwarding server is configured yet. <a href="'
                . self::e($this->url(['action' => 'server'])) . '">Add a server</a> to start.</div>';
        }
        $errors = Capsule::table(Schema::T_DOMAINS)->where('status', 'error')->count();
        if ($errors) {
            echo '<div class="alert alert-danger">' . (int) $errors . ' domain(s) are in error state. <a href="'
                . self::e($this->url(['action' => 'domains', 'status' => 'error'])) . '">Review them</a>.</div>';
        }
        $stats = [
            'Forwarding domains' => Capsule::table(Schema::T_DOMAINS)->count(),
            'Active on server' => Capsule::table(Schema::T_DOMAINS)->where('status', 'active')->count(),
            'Parked' => Capsule::table(Schema::T_DOMAINS)->where('parked', 1)->count(),
            'URL forwarders' => Capsule::table(Schema::T_URL)->count(),
            'Email forwarders' => Capsule::table(Schema::T_EMAIL)->count(),
            'Servers' => $servers,
        ];
        echo '<div class="row">';
        foreach ($stats as $label => $value) {
            echo '<div class="col-sm-2"><div class="panel panel-default card"><div class="panel-body card-body text-center">'
                . '<h2 style="margin:0">' . (int) $value . '</h2><small>' . $label . '</small></div></div></div>';
        }
        echo '</div><p>Access mode: <strong>' . (Settings::get('access_mode') === 'all' ? 'All active domains' : 'Domain addon ("Email Forwarding") required')
            . '</strong>. Automatic forwarders: <strong>' . (Settings::on('auto_forwarders') ? 'on' : 'off') . '</strong>. API: <strong>'
            . (Settings::on('api_enabled') ? 'enabled' : 'disabled') . '</strong>.</p>';
        $this->openDomainForm();

        echo '<h3>Recent Activity</h3>';
        $this->logTable(Capsule::table(Schema::T_LOGS)->orderBy('id', 'desc')->limit(15)->get()->all());
    }

    private function openDomainForm()
    {
        echo '<form method="post" class="form-inline well" action="' . self::e($this->url(['action' => 'domains'])) . '">' . $this->token()
            . '<input type="hidden" name="op" value="open">Open any WHMCS domain: <input type="text" name="domain" class="form-control" placeholder="example.com"> '
            . '<button class="btn btn-default">Open</button></form>';
    }

    private function domains()
    {
        $q = Capsule::table(Schema::T_DOMAINS . ' as d')
            ->leftJoin('tblclients as c', 'c.id', '=', 'd.client_id')
            ->leftJoin(Schema::T_SERVERS . ' as s', 's.id', '=', 'd.server_id')
            ->select('d.*', 'c.firstname', 'c.lastname', 'c.companyname', 'c.email', 's.name as server_name');
        $search = trim((string) $this->q('q'));
        if ($search !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search) . '%';
            $q->where(function ($w) use ($search, $like) {
                $w->where('d.domain', 'like', $like)
                    ->orWhere('c.firstname', 'like', $like)
                    ->orWhere('c.lastname', 'like', $like)
                    ->orWhere('c.companyname', 'like', $like)
                    ->orWhere('c.email', 'like', $like);
                if (ctype_digit($search)) {
                    $w->orWhere('d.client_id', (int) $search);
                }
            });
        }
        $status = (string) $this->q('status');
        if (in_array($status, self::STATUSES, true)) {
            $q->where('d.status', $status);
        }
        $serverId = (int) $this->q('server', 0);
        if ($serverId) {
            $q->where('d.server_id', $serverId);
        }
        $page = max(1, (int) $this->q('page', 1));
        $total = (clone $q)->count();
        $rows = $q->orderBy('d.domain')->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE)->get()->all();

        $ids = array_map(function ($r) {
            return $r->id;
        }, $rows);
        $urlCounts = $ids ? Capsule::table(Schema::T_URL)->whereIn('fwd_domain_id', $ids)->selectRaw('fwd_domain_id, count(*) as n')->groupBy('fwd_domain_id')->pluck('n', 'fwd_domain_id') : [];
        $emailCounts = $ids ? Capsule::table(Schema::T_EMAIL)->whereIn('fwd_domain_id', $ids)->selectRaw('fwd_domain_id, count(*) as n')->groupBy('fwd_domain_id')->pluck('n', 'fwd_domain_id') : [];

        $statusOptions = '<option value="">All statuses</option>';
        foreach (self::STATUSES as $s) {
            $statusOptions .= '<option value="' . $s . '"' . ($status === $s ? ' selected' : '') . '>' . ucfirst($s) . '</option>';
        }
        $serverOptions = '<option value="">All servers</option>';
        foreach (Capsule::table(Schema::T_SERVERS)->orderBy('name')->get()->all() as $s) {
            $serverOptions .= '<option value="' . (int) $s->id . '"' . ($serverId === (int) $s->id ? ' selected' : '') . '>' . self::e($s->name) . '</option>';
        }
        echo '<form method="get" class="form-inline" style="margin-bottom:10px"><input type="hidden" name="module" value="seekahost_forwarding">'
            . '<input type="hidden" name="action" value="domains"><input type="text" name="q" class="form-control" placeholder="Domain, client name/email or ID" value="'
            . self::e($search) . '"> <select name="status" class="form-control">' . $statusOptions . '</select> <select name="server" class="form-control">'
            . $serverOptions . '</select> <button class="btn btn-default">Search</button></form>';
        $this->openDomainForm();

        echo '<table class="table table-striped table-condensed"><thead><tr><th>Domain</th><th>Client</th><th>Server</th><th>Status</th><th>SSL</th>'
            . '<th>URL</th><th>Email</th><th>Catch-all</th><th>Last Error</th><th></th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $client = trim($r->firstname . ' ' . $r->lastname) . ($r->companyname ? ' (' . $r->companyname . ')' : '');
            echo '<tr><td><a href="clientsdomains.php?userid=' . (int) $r->client_id . '&id=' . (int) $r->domain_id . '">' . self::e(Validator::toUnicode($r->domain)) . '</a>'
                . ($r->parked ? ' <span class="label label-info badge badge-info">Parked</span>' : '') . '</td>'
                . '<td><a href="clientssummary.php?userid=' . (int) $r->client_id . '">' . self::e($client ?: '#' . $r->client_id) . '</a></td>'
                . '<td>' . self::e($r->server_name ?: '-') . '</td><td>' . $this->statusLabel($r->status) . '</td>'
                . '<td>' . self::e($r->ssl_status ?: '-') . '</td>'
                . '<td>' . (int) (isset($urlCounts[$r->id]) ? $urlCounts[$r->id] : 0) . '</td>'
                . '<td>' . (int) (isset($emailCounts[$r->id]) ? $emailCounts[$r->id] : 0) . '</td>'
                . '<td>' . self::e($r->catchall ?: '-') . '</td>'
                . '<td><small>' . self::e(substr((string) $r->last_error, 0, 80)) . '</small></td>'
                . '<td><a class="btn btn-xs btn-sm btn-primary" href="' . self::e($this->url(['action' => 'domain', 'fid' => $r->id])) . '">Manage</a></td></tr>';
        }
        if (!$rows) {
            echo '<tr><td colspan="10" class="text-center">No forwarding domains found.</td></tr>';
        }
        echo '</tbody></table>';
        $this->pager($total, $page, ['action' => 'domains', 'q' => $search, 'status' => $status, 'server' => $serverId ?: '']);
    }

    private function statusLabel($status)
    {
        $map = ['active' => 'success', 'pending' => 'default', 'suspended' => 'warning', 'disabled' => 'warning', 'error' => 'danger', 'migrating' => 'info'];
        $cls = isset($map[$status]) ? $map[$status] : 'default';
        return '<span class="label label-' . $cls . ' badge badge-' . $cls . '">' . self::e(ucfirst($status)) . '</span>';
    }

    private function domain()
    {
        $fwd = null;
        if ($this->q('domainid')) {
            $row = Service::domainRow((int) $this->q('domainid'));
            if ($row) {
                try {
                    $fwd = Service::fwdDomain($row);
                } catch (\Throwable $e) {
                    echo '<div class="alert alert-danger">' . self::e($e->getMessage()) . '</div>';
                    return;
                }
            }
        } elseif ($this->q('fid')) {
            $fwd = Capsule::table(Schema::T_DOMAINS)->where('id', (int) $this->q('fid'))->first();
        }
        if (!$fwd) {
            echo '<div class="alert alert-danger">Domain not found.</div>';
            return;
        }
        $domainRow = Service::domainRow($fwd->domain_id);
        list($eligible, $reason) = Service::access($domainRow);
        $limits = Service::limits($fwd);
        $server = Service::server($fwd);
        $policy = Service::policy($fwd);
        $base = ['action' => 'domain', 'fid' => $fwd->id];
        $form = function ($op, $inner, $extra = '', $confirm = null) use ($fwd, $base) {
            return '<form method="post" class="inline form-inline" action="' . self::e($this->url($base)) . '"'
                . ($confirm ? ' onsubmit="return confirm(\'' . self::e($confirm) . '\')"' : '') . '>' . $this->token()
                . '<input type="hidden" name="op" value="' . $op . '"><input type="hidden" name="fid" value="' . (int) $fwd->id . '">'
                . $extra . $inner . '</form>';
        };

        echo '<h2>' . self::e(Validator::toUnicode($fwd->domain)) . ' ' . $this->statusLabel($fwd->status)
            . ($fwd->parked ? ' <span class="label label-info badge badge-info">Parked</span>' : '') . '</h2>';
        if (!$eligible) {
            echo '<div class="alert alert-warning">Client access: ' . self::e($reason) . ' (admins can still manage it; the daily cron removes ineligible domains from the server).</div>';
        }
        if ($policy['park_only']) {
            echo '<div class="alert alert-info">This TLD is configured as "park only".</div>';
        }
        if ($fwd->last_error) {
            echo '<div class="alert alert-danger"><strong>Last error:</strong> ' . self::e($fwd->last_error) . '</div>';
        }

        echo '<p>Client: <a href="clientssummary.php?userid=' . (int) $fwd->client_id . '">#' . (int) $fwd->client_id . '</a> &middot; '
            . 'WHMCS domain: <a href="clientsdomains.php?userid=' . (int) $fwd->client_id . '&id=' . (int) $fwd->domain_id . '">#' . (int) $fwd->domain_id . '</a> &middot; '
            . 'Server: ' . self::e($server ? $server->name . ' (' . $server->type . ')' : 'none') . ' &middot; SSL: ' . self::e($fwd->ssl_status ?: '-')
            . ' &middot; Last sync: ' . self::e($fwd->last_sync ?: '-') . '</p><p>'
            . $form('sync', '<button class="btn btn-primary">Sync to Server</button>') . ' '
            . $form('check', '<button class="btn btn-default">Check Server</button>') . ' '
            . $form('ssl', '<button class="btn btn-default">Request SSL</button>') . ' '
            . ($fwd->parked
                ? $form('unpark', '<button class="btn btn-default">Unpark</button>')
                : $form('park', '<button class="btn btn-default">Park</button>')) . ' '
            . $form('disable', '<button class="btn btn-warning">Remove from Server &amp; Disable</button>', '', 'Remove this domain from the server and disable it? Rules are kept.') . ' '
            . $form('purge', '<button class="btn btn-danger">Delete All Forwarding Data</button> <label><input type="checkbox" name="force" value="1"> force (even if the server cannot be reached)</label>', '', 'Delete all forwarders for this domain?')
            . '</p>';

        // Limits / server
        $serverSelect = '';
        if (!in_array($fwd->status, ['active', 'error'], true)) {
            $serverSelect = ' Server: <select name="server_id" class="form-control"><option value="">Default</option>';
            foreach (Capsule::table(Schema::T_SERVERS)->where('active', 1)->get()->all() as $s) {
                $serverSelect .= '<option value="' . (int) $s->id . '"' . ((int) $fwd->server_id === (int) $s->id ? ' selected' : '') . '>' . self::e($s->name) . '</option>';
            }
            $serverSelect .= '</select>';
        }
        echo '<div class="well">' . $form('limits',
            'URL limit <input type="number" min="0" name="url_limit" class="form-control" style="width:90px" placeholder="' . Settings::int('url_limit') . '" value="' . self::e($fwd->url_limit) . '"> '
            . 'Email limit <input type="number" min="0" name="email_limit" class="form-control" style="width:90px" placeholder="' . Settings::int('email_limit') . '" value="' . self::e($fwd->email_limit) . '"> '
            . $serverSelect . ' <button class="btn btn-default">Save</button> <small>Empty = module default, 0 = unlimited. Effective: '
            . $limits['url'] . ' URL / ' . $limits['email'] . ' email.</small>') . '</div>';

        // URL forwarders
        echo '<h3>URL Forwarders</h3><table class="table table-striped"><thead><tr><th>Source</th><th>Destination</th><th>Type</th><th>www.</th><th>Wildcard</th><th>Keep Query</th><th>Title</th><th></th></tr></thead><tbody>';
        foreach (Service::urls($fwd) as $u) {
            echo '<tr><td>' . self::e($u->source_path) . ($u->auto ? ' <small>(auto)</small>' : '') . '</td><td>' . self::e($u->destination) . '</td><td>' . self::e($u->type) . '</td>'
                . '<td>' . self::e(isset(Service::WWW_MODES[$u->www_mode]) ? Service::WWW_MODES[$u->www_mode] : $u->www_mode) . '</td>'
                . '<td>' . ($u->wildcard ? 'Yes' : 'No') . '</td><td>' . ($u->preserve_query ? 'Yes' : 'No') . '</td><td>' . self::e($u->title) . '</td>'
                . '<td>' . $form('delete_url', '<button class="btn btn-xs btn-sm btn-danger">Delete</button>', '<input type="hidden" name="item" value="' . (int) $u->id . '">', 'Delete?') . '</td></tr>';
        }
        echo '</tbody></table>';
        $typeOptions = '';
        foreach (Service::URL_TYPES as $k => $label) {
            $typeOptions .= '<option value="' . $k . '">' . $label . '</option>';
        }
        $wwwOptions = '';
        foreach (Service::WWW_MODES as $k => $label) {
            $wwwOptions .= '<option value="' . $k . '">' . $label . '</option>';
        }
        echo '<div class="well">' . $form('save_url',
            'Path <input name="source_path" value="/" class="form-control" style="width:120px"> '
            . 'Destination <input name="destination" class="form-control" style="width:260px" placeholder="https://"> '
            . '<select name="type" class="form-control">' . $typeOptions . '</select> '
            . '<select name="www_mode" class="form-control">' . $wwwOptions . '</select> '
            . '<label><input type="checkbox" name="wildcard" value="1"> Wildcard</label> '
            . '<label><input type="checkbox" name="preserve_query" value="1" checked> Keep query</label> '
            . 'Title <input name="title" class="form-control" style="width:140px"> '
            . '<button class="btn btn-success">Add</button>') . '<br><small>Domain limits, TLD rules and validation also apply to admin changes.</small></div>';

        // Email forwarders
        echo '<h3>Email Forwarders</h3><table class="table table-striped"><thead><tr><th>Address</th><th>Destinations</th><th></th></tr></thead><tbody>';
        foreach (Service::emails($fwd) as $e) {
            echo '<tr><td>' . self::e($e->local_part . '@' . $fwd->domain) . '</td><td>' . self::e(str_replace(',', ', ', $e->destinations)) . '</td>'
                . '<td>' . $form('delete_email', '<button class="btn btn-xs btn-sm btn-danger">Delete</button>', '<input type="hidden" name="item" value="' . (int) $e->id . '">', 'Delete?') . '</td></tr>';
        }
        echo '</tbody></table><div class="well">' . $form('save_email',
            'Alias <input name="local_part" class="form-control" style="width:140px">@' . self::e($fwd->domain)
            . ' &rarr; <input name="destinations" class="form-control" style="width:320px" placeholder="a@example.com, b@example.com"> '
            . '<button class="btn btn-success">Add</button>') . '</div>';

        echo '<h3>Catch-All</h3>' . $form('catchall',
            '<input type="text" name="catchall" class="form-control" style="width:300px" value="' . self::e($fwd->catchall) . '" placeholder="empty = reject"> <button class="btn btn-default">Save</button>');

        echo '<h3>Domain Email Forwarding <small>(cPanel)</small></h3>' . $form('domain_forward',
            '@' . self::e($fwd->domain) . ' &rarr; @<input type="text" name="domain_forward" class="form-control" style="width:260px" value="' . self::e($fwd->domain_forward) . '" placeholder="empty = disabled"> <button class="btn btn-default">Save</button>');

        echo '<h3>Activity</h3>';
        $this->logTable(Capsule::table(Schema::T_LOGS)->where('domain_id', $fwd->domain_id)->orderBy('id', 'desc')->limit(30)->get()->all());
    }

    private function servers()
    {
        echo '<p><a class="btn btn-success" href="' . self::e($this->url(['action' => 'server'])) . '">Add Server</a></p>';
        echo '<table class="table table-striped"><thead><tr><th>Name</th><th>Type</th><th>Hostname</th><th>Account</th><th>Web IP</th><th>Mail Host</th>'
            . '<th>Domains</th><th>Default</th><th>Active</th><th></th></tr></thead><tbody>';
        foreach (Capsule::table(Schema::T_SERVERS)->orderBy('name')->get()->all() as $s) {
            $count = Capsule::table(Schema::T_DOMAINS)->where('server_id', $s->id)->whereIn('status', ['active', 'error'])->count();
            $post = function ($op, $label, $cls, $confirm = null) use ($s) {
                return '<form method="post" class="inline" action="' . self::e($this->url(['action' => 'server'])) . '"'
                    . ($confirm ? ' onsubmit="return confirm(\'' . $confirm . '\')"' : '') . '>' . $this->token()
                    . '<input type="hidden" name="op" value="' . $op . '"><input type="hidden" name="id" value="' . (int) $s->id . '">'
                    . '<button class="btn btn-xs btn-sm ' . $cls . '">' . $label . '</button></form>';
            };
            echo '<tr><td>' . self::e($s->name) . '</td><td>' . self::e(isset(PanelFactory::TYPES[$s->type]) ? PanelFactory::TYPES[$s->type] : $s->type) . '</td><td>' . self::e($s->hostname) . '</td>'
                . '<td>' . self::e($s->account) . '</td><td>' . self::e($s->web_ip) . '</td><td>' . self::e($s->mail_host) . '</td><td>' . $count . '</td>'
                . '<td>' . ($s->is_default ? 'Yes' : '') . '</td><td>' . ($s->active ? 'Yes' : 'No') . '</td><td style="white-space:nowrap">'
                . '<a class="btn btn-xs btn-sm btn-default" href="' . self::e($this->url(['action' => 'server', 'id' => $s->id])) . '">Edit</a> '
                . $post('test', 'Test', 'btn-info') . ' ' . $post('delete', 'Delete', 'btn-danger', 'Delete this server?') . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private function serverForm()
    {
        $id = (int) $this->q('id', 0);
        $s = $id ? Capsule::table(Schema::T_SERVERS)->where('id', $id)->first() : null;
        $v = function ($f, $default = '') use ($s) {
            return self::e($s ? $s->$f : $default);
        };
        $typeOptions = '';
        foreach (PanelFactory::TYPES as $k => $label) {
            $typeOptions .= '<option value="' . $k . '"' . ($s && $s->type === $k ? ' selected' : '') . '>' . $label . '</option>';
        }
        $row = function ($label, $input, $help = '') {
            return '<tr><td class="fieldlabel" width="220">' . $label . '</td><td class="fieldarea">' . $input
                . ($help ? '<br><small class="text-muted">' . $help . '</small>' : '') . '</td></tr>';
        };
        $text = function ($name, $value, $type = 'text', $placeholder = '') {
            return '<input type="' . $type . '" name="' . $name . '" class="form-control" style="max-width:420px" value="' . $value
                . '" placeholder="' . self::e($placeholder) . '" autocomplete="off">';
        };
        $check = function ($name, $on) {
            return '<input type="checkbox" name="' . $name . '" value="1"' . ($on ? ' checked' : '') . '>';
        };
        $secretHint = $s ? 'Leave empty to keep the stored value (you must re-enter it if the hostname, FTP host or type changes).' : '';

        echo '<h2>' . ($s ? 'Edit' : 'Add') . ' Server</h2><form method="post" action="' . self::e($this->url(['action' => 'server'])) . '">'
            . $this->token() . '<input type="hidden" name="op" value="save"><input type="hidden" name="id" value="' . $id . '">'
            . '<table class="form" width="100%" cellspacing="2" cellpadding="3">'
            . $row('Name', $text('name', $v('name')))
            . $row('Type', '<select name="type" class="form-control" style="max-width:420px">' . $typeOptions . '</select>')
            . $row('Hostname', $text('hostname', $v('hostname'), 'text', 'server.example.com'))
            . $row('Port', $text('port', $v('port'), 'number'), 'Empty = default (WHM 2087, DirectAdmin 2222, Plesk 8443).')
            . $row('Use SSL', $check('secure', $s ? $s->secure : true))
            . $row('Verify SSL Certificate', $check('verify_ssl', $s ? $s->verify_ssl : true), 'Recommended. Disable only if the panel uses a self-signed certificate.')
            . $row('Admin Username', $text('username', $v('username'), 'text', 'root / admin'), 'WHM user, DirectAdmin admin/reseller (or the user itself), or Plesk admin.')
            . $row('Password', $text('password', '', 'password'), $secretHint)
            . $row('API Token / Key', $text('api_token', '', 'password'), 'Recommended. WHM API token, DirectAdmin login key, or Plesk secret key. ' . $secretHint)
            . $row('Account', $text('account', $v('account')), 'cPanel username / DirectAdmin username / Plesk subscription (webspace) domain that will hold the forwarded domains.')
            . $row('Web IP (A record)', $text('web_ip', $v('web_ip')), 'Shown to clients as the required A record; also used before requesting SSL.')
            . $row('Mail Host (MX)', $text('mail_host', $v('mail_host')), 'Shown to clients as the required MX record.')
            . $row('Nameserver 1', $text('ns1', $v('ns1'), 'text', 'ns1.example.com'), 'Optional. Shown to clients as an alternative to the A/MX records: they can point the domain to these nameservers instead.')
            . $row('Nameserver 2', $text('ns2', $v('ns2'), 'text', 'ns2.example.com'), 'Optional.')
            . $row('FTP Host', $text('ftp_host', $v('ftp_host')), 'DirectAdmin and Plesk only (file upload). Empty = hostname.')
            . $row('FTP Port', $text('ftp_port', $v('ftp_port'), 'number'), 'Empty = 21.')
            . $row('FTP Username', $text('ftp_user', $v('ftp_user')), 'Empty = Account. For Plesk, the subscription\'s system user.')
            . $row('FTP Password', $text('ftp_pass', '', 'password'), 'Required for DirectAdmin and Plesk (the panel admin password is never used for FTP). ' . $secretHint)
            . $row('FTP over TLS', $check('ftp_ssl', $s ? $s->ftp_ssl : true), 'Recommended.')
            . $row('Default Server', $check('is_default', $s ? $s->is_default : false), 'New domains are provisioned on the default server.')
            . $row('Active', $check('active', $s ? $s->active : true), 'Inactive servers receive no new domains but keep managing existing ones.')
            . '</table><p style="margin-top:10px"><button class="btn btn-primary">Save</button> <a class="btn btn-default" href="'
            . self::e($this->url(['action' => 'servers'])) . '">Cancel</a></p></form>';
    }

    private function tlds()
    {
        $tlds = Tld::sold();
        echo '<p>Per-TLD permissions narrow the global settings (Setup &gt; Addon Modules). TLDs without saved settings allow everything the global settings allow. '
            . 'Automatic actions run after a domain is registered or transferred (when "Automatic Forwarders" is enabled). In the URL, <code>{domain}</code> is replaced with the domain name.</p>';
        if (!$tlds) {
            echo '<div class="alert alert-info">No TLDs are configured in Setup &gt; Products/Services &gt; Domain Pricing.</div>';
            return;
        }
        $rows = Capsule::table(Schema::T_TLDS)->get()->keyBy('tld');
        $typeLabels = ['301' => '301', '302' => '302', '303' => '303', 'masked' => 'Masked'];
        echo '<form method="post" action="' . self::e($this->url(['action' => 'tlds'])) . '">' . $this->token()
            . '<table class="table table-striped table-condensed"><thead><tr><th>TLD</th><th>URL forwarders</th><th>Directory forwarders</th>'
            . '<th>Email forwarders</th><th>Domain email fwd</th><th>Catch-all</th><th>Park only</th><th>Automatic action</th><th>Auto URL</th><th>Auto type</th></tr></thead><tbody>';
        foreach ($tlds as $tld) {
            $r = isset($rows[$tld]) ? $rows[$tld] : null;
            $name = 'tld[' . self::e($tld) . ']';
            $cb = function ($field) use ($r, $name) {
                $on = $r ? (bool) $r->$field : true;
                return '<td><input type="checkbox" name="' . $name . '[' . $field . ']" value="1"' . ($on ? ' checked' : '') . '></td>';
            };
            $actionSelect = '<select name="' . $name . '[auto_action]" class="form-control input-sm">';
            foreach (Tld::AUTO_ACTIONS as $k => $label) {
                $actionSelect .= '<option value="' . $k . '"' . (($r ? $r->auto_action : 'none') === $k ? ' selected' : '') . '>' . $label . '</option>';
            }
            $actionSelect .= '</select>';
            $typeSelect = '<select name="' . $name . '[auto_type]" class="form-control input-sm">';
            foreach ($typeLabels as $k => $label) {
                $typeSelect .= '<option value="' . $k . '"' . (($r ? $r->auto_type : '301') === $k ? ' selected' : '') . '>' . $label . '</option>';
            }
            $typeSelect .= '</select>';
            echo '<tr><td><strong>' . self::e($tld) . '</strong></td>' . $cb('allow_url') . $cb('allow_path') . $cb('allow_email')
                . $cb('allow_domain_email') . $cb('allow_catchall')
                . '<td><input type="checkbox" name="' . $name . '[park_only]" value="1"' . ($r && $r->park_only ? ' checked' : '') . '></td>'
                . '<td>' . $actionSelect . '</td><td><input type="text" name="' . $name . '[auto_url]" class="form-control input-sm" value="'
                . self::e($r ? $r->auto_url : '') . '" placeholder="https://www.example.com/?d={domain}"></td><td>' . $typeSelect . '</td></tr>';
        }
        echo '</tbody></table><button class="btn btn-primary">Save TLD Settings</button></form>';
    }

    private function api()
    {
        $endpoint = rtrim((string) \WHMCS\Config\Setting::getValue('SystemURL'), '/') . '/modules/addons/seekahost_forwarding/api.php';
        if (!Settings::on('api_enabled')) {
            echo '<div class="alert alert-warning">The API is disabled. Enable it in Setup &gt; Addon Modules &gt; SeekaHost Domain &amp; Email Forwarding.</div>';
        }
        if (!empty($_SESSION['shfwd_new_token'])) {
            echo '<div class="alert alert-success">New token: <code style="font-size:14px">' . self::e($_SESSION['shfwd_new_token']) . '</code></div>';
            unset($_SESSION['shfwd_new_token']);
        }
        echo '<p>Endpoint: <code>' . self::e($endpoint) . '</code> &mdash; send the token in the <code>X-API-Token</code> header (or a <code>token</code> POST field &mdash; never in the URL), '
            . 'with <code>action</code> and parameters via GET or POST. Responses are JSON. Behind a proxy or load balancer, configure WHMCS Trusted Proxies so IP restrictions see the real client IP.</p>'
            . '<p>Actions: <code>domains</code> (client_id), <code>redirects</code> / <code>email_forwarders</code> (domain_id or domain), '
            . '<code>redirect_create</code> / <code>redirect_update</code> (id, source_path, destination, type, www_mode, wildcard, preserve_query, title...), <code>redirect_delete</code> (id), '
            . '<code>email_forwarder_create</code> / <code>email_forwarder_update</code> (id, local_part, destinations), <code>email_forwarder_delete</code> (id), <code>catchall_set</code> (destination).</p>';

        echo '<table class="table table-striped"><thead><tr><th>Name</th><th>Token</th><th>Allowed IPs</th><th>Active</th><th>Last Used</th><th></th></tr></thead><tbody>';
        foreach (Capsule::table(Schema::T_TOKENS)->orderBy('name')->get()->all() as $t) {
            echo '<tr><td>' . self::e($t->name) . '</td><td><code>' . self::e($t->token_hint) . '&hellip;</code></td><td>'
                . '<form method="post" class="inline form-inline" action="' . self::e($this->url(['action' => 'api'])) . '">' . $this->token()
                . '<input type="hidden" name="op" value="update"><input type="hidden" name="id" value="' . (int) $t->id . '">'
                . '<input type="text" name="allowed_ips" class="form-control input-sm" style="width:260px" value="' . self::e($t->allowed_ips) . '" placeholder="any"> '
                . '<label><input type="checkbox" name="active" value="1"' . ($t->active ? ' checked' : '') . '> active</label> <button class="btn btn-xs btn-sm btn-default">Save</button></form></td>'
                . '<td>' . ($t->active ? 'Yes' : 'No') . '</td><td>' . self::e($t->last_used_at ? $t->last_used_at . ' from ' . $t->last_ip : '-') . '</td><td>'
                . '<form method="post" class="inline" action="' . self::e($this->url(['action' => 'api'])) . '" onsubmit="return confirm(\'Revoke this token?\')">' . $this->token()
                . '<input type="hidden" name="op" value="delete"><input type="hidden" name="id" value="' . (int) $t->id . '"><button class="btn btn-xs btn-sm btn-danger">Revoke</button></form></td></tr>';
        }
        echo '</tbody></table><div class="well"><form method="post" class="form-inline" action="' . self::e($this->url(['action' => 'api'])) . '">' . $this->token()
            . '<input type="hidden" name="op" value="create">Name <input type="text" name="name" class="form-control" required> '
            . 'Allowed IPs <input type="text" name="allowed_ips" class="form-control" style="width:260px" placeholder="comma separated, empty = any"> '
            . '<button class="btn btn-success">Create Token</button></form></div>';
    }

    /* ------------------------------------------------------------- migration */

    private function postMigration($op)
    {
        $in = $this->in;
        $serverId = !empty($in['server_id']) ? (int) $in['server_id'] : (isset($_SESSION['shfwd_mig_server']) ? (int) $_SESSION['shfwd_mig_server'] : 0);
        $ids = isset($in['ids']) && is_array($in['ids']) ? array_values(array_filter(array_map('intval', $in['ids']))) : [];
        $back = ['action' => 'migration'];
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        // A domain must never be left half-moved because the browser disconnected.
        @ignore_user_abort(true);
        switch ($op) {
            case 'discover':
                $_SESSION['shfwd_mig_server'] = $serverId;
                list($n, $global) = Migrator::forServer($serverId)->discover();
                $this->flash('success', 'Discovered ' . $n . ' domain(s). Next: scan them (read-only).');
                if ($global) {
                    $this->flash('error', $global . ' redirect(s) on this cPanel account apply to ALL its domains. They are not migrated per domain; review them in cPanel > Redirects.');
                }
                break;
            case 'scan':
                list($done, $left) = Migrator::forServer($serverId)->scanBatch(10);
                $this->flash('success', 'Scanned ' . $done . ' domain(s); ' . $left . ' waiting.');
                if ($left > 0 && !empty($in['auto'])) {
                    $back['continue'] = 'scan';
                }
                break;
            case 'import':
                list($done, $errors) = Migrator::forServer($serverId)->import($ids);
                $this->flash('success', 'Imported ' . $done . ' domain(s) (dry run: nothing changed on the server yet).');
                foreach (array_slice($errors, 0, 10) as $err) {
                    $this->flash('error', $err);
                }
                break;
            case 'cancel':
                $this->flash('success', Migrator::cancel($ids) . ' import(s) cancelled.');
                break;
            case 'rescan':
                Migrator::rescan($ids);
                $this->flash('success', 'Selected domains will be scanned again.');
                break;
            case 'skip':
                $this->flash('success', Migrator::skip($ids) . ' domain(s) skipped.');
                break;
            case 'recheck':
                $migrator = Migrator::forServer($serverId);
                foreach (Capsule::table(Schema::T_MIGRATION)->whereIn('id', $ids)->get()->all() as $rec) {
                    list($ok, $message) = $migrator->recheck($rec);
                    $this->flash($ok ? 'success' : 'error', $message);
                }
                break;
            case 'mark_restored':
                $n = Migrator::markRestored($ids);
                $this->flash($n ? 'success' : 'error', $n
                    ? $n . ' domain(s) marked as restored. They can be moved again.'
                    : 'Nothing marked: only "rollback failed" or "restoring" domains whose new setup is no longer on the server can be marked as restored.');
                break;
            case 'stop':
                unset($_SESSION['shfwd_mig_queue']);
                $this->flash('success', 'Stopped. Domains not processed yet keep their current state.');
                break;
            case 'move':
                $billing = isset($in['billing']) ? (string) $in['billing'] : '';
                if (!isset(Migrator::BILLING[$billing])) {
                    Validator::fail('Choose how migrated domains are billed.');
                }
                if (!$ids) {
                    Validator::fail('Select the imported domains to move.');
                }
                // Only queue the work here: every step below runs as its own short request.
                $kinds = Capsule::table(Schema::T_MIGRATION)->whereIn('id', $ids)->where('state', '!=', 'migrated')->pluck('kind')->all();
                if ($billing === 'all') {
                    Migrator::setAccessModeAll();
                }
                $_SESSION['shfwd_mig_queue'] = [
                    'server' => $serverId,
                    'ids' => $ids,
                    'opts' => ['billing' => $billing, 'accept_diff' => !empty($in['accept_diff']), 'keep_default' => !empty($in['keep_default'])],
                    'preflight' => in_array('parked', $kinds, true),
                    'total' => count($ids),
                ];
                Logger::log('migration', 'Move started for ' . count($ids) . ' domain(s), billing: ' . $billing);
                $back['continue'] = 'move';
                break;
            case 'move_continue':
                $q = isset($_SESSION['shfwd_mig_queue']) ? $_SESSION['shfwd_mig_queue'] : null;
                if (!$q) {
                    break;
                }
                $migrator = Migrator::forServer($q['server']);
                if (!empty($q['preflight'])) {
                    // Step 1 of the run: pre-flight test only (parked domains need a new addon domain).
                    try {
                        $note = $migrator->preflight();
                    } catch (\InvalidArgumentException $e) {
                        unset($_SESSION['shfwd_mig_queue']);
                        throw $e;
                    }
                    $_SESSION['shfwd_mig_queue']['preflight'] = false;
                    $this->flash('success', 'Pre-flight test passed.');
                    if ($note !== '') {
                        $this->flash('error', $note);
                    }
                    $back['continue'] = 'move';
                    break;
                }
                // Then one domain per request.
                $left = $migrator->moveBatch($q['ids'], $q['opts'], 1);
                if ($left) {
                    $_SESSION['shfwd_mig_queue']['ids'] = $left;
                    $back['continue'] = 'move';
                    $total = isset($q['total']) ? (int) $q['total'] : count($left) + 1;
                    $this->flash('success', ($total - count($left)) . ' of ' . $total . ' done.');
                } else {
                    unset($_SESSION['shfwd_mig_queue']);
                    $this->flash('success', 'Move finished. Review the results below.');
                    if (Capsule::table(Schema::T_MIGRATION)->where('server_id', $q['server'])->whereIn('state', ['verifying', 'restoring'])->exists()) {
                        $back['continue'] = 'verify';
                    }
                }
                break;
            case 'verify_pending':
                list($checked, $done) = Migrator::verifyPendingAll(10, $serverId);
                if ($done) {
                    $this->flash('success', $done . ' waiting domain(s) finished.');
                }
                if (Capsule::table(Schema::T_MIGRATION)->where('server_id', $serverId)->whereIn('state', ['verifying', 'restoring'])->exists()
                    && empty($in['once'])) {
                    $back['continue'] = 'verify';
                }
                break;
            case 'resume':
            case 'undo':
                $migrator = Migrator::forServer($serverId);
                foreach (Capsule::table(Schema::T_MIGRATION)->whereIn('id', $ids)->get()->all() as $rec) {
                    list($ok, $message) = $op === 'resume' ? $migrator->resume($rec) : $migrator->undoInterrupted($rec);
                    $this->flash($ok ? 'success' : 'error', $message);
                }
                if (Capsule::table(Schema::T_MIGRATION)->where('server_id', $serverId)->whereIn('state', ['verifying', 'restoring'])->exists()) {
                    $back['continue'] = 'verify';
                }
                break;
        }
        $this->redirect($back);
    }

    private function migration()
    {
        $servers = Capsule::table(Schema::T_SERVERS)->where('type', 'cpanel')->orderBy('name')->get()->all();
        echo '<h2>Migrate from the previous forwarding module</h2>'
            . '<p>Moves domains that an older forwarding module (e.g. ModulesGarden) set up on a cPanel account into this module, on the <strong>same account</strong>, so customers change no DNS. '
            . 'Each domain is moved on its own: the old setup is detached, the new one is created, and the live web redirects and email routing are compared with what they did before. '
            . 'If anything differs, the old setup is put back automatically.</p>'
            . '<div class="alert alert-warning"><strong>Before you start:</strong> back up the WHMCS database and the cPanel account. Steps 1&ndash;3 never change the server.</div>';
        if (!$servers) {
            echo '<div class="alert alert-info">Add the cPanel server (with the account the old module uses) under <a href="' . self::e($this->url(['action' => 'servers'])) . '">Servers</a> first.</div>';
            return;
        }
        $serverId = isset($_SESSION['shfwd_mig_server']) ? (int) $_SESSION['shfwd_mig_server'] : (int) $servers[0]->id;
        $mig = $this->url(['action' => 'migration']);
        $token = $this->token();

        $counts = Capsule::table(Schema::T_MIGRATION)->where('server_id', $serverId)->selectRaw('state, count(*) as n')->groupBy('state')->pluck('n', 'state');
        $count = function ($state) use ($counts) {
            return (int) (isset($counts[$state]) ? $counts[$state] : 0);
        };

        // Auto-continue: every step is its own short request (scan batch, pre-flight, one domain, a quick re-check).
        $continue = (string) $this->q('continue');
        $steps = ['scan' => ['scan', 800, 'Scanning&hellip; the next batch starts automatically.'],
            'move' => ['move_continue', 800, 'Moving&hellip; the next domain starts automatically (one domain per step).'],
            'verify' => ['verify_pending', 15000, 'Some domains are waiting for the web server to load the change. Checking again automatically every 15 seconds '
                . '(the WHMCS cron also finishes them if you leave this page).']];
        if (isset($steps[$continue]) && ($continue !== 'move' || !empty($_SESSION['shfwd_mig_queue']))) {
            list($nextOp, $delay, $text) = $steps[$continue];
            echo '<form method="post" id="shfwd-continue" action="' . self::e($mig) . '">' . $token
                . '<input type="hidden" name="op" value="' . $nextOp . '"><input type="hidden" name="auto" value="1">'
                . '<input type="hidden" name="server_id" value="' . $serverId . '"></form>'
                . '<div class="alert alert-info">' . $text . ' '
                . '<form method="post" class="inline" action="' . self::e($mig) . '">' . $token
                . '<input type="hidden" name="op" value="stop"><button class="btn btn-xs btn-sm btn-default">Stop</button></form></div>'
                . '<script>setTimeout(function(){document.getElementById("shfwd-continue").submit();}, ' . (int) $delay . ');</script>';
        } elseif ($count('verifying') + $count('restoring') > 0) {
            echo '<div class="alert alert-info">' . ($count('verifying') + $count('restoring')) . ' domain(s) are waiting for the web server. '
                . '<form method="post" class="inline" action="' . self::e($mig) . '">' . $token
                . '<input type="hidden" name="op" value="verify_pending"><input type="hidden" name="server_id" value="' . $serverId . '">'
                . '<button class="btn btn-xs btn-sm btn-primary">Check now</button></form> (the WHMCS cron also checks them every run)</div>';
        }

        $options = '';
        foreach ($servers as $s) {
            $options .= '<option value="' . (int) $s->id . '"' . ($serverId === (int) $s->id ? ' selected' : '') . '>' . self::e($s->name . ' (' . $s->account . '@' . $s->hostname . ')') . '</option>';
        }

        echo '<div class="well"><form method="post" class="form-inline" action="' . self::e($mig) . '">' . $token
            . '<strong>1. Discover</strong> &nbsp; cPanel server / account used by the old module: <select name="server_id" class="form-control">' . $options . '</select> '
            . '<button class="btn btn-default" name="op" value="discover">Discover domains</button></form>'
            . '<form method="post" class="form-inline" style="margin-top:8px" action="' . self::e($mig) . '">' . $token
            . '<input type="hidden" name="server_id" value="' . $serverId . '"><strong>2. Scan</strong> (read-only) &nbsp; '
            . $count('discovered') . ' waiting &nbsp; <label><input type="checkbox" name="auto" value="1" checked> continue automatically</label> '
            . '<button class="btn btn-default" name="op" value="scan"' . ($count('discovered') ? '' : ' disabled') . '>Scan</button></form>'
            . '<p style="margin:8px 0 0"><strong>3. Import</strong> (dry run, server unchanged) and <strong>4. Move</strong>: select domains in the table below.</p></div>';

        $summary = [];
        foreach (self::MIGRATION_STATES as $state => $cls) {
            if ($count($state)) {
                $summary[] = '<a href="' . self::e($this->url(['action' => 'migration', 'state' => $state])) . '"><span class="label label-' . $cls . ' badge badge-' . $cls . '">'
                    . self::e(str_replace('_', ' ', $state)) . ': ' . $count($state) . '</span></a>';
            }
        }
        echo '<p>' . ($summary ? implode(' ', $summary) . ' &nbsp; <a href="' . self::e($mig) . '">show all</a>' : 'No domains discovered yet.') . '</p>';

        $q = Capsule::table(Schema::T_MIGRATION)->where('server_id', $serverId);
        $stateFilter = (string) $this->q('state');
        if (isset(self::MIGRATION_STATES[$stateFilter])) {
            $q->where('state', $stateFilter);
        }
        $rows = $q->orderBy('domain')->limit(500)->get()->all();
        if (!$rows) {
            return;
        }

        $billing = '';
        foreach (Migrator::BILLING as $k => $label) {
            $billing .= '<label style="display:block;font-weight:normal"><input type="radio" name="billing" value="' . $k . '"' . ($k === 'flag' ? ' checked' : '') . '> ' . self::e($label) . '</label>';
        }
        echo '<form method="post" action="' . self::e($mig) . '">' . $token . '<input type="hidden" name="server_id" value="' . $serverId . '">'
            . '<table class="table table-striped table-condensed"><thead><tr><th><input type="checkbox" onclick="var c=this.checked;document.querySelectorAll(\'.shfwd-mig-id\').forEach(function(x){x.checked=c;});"></th>'
            . '<th>Domain</th><th>Type</th><th>Client</th><th>State</th><th>Redirects</th><th>Email aliases</th><th>Catch-all</th><th>Domain fwd</th><th>Issues / result</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $map = Migrator::decode($r, 'mapping');
            $issues = Migrator::decode($r, 'issues');
            $cls = isset(self::MIGRATION_STATES[$r->state]) ? self::MIGRATION_STATES[$r->state] : 'default';
            $issueHtml = '';
            foreach ($issues as $i) {
                $issueHtml .= '<div' . ($r->blocking ? ' class="text-danger"' : ' class="text-muted"') . '>&bull; ' . self::e($i) . '</div>';
            }
            if ($r->message) {
                $issueHtml .= '<div><strong>' . self::e($r->message) . '</strong></div>';
            }
            $details = '';
            foreach (['mapping' => 'Planned rules', 'snapshot' => 'Snapshot of the old setup', 'result' => 'Verification'] as $f => $label) {
                if ($r->$f) {
                    $details .= '<details><summary>' . $label . '</summary><pre style="max-height:300px;overflow:auto;font-size:11px">'
                        . self::e(json_encode(json_decode($r->$f, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) . '</pre></details>';
                }
            }
            echo '<tr><td><input type="checkbox" class="shfwd-mig-id" name="ids[]" value="' . (int) $r->id . '"></td>'
                . '<td>' . self::e(Validator::toUnicode($r->domain)) . '</td><td>' . self::e($r->kind) . '</td>'
                . '<td>' . ($r->client_id ? '<a href="clientssummary.php?userid=' . (int) $r->client_id . '">#' . (int) $r->client_id . '</a>' : '-') . '</td>'
                . '<td><span class="label label-' . $cls . ' badge badge-' . $cls . '">' . self::e(str_replace('_', ' ', $r->state)) . '</span>'
                . ($r->blocking ? ' <span class="label label-danger badge badge-danger">blocked</span>' : '')
                . (Migrator::isInterrupted($r) ? ' <span class="label label-danger badge badge-danger">interrupted</span>' : '') . '</td>'
                . '<td>' . (isset($map['urls']) ? count($map['urls']) : '-') . '</td>'
                . '<td>' . (isset($map['emails']) ? count($map['emails']) : '-') . '</td>'
                . '<td>' . self::e(!empty($map['catchall']) ? $map['catchall'] : '-') . '</td>'
                . '<td>' . self::e(!empty($map['domain_forward']) ? $map['domain_forward'] : '-') . '</td>'
                . '<td style="max-width:420px">' . $issueHtml . $details . '</td></tr>';
        }
        echo '</tbody></table>'
            . '<div class="well"><p><strong>With the selected domains:</strong></p><p>'
            . '<button class="btn btn-primary" name="op" value="import">3. Import (dry run)</button> '
            . '<button class="btn btn-default" name="op" value="cancel">Cancel import</button> '
            . '<button class="btn btn-default" name="op" value="rescan">Re-scan</button> '
            . '<button class="btn btn-default" name="op" value="skip">Skip</button></p>'
            . '<p><strong>Interrupted move?</strong> (the request was cut off, e.g. by a web-server timeout) '
            . '<button class="btn btn-success" name="op" value="resume">Resume</button> finishes it, '
            . '<button class="btn btn-warning" name="op" value="undo" onclick="return confirm(\'Put the old setup back for the selected interrupted domains?\')">Undo</button> puts the old setup back.</p>'
            . '<p><strong>Verifying, restoring or rollback failed?</strong> <em>Verifying</em> and <em>restoring</em> rows finish automatically (this page and the WHMCS cron); Re-check checks them now. '
            . 'For <em>rollback failed</em>, restore the domain (see its snapshot), then: '
            . '<button class="btn btn-info" name="op" value="recheck">Re-check</button> '
            . '<button class="btn btn-warning" name="op" value="mark_restored" onclick="return confirm(\'Only use this after you have confirmed the old setup works again. Mark the selected rollback-failed domains as restored?\')">Mark restored</button> '
            . '<small class="text-muted">Re-check compares the live setup with the snapshot; Mark restored is a manual override.</small></p>'
            . '<hr><p><strong>4. Move imported domains</strong> &mdash; billing for migrated domains:</p>' . $billing
            . '<p style="margin-top:8px"><small class="text-muted">One domain per step, each step a short request. Addon domains are switched in place (no Apache rebuild); '
            . 'addon domains whose folder is shared or holds a website are blocked by the scan. Parked domains wait up to ' . (int) Migrator::waitSeconds()
            . ' s for the web server, then continue as <em>verifying</em>. A pre-flight test runs first when parked domains are included.</small></p>'
            . '<p><label style="font-weight:normal"><input type="checkbox" name="keep_default" value="1" checked> Keep unrouted mail as before '
            . '(mail to unknown addresses keeps going where it goes today, e.g. the account mailbox)</label></p><p>'
            . '<label style="font-weight:normal"><input type="checkbox" name="accept_diff" value="1"> keep the new setup even if the verification shows differences (not recommended)</label></p>'
            . '<button class="btn btn-success" name="op" value="move" onclick="return confirm(\'Move the selected imported domains now? Each one is verified and rolled back automatically if anything differs.\')">4. Move selected</button>'
            . '</div></form>';
    }

    private function logs()
    {
        $q = Capsule::table(Schema::T_LOGS);
        $filter = trim((string) $this->q('q'));
        if ($filter !== '') {
            $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $filter) . '%';
            $q->where(function ($w) use ($like) {
                $w->where('message', 'like', $like)->orWhere('action', 'like', $like);
            });
        }
        $level = (string) $this->q('level');
        if (in_array($level, ['info', 'warning', 'error'], true)) {
            $q->where('level', $level);
        }
        $actor = (string) $this->q('actor');
        if ($actor !== '') {
            $q->where('actor', 'like', $actor . '%');
        }
        $page = max(1, (int) $this->q('page', 1));
        $total = (clone $q)->count();
        $levelOptions = '<option value="">All levels</option>';
        foreach (['info', 'warning', 'error'] as $l) {
            $levelOptions .= '<option value="' . $l . '"' . ($level === $l ? ' selected' : '') . '>' . ucfirst($l) . '</option>';
        }
        $actorOptions = '<option value="">All actors</option>';
        foreach (['admin', 'client', 'system', 'api'] as $a) {
            $actorOptions .= '<option value="' . $a . '"' . ($actor === $a ? ' selected' : '') . '>' . ucfirst($a) . '</option>';
        }
        echo '<form method="get" class="form-inline" style="margin-bottom:10px"><input type="hidden" name="module" value="seekahost_forwarding">'
            . '<input type="hidden" name="action" value="logs"><input name="q" class="form-control" placeholder="Search" value="' . self::e($filter) . '"> '
            . '<select name="level" class="form-control">' . $levelOptions . '</select> <select name="actor" class="form-control">' . $actorOptions . '</select> '
            . '<button class="btn btn-default">Filter</button></form>';
        $this->logTable($q->orderBy('id', 'desc')->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE)->get()->all());
        $this->pager($total, $page, ['action' => 'logs', 'q' => $filter, 'level' => $level, 'actor' => $actor]);
    }

    private function logTable(array $rows)
    {
        echo '<table class="table table-condensed table-striped"><thead><tr><th>Date</th><th>Actor</th><th>Level</th><th>Action</th><th>Client</th><th>Message</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $cls = $r->level === 'error' ? ' class="danger"' : ($r->level === 'warning' ? ' class="warning"' : '');
            echo '<tr' . $cls . '><td style="white-space:nowrap">' . self::e($r->created_at) . '</td><td>' . self::e($r->actor) . '</td>'
                . '<td>' . self::e($r->level) . '</td><td>' . self::e($r->action) . '</td>'
                . '<td>' . ($r->client_id ? '<a href="clientssummary.php?userid=' . (int) $r->client_id . '">#' . (int) $r->client_id . '</a>' : '-') . '</td>'
                . '<td>' . self::e($r->message) . '</td></tr>';
        }
        if (!$rows) {
            echo '<tr><td colspan="6" class="text-center">No entries.</td></tr>';
        }
        echo '</tbody></table>';
    }

    private function pager($total, $page, array $params)
    {
        $pages = (int) ceil($total / self::PER_PAGE);
        if ($pages < 2) {
            return;
        }
        $from = max(1, $page - 5);
        $to = min($pages, $page + 5);
        echo '<ul class="pagination">';
        if ($from > 1) {
            echo '<li class="page-item"><a class="page-link" href="' . self::e($this->url(array_merge($params, ['page' => 1]))) . '">&laquo; 1</a></li>';
        }
        for ($i = $from; $i <= $to; $i++) {
            echo '<li class="page-item' . ($i === $page ? ' active' : '') . '"><a class="page-link" href="'
                . self::e($this->url(array_merge($params, ['page' => $i]))) . '">' . $i . '</a></li>';
        }
        if ($to < $pages) {
            echo '<li class="page-item"><a class="page-link" href="' . self::e($this->url(array_merge($params, ['page' => $pages]))) . '">' . $pages . ' &raquo;</a></li>';
        }
        echo '</ul> <small>' . (int) $total . ' total</small>';
    }
}
