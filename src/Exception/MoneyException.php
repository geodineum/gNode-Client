<?php
declare(strict_types=1);

namespace gCore\gNode\Exception;

/**
 * MoneyException - Exception for exact-decimal money operations
 *
 * Thrown when:
 * - A value does not have the shape of an exact decimal
 * - The daemon refused an amount, a rate or a reconciliation
 * - The daemon could not be reached, or answered from the topology fallback
 *
 * Every money failure is an exception. There is no path that returns a
 * default, a zero or a partially computed result.
 *
 * @package gCore\gNode\Exception
 */
class MoneyException extends gNodeException
{
}
