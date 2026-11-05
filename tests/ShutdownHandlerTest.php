<?php

use Adepto\Slim3Init\Handlers\ShutdownHandler;
use PHPUnit\Framework\TestCase;

final class ShutdownHandlerTest extends TestCase {

	/** @dataProvider errors */
	public function testOnlyFatalErrorsReplaceTheHttpResponse(?array $error, bool $fatal): void {
		self::assertSame($fatal, ShutdownHandler::isFatal($error));
	}

	public static function errors(): array {
		return [
			'clean shutdown' => [null, false],
			'missing severity' => [[], false],
			'warning' => [['type' => E_WARNING], false],
			'notice' => [['type' => E_NOTICE], false],
			'PHP deprecation' => [['type' => E_DEPRECATED], false],
			'user deprecation' => [['type' => E_USER_DEPRECATED], false],
			'fatal error' => [['type' => E_ERROR], true],
			'parse error' => [['type' => E_PARSE], true],
			'core error' => [['type' => E_CORE_ERROR], true],
			'compile error' => [['type' => E_COMPILE_ERROR], true],
			'user fatal error' => [['type' => E_USER_ERROR], true],
			'recoverable error' => [['type' => E_RECOVERABLE_ERROR], true],
		];
	}
}
