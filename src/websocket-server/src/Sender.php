<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */
namespace Hyperf\WebSocketServer;

use Hyperf\Contract\ConfigInterface;
use Hyperf\Contract\StdoutLoggerInterface;
use Hyperf\Server\CoroutineServer;
use Hyperf\WebSocketServer\Exception\InvalidMethodException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Swoole\Http\Response;
use Swoole\Server;

/**
 * @method push(int $fd, $data, int $opcode = null, $finish = null)
 * @method disconnect(int $fd, int $code = null, string $reason = null)
 */
class Sender
{
    protected LoggerInterface $logger;

    protected ?int $workerId = null;

    /**
     * @var Response[]
     */
    protected array $responses = [];

    protected bool $isCoroutineServer = false;

    public function __construct(protected ContainerInterface $container)
    {
        $this->logger = $container->get(StdoutLoggerInterface::class);
        if ($config = $container->get(ConfigInterface::class)) {
            $this->isCoroutineServer = $config->get('server.type') === CoroutineServer::class;
        }
    }

    public function __call($name, $arguments)
    {
        [$fd, $method] = $this->getFdAndMethodFromProxyMethod($name, $arguments);

        if ($this->isCoroutineServer) {
            if (isset($this->responses[$fd])) {
                array_shift($arguments);
                if ($method === 'disconnect') {
                    $method = 'close';
                }
                $this->responses[$fd]->{$method}(...$arguments);
                $this->logger->debug("[WebSocket] Worker send to #{$fd}");
            }
            return;
        }

        if (! $this->proxy($fd, $method, $arguments)) {
            $this->sendPipeMessage($name, $arguments);
        }
    }

    public function proxy(int $fd, string $method, array $arguments): bool
    {
        $result = $this->check($fd);
        if ($result) {
            /** @var \Swoole\WebSocket\Server $server */
            $server = $this->getServer();
            $server->{$method}(...$arguments);
            $this->logger->debug("[WebSocket] Worker.{$this->workerId} send to #{$fd}");
        }

        return $result;
    }

    public function setWorkerId(int $workerId): void
    {
        $this->workerId = $workerId;
    }

    public function check($fd): bool
    {
        $info = $this->getServer()->connection_info($fd);

        if (($info['websocket_status'] ?? null) === WEBSOCKET_STATUS_ACTIVE) {
            return true;
        }

        return false;
    }

    public function setResponse(int $fd, ?Response $response): void
    {
        if ($response === null) {
            unset($this->responses[$fd]);
        } else {
            $this->responses[$fd] = $response;
        }
    }

    public function getResponse(int $fd): ?Response
    {
        return $this->responses[$fd] ?? null;
    }

    public function getFdAndMethodFromProxyMethod(string $method, array $arguments): array
    {
        if (! in_array($method, ['push', 'disconnect'])) {
            throw new InvalidMethodException(sprintf('Method [%s] is not allowed.', $method));
        }

        return [(int) $arguments[0], $method];
    }

    protected function getServer(): Server
    {
        return $this->container->get(Server::class);
    }

    protected function sendPipeMessage(string $name, array $arguments): void
    {
        $server = $this->getServer();
        $workerCount = $server->setting['worker_num'] - 1;
        for ($workerId = 0; $workerId <= $workerCount; ++$workerId) {
            if ($workerId !== $this->workerId) {
                $server->sendMessage(new SenderPipeMessage($name, $arguments), $workerId);
                $this->logger->debug("[WebSocket] Let Worker.{$workerId} try to {$name}.");
            }
        }
    }
}
