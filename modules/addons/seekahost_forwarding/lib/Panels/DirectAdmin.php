<?php

namespace SeekaHost\Forwarding\Panels;

/**
 * DirectAdmin driver. Admin/reseller credentials log in as the configured user
 * ("admin|user"), or user credentials are used directly. Files are uploaded by FTP
 * with the user's own FTP login.
 */
class DirectAdmin extends AbstractPanel
{
    protected function defaultPort()
    {
        return 2222;
    }

    private function login()
    {
        $admin = trim((string) $this->server->username);
        $account = trim((string) $this->server->account);
        $user = $admin === '' ? $account : $admin;
        if ($admin !== '' && $account !== '' && $account !== $admin) {
            $user = $admin . '|' . $account;
        }
        return $user . ':' . ($this->token !== '' ? $this->token : $this->password);
    }

    private function api($command, array $params = [], $method = 'POST')
    {
        $params['json'] = 'yes';
        $url = $this->baseUrl() . '/' . $command;
        $body = null;
        if ($method === 'GET') {
            $url .= '?' . http_build_query($params);
        } else {
            $body = http_build_query($params);
        }
        list(, $response) = $this->http($method, $url, ['Content-Type: application/x-www-form-urlencoded'], $body, $this->login(), $command);

        $data = json_decode($response, true);
        if (!is_array($data)) {
            if (stripos((string) $response, '<html') !== false) {
                throw new \RuntimeException('DirectAdmin ' . $command . ': unexpected HTML response (check credentials, port and that the API is enabled).');
            }
            parse_str((string) $response, $data);
        }
        if ($this->isError($data)) {
            $parts = [];
            foreach (['error', 'text', 'details', 'result'] as $key) {
                if (isset($data[$key]) && is_string($data[$key]) && !in_array(trim($data[$key]), ['', '0', '1'], true)) {
                    $parts[] = trim(strip_tags(urldecode($data[$key])));
                }
            }
            throw new \RuntimeException('DirectAdmin ' . $command . ': ' . ($parts ? implode(' - ', array_unique($parts)) : 'request failed'));
        }
        return $data;
    }

    /** Legacy responses use error=1; JSON responses put the message itself in "error". */
    private function isError(array $data)
    {
        if (!array_key_exists('error', $data)) {
            return false;
        }
        $error = $data['error'];
        if ($error === 0 || $error === false || $error === null) {
            return false;
        }
        return trim((string) $error) !== '' && (string) $error !== '0';
    }

    public function testConnection()
    {
        $count = count($this->listDomains());
        $this->ftpTest();
        return 'Connected to DirectAdmin as ' . $this->server->account . ' (' . $count . ' domains). FTP login OK.';
    }

    public function listDomains()
    {
        $data = $this->api('CMD_API_SHOW_DOMAINS', [], 'GET');
        $list = [];
        if (isset($data['list']) && is_array($data['list'])) {
            $list = $data['list'];
        } else {
            foreach ($data as $key => $value) {
                $list[] = is_string($value) ? $value : '';
                if (is_string($key) && strpos($key, '.') !== false) {
                    $list[] = $key;
                }
            }
        }
        return self::normaliseDomains($list);
    }

    public function addDomain($domain)
    {
        $ref = ['dir' => 'domains/' . $domain . '/public_html', 'ssl_dir' => 'domains/' . $domain . '/private_html'];
        if (in_array($domain, $this->listDomains(), true)) {
            return $ref;
        }
        $this->api('CMD_API_DOMAIN', [
            'action' => 'create',
            'domain' => $domain,
            'ubandwidth' => 'unlimited',
            'uquota' => 'unlimited',
            'ssl' => 'ON',
            'cgi' => 'OFF',
            'php' => 'ON',
        ]);
        return $ref;
    }

    public function removeDomain($domain, array $ref)
    {
        if (!in_array($domain, $this->listDomains(), true)) {
            return;
        }
        $this->api('CMD_API_DOMAIN', ['delete' => 'yes', 'confirmed' => 'yes', 'select0' => $domain]);
    }

    public function deployFiles($domain, array $ref, array $files)
    {
        // Remove DirectAdmin's default page so it can never shadow the forwarding script.
        $this->ftpUpload($ref['dir'], $files, ['index.html', 'index.htm']);
        // HTTPS is served from private_html unless it is a symlink to public_html; uploading covers both cases.
        $sslDir = isset($ref['ssl_dir']) ? $ref['ssl_dir'] : 'domains/' . $domain . '/private_html';
        try {
            $this->ftpUpload($sslDir, $files, ['index.html', 'index.htm']);
        } catch (\RuntimeException $e) {
            // private_html may be a symlink that some FTP servers refuse to write through; public_html already done.
        }
    }

    public function syncEmailForwarder($domain, array $ref, $local, $old, array $new)
    {
        if (!$new) {
            try {
                $this->api('CMD_API_EMAIL_FORWARDERS', ['action' => 'delete', 'domain' => $domain, 'select0' => $local]);
            } catch (\RuntimeException $e) {
                if (!self::isNotFound($e->getMessage())) {
                    throw $e;
                }
            }
            return;
        }
        $params = ['domain' => $domain, 'user' => $local, 'email' => implode(',', $new)];
        $params['action'] = $old ? 'modify' : 'create';
        try {
            $this->api('CMD_API_EMAIL_FORWARDERS', $params);
        } catch (\RuntimeException $e) {
            // Unknown or stale server state: retry with the other action.
            $retryable = $params['action'] === 'create' ? self::isAlreadyExists($e->getMessage()) : self::isNotFound($e->getMessage());
            if (!$retryable && $old !== null) {
                throw $e;
            }
            $params['action'] = $params['action'] === 'create' ? 'modify' : 'create';
            $this->api('CMD_API_EMAIL_FORWARDERS', $params);
        }
    }

    public function setCatchAll($domain, array $ref, $destination)
    {
        $this->api('CMD_API_EMAIL_CATCH_ALL', [
            'domain' => $domain,
            'update' => 'Update',
            'catch' => $destination ? 'address' : ':fail:',
            'value' => $destination ? $destination : '',
        ]);
    }

    public function requestSsl($domain, array $ref)
    {
        $this->api('CMD_API_SSL', [
            'domain' => $domain,
            'action' => 'save',
            'type' => 'create',
            'request' => 'letsencrypt',
            'name' => $domain,
            'le_select0' => $domain,
            'le_select1' => 'www.' . $domain,
            'keysize' => 'secp384r1',
            'encryption' => 'sha256',
            'background' => 'auto',
        ]);
        return 'requested';
    }
}
