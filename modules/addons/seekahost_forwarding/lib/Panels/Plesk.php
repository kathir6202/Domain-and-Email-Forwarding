<?php

namespace SeekaHost\Forwarding\Panels;

/**
 * Plesk driver. Each forwarded domain is added as an additional domain (site)
 * inside the subscription named in "account". Uses the XML API for domains and
 * mail, the REST CLI gateway for Let's Encrypt, and FTP for files.
 */
class Plesk extends AbstractPanel
{
    const DOCROOT_BASE = 'seekahost_fwd';

    private $webspaceId;

    protected function defaultPort()
    {
        return 8443;
    }

    private function authHeaders()
    {
        if ($this->token !== '') {
            return ['KEY: ' . $this->token];
        }
        return ['HTTP_AUTH_LOGIN: ' . $this->server->username, 'HTTP_AUTH_PASSWD: ' . $this->password];
    }

    private function xml($inner, $action = 'xml')
    {
        $packet = '<?xml version="1.0" encoding="UTF-8"?><packet>' . $inner . '</packet>';
        $headers = array_merge(['Content-Type: text/xml', 'HTTP_PRETTY_PRINT: TRUE'], $this->authHeaders());
        list(, $body) = $this->http('POST', $this->baseUrl() . '/enterprise/control/agent.php', $headers, $packet, null, $action);

        $prev = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_use_internal_errors($prev);
        if ($xml === false) {
            throw new \RuntimeException('Unexpected response from Plesk: ' . substr(strip_tags($body), 0, 200));
        }
        foreach ($xml->xpath('//status') as $status) {
            if ((string) $status === 'error') {
                $parent = $status->xpath('..');
                $msg = $parent ? (string) $parent[0]->errtext : 'request failed';
                throw new \RuntimeException('Plesk ' . $action . ': ' . $msg);
            }
        }
        return $xml;
    }

    private static function e($value)
    {
        return htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function webspaceId()
    {
        if ($this->webspaceId === null) {
            $xml = $this->xml('<webspace><get><filter><name>' . self::e($this->server->account)
                . '</name></filter><dataset><gen_info/></dataset></get></webspace>', 'webspace.get');
            $ids = $xml->xpath('//webspace/get/result/id');
            if (!$ids) {
                throw new \RuntimeException('Plesk subscription "' . $this->server->account . '" was not found.');
            }
            $this->webspaceId = (int) $ids[0];
        }
        return $this->webspaceId;
    }

    private function siteId($domain, array $ref)
    {
        if (!empty($ref['site_id'])) {
            return (int) $ref['site_id'];
        }
        $xml = $this->xml('<site><get><filter><name>' . self::e($domain) . '</name></filter><dataset><gen_info/></dataset></get></site>', 'site.get');
        $ids = $xml->xpath('//site/get/result/id');
        if (!$ids) {
            throw new \RuntimeException('Plesk site ' . $domain . ' was not found.');
        }
        return (int) $ids[0];
    }

    public function testConnection()
    {
        return 'Connected. Subscription ID: ' . $this->webspaceId();
    }

    public function addDomain($domain)
    {
        $dir = self::DOCROOT_BASE . '/' . $domain;
        try {
            $xml = $this->xml('<site><add><gen_setup><name>' . self::e($domain) . '</name><webspace-id>'
                . $this->webspaceId() . '</webspace-id></gen_setup><hosting><vrt_hst><property><name>www_root</name><value>'
                . self::e($dir) . '</value></property></vrt_hst></hosting></add></site>', 'site.add');
            $ids = $xml->xpath('//site/add/result/id');
            $siteId = $ids ? (int) $ids[0] : $this->siteId($domain, []);
        } catch (\RuntimeException $e) {
            if (!self::isAlreadyExists($e->getMessage())) {
                throw $e;
            }
            $siteId = $this->siteId($domain, []);
        }
        return ['site_id' => $siteId, 'dir' => $dir];
    }

    public function removeDomain($domain, array $ref)
    {
        $this->xml('<site><del><filter><id>' . $this->siteId($domain, $ref) . '</id></filter></del></site>', 'site.del');
    }

    public function deployFiles($domain, array $ref, array $files)
    {
        $this->ftpUpload($ref['dir'], $files);
    }

    public function syncEmailForwarder($domain, array $ref, $local, array $old, array $new)
    {
        $siteId = $this->siteId($domain, $ref);
        if ($old) {
            try {
                $this->xml('<mail><remove><filter><site-id>' . $siteId . '</site-id><name>' . self::e($local)
                    . '</name></filter></remove></mail>', 'mail.remove');
            } catch (\RuntimeException $e) {
                // Not present on the server; continue.
            }
        }
        if (!$new) {
            return;
        }
        $addresses = '';
        foreach ($new as $dest) {
            $addresses .= '<address>' . self::e($dest) . '</address>';
        }
        $this->xml('<mail><create><filter><site-id>' . $siteId . '</site-id><mailname><name>' . self::e($local)
            . '</name><mailbox><enabled>false</enabled></mailbox><forwarding><enabled>true</enabled>' . $addresses
            . '</forwarding></mailname></filter></create></mail>', 'mail.create');
    }

    public function setCatchAll($domain, array $ref, $destination)
    {
        $mode = $destination ? '<forward>' . self::e($destination) . '</forward>' : '<reject/>';
        $this->xml('<mail><set><filter><site-id>' . $this->siteId($domain, $ref) . '</site-id><prefs><nonexistent-user>'
            . $mode . '</nonexistent-user></prefs></filter></set></mail>', 'mail.set');
    }

    public function requestSsl($domain, array $ref)
    {
        $email = \SeekaHost\Forwarding\Settings::get('le_email');
        $params = ['--exec', 'letsencrypt', 'cli.php', '-d', $domain, '-d', 'www.' . $domain];
        if ($email) {
            $params[] = '-m';
            $params[] = $email;
        }
        $headers = ['Content-Type: application/json', 'Accept: application/json'];
        $auth = null;
        if ($this->token !== '') {
            $headers[] = 'X-API-Key: ' . $this->token;
        } else {
            $auth = $this->server->username . ':' . $this->password;
        }
        list($code, $body) = $this->http('POST', $this->baseUrl() . '/api/v2/cli/extension/call', $headers, json_encode(['params' => $params]), $auth, 'letsencrypt');
        $json = json_decode($body, true);
        if ($code >= 400 || (isset($json['code']) && (int) $json['code'] !== 0)) {
            $msg = isset($json['stderr']) ? $json['stderr'] : (isset($json['message']) ? $json['message'] : 'HTTP ' . $code);
            throw new \RuntimeException('Plesk Let\'s Encrypt: ' . trim($msg));
        }
        return 'issued';
    }
}
