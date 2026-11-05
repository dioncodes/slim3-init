<?php
	namespace Adepto\Slim3Init\Handlers;

	use Adepto\Slim3Init\Exceptions\InternalErrorException;
	use Adepto\Slim3Init\Helpers\Severity;
	use Adepto\Slim3Init\Request;
	use Slim\ResponseEmitter;

	/**
	 * ShutdownHandler
	 * Catch any errors on PHP shutdown and handle them through an exception handler
	 *
	 * @author     bluefirex
	 * @version    1.0
	 */
	class ShutdownHandler {
		protected Request $request;
		protected ExceptionHandler $exceptionHandler;
		protected bool $displayErrorDetails;
		protected bool $logErrors;
		protected bool $logErrorDetails;

		public function __construct(Request $request, ExceptionHandler $exceptionHandler, bool $displayErrorDetails, bool $logErrors, bool $logErrorDetails) {
			$this->request = $request;
			$this->exceptionHandler = $exceptionHandler;
			$this->displayErrorDetails = $displayErrorDetails;
			$this->logErrors = $logErrors;
			$this->logErrorDetails = $logErrorDetails;
		}

		public static function isFatal(?array $error): bool {
			return $error !== null && in_array($error['type'] ?? null, [
				E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR,
			], true);
		}

		public function __invoke(): void {
			$error = error_get_last();

			// PHP logs nonfatal errors normally; they must not replace a valid response.
			if (self::isFatal($error)) {
				$file = $error['file'] ?? null;
				$line = $error['line'] ?? null;
				$type = $error['type'] ?? null;
				$errorMessage = $error['message'] ?? null;
				$message = 'An internal error happened. >.<';

				if ($this->displayErrorDetails) {
					$message = (new Severity())->getSeverityMessage($type, $errorMessage, $file, $line);
				}

				$response = $this->exceptionHandler->handle($this->request, new InternalErrorException($this->request, $message), $this->displayErrorDetails);

				if (ob_get_length()) {
					ob_clean();
				}

				(new ResponseEmitter())->emit($response);
			}
		}
	}
