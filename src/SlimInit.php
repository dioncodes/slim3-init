<?php
	namespace Adepto\Slim3Init;

	use Adepto\Slim3Init\Handlers\{
		Route
	};

	use Adepto\Slim3Init\Factories\SlimInitPsr17Factory;
	use Adepto\Slim3Init\Exceptions\InvalidRouteException;

	use Psr\Http\Message\ServerRequestInterface;
	use Slim\App;

	use Slim\Exception\{
		HttpMethodNotAllowedException,
		HttpNotFoundException
	};

	use Slim\Factory\AppFactory;
	use Slim\Factory\Psr17\Psr17FactoryProvider;
	use Slim\Routing\RouteContext;

	use stdClass;
	use Throwable;
	use InvalidArgumentException;
	use ReflectionClass;
	use ReflectionException;

	/**
	 * SlimInit
	 * Slim initialization handling.
	 *
	 * @author  bluefirex
	 * @version 2.0
	 * @package as.adepto.slim-init
	 */
	class SlimInit {
		protected $container;
		protected $exceptions;
		protected $handlers;
		protected $middleware;
		protected $app;

		/**
		 * Create a SlimInit container.
		 *
		 * This also auto-adds some exceptions:
		 *     - InvalidRequestException: 400
		 *     - UnauthorizedException: 401
		 *     - AccessDeniedException: 403
		 */
		public function __construct() {
			$this->exceptions = [];
			$this->handlers = [];
			$this->middleware = [];
			$this->container = new Container();

			AppFactory::setContainer($this->container);
			Psr17FactoryProvider::addFactory(SlimInitPsr17Factory::class);

			$this->app = AppFactory::create();
			$this->app->addBodyParsingMiddleware();
			$this->app->addRoutingMiddleware();

			$this->container->set('router', $this->app->getRouteCollector()->getRouteParser());

			/*
				Add some default exceptions
			 */
			$this->setException('Adepto\\Slim3Init\\Exceptions\\InvalidRequestException', 400);
			$this->setException('Adepto\\Slim3Init\\Exceptions\\UnauthorizedException', 401);
			$this->setException('Adepto\\Slim3Init\\Exceptions\\AccessDeniedException', 403);

			/*
				Set an empty debug header (disabling this feature essentially)
			 */
			$this->setDebugHeader('');
		}

		/**
		 * Get the Slim container.
		 *
		 * @return Container
		 */
		public function getContainer(): Container {
			return $this->container;
		}

		/**
		 * Set the base path. This is required if Slim is running on a path that is not "/"
		 *
		 * @param string $path
		 *
		 * @return $this
		 */
		public function setBasePath(string $path): self {
			$this->app->setBasePath($path);

			return $this;
		}

		/**
		 * Set the header used for debugging.
		 * If a header is set with the key and value defined here,
		 * it will circumvent any "human friendly" error page and output exception's
		 * details in JSON, so be careful with this.
		 *
		 * @param string $header        Header's Name
		 * @param string $expectedValue Header's Value to trigger debugging
		 */
		public function setDebugHeader(string $header, string $expectedValue = ''): SlimInit {
			if (empty($header)) {
				$this->container->set('debugHeader', null);
			} else {
				$this->container->set('debugHeader', [
					'key'		=>	$header,
					'value'		=>	$expectedValue
				]);
			}

			return $this;
		}

		/**
		 * Set the status code for an exception.
		 *
		 * @param string|array                  $ex                     Exception Class(es)
		 * @param int                           $statusCode             HTTP status code
		 */
		public function setException($ex, int $statusCode): SlimInit {
			if (is_array($ex)) {
				foreach ($ex as $e) {
					$this->setException($e, $statusCode);
				}
			} else {
				$this->exceptions[$ex] = $statusCode;
			}

			return $this;
		}

		/**
		 * Add an item to the container of this slim app.
		 *
		 * @param string $key   Key
		 * @param mixed  $value Value
		 */
		public function addToContainer(string $key, $value): SlimInit {
			$this->container[$key] = $value;

			return $this;
		}

		/**
		 * Add a single handler class.
		 * That class MUST implement getRoutes().
		 * The class will NOT be automatically loaded unless an autoloader is defined
		 * for that class.
		 *
		 * @param string $className Class Name
		 */
		public function addHandler(string $className): SlimInit {
			if (!class_exists($className)) {
				throw new InvalidArgumentException('Could not find class ' . $className);
			}

			$this->handlers[$className] = $className::getRoutes();

			return $this;
		}

		/**
		 * Collect all php files form a directory and put them up as handlers.
		 * This automatically loads them as well.
		 * Does not work with namespaced classes.
		 *
		 * @param string $dir Directory to look in - NO trailing slash!
		 *
		 * @throws ReflectionException
		 */
		public function addHandlersFromDirectory(string $dir): SlimInit {
			if (!is_dir($dir)) {
				throw new InvalidArgumentException('Could not find directory: ' . $dir);
			}

			$handlerFiles = glob($dir . '/*.php');

			foreach ($handlerFiles as $handlerFile) {
				/** @noinspection PhpIncludeInspection */
				require_once $handlerFile;

				$handlerClass = str_replace('.php', '', basename($handlerFile));
				$reflectionClass = new ReflectionClass($handlerClass);

				if (!$reflectionClass->isAbstract()) {
					$this->addHandler($handlerClass);
				}
			}

			return $this;
		}

		/**
		 * Collect all php files from a namespace and put them up as handlers.
		 * Automatically ignores abstract classes.
		 *
		 * Example:
		 * 		$namespace = Adepto\Slim3Init\Handlers
		 * 		$prefix = Adepto\Slim3Init\
		 * 		$directory = /htdocs/adepto-slim3init/src
		 *
		 * @param string $namespace Namespace to add, do not end with a backslash!
		 * @param string $prefix    What is the prefix of this namespace? Include trailing backslash! (works just like in composer)
		 * @param string $directory How is the prefix mapped to the filesystem? Do not end with a slash!
		 */
		public function addPsr4Namespace(string $namespace, string $prefix, string $directory): SlimInit {
			$remainingNamespace = str_replace($prefix, '', $namespace);
			$dirPath = str_replace('\\', DIRECTORY_SEPARATOR, $directory . '/' . $remainingNamespace);

			$handlerFiles = glob($dirPath . '/*.php');

			foreach ($handlerFiles as $handlerFile) {
				/** @noinspection PhpIncludeInspection */
				require_once $handlerFile;

				$handlerClass = str_replace('.php', '', basename($handlerFile));
				$handlerClassPath = $prefix . str_replace(DIRECTORY_SEPARATOR, '\\', $remainingNamespace . '\\' . $handlerClass);

				if (!class_exists($handlerClassPath)) {
					throw new InvalidArgumentException('Could not find class "' . $handlerClassPath . '" in ' . $handlerFile);
				}

				try {
					$reflectionClass = new ReflectionClass($handlerClassPath);

					if (!$reflectionClass->isAbstract()) {
						$this->addHandler($handlerClassPath);
					}
				} catch (ReflectionException $exception) {
					throw new InvalidArgumentException($handlerClass . ' is not a valid class');
				}
			}

			return $this;
		}

		/**
		 * Add slim-compatible middleware.
		 *
		 * @param callable $middleware Middleware to add
		 */
		public function addMiddleware(callable $middleware): SlimInit {
			$this->middleware[] = $middleware;

			return $this;
		}

		/**
		 * Boot up the slim application, add handlers, exceptions and run it.
		 *
		 * @return App
		 */
		public function run(): App {
			$scope = $this;

			// Map the routes from all loaded handlers
			$instances = [];

			foreach ($scope->handlers as $handlerClass => $handlerConfig) {
				if (!isset($instances[$handlerClass])) {
					$instances[$handlerClass] = new $handlerClass($scope->container);
				}

				/** @var $config Handlers\Route */
				foreach ($handlerConfig as $route) {
					if (!$route instanceof Route) {
						throw new InvalidArgumentException('Route must be instance of Adepto\\Slim3Init\\Handlers\\Route');
					}

					$slimRoute = $this->app->map([ $route->getHTTPMethod() ], $route->getURL(), function($request, $response, $args) use($handlerClass, $route, $instances) {
						$method = $route->getClassMethod();
						$argsObject = self::arrayToObject($args);

						foreach ($route->getArguments() as $key => $value) {
							$argsObject->$key = $value;
						}

						if (!is_callable([ $instances[$handlerClass], $method ])) {
							throw new InvalidRouteException($handlerClass . ' defines a route "' . $route->getURL() . '"" for which the handler "' . $route->getClassMethod() . '" is not callable', 1);
						}

						return $instances[$handlerClass]->onRequest($request, $response, $argsObject, [ $instances[$handlerClass], $method ]);
					});

					if (!empty($route->getName())) {
						$slimRoute->setName($route->getName());
					}
				}
			}

			// Add all middleware callables
			foreach ($this->middleware as $middleware) {
				$this->app->add($middleware);
			}

			$scope = $this;

			// Add error handlers
			$errorMiddleware = $this->app->addErrorMiddleware(true, true, true);

			// 404
			$errorMiddleware->setErrorHandler(HttpNotFoundException::class, function(ServerRequestInterface $request) use ($scope) {
				return $scope->handleNotFound(Request::fromSlimRequest($request));
			});

			// 405
			$errorMiddleware->setErrorHandler(HttpMethodNotAllowedException::class, function(ServerRequestInterface $request, Throwable $exception) use ($scope) {
				return $scope->handleMethodNotAllowed(Request::fromSlimRequest($request), $exception);
			});

			// 500 or anything else
			$errorMiddleware->setDefaultErrorHandler(function(ServerRequestInterface $request, Throwable $exception, bool $displayErrorDetails, bool $logErrors, bool $logErrorDetails) use ($scope) {
				return $scope->handleError(Request::fromSlimRequest($request), $exception, $displayErrorDetails);
			});

			$this->app->run();

			return $this->app;
		}

		/***********
		 * HELPERS *
		 ***********/

		/**
		 * Convert an array to an object. This deep-copies everything
		 * from the array to the object.
		 * Note: The object is a reference. If you return this from a method
		 * any other function can modify it!
		 *
		 * @param array  $arr Array to convert
		 *
		 * @return stdClass
		 */
		public static function arrayToObject(array $arr): stdClass {
			$obj = new stdClass();

			foreach ($arr as $key => $val) {
				if (is_array($val)) {
					$obj->$key = self::arrayToObject($val);
				} else {
					$obj->$key = $val;
				}
			}

			return $obj;
		}

		/************
		 * HANDLERS *
		 ************/

		protected function handleError(Request $request, Throwable $t, bool $displayErrorDetails = false): Response {
			$tClass = get_class($t);
			$statusCode = $this->exceptions[$tClass] ?? 500;
			$res = new Response();

			$content = [
				'status'		=>	'error',
				'message'		=>	$t->getMessage()
			];

			if ($t->getCode()) {
				$content['code'] = $t->getCode();
			}

			/*
				Internal errors get more info for developers but less errors for users.
			 */
			if ($statusCode == 500 && $displayErrorDetails) {
				$content['message'] = 'An internal error happened. >.<';

				if ($this->container->has('debugHeader')) {
					$debugHeader = $this->container['debugHeader'];

					if ($request->hasHeader($debugHeader['key']) && $request->getHeader($debugHeader['key'])[0] == $debugHeader['value']) {
						$content['details'] = [
							'exception'		=>	get_class($t),
							'message'		=>	$t->getMessage(),
							'stacktrace'	=>	explode("\n", $t->getTraceAsString())
						];
					}
				}
			}

			return $res->withJson($content, $statusCode);
		}

		protected function handleNotFound(Request $request): Response {
			$res = new Response();

			return $res->withJson([
				'status'		=>	'error',
				'message'		=>	'Page not found.'
			], 404);
		}

		protected function handleMethodNotAllowed(Request $req, Throwable $t): Response {
			$routeContext = RouteContext::fromRequest($req);
			$routingResults = $routeContext->getRoutingResults();
			$methods = $routingResults->getAllowedMethods();
			$res = new Response();

			$res = $res->withJson([
				'status'			=>	'error',
				'message'			=>	'Method not allowed',
				'allowedMethods'	=>	$methods
			], 405);

			$res = $res->withHeader('Allow', implode(', ', $methods));

			return $res;
		}
	}