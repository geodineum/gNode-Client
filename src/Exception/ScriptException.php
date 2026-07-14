<?php
declare(strict_types=1);

namespace gCore\gNode\Exception;

/**
 * ScriptException - Exception for script errors
 *
 * @package gCore\gNode\Exception
 */
class ScriptException extends gNodeException
{
    /**
     * Constructor
     *
     * @param string $message Exception message
     * @param int $code Exception code
     * @param \Throwable|null $previous Previous exception
     */
    public function __construct(string $message = "Script error", int $code = 0, \Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
