<?php

namespace SeekaHost\Forwarding\Panels;

abstract class AbstractPanel implements PanelInterface
{
    /** @var object server row */
    protected $server;
    protected $password;
    protected $token;
    protected $meta = [];

    public function __construct($server)
    {
        $this->server = $server;
        $this->password = $server->password ? decrypt($server->password) : '';
        $this->token = $server->api_token ? decrypt($server->api_token) : '';
        $this->meta = $server->meta ? (json_decode($server->meta, true) ?: []) : [];
    }

    abstract protected function defaultPort();

    protected function baseUrl()
    {
        $scheme = $this->server->secure ? 'https' : 'http';
        $port = $this->server->port ?: $this->defaultPort();
        return $scheme . '://' . $this->server->hostname . ':' . $port;
    }

    /**
     * @return array [httpCode, body]
     */
    protected function http($method, $url, array $headers = [], $body = null, $basicAuth = null, $logAction = 'request')
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_CONNECTTIMEOUT => 15,
            // Control panels commonly use self-signed certificates on their admin ports.
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
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
        $secrets = array_filter([$this->password, $this->token]);
        logModuleCall(SEEKAHOST_FWD_MODULE, get_class($this) . '::' . $action, $request, $response, '', $secrets);
    }

    protected function ftpUpload($dir, array $files)
    {
        $s = $this->server;
        $pass = $s->ftp_pass ? decrypt($s->ftp_pass) : $this->password;
        Ftp::upload(
            $s->ftp_host ?: $s->hostname,
            $s->ftp_port ?: 21,
            $s->ftp_user ?: $s->account,
            $pass,
            (bool) $s->ftp_ssl,
            $dir,
            $files
        );
    }

    protected static function isAlreadyExists($message)
    {
        return (bool) preg_match('/already exist|already been|exists already|is already/i', $message);
    }
}
