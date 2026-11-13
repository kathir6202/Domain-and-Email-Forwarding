<?php

namespace SeekaHost\Forwarding\Migration;

use SeekaHost\Forwarding\Panels\Cpanel;

/**
 * Reads and changes the setup a previous forwarding module (e.g. ModulesGarden)
 * left on a cPanel account: parked/addon domains, cPanel redirects and email routing.
 */
class CpanelLegacy
{
    /** @var Cpanel */
    private $cp;
    private $redirects;

    public function __construct(Cpanel $cp)
    {
        $this->cp = $cp;
    }

    /** @return array ['main' => string, 'parked' => string[], 'addon' => [domain => row]] */
    public function inventory()
    {
        $info = (array) $this->cp->call(3, 'DomainInfo', 'list_domains');
        $parked = [];
        foreach ((array) (isset($info['parked_domains']) ? $info['parked_domains'] : []) as $d) {
            $parked[] = strtolower((string) $d);
        }
        $addons = [];
        foreach ((array) $this->cp->call(2, 'AddonDomain', 'listaddondomains') as $row) {
            if (!empty($row['domain'])) {
                $addons[strtolower($row['domain'])] = $row;
            }
        }
        return [
            'main' => strtolower(isset($info['main_domain']) ? (string) $info['main_domain'] : ''),
            'parked' => array_values(array_unique($parked)),
            'addon' => $addons,
        ];
    }

    /** All cPanel redirects of the account (cached for the request). */
    public function redirects()
    {
        if ($this->redirects === null) {
            $this->redirects = [];
            foreach ((array) $this->cp->call(3, 'Mime', 'list_redirects') as $row) {
                if (is_array($row)) {
                    $this->redirects[] = $row;
                }
            }
        }
        return $this->redirects;
    }

    public static function field(array $row, array $keys, $default = '')
    {
        foreach ($keys as $k) {
            if (isset($row[$k]) && $row[$k] !== '' && $row[$k] !== null) {
                return $row[$k];
            }
        }
        return $default;
    }

    public static function redirectDomain(array $row)
    {
        return strtolower((string) self::field($row, ['domain', 'displaydomain', 'urldomain']));
    }

    /** Redirects for $domain (incl. ones stored for www.$domain). */
    public function redirectsFor($domain)
    {
        $out = [];
        foreach ($this->redirects() as $row) {
            $rd = self::redirectDomain($row);
            if ($rd === $domain || $rd === 'www.' . $domain) {
                $out[] = $row;
            }
        }
        return $out;
    }

    /** Redirects that apply to every domain of the account (".*"). */
    public function globalRedirects()
    {
        return array_values(array_filter($this->redirects(), function ($row) {
            $rd = self::redirectDomain($row);
            return $rd === '.*' || $rd === '*' || $rd === 'all';
        }));
    }

    /** @return array[] ['dest' => local@domain, 'forward' => target] */
    public function forwarders($domain)
    {
        $out = [];
        foreach ((array) $this->cp->call(3, 'Email', 'list_forwarders', ['domain' => $domain]) as $row) {
            if (!isset($row['dest'], $row['forward'])) {
                continue;
            }
            $dest = strtolower(trim($row['dest']));
            if (substr($dest, -strlen('@' . $domain)) === '@' . $domain) {
                $out[] = ['dest' => $dest, 'forward' => trim($row['forward'])];
            }
        }
        return $out;
    }

    /** @return string|null destination domain */
    public function domainForwarder($domain)
    {
        foreach ((array) $this->cp->call(3, 'Email', 'list_domain_forwarders', ['domain' => $domain]) as $row) {
            if (isset($row['forward']) && (!isset($row['dest']) || strtolower($row['dest']) === $domain)) {
                return strtolower(trim($row['forward']));
            }
        }
        return null;
    }

    /** @return string raw default address (":fail: msg", ":blackhole:", user@x, account name...) */
    public function defaultAddress($domain)
    {
        foreach ((array) $this->cp->call(3, 'Email', 'list_default_address', ['domain' => $domain]) as $row) {
            if (isset($row['defaultaddress']) && (!isset($row['domain']) || strtolower($row['domain']) === $domain)) {
                return trim((string) $row['defaultaddress']);
            }
        }
        return '';
    }

