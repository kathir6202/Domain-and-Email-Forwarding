<?php

namespace SeekaHost\Forwarding\Panels;

/**
 * Every method throws \RuntimeException with a readable message on failure.
 * $ref is the array returned by addDomain() and stored per domain.
 */
interface PanelInterface
{
    /** @return string human readable success message */
    public function testConnection();

    /** @return bool whether the panel supports an optional feature ("domain_email") */
    public function supports($feature);

    /** @return string[] domains currently present in the configured account */
    public function listDomains();

    /**
     * Adds the domain to the configured account, or adopts it when it already
     * belongs to that account. Must fail when the domain exists elsewhere.
     * @return array reference data needed for later calls
     */
    public function addDomain($domain);

    /** Idempotent: succeeds when the domain is already gone. */
    public function removeDomain($domain, array $ref);

    /** @param array $files filename => content, written to the domain document root */
    public function deployFiles($domain, array $ref, array $files);

    /**
     * Make local@domain forward to exactly $new.
     * @param array|null $old destinations currently on the server, or null when unknown
     */
    public function syncEmailForwarder($domain, array $ref, $local, $old, array $new);

    /** @param string|null $destination null disables the catch-all (reject) */
    public function setCatchAll($domain, array $ref, $destination);

    /** Forward all mail for $domain to the same local part at $destDomain (null removes). */
    public function setDomainForwarder($domain, array $ref, $destDomain);

    /** @return string "issued" or "requested" */
    public function requestSsl($domain, array $ref);
}
