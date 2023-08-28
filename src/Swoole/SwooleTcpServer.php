<?php

/**
 * Part of earth project.
 *
 * @copyright  Copyright (C) 2023 __ORGANIZATION__.
 * @license    __LICENSE__
 */

declare(strict_types=1);

namespace Windwalker\Reactor\Swoole;

use Swoole\Http\Response;
use Swoole\Server as SwooleServer;
use Swoole\Server\Port;
use Windwalker\DI\Container;
use Windwalker\Event\EventAwareTrait;
use Windwalker\Http\HttpFactory;
use Windwalker\Http\Server\ServerInterface;
use Windwalker\Reactor\Swoole\Event\AfterReloadEvent;
use Windwalker\Reactor\Swoole\Event\BeforeReloadEvent;
use Windwalker\Reactor\Swoole\Event\BeforeShutdownEvent;
use Windwalker\Reactor\Swoole\Event\CloseEvent;
use Windwalker\Reactor\Swoole\Event\ConnectEvent;
use Windwalker\Reactor\Swoole\Event\FinishEvent;
use Windwalker\Reactor\Swoole\Event\ManagerStartEvent;
use Windwalker\Reactor\Swoole\Event\ManagerStopEvent;
use Windwalker\Reactor\Swoole\Event\PacketEvent;
use Windwalker\Reactor\Swoole\Event\PipeMessageEvent;
use Windwalker\Reactor\Swoole\Event\ReceiveEvent;
use Windwalker\Reactor\Swoole\Event\ShutdownEvent;
use Windwalker\Reactor\Swoole\Event\StartEvent;
use Windwalker\Reactor\Swoole\Event\TaskEvent;
use Windwalker\Reactor\Swoole\Event\WorkerErrorEvent;
use Windwalker\Reactor\Swoole\Event\WorkerExitEvent;
use Windwalker\Reactor\Swoole\Event\WorkerStartEvent;
use Windwalker\Reactor\Swoole\Event\WorkerStopEvent;
use Windwalker\Utilities\Exception\ExceptionFactory;
use Windwalker\Utilities\StrNormalize;

/**
 * The SwooleTcpServer class.
 *
 * @method $this onStart(callable $handler)
 * @method $this onBeforeShutdown(callable $handler)
 * @method $this onShutdown(callable $handler)
 * @method $this onWorkerStart(callable $handler)
 * @method $this onWorkerStop(callable $handler)
 * @method $this onWorkerExit(callable $handler)
 * @method $this onConnect(callable $handler)
 * @method $this onReceive(callable $handler)
 * @method $this onPacket(callable $handler)
 * @method $this onClose(callable $handler)
 * @method $this onTask(callable $handler)
 * @method $this onFinish(callable $handler)
 * @method $this onPipeMessage(callable $handler)
 * @method $this onWorkerError(callable $handler)
 * @method $this onManagerStart(callable $handler)
 * @method $this onManagerStop(callable $handler)
 * @method $this onBeforeReload(callable $handler)
 * @method $this onAfterReload(callable $handler)
 */
class SwooleTcpServer implements ServerInterface
{
    use EventAwareTrait;

    protected ?string $host = null;

    protected array $config = [];

    protected int $mode = SWOOLE_BASE;

    protected int $sockType = SWOOLE_TCP;

    protected ?SwooleServer $swooleServer = null;

    public static string $swooleServerClass = SwooleServer::class;

    protected bool $isSubServer = false;

    public array $eventMapping = [
        'Start' => StartEvent::class,
        'BeforeShutdown' => BeforeShutdownEvent::class,
        'Shutdown' => ShutdownEvent::class,
        'WorkerStart' => WorkerStartEvent::class,
        'WorkerStop' => WorkerStopEvent::class,
        'WorkerExit' => WorkerExitEvent::class,
        'Connect' => ConnectEvent::class,
        'Receive' => ReceiveEvent::class,
        'Packet' => PacketEvent::class,
        'Close' => CloseEvent::class,
        'Task' => TaskEvent::class,
        'Finish' => FinishEvent::class,
        'PipeMessage' => PipeMessageEvent::class,
        'WorkerError' => WorkerErrorEvent::class,
        'ManagerStart' => ManagerStartEvent::class,
        'ManagerStop' => ManagerStopEvent::class,
        'BeforeReload' => BeforeReloadEvent::class,
        'AfterReload' => AfterReloadEvent::class,
    ];

    /**
     * @var array<static>
     */
    protected array $subServers = [];

    protected \Closure $listenCallback;

    public function createSubServer(
        array $middlewares = [],
        ?HttpFactory $httpFactory = null,
        \Closure|null $outputBuilder = null,
    ): static {
        $subServer = new static();
        $subServer->isSubServer = true;

        $this->subServers[] = $subServer;

        return $subServer;
    }

    public function listen(string $host = '0.0.0.0', int $port = 0, array $options = []): void
    {
        if ($this->isSubServer) {
            $this->listenCallback = function (SwooleServer $parentServer) use ($port, $host) {
                $serverPort = $parentServer->listen($host, $port, $this->sockType);

                $this->registerEvents($serverPort);
            };
        } else {
            $servers = [];

            $server = $this->getSwooleServer($host, $port, $this->mode, $this->sockType);

            $this->registerEvents($server);

            foreach ($this->subServers as $subServer) {
                ($subServer->listenCallback)($server, $servers);
            }

            $server->start();
        }
    }