    /** Remove the legacy domain from the account; its .htaccess redirects stay on disk untouched. */
    public function detach($domain, $kind, ?array $addonRow = null)
    {
        if ($kind === 'parked') {
            $this->cp->call(2, 'Park', 'unpark', ['domain' => $domain]);
            return;
        }
        $row = $addonRow ?: [];
        if (!empty($row['domainkey'])) {
            $key = $row['domainkey'];
        } elseif (!empty($row['subdomain']) && !empty($row['rootdomain'])) {
            $key = $row['subdomain'] . '_' . $row['rootdomain'];
        } else {
            throw new \RuntimeException('Cannot determine the addon domain key for ' . $domain . '.');
        }
        $this->cp->call(2, 'AddonDomain', 'deladdondomain', ['domain' => $domain, 'subdomain' => $key]);
    }

    /** Put the legacy domain back exactly where it was (redirects come back with it). */
    public function reattach($domain, $kind, ?array $addonRow = null)
    {
        if ($kind === 'parked') {
            $this->cp->call(2, 'Park', 'park', ['domain' => $domain]);
            return;
        }
        $row = $addonRow ?: [];
        $dir = (string) self::field($row, ['reldir', 'dir']);
        $dir = preg_replace('#^home:/?#', '', $dir);
        $label = self::originalLabel($row);
        if ($dir === '' || $label === '') {
            throw new \RuntimeException('Cannot restore addon domain ' . $domain . ': original directory or label unknown.');
        }
        $this->cp->call(2, 'AddonDomain', 'addaddondomain', ['newdomain' => $domain, 'subdomain' => $label, 'dir' => $dir]);
    }

    /**
     * The addon domain's internal subdomain exactly as cPanel created it, e.g. "uniquebusinessidea.net"
     * for "uniquebusinessidea.net.maindomain.com" (labels may contain dots).
     */
    public static function originalLabel(array $row)
    {
        $root = strtolower((string) self::field($row, ['rootdomain']));
        $full = strtolower((string) self::field($row, ['fullsubdomain']));
        $label = strtolower((string) self::field($row, ['subdomain']));
        if ($root !== '' && $full !== '' && substr($full, -strlen('.' . $root)) === '.' . $root) {
            return substr($full, 0, -strlen('.' . $root));
        }
        if ($root !== '' && substr($label, -strlen('.' . $root)) === '.' . $root) {
            return substr($label, 0, -strlen('.' . $root));
        }
        return $label;
    }

    private function abs($dir)
    {
        return strpos($dir, '/') === 0 ? rtrim($dir, '/') : rtrim($this->cp->home(), '/') . '/' . trim($dir, '/');
    }

    /** Absolute path of a folder given relative to the account's home directory. */
    public function absPath($dir)
    {
        return $this->abs($dir);
    }

    /** @return array absolute document root => domains using it (main, addon, sub and parked domains) */
    public function docrootMap()
    {
        $map = [];
        $add = function ($root, $domain) use (&$map) {
            $root = rtrim((string) $root, '/');
            if ($root !== '' && $domain !== '') {
                $map[$root][] = strtolower((string) $domain);
            }
        };
        $data = (array) $this->cp->call(3, 'DomainInfo', 'domains_data', ['format' => 'hash']);
        $mainRoot = isset($data['main_domain']['documentroot']) ? $data['main_domain']['documentroot'] : $this->abs('public_html');
        if (!empty($data['main_domain']['domain'])) {
            $add($mainRoot, $data['main_domain']['domain']);
        }
        foreach (['addon_domains', 'sub_domains'] as $key) {
            foreach ((array) (isset($data[$key]) ? $data[$key] : []) as $row) {
                if (is_array($row) && !empty($row['domain'])) {
                    $add(isset($row['documentroot']) ? $row['documentroot'] : '', $row['domain']);
                }
            }
        }
        foreach ((array) (isset($data['parked_domains']) ? $data['parked_domains'] : []) as $row) {
            if (is_array($row)) {
                $add(isset($row['documentroot']) ? $row['documentroot'] : $mainRoot, isset($row['domain']) ? $row['domain'] : '');
            } else {
                $add($mainRoot, $row);
            }
        }
        foreach ($map as $root => $domains) {
            $map[$root] = array_values(array_unique($domains));
        }
        return $map;
    }

    /** @return string[] names of the entries in a folder (relative to home), hidden ones included */
    public function listFolder($dir)
    {
        $names = [];
        foreach ((array) $this->cp->call(3, 'Fileman', 'list_files', ['dir' => $this->abs($dir), 'show_hidden' => 1]) as $row) {
            $name = is_array($row) ? (isset($row['file']) ? $row['file'] : (isset($row['fullpath']) ? basename($row['fullpath']) : '')) : (string) $row;
            if ($name !== '' && $name !== '.' && $name !== '..') {
                $names[] = $name;
            }
        }
        return $names;
    }

