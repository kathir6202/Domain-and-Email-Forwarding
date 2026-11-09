<?php
/**
 * In-memory cPanel account for migration tests. State is persisted to a JSON file so the
 * HTTP router (migration_router.php) can serve parked domains (cPanel-style redirects)
 * and this module's addon domains (the generated index.php).
 */
class FakeCpanel extends \SeekaHost\Forwarding\Panels\Cpanel
{
    public static $st;
    public static $file;
    public static $home;
    public static $failOn = [];
    public static $calls = [];

    public static function reset($home, $file)
    {
        self::$home = $home;
        self::$file = $file;
        self::$st = ['main' => 'main.com', 'parked' => [], 'addon' => [], 'redirects' => [], 'fwd' => [], 'dfwd' => [], 'default' => []];
        self::$failOn = [];
        self::persist();
    }

    public static function persist()
    {
        file_put_contents(self::$file, json_encode(self::$st + ['home' => self::$home]));
    }

    protected function whm($function, array $params = [])
    {
        return $function === 'accountsummary' ? ['acct' => [['domain' => self::$st['main']]]] : [];
    }

    private static function dropMail($d)
    {
        unset(self::$st['fwd'][$d], self::$st['dfwd'][$d], self::$st['default'][$d]);
    }

    protected function cpanel($version, $module, $function, array $p = [])
    {
        $key = $module . '::' . $function;
        self::$calls[] = $key;
        if (isset(self::$failOn[$key])) {
            throw new RuntimeException($key . ': ' . self::$failOn[$key]);
        }
        $st = &self::$st;
        $out = [];
        switch ($key) {
            case 'DomainInfo::list_domains':
                $out = ['main_domain' => $st['main'], 'parked_domains' => array_keys($st['parked']), 'addon_domains' => array_keys($st['addon'])];
                break;
            case 'AddonDomain::listaddondomains':
                foreach ($st['addon'] as $d => $a) {
                    $out[] = ['domain' => $d, 'subdomain' => $a['label'], 'rootdomain' => $st['main'], 'fullsubdomain' => $a['label'] . '.' . $st['main'],
                        'domainkey' => $a['label'] . '_' . $st['main'], 'reldir' => 'home:' . $a['dir'], 'dir' => self::$home . '/' . $a['dir']];
                }
                break;
            case 'AddonDomain::addaddondomain':
                $d = $p['newdomain'];
                if (isset($st['addon'][$d]) || isset($st['parked'][$d])) {
                    throw new RuntimeException('The domain ' . $d . ' already exists.');
                }
                $st['addon'][$d] = ['label' => $p['subdomain'], 'dir' => $p['dir']];
                @mkdir(self::$home . '/' . $p['dir'], 0777, true);
                break;
            case 'AddonDomain::deladdondomain':
                $d = $p['domain'];
                if (!isset($st['addon'][$d]) || $p['subdomain'] !== $st['addon'][$d]['label'] . '_' . $st['main']) {
                    throw new RuntimeException('Invalid addon domain key ' . $p['subdomain']);
                }
                unset($st['addon'][$d]);
                self::dropMail($d);
                break;
            case 'Park::park':
                if (isset($st['addon'][$p['domain']])) {
                    throw new RuntimeException('Domain already exists.');
                }
                $st['parked'][$p['domain']] = true;
                break;
            case 'Park::unpark':
                unset($st['parked'][$p['domain']]);
                self::dropMail($p['domain']); // worst case: cPanel drops the domain's mail routing
                break;
            case 'Mime::list_redirects':
                $out = $st['redirects'];
                break;
            case 'Mime::delete_redirect':
                $st['redirects'] = array_values(array_filter($st['redirects'], function ($r) use ($p) {
                    return !($r['domain'] === $p['domain'] && $r['sourceurl'] === $p['src']);
                }));
                break;
            case 'Email::list_forwarders':
                foreach ((array) (isset($st['fwd'][$p['domain']]) ? $st['fwd'][$p['domain']] : []) as $pair) {
                    $out[] = ['dest' => $pair[0], 'forward' => $pair[1]];
                }
                break;
            case 'Email::add_forwarder':
                $email = strpos($p['email'], '@') === false ? $p['email'] . '@' . $p['domain'] : $p['email'];
                $target = $p['fwdopt'] === 'fwd' ? $p['fwdemail'] : ($p['fwdopt'] === 'fail' ? ':fail: ' . $p['failmsgs'] : ':blackhole:');
                $st['fwd'][$p['domain']][] = [strtolower($email), $target];
                break;
            case 'Email::delete_forwarder':
                $d = substr($p['address'], strpos($p['address'], '@') + 1);
                $st['fwd'][$d] = array_values(array_filter((array) (isset($st['fwd'][$d]) ? $st['fwd'][$d] : []), function ($pair) use ($p) {
                    return !($pair[0] === $p['address'] && $pair[1] === $p['forwarder']);
                }));
                break;
            case 'Email::list_domain_forwarders':
                if (!empty($st['dfwd'][$p['domain']])) {
                    $out[] = ['dest' => $p['domain'], 'forward' => $st['dfwd'][$p['domain']]];
                }
                break;
            case 'Email::add_domain_forwarder':
                $st['dfwd'][$p['domain']] = $p['destdomain'];
                break;
            case 'Email::delete_domain_forwarder':
                unset($st['dfwd'][$p['domain']]);
                break;
            case 'Email::list_default_address':
                $out[] = ['domain' => $p['domain'], 'defaultaddress' => isset($st['default'][$p['domain']]) ? $st['default'][$p['domain']] : 'fwduser'];
                break;
            case 'Email::set_default_address':
                $st['default'][$p['domain']] = $p['fwdopt'] === 'fwd' ? $p['fwdemail'] : ($p['fwdopt'] === 'blackhole' ? ':blackhole:' : ($p['fwdopt'] === 'system' ? $p['fwdsystem'] : ':fail: ' . (isset($p['failmsgs']) ? $p['failmsgs'] : '')));
                break;
            case 'Fileman::save_file_content':
                @mkdir($p['dir'], 0777, true);
                file_put_contents(rtrim($p['dir'], '/') . '/' . $p['file'], $p['content']);
                break;
            case 'Variables::get_user_information':
                $out = ['home' => self::$home];
                break;
            case 'SSL::add_autossl_excluded_domains':
            case 'SSL::start_autossl_check':
                break;
            default:
                throw new RuntimeException('FakeCpanel: unhandled ' . $key);
        }
        self::persist();
        return $out;
    }
}
