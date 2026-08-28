<?php

declare(strict_types=1);

namespace App\Scraping\Exceptions;

use RuntimeException;

/**
 * Thrown when a URL fails the safety checks in UrlGuard.
 *
 * Separate from ScrapeFailedException on purpose: this one means "we refused
 * to make the request", not "the request failed". The API turns it into a 422
 * validation error rather than a 500, because the caller sent us something we
 * will never accept.
 */
final class UnsafeUrlException extends RuntimeException {}
