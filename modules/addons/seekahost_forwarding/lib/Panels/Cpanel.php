<?php

namespace SeekaHost\Forwarding\Panels;

/**
 * cPanel/WHM driver. Uses WHM credentials (API token recommended) and acts on
 * the cPanel account configured as "account". Each forwarded domain becomes an
 * addon domain of that account with its own document root.
 */
class Cpanel extends AbstractPanel
{
    const DOCROOT_BASE = 'seekahost_fwd';

    private $home;

    protected function defaultPort()
    {
        return $this->server->secure ? 2087 : 2086;
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

    private function whm($function, array $params = [])
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

    private function cpanel($version, $module, $function, array $params = [])
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
            $this->headers(),
            http_build_query($params),
            null,
            $module . '::' . $function
        );
        $json = $this->decode($body);

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
            throw new \RuntimeException($module . '::' . $function . ': ' . $error);
        }
        return isset($result['data']) ? $result['data'] : [];
    }

    private function accountMainDomain()
    {
        $data = $this->whm('accountsummary', ['user' => $this->server->account]);
        if (empty($data['acct'][0]['domain'])) {
            throw new \RuntimeException('cPanel account "' . $this->server->account . '" was not found.');
        }
        return $data['acct'][0]['domain'];
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

    public function testConnection()
    {
        return 'Connected. Account main domain: ' . $this->accountMainDomain();
    }

    public function addDomain($domain)
    {
        $sub = substr(preg_replace('/[^a-z0-9]/', '', strtolower($domain)), 0, 30);
        $dir = 'public_html/' . self::DOCROOT_BASE . '/' . $domain;
        try {
            $this->cpanel(2, 'AddonDomain', 'addaddondomain', [
                'newdomain' => $domain,
                'subdomain' => $sub,
                'dir' => $dir,
            ]);
        } catch (\RuntimeException $e) {
            if (!self::isAlreadyExists($e->getMessage())) {
                throw $e;
            }
        }
        return ['sub' => $sub, 'dir' => $dir, 'main' => $this->accountMainDomain()];
    }

    public function removeDomain($domain, array $ref)
    {
        $main = !empty($ref['main']) ? $ref['main'] : $this->accountMainDomain();
        $this->cpanel(2, 'AddonDomain', 'deladdondomain', [
            'domain' => $domain,
            'subdomain' => $ref['sub'] . '_' . $main,
        ]);
    }

    public function deployFiles($domain, array $ref, array $files)
    {
        $dir = $this->homeDir() . '/' . $ref['dir'];
        foreach ($files as $name => $content) {
            $this->cpanel(3, 'Fileman', 'save_file_content', [
                'dir' => $dir,
                'file' => $name,
                'content' => $content,
            ]);
        }
    }

    public function syncEmailForwarder($domain, array $ref, $local, array $old, array $new)
    {
        $address = $local . '@' . $domain;
        foreach (array_diff($old, $new) as $dest) {
            try {
                $this->cpanel(3, 'Email', 'delete_forwarder', ['address' => $address, 'forwarder' => $dest]);
            } catch (\RuntimeException $e) {
                // Already gone on the server; nothing to remove.
            }
        }
        foreach (array_diff($new, $old) as $dest) {
            $this->cpanel(3, 'Email', 'add_forwarder', [
                'domain' => $domain,
                'email' => $local,
                'fwdopt' => 'fwd',
                'fwdemail' => $dest,
            ]);
        }
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

    public function requestSsl($domain, array $ref)
    {
        // AutoSSL covers addon domains; trigger a check for the account now.
        $this->whm('start_autossl_check_for_one_user', ['username' => $this->server->account]);
        return 'requested';
    }
}
