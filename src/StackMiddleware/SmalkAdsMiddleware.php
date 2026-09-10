<?php

declare(strict_types=1);

namespace Drupal\smalk\StackMiddleware;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\smalk\Api\SmalkApi;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
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
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->httpKernel = $http_kernel;
    $this->configFactory = $config_factory;
    $this->httpClient = $http_client;
    $this->logger = $logger_factory->get('smalk');
  }

  /**
   * {@inheritdoc}
   */
  public function handle(Request $request, $type = 1, $catch = TRUE): Response {
    // Symfony renamed MASTER_REQUEST to MAIN_REQUEST in 5.3 and dropped the old
    // name in 7.0, so neither constant exists on every core we support:
    // Drupal 9 ships Symfony 4.4 (MASTER_REQUEST only), Drupal 11 ships 7.4
    // (MAIN_REQUEST only). Both equal 1; SUB_REQUEST (2) is the one name
    // present in all of them, so the test is written against it.
    if ($type === self::SUB_REQUEST) {
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

    // 1.0 is what config/install ships and what the settings form displays as
    // its default; this used to fall back to 0.25, so a site whose value was
    // empty served ads on a quarter of the budget the screen promised it.
    $apiTimeout = (float) ($config->get('api_timeout') ?: 1.0);

    // Inject ads.
    $modifiedContent = $this->processAdInjection(
      $content,
      $currentUrl,
      $pageUrl,
      $workspaceKey,
      $apiKey,
      $request->headers->get('User-Agent', ''),
      $request->headers->get('Referer', ''),
      $apiTimeout,
      $debugMode
    );

    // An unfilled placeholder is not an ad: if the API served nothing, leave the
    // page exactly as the kernel rendered it, cacheable. Marking it otherwise
    // made every page carrying an empty <div smalk-ads> uncacheable for ever and
    // emitted a fresh Last-Modified on each hit, which is the very signal the
    // freshness sweep exists to control.
    if ($modifiedContent === $content) {
      if ($debugMode) {
        $this->logger->debug('Smalk Ads: placeholder(s) found but no ad content served - leaving @url cacheable', [
          '@url' => $request->getRequestUri(),
        ]);
      }
      return $response;
    }

    // Update response.
    $response->setContent($modifiedContent);

    // Mark request as containing injected ads (internal signal for page cache policy).
    $request->attributes->set('_smalk_ads_injected', TRUE);

    // Allow crawlers to store and index, but force revalidation on every request.
    // REMOVED: no-store (prevents LLM crawlers from indexing the page).
    // REMOVED: Expires 1978 and Pragma (HTTP/1.0 legacy).
    $response->setPrivate();
    $response->setMaxAge(0);
    $response->headers->addCacheControlDirective('no-cache', TRUE);
    $response->headers->addCacheControlDirective('must-revalidate', TRUE);

    // Last-Modified = current time signals page was just modified (ad injected).
    // Crawlers can use If-Modified-Since on next visit.
    $response->headers->set('Last-Modified', gmdate('D, d M Y H:i:s') . ' GMT');

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
    $timeout,
    $debugMode,
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
          $timeout
        );

        // Only replace div if we have non-empty ad content.
        // If API returns {"html": ""} or {"htm": ""}, $adContent will be NULL
        // and the div will remain unchanged in the source code.
        if ($adContent !== NULL && $adContent !== '') {
          // substr_replace, not preg_replace: the ad copy is the REPLACEMENT
          // string, so preg_replace reads $0/$1/\1 inside it as backreferences
          // and a price like "$1,000" is served as ",000". This also skips
          // compiling a pattern for a literal match we already located.
          $pos = strpos($html, $div);
          if ($pos !== FALSE) {
            $html = substr_replace($html, $adContent, $pos, strlen($div));
            $adsInjected++;
          }
        }
        elseif ($debugMode) {
          $this->logger->warning('Smalk Ads: No ad content for placement @id (empty response from API)', [
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
    $timeout,
  ) {
    try {
      $payload = [
        'project_key' => $workspaceKey,
        'user_agent' => $userAgent,
        // GDPR: no client IP is collected or sent; the server drops it anyway
        // (2026-07-22, TF1 audit).
        'referer' => $referer,
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
          'X-Smalk-CMS' => 'drupal/' . \Drupal::VERSION,
          'X-Smalk-Plugin-Version' => $this->getModuleVersion(),
        ],
        'timeout' => $timeout,
        'connect_timeout' => $timeout,
      ]);

      if ($response->getStatusCode() === 200) {
        $data = json_decode($response->getBody()->getContents(), TRUE);
        // Check for 'html' key first (standard), then 'htm' as fallback.
        $htmlContent = $data['html'] ?? ($data['htm'] ?? NULL);

        // Return NULL if content is empty string - this ensures div is not replaced
        // when API responds with {"html": ""} or {"htm": ""}.
        if ($htmlContent === '' || $htmlContent === NULL) {
          return NULL;
        }

        return $htmlContent;
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
   * Get the module version from smalk.info.yml.
   */
  protected function getModuleVersion(): string {
    $info = \Drupal::service('extension.list.module')->getExtensionInfo('smalk');
    return $info['version'] ?? '';
  }

}
