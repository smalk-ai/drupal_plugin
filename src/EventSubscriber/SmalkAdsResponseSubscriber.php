<?php

declare(strict_types=1);

namespace Drupal\smalk\EventSubscriber;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Subscribes to kernel response events to inject Smalk ads server-side.
 *
 * This subscriber processes HTML responses and replaces <div smalk-ads>
 * elements with actual ad content fetched from the Smalk API.
 *
 * Ads are injected directly into the HTML response (Server-Side Ad Injection),
 * so the page arrives to the user with ads already in place.
 */
class SmalkAdsResponseSubscriber implements EventSubscriberInterface {

  /**
   * Smalk API endpoint for ad content.
   */
  private const API_URL = 'https://api.smalk.ai/api/v1/transform/ads/content/';

  /**
   * Regex pattern to find smalk-ads divs (attribute-based).
   */
  private const DIV_PATTERN = '/<div[^>]*\ssmalk-ads(?:\s|>)[^>]*><\/div>/i';

  /**
   * Regex pattern to extract id attribute.
   */
  private const ID_PATTERN = '/\sid=["\']([^"\']*)["\']|\sid=([^\s>]+)/i';

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
   * Constructs a SmalkAdsResponseSubscriber object.
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
    // Run with low priority to ensure we process the final HTML.
    return [
      KernelEvents::RESPONSE => ['onResponse', -100],
    ];
  }

  /**
   * Process response and inject ads.
   */
  public function onResponse(ResponseEvent $event): void {
    // Only process main requests (not subrequests).
    if (!$event->isMainRequest()) {
      return;
    }

    $response = $event->getResponse();
    $request = $event->getRequest();

    // Only process HTML responses.
    $contentType = $response->headers->get('Content-Type', '');
    if (strpos($contentType, 'text/html') === FALSE) {
      return;
    }

    // Get configuration.
    $config = $this->configFactory->get('smalk.settings');

    // Check if module and ads are enabled.
    if (!$config->get('enabled') || !$config->get('ads_enabled')) {
      return;
    }

    // Check for required credentials.
    $workspaceKey = $config->get('workspace_key');
    $apiKey = $config->get('api_key');

    if (empty($workspaceKey) || empty($apiKey)) {
      if ($config->get('debug_mode')) {
        $this->logger->warning('Smalk: Missing workspace_key or api_key.');
      }
      return;
    }

    // Check if publisher is activated.
    if (!$config->get('publisher_activated')) {
      if ($config->get('debug_mode')) {
        $this->logger->info('Smalk: Publisher not activated, skipping ad injection.');
      }
      return;
    }

    // Check if current path is excluded.
    $currentPath = $request->getPathInfo();
    if ($this->isPathExcluded($currentPath, $config->get('excluded_paths'))) {
      return;
    }

    // Get response content.
    $content = $response->getContent();
    if (empty($content)) {
      return;
    }

    // Check if there are any smalk-ads divs.
    if (!preg_match(self::DIV_PATTERN, $content)) {
      return;
    }

    // Build URLs.
    $currentUrl = $request->getSchemeAndHttpHost() . $request->getRequestUri();
    $pageUrl = $request->getPathInfo();

    $apiTimeout = (float) $config->get('api_timeout') ?: 0.25;
    $debugMode = (bool) $config->get('debug_mode');

    // Inject ads.
    $modifiedContent = $this->injectAds(
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

  /**
   * Check if a path should be excluded from ad injection.
   */
  protected function isPathExcluded(string $path, ?string $excludedPaths): bool {
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
   * Find and replace smalk-ads divs with ad content.
   */
  protected function injectAds(
    string $html,
    string $currentUrl,
    string $pageUrl,
    string $workspaceKey,
    string $apiKey,
    string $userAgent,
    string $referer,
    string $clientIp,
    float $timeout,
    bool $debugMode
  ): string {
    $adsInjected = 0;

    if (preg_match_all(self::DIV_PATTERN, $html, $matches)) {
      foreach ($matches[0] as $div) {
        // Extract id attribute for selector_id.
        $selectorId = NULL;
        if (preg_match(self::ID_PATTERN, $div, $idMatch)) {
          $selectorId = $idMatch[1] ?: ($idMatch[2] ?? NULL);
        }

        // Fetch ad content.
        $adContent = $this->fetchAdContent(
          $currentUrl,
          $pageUrl,
          $workspaceKey,
          $apiKey,
          $selectorId,
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
      }
    }

    if ($debugMode && $adsInjected > 0) {
      $this->logger->info('Smalk: Injected @count ads on @url', [
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
    string $currentUrl,
    string $pageUrl,
    string $workspaceKey,
    string $apiKey,
    ?string $selectorId,
    string $userAgent,
    string $referer,
    string $clientIp,
    float $timeout
  ): ?string {
    try {
      $payload = [
        'project_key' => $workspaceKey,
        'user_agent' => $userAgent,
        'referer' => $referer,
        'client_ip' => $clientIp,
        'current_url' => $currentUrl,
        'page_url' => $pageUrl,
        'timestamp' => date('c'),
      ];

      if ($selectorId) {
        $payload['selector_id'] = $selectorId;
      }

      $response = $this->httpClient->request('POST', self::API_URL, [
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
        return $data['html'] ?? NULL;
      }

      return NULL;

    }
    catch (ConnectException $e) {
      $this->logger->warning('Smalk API timeout for @url', [
        '@url' => $currentUrl,
      ]);
      return NULL;
    }
    catch (RequestException $e) {
      $this->logger->warning('Smalk API failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
    catch (\Exception $e) {
      $this->logger->error('Smalk error: @message', [
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

}
