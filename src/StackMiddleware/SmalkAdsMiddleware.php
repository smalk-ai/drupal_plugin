<?php

declare(strict_types=1);

namespace Drupal\smalk\StackMiddleware;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\smalk\Api\SmalkApi;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * HTTP Middleware for Smalk server-side ad injection.
 *
 * This middleware runs AFTER Drupal's page cache (priority 100 < 200),
 * so it can inject ads and set cache headers BEFORE the page cache stores
 * the response.
 *
 * Flow:
 * 1. Request → page_cache (200) → this middleware (100) → kernel
 * 2. Response ← kernel → this middleware (injects ads, disables cache) → page_cache (sees no-cache, doesn't store)
 *
 * Priority: 100 (lower than page_cache at 200)
 */
class SmalkAdsMiddleware implements HttpKernelInterface {

  /**
   * Regex pattern to find elements with smalk-ads attribute.
   */
  const DIV_PATTERN = '/<(\w+)[^>]*\bsmalk-ads(?:="[^"]*"|=\'[^\']*\'|=[^\s>]+|(?=\s)|(?=>))[^>]*>.*?<\/\1>/is';

  /**
   * Regex pattern to extract id attribute.
   */
  const ID_PATTERN = '/\bid=(["\'])([^"\']*)\1|\bid=([^\s>]+)/i';

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
   * Constructs a SmalkAdsMiddleware object.
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

    // Pass request to kernel to generate response.
    $response = $this->httpKernel->handle($request, $type, $catch);

    $config = $this->configFactory->get('smalk.settings');

    // Check if module is enabled and ads are enabled.
    if (!$config->get('enabled') || !$config->get('ads_enabled') || !$config->get('publisher_activated')) {
      return $response;
    }

    $debugMode = (bool) $config->get('debug_mode');

    // Inject ads into the response.
    return $this->injectAds($request, $response, $config, $debugMode);
  }

  /**
   * Inject ads into HTML response.
   */
  protected function injectAds(Request $request, Response $response, $config, $debugMode) {
    // Only process HTML responses.
    $contentType = $response->headers->get('Content-Type', '');
    if (strpos($contentType, 'text/html') === FALSE) {
      if ($debugMode) {
        $this->logger->debug('Smalk Ads: Skipping - not HTML (Content-Type: @type)', [
          '@type' => $contentType ?: 'empty',
        ]);
      }
      return $response;
    }

    // Check for required credentials.
    $workspaceKey = $config->get('workspace_key');
    $apiKey = $config->get('api_key');

    if (empty($workspaceKey) || empty($apiKey)) {
      if ($debugMode) {
        $this->logger->debug('Smalk Ads: Skipping - missing credentials');
      }
      return $response;
    }

    // Check if current path is excluded.
    $currentPath = $request->getPathInfo();
    if ($this->isPathExcluded($currentPath, $config)) {
      if ($debugMode) {
        $this->logger->debug('Smalk Ads: Skipping - path excluded: @path', [
          '@path' => $currentPath,
        ]);
      }
      return $response;
    }

    // Get response content.
    $content = $response->getContent();
    if (empty($content)) {
      if ($debugMode) {
        $this->logger->debug('Smalk Ads: Skipping - empty content');
      }
      return $response;
    }

    // Check if there are any smalk-ads divs.
    if (!preg_match(self::DIV_PATTERN, $content)) {
      if ($debugMode) {
        if (preg_match('/smalk-ads/i', $content)) {
          $this->logger->warning('Smalk Ads: Found smalk-ads attribute but regex pattern did not match.');
        }
        else {
          $this->logger->debug('Smalk Ads: No smalk-ads divs found on @path', [
            '@path' => $currentPath,
          ]);
        }
      }
      return $response;
    }

    if ($debugMode) {
      $this->logger->info('Smalk Ads: Found smalk-ads div(s), starting ad injection for @url', [
        '@url' => $request->getRequestUri(),
      ]);
    }

    // Build URLs.
    $currentUrl = $request->getSchemeAndHttpHost() . $request->getRequestUri();
    $pageUrl = $request->getPathInfo();

    $apiTimeout = (float) $config->get('api_timeout') ?: 0.25;

    // Inject ads.
    $modifiedContent = $this->processAdInjection(
      $content,
      $currentUrl,
      $pageUrl,
      $workspaceKey,
      $apiKey,
      $request->headers->get('User-Agent', ''),
      $request->headers->get('Referer', ''),
      $this->getClientIp($request),
      $apiTimeout,
      $debugMode
    );

    // Update response.
    $response->setContent($modifiedContent);

    // Mark response as containing injected ads.
    $response->headers->set('X-Smalk-Ads-Injected', 'true');

    // CRITICAL: Disable caching for pages with ads.
    // This ensures every request fetches fresh ads for accurate impression tracking.
    // These headers are set BEFORE page_cache sees the response (we're at priority 100).
    $response->setPrivate();
    $response->setMaxAge(0);
    $response->headers->addCacheControlDirective('no-cache', true);
    $response->headers->addCacheControlDirective('no-store', true);
    $response->headers->addCacheControlDirective('must-revalidate', true);

    // Also set Expires header to ensure proxy caches don't store.
    $response->headers->set('Expires', 'Sun, 19 Nov 1978 05:00:00 GMT');

    // Pragma for HTTP/1.0 compatibility.
    $response->headers->set('Pragma', 'no-cache');

    if ($debugMode) {
      $this->logger->info('Smalk Ads: Disabled caching for page with ads - @url', [
        '@url' => $request->getRequestUri(),
      ]);
    }

    return $response;
  }

  /**
   * Process ad injection for all smalk-ads elements.
   */
  protected function processAdInjection(
    $html,
    $currentUrl,
    $pageUrl,
    $workspaceKey,
    $apiKey,
    $userAgent,
    $referer,
    $clientIp,
    $timeout,
    $debugMode
  ) {
    $adsInjected = 0;

    if (preg_match_all(self::DIV_PATTERN, $html, $matches)) {
      foreach ($matches[0] as $div) {
        // Extract id attribute for placement_id.
        $placementId = 'default';
        if (preg_match(self::ID_PATTERN, $div, $idMatch)) {
          $placementId = !empty($idMatch[2]) ? $idMatch[2] : (!empty($idMatch[3]) ? $idMatch[3] : 'default');
        }

        // Fetch ad content.
        $adContent = $this->fetchAdContent(
          $currentUrl,
          $pageUrl,
          $workspaceKey,
          $apiKey,
          $placementId,
          $userAgent,
          $referer,
          $clientIp,
          $timeout
        );

        if ($adContent) {
          // Replace only first occurrence of this specific div.
          $html = preg_replace(
            '/' . preg_quote($div, '/') . '/',
            $adContent,
            $html,
            1
          );
          $adsInjected++;
        }
        elseif ($debugMode) {
          $this->logger->warning('Smalk Ads: No ad content for placement @id', [
            '@id' => $placementId,
          ]);
        }
      }
    }

    if ($debugMode && $adsInjected > 0) {
      $this->logger->info('Smalk Ads: Injected @count ads on @url', [
        '@count' => $adsInjected,
        '@url' => $currentUrl,
      ]);
    }

    return $html;
  }

  /**
   * Fetch ad content from Smalk API.
   */
  protected function fetchAdContent(
    $currentUrl,
    $pageUrl,
    $workspaceKey,
    $apiKey,
    $placementId,
    $userAgent,
    $referer,
    $clientIp,
    $timeout
  ) {
    try {
      $payload = [
        'project_key' => $workspaceKey,
        'user_agent' => $userAgent,
        'referer' => $referer,
        'client_ip' => $clientIp,
        'current_url' => $currentUrl,
        'page_url' => $pageUrl,
        'placement_id' => $placementId,
        'timestamp' => date('c'),
      ];

      $response = $this->httpClient->request('POST', SmalkApi::getAdsContentUrl(), [
        'json' => $payload,
        'headers' => [
          'Authorization' => 'Api-Key ' . $apiKey,
          'Content-Type' => 'application/json',
        ],
        'timeout' => $timeout,
        'connect_timeout' => $timeout,
      ]);

      if ($response->getStatusCode() === 200) {
        $data = json_decode($response->getBody()->getContents(), TRUE);
        return isset($data['html']) ? $data['html'] : NULL;
      }

      return NULL;
    }
    catch (ConnectException $e) {
      $this->logger->warning('Smalk API timeout for @url', ['@url' => $currentUrl]);
      return NULL;
    }
    catch (RequestException $e) {
      $this->logger->warning('Smalk API failed: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
    catch (\Exception $e) {
      $this->logger->error('Smalk error: @message', ['@message' => $e->getMessage()]);
      return NULL;
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