    protected static function keysToCamel(array $args): array
    {
        $newArgs = [];

        foreach ($args as $name => $value) {
            if (str_contains($name, '_')) {
                $name = StrNormalize::toCamelCase($name);
            }

            $newArgs[$name] = $value;
        }

        return $newArgs;
    }

    public function stop(int $workerId = -1, bool $waitEvent = false): void
    {
        $this->getSwooleServer()->stop($workerId, $waitEvent);
    }

    /**
     * @return array
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    public function setConfig(array $config): static
    {
        $this->config = $config;

        return $this;
    }

    public function getSwooleServer(
        string $host = '0.0.0.0',
        int $port = 0,
        int $mode = SWOOLE_BASE,
        int $sockType = SWOOLE_SOCK_TCP
    ): SwooleServer {
        if (!$this->swooleServer) {
            $server = static::createSwooleServer($host, $port, $mode, $sockType);
            $server->set($this->config);

            $this->swooleServer = $server;
        }

        return $this->swooleServer;
    }

    public static function createSwooleServer(
        string $host = '0.0.0.0',
        int $port = 0,
        int $mode = SWOOLE_BASE,
        int $sockType = SWOOLE_SOCK_TCP
    ): SwooleServer {
        $class = static::$swooleServerClass;

        return new $class($host, $port, $mode, $sockType);
    }

    /**
     * @return int
     */
    public function getMode(): int
    {
        return $this->mode;
    }

    /**
     * @param  int  $mode
     *
     * @return  static  Return self to support chaining.
     */
    public function setMode(int $mode): static
    {
        $this->mode = $mode;

        return $this;
    }

    /**
     * @return int
     */
    public function getSockType(): int
    {
        return $this->sockType;
    }

