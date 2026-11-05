<?php

use PHPUnit\Framework\TestCase;

final class ShutdownIntegrationTest extends TestCase {

	/** @dataProvider nonfatalSeverities */
	public function testNonfatalErrorsPreserveResponsesAndLogging(int $severity): void {
		[$status, $output, $log] = $this->runPhp(
			'trigger_error("Synthetic nonfatal marker", ' . $severity . '); echo "successful-response";'
		);

		self::assertSame(0, $status);
		self::assertSame('successful-response', $output);
		self::assertStringContainsString('Synthetic nonfatal marker', $log);
	}

	public static function nonfatalSeverities(): array {
		return [
			'warning' => [E_USER_WARNING],
			'notice' => [E_USER_NOTICE],
			'deprecation' => [E_USER_DEPRECATED],
		];
	}

	public function testDefaultFatalResponseHidesInternalDetails(): void {
		[$status, $output, $log] = $this->runPhp(
			'trigger_error("Synthetic internal fatal marker", E_USER_ERROR);'
		);
		$response = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

		self::assertNotSame(0, $status);
		self::assertSame('error', $response['status']);
		self::assertArrayNotHasKey('details', $response);
		self::assertStringNotContainsString('Synthetic internal fatal marker', $output);
		self::assertStringContainsString('Synthetic internal fatal marker', $log);
	}

	private function runPhp(string $body): array {
		if (!function_exists('proc_open')) {
			self::markTestSkipped('Subprocess regression tests require proc_open.');
		}

		$code = 'require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . ';'
			. 'new \\Adepto\\Slim3Init\\SlimInit();' . $body;
		$process = proc_open(
			[PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_reporting=-1', '-r', $code],
			[0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
			$pipes,
		);
		self::assertIsResource($process);
		fclose($pipes[0]);
		$output = stream_get_contents($pipes[1]);
		$log = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);

		return [proc_close($process), $output, $log];
	}
}
