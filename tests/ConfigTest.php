<?php

namespace GameAP\Whmcs\Tests;

use PHPUnit\Framework\TestCase;
use WHMCS\Module\Server\Gameap\Config;

class ConfigTest extends TestCase
{
    public function testOptionOrderIsFrozen(): void
    {
        // The positional mapping to configoption1..N is part of the on-disk
        // format: reordering silently reassigns every existing product's
        // settings. This test is the tripwire for that.
        $this->assertSame([
            'game',
            'game_mod',
            'node_pool',
            'slots',
            'ram_mb',
            'cpu_percent',
            'disk_mb',
            'port_range',
            'ports_per_server',
            'server_name',
            'su_user',
            'server_settings',
            'client_permissions',
            'user_login_template',
            'install_on_create',
            'start_after_install',
            'suspend_mode',
            'terminate_mode',
            'sso_enabled',
        ], array_keys(Config::OPTIONS));

        $this->assertLessThanOrEqual(24, count(Config::OPTIONS), 'WHMCS supports at most 24 module options');
    }

    public function testProductSettingIsReadByPosition(): void
    {
        $config = new Config(['configoption1' => 'cs2']);

        $this->assertSame('cs2', $config->string('game'));
    }

    public function testConfigurableOptionOverridesProductSetting(): void
    {
        $config = new Config([
            'configoption4' => '10',
            'configoptions' => ['Slots' => '32'],
        ]);

        $this->assertSame(32, $config->int('slots'));
    }

    public function testCustomFieldOverridesProductSettingButNotConfigurableOption(): void
    {
        $byCustomField = new Config([
            'configoption4' => '10',
            'customfields' => ['Slots' => '20'],
        ]);
        $this->assertSame(20, $byCustomField->int('slots'));

        $both = new Config([
            'configoption4' => '10',
            'customfields' => ['Slots' => '20'],
            'configoptions' => ['Slots' => '32'],
        ]);
        $this->assertSame(32, $both->int('slots'));
    }

    public function testOptionsAreAlsoAddressableByKey(): void
    {
        $config = new Config(['configoptions' => ['slots' => '48']]);

        $this->assertSame(48, $config->int('slots'));
    }

    /**
     * WHMCS sends "on" from a product checkbox and 1/yes/true from a
     * configurable option, sometimes for the same setting.
     */
    public function testYesNoAcceptsEveryShapeWhmcsSends(): void
    {
        foreach (['on', '1', 'yes', 'true', 'YES', 'On'] as $value) {
            $config = new Config(['configoption19' => $value]);
            $this->assertTrue($config->bool('sso_enabled'), 'value: ' . $value);
        }

        foreach (['off', '0', 'no', 'false'] as $value) {
            $config = new Config(['configoption19' => $value]);
            $this->assertFalse($config->bool('sso_enabled'), 'value: ' . $value);
        }
    }

    public function testBoolFallsBackToTheGivenDefaultWhenUnset(): void
    {
        $config = new Config([]);

        $this->assertTrue($config->bool('start_after_install', true));
        $this->assertFalse($config->bool('start_after_install'));

        // An unticked WHMCS checkbox arrives as an empty string, which must
        // read as "not set", not as "off".
        $this->assertTrue((new Config(['configoption16' => '']))->bool('start_after_install', true));
    }

    /**
     * The option is a yes/no dropdown rather than a checkbox because an
     * unticked checkbox is indistinguishable from "never set": a checkbox
     * that defaults to on could never be turned off.
     */
    public function testInstallOnCreateCanBeTurnedOff(): void
    {
        $this->assertSame('dropdown', Config::OPTIONS['install_on_create']['Type']);

        $this->assertTrue((new Config([]))->bool('install_on_create', true), 'declared default is yes');
        $legacyEmpty = new Config(['configoption15' => '']);
        $this->assertTrue($legacyEmpty->bool('install_on_create', true), 'legacy empty value');
        $legacyCheckbox = new Config(['configoption15' => 'on']);
        $this->assertTrue($legacyCheckbox->bool('install_on_create', true), 'legacy checkbox value');
        $this->assertTrue((new Config(['configoption15' => 'yes']))->bool('install_on_create', true));
        $this->assertFalse((new Config(['configoption15' => 'no']))->bool('install_on_create', true));
    }

