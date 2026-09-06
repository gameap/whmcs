<?php

namespace GameAP\Whmcs\Tests;

use PHPUnit\Framework\TestCase;
use WHMCS\Module\Server\Gameap\Naming;

class NamingTest extends TestCase
{
    public function testLoginIsDeterministicForTheSameClient(): void
    {
        $client = ['id' => 42, 'firstname' => 'Ann', 'lastname' => 'Bee'];

        $this->assertSame('whmcs42', Naming::login('whmcs{client_id}', $client));
        $this->assertSame('whmcs42', Naming::login('whmcs{client_id}', $client));
    }

    public function testLoginStripsCharactersThePanelWouldNotAccept(): void
    {
        $login = Naming::login('{first_name}{last_name}_{client_id}', [
            'id' => 7,
            'firstname' => "O'Br ien",
            'lastname' => 'Ünicode',
        ]);

        $this->assertMatchesRegularExpression('/^[a-z0-9._-]+$/', $login);
        $this->assertStringEndsWith('_7', $login);
    }

    public function testLoginFallsBackWhenTheTemplateYieldsNothing(): void
    {
        $this->assertSame('whmcs9', Naming::login('{first_name}', ['id' => 9, 'firstname' => 'Анна']));
        $this->assertSame('whmcs9', Naming::login('', ['id' => 9]));
    }

    public function testLoginIsLengthBounded(): void
    {
        $login = Naming::login(str_repeat('a', 200) . '{client_id}', ['id' => 1]);

        $this->assertLessThanOrEqual(40, strlen($login));
    }

    public function testServerNameSubstitutesPlaceholders(): void
    {
        $name = Naming::serverName('{game} for {client_name} #{service_id}', [
            '{game}' => 'cs2',
            '{client_name}' => 'Ann Bee',
            '{service_id}' => '1042',
        ]);

        $this->assertSame('cs2 for Ann Bee #1042', $name);
    }

    public function testServerNameDropsControlCharacters(): void
    {
        $name = Naming::serverName("bad\nname\x00here", []);

        $this->assertSame('badnamehere', $name);
    }

    public function testServerNameFallsBackWhenEmpty(): void
    {
        $this->assertSame('Server #5', Naming::serverName('{missing}', ['{service_id}' => '5']));
    }

    public function testGeneratedPasswordSatisfiesThePanelPolicy(): void
    {
        for ($attempt = 0; $attempt < 25; $attempt++) {
            $password = Naming::password();

            $this->assertSame(24, strlen($password));
            $this->assertMatchesRegularExpression('/[a-z]/', $password);
            $this->assertMatchesRegularExpression('/[A-Z]/', $password);
            $this->assertMatchesRegularExpression('/[0-9]/', $password);
            $this->assertMatchesRegularExpression('/[^a-zA-Z0-9]/', $password);
        }
    }

    public function testGeneratedPasswordsDiffer(): void
    {
        $passwords = [];
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $passwords[] = Naming::password();
        }

        $this->assertCount(20, array_unique($passwords));
    }
}
