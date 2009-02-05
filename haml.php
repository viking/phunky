<?php
class Haml {
  const indent = 2;   // 2 spaces per indent level
  static function indent($num) {
    return str_repeat(" ", $num * Haml::indent);
  }
}

class HamlNode {
  var $contents, $level, $closed;
  function __construct($level = 0) {
    $this->contents = "";
    $this->level = $level;
    $this->closed = false;
  }

  function append($node) {
    if ($this->closed)
      return false;

    $this->contents .= $node;
    return true;
  }

  function __toString() {
//    if ($this->level == -1)
//      return $this->contents;
//
//    return Haml::indent($this->level) . $this->contents;
    return $this->contents;
  }

  function close() {
    $this->closed = true;
  }
}

class HamlTag extends HamlNode {
  var $name, $attribs, $closed;

  function __construct($name, $level = 0, $attribs = array()) {
    parent::__construct($level);
    $this->name = $name;
    $this->attribs = $attribs;
    $this->self_closed = false;
  }

  function __toString() {
//    $indent = Haml::indent($this->level);
//    return $indent . "<". $this->name . $this->html_attributes() . ">" .
//      $this->contents . $indent . "</" . $this->name . ">";
    $str = "<". $this->name . $this->html_attributes();
    if ($this->self_closed) {
      $str .= " />";
      return $str;
    }
    return $str.">".$this->contents."</".$this->name.">";
  }

  function html_attributes() {
    $retval = '';
    foreach ($this->attribs as $key => $val) {
      if (!$val) continue;
      $retval .= " $key=\"".htmlspecialchars($val, ENT_COMPAT, "ISO-8859-1", false)."\"";
    }
    return $retval;
  }

  function set_attribs(&$str) {
    $str = preg_replace('/\s*:(\w+)\s*=>/', '"$1" =>', $str);
    eval("\$this->attribs = array($str);");
  }

  function set_id_and_class(&$str) {
    $offset = 0;
    $len = strlen($str);
    $m = array();
    while ($offset < $len) {
      preg_match("/([#.])([\w-]+)/", $str, $m, 0, $offset);
      $offset += strlen($m[0]);
      if ($m[1] == "#") {
        $this->attribs['id'] = $m[2];
      }
      else {
        if (!array_key_exists('class', $this->attribs)) {
          $this->attribs['class'] = $m[2];
        }
        else {
          $this->attribs['class'] .= ' '.$m[2];
        }
      }
    }
  }

  function self_close() {
    $this->close();
    $this->self_closed = true;
  }
}

class HamlText extends HamlNode {
  function __construct($text, $level = 0) {
    parent::__construct($level);
    $this->contents = $text;
  }
}

class HamlParser {
  var $filename, $level, $tree, $line_number, $line;

  function __construct($filename) {
    $this->filename = $filename;
    $this->level = -1;
    $this->tree = array();
    $this->line_number = 0;
  }

  function compile() {
    $input = fopen($this->filename, 'r');
    $node  = new HamlNode(-1);
    while (true) {
      $prev_node = $node;
      $node = $diff = $new_level = null;

      $this->line = fgets($input);
      if ($this->line === false) {
        // end of file
        $diff = -1 - $this->level;
        $this->level = -1;
      }
      else {
        // create the next node
        $this->line_number++;
        $this->line = rtrim($this->line);
        if (preg_match("/^\s*$/", $this->line)) {
          // skip blank lines
          continue;
        }

        // NOTE: diff will always be 1 for the first iteration
        $diff = $this->get_level();

        ////////////////////////////
        //// main parsing logic ////
        ////////////////////////////
        if ($m = $this->match("/^([#.]|%)([\w-]+)/")) {
          // % - xhtml tag
          if ($m[1] == "#" || $m[1] == ".") {
            // handle implicit div
            $node = new HamlTag('div', $this->level);
            $node->set_id_and_class($m[0]);
          }
          else {
            $node = new HamlTag($m[2], $this->level);
          }

          // id and class
          // ex: %div#bar.foo
          if ($m = $this->match('/^([#.][\w-]+)+/')) {
            $node->set_id_and_class($m[0]);
          }

          // attributes
          // ex: %p{:huge => "small", :omg => "medium"}
          if ($m = $this->match('/^{((:\w+ => .+?(,\s*)?)+)}/')) {
            $node->set_attribs($m[1]);
          }

          // self-closed
          // ex: %br/
          if ($m = $this->match('/^\//')) {
            $node->self_close();
          }

          // content
          if ($m = $this->match('/^\s*(.+)$/')) {
            $this->try_append($node, new HamlText($m[1]));
            $node->close();
          }
        }
        else {
          $this->report('invalid haml');
        }
      }
//      echo "new level: ".$this->level."; diff: $diff\n";
//      echo "node: $node\n";

      if ($diff <= 0) {
        // first close previous tag
        $this->try_append($this->last(), $prev_node);

        if ($diff < 0) {
          // went up at least one level; start closing tags
          for ($i = -1; $i > $diff; $i--) {
            $child = array_pop($this->tree);
            $this->try_append($this->last(), $child);
          }
        }
      }
      else {
        // $diff == 1
        // push prev tag onto the tree
        array_push($this->tree, $prev_node);
      }

      if (feof($input)) {
        // all done!
        return $this->last()->__toString();
      }
    }
  }

  function get_level() {
    $m = $this->match("/^\s*/");
    $len = strlen($m[0]);
    if ($len % Haml::indent != 0)
      $this->report("invalid indention ($len)");

    $new_level = $len / Haml::indent;
    $diff = $new_level - $this->level;
    if ($diff > 1)
      $this->report("skipped too many levels (from ".$this->level." to $new_level)");

    $this->level = $new_level;
    return $diff;
  }

  function report($msg) {
    die("haml error on line " . $this->line_number . ": $msg\n");
  }

  // Match $this->line with pattern; chops off $this->line on success
  function match($pattern) {
//    echo "before: <".$this->line.">\n";
    $m = array();
    if (preg_match($pattern, $this->line, $m)) {
      $len = strlen($m[0]);
      $this->line = ($len < strlen($this->line)) ? substr($this->line, $len) : "";
//      echo "after: <".$this->line.">\n";
      return $m;
    }
//    echo "after: <".$this->line.">\n";
    return false;
  }

  function &last() {
    return $this->tree[count($this->tree)-1];
  }

  function try_append(&$node, &$content) {
    if (!$node->append($content))
      $this->report('tag already closed');
  }
}
?>
