<?php

namespace SeekaHost\Forwarding\Panels;

abstract class AbstractPanel implements PanelInterface
{
    /** @var object server row */
    protected $server;
    protected $password;
    protected $token;

    public function __construct($server)
    {
        $this->server = $server;
        $this->password = $server->password ? decrypt($server->password) : '';
        $this->token = $server->api_token ? decrypt($server->api_token) : '';
    }

    abstract protected function defaultPort();

    public function supports($feature)
    {
        return false;
    }

    public function setDomainForwarder($domain, array $ref, $destDomain)
    {
        throw new \RuntimeException('Domain email forwarding is not supported on this server type.');
    }

    protected function baseUrl()
    {
        $scheme = $this->server->secure ? 'https' : 'http';
        $port = $this->server->port ?: $this->defaultPort();
        return $scheme . '://' . $this->server->hostname . ':' . $port;
    }

    /**
     * @return array [httpCode, body]
     */
    protected function http($method, $url, array $headers = [], $body = null, $basicAuth = null, $logAction = 'request', $timeout = 90)
    {
        $verify = !empty($this->server->verify_ssl);
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => $verify,
            CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($basicAuth !== null) {
            curl_setopt($ch, CURLOPT_USERPWD, $basicAuth);
        }
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $this->moduleLog($logAction, $method . ' ' . $url . "\n" . (is_array($body) ? print_r($body, true) : (string) $body), $response);

        if ($response === false) {
            throw new \RuntimeException('Connection to ' . $this->server->hostname . ' failed: ' . $error);
        }
        if ($code === 401 || $code === 403) {
            throw new \RuntimeException('Authentication to ' . $this->server->hostname . ' failed (HTTP ' . $code . ').');
        }
        return [$code, $response];
    }

    protected function moduleLog($action, $request, $response)
    {
        if (!function_exists('logModuleCall')) {
            return;
        }
        $secrets = array_values(array_filter([$this->password, $this->token, $this->ftpPassword(false)]));
        logModuleCall(SEEKAHOST_FWD_MODULE, (new \ReflectionClass($this))->getShortName() . '::' . $action, $request, $response, '', $secrets);
    }

    /** FTP password: the dedicated FTP password, or the panel password only when logging in as the account itself. */
    protected function ftpPassword($required = true)
    {
        $s = $this->server;
        if ($s->ftp_pass) {
            return decrypt($s->ftp_pass);
        }
        $ftpUser = $s->ftp_user ?: $s->account;
        if ($this->password !== '' && $ftpUser === $s->username) {
            return $this->password;
        }
        if ($required) {
            throw new \RuntimeException('FTP password is not configured for server "' . $s->name . '".');
        }
        return '';
    }

    protected function ftpUpload($dir, array $files, array $delete = [])
    {
        $s = $this->server;
        Ftp::upload($s->ftp_host ?: $s->hostname, $s->ftp_port ?: 21, $s->ftp_user ?: $s->account, $this->ftpPassword(), (bool) $s->ftp_ssl, $dir, $files, $delete);
    }

    protected function ftpTest()
    {
        $s = $this->server;
        Ftp::test($s->ftp_host ?: $s->hostname, $s->ftp_port ?: 21, $s->ftp_user ?: $s->account, $this->ftpPassword(), (bool) $s->ftp_ssl);
    }

    protected static function isAlreadyExists($message)
    {
        return (bool) preg_match('/already exist|exists already|already been (added|created|parked)|is already (in use|added|parked|configured|a)/i', $message);
    }

    protected static function isNotFound($message)
    {
        return (bool) preg_match('/does not exist|doesn\'t exist|not exist|no such|not found|cannot find|could not find|unknown domain|object does not|\b1013\b/i', $message);
    }

    protected static function normaliseDomains(array $list)
    {
        $out = [];
        foreach ($list as $d) {
            $d = strtolower(trim((string) $d));
            if ($d !== '' && preg_match('/^[a-z0-9.\-]+\.[a-z0-9\-]+$/', $d)) {
                $out[] = $d;
            }
        }
        return array_values(array_unique($out));
    }
}
