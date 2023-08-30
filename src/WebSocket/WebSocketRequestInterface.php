<?php

/**
 * Part of cati project.
 *
 * @copyright  Copyright (C) 2023 __ORGANIZATION__.
 * @license    __LICENSE__
 */

declare(strict_types=1);

namespace Windwalker\Reactor\WebSocket;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Interface WebSocketRequestInterface
 */
interface WebSocketRequestInterface extends ServerRequestInterface
{
    public function getFd(): int;

    public function getData(): string;
}
