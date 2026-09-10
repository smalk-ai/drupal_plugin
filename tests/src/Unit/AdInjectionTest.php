<?php

declare(strict_types=1);

namespace Drupal\Tests\smalk\Unit;

use Drupal\smalk\StackMiddleware\SmalkAdsMiddleware;
use Drupal\Tests\UnitTestCase;

/**
 * Covers how ad HTML replaces a <div smalk-ads> placeholder.
 *
 * @group smalk
 * @coversDefaultClass \Drupal\smalk\StackMiddleware\SmalkAdsMiddleware
 */
class AdInjectionTest extends UnitTestCase {

  /**
   * Runs processAdInjection() with a stubbed ad payload.
   */
  protected function inject(string $html, ?string $adContent): string {
    $middleware = new class($adContent) extends SmalkAdsMiddleware {

      /**
       * The canned ad payload returned instead of calling the API.
       */
      protected ?string $canned;

      public function __construct(?string $canned) {
        $this->canned = $canned;
      }

      /**
       * {@inheritdoc}
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
        return $this->canned;
      }

      /**
       * Exposes the protected injection routine to the test.
       *
       * @param string $html
       *   The rendered page.
       *
       * @return string
       *   The page after injection.
       */
      public function run(string $html): string {
        return $this->processAdInjection($html, 'https://ex.test/p', '/p', 'wk', 'key', '', '', 1.0, FALSE);
      }

    };

    return $middleware->run($html);
  }

  /**
   * A price in the ad copy must survive verbatim.
   *
   * Preg_replace() reads $0/$1/\1 in the REPLACEMENT string as backreferences,
   * so "$1,000" used to be served as ",000".
   *
   * @covers ::processAdInjection
   */
  public function testDollarSequencesInAdCopySurvive(): void {
    $ad = '<p>Offre à partir de $1,000 — $0 la première année \1</p>';
    $out = $this->inject('<body><div smalk-ads id="top"></div></body>', $ad);

    $this->assertStringContainsString('$1,000', $out);
    $this->assertStringContainsString('$0 la première année', $out);
    $this->assertStringContainsString('\1', $out);
    $this->assertStringNotContainsString('smalk-ads', $out);
  }

  /**
   * An empty API response must leave the page byte-identical.
   *
   * The caller compares the returned string against the original to decide
   * whether to disable the page cache, so "no ad" must change nothing.
   *
   * @covers ::processAdInjection
   */
  public function testEmptyAdLeavesHtmlUntouched(): void {
    $html = '<body><div smalk-ads id="top"></div></body>';

    $this->assertSame($html, $this->inject($html, NULL));
    $this->assertSame($html, $this->inject($html, ''));
  }

  /**
   * Only the matched placeholder is replaced, once.
   *
   * @covers ::processAdInjection
   */
  public function testEachPlaceholderIsReplacedOnce(): void {
    $out = $this->inject(
      '<body><div smalk-ads id="a"></div><p>keep</p><div smalk-ads id="b"></div></body>',
      '<span>AD</span>'
    );

    $this->assertSame(2, substr_count($out, '<span>AD</span>'));
    $this->assertStringContainsString('<p>keep</p>', $out);
  }

}
