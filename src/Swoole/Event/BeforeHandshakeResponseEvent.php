<?php

/**
 * Part of Windwalker project.
 *
 * @copyright  Copyright (C) 2023 LYRASOFT.
 * @license    MIT
 */

declare(strict_types=1);

namespace Windwalker\Reactor\Swoole\Event;

use Swoole\Http\Request;
use Swoole\Http\Response;
use Windwalker\Event\AbstractEvent;

/**
 * The BeforeHandshakeResponseEvent class.
 */
class BeforeHandshakeResponseEvent extends AbstractEvent
{
    use ServerEventTrait;

    protected Request $request;

    protected Response $response;

    public function getRequest(): Request
    {
        return $this->request;
    }

    public function setRequest(Request $request): static
    {
        $this->request = $request;

        return $this;
    }

    public function getResponse(): Response
    {
        return $this->response;
    }

    public function setResponse(Response $response): static
    {
        $this->response = $response;

        return $this;
    }
}
