<?php
use SeekaHost\Forwarding\Panels\PanelInterface;

class FakePanel implements PanelInterface
{
    public static $domains = [];      // domain => ['files'=>[], 'mail'=>[local=>[dests]], 'catch'=>?, 'dfwd'=>?]
    public static $calls = [];
    public static $fail = [];         // method => message
    public static $supportsDomainEmail = true;

    private function hit($m, $args) {
        self::$calls[] = $m;
        if (isset(self::$fail[$m])) { throw new RuntimeException(self::$fail[$m]); }
    }
    public function testConnection() { $this->hit('test', []); return 'ok'; }
    public function supports($f) { return $f === 'domain_email' && self::$supportsDomainEmail; }
    public function listDomains() { $this->hit('listDomains', []); return array_keys(self::$domains); }
    public function addDomain($d) { $this->hit('addDomain', [$d]); if (!isset(self::$domains[$d])) self::$domains[$d] = ['files' => [], 'mail' => [], 'catch' => 'DEFAULT', 'dfwd' => null]; return ['dir' => 'x/' . $d]; }
    public function removeDomain($d, array $ref) { $this->hit('removeDomain', [$d]); unset(self::$domains[$d]); }
    public function deployFiles($d, array $ref, array $files) { $this->hit('deployFiles', [$d]); if (!isset(self::$domains[$d])) throw new RuntimeException('no domain'); self::$domains[$d]['files'] = $files; }
    public function syncEmailForwarder($d, array $ref, $local, $old, array $new) {
        $this->hit('syncEmail', [$d]);
        if (!isset(self::$domains[$d])) throw new RuntimeException('no domain');
        if ($new) self::$domains[$d]['mail'][$local] = $new; else unset(self::$domains[$d]['mail'][$local]);
    }
    public function setCatchAll($d, array $ref, $dest) { $this->hit('setCatchAll', [$d]); self::$domains[$d]['catch'] = $dest; }
    public function setDomainForwarder($d, array $ref, $dest) { $this->hit('setDomainForwarder', [$d]); self::$domains[$d]['dfwd'] = $dest; }
    public function requestSsl($d, array $ref) { $this->hit('requestSsl', [$d]); return 'requested'; }
}
