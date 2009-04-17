<?php
class Phunky {
  var $filename, $level, $tree, $line_number, $line;

  // helpers {{{1
  static function html_attributes($attribs) {
    $retval = '';
    foreach ($attribs as $key => $val) {
      if (!$val) continue;
      $retval .= " $key=\"".htmlspecialchars($val, ENT_COMPAT, "ISO-8859-1", false)."\"";
    }
    return $retval;
  }

  // filters {{{1
  static $filter_handlers = array(
    'plain'      => array('Phunky', 'filter_plain'),
    'javascript' => array('Phunky', 'filter_javascript'),
    'php'        => array('Phunky', 'filter_php'),
    'silent'     => array('Phunky', 'filter_silent')
  );
  static function filter_plain($text) {
    return $text;
  }
  static function filter_javascript($text) {
    // <script type='text/javascript'>
    //   //<![CDATA[
    //     whoa
    //   //]]>
    // </script>
    return
      "<script type='text/javascript'>\n" .
      "  //<![CDATA[\n" .
      "    " . preg_replace("/\n/", "\n    ", $text) . "\n" .
      "  //]]>\n" .
      "</script>";
  }
  static function filter_php($text) {
    return
      "<?php\n" .
      "  " . preg_replace("/\n/", "\n  ", $text) . "\n" .
      "?>";
  }
  static function filter_silent($_) {
    return "";
  }

  // compiling helpers {{{1
  function __construct($filename) {
    $this->filename = $filename;
    $this->line_number = 0;
  }

  function report($msg) {
    die("haml error on line " . $this->line_number . ": $msg\n");
  }

  function code_template($str, $echo = false, $closed = true) {
    return '<?php '.(($echo) ? "echo " : "").$str.(($closed) ? ' ?>' : '');
  }

