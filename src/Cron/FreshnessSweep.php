<?php

namespace Drupal\smalk\Cron;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\State\StateInterface;
use Drupal\path_alias\AliasManagerInterface;
use Drupal\smalk\Api\SmalkApi;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Psr\Log\LoggerInterface;

/**
 * Daily sweep: bump `changed` on nodes whose URLs currently serve an ad.
 *
 * Mirrors the WordPress freshness-sweep cron — without it, ad pages with no
 * organic traffic never emit a fresh Last-Modified to AI crawlers.
 */
class FreshnessSweep {

  const STATE_KEY = 'smalk.last_freshness_sweep';

  /**
   * Minimum seconds between two sweeps.
   *
   * 23h, which leaves 1h of drift slack so a daily cron is never skipped.
   */
  private const MIN_INTERVAL_SECONDS = 82800;

  /**
   * The smalk logger channel.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected LoggerInterface $logger;

  /**
   * The module version, read once from smalk.info.yml.
   *
   * @var string|null
   */
  private ?string $moduleVersion = NULL;

  public function __construct(
    protected ConfigFactoryInterface $configFactory,
    protected ClientInterface $httpClient,
    LoggerChannelFactoryInterface $logger_factory,
    protected StateInterface $state,
    protected TimeInterface $time,
    protected AliasManagerInterface $aliasManager,
    protected CacheTagsInvalidatorInterface $cacheTagsInvalidator,
    protected ModuleExtensionList $moduleList,
    protected Connection $database,
  ) {
    $this->logger = $logger_factory->get('smalk');
  }

  /**
   * Entry point. Rate-limited unless $force = TRUE (used by drush/tests).
   */
  public function run(bool $force = FALSE): void {
    $last_ts = (int) ($this->state->get(self::STATE_KEY)['ran_at_ts'] ?? 0);
    if (!$force && ($this->time->getRequestTime() - $last_ts) < self::MIN_INTERVAL_SECONDS) {
      return;
    }

    $config     = $this->configFactory->get('smalk.settings');
    $project_id = (string) $config->get('workspace_key');
    $api_key    = (string) $config->get('api_key');

    if ($project_id === '' || $api_key === '') {
      $this->logger->info('Freshness sweep: missing workspace_key or api_key, skip');
      return;
    }

    $urls = $this->fetchActiveAdUrls($project_id, $api_key);
    if ($urls === NULL) {
      return;
    }

    $nids = [];
    foreach ($urls as $url) {
      if (!is_string($url) || $url === '') {
        continue;
      }
      $nid = $this->resolveNid($url);
      if ($nid !== NULL) {
        $nids[$nid] = TRUE;
      }
    }

    $bumped = $this->bumpNodes(array_keys($nids));

    $now = $this->time->getRequestTime();
    $this->state->set(self::STATE_KEY, [
      'ran_at'     => gmdate('c', $now),
      'ran_at_ts'  => $now,
      'urls_total' => count($urls),
      'bumped'     => $bumped,
    ]);
    $this->logger->info('Freshness sweep: bumped @b of @t', ['@b' => $bumped, '@t' => count($urls)]);
  }

  /**
   * Fetches the URLs currently serving an ad for this project.
   *
   * @param string $project_id
   *   The Smalk workspace key.
   * @param string $api_key
   *   The Smalk API key.
   *
   * @return array|null
   *   The URLs, or NULL when the API answered 304 or could not be reached.
   */
  protected function fetchActiveAdUrls(string $project_id, string $api_key): ?array {
    $etag_state_key = 'smalk.active_ad_urls_etag';
    $prev_etag = (string) ($this->state->get($etag_state_key) ?? '');
    $headers = [
      'Authorization'          => 'Api-Key ' . $api_key,
      'Accept'                 => 'application/json',
      'Accept-Encoding'        => 'gzip',
      'X-Smalk-CMS'            => 'drupal/' . \Drupal::VERSION,
      'X-Smalk-Plugin-Version' => $this->getModuleVersion(),
    ];
    if ($prev_etag !== '') {
      $headers['If-None-Match'] = $prev_etag;
    }
    try {
      // flat=true → `urls` is a plain array of URL strings (lean payload, less
      // server RAM). Guzzle transparently decompresses gzip.
      $response = $this->httpClient->request('GET', SmalkApi::getActiveAdUrlsUrl($project_id, TRUE), [
        'timeout' => 15,
        'connect_timeout' => 5,
        'headers' => $headers,
      ]);
    }
    catch (GuzzleException $e) {
      $this->logger->warning('Freshness sweep: API call failed - @msg', ['@msg' => $e->getMessage()]);
      return NULL;
    }

    $code = $response->getStatusCode();
    if ($code === 304) {
      // Unchanged inventory — nothing to bump this run.
      return [];
    }
    if ($code !== 200) {
      $this->logger->warning('Freshness sweep: API returned HTTP @code', ['@code' => $code]);
      return NULL;
    }

    $new_etag = $response->getHeaderLine('ETag');
    if ($new_etag !== '') {
      $this->state->set($etag_state_key, $new_etag);
    }

    $payload = json_decode((string) $response->getBody(), TRUE);
    $raw = $payload['urls'] ?? [];
    if (!is_array($raw)) {
      return [];
    }
    // Accept both flat (string) and legacy object shapes.
    $urls = [];
    foreach ($raw as $entry) {
      $url = is_array($entry) ? ($entry['url'] ?? '') : $entry;
      if (is_string($url) && $url !== '') {
        $urls[] = $url;
      }
    }
    return $urls;
  }

  /**
   * Resolves a URL to the node id it renders, if any.
   *
   * @param string $url
   *   An absolute or root-relative URL on this site.
   *
   * @return int|null
   *   The node id, or NULL when the URL is not a node.
   */
  protected function resolveNid(string $url): ?int {
    $path = parse_url($url, PHP_URL_PATH);
    if (!$path) {
      return NULL;
    }
    $internal = $this->aliasManager->getPathByAlias($path);
    return preg_match('#^/node/(\d+)$#', $internal, $m) ? (int) $m[1] : NULL;
  }

  /**
   * Bump `changed` for all nids in one UPDATE, then invalidate cache tags.
   *
   * Bypasses Node::save() intentionally — avoids firing presave/update hooks
   * (pathauto, search_api, metatag, revisions) for every freshness bump.
   */
  protected function bumpNodes(array $nids): int {
    if (!$nids) {
      return 0;
    }
    $now = $this->time->getRequestTime();
    $count = $this->database->update('node_field_data')
      ->fields(['changed' => $now])
      ->condition('nid', $nids, 'IN')
      ->execute();

    $tags = array_map(static fn(int $nid) => 'node:' . $nid, $nids);
    $this->cacheTagsInvalidator->invalidateTags($tags);

    return (int) $count;
  }

  /**
   * Returns the module version declared in smalk.info.yml.
   *
   * @return string
   *   The version, or an empty string in a git checkout, where drupal.org's
   *   packaging has not injected one.
   */
  private function getModuleVersion(): string {
    return $this->moduleVersion ??= ($this->moduleList->getExtensionInfo('smalk')['version'] ?? '');
  }

}
