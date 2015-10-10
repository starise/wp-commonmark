<?php
/**
 * Plugin Name: WP CommonMark
 * Description: Enable Commonmark: a strongly specified, highly compatible implementation of Markdown.
 * Version:     0.2.0
 * Author:      Andrea Brandi
 * Author URI:  http://andreabrandi.com
 */

namespace starise\WordPress;

if (file_exists(__DIR__ . '/vendor/autoload.php')) {
  require __DIR__ . '/vendor/autoload.php';
}

define(__NAMESPACE__ . '\WPCM_VERSION', '0.2.0');
define(__NAMESPACE__ . '\WPCM_DIR_URL', plugins_url('', __FILE__));
define(__NAMESPACE__ . '\WPCM_DIR_PATH', plugin_basename(__FILE__));

add_action('init', [CommonMark::get_instance(), 'init']);
