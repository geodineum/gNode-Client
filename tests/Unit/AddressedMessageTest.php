<?php
declare(strict_types=1);
namespace gCore\gNode\Tests\Unit;

use PHPUnit\Framework\TestCase;
use gCore\gNode\gNodeClient;
use gCore\gNode\Storage\StorageInterface;

/** An addressed message carries `to` as gNode-COMMS reads it, and only one clean mailbox gets that far. */
final class AddressedMessageTest extends TestCase
{
    private function client(array &$added): gNodeClient
    {
        $storage = $this->createMock(StorageInterface::class);
        $storage->method('isConnected')->willReturn(true);
        $storage->method('xAdd')->willReturnCallback(function (string $key, string $id, array $fields) use (&$added) {
            $added[] = [$key, $fields];
            return '1-0';
        });
        return new gNodeClient($storage, 'nierto_com', 'test-node', ['use_fallback' => false, 'metrics_level' => 0]);
    }

    public function testTheAddresseeTravelsAsTheToField(): void
    {
        $added = [];
        $id = $this->client($added)->queueAddressedMessage('planning', ['email' => 'ana@example.invalid', 'name' => 'Ana'],
            ['subject' => 'Your link', 'body' => 'Open it'], ['form_type' => 'planning.invite']);
        $this->assertSame('1-0', $id);
        $this->assertCount(1, $added);
        [$key, $f] = $added[0];
        $this->assertStringStartsWith('{nierto_com}:gnode:comms:', $key);
        $this->assertSame('planning', $f['type']);
        $this->assertSame(['email' => 'ana@example.invalid', 'name' => 'Ana'], json_decode($f['to'], true));
        $this->assertSame(['email'], json_decode($f['dispatch'], true)['channels']);
    }

    public function testAnUnaddressedMessageHasNoToField(): void
    {
        $added = [];
        $this->client($added)->queueCommsMessage('contact', ['name' => 'X'], ['subject' => 's', 'body' => 'b']);
        $this->assertArrayNotHasKey('to', $added[0][1]);
    }

    public function testAnythingButOneCleanMailboxIsRefusedBeforeTheStream(): void
    {
        $added = [];
        $c = $this->client($added);
        foreach ([['email' => "ana@example.invalid\r\nBcc: x@example.invalid"], ['email' => 'a@example.invalid, b@example.invalid'],
                  ['email' => 'not an address'], ['email' => ''], ['email' => 'ana@example.invalid', 'name' => "Ana\r\nBcc: x"]] as $to) {
            $this->assertFalse($c->queueAddressedMessage('planning', $to, ['subject' => 's', 'body' => 'b']), json_encode($to));
        }
        $this->assertSame([], $added);
    }
}
