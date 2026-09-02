<?php
/**
 * Provider-neutral Ispluka gateway adapter contract.
 *
 * Gateway-specific implementations must verify the provider callback first,
 * then return a canonical payload. This layer never writes legacy billing
 * records and never stores raw credentials/secrets.
 */
interface IsplukaGatewayAdapterInterface
{
    /** Return the canonical provider key, e.g. bkash, nagad, sslcommerz. */
    public function provider();

    /**
     * Verify and normalize a provider callback.
     *
     * Canonical return shape:
     *   intent_id       integer
     *   gateway_trx_id  string
     *   amount          decimal string
     *   status          paid|failed|cancelled
     */
    public function verifyAndNormalize(array $payload);
}