    /**
     * @param  int  $sockType
     *
     * @return  static  Return self to support chaining.
     */
    public function setSockType(int $sockType): static
    {
        $this->sockType = $sockType;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getHost(): ?string
    {
        return $this->host;
    }

    /**
     * @param  string|null  $host
     *
     * @return  static  Return self to support chaining.
     */
    public function setHost(?string $host): static
    {
        $this->host = $host;

        return $this;
    }

    /**
     * @return \Closure|null
     */
    public function getOutputBuilder(): ?\Closure
    {
        return $this->outputBuilder ??= static function (Response $response) {
            return new SwooleOutput($response);
        };
    }

    public static function factory(
        array $config = [],
        array $middlewares = [],
        ?int $mode = null,
        ?int $sockType = null,
    ): \Closure {
        return static function (Container $container) use ($middlewares, $config, $mode, $sockType) {
            $server = $container->newInstance(static::class);
            $server->setMode($mode ?? SWOOLE_PROCESS);
            $server->setSockType($sockType ?? SWOOLE_TCP);
            $server->setConfig($config);
            $server->setMiddlewares($middlewares);
            $server->setMiddlewareResolver(
                function ($entry) use ($container) {
                    if ($entry instanceof \Closure) {
                        return $entry;
                    }

                    return $container->resolve($entry);
                }
            );

            return $server;
        };
    }

    public function isSubServer(): bool
    {
        return $this->isSubServer;
    }

    public function getServersInfo(): array
    {
        $servers = [];
        $swooleServer = $this->getSwooleServer();

        $servers[] = [
            'class' => $swooleServer::class,
            'host' => $swooleServer->host,
            'port' => $swooleServer->port,
            'mode' => $this->mode,
            'sockType' => $this->sockType,
            'config' => $this->config,
        ];

        foreach ($this->subServers as $subServer) {
            $swooleServer = $subServer->getSwooleServer();

            $servers[] = [
                'class' => $swooleServer::class,
                'host' => $swooleServer->host,
                'port' => $swooleServer->port,
                'mode' => $subServer->mode,
                'sockType' => $subServer->sockType,
                'config' => $subServer->config,
            ];
        }

        return $servers;
    }

    public function __call(string $name, array $args)
    {
        if (str_starts_with($name, 'on')) {
            $event = substr($name, 2);

            if ($this->eventMapping[$event] ?? null) {
                $eventClass = $this->eventMapping[$event];

                return $this->on($eventClass, ...$args);
            }
        }

        throw ExceptionFactory::badMethodCall($name);
    }

    protected function registerEvents(SwooleServer|Port $port): void
    {
        // $events = $this->eventMapping;

        // foreach ($events as $event => $eventClass) {
        //     $port->on(
        //         $event,
        //         function (SwooleServer $swooleServer, ...$args) use ($eventClass) {
        //             $eventObject = $args[0] ?? null;
        //
        //             if (is_object($eventObject)) {
        //
        //             }
        //
        //
        //             $args = static::keysToCamel($args);
        //             $args['server'] = $this;
        //             $args['swooleServer'] = $swooleServer;
        //
        //             $this->emit(
        //                 $eventClass,
        //                 $args
        //             );
        //         }
        //     );
        // }

        $server = $this;

        $port->on(
            'start',
            function (SwooleServer $swooleServer) use ($server) {
                return $this->emit(
                    StartEvent::class,
                    compact(
                        'swooleServer',
                        'server'
                    )
                );
            }
        );

        $port->on(
            'beforeShutdown',
            function (SwooleServer $swooleServer) use ($server) {
                $this->emit(
                    BeforeShutdownEvent::class,
                    compact(
                        'swooleServer',
                        'server'
                    )
                );
            }
        );

        $port->on(
            'shutdown',
            function (SwooleServer $swooleServer) use ($server) {
                $this->emit(
                    BeforeShutdownEvent::class,
                    compact(
                        'swooleServer',
                        'server'
                    )
                );
            }
        );

        $port->on(
            'workerStart',
            function (SwooleServer $swooleServer, int $workerId) use ($server) {
                $this->emit(
                    WorkerStartEvent::class,
                    compact(
                        'swooleServer',
                        'server',
                        'workerId'
                    )
                );
            }
        );

        $port->on(
            'workerStop',
            function (SwooleServer $swooleServer, int $workerId) use ($server) {
                $this->emit(
                    WorkerStopEvent::class,
                    compact(
                        'swooleServer',
                        'server',
                        'workerId'
                    )
                );
            }
        );

        $port->on(
            'workerExit',
            function (SwooleServer $swooleServer, int $workerId) use ($server) {
                $this->emit(
                    WorkerExitEvent::class,
                    compact(
                        'swooleServer',
                        'server',
                        'workerId'
                    )
                );
            }
        );

        $port->on(
            'connect',
            function (SwooleServer $swooleServer, int $reactorId) use ($server) {
                $this->emit(
                    ConnectEvent::class,
                    compact(
                        'swooleServer',
                        'server',
                        'reactorId'
                    )
                );
            }
        );

        $port->on(
            'receive',
            function (SwooleServer $swooleServer, int $reactorId, string $data) use ($server) {
                $this->emit(
                    ReceiveEvent::class,
                    compact(
                        'swooleServer',
                        'server',
                        'reactorId',
                        'data'
                    )
                );
            }
        );

        $port->on(
            'packet',
            function (SwooleServer $swooleServer, string $data, array $clientInfo) use ($server) {
                $this->emit(
                    PacketEvent::class,
                    compact(
                        'swooleServer',
                        'server',
                        'data',
                        'clientInfo'
                    )
                );
            }
        );

        $port->on(
            'close',
            function (SwooleServer $swooleServer, int $reactorId) use ($server) {
                $this->emit(
                    CloseEvent::class,
                    compact(
                        'swooleServer',
                        'server',
                        'reactorId'
                    )
                );
            }
        );

        $port->on(
            'task',
            function (SwooleServer $swooleServer, int $taskId, int $srcWorkerId) use ($server) {
                $this->emit(
                    TaskEvent::class,
                    compact(
                        'swooleServer',
                        'server',
                        'taskId',
                        'srcWorkerId'
                    )
                );
            }
        );

        $port->on(
            'finish',
            function (SwooleServer $swooleServer, int $taskId, mixed $data) use ($server) {
                $this->emit(
                    FinishEvent::class,
                    compact(
                        'swooleServer',
                        'server',
                        'taskId',
                        'data'
                    )
                );
            }
        );

        $port->on(
            'pipeMessage',
            function (SwooleServer $swooleServer, int $srcWorkerId, mixed $message) use ($server) {
                $this->emit(
                    PipeMessageEvent::class,
                    compact(
                        'swooleServer',
                        'server',
                        'srcWorkerId',
                        'message'
                    )
                );
            }
        );

        $port->on(
            'workerError',
            function (
                SwooleServer $swooleServer,
                int $workerId,
                int $workerPid,
                int $exitCode,
                int $signal
            ) use (
                $server
            ) {
                $this->emit(
                    PipeMessageEvent::class,
                    compact(
                        'swooleServer',
                        'server',
                        'workerId',
                        'workerPid',
                        'exitCode',
                        'signal'
                    )
                );
            }
        );

        $port->on(
            'managerStart',
            function (SwooleServer $swooleServer) use ($server) {
                $this->emit(
                    ManagerStartEvent::class,
                    compact(
                        'swooleServer',
                        'server'
                    )
                );
            }
        );

        $port->on(
            'managerStop',
            function (SwooleServer $swooleServer) use ($server) {
                $this->emit(
                    ManagerStopEvent::class,
                    compact(
                        'swooleServer',
                        'server'
                    )
                );
            }
        );

        $port->on(
            'beforeReload',
            function (SwooleServer $swooleServer) use ($server) {
                $this->emit(
                    BeforeReloadEvent::class,
                    compact(
                        'swooleServer',
                        'server'
                    )
                );
            }
        );

        $port->on(
            'afterReload',
            function (SwooleServer $swooleServer) use ($server) {
                $this->emit(
                    AfterReloadEvent::class,
                    compact(
                        'swooleServer',
                        'server'
                    )
                );
            }
        );
    }
}
