<?php

declare(strict_types=1);

namespace Drupal\smalk\EventSubscriber;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\smalk\Api\SmalkApi;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Serves the IndexNow verification key file at /{key}.txt.
 *
 * This cannot be a route. Drupal's RouteProvider matches a request by replacing
 * WHOLE path segments with '%' (RouteProvider::getCandidateOutlines()), so a
 * route declared as '/{key}.txt' is stored with the outline '/%.txt' and the
 * candidates generated for '/somekey.txt' are only ['/somekey.txt'] — the two
 * never meet. Measured on Drupal 9.5.11: the router row carried
 * `outline=/%.txt, fit=0` and a request for the correct key returned 404, which
 * is why the feature the README advertises had never once served a file.
 *
 * The path is not ours to choose either: the backend pings IndexNow with the
 * GET form (`?url=...&key=...`) and sends no `keyLocation`, so the file has to
 * sit at the domain root.
 *
 * Priority 100 puts this ahead of Symfony's RouterListener (32), so the
 * response is returned before routing is attempted at all.
 */
class IndexNowKeyFileSubscriber implements EventSubscriberInterface {

  /**
   * Cache id holding the synced key.
   */
  const CACHE_ID = 'smalk_indexnow_key';

  /**
   * Cache lifetime of a synced key, in seconds.
   */
  const CACHE_TTL = 120;

  /**
   * Cache lifetime after a failed sync, in seconds.
   */
  const ERROR_CACHE_TTL = 30;

  /**
   * Bounds of an IndexNow key, matching the backend's own contract.
   */
  const KEY_PATTERN = '#^/([a-zA-Z0-9-]{8,128})\.txt$#';

  /**
   * The smalk logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * Constructs the subscriber.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The HTTP client.
   * @param \Drupal\Core\Cache\CacheBackendInterface $cache
   *   The default cache bin.
   * @param \Drupal\Component\Datetime\TimeInterface $time
   *   The time service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected ClientInterface $httpClient,
    protected CacheBackendInterface $cache,
    protected TimeInterface $time,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->logger = $logger_factory->get('smalk');
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [KernelEvents::REQUEST => [['onRequest', 100]]];
  }

  /**
   * Answers /{key}.txt when the key matches the one synced from Smalk.
   *
   * @param \Symfony\Component\HttpKernel\Event\RequestEvent $event
   *   The request event.
   */
  public function onRequest(RequestEvent $event): void {
    if (!$event->isMainRequest()) {
      return;
    }

    // Cheapest possible gate: almost no request on a Drupal site ends in .txt,
    // and this runs on every one of them.
    $path = $event->getRequest()->getPathInfo();
    if (!preg_match(self::KEY_PATTERN, $path, $matches)) {
      return;
    }

    $config = $this->configFactory->get('smalk.settings');
    if (!$config->get('enabled') || empty($config->get('api_key'))) {
      return;
    }

    $key = $this->syncedKey($config->get('api_key'));
    if ($key === '' || $key !== $matches[1]) {
      // Not our file: leave the 404 (or another module's route) alone.
      return;
    }

    $event->setResponse(new Response($key, 200, [
      'Content-Type' => 'text/plain; charset=utf-8',
      'Cache-Control' => 'public, max-age=' . self::CACHE_TTL,
    ]));
  }

  /**
   * Returns the IndexNow key synced from the Smalk backend.
   *
   * The cache bin is the only store. This runs on an anonymous GET, so writing
   * the key to config here would invalidate config:smalk.settings on every
   * rotation, and nothing reads the key back from config.
   *
   * @param string $api_key
   *   The Smalk API key.
   *
   * @return string
   *   The key, or an empty string when unavailable or disabled.
   */
  protected function syncedKey(string $api_key): string {
    $cached = $this->cache->get(self::CACHE_ID);
    if ($cached !== FALSE) {
      return (string) $cached->data;
    }

    try {
      $response = $this->httpClient->request('GET', SmalkApi::getIndexNowKeyUrl(), [
        'timeout' => 5,
        'headers' => [
          'Authorization' => 'Api-Key ' . $api_key,
          'Accept' => 'application/json',
        ],
      ]);

      $key = '';
      if ($response->getStatusCode() === 200) {
        $body = json_decode((string) $response->getBody(), TRUE);
        $key = (string) ($body['indexnow_key'] ?? '');
      }
    }
    catch (\Exception $e) {
      $this->logger->error('IndexNow key sync failed: @message', ['@message' => $e->getMessage()]);
      $key = '';
    }

    $ttl = $key === '' ? self::ERROR_CACHE_TTL : self::CACHE_TTL;
    $this->cache->set(self::CACHE_ID, $key, $this->time->getRequestTime() + $ttl);

    return $key;
  }

}
