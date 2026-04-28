<?php

declare(strict_types=1);

namespace lameco\kunstmaanmigrator\analyze;

use RuntimeException;

/**
 * Thrown by LlmClassifier on any analyze-time AI call failure
 * (missing key, HTTP/timeout, parse error).
 *
 * Marker type — no extra state. The classifier is the SOLE file that
 * touches the Anthropic API surface (D-07-07 runtime-zero-AI invariant).
 *
 * Security invariant (T-2-11): callers MUST NOT pass key material
 * into the exception message; the service itself strips keys from any
 * re-thrown context via sanitiseErrorMessage().
 */
final class MappingProposalException extends RuntimeException
{
}
