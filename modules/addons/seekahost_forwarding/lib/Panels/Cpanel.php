<?php

namespace SeekaHost\Forwarding\Panels;

/**
 * cPanel/WHM driver. Uses WHM credentials (API token recommended) and acts on
 * the cPanel account configured as "account". Each forwarded domain becomes an
 * addon domain of that account with its own document root.
 */
class Cpanel extends AbstractPanel
{
    const DOCROOT_BASE = 'public_html/seekahost_fwd';

    private $home;
    private $mainDomain;
    private $forwarders = [];

    protected function defaultPort()
    {
        return $this->server->secure ? 2087 : 2086;
    }

    public function supports($feature)
    {
        return $feature === 'domain_email';
    }

    private function headers()
    {
        if ($this->token !== '') {
            return ['Authorization: whm ' . $this->server->username . ':' . $this->token];
        }
        return ['Authorization: Basic ' . base64_encode($this->server->username . ':' . $this->password)];
    }

    private function decode($body)
    {
        $json = json_decode($body, true);
        if (!is_array($json)) {
            throw new \RuntimeException('Unexpected response from cPanel/WHM: ' . substr(strip_tags($body), 0, 200));
        }
        return $json;
    }

    protected function whm($function, array $params = [])
    {
        $params['api.version'] = 1;
        list(, $body) = $this->http('GET', $this->baseUrl() . '/json-api/' . $function . '?' . http_build_query($params), $this->headers(), null, null, $function);
        $json = $this->decode($body);
        if (empty($json['metadata']['result'])) {
            $reason = isset($json['metadata']['reason']) ? $json['metadata']['reason'] : 'unknown error';
            throw new \RuntimeException('WHM ' . $function . ': ' . $reason);
        }
        return isset($json['data']) ? $json['data'] : [];
    }

    /** Generic cPanel API access for tools such as the migration wizard. */
    public function call($version, $module, $function, array $params = [])
    {
        return $this->cpanel($version, $module, $function, $params);
    }

    protected function cpanel($version, $module, $function, array $params = [])
    {
        $query = [
            'cpanel_jsonapi_user' => $this->server->account,
            'cpanel_jsonapi_apiversion' => $version,
            'cpanel_jsonapi_module' => $module,
            'cpanel_jsonapi_func' => $function,
        ];
        list(, $body) = $this->http(
            'POST',
            $this->baseUrl() . '/json-api/cpanel?' . http_build_query($query),
            array_merge($this->headers(), ['Content-Type: application/x-www-form-urlencoded']),
            http_build_query($params),
            null,
            $module . '::' . $function
        );
        $json = $this->decode($body);

        // Errors raised by the WHM proxy itself (unknown user, permissions...).
        if (!isset($json['result']) && !empty($json['cpanelresult']['error']) && $version == 3) {
            throw new \RuntimeException($module . '::' . $function . ': ' . $json['cpanelresult']['error']);
        }

        if ($version == 3) {
            $result = isset($json['result']) ? $json['result'] : [];
            if (empty($result['status'])) {
                $errors = !empty($result['errors']) ? implode(' ', (array) $result['errors']) : 'unknown error';
                throw new \RuntimeException($module . '::' . $function . ': ' . $errors);
            }
            return isset($result['data']) ? $result['data'] : [];
        }

        $result = isset($json['cpanelresult']) ? $json['cpanelresult'] : [];
        $error = '';
        if (!empty($result['error'])) {
            $error = $result['error'];
        } elseif (isset($result['event']['result']) && !$result['event']['result']) {
            $error = 'request failed';
        } elseif (isset($result['data'][0]['result']) && !$result['data'][0]['result']) {
            $error = isset($result['data'][0]['reason']) ? $result['data'][0]['reason'] : 'request failed';
        }
        if ($error !== '') {
            throw new \RuntimeException($module . '::' . $function . ': ' . strip_tags($error));
        }
        return isset($result['data']) ? $result['data'] : [];
    }

    private function accountMainDomain()
    {
        if ($this->mainDomain === null) {
            $data = $this->whm('accountsummary', ['user' => $this->server->account]);
            if (empty($data['acct'][0]['domain'])) {
                throw new \RuntimeException('cPanel account "' . $this->server->account . '" was not found.');
            }
            $this->mainDomain = strtolower($data['acct'][0]['domain']);
        }
        return $this->mainDomain;
    }

    private function homeDir()
    {
        if ($this->home === null) {
            try {
                $data = $this->cpanel(3, 'Variables', 'get_user_information', ['name' => 'home']);
                $this->home = !empty($data['home']) ? rtrim($data['home'], '/') : '';
            } catch (\RuntimeException $e) {
                $this->home = '';
            }
            if ($this->home === '') {
                $this->home = '/home/' . $this->server->account;
            }
        }
        return $this->home;
    }

