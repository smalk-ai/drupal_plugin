<?php

declare(strict_types=1);

namespace Drupal\smalk\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\smalk\Api\SmalkApi;
use GuzzleHttp\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Configure Smalk settings.
 *
 * Only requires API Key - workspace info is fetched automatically.
 */
class SmalkSettingsForm extends ConfigFormBase {

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

    // Add inline CSS for masked API key display.
    $form['#attached']['html_head'][] = [[
      '#tag' => 'style',
      '#value' => '.smalk-api-key-masked code { font-family: monospace; background: #f5f5f5; padding: 8px 12px; border-radius: 4px; display: inline-block; font-size: 14px; letter-spacing: 1px; }',
    ], 'smalk_masked_key_style'];

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
    $existingApiKey = $config->get('api_key');
    $hasApiKey = !empty($existingApiKey);
    
    $form['credentials'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('API Key'),
      '#description' => $this->t('Enter your API Key from the <a href="@dashboard" target="_blank">Smalk Dashboard</a> → Settings → API Keys.', [
        '@dashboard' => 'https://app.smalk.ai',
      ]),
    ];

    // Show masked API key if it exists.
    if ($hasApiKey) {
      $maskedKey = $this->maskApiKey($existingApiKey);
      $form['credentials']['api_key_current'] = [
        '#type' => 'item',
        '#title' => $this->t('Current API Key'),
        '#markup' => '<div class="smalk-api-key-masked"><code>' . htmlspecialchars($maskedKey) . '</code></div>',
        '#description' => $this->t('Your API key is saved. Enter a new key below to update it.'),
      ];
    }

    $form['credentials']['api_key'] = [
      '#type' => 'textfield',
      '#title' => $hasApiKey ? $this->t('Update API Key (optional)') : $this->t('API Key'),
      '#description' => $hasApiKey 
        ? $this->t('Leave empty to keep the current API key, or enter a new one to update it. Workspace info will be fetched automatically.')
        : $this->t('Your Smalk API Key. Workspace info will be fetched automatically.'),
      '#default_value' => '',
      '#required' => !$hasApiKey, // Only required if no key exists yet
      '#maxlength' => 128,
      '#attributes' => [
        'autocomplete' => 'off',
      ],
    ];

    // Display workspace info if configured.
    $workspaceName = $config->get('workspace_name');
    $publisherActivated = $config->get('publisher_activated');
    $workspaceKey = $config->get('workspace_key');
    $apiKey = $config->get('api_key');
    
    // Status information.
    $form['status'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Module Status'),
    ];
    
    $statusItems = [];
    
    // API Key status.
    if (empty($apiKey)) {
      $statusItems[] = '<span style="color: red;">✗</span> ' . $this->t('API Key: Not configured');
    }
    else {
      $statusItems[] = '<span style="color: green;">✓</span> ' . $this->t('API Key: Configured');
    }
    
    // Workspace Key status.
    if (empty($workspaceKey)) {
      $statusItems[] = '<span style="color: orange;">⚠</span> ' . $this->t('Workspace Key: Missing (configure API Key first)');
    }
    else {
      $statusItems[] = '<span style="color: green;">✓</span> ' . $this->t('Workspace Key: Configured');
    }
    
    // Tracking status.
    if (empty($apiKey) || empty($workspaceKey)) {
      $statusItems[] = '<span style="color: orange;">⚠</span> ' . $this->t('Tracking: Disabled (needs API Key and Workspace Key)');
    }
    elseif (!$config->get('enabled') || !$config->get('tracking_enabled')) {
      $statusItems[] = '<span style="color: orange;">⚠</span> ' . $this->t('Tracking: Disabled (check feature toggles below)');
    }
    else {
      $statusItems[] = '<span style="color: green;">✓</span> ' . $this->t('Tracking: Active');
    }
    
