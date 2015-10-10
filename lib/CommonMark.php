<?php

namespace starise\WordPress;

class CommonMark
{
  const IS_MARKDOWN = '_is_markdown';

  const NONCE_NAME = '_wpcm_nonce';

  private function __construct() {}

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
}