    /** Best effort: delete a file or folder (relative to home). */
    public function deletePath($path)
    {
        foreach (['unlink', 'trash'] as $op) {
            try {
                $this->cp->call(2, 'Fileman', 'fileop', ['op' => $op, 'sourcefiles' => $this->abs($path), 'doubledecode' => 0]);
                return;
            } catch (\Throwable $e) {
                // try the next way
            }
        }
    }

    /** @return string|null content of a file in the account (dir relative to home), null if missing */
    public function readFile($dir, $file)
    {
        return $this->cp->readFile($this->abs($dir), $file);
    }

    public function writeFile($dir, $file, $content)
    {
        $this->cp->call(3, 'Fileman', 'save_file_content', ['dir' => $this->abs($dir), 'file' => $file, 'content' => $content]);
    }

    /** Delete a file in the account (no error when it is already gone). */
    public function deleteFile($dir, $file)
    {
        $path = $this->abs($dir) . '/' . $file;
        if ($this->cp->readFile($this->abs($dir), $file) === null) {
            return;
        }
        $last = null;
        foreach (['unlink', 'trash'] as $op) {
            try {
                $this->cp->call(2, 'Fileman', 'fileop', ['op' => $op, 'sourcefiles' => $path, 'doubledecode' => 0]);
                if ($this->cp->readFile($this->abs($dir), $file) === null) {
                    return;
                }
            } catch (\Throwable $e) {
                $last = $e;
            }
        }
        throw new \RuntimeException('Could not delete ' . $path . ($last ? ': ' . $last->getMessage() : ''));
    }

    /** Best effort: remove the FTP account cPanel creates with an addon domain (never its files). */
    public function deleteFtpAccount($label)
    {
        try {
            $this->cp->call(3, 'Ftp', 'delete_ftp', ['user' => $label, 'destroy' => 0]);
        } catch (\Throwable $e) {
            // Not created on this server, or FTP disabled.
        }
    }

    public function deleteRedirect(array $row)
    {
        $params = [
            'domain' => self::redirectDomain($row),
            'src' => (string) self::field($row, ['sourceurl', 'source', 'displaysourceurl'], '/'),
        ];
        $docroot = self::field($row, ['docroot']);
        if ($docroot !== '') {
            $params['docroot'] = $docroot;
        }
        $this->cp->call(3, 'Mime', 'delete_redirect', $params);
        $this->redirects = null;
    }

    /** Re-create email routing from a snapshot (only what is missing now). */
    public function restoreEmail($domain, array $snap)
    {
        $current = [];
        foreach ($this->forwarders($domain) as $f) {
            $current[$f['dest'] . '>' . strtolower($f['forward'])] = true;
        }
        foreach ((array) $snap['forwarders'] as $f) {
            if (!isset($current[$f['dest'] . '>' . strtolower($f['forward'])])) {
                $params = ['domain' => $domain, 'email' => $f['dest']];
                if (filter_var($f['forward'], FILTER_VALIDATE_EMAIL)) {
                    $params['fwdopt'] = 'fwd';
                    $params['fwdemail'] = $f['forward'];
                } elseif (strpos($f['forward'], ':fail:') === 0) {
                    $params['fwdopt'] = 'fail';
                    $params['failmsgs'] = trim(substr($f['forward'], 6));
                } elseif ($f['forward'] === ':blackhole:') {
                    $params['fwdopt'] = 'blackhole';
                } elseif (strpos($f['forward'], '|') === 0) {
                    $params['fwdopt'] = 'pipe';
                    $params['pipefwd'] = ltrim(substr($f['forward'], 1));
                } else {
                    $params['fwdopt'] = 'system';
                    $params['fwdsystem'] = $f['forward'];
                }
                $this->cp->call(3, 'Email', 'add_forwarder', $params);
            }
        }
        if (!empty($snap['domain_forwarder']) && $this->domainForwarder($domain) !== $snap['domain_forwarder']) {
            $this->cp->call(3, 'Email', 'add_domain_forwarder', ['domain' => $domain, 'destdomain' => $snap['domain_forwarder']]);
        }
        $raw = isset($snap['default_address']) ? (string) $snap['default_address'] : '';
        if ($raw !== '' && $this->defaultAddress($domain) !== $raw) {
            $this->cp->setDefaultAddressRaw($domain, $raw);
        }
    }
}
