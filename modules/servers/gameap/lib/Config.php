<?php

namespace WHMCS\Module\Server\Gameap;

/**
 * Product configuration: the frozen option list plus typed access to it.
 *
 * WHMCS stores module options positionally as configoption1..24, so the ORDER
 * of self::OPTIONS is part of the on-disk format. Reordering or removing a key
 * after a release silently shifts every existing product's settings — a
 * customer's "4096 MB RAM" becomes whatever now sits in that slot. Append
 * only, and only up to 24 entries; the panel-side concepts that would blow
 * that budget (start command, docker image, port assignment) are resolved at
 * provisioning time instead of being duplicated per product.
 */
class Config
{
    public const DEFAULT_PORTS_PER_SERVER = 3;

    public const SUSPEND_STOP_AND_BLOCK = 'stop_and_block';
    public const SUSPEND_BLOCK_ONLY = 'block_only';
    public const SUSPEND_STOP_ONLY = 'stop_only';

    public const TERMINATE_DELETE = 'delete';
    public const TERMINATE_DISABLE_ONLY = 'disable_only';

    /**
     * The only settings a configurable option or custom field may override
     * per service. Everything else — which node, which system user, which
     * permissions, what happens on suspend — is the merchant's decision and
     * must not be reachable from a field a customer can fill in at checkout.
     */
    public const OVERRIDABLE = ['slots', 'ram_mb', 'cpu_percent', 'disk_mb', 'game_mod', 'server_name'];

    /**
     * FROZEN ORDER — maps to configoption1..19. Slots 20..24 are reserved.
     * See the class docblock before touching this array.
     */
    public const OPTIONS = [
        'game' => [
            'FriendlyName' => 'Game code',
            'Type' => 'text',
            'Size' => '25',
            'Description' => 'Game code in GameAP, for example cs2 or minecraft',
        ],
        'game_mod' => [
            'FriendlyName' => 'Game mod',
            'Type' => 'text',
            'Size' => '40',
            'Description' => 'Mod name as it appears in GameAP. Blank uses the first mod of the game (alphabetically)',
        ],
        'node_pool' => [
            'FriendlyName' => 'Nodes',
            'Type' => 'text',
            'Size' => '60',
            'Description' => 'Comma-separated node names to place servers on. Blank means any enabled node',
        ],
        'slots' => [
            'FriendlyName' => 'Slots',
            'Type' => 'text',
            'Size' => '6',
            'Description' => 'Player slots. Written into the mod variable configured below',
        ],
        'ram_mb' => [
            'FriendlyName' => 'RAM limit (MB)',
            'Type' => 'text',
            'Size' => '8',
            'Description' => 'Blank leaves the panel value alone; 0 removes the limit',
        ],
        'cpu_percent' => [
            'FriendlyName' => 'CPU limit (%)',
            'Type' => 'text',
            'Size' => '6',
            'Description' => '100 means one full core. Blank leaves the panel value alone; 0 removes the limit',
        ],
        'disk_mb' => [
            'FriendlyName' => 'Disk limit (MB)',
            'Type' => 'text',
            'Size' => '8',
            'Description' => 'Recorded on the server for reference. GameAP does not enforce disk quotas',
        ],
        'port_range' => [
            'FriendlyName' => 'Port range',
            'Type' => 'text',
            'Size' => '20',
            'Description' => 'Range to allocate from, for example 27000-28000',
        ],
        'ports_per_server' => [
            'FriendlyName' => 'Ports per server',
            'Type' => 'text',
            'Size' => '4',
            'Description' => 'Width of the reserved port block: game, query and RCON. Default 3',
        ],
        'server_name' => [
            'FriendlyName' => 'Server name',
            'Type' => 'text',
            'Size' => '60',
            'Description' => 'Template. Placeholders: {game} {service_id} {client_id} {client_name} {domain}',
        ],
        'su_user' => [
            'FriendlyName' => 'Run as user',
            'Type' => 'text',
            'Size' => '25',
            'Description' => 'System user on the node. Blank uses the node default',
        ],
        'server_settings' => [
            'FriendlyName' => 'Mod variables',
            'Type' => 'textarea',
            'Rows' => '4',
            'Cols' => '40',
            'Description' => 'One key=value per line. Use {slots} to insert the slots value',
        ],
        'client_permissions' => [
            'FriendlyName' => 'Client permissions',
            'Type' => 'textarea',
            'Rows' => '4',
            'Cols' => '40',
            'Description' => 'One permission per line. Blank grants the default set',
        ],
        'user_login_template' => [
            'FriendlyName' => 'Panel login',
            'Type' => 'text',
            'Size' => '30',
            'Description' => 'Template for a new panel account. Placeholders: {client_id} {first_name} {last_name}',
        ],
        // A dropdown rather than a checkbox: WHMCS stores an unticked box as
        // an empty string, which is indistinguishable from "never set", so a
        // checkbox that defaults to on can never be turned off.
        'install_on_create' => [
            'FriendlyName' => 'Install on create',
            'Type' => 'dropdown',
            'Options' => 'yes,no',
            'Default' => 'yes',
            'Description' => 'Queue the game installation immediately after creating the server',
        ],
        'start_after_install' => [
            'FriendlyName' => 'Start after install',
            'Type' => 'yesno',
            'Description' => 'Also used when the service is unsuspended',
        ],
        'suspend_mode' => [
            'FriendlyName' => 'On suspend',
            'Type' => 'dropdown',
            'Options' => 'stop_and_block,block_only,stop_only',
            'Default' => 'stop_and_block',
        ],
        'terminate_mode' => [
            'FriendlyName' => 'On terminate',
            'Type' => 'dropdown',
            'Options' => 'delete,disable_only',
            'Default' => 'delete',
            'Description' => 'delete removes the server and its files from the node',
        ],
        'sso_enabled' => [
            'FriendlyName' => 'Panel single sign-on',
            'Type' => 'yesno',
            'Description' => 'Show a "log in to the panel" button in the client area',
        ],
    ];