    $form['status']['items'] = [
      '#type' => 'markup',
      '#markup' => '<ul style="list-style: none; padding-left: 0;"><li>' . implode('</li><li>', $statusItems) . '</li></ul>',
    ];
    
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
    elseif (empty($apiKey)) {
      $form['workspace_info'] = [
        '#type' => 'markup',
        '#markup' => '<p class="description" style="color: orange;">' . $this->t('Configure your API Key above and save to fetch workspace information.') . '</p>',
      ];
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
      '#description' => $this->t('Maximum time to wait for API responses. Recommended: 1.0 second for development, 0.25-0.5 for production.'),
      '#default_value' => $config->get('api_timeout') ?: 1.0,
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
      '#description' => $this->t('One path per line. Use * as wildcard. Default exclusions: /admin/*, /user/*, /api/*, /.well-known/*'),
      '#default_value' => $config->get('excluded_paths'),
      '#rows' => 4,
    ];

    $form['advanced']['debug_mode'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable debug mode'),
      '#description' => $this->t('Log detailed info to Drupal watchdog.'),
      '#default_value' => $config->get('debug_mode'),
    ];

    // Troubleshooting section with fix button.
    $form['advanced']['troubleshooting'] = [
      '#type' => 'details',
      '#title' => $this->t('Troubleshooting'),
      '#open' => FALSE,
    ];

    $form['advanced']['troubleshooting']['fix_formats'] = [
      '#type' => 'submit',
      '#value' => $this->t('Re-configure Text Formats'),
      '#submit' => ['::fixTextFormats'],
      '#limit_validation_errors' => [],
      '#button_type' => 'secondary',
      '#prefix' => '<p class="description">' . $this->t('If the smalk-ads attribute is being stripped when saving content, click to re-configure text formats.') . '</p>',
    ];

    // Ad Placement instructions.
    $form['placement'] = [
      '#type' => 'details',
      '#title' => $this->t('How to Add Ads to Your Content'),
      '#open' => FALSE,
    ];

    $form['placement']['instructions'] = [
      '#type' => 'markup',
      '#markup' => '
        <p>' . $this->t('Add the following HTML where you want ads to appear:') . '</p>
        <pre style="background: #f5f5f5; padding: 10px; border-radius: 4px;">&lt;div smalk-ads&gt;&lt;/div&gt;</pre>
        <p><strong>' . $this->t('Steps:') . '</strong></p>
        <ol>
          <li>' . $this->t('Edit your page or article') . '</li>
          <li>' . $this->t('Click the "Source" button in the editor toolbar') . '</li>
          <li>' . $this->t('Paste the HTML code where you want the ad') . '</li>
          <li>' . $this->t('Save your content') . '</li>
        </ol>
        <p><strong>' . $this->t('For multiple ad placements:') . '</strong></p>
        <pre style="background: #f5f5f5; padding: 10px; border-radius: 4px;">&lt;div smalk-ads id="top-ad"&gt;&lt;/div&gt;
&lt;div smalk-ads id="bottom-ad"&gt;&lt;/div&gt;</pre>
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
    $config = $this->config('smalk.settings');
    $existingApiKey = $config->get('api_key');
    
    // If no new API key provided and one already exists, keep the existing one.
    if (empty($apiKey) && !empty($existingApiKey)) {
      // Use existing key for validation (but don't update it in submit)
      $apiKey = $existingApiKey;
    }
    
    // If still empty, validation will fail via #required (if no key exists)
    if (empty($apiKey)) {
      return;
    }

    // Fetch workspace info to validate API key.
    $workspaceInfo = $this->fetchWorkspaceInfo($apiKey);
    
    if ($workspaceInfo === NULL) {
      $form_state->setErrorByName('api_key', $this->t('Invalid API Key or could not connect to Smalk API. Check your API key and ensure your server can reach @api_url. See Drupal logs (Recent log messages) for details.', [
        '@api_url' => SmalkApi::getBaseUrl(),
      ]));
      return;
    }

    // Store workspace info in form state for submit handler.
    $form_state->set('workspace_info', $workspaceInfo);
  }

