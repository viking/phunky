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
    'javascript' => array('Phunky', 'filter_javascript')
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

  // compiling helpers {{{1
  function __construct($filename) {
    $this->filename = $filename;
    $this->line_number = 0;
  }

  function report($msg) {
    die("haml error on line " . $this->line_number . ": $msg\n");
  }

  function code_template($str, $echo = false) {
    return '<?php '.(($echo) ? "echo " : "").$str.' ?>';
  }

  // compile() {{{1
  function compile() {
    // setup {{{2
    $input = fopen($this->filename, 'r');
    $level = -1;
    $template = "";
    $closing_tags = array();
    $previous_closing = "";
    $done = false;

    // for filter handling
    $filter_text = null;
    $filter_level = null;
    $new_filter_name = null;
    $current_filter_name = null;
    $in_filter = false;
    // }}}2

    $m = array();   // for regex matches
    while (true) {
      // main loop start {{{2
      $this->line_number++;
      $newline = null;
      $closing = null;
      $line = fgets($input);
      if ($line === false) {
        // EOF
        $diff  = -1 - $level;
        $level = -1;
        $done  = true;
      }
      else {
        $line = rtrim($line);

        // get level {{{2
        preg_match("/^\s*/", $line, $m);
        $len = strlen($m[0]);
        $new_level = $len / 2;
        if ($in_filter && $new_level > $filter_level) {
          // since we're inside a filter, we only really care if the
          // new level is less than or equal to the filter level, because
          // that means the filter just finished
          $line = substr($line, ($filter_level + 1) * 2);
          $new_level = $filter_level + 1;
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
        if ($in_filter && $level > $filter_level) {
          if ($filter_text) {
            $filter_text .= "\n";
          }
          $filter_text .= $line;
          continue;
        }
        // normal handling {{{2
        else {
          // escaped {{{3
          if (preg_match('/^\\\/', $line)) {
            $newline = substr($line, 1);
          }
          // php code {{{3
          elseif (preg_match("/^(=|-)\s*(.+)$/", $line, $m)) {
            $char = $m[1];
            $code = $m[2];
            if ($char == "=") {
              $code .= '; echo "\n";';
            }
            else {
              if (substr($m[2], -1) == "{") {
                $closing = "<?php } ?>";
              }
              else {
                $code .= ';';
              }
            }
            $newline .= $this->code_template($code, $char == '=');
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
          }
          // xhtml tag {{{3
          elseif (preg_match("/^([#.%])([\w-]+)((?:[#.][\w-]+)+)?(?:{((?::\w+ => .+?(?:,\s*)?)+)})?/", $line, $m)) {
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
          // filter {{{3
          elseif (preg_match('/^:(.+)$/', $line, $m)) {
            $new_filter_name = $m[1];
            if (!array_key_exists($new_filter_name, self::$filter_handlers))
              $this->report("unsupported filter: ".$new_filter_name);

            $in_filter = true;
            $filter_level = $level;
          }
          // text node {{{3
          else {
            $newline = $line;
          }
        }
      }

      // level differences {{{2
      if ($diff <= 0) {
        // either the current node is a sibling of the previous node (diff == 0)
        // or the previous node was part of a tree that is now closed (diff < 0)

        if ($in_filter && $level <= $filter_level) {
          // execution gets here under one of the following circumstances:
          //   - a new filter just started after a non-filter
          //   - a new filter just started right after the current filter finished
          //   - the current filter just finished
          if ($current_filter_name) {
            // the current filter just finished; process it
            $callback = self::$filter_handlers[$current_filter_name];
            $template .= call_user_func($callback, $filter_text);
          }

          if ($new_filter_name) {
            // a new filter just started
            $current_filter_name = $new_filter_name;
            $new_filter_name = null;
            $filter_text = "";
          }
          else {
            // non-filter followed the current filter
            $current_filter_name = $filter_text = $filter_level = null;
            $in_filter = false;
          }
        }
        elseif ($previous_closing) {
          $template .= trim($previous_closing);
        }
        // filter_start is only true when the current line is :foo
        $filter_start = false;
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
        if ($template) {
          // this happens for all but the first iteration
          $template .= "\n";
        }
        if ($previous_closing === null)
          $this->report("illegal nesting");

        array_push($closing_tags, $previous_closing);
      }

      // main loop end {{{2
      if ($done) {
        // this only happens at EOF
        break;
      }
      $indent = str_repeat("  ", $level);
      if ($newline)
        $template .= $indent . $newline;
      $previous_closing = ($closing === null) ? null : $indent . $closing;
      // }}}2
    }
    return $template;
  }
}
// }}}1

// vim:fdm=marker
?>
