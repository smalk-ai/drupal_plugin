<?php

declare(strict_types=1);

namespace Drupal\smalk\PageCache;

use Drupal\Core\PageCache\ResponsePolicyInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Response policy that prevents caching pages with Smalk ads.
 *
 * This policy checks if a response has the X-Smalk-Ads-Injected header
 * and tells page_cache to NOT store those responses.
 */
class SmalkAdsResponsePolicy implements ResponsePolicyInterface {

  /**
   * {@inheritdoc}
   */
  public function check(Response $response, Request $request) {
    // Prevent caching pages with injected ads to ensure fresh ad content.
    if ($response->headers->get('X-Smalk-Ads-Injected') === 'true') {
      return static::DENY;
    }

    return NULL;
  }

}
