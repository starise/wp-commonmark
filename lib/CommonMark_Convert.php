<?php

namespace starise\WordPress;

use League\CommonMark\CommonMarkConverter;

class CommonMark_Convert
{
  protected $markdown = null;

  public function __construct()
  {
    $this->markdown = new CommonMarkConverter([
      'renderer' => [
        'block_separator' => "\n\n",
        'inner_separator' => "\n",
        'soft_break'      => "\n",
      ]
    ]);
  }

  /**
   * Convert Markdown to HTML.
   * @param string $text  Markdown content to be converted
   * @param array  $args  Arguments, with keys:
   *                      unslash: when true, expects and returns slashed data
   */
  public function to_html($text, $args = [])
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

  /**
   * Remove bare <p> elements. <p>s with attributes will be preserved.
   * @param  string $text HTML content
   * @return string       <p>-less content
   */
  public function unp($text)
  {
    return preg_replace("#<p>(.*?)</p>(\n|$)#ums", '$1$2', $text);
  }
}