  /**
   * Submit handler to fix text formats.
   */
  public function fixTextFormats(array &$form, FormStateInterface $form_state): void {
    $updated_count = $this->updateTextFormats();
    $ckeditor_count = $this->updateCkeditor5Configs();

    $messages = [];

    if ($updated_count > 0) {
      $messages[] = $this->t('Updated @count text format(s) to allow smalk-ads attribute.', [
        '@count' => $updated_count,
      ]);
    }

    if ($ckeditor_count > 0) {
      $messages[] = $this->t('Updated @count CKEditor 5 configuration(s) to allow smalk-ads in Source Editing.', [
        '@count' => $ckeditor_count,
      ]);
    }

    if (!empty($messages)) {
      foreach ($messages as $message) {
        $this->messenger()->addStatus($message);
      }
      // Clear cache to ensure changes take effect.
      drupal_flush_all_caches();
      $this->messenger()->addStatus($this->t('Cache cleared. The smalk-ads attribute should now work correctly.'));
    }
    else {
      // Check which text formats exist and their status.
      $formats = \Drupal\filter\Entity\FilterFormat::loadMultiple();
      $format_details = [];
      foreach ($formats as $format_id => $format) {
        $config = $format->toArray();
        $has_filter_html = isset($config['filters']['filter_html']['status']) && $config['filters']['filter_html']['status'];
        $allowed_html = $has_filter_html ? ($config['filters']['filter_html']['settings']['allowed_html'] ?? '') : '';
        $has_smalk_ads = strpos($allowed_html, 'smalk-ads') !== FALSE;

        $format_details[] = $format->label() . ' (' . ($has_filter_html ? 'filter_html: ' . ($has_smalk_ads ? 'has smalk-ads' : 'missing smalk-ads') : 'no filter_html') . ')';
      }

      $this->messenger()->addWarning($this->t('All text formats appear to be configured. Current status: @formats', [
        '@formats' => implode(', ', $format_details),
      ]));
      $this->messenger()->addStatus($this->t('If smalk-ads is still being stripped, make sure "Source Editing" is enabled in your CKEditor 5 toolbar. Go to Configuration → Text formats → Configure your format → Add "Source" button to toolbar.'));
    }
  }

  /**
   * Updates CKEditor 5 editor configurations to allow smalk-ads in Source Editing.
   *
   * @return int
   *   Number of editor configurations updated.
   */
  protected function updateCkeditor5Configs(): int {
    $updated_count = 0;

    // Check if Editor module is enabled.
    if (!\Drupal::moduleHandler()->moduleExists('editor')) {
      return 0;
    }

    // Load all editor configurations.
    $editors = \Drupal\editor\Entity\Editor::loadMultiple();

    foreach ($editors as $editor) {
      // Only process CKEditor 5 editors.
      if ($editor->getEditor() !== 'ckeditor5') {
        continue;
      }

      $settings = $editor->getSettings();
      $needs_save = FALSE;

      // Check if Source Editing plugin is configured.
      if (isset($settings['plugins']['ckeditor5_sourceEditing'])) {
        $allowed_tags = $settings['plugins']['ckeditor5_sourceEditing']['allowed_tags'] ?? [];

        // Add smalk-ads div tags if not already present.
        $tags_to_add = ['<div smalk-ads>', '<div smalk-ads id>'];

        foreach ($tags_to_add as $tag) {
          if (!in_array($tag, $allowed_tags)) {
            $allowed_tags[] = $tag;
            $needs_save = TRUE;
          }
        }

        if ($needs_save) {
          $settings['plugins']['ckeditor5_sourceEditing']['allowed_tags'] = $allowed_tags;
          $editor->setSettings($settings);
          $editor->save();
          $updated_count++;
        }
      }
      else {
        // Source Editing not configured - check if it's in the toolbar.
        $toolbar_items = $settings['toolbar']['items'] ?? [];

        if (in_array('sourceEditing', $toolbar_items)) {
          // Source Editing is in toolbar but not configured - initialize it.
          $settings['plugins']['ckeditor5_sourceEditing'] = [
            'allowed_tags' => ['<div smalk-ads>', '<div smalk-ads id>'],
          ];
          $editor->setSettings($settings);
          $editor->save();
          $updated_count++;
        }
      }
    }

    return $updated_count;
  }

