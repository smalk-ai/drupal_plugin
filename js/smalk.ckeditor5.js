/**
 * @file
 * CKEditor5 plugin to allow smalk-ads attribute on div elements.
 *
 * This plugin tells CKEditor5 that the smalk-ads attribute is allowed on div tags.
 */

(function (Drupal, CKEditor5) {

  /**
   * CKEditor5 plugin for Smalk ads attribute.
   */
  class SmalkAds extends CKEditor5.core.Plugin {
    /**
     * Plugin name for registration.
     */
    static get pluginName() {
      return 'SmalkAds';
    }

    /**
     * {@inheritdoc}
     */
    init() {
      const editor = this.editor;
      const schema = editor.model.schema;
      const conversion = editor.conversion;

      // Allow smalk-ads attribute on any element that can have attributes.
      schema.addAttributeCheck((context, attributeName) => {
        if (attributeName === 'smalkAds') {
          return true;
        }
      });

      // Register smalk-ads attribute in the schema for div elements.
      if (schema.isRegistered('htmlDiv')) {
        schema.extend('htmlDiv', {
          allowAttributes: ['smalkAds'],
        });
      }

      // Also register for generic elements.
      if (schema.isRegistered('htmlAttributes')) {
        schema.extend('htmlAttributes', {
          allowAttributes: ['smalkAds'],
        });
      }

      // Upcast converter: maps smalk-ads attribute from HTML (view) to model.
      conversion.for('upcast').attributeToAttribute({
        view: {
          key: 'smalk-ads',
        },
        model: 'smalkAds',
      });

      // Downcast converter: maps smalk-ads attribute from model to HTML (view).
      conversion.for('downcast').attributeToAttribute({
        model: 'smalkAds',
        view: 'smalk-ads',
      });
    }
  }

  // Register the plugin with CKEditor5 in the expected namespace.
  CKEditor5.smalk = CKEditor5.smalk || {};
  CKEditor5.smalk.SmalkAds = SmalkAds;

})(Drupal, CKEditor5);



