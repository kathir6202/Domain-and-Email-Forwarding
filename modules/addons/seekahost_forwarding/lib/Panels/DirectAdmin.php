<?php

namespace SeekaHost\Forwarding\Panels;

/**
 * DirectAdmin driver. Admin/reseller credentials log in as the configured user
 * ("admin|user"), or user credentials are used directly. Files are uploaded by FTP.
 */
class DirectAdmin extends AbstractPanel
{
    protected function defaultPort()
    {
        return 2222;
    }

    private function login()
    {
        $user = $this->server->username;
        if ($this->server->account !== '' && $this->server->account !== $user) {
            $user .= '|' . $this->server->account;
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
            parse_str((string) $response, $data);
        }
        if (stripos((string) $response, '<html') !== false && empty($data['error'])) {
            throw new \RuntimeException('DirectAdmin ' . $command . ': unexpected HTML response (check credentials/port).');
        }
        if (isset($data['error']) && (string) $data['error'] !== '0') {
            $msg = isset($data['details']) ? $data['details'] : (isset($data['text']) ? $data['text'] : 'request failed');
            throw new \RuntimeException('DirectAdmin ' . $command . ': ' . strip_tags(urldecode($msg)));
        }
        return $data;
    }

    public function testConnection()
    {
        $this->api('CMD_API_SHOW_DOMAINS', [], 'GET');
        return 'Connected to DirectAdmin as ' . $this->server->account . '.';
    }

    public function addDomain($domain)
    {
        try {
            $this->api('CMD_API_DOMAIN', [
                'action' => 'create',
                'domain' => $domain,
                'ubandwidth' => 'unlimited',
                'uquota' => 'unlimited',
                'ssl' => 'ON',
                'cgi' => 'OFF',
                'php' => 'ON',
            ]);
        } catch (\RuntimeException $e) {
            if (!self::isAlreadyExists($e->getMessage())) {
                throw $e;
            }
        }
        return ['dir' => 'domains/' . $domain . '/public_html'];
    }

    public function removeDomain($domain, array $ref)
    {
        $this->api('CMD_API_DOMAIN', ['delete' => 'yes', 'confirmed' => 'yes', 'select0' => $domain]);
    }

    public function deployFiles($domain, array $ref, array $files)
    {
        $this->ftpUpload($ref['dir'], $files);
    }

    public function syncEmailForwarder($domain, array $ref, $local, array $old, array $new)
    {
        if (!$new) {
            $this->api('CMD_API_EMAIL_FORWARDERS', ['action' => 'delete', 'domain' => $domain, 'select0' => $local]);
            return;
        }
        $params = ['domain' => $domain, 'user' => $local, 'email' => implode(',', $new)];
        $params['action'] = $old ? 'modify' : 'create';
        try {
            $this->api('CMD_API_EMAIL_FORWARDERS', $params);
        } catch (\RuntimeException $e) {
            if ($params['action'] !== 'create' || !self::isAlreadyExists($e->getMessage())) {
                throw $e;
            }
            $params['action'] = 'modify';
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
