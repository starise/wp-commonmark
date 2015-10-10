<?php

namespace starise\WordPress;

use League\CommonMark\CommonMarkConverter;

class CommonMark
{
  protected static $instance = null;

  const IS_MARKDOWN = '_is_markdown';

  public $posts_to_uncache = [];

  private $monitoring = ['post' => []];

  public $markdown;

  public static function get_instance()
  {
    null === self::$instance and self::$instance = new self;
    return self::$instance;
  }

  public function init()
  {
    // Load Markdown Class
    $this->markdown = new CommonMarkConverter([
      'renderer' => [
        'block_separator' => "\n\n",
        'inner_separator' => "\n",
        'soft_break'      => "\n",
      ]
    ]);

    // load_plugin_textdomain('wp-commonmark', null, basename(dirname(__FILE__)));
    add_filter('wp_insert_post_data', [$this, 'wp_insert_post_data'], 10, 2);
    add_filter('wp_insert_post', [$this, 'wp_insert_post']);
    add_filter('edit_post_content', [$this, 'edit_post_content'], 10, 2);
    add_filter('edit_post_content_filtered', [$this, 'edit_post_content_filtered'], 10, 2);
    add_action('wp_restore_post_revision', [$this, 'wp_restore_post_revision'], 10, 2);
    add_filter('_wp_post_revision_fields', [$this, '_wp_post_revision_fields']);

    // Admin panel
    add_action('post_submitbox_misc_actions', [$this, 'submitbox_actions']);
    add_action('load-post.php', [$this, 'load_post']);
    add_action('load-post.php', [$this, 'enqueue_scripts']);
    add_action('load-post-new.php', [$this, 'enqueue_scripts']);

    // XML-RPC support
    add_filter('xmlrpc_call', [$this, 'xmlrpc_actions']);
    if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) {
      $this->check_for_early_methods();
    }
  }

  public function is_markdown($post_id)
  {
    return get_metadata('post', $post_id, self::IS_MARKDOWN, true);
  }

  protected function set_as_markdown($post_id)
  {
    return update_metadata('post', $post_id, self::IS_MARKDOWN, true);
  }

  protected function set_not_markdown($post_id)
  {
    return delete_metadata('post', $post_id, self::IS_MARKDOWN);
  }

  protected function is_autosave()
  {
    return defined('DOING_AUTOSAVE') && DOING_AUTOSAVE;
  }

  protected function verify_nonce()
  {
    $nonce = filter_has_var(INPUT_POST, '_wpcm_nonce') ? filter_input(INPUT_POST, '_wpcm_nonce') : false;
    return $nonce && wp_verify_nonce($nonce, 'wpcm-markdown-save');
  }

  public function wp_insert_post_data($post_data, $postarr)
  {
    // Note: $post_data array is slashed!
    $post_id = isset($postarr['ID']) ? $postarr['ID'] : false;
    $parent_id = isset($postarr['post_parent']) ? $postarr['post_parent'] : false;
    $nonce = isset($postarr['_wpcm_nonce']) && $this->verify_nonce();
    $checked = $nonce ? isset($postarr['wpcm_using_markdown']) : false;

    if ($nonce && $checked) {
      $post_data['post_content_filtered'] = $post_data['post_content'];
      $post_data['post_content'] = $this->transform($post_data['post_content']);
      $this->monitoring['post'][$post_id] = true;
    } elseif ($nonce && ! $checked) {
      // Check if it *was* a markdown post before
      if ($this->is_markdown($post_id) && ! empty($post_data['post_content_filtered'])) {
        $post_data['post_content_filtered'] = ''; // Remove old MD markup
      }
      $this->monitoring['post'][$post_id] = false;
    } elseif ($this->is_autosave()) {
      // Autosaves are weird, check markdown flag in post_parent
      if($this->is_markdown($post_data['post_parent'])) {
        $post_data['post_content_filtered'] = $post_data['post_content'];
        $post_data['post_content'] = $this->transform($post_data['post_content']);
      }
    }

    return $post_data;
  }

  /**
   * Calls on wp_insert_post action, after wp_insert_post_data.
   * @param  int $post_id The post ID that has just been added/updated
   * @return null
   */
  public function wp_insert_post($post_id)
  {
    if (isset($this->monitoring['post'][$post_id])) {
      if($this->monitoring['post'][$post_id] === true) {
        $this->set_as_markdown($post_id);
      } else {
        $this->set_not_markdown($post_id);
      }
      unset($this->monitoring['post'][$post_id]);
    }
  }

  public function wp_restore_post_revision($post_id, $revision_id)
  {
    $revision = get_post($revision_id, ARRAY_A);

    if(! empty($revision['post_content_filtered'])) {
      $this->set_as_markdown($post_id);
    } else {
      $this->set_not_markdown($post_id);
    }
  }

  /**
   * Swap post_content and post_content_filtered for editing
   * @param  string $content Post content
   * @param  int    $id      Post ID
   * @return string          Swapped content
   */
  public function edit_post_content($content, $id)
  {
    if ($this->is_markdown($id)) {
      $post = get_post($id);
      if ($post && ! empty($post->post_content_filtered)) {
        $post = $this->swap_for_editing($post);
        return $post->post_content;
      }
    }
    return $content;
  }

  /**
   * Swap post_content_filtered and post_content for editing
   * @param  string $content Post content_filtered
   * @param  int    $id      Post ID
   * @return string          Swapped content
   */
  public function edit_post_content_filtered($content, $id)
  {
    if ($this->is_markdown($id)) {
      $post = get_post($id);
      if ($post && ! empty($post->post_content_filtered))
        $content = '';
    }
    return $content;
  }

  /**
   * Swaps `post_content_filtered` back to `post_content` for editing purposes.
   * @param  object $post WP_Post object
   * @return object       WP_Post object with swapped `post_content_filtered` and `post_content`
   */
  protected function swap_for_editing($post)
  {
    $markdown = $post->post_content_filtered;
    $markdown = preg_replace('/^&gt; /m', '> ', $markdown); // restore beginning of line blockquotes
    $post->post_content_filtered = $post->post_content;
    $post->post_content = $markdown;
    return $post;
  }

  /**
   * Kicks off magic for an XML-RPC session. We want to keep editing Markdown
   * and publishing HTML.
   * @param  string $xmlrpc_method The current XML-RPC method
   * @return null
   */
  public function xmlrpc_actions($xmlrpc_method)
  {
    switch ($xmlrpc_method) {
      case 'metaWeblog.getRecentPosts':
      case 'wp.getPosts':
      case 'wp.getPages':
        add_action('parse_query', [$this, 'make_filterable'], 10, 1);
        break;
      case 'wp.getPost':
        $this->prime_post_cache();
        break;
    }
  }

  /**
   * metaWeblog.getPost and wp.getPage fire xmlrpc_call action *after* get_post() is called.
   * So, we have to detect those methods and prime the post cache early.
   * @return null
   */
  protected function check_for_early_methods()
  {
    global $HTTP_RAW_POST_DATA;
    if (false === strpos($HTTP_RAW_POST_DATA, 'metaWeblog.getPost')
      && false === strpos($HTTP_RAW_POST_DATA, 'wp.getPage')) {
      return;
    }
    include_once(ABSPATH . WPINC . '/class-IXR.php');
    $message = new IXR_Message($HTTP_RAW_POST_DATA);
    $message->parse();
    $post_id_position = 'metaWeblog.getPost' === $message->methodName ?  0 : 1;
    $this->prime_post_cache($message->params[$post_id_position]);
  }

  /**
   * Prime the post cache with swapped post_content. This is a sneaky way of getting around
   * the fact that there are no good hooks to call on the *.getPost xmlrpc methods.
   * @return null
   */
  private function prime_post_cache($post_id = false)
  {
    global $wp_xmlrpc_server;
    if (! $post_id) {
      $post_id = $wp_xmlrpc_server->message->params[3];
    }

    // prime the post cache
    if ($this->is_markdown($post_id)) {
      $post = get_post($post_id);
      if (! empty($post->post_content_filtered)) {
        wp_cache_delete($post->ID, 'posts');
        $post = $this->swap_for_editing($post);
        wp_cache_add($post->ID, $post, 'posts');
        $this->posts_to_uncache[] = $post_id;
      }
    }
    // uncache munged posts if using a persistent object cache
    if (wp_using_ext_object_cache()) {
      add_action('shutdown', [$this, 'uncache_munged_posts']);
    }
  }

  /**
   * We munge the post cache to serve proper markdown content to XML-RPC clients.
   * Uncache these after the XML-RPC session ends.
   * @return null
   */
  public function uncache_munged_posts()
  {
    // $this context gets lost in testing sometimes. Weird.
    foreach(CommonMark::get_instance()->posts_to_uncache as $post_id) {
      wp_cache_delete($post_id, 'posts');
    }
  }

  /**
   * Since *.(get)?[Rr]ecentPosts calls get_posts with suppress filters on, we need to
   * turn them back on so that we can swap things for editing.
   * @param  object $wp_query WP_Query object
   * @return null
   */
  public function make_filterable($wp_query)
  {
    $wp_query->set('suppress_filters', false);
    add_action('the_posts', [$this, 'the_posts'], 10, 2);
  }

  /**
   * Swaps post_content and post_content_filtered in all $posts for editing.
   * @param  array  $posts     Posts returned by the just-completed query
   * @param  object $wp_query  Current WP_Query object
   * @return array             Modified $posts
   */
  public function the_posts($posts, $wp_query)
  {
    foreach ($posts as $key => $post) {
      if ($this->is_markdown($post->ID) && ! empty($posts[$key]->post_content_filtered)) {
        $markdown = $posts[$key]->post_content_filtered;
        $posts[$key]->post_content_filtered = $posts[$key]->post_content;
        $posts[$key]->post_content = $markdown;
      }
    }
    return $posts;
  }

  /**
   * Markdown conversion method.
   * @param string $text  Markdown content to be converted
   * @param array  $args  Arguments, with keys:
   *                      unslash: when true, expects and returns slashed data
   */
  public function transform($text, $args = [])
  {
    $args = wp_parse_args($args, [
      'unslash' => true
    ]);

    // Probably need to unslash
    if ($args['unslash']) {
      $text = wp_unslash($text);
    }

    $text = apply_filters('wpcm_markdown_transform_pre', $text, $args);

    // Ensure our paragraphs are separated
    $text = str_replace(['</p><p>', "</p>\n<p>"], "</p>\n\n<p>", $text);
    // Sometimes we get an encoded > at start of line, breaking blockquotes
    $text = preg_replace('/^&gt;/m', '>', $text);
    // Convert Markdown to HTML!
    $text = $this->markdown->convertToHtml($text);
    // Restore quotation mark characters
    $text = str_replace('&quot;', '"', $text);
    // Remove redundant <p>s.
    $text = $this->unp($text);
    // Fix footnotes - kses doesn't like the : IDs it supplies
    $text = preg_replace('/((id|href)="#?fn(ref)?):/', "$1-", $text);
    // Markdown inserts extra spaces to make itself work.
    $text = rtrim($text);

    $text = apply_filters('wpcm_markdown_transform_post', $text, $args);

    // Probably need to re-slash
    if ($args['unslash']) {
      $text = wp_slash($text);
    }

    return $text;
  }

  public function submitbox_actions()
  {
    $markdown = isset($GLOBALS['post']) && isset($GLOBALS['post']->ID) && $this->is_markdown($GLOBALS['post']->ID);
    printf(
      '<style>
        #submitdiv h3 > span { margin-left: 38px; } #wpcm-markdown { position: absolute; top: 5px; left: 10px; }
        #wpcm-markdown img { vertical-align: bottom; margin-right: 10px; }
        #wpcm-markdown a:active { outline: 0 !important }
      </style>
      <wrap id="wpcm-markdown" style="display: none">
        <a href="#" onclick="return false;"><img %1$s class="markdown-status markdown-on" src="%2$s/assets/images/208x128-solid.png" width="32" height="20" />
        <img %1$s class="markdown-status markdown-off" src="%2$s/assets/images/208x128.png" width="32" height="20" /></a>
      </wrap>
      <input style="display: none" type="checkbox" name="wpcm_using_markdown" id="wpcm_using_markdown" value="1" %3$s />',
      ! $markdown ? 'style="display:none" ' : '',
      WPCM_DIR_URL,
      checked($this->is_markdown($GLOBALS['post']->ID), true, false));
    wp_nonce_field('wpcm-markdown-save', '_wpcm_nonce', false, true);
  }

  public function enqueue_scripts()
  {
    wp_enqueue_script('wp-commonmark', WPCM_DIR_URL . '/assets/scripts/wp-commonmark.js', ['jquery']);
  }

  public function load_post()
  {
    if (! isset($_GET['post'])) {
      return;
    }
    if ($this->is_markdown($_GET['post'])) {
      add_filter('user_can_richedit', '__return_false', 99);
    }
  }

  public function _wp_post_revision_fields($fields)
  {
    $fields['post_content_filtered'] = 'Markdown';
    return $fields;
  }

  /**
   * Remove bare <p> elements. <p>s with attributes will be preserved.
   * @param  string $text HTML content
   * @return string       <p>-less content
   */
  public function unp($text)
  {
    return preg_replace("#<p>(.*?)</p>(\n|$)#ums", '$1$2', $text);
  }

  /**
   * Singleton silence is golden
   */
  private function __construct() {}
}