  // compile() {{{1
  function compile() {
    // setup {{{2
    $input = fopen($this->filename, 'r');
    $level = -1;
    $template = "";
    $closing_tags = array();
    $previous_closing = "";
    $previous_node_type = null;
    $node_type = "root";

    // for filter handling
    $filter_text = null;
    $new_filter_level = null;
    $new_filter_name = null;
    $current_filter_level = null;
    $current_filter_name = null;
    $in_filter = false;
    $filter_end = false;
    $filter_start = false;
    // }}}2

    $m = array();   // for regex matches
    while (true) {
      // main loop start {{{2
      $this->line_number++;
      $newline = null;
      $closing = null;
      $no_indent = false;
      $previous_node_type = $node_type;
      $node_type = null;
      $line = fgets($input);
//      echo "previous_node_type: &lt;$previous_node_type&gt;<br/>\n";
//      echo "<!-- <".chop($line)."> -->\n";
      if ($line === false) {
        // EOF
        $diff  = -1 - $level;
        $level = -1;
        $node_type = "eof";
        if ($in_filter) {
          $filter_end = true;
        }
      }
      else {
        $line = rtrim($line);

        // get level {{{2
        preg_match("/^(\s*)(\S.*)?$/", $line, $m);
        if (!$m[2]) {
          $node_type = "empty";
          if ($in_filter && $filter_text) {
            $filter_text .= "\n";
          }
          continue;
        }
        $len = strlen($m[1]);
        $new_level = $len / 2;
        if ($in_filter) {
          if ($new_level > $current_filter_level) {
            // deeper levels don't matter when inside a filter
            $line = substr($line, ($current_filter_level + 1) * 2);
            $new_level = $current_filter_level;
          }
          else {
            // we're out of the filter
            $filter_end = true;
            $line = substr($line, $len);
          }
        }
        elseif ($len % 2 != 0) {
          $this->report("invalid indention ($len)");
        }
        else {
          // non-filter
          $line = substr($line, $len);
        }

        $diff = $new_level - $level;
        if ($diff > 1)
          $this->report("skipped too many levels (from $level to $new_level)");

        $level = $new_level;

        // filter handling {{{2
        if ($in_filter && !$filter_end) {
          if ($filter_text) {
            $filter_text .= "\n";
          }
          $filter_text .= $line;
          $node_type = "filter-text";
          continue;
        }
        // normal handling {{{2
        else {
          // escaped {{{3
          if (preg_match('/^\\\/', $line)) {
            $newline = substr($line, 1);
            $node_type = "text";
          }
          // filter {{{3
          // NOTE: silent comment blocks are handled as a noop filter
          elseif (preg_match('/^(-#|:(.+))$/', $line, $m)) {
            $node_type = "filter";
            $new_filter_name = ($m[1] == "-#") ? 'silent' : $m[2];
            if (!array_key_exists($new_filter_name, self::$filter_handlers))
              $this->report("unsupported filter: ".$new_filter_name);

            $in_filter = true;
            $filter_start = true;
            $new_filter_level = $level;
          }
          // silent comment {{{3
          elseif (preg_match("/^-#/", $line)) {
            $node_type = "silent-comment";
          }
          // php code {{{3
          elseif (preg_match("/^(=|-)\s*(.+)$/", $line, $m)) {
            $char = $m[1];
            $code = $m[2];
            if ($char == "=") {
              $node_type = "php-inline";
              $code .= '; echo "\n";';
              $newline .= $this->code_template($code, true);
            }
            else {
              // NOTE: non-echoing php nodes are intentionally left open
              // because they may or may not have child nodes
              $node_type = "php";
              $no_indent = true;
              $newline .= $this->code_template($code, false, false);
              $closing = "; ?>";
            }
          }
          // comment {{{3
          elseif (preg_match("/^\/(\[if IE.*?\])?\s*(.+)?$/", $line, $m)) {
            $newline .= '<!--';
            if ($m[1]) {
              $newline .= $m[1].'>';
              $closing  = '<![endif]-->';
            }
            else {
              $closing = "-->";
            }
            if ($m[2]) {
              $newline .= ' '.$m[2].' '.$closing;
              $closing  = null;
            }
            $node_type = "comment";
          }
          // xhtml tag {{{3
          elseif (preg_match("/^([#.%])([\w-]+)((?:[#.][\w-]+)+)?(?:{((?:\s*:\w+ => .+?\s*,?)+)})?/", $line, $m)) {
            $node_type = "tag";
            $attribs = "";
            $id = ""; $class = "";
            $offset = strlen($m[0]);

            // tag name {{{4
            if ($m[1] == "#" || $m[1] == ".") {
              // handle implicit div
              $newline = "<div";
              $closing = "</div>";
              $value = str_replace("'", "\\'", $m[2]);
              if ($m[1] == "#")
                $id = $value;
              else
                $class = $value;
            }
            else {
              // explicit tag name
              $newline = "<".$m[2]."";
              $closing = "</".$m[2].">";
            }

            // id/class attributes {{{4
            //   ex: %div#foo.bar
            if ($m[3]) {
              $x = array();
              preg_match_all("/([#.])([\w-]+)/", $m[3], $x);
              $len = count($x[0]);
              for ($i = 0; $i < $len; $i++) {
                $value = str_replace("'", "\\'", $x[2][$i]);
                if ($x[1][$i] == "#")
                  $id .= ($id) ? "_$value" : $value;
                else
                  $class .= ($class) ? " $value" : $value;
              }
            }
            if ($id) {
              $attribs = "'id' => '$id'";
            }
            if ($class) {
              if ($attribs) {
                $attribs .= ", ";
              }
              $attribs .= "'class' => '$class'";
            }

            // general attributes {{{4
            //   ex: %div{:foo => 'bar', :baz => str_repeat("huge", 10)}
            if ($m[4]) {
              $str = preg_replace('/:(\w+)\s*=>/', '"$1" =>', $m[4]);
              $attribs .= (($attribs) ? ", " : "").$str;
            }

            if ($attribs != "") {
              $newline .= "<?php echo Phunky::html_attributes(array($attribs)); ?>";
            }

            // self-closing or content {{{4
            if ($offset < strlen($line)) {
              $str = substr($line, $offset);
              preg_match("/^(\/|=)?\s*(.+?)?$/", $str, $m);
              if ($m[1] == "/") {
                $newline .= " />";
              }
              else {
                // content
                $newline .= '>';
                if ($m[1] == "=") {
                  // php code
                  $newline .= $this->code_template($m[2], true);
                }
                else {
                  $newline .= $m[2];
                }
                $newline .= $closing;
                $closing = null;
              }
              $closing = null;
            }
            else {
              // this is a block!
              $newline .= '>';
            }
          }
          // text node {{{3
          else {
            $newline = $line;
            $node_type = "text";
          }
        }
      }

      // filter start/end {{{2
      if ($in_filter) {
        // execution gets here under one of the following circumstances:
        //   - a new filter just started after a non-filter
        //   - a new filter just started right after the current filter finished
        //   - the current filter just finished
        if ($filter_end) {
          // the current filter just finished; process it
          $callback = self::$filter_handlers[$current_filter_name];
          $result = call_user_func($callback, $filter_text);

          // indent the result
          $indent = str_repeat("  ", $current_filter_level);
          $template .= $indent . preg_replace("/\n/", "\n$indent", $result);
          $filter_end = false;
        }

        if ($filter_start) {
          // a new filter just started
          $current_filter_name = $new_filter_name;
          $current_filter_level = $new_filter_level;
          $new_filter_name = $new_filter_level = null;
          $filter_text = "";
          $filter_start = false;
        }
        else {
          // non-filter followed the current filter
          $current_filter_name = $filter_text = $current_filter_level = null;
          $in_filter = false;
        }
      }

      // level differences {{{2
      if ($diff <= 0) {
        // either the current node is a sibling of the previous node (diff == 0)
        // or the previous node was part of a tree that is now closed (diff < 0)

        if ($previous_closing) {
          $template .= trim($previous_closing);
        }
        $template .= "\n";

        if ($diff < 0) {
          // we've dropped at least 1 level, it's time to close off some tags
          for ($i = 0; $i > $diff; $i--) {
            $tag = array_pop($closing_tags);
            if ($tag) {
              // this happens for all but the last iteration
              $template .= $tag . "\n";
            }
          }
        }
      }
      elseif ($diff == 1) {
        // current node is child of previous node
        // push closing tag of previous node onto stack
        if ($previous_node_type == "php") {
          // this php node has a child! add a brace if there isn't already one
          if (substr($template, -1) != "{") {
            $template .= " {";
          }
          $template .= " ?>";
          $previous_closing = "<?php } ?>";
        }

        if ($template) {
          // this happens for all but the first iteration
          $template .= "\n";
        }
        if ($previous_closing === null)
          $this->report("illegal nesting");

        array_push($closing_tags, $previous_closing);
      }

      // main loop end {{{2
      if ($node_type == "eof") {
        break;
      }
      $indent = ($no_indent) ? "" : str_repeat("  ", $level);
      if ($newline)
        $template .= $indent . $newline;
      $previous_closing = ($closing === null) ? null : $indent . $closing;
      // }}}2
    }

    // SMELL: this is a hack to make sure consecutive php blocks are merged
    // together, otherwise if-else won't work correctly
    return preg_replace('/\?>\n<\?php/', "\n", $template);
  }
}
// }}}1

// vim:fdm=marker
?>
