<?php

use Adepto\Slim3Init\Container;
use PHPUnit\Framework\TestCase;

final class ContainerCompatibilityTest extends TestCase {

	public function testArrayAccessAndAutowiringRemainCompatibleWithPhpDi(): void {
		$container = new Container();
		$value = new stdClass();
		$container['value'] = $value;

		self::assertTrue(isset($container['value']));
		self::assertSame($value, $container->get('value'));
		self::assertNull($container['missing-entry']);
		self::assertInstanceOf(ContainerTestService::class, $container->get(ContainerTestService::class));

		unset($container['value']);
		self::assertNull($container['value']);
	}

	public function testExistingDynamicPropertiesRemainAvailable(): void {
		$container = new Container();
		$client = new stdClass();
		$container->Client = $client;

		self::assertSame($client, $container->Client);
	}
}

final class ContainerTestService {}
