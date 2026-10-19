<?php

namespace SeekaHost\Forwarding\Panels;

/**
 * Plesk driver. Each forwarded domain is added as an additional domain (site)
 * inside the subscription named in "account". Uses the XML API for domains and
 * mail, the REST CLI gateway for Let's Encrypt, and FTP (the subscription's
 * system user) for files.
 */
class Plesk extends AbstractPanel
{
    const DOCROOT_BASE = 'seekahost_fwd';

    private $webspaceId;

    protected function defaultPort()
    {
        return $this->server->secure ? 8443 : 8880;
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
        if (isset($xml->system->status) && (string) $xml->system->status === 'error') {
            throw new \RuntimeException('Plesk: ' . (string) $xml->system->errtext . ' (' . (string) $xml->system->errcode . ')');
        }
        foreach ($xml->xpath('//status') as $status) {
            if ((string) $status === 'error') {
                $parent = $status->xpath('..');
                $msg = $parent ? (string) $parent[0]->errtext . ' (' . (string) $parent[0]->errcode . ')' : 'request failed';
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

    /**
     * @return array|null [id, webspaceId] of a site with this name anywhere on the server
     */
    private function findSite($domain)
    {
        try {
            $xml = $this->xml('<site><get><filter><name>' . self::e($domain) . '</name></filter><dataset><gen_info/></dataset></get></site>', 'site.get');
        } catch (\RuntimeException $e) {
            if (self::isNotFound($e->getMessage())) {
                return null;
            }
            throw $e;
        }
        $results = $xml->xpath('//site/get/result');
        if (!$results || !isset($results[0]->id)) {
            return null;
        }
        $r = $results[0];
        $ws = isset($r->data->gen_info->{'webspace-id'}) ? (int) $r->data->gen_info->{'webspace-id'} : 0;
        if ($ws === 0) {
            // Older Plesk versions omit webspace-id: check membership via the subscription's site list.
            $ws = in_array(strtolower($domain), $this->listDomains(), true) ? $this->webspaceId() : -1;
        }
        return [(int) $r->id, $ws];
    }

    /** Site id, guaranteed to belong to our subscription. */
    private function ownSiteId($domain, array $ref)
    {
        $site = $this->findSite($domain);
        if (!$site) {
            throw new \RuntimeException('Plesk site ' . $domain . ' was not found.');
        }
        if ($site[1] !== $this->webspaceId()) {
            throw new \RuntimeException('Plesk site ' . $domain . ' belongs to another subscription; refusing to modify it.');
        }
        return $site[0];
    }

    public function testConnection()
    {
        $msg = 'Connected. Subscription ID: ' . $this->webspaceId();
        $this->ftpTest();
        return $msg . '. FTP login OK.';
    }

    public function listDomains()
    {
        $xml = $this->xml('<site><get><filter><parent-id>' . $this->webspaceId() . '</parent-id></filter><dataset><gen_info/></dataset></get></site>', 'site.list');
        $names = [];
        foreach ($xml->xpath('//site/get/result/data/gen_info/name') as $name) {
            $names[] = (string) $name;
        }
        return self::normaliseDomains($names);
    }

    public function addDomain($domain)
    {
        $dir = self::DOCROOT_BASE . '/' . $domain;
        $site = $this->findSite($domain);
        if ($site) {
            if ($site[1] !== $this->webspaceId()) {
                throw new \RuntimeException('Domain ' . $domain . ' already exists in another Plesk subscription on this server.');
            }
            return ['site_id' => $site[0], 'dir' => $dir];
        }
        $xml = $this->xml('<site><add><gen_setup><name>' . self::e($domain) . '</name><webspace-id>'
            . $this->webspaceId() . '</webspace-id></gen_setup><hosting><vrt_hst><property><name>www_root</name><value>'
            . self::e($dir) . '</value></property></vrt_hst></hosting></add></site>', 'site.add');
        $ids = $xml->xpath('//site/add/result/id');
        return ['site_id' => $ids ? (int) $ids[0] : $this->ownSiteId($domain, []), 'dir' => $dir];
    }

    public function removeDomain($domain, array $ref)
    {
        $site = $this->findSite($domain);
        if (!$site) {
            return;
        }
        if ($site[1] !== $this->webspaceId()) {
            throw new \RuntimeException('Plesk site ' . $domain . ' belongs to another subscription; not removing it.');
        }
        $this->xml('<site><del><filter><id>' . $site[0] . '</id></filter></del></site>', 'site.del');
    }

    public function deployFiles($domain, array $ref, array $files)
    {
        // Remove Plesk's default page so nginx can never serve it instead of the forwarding script.
        $this->ftpUpload($ref['dir'], $files, ['index.html', 'index.htm', 'default.htm']);
    }

    private function createMailForwarder($siteId, $local, array $dests)
    {
        $addresses = '';
        foreach ($dests as $dest) {
            $addresses .= '<address>' . self::e($dest) . '</address>';
        }
        $this->xml('<mail><create><filter><site-id>' . $siteId . '</site-id><mailname><name>' . self::e($local)
            . '</name><mailbox><enabled>false</enabled></mailbox><forwarding><enabled>true</enabled>' . $addresses
            . '</forwarding></mailname></filter></create></mail>', 'mail.create');
    }

    private function removeMailName($siteId, $local)
    {
        try {
            $this->xml('<mail><remove><filter><site-id>' . $siteId . '</site-id><name>' . self::e($local)
                . '</name></filter></remove></mail>', 'mail.remove');
        } catch (\RuntimeException $e) {
            if (!self::isNotFound($e->getMessage())) {
                throw $e;
            }
        }
    }

    public function syncEmailForwarder($domain, array $ref, $local, $old, array $new)
    {
        $siteId = $this->ownSiteId($domain, $ref);
        $this->removeMailName($siteId, $local);
        if (!$new) {
            return;
        }
        try {
            $this->createMailForwarder($siteId, $local, $new);
        } catch (\RuntimeException $e) {
            if ($old) {
                try {
                    $this->createMailForwarder($siteId, $local, $old); // restore previous state
                } catch (\RuntimeException $ignored) {
                }
            }
            throw $e;
        }
    }

    public function setCatchAll($domain, array $ref, $destination)
    {
        $mode = $destination ? '<forward>' . self::e($destination) . '</forward>' : '<reject/>';
        $this->xml('<mail><set_prefs><filter><site-id>' . $this->ownSiteId($domain, $ref) . '</site-id><prefs><nonexistent-user>'
            . $mode . '</nonexistent-user></prefs></filter></set_prefs></mail>', 'mail.set_prefs');
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
        list($code, $body) = $this->http('POST', $this->baseUrl() . '/api/v2/cli/extension/call', $headers, json_encode(['params' => $params]), $auth, 'letsencrypt', 300);
        $json = json_decode($body, true);
        if ($code >= 400 || (is_array($json) && isset($json['code']) && (int) $json['code'] !== 0)) {
            $msg = isset($json['stderr']) ? $json['stderr'] : (isset($json['message']) ? $json['message'] : 'HTTP ' . $code);
            throw new \RuntimeException('Plesk Let\'s Encrypt: ' . trim($msg));
        }
        return is_array($json) && isset($json['code']) ? 'issued' : 'requested';
    }
}
