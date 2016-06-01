<?php
namespace Peast\Syntax;

abstract class Parser
{
    protected $scanner;
    
    public function setScanner(Scanner $scanner)
    {
        $this->scanner = $scanner;
        return $this;
    }
    
    abstract public function parse();
    
    public function createNode($nodeType, $position)
    {
        $parts = explode("\\", get_class($this));
        array_pop($parts);
        $nodeClass = implode("\\", $parts) . "\\Node\\$nodeType";
        $node = new $nodeClass;
        if ($position instanceof Node) {
            $position = $position->getLocation()->getStart();
        } elseif (is_array($position)) {
            if (count($position)) {
                $position = $position[0]->getLocation()->getStart();
            } else {
                $position = $this->scanner->getPosition();
            }
        }
        return $node->setStartPosition($position);
    }
    
    public function completeNode(Node $node, $position = null)
    {
        return $node->setEndPosition(
            $position ? $position : $this->scanner->getPosition()
        );
    }
    
    public function error($message = "", $position = null)
    {
        if (!$position) {
            $position = $this->scanner->getPosition();
        }
        if (!$message) {
            $ws = $this->scanner->consumeWhitespacesAndComments();
            $token = $this->scanner->getToken();
            if ($token === null || $ws === null) {
                $message = "Unexpected end of input";
            } else {
                $message = "Unexpected token " . $token["source"];
            }
        }
        throw new Exception($message, $position);
    }
    
    protected function assertEndOfStatement()
    {
        $position = $this->scanner->getPosition();
        if ($this->scanner->consumeWhitespacesAndComments(false) === null) {
            $this->scanner->setPosition($position);
            return true;
        } else {
            if ($this->scanner->isEOF() ||
                $this->scanner->consume(";")) {
                return true;
            } elseif ($this->scanner->consume("}")) {
                $this->scanner->setPosition($position);
                return true;
            }
        }
        return $this->error();
    }
    
    protected function charSeparatedListOf($fn, $args = array(), $char = ",")
    {
        $multi = is_array($char);
        $list = array();
        $valid = true;
        $matchedChar = null;
        while ($param = call_user_func_array(array($this, $fn), $args)) {
            $list[] = $multi ? array($param, $matchedChar) : $param;
            $valid = true;
            $matchedChar = $multi ?
                           $this->scanner->consumeOneOf($char) :
                           $this->scanner->consume($char);
            if (!$matchedChar) {
                break;
            } else {
                $valid = false;
            }
        }
        if (!$valid) {
            $this->error();
            return null;
        }
        return $list;
    }
    
    protected function recursiveExpression($fn, $args, $operator, $class)
    {
        $multi = is_array($operator);
        $list = $this->charSeparatedListOf($fn, $args, $operator);
        
        if ($list === null) {
            return null;
        } elseif (count($list) === 1) {
            return $multi ? $list[0][0] : $list[0];
        } else {
            $lastNode = null;
            foreach ($list as $i => $expr) {
                if ($i) {
                    $left = $lastNode ?
                            $lastNode :
                            ($multi ? $list[0][0] : $list[0]);
                    $node = $this->createNode($class, $left);
                    $node->setLeft($left);
                    $node->setOperator($multi ? $expr[1] : $operator);
                    $node->setRight($multi ? $expr[0] : $expr);
                    $lastNode = $this->completeNode($node);
                }
            }
        }
        
        return $lastNode;
    }
    
    static public function unquoteLiteralString($str)
    {
        //Remove quotes
        $str = substr($str, 1, -1);
        
        $config = static::getConfig();
        $lineTerminators = $config->getLineTerminators();
        
        //Handle escapes
        $patterns = array(
            "u\{[a-fA-F0-9]+\}",
            "u[a-fA-F0-9]{1,4}",
            "x[a-fA-F0-9]{1,2}",
            "0[0-7]{2}",
            "[1-7][0-7]",
            "."
        );
        $reg = "/\\\\(" . implode("|", $patterns) . ")/s";
        $simpleSequence = array(
            "n" => "\n",
            "f" => "\f",
            "r" => "\r",
            "t" => "\t",
            "v" => "\v",
            "b" => "\x8"
        );
        $replacement = function ($m) use ($simpleSequence, $lineTerminators) {
            $type = $m[1][0];
            if (isset($simpleSequence[$type])) {
                // \n, \r, \t ...
                return $simpleSequence[$type];
            } elseif ($type === "u" || $type === "x") {
                // \uFFFF, \u{FFFF}, \xFF
                $code = substr($m[1], 1);
                $code = str_replace(array("{", "}"), "", $code);
                return Utils::unicodeToUtf8(hexdec($code));
            } elseif ($type >= "0" && $type <= "7") {
                // \123
                return Utils::unicodeToUtf8(octdec($m[1]));
            } elseif (in_array($m[1], $lineTerminators)) {
                // Escaped line terminators
                return "";
            } else {
                // Escaped characters
                return $m[1];
            }
        };
        $str = preg_replace_callback($reg, $replacement, $str);
        
        return $str;
    }
    
    static public function quoteLiteralString($str, $quote)
    {
        $config = static::getConfig();
        $escape = $config->getLineTerminators();
        $escape[] = $quote;
        $escape[] = "\\\\";
        $reg = "/(" . implode("|", $escape) . ")/";
        $str = preg_replace($reg, "\\$1", $str);
        return $quote . $str . $quote;
    }
}