  /**
   * Updates all text formats to allow smalk-ads attribute on div elements.
   *
   * @return int
   *   Number of text formats updated.
   */
  protected function updateTextFormats(): int {
    $updated_count = 0;
    
    // Load all filter formats.
    $formats = \Drupal\filter\Entity\FilterFormat::loadMultiple();
    
    foreach ($formats as $format) {
      $config = $format->toArray();
      
      // Check if this format uses the filter_html filter.
      if (!isset($config['filters']['filter_html']['status']) || !$config['filters']['filter_html']['status']) {
        // Skip formats that don't have filter_html enabled.
        continue;
      }
      
      // Get the current allowed HTML.
      $allowed_html = $config['filters']['filter_html']['settings']['allowed_html'] ?? '';
      
      // Check if smalk-ads is already present.
      if (strpos($allowed_html, 'smalk-ads') !== FALSE) {
        // Already configured, skip.
        continue;
      }
      
      // Parse and update the allowed HTML to include smalk-ads on div tags.
      $updated_allowed_html = $this->addAttributeToTag($allowed_html, 'div', 'smalk-ads');
      
      if ($updated_allowed_html !== $allowed_html) {
        // Get all existing filter settings.
        $existing_settings = $config['filters']['filter_html']['settings'] ?? [];
        
        // Update only the allowed_html setting, preserve all others.
        $existing_settings['allowed_html'] = $updated_allowed_html;
        
        // Update the filter configuration.
        $format->setFilterConfig('filter_html', [
          'status' => TRUE,
          'weight' => $config['filters']['filter_html']['weight'] ?? -10,
          'settings' => $existing_settings,
        ]);
        
        $format->save();
        $updated_count++;
      }
    }
    
    return $updated_count;
  }

