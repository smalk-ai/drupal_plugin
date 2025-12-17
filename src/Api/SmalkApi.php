<?php

namespace Drupal\smalk\Api;

/**
 * Central API configuration for Smalk.
 */
class SmalkApi {

  /**
   * Base URL for the Smalk API.
   */
  const API_BASE_URL = 'https://api.smalk.ai';
  /**
   * Get the base API URL.
   *
   * @return string
   *   The base API URL.
   */
  public static function getBaseUrl(): string {
    return self::API_BASE_URL;
  }

  /**
   * Get the API v1 base URL.
   *
   * @return string
   *   The API v1 base URL.
   */
  public static function getApiV1BaseUrl(): string {
    return self::API_BASE_URL . '/api/v1';
  }

  /**
   * Get the projects API endpoint.
   *
   * @return string
   *   The projects API endpoint URL.
   */
  public static function getProjectsUrl(): string {
    return self::getApiV1BaseUrl() . '/projects/';
  }

  /**
   * Get the tracking API endpoint.
   *
   * @return string
   *   The tracking API endpoint URL.
   */
  public static function getTrackingUrl(): string {
    return self::getApiV1BaseUrl() . '/tracking/visit';
  }

  /**
   * Get the ads content API endpoint.
   *
   * @return string
   *   The ads content API endpoint URL.
   */
  public static function getAdsContentUrl(): string {
    return self::getApiV1BaseUrl() . '/transform/ads/content/';
  }

  /**
   * Get the tracker JavaScript URL.
   *
   * @return string
   *   The tracker JavaScript URL.
   */
  public static function getTrackerJsUrl(): string {
    return self::API_BASE_URL . '/tracker.js';
  }

}
