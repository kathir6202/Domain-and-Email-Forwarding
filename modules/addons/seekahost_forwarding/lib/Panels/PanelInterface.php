<?php

namespace SeekaHost\Forwarding\Panels;

/**
 * Every method throws \RuntimeException with a readable message on failure.
 * $ref is the array returned by addDomain() and stored per domain.
 */
interface PanelInterface
{
    public function testConnection();

    /** @return array reference data needed for later calls */
    public function addDomain($domain);

    public function removeDomain($domain, array $ref);

    /** @param array $files filename => content, written to the domain document root */
    public function deployFiles($domain, array $ref, array $files);

    /** Replace the destinations of local@domain: $old are currently active, $new are wanted. */
    public function syncEmailForwarder($domain, array $ref, $local, array $old, array $new);

    /** @param string|null $destination null disables the catch-all (bounce) */
    public function setCatchAll($domain, array $ref, $destination);

    public function requestSsl($domain, array $ref);
}