  /**
   * Adds an attribute to a tag in the allowed HTML string.
   *
   * @param string $allowed_html
   *   The current allowed HTML string.
   * @param string $tag
   *   The tag name (e.g., 'div').
   * @param string $attribute
   *   The attribute to add (e.g., 'smalk-ads').
   *
   * @return string
   *   The updated allowed HTML string.
   */
  protected function addAttributeToTag(string $allowed_html, string $tag, string $attribute): string {
    // Drupal's allowed HTML format is space-separated: "<tag> <tag attr1 attr2> ..."
    // Pattern to match the tag definition: <div>, <div id>, <div id class>, etc.
    $pattern = '/<' . preg_quote($tag, '/') . '([^>]*)>/i';
    
    if (preg_match($pattern, $allowed_html, $matches)) {
      // Tag exists in allowed HTML.
      $existing_attrs = trim($matches[1]);
      
      // Check if attribute already exists (using word boundary to avoid partial matches).
      if (preg_match('/\b' . preg_quote($attribute, '/') . '\b/', $existing_attrs)) {
        // Attribute already present, return as-is.
        return $allowed_html;
      }
      
      // Add the attribute to existing attributes.
      if (empty($existing_attrs)) {
        $new_attrs = $attribute . ' id';
      }
      else {
        // Also ensure id is present if not already there.
        if (strpos($existing_attrs, 'id') === FALSE) {
          $new_attrs = $existing_attrs . ' ' . $attribute . ' id';
        }
        else {
          $new_attrs = $existing_attrs . ' ' . $attribute;
        }
      }
      
      $new_tag = '<' . $tag . ' ' . $new_attrs . '>';
      
      // Replace the tag in the allowed HTML.
      return preg_replace($pattern, $new_tag, $allowed_html, 1);
    }
    else {
      // Tag doesn't exist, add it with the attribute and id.
      $new_tag = ' <' . $tag . ' ' . $attribute . ' id>';
      return trim($allowed_html) . $new_tag;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $workspaceInfo = $form_state->get('workspace_info');

    $config = $this->config('smalk.settings');
    
    // Only update API key if a new value was provided.
    $newApiKey = trim($form_state->getValue('api_key'));
    if (!empty($newApiKey)) {
      $config->set('api_key', $newApiKey);
    }
    // Otherwise, keep the existing API key (it's already in config)
    
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
      $response = $this->httpClient->request('GET', SmalkApi::getProjectsUrl(), [
        'headers' => [
          'Authorization' => 'Api-Key ' . $apiKey,
          'Content-Type' => 'application/json',
        ],
        'timeout' => 5,
      ]);

      $statusCode = $response->getStatusCode();
      $body = $response->getBody()->getContents();
      $data = json_decode($body, TRUE);

      if ($statusCode === 200) {
        $projectData = NULL;
        
        // According to API docs, /api/v1/projects/ returns a list (array)
        // When using API key, it's a list with 1 item: [{id: ..., name: ...}]
        // When using user auth, it's a list with multiple items: [{...}, {...}, ...]
        if (is_array($data)) {
          // Check if it's a list/array response (most common case)
          // Use array_key_exists to avoid "Undefined array key" warnings
          if (!empty($data) && array_key_exists(0, $data) && is_array($data[0]) && isset($data[0]['id'])) {
            $projectData = $data[0];
          }
          // Fallback: Check if it's a single object at root level (shouldn't happen per docs, but handle it)
          elseif (isset($data['id']) && isset($data['name']) && !array_key_exists(0, $data)) {
            $projectData = $data;
          }
        }
        
        if ($projectData && isset($projectData['id'])) {
          return [
            'key' => $projectData['id'], // Project ID (UUID) is used as the workspace key
            'name' => $projectData['name'] ?? '',
            'publisher_activated' => $projectData['publisher_ads_enabled'] ?? FALSE,
          ];
        }
        
        // Log if we got a 200 but couldn't parse the response
        \Drupal::logger('smalk')->warning('Smalk API returned 200 but unexpected response format. Data type: @type, Response: @response', [
          '@type' => gettype($data),
          '@response' => substr($body, 0, 500),
        ]);
      }
      elseif ($statusCode === 401 || $statusCode === 403) {
        \Drupal::logger('smalk')->error('Smalk API authentication failed. Status: @status, Response: @response', [
          '@status' => $statusCode,
          '@response' => substr($body, 0, 200),
        ]);
        return NULL;
      }
      else {
        \Drupal::logger('smalk')->warning('Smalk API returned unexpected status: @status, Response: @response', [
          '@status' => $statusCode,
          '@response' => substr($body, 0, 200),
        ]);
      }

      return NULL;

    } catch (\GuzzleHttp\Exception\RequestException $e) {
      $statusCode = $e->hasResponse() ? $e->getResponse()->getStatusCode() : 'N/A';
      $responseBody = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : '';
      
      \Drupal::logger('smalk')->error('Failed to fetch workspace info from Smalk API. Status: @status, Error: @message, Response: @response', [
        '@status' => $statusCode,
        '@message' => $e->getMessage(),
        '@response' => substr($responseBody, 0, 200),
      ]);
      
      return NULL;
    } catch (\Exception $e) {
      \Drupal::logger('smalk')->error('Unexpected error fetching workspace info: @message', [
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Mask an API key for display purposes.
   *
   * Shows only the last 4 characters, masking the rest.
   *
   * @param string $apiKey
   *   The API key to mask.
   *
   * @return string
   *   Masked API key (e.g., "********************xxxx").
   */
  protected function maskApiKey(string $apiKey): string {
    $length = strlen($apiKey);
    if ($length <= 4) {
      // If key is 4 chars or less, mask everything
      return str_repeat('*', $length);
    }
    
    // Show last 4 characters, mask the rest
    $visibleChars = 4;
    $maskedPart = str_repeat('*', $length - $visibleChars);
    $visiblePart = substr($apiKey, -$visibleChars);
    
    return $maskedPart . $visiblePart;
  }

}