    /**
     * A custom field is something a customer may fill in at checkout, so
     * only the settings that describe the tier they bought may come from one.
     */
    public function testOnlyWhitelistedSettingsCanBeOverriddenPerService(): void
    {
        $config = new Config([
            'configoption3' => 'eu-1',
            'configoption11' => 'gameap',
            'configoption17' => 'stop_and_block',
            'configoptions' => ['Nodes' => 'us-9', 'Run as user' => 'root', 'On suspend' => 'stop_only'],
            'customfields' => ['Client permissions' => 'game-server-common', 'Port range' => '1-65535'],
        ]);

        $this->assertSame(['eu-1'], $config->csv('node_pool'));
        $this->assertSame('gameap', $config->string('su_user'));
        $this->assertSame('stop_and_block', $config->suspendMode());
        $this->assertSame([], $config->lines('client_permissions'));
        $this->assertNull($config->portRange());

        foreach (Config::OVERRIDABLE as $key) {
            $this->assertArrayHasKey($key, Config::OPTIONS);
        }
    }

    public function testIntOrNullTellsBlankFromZero(): void
    {
        $this->assertNull((new Config([]))->intOrNull('ram_mb'));
        $this->assertNull((new Config(['configoption5' => '']))->intOrNull('ram_mb'));
        $this->assertNull((new Config(['configoption5' => 'lots']))->intOrNull('ram_mb'));
        $this->assertSame(0, (new Config(['configoption5' => '0']))->intOrNull('ram_mb'));
        $this->assertSame(4096, (new Config(['configoption5' => ' 4096 ']))->intOrNull('ram_mb'));
    }

    public function testNonNumericValuesDoNotBecomeZero(): void
    {
        // Silently coercing "4 GB" to 0 is how a customer ends up with an
        // unlimited or crippled server instead of an error.
        $config = new Config(['configoption5' => '4 GB']);

        $this->assertSame(2048, $config->int('ram_mb', 2048));
        $this->assertContains(
            'RAM limit (MB) must be a non-negative whole number.',
            $config->validate()
        );
    }

    public function testPortRangeParsing(): void
    {
        $this->assertSame([27000, 28000], (new Config(['configoption8' => '27000-28000']))->portRange());
        $this->assertSame([27000, 28000], (new Config(['configoption8' => ' 27000 - 28000 ']))->portRange());

        foreach (['28000-27000', '0-100', '100-70000', 'abc', '27000'] as $bad) {
            $this->assertNull((new Config(['configoption8' => $bad]))->portRange(), 'value: ' . $bad);
        }
    }

    public function testPortsPerServerFallsBackToDefault(): void
    {
        $this->assertSame(3, (new Config([]))->portsPerServer());
        $this->assertSame(3, (new Config(['configoption9' => '0']))->portsPerServer());
        $this->assertSame(5, (new Config(['configoption9' => '5']))->portsPerServer());
    }

    public function testPairsParsesKeyValueTextarea(): void
    {
        $config = new Config(['configoption12' => "maxplayers={slots}\r\nmap = de_dust2\n\nbroken\n"]);

        $this->assertSame(['maxplayers' => '{slots}', 'map' => 'de_dust2'], $config->pairs('server_settings'));
        $this->assertContains(
            'Mod variables: "broken" is not in key=value form.',
            $config->validate()
        );
    }

    public function testCsvSplitsAndTrims(): void
    {
        $config = new Config(['configoption3' => ' eu-1 , eu-2 ,, ']);

        $this->assertSame(['eu-1', 'eu-2'], $config->csv('node_pool'));
    }

    public function testModeAccessorsRejectUnknownValues(): void
    {
        $this->assertSame('stop_and_block', (new Config(['configoption17' => 'nonsense']))->suspendMode());
        $this->assertSame('block_only', (new Config(['configoption17' => 'block_only']))->suspendMode());
        $this->assertSame('delete', (new Config(['configoption18' => 'nonsense']))->terminateMode());
        $this->assertSame('disable_only', (new Config(['configoption18' => 'disable_only']))->terminateMode());
    }

    public function testValidateRequiresGame(): void
    {
        $this->assertContains('Game code is required.', (new Config([]))->validate());
        $this->assertSame([], (new Config(['configoption1' => 'cs2']))->validate());
    }
}
