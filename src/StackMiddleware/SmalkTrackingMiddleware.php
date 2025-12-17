<?php

declare(strict_types=1);

namespace Drupal\smalk\StackMiddleware;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\smalk\Api\SmalkApi;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * HTTP Middleware for Smalk server-side tracking.
 *
 * This middleware runs BEFORE Drupal's page cache (priority 250 > 200),
 * ensuring server-side tracking fires for EVERY request including cached pages.
 *
 * Priority: 250 (higher than page_cache at 200)
 */
class SmalkTrackingMiddleware implements HttpKernelInterface {

  /**
   * The wrapped HTTP kernel.
   *
   * @var \Symfony\Component\HttpKernel\HttpKernelInterface
   */
  protected $httpKernel;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a SmalkTrackingMiddleware object.
   *
   * @param \Symfony\Component\HttpKernel\HttpKernelInterface $http_kernel
   *   The decorated kernel.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    HttpKernelInterface $http_kernel,
    ConfigFactoryInterface $config_factory,
    ClientInterface $http_client,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->httpKernel = $http_kernel;
    $this->configFactory = $config_factory;
    $this->httpClient = $http_client;
    $this->logger = $logger_factory->get('smalk');
  }

  /**
   * {@inheritdoc}
   */
  public function handle(Request $request, $type = self::MAIN_REQUEST, $catch = TRUE): Response {
    // Only process main requests (not subrequests).
    if ($type !== self::MAIN_REQUEST) {
      return $this->httpKernel->handle($request, $type, $catch);
    }

    $config = $this->configFactory->get('smalk.settings');

    // Check if module is enabled and tracking is enabled.
    if ($config->get('enabled') && $config->get('tracking_enabled')) {
      $debugMode = (bool) $config->get('debug_mode');
      $this->sendTracking($request, $config, $debugMode);
    }

    // Pass to next middleware (page_cache, then kernel).
    return $this->httpKernel->handle($request, $type, $catch);
  }

  /**
   * Send server-side tracking to Smalk API.
   */
  protected function sendTracking(Request $request, $config, $debugMode) {
    $apiKey = $config->get('api_key');
    if (empty($apiKey)) {
      return;
    }

    $currentPath = $request->getPathInfo();

    // Check if path is excluded.
    if ($this->isPathExcluded($currentPath, $config)) {
      return;
    }

    // Skip static assets.
    if ($this->isStaticAsset($currentPath)) {
      return;
    }

    // Build tracking payload.
    $payload = [
      'request_path' => $currentPath,
      'request_method' => $request->getMethod(),
      'request_headers' => [
        'User-Agent' => $request->headers->get('User-Agent', ''),
        'X-Real-IP' => $this->getClientIp($request),
        'Referer' => $request->headers->get('Referer', ''),
      ],
    ];

    if ($debugMode) {
      $this->logger->info('Smalk Tracking: Sending tracking for @path', [
        '@path' => $currentPath,
      ]);
    }

    try {
      $response = $this->httpClient->request('POST', SmalkApi::getTrackingUrl(), [
        'json' => $payload,
        'headers' => [
          'Authorization' => 'Api-Key ' . $apiKey,
          'Content-Type' => 'application/json',
        ],
        'timeout' => 1.0,
        'connect_timeout' => 0.5,
      ]);

      if ($debugMode) {
        $this->logger->info('Smalk Tracking: Sent for @path - Status: @status', [
          '@path' => $currentPath,
          '@status' => $response->getStatusCode(),
        ]);
      }
    }
    catch (ConnectException $e) {
      if ($debugMode) {
        $this->logger->warning('Smalk Tracking: Timeout for @path', [
          '@path' => $currentPath,
        ]);
      }
    }
    catch (\Exception $e) {
      if ($debugMode) {
        $this->logger->warning('Smalk Tracking: Failed for @path: @message', [
          '@path' => $currentPath,
          '@message' => $e->getMessage(),
        ]);
      }
    }
  }

  /**
   * Check if a path should be excluded.
   */
  protected function isPathExcluded($path, $config) {
    // Always exclude admin paths if configured.
    if ($config->get('exclude_admin_pages') && strpos($path, '/admin') === 0) {
      return TRUE;
    }

    // Check custom excluded paths.
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
   * Check if path is a static asset.
   */
  protected function isStaticAsset($path) {
    $extensions = ['.png', '.ico', '.jpg', '.jpeg', '.gif', '.css', '.js', '.woff', '.woff2', '.ttf', '.svg', '.map'];
    $lowerPath = strtolower($path);
    foreach ($extensions as $ext) {
      if (substr($lowerPath, -strlen($ext)) === $ext) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Get the client IP address from the request.
   */
  protected function getClientIp(Request $request) {
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
