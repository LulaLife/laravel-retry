<?php

namespace GregPriday\LaravelRetry\Http;

use Closure;
use GregPriday\LaravelRetry\Contracts\RetryStrategy;
use GregPriday\LaravelRetry\Strategies\CustomOptionsStrategy;
use GregPriday\LaravelRetry\Strategies\GuzzleResponseStrategy;
use GregPriday\LaravelRetry\Strategies\RateLimitStrategy;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Integration with Laravel's HTTP client
 */
class LaravelHttpRetryIntegration
{
    /**
     * Register macros with Laravel's HTTP client
     */
    public static function register(): void
    {
        // Define the macro implementations as closures to reuse them
        $robustRetryMacro = function (
            int $maxAttempts = 3,
            ?RetryStrategy $strategy = null,
            array $options = []
        ) {
            // Determine the base delay from options or use default
            $baseDelay = (float) ($options['base_delay'] ?? 1.0);

            // Default to GuzzleResponseStrategy if no strategy provided
            $baseStrategy = $strategy ?? new GuzzleResponseStrategy($baseDelay);

            // Wrap with CustomOptionsStrategy if options provided
            if (! empty($options)) {
                $strategy = new CustomOptionsStrategy($baseDelay, $baseStrategy, $options);
            } else {
                $strategy = $baseStrategy;
            }

            $pendingRequest = $this;

            // Apply middleware if specified
            if (isset($options['middleware']) && is_callable($options['middleware'])) {
                $pendingRequest = $options['middleware']($pendingRequest);
            }

            // Apply timeout if specified
            if (isset($options['timeout'])) {
                $pendingRequest = $pendingRequest->timeout($options['timeout']);
            }

            // Determine if we should retry based on strategy
            $whenCallback = function (Throwable $exception, PendingRequest $request) use ($strategy, $maxAttempts) {
                static $currentAttempt = 0;
                $currentAttempt++;

                return $currentAttempt < $maxAttempts && $strategy->shouldRetry($currentAttempt - 1, $maxAttempts, $exception);
            };

            // Calculate delay using strategy (convert attempt to 0-based index)
            $sleepCallback = function (int $attempt, Throwable $exception) use ($strategy) {
                return $strategy->getDelay($attempt - 1) * 1000; // Convert to milliseconds
            };

            // Apply retry using Laravel's built-in retry method
            $throw = $options['throw'] ?? true;

            return $pendingRequest->retry($maxAttempts, $sleepCallback, $whenCallback, $throw);
        };

        $withRetryStrategyMacro = function (RetryStrategy $strategy, array $options = []) {
            return $this->robustRetry(
                $options['max_attempts'] ?? 3,
                $strategy,
                $options
            );
        };

        $withCircuitBreakerMacro = function (int $maxAttempts = 3, int $timeout = 60, array $options = []) {
            $innerStrategy = new GuzzleResponseStrategy;

            $strategy = new \GregPriday\LaravelRetry\Strategies\CircuitBreakerStrategy(
                innerStrategy: $innerStrategy,
                failureThreshold: $options['failure_threshold'] ?? 5,
                resetTimeout: $timeout
            );

            return $this->robustRetry($maxAttempts, $strategy, $options);
        };

        $withRateLimitHandlingMacro = function (int $maxAttempts = 100, int $timeWindow = 60, array $options = []) {
            $strategy = new RateLimitStrategy(
                innerStrategy: new GuzzleResponseStrategy,
                maxAttempts: $maxAttempts,
                timeWindow: $timeWindow
            );

            return $this->robustRetry(
                $options['max_attempts'] ?? 3,
                $strategy,
                $options
            );
        };

        $retryWhenMacro = function (Closure $condition, array $options = []) {
            $baseDelay = (float) ($options['base_delay'] ?? 1.0);
            $strategy = new CustomOptionsStrategy($baseDelay, new GuzzleResponseStrategy($baseDelay));
            $strategy->withShouldRetryCallback($condition);

            return $this->robustRetry(
                $options['max_attempts'] ?? 3,
                $strategy,
                $options
            );
        };

        // Register macros on Http facade (Factory class)
        Http::macro('robustRetry', $robustRetryMacro);
        Http::macro('withRetryStrategy', $withRetryStrategyMacro);
        Http::macro('withCircuitBreaker', $withCircuitBreakerMacro);
        Http::macro('withRateLimitHandling', $withRateLimitHandlingMacro);
        Http::macro('retryWhen', $retryWhenMacro);

        // Register macros on PendingRequest class for method chaining
        PendingRequest::macro('robustRetry', $robustRetryMacro);
        PendingRequest::macro('withRetryStrategy', $withRetryStrategyMacro);
        PendingRequest::macro('withCircuitBreaker', $withCircuitBreakerMacro);
        PendingRequest::macro('withRateLimitHandling', $withRateLimitHandlingMacro);
        PendingRequest::macro('retryWhen', $retryWhenMacro);
    }
}
