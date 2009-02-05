<?php
class Haml {
  const indent = 2;   // 2 spaces per indent level
  static function indent($num) {
    return ($num > 0) ? str_repeat(" ", $num * Haml::indent) : '';
  }
}

abstract class HamlNode {
  var $closed;
  function __construct() {
    $this->children = array();
    $this->closed = false;
  }

  function append(&$child) {
    if ($this->closed)
      return false;

    array_push($this->children, &$child);
    return true;
  }

  function close() {
    $this->closed = true;
  }

  abstract function to_s($level);
}

class HamlRoot extends HamlNode {
  function __construct() {
    parent::__construct();
  }

  function to_s($level) {
    $retval = "";
    foreach($this->children as $child) {
      $retval .= $child->to_s(0) . "\n";
    }
    return $retval;
  }
}

class HamlTag extends HamlNode {
  var $name, $self_closed;

  function __construct($name, $attribs = array()) {
    parent::__construct();
    $this->name = $name;
    $this->attribs = $attribs;
    $this->self_closed = false;
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

  function html_attributes() {
    $retval = '';
    foreach ($this->attribs as $key => $val) {
      if (!$val) continue;
      $retval .= " $key=\"".htmlspecialchars($val, ENT_COMPAT, "ISO-8859-1", false)."\"";
    }
    return $retval;
  }

  function to_s($level) {
    $indent = Haml::indent($level);
    $retval = "$indent<".$this->name.$this->html_attributes();
    if ($this->self_closed) {
      $retval .= " />";
    }
    else {
      $retval .= ">\n";
      foreach($this->children as $child) {
        $retval .= $child->to_s($level+1) . "\n";
      }
      $retval .= "$indent</".$this->name.">";
    }
    return $retval;
  }
}

class HamlText extends HamlNode {
  var $text;
  function __construct($text) {
    parent::__construct();
    $this->text = $text;
  }

  function to_s($level) {
    return Haml::indent($level) . $this->text;
  }
}

class HamlPHP extends HamlText {
  function __construct($code) {
    eval("\$text = $code;");
    parent::__construct($text);
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
    $root = new HamlRoot();
    $prev_node =& $root;
    while (true) {
      $diff = null;

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
        if ($m = $this->match("/^=(.+)$/")) {
          $node =& new HamlPHP($m[1]);
        }
        elseif ($m = $this->match("/^([#.]|%)([\w-]+)/")) {
          // % - xhtml tag
          if ($m[1] == "#" || $m[1] == ".") {
            // handle implicit div
            $node =& new HamlTag('div');
            $node->set_id_and_class($m[0]);
          }
          else {
            $node =& new HamlTag($m[2]);
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

          if ($m = $this->match('/^=(.+)$/')) {
            // eval code and set as content
            $this->try_append($node, new HamlPHP($m[1]));
            $node->close();
          }
          elseif ($m = $this->match('/^\s*(.+)$/')) {
            // text
            $this->try_append($node, new HamlText($m[1]));
            $node->close();
          }
        }
        else {
          // text node
          $node =& new HamlText($this->line);
        }
      }
//      echo "new level: ".$this->level."; diff: $diff\n";
//      echo "node: $node\n";

      if ($diff == 0) {
        $this->try_append($this->last(), $prev_node);
      }
      elseif ($diff < 0) {
        // went up at least one level; start closing tags
        $this->try_append($this->last(), $prev_node);

        for ($i = -1; $i > $diff; $i--) {
          // append last element to next to last
          // NOTE: i can't just pop and append because of reference weirdness
          $from = count($this->tree) - 1;
          $to   = $from - 1;
          $this->try_append($this->tree[$to], $this->tree[$from]);
          array_pop($this->tree);
        }
      }
      else {
        // $diff == 1
        // push prev tag onto the tree
        array_push($this->tree, &$prev_node);
      }

      if (feof($input)) {
        // all done!
        return $this->last()->to_s(0);
      }

      $prev_node =& $node;
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
    $m = array();
    if (preg_match($pattern, $this->line, $m)) {
      $len = strlen($m[0]);
      $this->line = ($len < strlen($this->line)) ? substr($this->line, $len) : "";
      return $m;
    }
    return false;
  }

  function &last() {
    return $this->tree[count($this->tree)-1];
  }

  function try_append(&$parent, &$child) {
//    echo("=== parent ===\n");
//    var_dump($parent);
//    echo("=== child ===\n");
//    var_dump($child);
    if (!$parent->append($child))
      $this->report('tag already closed');
  }
}
?>
