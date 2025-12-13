<?php

declare(strict_types=1);

namespace Drupal\smalk\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure Smalk settings.
 *
 * Only requires API Key - workspace info is fetched automatically.
 */
class SmalkSettingsForm extends ConfigFormBase {

  /**
   * Smalk API URL for workspace/project info.
   */
  private const PROJECTS_API_URL = 'https://api.smalk.ai/api/v1/projects/';

  /**
   * The HTTP client.
   */
  protected ClientInterface $httpClient;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->httpClient = $container->get('http_client');
    return $instance;
  }

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
          <p><strong>' . $this->t('Why Server-Side?') . '</strong></p>
          <p>' . $this->t('AI Agents (ChatGPT, Claude, Perplexity, etc.) do NOT execute JavaScript. Traditional analytics and ads are invisible to them.') . '</p>
          <p>' . $this->t('Smalk provides server-side tracking and ad injection so publishers can monetize AI Agent traffic.') . '</p>
        </div>
      ',
    ];

    // API Key fieldset.
    $form['credentials'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('API Key'),
      '#description' => $this->t('Enter your API Key from the <a href="@dashboard" target="_blank">Smalk Dashboard</a> → Settings → API Keys.', [
        '@dashboard' => 'https://app.smalk.ai',
      ]),
    ];

    $form['credentials']['api_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('API Key'),
      '#description' => $this->t('Your Smalk API Key. Workspace info will be fetched automatically.'),
      '#default_value' => $config->get('api_key'),
      '#required' => TRUE,
      '#maxlength' => 128,
    ];

    // Display workspace info if configured.
    $workspaceName = $config->get('workspace_name');
    $publisherActivated = $config->get('publisher_activated');
    
    if (!empty($workspaceName)) {
      $publisherStatus = $publisherActivated 
        ? '<span style="color: green;">✓ ' . $this->t('Active') . '</span>' 
        : '<span style="color: orange;">⚠ ' . $this->t('Not activated') . '</span>';
      
      $form['workspace_info'] = [
        '#type' => 'fieldset',
        '#title' => $this->t('Workspace Info'),
        '#attributes' => ['style' => 'background: #f5f5f5; border-radius: 4px;'],
      ];
      
      $form['workspace_info']['info'] = [
        '#type' => 'markup',
        '#markup' => '
          <p><strong>' . $this->t('Workspace:') . '</strong> ' . htmlspecialchars($workspaceName) . '</p>
          <p><strong>' . $this->t('Publisher Status:') . '</strong> ' . $publisherStatus . '</p>
        ',
      ];
      
      if (!$publisherActivated) {
        $form['workspace_info']['publisher_note'] = [
          '#type' => 'markup',
          '#markup' => '<p class="description">' . $this->t('Activate Publisher in your Smalk Dashboard to enable ad injection.') . '</p>',
        ];
      }
    }

    // Feature toggles fieldset.
    $form['features'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Features'),
    ];

    $form['features']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable module'),
      '#description' => $this->t('Master switch. Disable to turn off all features.'),
      '#default_value' => $config->get('enabled') ?? TRUE,
    ];

    $form['features']['tracking_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable Tracking'),
      '#description' => $this->t('JavaScript tracker + server-side AI Agent detection.'),
      '#default_value' => $config->get('tracking_enabled') ?? TRUE,
      '#states' => [
        'disabled' => [':input[name="enabled"]' => ['checked' => FALSE]],
      ],
    ];

    $form['features']['ads_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable AI Search Ads'),
      '#description' => $this->t('Server-side injection of ads into &lt;div smalk-ads&gt; elements. Requires Publisher to be activated.'),
      '#default_value' => $config->get('ads_enabled') ?? TRUE,
      '#states' => [
        'disabled' => [':input[name="enabled"]' => ['checked' => FALSE]],
      ],
    ];

    // Advanced settings fieldset.
    $form['advanced'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced Settings'),
      '#open' => FALSE,
    ];

    $form['advanced']['api_timeout'] = [
      '#type' => 'number',
      '#title' => $this->t('API Timeout (seconds)'),
      '#description' => $this->t('Maximum time to wait for API responses. Recommended: 0.25'),
      '#default_value' => $config->get('api_timeout') ?: 0.25,
      '#min' => 0.1,
      '#max' => 5,
      '#step' => 0.05,
    ];

    $form['advanced']['exclude_admin_pages'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Exclude admin pages'),
      '#default_value' => $config->get('exclude_admin_pages') ?? TRUE,
    ];

    $form['advanced']['excluded_paths'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Additional excluded paths'),
      '#description' => $this->t('One path per line. Use * as wildcard.'),
      '#default_value' => $config->get('excluded_paths'),
      '#rows' => 3,
    ];

    $form['advanced']['debug_mode'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable debug mode'),
      '#description' => $this->t('Log detailed info to Drupal watchdog.'),
      '#default_value' => $config->get('debug_mode'),
    ];

    // Usage instructions.
    $form['usage'] = [
      '#type' => 'details',
      '#title' => $this->t('Ad Placement Instructions'),
      '#open' => FALSE,
    ];

    $form['usage']['instructions'] = [
      '#type' => 'markup',
      '#markup' => '
        <p>' . $this->t('Add this HTML where you want ads to appear:') . '</p>
        <pre>&lt;div smalk-ads&gt;&lt;/div&gt;</pre>
        <p>' . $this->t('For multiple placements, add unique IDs:') . '</p>
        <pre>&lt;div smalk-ads id="header-ad"&gt;&lt;/div&gt;
&lt;div smalk-ads id="sidebar-ad"&gt;&lt;/div&gt;</pre>
        <p>' . $this->t('In Twig templates:') . '</p>
        <pre>{{ \'&lt;div smalk-ads&gt;&lt;/div&gt;\'|raw }}</pre>
      ',
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    parent::validateForm($form, $form_state);

    $apiKey = trim($form_state->getValue('api_key'));
    
    if (empty($apiKey)) {
      return;
    }

    // Fetch workspace info to validate API key.
    $workspaceInfo = $this->fetchWorkspaceInfo($apiKey);
    
    if ($workspaceInfo === NULL) {
      $form_state->setErrorByName('api_key', $this->t('Invalid API Key or could not connect to Smalk API.'));
      return;
    }

    // Store workspace info in form state for submit handler.
    $form_state->set('workspace_info', $workspaceInfo);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $workspaceInfo = $form_state->get('workspace_info');

    $config = $this->config('smalk.settings');
    $config->set('api_key', trim($form_state->getValue('api_key')));
    
    // Save workspace info from API.
    if ($workspaceInfo) {
      $config->set('workspace_key', $workspaceInfo['key'] ?? '');
      $config->set('workspace_name', $workspaceInfo['name'] ?? '');
      $config->set('publisher_activated', $workspaceInfo['publisher_activated'] ?? FALSE);
    }
    
    $config->set('enabled', (bool) $form_state->getValue('enabled'));
    $config->set('tracking_enabled', (bool) $form_state->getValue('tracking_enabled'));
    $config->set('ads_enabled', (bool) $form_state->getValue('ads_enabled'));
    $config->set('api_timeout', (float) $form_state->getValue('api_timeout'));
    $config->set('exclude_admin_pages', (bool) $form_state->getValue('exclude_admin_pages'));
    $config->set('excluded_paths', $form_state->getValue('excluded_paths'));
    $config->set('debug_mode', (bool) $form_state->getValue('debug_mode'));
    $config->save();

    parent::submitForm($form, $form_state);
    drupal_flush_all_caches();

    $this->messenger()->addStatus($this->t('Smalk settings saved. Workspace: @name', [
      '@name' => $workspaceInfo['name'] ?? 'Unknown',
    ]));
  }

  /**
   * Fetch workspace info from Smalk API.
   *
   * @param string $apiKey
   *   The API key.
   *
   * @return array|null
   *   Workspace info or NULL on failure.
   */
  protected function fetchWorkspaceInfo(string $apiKey): ?array {
    try {
      $response = $this->httpClient->request('GET', self::PROJECTS_API_URL, [
        'headers' => [
          'Authorization' => 'Api-Key ' . $apiKey,
          'Content-Type' => 'application/json',
        ],
        'timeout' => 5,
      ]);

      if ($response->getStatusCode() === 200) {
        $data = json_decode($response->getBody()->getContents(), TRUE);
        
        // API returns array, get first project.
        if (is_array($data) && !empty($data)) {
          return $data[0];
        }
      }

      return NULL;

    } catch (\Exception $e) {
      $this->logger('smalk')->warning('Failed to fetch workspace info: @message', [
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

}
