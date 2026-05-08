<?php declare(strict_types=1);

namespace Wiswes\Widget\Service;

use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * Thin wrapper around SystemConfigService — keeps the
 * "WiswesWidget.config.<key>" prefix in one place so callers don't
 * accidentally drift on the key namespace and silently read an empty
 * config.
 */
final class ConfigReader
{
    private const PREFIX = 'WiswesWidget.config.';

    public function __construct(private readonly SystemConfigService $systemConfigService)
    {
    }

    public function getString(string $key, ?string $salesChannelId = null, string $default = ''): string
    {
        $value = $this->systemConfigService->getString(self::PREFIX . $key, $salesChannelId);
        return $value !== '' ? $value : $default;
    }

    public function getBool(string $key, ?string $salesChannelId = null, bool $default = false): bool
    {
        $raw = $this->systemConfigService->get(self::PREFIX . $key, $salesChannelId);
        if ($raw === null) {
            return $default;
        }
        return (bool) $raw;
    }

    public function set(string $key, mixed $value, ?string $salesChannelId = null): void
    {
        $this->systemConfigService->set(self::PREFIX . $key, $value, $salesChannelId);
    }

    public function clear(string $key, ?string $salesChannelId = null): void
    {
        $this->systemConfigService->delete(self::PREFIX . $key, $salesChannelId);
    }

    /**
     * @return array{
     *   chatAgentBaseUrl: string,
     *   widgetToken: string,
     *   tenantSlug: string,
     *   injectOnStorefront: bool,
     *   isInstalled: bool
     * }
     */
    public function snapshot(?string $salesChannelId = null): array
    {
        $widgetToken = $this->getString('widgetToken', $salesChannelId);
        return [
            'chatAgentBaseUrl' => $this->getString('chatAgentBaseUrl', $salesChannelId, 'https://app.wiswes.com'),
            'widgetToken' => $widgetToken,
            'tenantSlug' => $this->getString('tenantSlug', $salesChannelId),
            'injectOnStorefront' => $this->getBool('injectOnStorefront', $salesChannelId, true),
            'isInstalled' => $widgetToken !== '',
        ];
    }
}