    /** @var array<string,mixed> */
    private array $params;

    /**
     * @param array<string,mixed> $params
     */
    public function __construct(array $params)
    {
        $this->params = $params;
    }

    /**
     * Resolution order for an overridable key: configurable option, then
     * custom field, then the product-level module setting, then the declared
     * default. The first two are per-service, which is what lets a merchant
     * sell slots or RAM as an upgrade without a separate product. Every other
     * key is read from the product setting only.
     */
    public function raw(string $key): ?string
    {
        if (in_array($key, self::OVERRIDABLE, true)) {
            $override = $this->override($key);
            if ($override !== null) {
                return $override;
            }
        }

        $index = self::optionIndex($key);
        if ($index !== null) {
            $value = $this->params['configoption' . $index] ?? null;
            if ($value !== null && (string) $value !== '') {
                return (string) $value;
            }
        }

        $default = self::OPTIONS[$key]['Default'] ?? null;

        return $default === null || $default === '' ? null : (string) $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->raw($key);

        return $value === null ? $default : trim($value);
    }

    public function int(string $key, int $default = 0): int
    {
        return $this->intOrNull($key) ?? $default;
    }

    /**
     * Distinguishes "not set" (null) from an explicit value, including 0.
     * A blank limit means "leave the panel alone"; a zero means "remove it".
     */
    public function intOrNull(string $key): ?int
    {
        $value = $this->raw($key);

        if ($value === null || !is_numeric(trim($value))) {
            return null;
        }

        return (int) trim($value);
    }

    /**
     * WHMCS sends checkbox state as "on" from a product page and as 1/yes/true
     * from a configurable option, so all of them have to be accepted. An
     * empty value is "not set" and yields the default.
     */
    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->raw($key);

        if ($value === null || trim($value) === '') {
            return $default;
        }

