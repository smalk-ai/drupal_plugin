<?php

declare(strict_types=1);

namespace Drupal\smalk\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Subscribes to kernel request events to track visits server-side.
 *
 * This subscriber captures ALL incoming requests and sends tracking data
 * to the Smalk API. This is critical for detecting AI Agents that don't
 * execute JavaScript (ChatGPT, Perplexity, Claude, etc.).
 *
 * Key features:
 * - Runs early in the request lifecycle (high priority)
 * - Non-blocking: Uses async HTTP requests
 * - Filters sensitive headers (only sends required data)
 * - Graceful degradation on errors
 */
class SmalkTrackingSubscriber implements EventSubscriberInterface {

  /**
   * Smalk API endpoint for server-side tracking.
   */
  private const TRACKING_API_URL = 'https://api.smalk.ai/api/v1/tracking/visit';

  /**
   * API timeout in seconds (150ms as recommended).
   */
  private const API_TIMEOUT = 0.15;

  /**
   * The config factory.
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The HTTP client.
   */
  protected ClientInterface $httpClient;

  /**
   * The logger.
   */
  protected LoggerInterface $logger;

  /**
   * The request stack.
   */
  protected RequestStack $requestStack;

  /**
   * Constructs a SmalkTrackingSubscriber object.
   */
  public function __construct(
    ConfigFactoryInterface $config_factory,
    ClientInterface $http_client,
    LoggerChannelFactoryInterface $logger_factory,
    RequestStack $request_stack
  ) {
    $this->configFactory = $config_factory;
    $this->httpClient = $http_client;
    $this->logger = $logger_factory->get('smalk');
    $this->requestStack = $request_stack;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    // Run with HIGH priority to capture requests before cache
    return [
      KernelEvents::REQUEST => ['onRequest', 100],
    ];
  }

  /**
   * Track incoming requests to Smalk API.
   */
  public function onRequest(RequestEvent $event): void {
    // Only process main requests (not subrequests)
    if (!$event->isMainRequest()) {
      return;
    }

    $request = $event->getRequest();
    $config = $this->configFactory->get('smalk.settings');

    // Check if module and tracking are enabled
    if (!$config->get('enabled') || !$config->get('tracking_enabled')) {
      return;
    }

    // Check for required API key
    $apiKey = $config->get('api_key');
    if (empty($apiKey)) {
      return;
    }

    // Check if current path is excluded
    $currentPath = $request->getPathInfo();
    if ($this->isPathExcluded($currentPath, $config)) {
      return;
    }

    // Send tracking request (fire-and-forget)
    $this->trackVisit($request, $apiKey, (bool) $config->get('debug_mode'));
  }

  /**
   * Check if a path should be excluded from tracking.
   */
  protected function isPathExcluded(string $path, $config): bool {
    // Always exclude admin paths if configured
    if ($config->get('exclude_admin_pages') && strpos($path, '/admin') === 0) {
      return TRUE;
    }

    // Check custom excluded paths
    $excludedPaths = $config->get('excluded_paths');
    if (empty($excludedPaths)) {
      return FALSE;
    }

    $patterns = array_filter(array_map('trim', explode("\n", $excludedPaths)));

    foreach ($patterns as $pattern) {
      $regex = '/^' . str_replace(['\*', '\?'], ['.*', '.'], preg_quote($pattern, '/')) . '$/';
      if (preg_match($regex, $path)) {
        return TRUE;
      }
    }

    return FALSE;
  }

  /**
   * Send tracking request.
   */
  protected function trackVisit($request, string $apiKey, bool $debugMode): void {
    // Build tracking payload with ONLY required/recommended headers
    $payload = [
      'request_path' => $request->getPathInfo(),
      'request_method' => $request->getMethod(),
      'request_headers' => [
        'User-Agent' => $request->headers->get('User-Agent', ''),
        'X-Real-IP' => $this->getClientIp($request),
        'Referer' => $request->headers->get('Referer', ''),
      ],
    ];

    try {
      $this->httpClient->requestAsync('POST', self::TRACKING_API_URL, [
        'json' => $payload,
        'headers' => [
          'Authorization' => 'Api-Key ' . $apiKey,
          'Content-Type' => 'application/json',
        ],
        'timeout' => self::API_TIMEOUT,
        'connect_timeout' => self::API_TIMEOUT,
      ])->then(
        function ($response) use ($debugMode, $request) {
          if ($debugMode) {
            $this->logger->info('Smalk tracking: @path', [
              '@path' => $request->getPathInfo(),
            ]);
          }
        },
        function ($exception) use ($debugMode, $request) {
          if ($debugMode) {
            $this->logger->warning('Smalk tracking failed for @path: @message', [
              '@path' => $request->getPathInfo(),
              '@message' => $exception->getMessage(),
            ]);
          }
        }
      );
    }
    catch (ConnectException $e) {
      if ($debugMode) {
        $this->logger->warning('Smalk tracking timeout for @path', [
          '@path' => $request->getPathInfo(),
        ]);
      }
    }
    catch (\Exception $e) {
      if ($debugMode) {
        $this->logger->error('Smalk tracking error: @message', [
          '@message' => $e->getMessage(),
        ]);
      }
    }
  }

  /**
   * Get the client IP address from the request.
   */
  protected function getClientIp($request): string {
    $forwardedFor = $request->headers->get('X-Forwarded-For');
    if ($forwardedFor) {
      $ips = explode(',', $forwardedFor);
      return trim($ips[0]);
    }

    $realIp = $request->headers->get('X-Real-IP');
    if ($realIp) {
      return $realIp;
    }

    return $request->getClientIp() ?: '';
  }

}