    /** @return array domain => addon info */
    private function addonDomains()
    {
        $out = [];
        foreach ((array) $this->cpanel(2, 'AddonDomain', 'listaddondomains') as $row) {
            if (!empty($row['domain'])) {
                $out[strtolower($row['domain'])] = $row;
            }
        }
        return $out;
    }

    /** First label of the addon's internal subdomain. */
    private static function labelOf(array $row)
    {
        foreach (['fullsubdomain', 'subdomain'] as $key) {
            if (!empty($row[$key])) {
                $value = (string) $row[$key];
                $dot = strpos($value, '.');
                return $dot === false ? $value : substr($value, 0, $dot);
            }
        }
        return '';
    }

    public function testConnection()
    {
        $msg = 'Connected. Account main domain: ' . $this->accountMainDomain();
        $this->addonDomains();
        return $msg . ' (addon domain API OK).';
    }

    public function listDomains()
    {
        return array_keys($this->addonDomains());
    }

    public function addDomain($domain)
    {
        $existing = $this->addonDomains();
        $main = $this->accountMainDomain();
        if (isset($existing[$domain])) {
            $row = $existing[$domain];
            $dir = isset($row['reldir']) ? preg_replace('#^home:/?#', '', $row['reldir']) : self::DOCROOT_BASE . '/' . $domain;
            return ['sub' => self::labelOf($row) ?: 'x', 'dir' => $dir, 'main' => $main];
        }

        // Opaque, unique label: the internal subdomain (label.maindomain) must not reveal the customer's domain.
        $sub = 'f' . substr(md5($domain), 0, 12);
        $dir = self::DOCROOT_BASE . '/' . $domain;
        $this->cpanel(2, 'AddonDomain', 'addaddondomain', [
            'newdomain' => $domain,
            'subdomain' => $sub,
            'dir' => $dir,
        ]);
        $this->protectBaseDir();
        $this->excludeFromAutoSsl($sub . '.' . $main);
        return ['sub' => $sub, 'dir' => $dir, 'main' => $main];
    }

    /**
     * Keep the internal label.maindomain name out of AutoSSL certificates, so public
     * Certificate Transparency logs cannot link customer domains to the forwarding account.
     */
    private function excludeFromAutoSsl($name)
    {
        try {
            $this->cpanel(3, 'SSL', 'add_autossl_excluded_domains', ['domains' => $name]);
        } catch (\RuntimeException $e) {
            // Older cPanel versions: hardening only.
        }
    }

    /** Stop directory listing of the folder holding every forwarded docroot. */
    private function protectBaseDir()
    {
        try {
            $dir = $this->homeDir() . '/' . self::DOCROOT_BASE;
            $this->cpanel(3, 'Fileman', 'save_file_content', ['dir' => $dir, 'file' => 'index.html', 'content' => '']);
        } catch (\RuntimeException $e) {
            // Cosmetic hardening only.
        }
    }

    public function removeDomain($domain, array $ref)
    {
        $existing = $this->addonDomains();
        if (!isset($existing[$domain])) {
            return;
        }
        $row = $existing[$domain];
        if (!empty($row['domainkey'])) {
            $key = $row['domainkey'];
        } elseif (!empty($row['subdomain']) && !empty($row['rootdomain'])) {
            $key = $row['subdomain'] . '_' . $row['rootdomain'];
        } else {
            $key = (self::labelOf($row) ?: (isset($ref['sub']) ? $ref['sub'] : '')) . '_' . $this->accountMainDomain();
        }
        $this->cpanel(2, 'AddonDomain', 'deladdondomain', ['domain' => $domain, 'subdomain' => $key]);
    }

    public function deployFiles($domain, array $ref, array $files)
    {
        $dir = $this->homeDir() . '/' . $ref['dir'];
        if (isset($files['.htaccess'])) {
            $files['.htaccess'] = self::keepCpanelBlocks($files['.htaccess'], $this->readFile($dir, '.htaccess'));
        }
        foreach ($files as $name => $content) {
            $this->cpanel(3, 'Fileman', 'save_file_content', [
                'dir' => $dir,
                'file' => $name,
                'content' => $content,
            ]);
        }
    }

    /** @return string|null file content, or null when the file does not exist */
    public function readFile($absDir, $file)
    {
        try {
            $data = $this->cpanel(3, 'Fileman', 'get_file_content', ['dir' => $absDir, 'file' => $file]);
        } catch (\RuntimeException $e) {
            if (preg_match('/no such file|not exist|cannot open|failed to open|not found|could not open|enoent/i', $e->getMessage())) {
                return null;
            }
            throw $e;
        }
        return isset($data['content']) ? (string) $data['content'] : '';
    }

    public function home()
    {
        return $this->homeDir();
    }