        return in_array(strtolower(trim($value)), ['on', '1', 'yes', 'true'], true);
    }

    /**
     * @return array<int,string>
     */
    public function lines(string $key): array
    {
        $value = $this->raw($key);
        if ($value === null) {
            return [];
        }

        $lines = preg_split('/\R/', $value) ?: [];

        return array_values(array_filter(array_map('trim', $lines), static fn ($line) => $line !== ''));
    }

    /**
     * @return array<int,string>
     */
    public function csv(string $key): array
    {
        $value = $this->raw($key);
        if ($value === null) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $value)), static fn ($i) => $i !== ''));
    }

    /**
     * Parses a "key=value per line" textarea.
     *
     * @return array<string,string>
     */
    public function pairs(string $key): array
    {
        $pairs = [];

        foreach ($this->lines($key) as $line) {
            if (!str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);
            $name = trim($name);

            if ($name !== '') {
                $pairs[$name] = trim($value);
            }
        }

        return $pairs;
    }

    /**
     * @return array{0:int,1:int}|null
     */
    public function portRange(): ?array
    {
        $value = $this->string('port_range');
        if ($value === '') {
            return null;
        }

        if (!preg_match('/^\s*(\d{1,5})\s*-\s*(\d{1,5})\s*$/', $value, $matches)) {
            return null;
        }

        $from = (int) $matches[1];
        $to = (int) $matches[2];

        if ($from < 1 || $to > 65535 || $from > $to) {
            return null;
        }

        return [$from, $to];
    }

    public function portsPerServer(): int
    {
        $value = $this->int('ports_per_server', self::DEFAULT_PORTS_PER_SERVER);

        return $value > 0 ? $value : self::DEFAULT_PORTS_PER_SERVER;
    }

    public function suspendMode(): string
    {
        $mode = $this->string('suspend_mode', self::SUSPEND_STOP_AND_BLOCK);

        return in_array($mode, [
            self::SUSPEND_STOP_AND_BLOCK,
            self::SUSPEND_BLOCK_ONLY,
            self::SUSPEND_STOP_ONLY,
        ], true) ? $mode : self::SUSPEND_STOP_AND_BLOCK;
    }

    public function terminateMode(): string
    {
        $mode = $this->string('terminate_mode', self::TERMINATE_DELETE);

        return $mode === self::TERMINATE_DISABLE_ONLY ? $mode : self::TERMINATE_DELETE;
    }

    /**
     * Positional index of an option, 1-based, matching configoptionN.
     */
    public static function optionIndex(string $key): ?int
    {
        $index = array_search($key, array_keys(self::OPTIONS), true);

        return $index === false ? null : $index + 1;
    }

    /**
     * Structural checks only — anything that needs the panel (does this game
     * exist? is this mod real?) is checked by the provisioner at first use,
     * because WHMCS offers server modules no hook at product-save time.
     *
     * @return array<int,string>
     */
    public function validate(): array
    {
        $errors = [];

        if ($this->string('game') === '') {
            $errors[] = 'Game code is required.';
        }

        foreach (['slots', 'ram_mb', 'cpu_percent', 'disk_mb', 'ports_per_server'] as $key) {
            $value = $this->raw($key);
            if ($value === null || trim($value) === '') {
                continue;
            }

            if (!ctype_digit(trim($value))) {
                $errors[] = self::OPTIONS[$key]['FriendlyName'] . ' must be a non-negative whole number.';
            }
        }

        if ($this->string('port_range') !== '' && $this->portRange() === null) {
            $errors[] = 'Port range must look like 27000-28000, within 1-65535, low value first.';
        }

        foreach ($this->lines('server_settings') as $line) {
            if (!str_contains($line, '=')) {
                $errors[] = 'Mod variables: "' . $line . '" is not in key=value form.';
            }
        }

        return $errors;
    }

    /**
     * A per-service value for one of the OVERRIDABLE keys, or null. Both the
     * option's label ("RAM limit (MB)") and its key ("ram_mb") are accepted
     * as the configurable option / custom field name.
     */
    private function override(string $key): ?string
    {
        $friendly = self::OPTIONS[$key]['FriendlyName'] ?? null;

        foreach (['configoptions', 'customfields'] as $bag) {
            $values = $this->params[$bag] ?? null;
            if (!is_array($values)) {
                continue;
            }

            foreach ([$friendly, $key] as $candidate) {
                if ($candidate === null) {
                    continue;
                }

                if (isset($values[$candidate]) && (string) $values[$candidate] !== '') {
                    return (string) $values[$candidate];
                }
            }
        }

        return null;
    }
}
