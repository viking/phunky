<?php
class HamlParser {
  var $filename, $level, $tree, $line_number, $line;

  static function html_attributes($attribs) {
    $retval = '';
    foreach ($attribs as $key => $val) {
      if (!$val) continue;
      $retval .= " $key=\"".htmlspecialchars($val, ENT_COMPAT, "ISO-8859-1", false)."\"";
    }
    return $retval;
  }

  function __construct($filename) {
    $this->filename = $filename;
    $this->line_number = 0;
  }

  function report($msg) {
    die("haml error on line " . $this->line_number . ": $msg\n");
  }

  function code_template($str, $echo = false) {
    return '<?php '.(($echo) ? "echo " : "").$str.'; ?>';
  }

  function compile() {
    $input = fopen($this->filename, 'r');
    $level = -1;
    $template = "<?php include('haml.php') ?>";
    $closing_tags = array();
    $previous_closing = "";
    $m = array();   // for regex matches
    while (true) {
      $this->line_number++;
      $newline = null;
      $closing = null;
      $line = fgets($input);
      if ($line === false) {
        // EOF
        $diff  = -1 - $level;
        $level = -1;
      }
      else {
        $line = rtrim($line);

        //
        // get level
        //
        $tmp  = ltrim($line);
        $len  = strlen($line) - strlen($tmp);
        $line = $tmp;
        if ($len % 2 != 0)
          $this->report("invalid indention ($len)");

        $new_level = $len / 2;
        $diff = $new_level - $level;
        if ($diff > 1)
          $this->report("skipped too many levels (from $level to $new_level)");

        $level = $new_level;

        //
        // main parsing logic
        //

        // php code
        if (preg_match("/^(=|-)\s*(.+)$/", $line, $m)) {
          $newline .= $this->code_template($m[2], ($m[1] == "="));
        }
        // comment
        elseif (preg_match("/^\/\s*(.+)?$/", $line, $m)) {
          if ($m[1]) {
            $newline .= '<!-- '.$m[1].' -->';
          }
          else {
            $newline .= "<!--";
            $closing = "-->";
          }
        }
        // xhtml tag
        elseif (preg_match("/^([#.%])([\w-]+)((?:[#.][\w-]+)+)?(?:{((?::\w+ => .+?(?:,\s*)?)+)})?/", $line, $m)) {
          $attribs = "";
          $id = ""; $class = "";
          $offset = strlen($m[0]);
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

          // handle id/class attributes
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

          // handle general attributes
          //   ex: %div{:foo => 'bar', :baz => str_repeat("huge", 10)}
          if ($m[4]) {
            $str = preg_replace('/:(\w+)\s*=>/', '"$1" =>', $m[4]);
            $attribs .= (($attribs) ? ", " : "").$str;
          }

          if ($attribs != "") {
            $newline .= "<?php echo HamlParser::html_attributes(array($attribs)); ?>";
          }

          if ($offset < strlen($line)) {
            // handle self-closing or content
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
        else {
          // text node
          $newline = $line;
        }
      }

      // now it's time to handle level differences
      if ($diff == 0) {
        // current node is sibling of previous node
        // if the previous node hasn't been closed yet, close it
        if ($previous_closing) {
          $template .= $previous_closing;
        }
        $template .= "\n";
      }
      if ($diff == 1) {
        // current node is child of previous node
        // push closing tag of previous node onto stack
        $template .= "\n";
        if ($previous_closing === null)
          $this->report("illegal nesting");

        array_push($closing_tags, $previous_closing);
      }
      if ($diff < 0) {
        // we've dropped at least 1 level, it's time to close off some tags
        if ($previous_closing) {
          $template .= trim($previous_closing);
        }
        $template .= "\n";
        for ($i = 0; $i > $diff; $i--) {
          $tag = array_pop($closing_tags);
          if ($tag) {
            // the only time this doesn't run is for the 'root' node closing
            $template .= $tag . "\n";
          }
        }
      }

      if ($newline === null) {
        // this only happens at EOF
        break;
      }
      $indent = str_repeat("  ", $level);
      $template .= $indent . $newline;
      $previous_closing = ($closing === null) ? null : $indent . $closing;
    }
    return $template;
  }
}
?>
