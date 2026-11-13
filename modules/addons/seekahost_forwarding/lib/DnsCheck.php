<?php

namespace SeekaHost\Forwarding;

class DnsCheck
{
    /** True when the domain's A record points at the server (or no web IP is configured to compare). */
    public static function pointsHere($domain, $server)
    {
        if (!$server || $server->web_ip === '') {
            return true;
        }
        foreach ((array) @dns_get_record($domain, DNS_A) as $rec) {
            if (isset($rec['ip']) && $rec['ip'] === $server->web_ip) {
                return true;
            }
        }
        return false;
    }

    /**
     * The server's nameservers (alternative to the A/MX records), with live status.
     * @return array[] each: label, value, ok
     */
    public static function nameservers($domain, $server)
    {
        if (!$server) {
            return [];
        }
        $wanted = [];
        foreach (['ns1', 'ns2'] as $field) {
            if (!empty($server->$field)) {
                $wanted[] = strtolower(rtrim(trim($server->$field), '.'));
            }
        }
        if (!$wanted) {
            return [];
        }
        $found = [];
        foreach ((array) @dns_get_record($domain, DNS_NS) as $rec) {
            if (isset($rec['target'])) {
                $found[] = strtolower(rtrim($rec['target'], '.'));
            }
        }
        $rows = [];
        foreach ($wanted as $i => $ns) {
            $rows[] = ['label' => 'Nameserver ' . ($i + 1), 'value' => $ns, 'ok' => in_array($ns, $found, true)];
        }
        return $rows;
    }

    /**
     * Records the client must have for forwarding to work, with live status.
     * @return array[] each: type, host, value, ok
     */
    public static function records($domain, $server)
    {
        if (!$server) {
            return [];
        }
        $rows = [];
        if ($server->web_ip !== '') {
            foreach ([$domain, 'www.' . $domain] as $host) {
                $found = [];
                foreach ((array) @dns_get_record($host, DNS_A) as $rec) {
                    if (isset($rec['ip'])) {
                        $found[] = $rec['ip'];
                    }
                }
                $rows[] = [
                    'type' => 'A',
                    'host' => $host === $domain ? '@' : 'www',
                    'value' => $server->web_ip,
                    'ok' => in_array($server->web_ip, $found, true),
                ];
            }
        }
        if ($server->mail_host !== '') {
            $found = [];
            foreach ((array) @dns_get_record($domain, DNS_MX) as $rec) {
                if (isset($rec['target'])) {
                    $found[] = strtolower(rtrim($rec['target'], '.'));
                }
            }
            $rows[] = [
                'type' => 'MX (priority 10)',
                'host' => '@',
                'value' => $server->mail_host,
                'ok' => in_array(strtolower($server->mail_host), $found, true),
            ];
        }
        return $rows;
    }
}
