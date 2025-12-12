<?php

declare(strict_types=1);

namespace Drupal\smalk\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure Smalk settings.
 *
 * Provides settings for:
 * - JavaScript tracker injection (frontend analytics)
 * - Server-side tracking (AI Agent detection)
 * - Server-side AI Search Ads injection
 */
class SmalkSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'smalk_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['smalk.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('smalk.settings');

    // Introduction.
    $form['intro'] = [
      '#type' => 'markup',
      '#markup' => '
        <div class="messages messages--info">
          <p>' . $this->t('The Smalk module provides complete GEO (Generative Engine Optimization) integration:') . '</p>
          <ul>
            <li><strong>' . $this->t('JavaScript Tracker') . '</strong>: ' . $this->t('Frontend analytics for browser-based visitors.') . '</li>
            <li><strong>' . $this->t('Server-Side Tracking') . '</strong>: ' . $this->t('Detects AI Agents that don\'t execute JavaScript (ChatGPT, Perplexity, Claude, etc.).') . '</li>
            <li><strong>' . $this->t('AI Search Ads') . '</strong>: ' . $this->t('Server-side injection of contextual ads into HTML responses.') . '</li>
          </ul>
          <p>' . $this->t('Get your API credentials in the <a href="@dashboard" target="_blank">Smalk Dashboard</a>.', [
            '@dashboard' => 'https://app.smalk.ai',
          ]) . '</p>
        </div>
      ',
    ];

    // API Credentials fieldset.
    $form['credentials'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('API Credentials'),
    ];

    $form['credentials']['project_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Project Key'),
      '#description' => $this->t('Your Smalk project key (UUID format). Used for the JavaScript tracker. Found in Dashboard → Integrations.'),
      '#default_value' => $config->get('project_key'),
      '#required' => TRUE,
      '#maxlength' => 64,
      '#attributes' => [
        'placeholder' => 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
      ],
    ];

    $form['credentials']['api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API Key'),
      '#description' => $this->t('Your Smalk API key for server-side requests. Found in Dashboard → Settings → API Keys.'),
      '#default_value' => $config->get('api_key'),
      '#required' => TRUE,
      '#maxlength' => 128,
    ];

    // Feature toggles fieldset.
    $form['features'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Features'),
    ];

    $form['features']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable module'),
      '#description' => $this->t('Master switch. Disable to turn off all features without uninstalling.'),
      '#default_value' => $config->get('enabled') ?? TRUE,
    ];

    $form['features']['tracking_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Tracking'),
      '#description' => $this->t('Inject the JavaScript tracker and send server-side tracking for AI Agent detection.'),
      '#default_value' => $config->get('tracking_enabled') ?? TRUE,
      '#states' => [
        'disabled' => [
          ':input[name="enabled"]' => ['checked' => FALSE],
        ],
      ],
    ];

    $form['features']['ads_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable AI Search Ads'),
      '#description' => $this->t('Server-side injection of contextual ads into &lt;div smalk-ads&gt; elements.'),
      '#default_value' => $config->get('ads_enabled') ?? TRUE,
      '#states' => [
        'disabled' => [
          ':input[name="enabled"]' => ['checked' => FALSE],
        ],
      ],
    ];

    // Performance settings fieldset.
    $form['performance'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Performance Settings'),
    ];

    $form['performance']['api_timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('API Timeout (seconds)'),
      '#description' => $this->t('Maximum time to wait for API responses. Recommended: 0.25 (250ms).'),
      '#default_value' => $config->get('api_timeout') ?: 0.25,
      '#min' => 0.1,
      '#max' => 5,
      '#step' => 0.05,
      '#required' => TRUE,
    ];

    // Advanced settings fieldset.
    $form['advanced'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced Settings'),
      '#open' => FALSE,
    ];

    $form['advanced']['exclude_admin_pages'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Exclude admin pages'),
      '#description' => $this->t('Don\'t track or inject ads on admin pages.'),
      '#default_value' => $config->get('exclude_admin_pages') ?? TRUE,
    ];

    $form['advanced']['excluded_paths'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Additional excluded paths'),
      '#description' => $this->t('Enter paths to exclude from tracking and ad injection, one per line. Use * as a wildcard.'),
      '#default_value' => $config->get('excluded_paths'),
      '#rows' => 5,
      '#attributes' => [
        'placeholder' => "/user/*\n/api/*\n/cron",
      ],
    ];

    $form['advanced']['debug_mode'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable debug mode'),
      '#description' => $this->t('Log detailed information to Drupal\'s watchdog. Useful for troubleshooting.'),
      '#default_value' => $config->get('debug_mode'),
    ];

    // Usage instructions.
    $form['usage'] = [
      '#type' => 'details',
      '#title' => $this->t('Usage Instructions'),
      '#open' => FALSE,
    ];

    $form['usage']['instructions'] = [
      '#type' => 'markup',
      '#markup' => '
        <h4>' . $this->t('How Tracking Works') . '</h4>
        <p>' . $this->t('Once enabled, the module automatically:') . '</p>
        <ol>
          <li>' . $this->t('Injects the JavaScript tracker on all pages for frontend analytics') . '</li>
          <li>' . $this->t('Sends server-side tracking requests for every page visit (detects AI Agents)') . '</li>
        </ol>
        <p><strong>' . $this->t('No additional setup required for tracking!') . '</strong></p>

        <h4>' . $this->t('How to Add Ad Placements') . '</h4>
        <p>' . $this->t('Ads are injected server-side directly into the HTML. Add this markup where you want ads:') . '</p>
        <pre>&lt;div smalk-ads&gt;&lt;/div&gt;</pre>

        <h4>' . $this->t('Multiple Placements Per Page') . '</h4>
        <p>' . $this->t('For multiple placements, add unique IDs:') . '</p>
        <pre>&lt;div smalk-ads id="header-ad"&gt;&lt;/div&gt;
&lt;div smalk-ads id="sidebar-ad"&gt;&lt;/div&gt;
&lt;div smalk-ads id="footer-ad"&gt;&lt;/div&gt;</pre>

        <h4>' . $this->t('Using with Twig Templates') . '</h4>
        <pre>{{ \'&lt;div smalk-ads id="article-ad"&gt;&lt;/div&gt;\'|raw }}</pre>
      ',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    // Validate project_key format (UUID).
    $projectKey = $form_state->getValue('project_key');
    if (!empty($projectKey) && !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $projectKey)) {
      $form_state->setErrorByName('project_key', $this->t('The Project Key must be a valid UUID format (xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx).'));
    }

    // Validate timeout range.
    $timeout = $form_state->getValue('api_timeout');
    if ($timeout < 0.1 || $timeout > 5) {
      $form_state->setErrorByName('api_timeout', $this->t('API timeout must be between 0.1 and 5 seconds.'));
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $this->config('smalk.settings')
      ->set('project_key', trim($form_state->getValue('project_key')))
      ->set('api_key', trim($form_state->getValue('api_key')))
      ->set('enabled', (bool) $form_state->getValue('enabled'))
      ->set('tracking_enabled', (bool) $form_state->getValue('tracking_enabled'))
      ->set('ads_enabled', (bool) $form_state->getValue('ads_enabled'))
      ->set('api_timeout', (float) $form_state->getValue('api_timeout'))
      ->set('exclude_admin_pages', (bool) $form_state->getValue('exclude_admin_pages'))
      ->set('excluded_paths', $form_state->getValue('excluded_paths'))
      ->set('debug_mode', (bool) $form_state->getValue('debug_mode'))
      ->save();

    parent::submitForm($form, $form_state);

    // Clear caches to ensure settings take effect.
    drupal_flush_all_caches();

    $this->messenger()->addStatus($this->t('Smalk settings have been saved and caches cleared.'));
  }

}