    /**
     * cPanel writes its own blocks (MultiPHP handler, php ini directives) into .htaccess files.
     * Keep them when replacing the file, so PHP keeps working exactly as configured in cPanel.
     */
    public static function keepCpanelBlocks($ours, $existing)
    {
        if ($existing === null || $existing === '') {
            return $ours;
        }
        $keep = [];
        $patterns = [
            '/# BEGIN cPanel-generated php ini directives, do not edit.*?# END cPanel-generated php ini directives, do not edit/s',
            '/# php -- BEGIN cPanel-generated handler, do not edit.*?# php -- END cPanel-generated handler, do not edit/s',
        ];
        foreach ($patterns as $re) {
            if (preg_match($re, $existing, $m) && strpos($ours, $m[0]) === false) {
                $keep[] = $m[0];
            }
        }
        return $keep ? rtrim($ours) . "\n\n" . implode("\n\n", $keep) . "\n" : $ours;
    }

    /** Set a domain's default address from its raw cPanel value (user@x, account name, :fail: msg, :blackhole:, |pipe). */
    public function setDefaultAddressRaw($domain, $raw)
    {
        $raw = trim((string) $raw);
        $params = ['domain' => $domain];
        if (filter_var($raw, FILTER_VALIDATE_EMAIL)) {
            $params += ['fwdopt' => 'fwd', 'fwdemail' => $raw];
        } elseif ($raw === ':blackhole:') {
            $params += ['fwdopt' => 'blackhole'];
        } elseif (strpos($raw, ':fail:') === 0) {
            $params += ['fwdopt' => 'fail', 'failmsgs' => trim(substr($raw, 6))];
        } elseif (strpos($raw, '|') === 0) {
            $params += ['fwdopt' => 'pipe', 'pipefwd' => ltrim(substr($raw, 1))];
        } else {
            $params += ['fwdopt' => 'system', 'fwdsystem' => $raw];
        }
        $this->cpanel(3, 'Email', 'set_default_address', $params);
    }

    /** @return string[] current destinations of $address on the server */
    private function serverForwarders($domain, $address)
    {
        if (!isset($this->forwarders[$domain])) {
            $this->forwarders[$domain] = [];
            foreach ((array) $this->cpanel(3, 'Email', 'list_forwarders', ['domain' => $domain]) as $row) {
                if (isset($row['dest'], $row['forward'])) {
                    $this->forwarders[$domain][strtolower($row['dest'])][] = strtolower($row['forward']);
                }
            }
        }
        return isset($this->forwarders[$domain][$address]) ? $this->forwarders[$domain][$address] : [];
    }

    public function syncEmailForwarder($domain, array $ref, $local, $old, array $new)
    {
        $address = $local . '@' . $domain;
        if ($old === null) {
            $old = $this->serverForwarders($domain, $address);
        }
        // Add first, then remove, so a failure never leaves the alias without a destination.
        foreach (array_diff($new, $old) as $dest) {
            $this->cpanel(3, 'Email', 'add_forwarder', [
                'domain' => $domain,
                'email' => $address,
                'fwdopt' => 'fwd',
                'fwdemail' => $dest,
            ]);
        }
        foreach (array_diff($old, $new) as $dest) {
            try {
                $this->cpanel(3, 'Email', 'delete_forwarder', ['address' => $address, 'forwarder' => $dest]);
            } catch (\RuntimeException $e) {
                if (!self::isNotFound($e->getMessage())) {
                    throw $e;
                }
            }
        }
        unset($this->forwarders[$domain]);
    }

    public function setCatchAll($domain, array $ref, $destination)
    {
        $params = ['domain' => $domain];
        if ($destination) {
            $params['fwdopt'] = 'fwd';
            $params['fwdemail'] = $destination;
        } else {
            $params['fwdopt'] = 'fail';
            $params['failmsgs'] = 'No such person at this address';
        }
        $this->cpanel(3, 'Email', 'set_default_address', $params);
    }

    public function setDomainForwarder($domain, array $ref, $destDomain)
    {
        $current = null;
        foreach ((array) $this->cpanel(3, 'Email', 'list_domain_forwarders', ['domain' => $domain]) as $row) {
            if (isset($row['forward'])) {
                $current = strtolower($row['forward']);
            }
        }
        if ($current === $destDomain) {
            return;
        }
        if ($current !== null) {
            $this->cpanel(3, 'Email', 'delete_domain_forwarder', ['domain' => $domain]);
        }
        if ($destDomain) {
            $this->cpanel(3, 'Email', 'add_domain_forwarder', ['domain' => $domain, 'destdomain' => $destDomain]);
        }
    }

    public function requestSsl($domain, array $ref)
    {
        // AutoSSL covers addon domains. The cPanel-level call works for reseller credentials too.
        try {
            $this->cpanel(3, 'SSL', 'start_autossl_check');
        } catch (\RuntimeException $e) {
            $this->whm('start_autossl_check_for_one_user', ['username' => $this->server->account]);
        }
        return 'requested';
    }
}
