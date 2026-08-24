<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\queue\jobs;

use RuntimeException;

/**
 * Thrown by the queue jobs when a workflow reports a failed result, AFTER the
 * run record has already been marked failed with the workflow's rich failure
 * context. The catch blocks skip re-marking for this type so the richer
 * context isn't overwritten — the throw exists so the queue itself records
 * the job as failed instead of showing a failed batch as green.
 */
final class WorkflowFailedException extends RuntimeException
{
}
