<?php

namespace starise\WordPress;

class CommonMark_XmlRpc extends CommonMark
{
  protected static $instance = null;

  public $posts_to_uncache = [];

  public static function get_instance()
  {
    null === self::$instance and self::$instance = new self;
    return self::$instance;
  }

  public function init()
  {
    add_filter('xmlrpc_call', [$this, 'xmlrpc_actions']);
    if ($this->is_xmlrpc_request()) {
      $this->check_for_early_methods();
    }
  }

  public function is_xmlrpc_request()
  {
    return defined('XMLRPC_REQUEST') && XMLRPC_REQUEST;
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
    foreach(CommonMark_XmlRpc::get_instance()->posts_to_uncache as $post_id) {
      wp_cache_delete($post_id, 'posts');
    }
  }

  /**
   * Singleton silence is golden
   */
  private function __construct() {}
}
