<?php

namespace SeekaHost\Forwarding\Admin;

use SeekaHost\Forwarding\Logger;
use SeekaHost\Forwarding\Panels\PanelFactory;
use SeekaHost\Forwarding\Schema;
use SeekaHost\Forwarding\Service;
use SeekaHost\Forwarding\Settings;
use WHMCS\Database\Capsule;

class AdminController
{
    const PER_PAGE = 50;

    private $link;
    private $messages = [];

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

    public function dispatch()
    {
        $action = isset($_REQUEST['action']) ? $_REQUEST['action'] : 'dashboard';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            check_token('WHMCS.admin.default');
            $this->handlePost($action);
        }

        $this->messages = isset($_SESSION['shfwd_admin_flash']) ? $_SESSION['shfwd_admin_flash'] : [];
        unset($_SESSION['shfwd_admin_flash']);

        echo '<style>.shfwd-a .nav{margin-bottom:15px}.shfwd-a td,.shfwd-a th{vertical-align:middle!important}'
            . '.shfwd-a .label{font-size:11px}.shfwd-a form.inline{display:inline}</style><div class="shfwd-a">';
        $this->nav($action);
        foreach ($this->messages as $m) {
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
        $tabs = ['dashboard' => 'Dashboard', 'domains' => 'Domains & Forwarders', 'servers' => 'Servers', 'logs' => 'Logs'];
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
        $op = isset($_POST['op']) ? $_POST['op'] : '';
        try {
            if ($action === 'server') {
                $this->postServer($op);
                return;
            }
            if ($action === 'domain') {
                $this->postDomain($op);
                return;
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
        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;

        if ($op === 'delete') {
            if (Capsule::table(Schema::T_DOMAINS)->where('server_id', $id)->where('status', 'active')->exists()) {
                throw new \InvalidArgumentException('This server still hosts active forwarding domains.');
            }
            Capsule::table(Schema::T_SERVERS)->where('id', $id)->delete();
            $this->flash('success', 'Server deleted.');
            $this->redirect(['action' => 'servers']);
        }
        if ($op === 'test') {
            $server = Capsule::table(Schema::T_SERVERS)->where('id', $id)->first();
            if (!$server) {
                throw new \InvalidArgumentException('Server not found.');
            }
            try {
                $this->flash('success', PanelFactory::make($server)->testConnection());
            } catch (\Throwable $e) {
                $this->flash('error', 'Test failed: ' . $e->getMessage());
            }
            $this->redirect(['action' => 'servers']);
        }

        // save
        $type = isset($_POST['type']) ? $_POST['type'] : '';
        if (!isset(PanelFactory::TYPES[$type])) {
            throw new \InvalidArgumentException('Choose a server type.');
        }
        $data = [];
        foreach (['name', 'hostname', 'username', 'account', 'web_ip', 'mail_host', 'ftp_host', 'ftp_user'] as $f) {
            $data[$f] = trim(isset($_POST[$f]) ? (string) $_POST[$f] : '');
        }
        if ($data['name'] === '' || $data['hostname'] === '' || $data['account'] === '') {
            throw new \InvalidArgumentException('Name, hostname and account are required.');
        }
        $data['type'] = $type;
        $data['port'] = !empty($_POST['port']) ? (int) $_POST['port'] : null;
        $data['ftp_port'] = !empty($_POST['ftp_port']) ? (int) $_POST['ftp_port'] : null;
        $data['secure'] = !empty($_POST['secure']) ? 1 : 0;
        $data['ftp_ssl'] = !empty($_POST['ftp_ssl']) ? 1 : 0;
        $data['active'] = !empty($_POST['active']) ? 1 : 0;
        foreach (['password', 'api_token', 'ftp_pass'] as $secret) {
            // Blank secret fields keep the stored value.
            if (isset($_POST[$secret]) && $_POST[$secret] !== '') {
                $data[$secret] = encrypt($_POST[$secret]);
            }
        }
        $data['updated_at'] = date('Y-m-d H:i:s');

        if (!empty($_POST['is_default'])) {
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
        Logger::log('server_save', 'Server "' . $data['name'] . '" saved', 'info');
        $this->flash('success', 'Server saved. Use "Test" to verify the connection.');
        $this->redirect(['action' => 'servers']);
    }

    private function postDomain($op)
    {
        $fwd = Capsule::table(Schema::T_DOMAINS)->where('id', (int) (isset($_POST['fid']) ? $_POST['fid'] : 0))->first();
        if (!$fwd) {
            throw new \InvalidArgumentException('Forwarding domain not found.');
        }
        $back = ['action' => 'domain', 'fid' => $fwd->id];
        $item = isset($_POST['item']) ? (int) $_POST['item'] : null;

        switch ($op) {
            case 'limits':
                $data = [];
                foreach (['url_limit', 'email_limit'] as $f) {
                    $data[$f] = (isset($_POST[$f]) && $_POST[$f] !== '') ? max(0, (int) $_POST[$f]) : null;
                }
                if ($fwd->status !== 'active' && !empty($_POST['server_id'])) {
                    $data['server_id'] = (int) $_POST['server_id'];
                }
                Capsule::table(Schema::T_DOMAINS)->where('id', $fwd->id)->update($data);
                $this->flash('success', 'Domain settings saved.');
                break;
            case 'sync':
                Service::sync($fwd);
                $this->flash('success', 'Domain synchronised with the server.');
                break;
            case 'ssl':
                Service::requestSsl(Service::ensureProvisioned($fwd));
                $this->flash('success', 'SSL requested.');
                break;
            case 'suspend':
                Service::suspend($fwd, 'admin request');
                $this->flash('success', 'Domain removed from the server. Rules are kept and restored by "Sync".');
                break;
            case 'purge':
                Service::purge($fwd);
                $this->flash('success', 'Forwarding data deleted.');
                $this->redirect(['action' => 'domains']);
                break;
            case 'save_url':
                Service::saveUrl($fwd, $_POST, $item);
                $this->flash('success', 'URL forwarder saved.');
                break;
            case 'delete_url':
                Service::deleteUrl($fwd, $item);
                $this->flash('success', 'URL forwarder deleted.');
                break;
            case 'save_email':
                Service::saveEmail($fwd, $_POST, $item);
                $this->flash('success', 'Email forwarder saved.');
                break;
            case 'delete_email':
                Service::deleteEmail($fwd, $item);
                $this->flash('success', 'Email forwarder deleted.');
                break;
            case 'catchall':
                Service::setCatchAll($fwd, isset($_POST['catchall']) ? $_POST['catchall'] : '');
                $this->flash('success', 'Catch-all saved.');
                break;
        }
        $this->redirect($back);
    }

    /* ----------------------------------------------------------------- pages */

    private function dashboard()
    {
        $servers = Capsule::table(Schema::T_SERVERS)->where('active', 1)->count();
        if (!$servers) {
            echo '<div class="alert alert-warning">No forwarding server is configured yet. <a href="'
                . self::e($this->url(['action' => 'server'])) . '">Add a server</a> to start.</div>';
        }
        $stats = [
            'Forwarding domains' => Capsule::table(Schema::T_DOMAINS)->count(),
            'Active on server' => Capsule::table(Schema::T_DOMAINS)->where('status', 'active')->count(),
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
            . '</strong>. Domains appear here once a client opens the forwarding page or an admin opens them from a domain\'s page.</p>';

        echo '<h3>Recent Activity</h3>';
        $this->logTable(Capsule::table(Schema::T_LOGS)->orderBy('id', 'desc')->limit(15)->get()->all());
    }

    private function domains()
    {
        $q = Capsule::table(Schema::T_DOMAINS . ' as d')
            ->leftJoin('tblclients as c', 'c.id', '=', 'd.client_id')
            ->leftJoin(Schema::T_SERVERS . ' as s', 's.id', '=', 'd.server_id')
            ->select('d.*', 'c.firstname', 'c.lastname', 'c.companyname', 's.name as server_name');
        $search = trim(isset($_GET['q']) ? (string) $_GET['q'] : '');
        if ($search !== '') {
            $q->where(function ($w) use ($search) {
                $w->where('d.domain', 'like', '%' . $search . '%');
                if (ctype_digit($search)) {
                    $w->orWhere('d.client_id', (int) $search);
                }
            });
        }
        $page = max(1, (int) (isset($_GET['page']) ? $_GET['page'] : 1));
        $total = (clone $q)->count();
        $rows = $q->orderBy('d.domain')->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE)->get()->all();

        $urlCounts = Capsule::table(Schema::T_URL)->selectRaw('fwd_domain_id, count(*) as n')->groupBy('fwd_domain_id')->pluck('n', 'fwd_domain_id');
        $emailCounts = Capsule::table(Schema::T_EMAIL)->selectRaw('fwd_domain_id, count(*) as n')->groupBy('fwd_domain_id')->pluck('n', 'fwd_domain_id');

        echo '<form method="get" class="form-inline" style="margin-bottom:10px"><input type="hidden" name="module" value="seekahost_forwarding">'
            . '<input type="hidden" name="action" value="domains"><input type="text" name="q" class="form-control" placeholder="Domain or client ID" value="'
            . self::e($search) . '"> <button class="btn btn-default">Search</button></form>';

        echo '<table class="table table-striped table-condensed"><thead><tr><th>Domain</th><th>Client</th><th>Server</th><th>Status</th><th>SSL</th>'
            . '<th>URL</th><th>Email</th><th>Catch-all</th><th>Last Error</th><th></th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $client = trim($r->firstname . ' ' . $r->lastname) . ($r->companyname ? ' (' . $r->companyname . ')' : '');
            echo '<tr><td><a href="clientsdomains.php?userid=' . (int) $r->client_id . '&id=' . (int) $r->domain_id . '">' . self::e($r->domain) . '</a></td>'
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
        $this->pager($total, $page, ['action' => 'domains', 'q' => $search]);
    }

    private function statusLabel($status)
    {
        $map = ['active' => 'success', 'pending' => 'default', 'suspended' => 'warning', 'error' => 'danger'];
        $cls = isset($map[$status]) ? $map[$status] : 'default';
        return '<span class="label label-' . $cls . ' badge badge-' . $cls . '">' . self::e(ucfirst($status)) . '</span>';
    }

    private function domain()
    {
        $fwd = null;
        if (!empty($_GET['domainid'])) {
            $row = Service::domainRow((int) $_GET['domainid']);
            if ($row) {
                $fwd = Service::fwdDomain($row);
            }
        } elseif (!empty($_GET['fid'])) {
            $fwd = Capsule::table(Schema::T_DOMAINS)->where('id', (int) $_GET['fid'])->first();
        }
        if (!$fwd) {
            echo '<div class="alert alert-danger">Domain not found.</div>';
            return;
        }
        $domainRow = Service::domainRow($fwd->domain_id);
        list($eligible, $reason) = Service::eligibility($domainRow);
        $limits = Service::limits($fwd);
        $server = Service::server($fwd);
        $base = ['action' => 'domain', 'fid' => $fwd->id];
        $form = function ($op, $inner, $extra = '', $confirm = null) use ($fwd, $base) {
            return '<form method="post" class="inline" action="' . self::e($this->url($base)) . '"'
                . ($confirm ? ' onsubmit="return confirm(\'' . self::e($confirm) . '\')"' : '') . '>' . $this->token()
                . '<input type="hidden" name="op" value="' . $op . '"><input type="hidden" name="fid" value="' . (int) $fwd->id . '">'
                . $extra . $inner . '</form>';
        };

        echo '<h2>' . self::e($fwd->domain) . ' ' . $this->statusLabel($fwd->status) . '</h2>';
        if (!$eligible) {
            echo '<div class="alert alert-warning">Client access: ' . self::e($reason) . ' (admins can still manage it; the daily cron removes ineligible domains from the server).</div>';
        } elseif (Settings::on('hide_if_hosting') && Service::hasHosting($domainRow)) {
            echo '<div class="alert alert-info">Hidden from the client: the domain has a hosting service.</div>';
        }
        if ($fwd->last_error) {
            echo '<div class="alert alert-danger"><strong>Last error:</strong> ' . self::e($fwd->last_error) . '</div>';
        }

        echo '<p>Client: <a href="clientssummary.php?userid=' . (int) $fwd->client_id . '">#' . (int) $fwd->client_id . '</a> &middot; '
            . 'Server: ' . self::e($server ? $server->name . ' (' . $server->type . ')' : 'none') . ' &middot; SSL: ' . self::e($fwd->ssl_status ?: '-')
            . ' &middot; Last sync: ' . self::e($fwd->last_sync ?: '-') . '</p><p>'
            . $form('sync', '<button class="btn btn-primary">Sync to Server</button>') . ' '
            . $form('ssl', '<button class="btn btn-default">Request SSL</button>') . ' '
            . $form('suspend', '<button class="btn btn-warning">Remove from Server</button>', '', 'Remove this domain from the server? Rules are kept.') . ' '
            . $form('purge', '<button class="btn btn-danger">Delete All Forwarding Data</button>', '', 'Delete all forwarders for this domain?')
            . '</p>';

        // Limits / server
        $serverSelect = '';
        if ($fwd->status !== 'active') {
            $serverSelect = ' Server: <select name="server_id" class="form-control"><option value="">Default</option>';
            foreach (Capsule::table(Schema::T_SERVERS)->where('active', 1)->get()->all() as $s) {
                $serverSelect .= '<option value="' . (int) $s->id . '"' . ((int) $fwd->server_id === (int) $s->id ? ' selected' : '') . '>' . self::e($s->name) . '</option>';
            }
            $serverSelect .= '</select>';
        }
        echo '<div class="well" style="padding:10px">' . $form('limits',
            'URL limit <input type="number" min="0" name="url_limit" class="form-control" style="width:90px" placeholder="' . Settings::int('url_limit') . '" value="' . self::e($fwd->url_limit) . '"> '
            . 'Email limit <input type="number" min="0" name="email_limit" class="form-control" style="width:90px" placeholder="' . Settings::int('email_limit') . '" value="' . self::e($fwd->email_limit) . '"> '
            . $serverSelect . ' <button class="btn btn-default">Save</button> <small>Empty = module default, 0 = unlimited. Effective: '
            . $limits['url'] . ' URL / ' . $limits['email'] . ' email.</small>', '', null) . '</div>';

        // URL forwarders
        echo '<h3>URL Forwarders</h3><table class="table table-striped"><thead><tr><th>Source</th><th>Destination</th><th>Type</th><th>Wildcard</th><th>Keep Query</th><th>Title</th><th></th></tr></thead><tbody>';
        foreach (Service::urls($fwd) as $u) {
            echo '<tr><td>' . self::e($u->source_path) . '</td><td>' . self::e($u->destination) . '</td><td>' . self::e($u->type) . '</td>'
                . '<td>' . ($u->wildcard ? 'Yes' : 'No') . '</td><td>' . ($u->preserve_query ? 'Yes' : 'No') . '</td><td>' . self::e($u->title) . '</td>'
                . '<td>' . $form('delete_url', '<button class="btn btn-xs btn-sm btn-danger">Delete</button>', '<input type="hidden" name="item" value="' . (int) $u->id . '">', 'Delete?') . '</td></tr>';
        }
        echo '</tbody></table>';
        $typeOptions = '';
        foreach (Service::URL_TYPES as $k => $label) {
            $typeOptions .= '<option value="' . $k . '">' . $label . '</option>';
        }
        echo '<div class="well" style="padding:10px">' . $form('save_url',
            'Path <input name="source_path" value="/" class="form-control" style="width:120px"> '
            . 'Destination <input name="destination" class="form-control" style="width:260px" placeholder="https://"> '
            . '<select name="type" class="form-control">' . $typeOptions . '</select> '
            . '<label><input type="checkbox" name="wildcard" value="1"> Wildcard</label> '
            . '<label><input type="checkbox" name="preserve_query" value="1" checked> Keep query</label> '
            . 'Title <input name="title" class="form-control" style="width:140px"> '
            . '<input type="hidden" name="meta_description" value=""><input type="hidden" name="meta_keywords" value=""> '
            . '<button class="btn btn-success">Add</button>') . '<br><small>Domain limits and validation also apply to admin changes.</small></div>';

        // Email forwarders
        echo '<h3>Email Forwarders</h3><table class="table table-striped"><thead><tr><th>Address</th><th>Destinations</th><th></th></tr></thead><tbody>';
        foreach (Service::emails($fwd) as $e) {
            echo '<tr><td>' . self::e($e->local_part . '@' . $fwd->domain) . '</td><td>' . self::e(str_replace(',', ', ', $e->destinations)) . '</td>'
                . '<td>' . $form('delete_email', '<button class="btn btn-xs btn-sm btn-danger">Delete</button>', '<input type="hidden" name="item" value="' . (int) $e->id . '">', 'Delete?') . '</td></tr>';
        }
        echo '</tbody></table><div class="well" style="padding:10px">' . $form('save_email',
            'Alias <input name="local_part" class="form-control" style="width:140px">@' . self::e($fwd->domain)
            . ' &rarr; <input name="destinations" class="form-control" style="width:320px" placeholder="a@example.com, b@example.com"> '
            . '<button class="btn btn-success">Add</button>') . '</div>';

        echo '<h3>Catch-All</h3>' . $form('catchall',
            '<input type="email" name="catchall" class="form-control" style="width:300px" value="' . self::e($fwd->catchall) . '" placeholder="empty = reject"> <button class="btn btn-default">Save</button>');

        echo '<h3>Activity</h3>';
        $this->logTable(Capsule::table(Schema::T_LOGS)->where('domain_id', $fwd->domain_id)->orderBy('id', 'desc')->limit(30)->get()->all());
    }

    private function servers()
    {
        echo '<p><a class="btn btn-success" href="' . self::e($this->url(['action' => 'server'])) . '">Add Server</a></p>';
        echo '<table class="table table-striped"><thead><tr><th>Name</th><th>Type</th><th>Hostname</th><th>Account</th><th>Web IP</th><th>Mail Host</th>'
            . '<th>Domains</th><th>Default</th><th>Active</th><th></th></tr></thead><tbody>';
        foreach (Capsule::table(Schema::T_SERVERS)->orderBy('name')->get()->all() as $s) {
            $count = Capsule::table(Schema::T_DOMAINS)->where('server_id', $s->id)->where('status', 'active')->count();
            $post = function ($op, $label, $cls, $confirm = null) use ($s) {
                return '<form method="post" class="inline" action="' . self::e($this->url(['action' => 'server'])) . '"'
                    . ($confirm ? ' onsubmit="return confirm(\'' . $confirm . '\')"' : '') . '>' . $this->token()
                    . '<input type="hidden" name="op" value="' . $op . '"><input type="hidden" name="id" value="' . (int) $s->id . '">'
                    . '<button class="btn btn-xs btn-sm ' . $cls . '">' . $label . '</button></form>';
            };
            echo '<tr><td>' . self::e($s->name) . '</td><td>' . self::e(PanelFactory::TYPES[$s->type]) . '</td><td>' . self::e($s->hostname) . '</td>'
                . '<td>' . self::e($s->account) . '</td><td>' . self::e($s->web_ip) . '</td><td>' . self::e($s->mail_host) . '</td><td>' . $count . '</td>'
                . '<td>' . ($s->is_default ? 'Yes' : '') . '</td><td>' . ($s->active ? 'Yes' : 'No') . '</td><td style="white-space:nowrap">'
                . '<a class="btn btn-xs btn-sm btn-default" href="' . self::e($this->url(['action' => 'server', 'id' => $s->id])) . '">Edit</a> '
                . $post('test', 'Test', 'btn-info') . ' ' . $post('delete', 'Delete', 'btn-danger', 'Delete this server?') . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private function serverForm()
    {
        $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
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
        $secretHint = $s ? 'Leave empty to keep the stored value.' : '';

        echo '<h2>' . ($s ? 'Edit' : 'Add') . ' Server</h2><form method="post" action="' . self::e($this->url(['action' => 'server'])) . '">'
            . $this->token() . '<input type="hidden" name="op" value="save"><input type="hidden" name="id" value="' . $id . '">'
            . '<table class="form" width="100%" cellspacing="2" cellpadding="3">'
            . $row('Name', $text('name', $v('name')))
            . $row('Type', '<select name="type" class="form-control" style="max-width:420px">' . $typeOptions . '</select>')
            . $row('Hostname', $text('hostname', $v('hostname'), 'text', 'server.example.com'))
            . $row('Port', $text('port', $v('port'), 'number'), 'Empty = default (WHM 2087, DirectAdmin 2222, Plesk 8443).')
            . $row('Use SSL', $check('secure', $s ? $s->secure : true))
            . $row('Admin Username', $text('username', $v('username'), 'text', 'root / admin'), 'WHM user, DirectAdmin admin/reseller, or Plesk admin.')
            . $row('Password', $text('password', '', 'password'), $secretHint)
            . $row('API Token / Key', $text('api_token', '', 'password'), 'Recommended. WHM API token, DirectAdmin login key, or Plesk secret key. ' . $secretHint)
            . $row('Account', $text('account', $v('account')), 'cPanel username / DirectAdmin username / Plesk subscription (webspace) domain that will hold the forwarded domains.')
            . $row('Web IP (A record)', $text('web_ip', $v('web_ip')), 'Shown to clients as the required A record.')
            . $row('Mail Host (MX)', $text('mail_host', $v('mail_host')), 'Shown to clients as the required MX record.')
            . $row('FTP Host', $text('ftp_host', $v('ftp_host')), 'DirectAdmin and Plesk only (file upload). Empty = hostname.')
            . $row('FTP Port', $text('ftp_port', $v('ftp_port'), 'number'), 'Empty = 21.')
            . $row('FTP Username', $text('ftp_user', $v('ftp_user')), 'Empty = Account. For Plesk, the subscription\'s system user.')
            . $row('FTP Password', $text('ftp_pass', '', 'password'), $secretHint)
            . $row('FTP over TLS', $check('ftp_ssl', $s ? $s->ftp_ssl : false))
            . $row('Default Server', $check('is_default', $s ? $s->is_default : false), 'New domains are provisioned on the default server.')
            . $row('Active', $check('active', $s ? $s->active : true))
            . '</table><p style="margin-top:10px"><button class="btn btn-primary">Save</button> <a class="btn btn-default" href="'
            . self::e($this->url(['action' => 'servers'])) . '">Cancel</a></p></form>';
    }

    private function logs()
    {
        $q = Capsule::table(Schema::T_LOGS);
        $filter = trim(isset($_GET['q']) ? (string) $_GET['q'] : '');
        if ($filter !== '') {
            $q->where('message', 'like', '%' . $filter . '%');
        }
        if (!empty($_GET['level'])) {
            $q->where('level', $_GET['level']);
        }
        $page = max(1, (int) (isset($_GET['page']) ? $_GET['page'] : 1));
        $total = (clone $q)->count();
        echo '<form method="get" class="form-inline" style="margin-bottom:10px"><input type="hidden" name="module" value="seekahost_forwarding">'
            . '<input type="hidden" name="action" value="logs"><input name="q" class="form-control" placeholder="Search" value="' . self::e($filter) . '"> '
            . '<select name="level" class="form-control"><option value="">All levels</option><option value="info">Info</option><option value="error">Error</option></select> '
            . '<button class="btn btn-default">Filter</button></form>';
        $this->logTable($q->orderBy('id', 'desc')->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE)->get()->all());
        $this->pager($total, $page, ['action' => 'logs', 'q' => $filter, 'level' => isset($_GET['level']) ? $_GET['level'] : '']);
    }

    private function logTable(array $rows)
    {
        echo '<table class="table table-condensed table-striped"><thead><tr><th>Date</th><th>Actor</th><th>Level</th><th>Action</th><th>Client</th><th>Message</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            echo '<tr' . ($r->level === 'error' ? ' class="danger"' : '') . '><td style="white-space:nowrap">' . self::e($r->created_at) . '</td><td>' . self::e($r->actor) . '</td>'
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
        echo '<ul class="pagination">';
        for ($i = 1; $i <= $pages; $i++) {
            echo '<li class="page-item' . ($i === $page ? ' active' : '') . '"><a class="page-link" href="'
                . self::e($this->url(array_merge($params, ['page' => $i]))) . '">' . $i . '</a></li>';
        }
        echo '</ul>';
    }
}
