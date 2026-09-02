<?php
/**
 * Registry for provider-neutral Ispluka payment gateway adapters.
 *
 * Adapters are registered explicitly by the integration layer. This registry
 * never stores credentials and never performs payment or legacy billing writes.
 */
class IsplukaGatewayAdapterRegistry
{
    private $adapters = [];

    public function register(IsplukaGatewayAdapterInterface $adapter)
    {
        $provider = strtolower(trim((string) $adapter->provider()));
        if ($provider === '' || !preg_match('/^[a-z0-9._-]{1,50}$/', $provider)) {
            throw new InvalidArgumentException('Invalid gateway provider.');
        }

        if (isset($this->adapters[$provider])) {
            throw new RuntimeException('Gateway adapter is already registered: ' . $provider);
        }

        $this->adapters[$provider] = $adapter;
        return $this;
    }

    public function has($provider)
    {
        $provider = strtolower(trim((string) $provider));
        return isset($this->adapters[$provider]);
    }

    public function get($provider)
    {
        $provider = strtolower(trim((string) $provider));
        if (!$this->has($provider)) {
            throw new RuntimeException('Gateway adapter is not registered: ' . $provider);
        }

        return $this->adapters[$provider];
    }

    public function providers()
    {
        return array_keys($this->adapters);
    }
}
