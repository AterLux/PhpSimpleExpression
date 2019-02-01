<?php
/*************************************************************************************
 *
 *  SimpleExpression 1.1.0
 *
 *  Copyright (C) 2018 Dmitry Pogrebnyak 
 *  (https://aterlux.ru/ dmitry@aterlux.ru)
 *
 *  This file is part of SimpleExpression
 *
 *  SimpleExpression is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU Lesser General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  SimpleExpression is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU Lesser General Public License for more details.
 *
 *  You should have received a copy of the GNU Lesser General Public License
 *  along with SimpleExpression.  If not, see <https://www.gnu.org/licenses/>.
 *
 ***************************************************************************************/

/* What's new
 * ver 1.1.0:
 *   + added # (explicit string concatenation) operator.
 *   - implicit string concatenation (without operators) now disabled by default
 *     it can be enabled in the SimpleContext (see SimpleContext::implicitConcatenation() method)
 *   * conditional and boolean logic was changed. Now string '0' (also empty array) is considered as 'true'. NULL, false, 0, 0.0, '' are still 'false'
 *   * & (or), | (and) and ^^ (exclusive or) are not boolean operators anymore. Their result depend on the operands types:
 *       A | B  equals to  A ? A : B 
 *       A & B  equals to  A ? B : A
 *       A ^^ B equals to  !A ? B : (!B ? A : '')
 *   * engine now has OR-chain processor which is used when construction A | B | C ... is detected, it returns the first true operand, or the last one if not a such.
 *   + several new optimizations.
 *   - (X * 0) => 0 optimization was removed to handle right NAN and INF values of the X expression
 */

/**
 * Exception risen on parse error
 */ 
class SimpleExpressionParseError extends Exception {

  protected $position;

  public function __construct($position, $message) {
    parent::__construct($message);
    $this->position = $position;
  }

  public function __toString() {
    return (isset($this->position) && ($this->position !== false)) ? ('@' . $this->position . ': ' . $this->message) : $this->message;
  }

  public function getPosition() {
    return $this->position;
  }

}


/**
 * Tokenizer used by SimpleExpression
 */
class SimpleExpressionTokenizer {

  const EOL = 0;
  const OP = 1;
  const ID = 2;
  const NUM = 3;
  const STR = 4;

  protected $text;
  protected $pos;
  protected $tokenpos;

  public $type;
  protected $value;

  public function __construct($text) {
    if (is_string($text)) {
      $this->text = $text;
    } else {
      $this->text = $text . ''; // cast to string
    }

    $this->pos = 0;
  }

  /**
   * Retrives next token and returns it value
   */
  public function next() {
    $l = strlen($this->text);
    while (($this->pos < $l) && (ord($this->text[$this->pos]) <= 32)) $this->pos++;
    $this->tokenpos = $this->pos;
    if ($this->pos >= $l) {
      $this->type = self::EOL;
      $this->value = NULL;
      return $this->value;
    }

    if (!preg_match('#\G(?:(?P<op>[-+/%&|=\]()?:,\#]|\^\^?|\*\*?|<[=>]?|>=?|!=?)|(?P<num>[0-9]+(?P<num_frac>\.[0-9]*)?)|(?P<id>[A-Za-z_\x80-\xFF][A-Za-z0-9_\x80-\xFF]*)|(?P<str>"(?:[^"]|"")*|\'(?:[^\']|\'\')*))#s', $this->text, $res, 0, $this->pos)) {
      throw new SimpleExpressionParseError($this->pos, 'Unexpected symbol ' . $this->text[$this->pos]);
    }
    $this->pos += strlen($res[0]);
    if (isset($res['op']) && ($res['op'] !== '')) {
      $this->value = $res['op'];
      $this->type = self::OP;
    } else if (isset($res['id']) && ($res['id'] !== '')) {
      $this->value = $res['id'];
      $this->type = self::ID;
    } else if (isset($res['num']) && ($res['num'] !== '')) {
      $this->value = floatval($res['num']);
      if (empty($res['num_frac'])) {
        $iv = intval($this->value);
        if ($iv == $this->value) $this->value = $iv;
      }
      $this->type = self::NUM;
    } else if (isset($res['str']) && ($res['str'] !== '')) {

      $s = $res['str'];
      $quot = $s[0];
      if (($this->pos >= $l) || ($this->text[$this->pos] != $quot)) {
        throw new SimpleExpressionParseError($this->pos, 'Unterminated string constant');
      }
      $this->pos++;
      $this->value = str_replace($quot . $quot, $quot, substr($s, 1));
      $this->type = self::STR;
    }
    return $this->value;
  }

  /**
   * Returns part of the string from current until end of the string or symbol [ is met
   */
  public function getOpen() {
    $p = strpos($this->text, '[', $this->pos);
    if ($p === false) {
      $l = strlen($this->text);
      if ($this->pos >= $l) {
        $res = '';
      } else {
        $res = substr($this->text, $this->pos);
      }
      $this->pos = $l;
      $this->value = NULL;
      $this->type = self::EOL;
    } else {
      $res = substr($this->text, $this->pos, $p - $this->pos);
      $this->tokenpos = $p;
      $this->pos = $p + 1;
      $this->value = $this->text[$p];
      $this->type = self::OP;
    }
    return $res;
  }

  /**
   * is end of line reached
   */
  public function isEol() {
    return $this->type == self::EOL;
  }

  /**
   * is current token an operator/control symbol
   */
  public function isOp() {
    return $this->type == self::OP;
  }

  /**
   * is current token an identifier
   */
  public function isId() {
    return $this->type == self::ID;
  }

  /**
   * is current token a number
   */
  public function isNum() {
    return $this->type == self::NUM;
  }

  /**
   * is current token a string constant
   */
  public function isStr() {
    return $this->type == self::STR;
  }

  /**
   * returns parsed value of current token. NULL is end of line is reached
   */
  public function getValue() {
    return $this->value;
  }

  /**
   * Returns position where current token starts
   */
  public function getTokenPos() {
    return $this->tokenpos;
  }

  public function getTypeName() {
    switch ($this->type) {
      case self::EOL: return 'end of line';
      case self::ID: return 'identifier';
      case self::OP: return 'operator';
      case self::NUM: return 'numeric constant';
      case self::STR: return 'string constant';
    }
    return 'symbol';
  } 

  /**
   * Raises SimpleExpressionParseError with given text at current token position
   */
  public function parseError($text) {
    throw new SimpleExpressionParseError($this->tokenpos, $text);
  }
}


/**
 * SimpleExpression is a class for compiling and evaluating an expression
 */
class SimpleExpression {

  /**
   * $proc array contains operation processors. Each processor is a function of two arguments : ($data, $var)
   * $data - is a calculation node, array, where this function placed at the first places, other items - are arguments.
   * $var - array of varibles values for the current run.
   */
  protected static $proc;

  /**
   * This array describes which operation processor corresponds to a binary operation in parceable string
   * The key of each array item is one of the `OP`-type tokens returned by the SimpleExpressionTokenizer
   */
  protected static $binary_op_proc = array(
    '^^' => 'xor',
    '|' => 'or',
    '&' => 'and',
    '=' => 'eq', '!=' => 'neq', '<>' => 'neq', '>' => 'gt', '>=' => 'gte', '<' => 'lt', '<=' => 'lte',
    '#' => 'concat', // not a binary op, but is handled specially
    '+' => 'add', '-' => 'sub',
    '*' => 'mul', '/' => 'div', '%' => 'mod',
    '^' => 'pow', '**' => 'pow'
  );

  /**
   * This array defines operation priority. Operations with higher priority are calculated firstly
   * The key of each array item is one of the `OP`-type tokens returned by the SimpleExpressionTokenizer
   */
  protected static $binary_op_priority = array(
    '^^' => 1,
    '|' => 2,
    '&' => 3,
    '=' => 4, '!=' => 4, '<>' => 4, '>' => 4, '>=' => 4, '<' => 4, '<=' => 4,
    '#' => 5,
    '+' => 6, '-' => 6,
    '*' => 7, '/' => 7, '%' => 7,
    '^' => 8, '**' => 8
  );


  /**
   * The root node of parced expression
   * Each node is an array, where [0] element is a function of two parameters ($data, $var).
   * When function is called, the node array itself is passed as the first parameter, and array with varibles as the second.
   * Other array items are function parameters and their content depend on the function, but in the most cases they are
   * child nodes of the same structure, being called by the same rules
   */
  protected $runtree;
  protected $vars_used;

  /**
   * Fills the $proc static array (function reference cannot be passed in simple initialization)
   */
  protected static function initProc() {
    if (isset(self::$proc)) return;
    self::$proc  = array(
      // Unary operators
      'castbool'=> function($data, $vars) { return ($data[1][0]($data[1], $vars) != '') ? true : false;  }, 
      'castnum' => function($data, $vars) { return $data[1][0]($data[1], $vars) + 0; }, 
      'not'     => function($data, $vars) { return ($data[1][0]($data[1], $vars) == ''); },
      'neg'     => function($data, $vars) { return -($data[1][0]($data[1], $vars)); },

      // Math
      'add'     => function($data, $vars) { return ($data[1][0]($data[1], $vars)) + ($data[2][0]($data[2], $vars)); },
      'sub'     => function($data, $vars) { return ($data[1][0]($data[1], $vars)) - ($data[2][0]($data[2], $vars)); },
      'mul'     => function($data, $vars) { return ($data[1][0]($data[1], $vars)) * ($data[2][0]($data[2], $vars)); },
      'div'     => function($data, $vars) {
           $a = $data[1][0]($data[1], $vars);
           $b = $data[2][0]($data[2], $vars);
           if ($b == 0) {
             return (is_nan($a) || !is_numeric($a) || ($a == 0)) ? NAN : (($a < 0) ? -INF : INF);
           }
           return $a / $b;
         },
      'mod'     => function($data, $vars) {
           $a = $data[1][0]($data[1], $vars);
           $b = $data[2][0]($data[2], $vars);
           if ($b == 0) {
             return (is_nan($a) || !is_numeric($a) || ($a == 0)) ? NAN : 0;
           }
           return $a % $b;
         },
      'pow'     => function($data, $vars) { return pow($data[1][0]($data[1], $vars) , $data[2][0]($data[2], $vars)); },

      // Logic
      'and'     => function($data, $vars) { $a = $data[1][0]($data[1], $vars); if ($a == '') return $a; else return $data[2][0]($data[2], $vars); },
      'or'      => function($data, $vars) { $a = $data[1][0]($data[1], $vars); if ($a != '') return $a; else return $data[2][0]($data[2], $vars); },
      'xor'     => function($data, $vars) {   // a ^^ b == !a ? b : (!b ? a : '')
           $a = $data[1][0]($data[1], $vars); 
           $b = $data[2][0]($data[2], $vars);   
           if ($a == '') {                      
             return $b;
           } elseif ($b == '') {
             return $a;
           } else {
             return ''; 
           }
         },

      // Comparison
      'eq'      => function($data, $vars) { return ($data[1][0]($data[1], $vars)) == ($data[2][0]($data[2], $vars)); },
      'neq'     => function($data, $vars) { return ($data[1][0]($data[1], $vars)) != ($data[2][0]($data[2], $vars)); },
      'gt'      => function($data, $vars) { return ($data[1][0]($data[1], $vars)) > ($data[2][0]($data[2], $vars)); },
      'gte'     => function($data, $vars) { return ($data[1][0]($data[1], $vars)) >= ($data[2][0]($data[2], $vars)); },
      'lt'      => function($data, $vars) { return ($data[1][0]($data[1], $vars)) < ($data[2][0]($data[2], $vars)); },
      'lte'     => function($data, $vars) { return ($data[1][0]($data[1], $vars)) <= ($data[2][0]($data[2], $vars)); },

      // Ternary conditional
      'condition' => function($data, $vars) {
           if ($data[1][0]($data[1], $vars) != '') {
             return $data[2][0]($data[2], $vars);
           } else {
             return $data[3][0]($data[3], $vars);
           }
         },

      // Constant
      'constant' => function($data, $vars) { return $data[1]; },

      // Variable access: $data[1] - string with the variable name
      'var' => function($data, $vars) {
           $vn = $data[1];
           if (isset($vars[$vn])) return $vars[$vn];
           return NULL;
         },

      // Function call: $data[1] - ReflectionFunction object, $data[2+] - arguments
      'call' => function($data, $vars) {
           $args = array();
           for ($i = 2, $c = count($data) ; $i < $c ; $i++) {
             $args[] = $data[$i][0]($data[$i], $vars);
           }
           return $data[1]->invokeArgs($args);
         },

      // String concatenation
      'concat' => function ($data, $vars) {
           $s = '';
           for ($i = 1, $c = count($data) ; $i < $c ; $i++) {
             $s .= $data[$i][0]($data[$i], $vars);
           }
           return $s;
         },

      'orchain' => function($data, $vars) {
           $max = count($data) - 1;
           for ($i = 1 ; $i < $max ; $i++) {
             $v = $data[$i][0]($data[$i], $vars);
             if ($v != '') return $v;
           }
           return $data[$max][0]($data[$max], $vars);
         }

    );
  }

  /**
   * @param $node - node of the parsed expression tree
   * @return true if the node always returns a boolean value
   */
  protected static function isExplicitBoolean($node) {
    $proc = $node[0];
    if (($proc == self::$proc['castbool']) || ($proc == self::$proc['not']) ||
        ($proc == self::$proc['eq']) || ($proc == self::$proc['neq']) ||
        ($proc == self::$proc['gt']) || ($proc == self::$proc['lt']) ||
        ($proc == self::$proc['gte']) || ($proc == self::$proc['lte'])) {
      return true;
    }
    if (($proc == self::$proc['constant']) && is_bool($node[1])) {
      return true;
    }
    if ($proc == self::$proc['condition']) { // Condition, which returns only booleans is boolean too
      return self::isExplicitBoolean($node[2]) && self::isExplicitBoolean($node[3]);
    }
    if (($proc == self::$proc['or']) || ($proc == self::$proc['and'])) { // | and & operators, which returns only booleans are boolean too
      return self::isExplicitBoolean($node[1]) && self::isExplicitBoolean($node[2]);
    }
    if ($proc == self::$proc['orchain']) { // OR-chain made from booleans is boolean too
      $c = count($node);
      for ($i = 1 ; $i < $c ; $i++) {
        if (!self::isExplicitBoolean($node[$i])) return false;
      }
      return true;
    }
    return false;
  }

  /**
   * @param $node - node of the parsed expression tree
   * @return true if the node always returns a numeric value
   */
  protected static function isExplicitNumeric($node) {
    $proc = $node[0];
    if (($proc == self::$proc['add']) || ($proc == self::$proc['sub']) ||
        ($proc == self::$proc['mul']) || ($proc == self::$proc['div']) || 
        ($proc == self::$proc['mod']) || ($proc == self::$proc['pow']) ||
        ($proc == self::$proc['castnum']) || ($proc == self::$proc['neg'])) {
      return true;
    }
    if (($proc == self::$proc['constant']) && is_numeric($node[1]) && !is_string($node[1])) {
      return true;
    }
    if ($proc == self::$proc['condition']) { // Condition, which returns only numbers is numeric too
      return self::isExplicitNumeric($node[2]) && self::isExplicitNumeric($node[3]);
    }
    if (($proc == self::$proc['or']) || ($proc == self::$proc['and'])) { // | and & operators, which returns only numbers are numeric too
      return self::isExplicitNumeric($node[1]) && self::isExplicitNumeric($node[2]);
    }
    if ($proc == self::$proc['orchain']) { // OR-chain made from numerics is numerics too
      $c = count($node);
      for ($i = 1 ; $i < $c ; $i++) {
        if (!self::isExplicitNumeric($node[$i])) return false;
      }
      return true;
    }
    return false;
  }

  /**
   * @param $node - node of the parsed expression tree
   * @return true if the node always returns a string value
   */
  protected static function isExplicitString($node) {
    $proc = $node[0];
    if ($proc == self::$proc['concat']) {
      return true;
    }
    if (($proc == self::$proc['constant']) && is_string($node[1])) {
      return true;
    }
    if ($proc == self::$proc['condition']) { // Condition, which returns only strings is string too 
      return self::isExplicitString($node[2]) && self::isExplicitString($node[3]); 
    } 
    if (($proc == self::$proc['or']) || ($proc == self::$proc['and']) || ($proc == self::$proc['xor'])) {
      // |, & and ^^ operators, which returns only strings are string too  
      return self::isExplicitString($node[1]) && self::isExplicitString($node[2]);
   }
    return false;
  }


  /** 
   * Checks, if the node always returns a result, which is, when converted to boolean, evaluates to $result
   * @param $node - node of the parsed expression tree
   * @param $result expected boolean result;
   * @return true if condition is fulfiled
   */
  protected static function checkBoolResult($node, $result) {
    $proc = $node[0];
    if ($proc == self::$proc['constant']) { // Check constant
      if ($result) {
        return $node[1] != '';
      } else {
        return $node[1] == '';
      }
    }
    // Check condition results
    if ($proc == self::$proc['condition']) {
      return self::checkBoolResult($node[2], $result) && self::checkBoolResult($node[2], $result);
    }
    // If any node of concat always gives non-empty string (which is equals to the true boolean result) then the whole concat is true
    if ($proc == self::$proc['concat']) {
      if ($result) {
        for ($i = 1, $c = count($node) ; $i < $c ; $i++) {
          if (self::checkBoolResult($node[$i], true)) return true;
        }
      }
      return false;
    }
    // If the latest node of the OR-chain gives always true then the whole chain is true;
    if ($proc == self::$proc['orchain']) {
      if ($result) {
        return self::checkBoolResult($node[count($node) - 1], true);
      }
      return false;
    }
    // X | true => always true (true | X is processed by the node optimization)
    if ($proc == self::$proc['or']) {
      if ($result) {
        return self::checkBoolResult($node[2], true);
      }
      return false;
    }
    // X & false => always false (false & X is processed by the node optimization)
    if ($proc == self::$proc['and']) {
      if (!$result) {
        return self::checkBoolResult($node[2], false);
      }
      return false;
    }
    // exclusive or 
    if ($proc == self::$proc['xor']) {
      return (self::checkBoolResult($node[1], false) && self::checkBoolResult($node[2], $result)) ||
             (self::checkBoolResult($node[1], true) && self::checkBoolResult($node[2], !$result));
    }

    return false;
  }
  /**
   * Performs some optimizations on unary-operation nodes. Modifies $node, if neccessary
   * @param $node - node of the parsed expression tree
   */
  protected static function simplifyUnary(&$node) {
    $constant_proc = self::$proc['constant'];
    $proc = $node[0];
    // Calculate constant expression just in time
    if ($node[1][0] == $constant_proc) {
      $res = $proc($node, false);
      $node = array($constant_proc, $res);
      return;
    }
    if ($proc == self::$proc['castnum']) {
      if (self::isExplicitNumeric($node[1])) { // Remove cast of operation that explicitly returns a number
        $node = $node[1];
        return;
      }
    }
    if ($proc == self::$proc['castbool']) {
      if (self::checkBoolResult($node[1], true)) {  // If the node gives always true or false boolean, then replace it by a constant
        $node = array($constant_proc, true);
        return;
      } elseif (self::checkBoolResult($node[1], false)) {
        $node = array($constant_proc, false);
        return;
      } elseif (self::isExplicitBoolean($node[1])) { // Remove cast of operation that explicitly returns a boolean
        $node = $node[1];
        return;
      }
    }
    if ($proc == self::$proc['not']) {
      $rpr = $node[1][0];
      if ($rpr == self::$proc['not']) { 
        $node = array(self::$proc['castbool'], $node[1][1]);
        self::simplifyUnary($node);
        return;
      } elseif (self::checkBoolResult($node[1], true)) {  // If the node gives always true or false boolean, then replace it by a constant
        $node = array($constant_proc, false);
        return;
      } elseif (self::checkBoolResult($node[1], false)) {
        $node = array($constant_proc, true);
        return;
      } elseif (($rpr == self::$proc['eq']) || ($rpr == self::$proc['neq']) || ($rpr == self::$proc['gt']) ||
                ($rpr == self::$proc['gte']) || ($rpr == self::$proc['lt']) || ($rpr == self::$proc['lte'])) {
        if ($rpr == self::$proc['eq']) $rpr = self::$proc['neq'];
        elseif ($rpr == self::$proc['neq']) $rpr = self::$proc['eq'];
        elseif ($rpr == self::$proc['lt']) $rpr = self::$proc['gte'];
        elseif ($rpr == self::$proc['lte']) $rpr = self::$proc['gt'];
        elseif ($rpr == self::$proc['gt']) $rpr = self::$proc['lte'];
        elseif ($rpr == self::$proc['gte']) $rpr = self::$proc['lt'];
        $node = $node[1];
        $node[0] = $rpr;
        self::simplifyBinary($node);
        return;
      } elseif ($rpr == self::$proc['castbool']) { // Not implicitly converts operand to boolean
        $node[1] = $node[1][1];
        self::simplifyUnary($node);
        return;
      }
    }
    if ($proc == self::$proc['neg']) {
      $rpr = $node[1][0];
      if ($rpr == self::$proc['neg']) { 
        $node = array(self::$proc['castnum'], $node[1][1]);
        self::simplifyUnary($node);
        return;
      } elseif ($rpr == self::$proc['castnum']) { // Neg implicitly converts operand to number
        $node[1] = $node[1][1];
        self::simplifyUnary($node);
        return;
      }
    }
  }

  /**
   * Performs some optimizations on concat node. Modifies $node, if neccessary
   * @param $node - node of the parsed expression tree
   */
  protected static function simplifyConcat(&$node) {
    $constant_proc = self::$proc['constant'];

    $proc = $node[0];
    if ($proc == self::$proc['concat']) {
      $c = count($node); 
      $all_const = true;
      for ($i = 1 ; $i < $c ; $i++) {
        if ($node[$i][0] != $constant_proc) {
          $all_const = false;
          break;
        }
      }
      if ($all_const) { // If concat only constants
        $res = $proc($node, false); // Turn into a constant
        $node = array($constant_proc, $res);
        return;
      }
      $need_reorganize = false;
      $prev_const = 0;
      for ($i = 1 ; $i < $c ; $i++) {
        if (($node[$i][0] == $proc) ||  // If one of the nodes is also a concat operation
            (($node[$i][0] == $constant_proc) && // Or empty string constant, or successive constants in a row
             (($node[$i][1] === '') || (($i > 1) && ($node[$i - 1][0] == $constant_proc))))) {
          $need_reorganize = true; // Then reorganize
          break;
        }
      }
      if ($need_reorganize) {
        $new_node = array(0 => $proc);
        $outc = 0;
        for ($i = 1 ; $i < $c ; $i++) {
          if ($node[$i][0] == $constant_proc) { // If a constant
            if ($node[$i][1] !== '') {  // if not an empty string
              if (($outc > 0) && ($new_node[$outc][0] == $constant_proc)) { // If previous also a constant
                $new_node[$outc][1] .= $node[$i][1];  // Concat together
              } else {
                $new_node[++$outc] = $node[$i];
              }
            }
          } else if ($node[$i][0] == $proc) { // If also a concat
            $nconc = $node[$i];
            $c2 = count($nconc);
            for ($j = 1 ; $j < $c ; $j++) {
              if ($nconc[$j][0] == $constant_proc) { // If a constant
                if ($nconc[$j][1] !== '') {  // if not an empty string
                  if (($outc > 0) && ($new_node[$outc][0] == $constant_proc)) { // If previous also a constant
                    $new_node[$outc][1] .= $nconc[$j][1];  // Concat them together
                  } else {
                    $new_node[++$outc] = $nconc[$j];
                  }
                }
              } else {
                $new_node[++$outc] = $nconc[$j];
              }
            }
          } else {
            $new_node[++$outc] = $node[$i];
          }
        }
        // Concat of one element is a conversion to a string. If that element already is a string, then just use it
        if (($outc == 1) && self::isExplicitString($new_node[1])) {
          $node = $new_node[1];
        } else {
          $node = $new_node;
        }
      }
    }
  }

  /**
   * Performs some optimizations on an or-chain node. Modifies $node, if neccessary
   * @param $node - node of the parsed expression tree
   */
  protected static function simplifyOrChain(&$node) {
    $constant_proc = self::$proc['constant'];

    $c = count($node);
    if ($c == 2) {
      $node = $node[1];
      return;
    } elseif ($c == 3) {
      $node[0] = self::$proc['or'];
      return;
    }
    $reorganize = false;
    for ($i = 1 ; $i < $c ; $i++) {
      $n = $node[$i];
      if (($n[0] == self::$proc['or']) || ($n[0] == self::$proc['orchain']) ||
          (($n[0] == $constant_proc) && $i != ($c - 1))) {
        $reorganize = true;
        break;
      } 
    }
    if (!$reorganize) return;
    $a = array(self::$proc['orchain']);
    $extend_const = NULL;
    for ($i = 1 ; $i < $c ; $i++) {
      $n = $node[$i];
      if ($n[0] == $constant_proc) {
        $extend_const = $n;
        if ($n[1] != '') break;
      } else {
        $extend_const = NULL;
        if ($n[0] == self::$proc['or']) {
          $a[] = $n[1];
          if ($n[2][0] == $constant_proc) {
            $extend_const = $n[2];
            if ($extend_const[1] != '') break;
          } else {
            $a[] = $n[2];
          }
        } elseif ($n[0] == self::$proc['orchain']) {
          $cc = count($n);
          for ($j = 1; $j < $cc ; $j++) {
            $nc = $n[$j];
            if ($nc[0] == $constant_proc) {
              $extend_const = $nc;
              if ($nc[1] != '') break;
            } else {
              $a[] = $nc;
            }
          }
          if (isset($extend_const) && ($extend_const[1] != '')) break;
        } else {
          $a[] = $n;
        }
      }
    }
    if (isset($extend_const)) $a[] = $extend_const;
    if (count($a) == 2) {
      $node = $a[1];
      return;
    }
    if (count($a) == 3) {
      $a[0] = self::$proc['or'];
    }
    $node = $a;
  }

  /**
   * Performs some optimizations on arithmetical, logical operations, comparisons etc. I.e. operation of two arguments.
   * Modifies $node, if neccessary
   * @param $node - node of the parsed expression tree
   */
  protected static function simplifyBinary(&$node) {
    $constant_proc = self::$proc['constant'];
    $proc = $node[0];
    if (self::isExplicitNumeric($node)) {
      // Math operations are implicitly converting their's operands to numbers, so, additional number cast is not neccessary
      if ($node[1][0] == self::$proc['castnum']) $node[1] = $node[1][1];
      if ($node[2][0] == self::$proc['castnum']) $node[2] = $node[2][1];
    }

    if ($proc == self::$proc['or']) {
      if ($node[1][0] == self::$proc['or']) {
        $node = array(self::$proc['orchain'], $node[1][1], $node[1][2], $node[2]);
        self::simplifyOrChain($node);
        return;
      } elseif ($node[1][0] == self::$proc['orchain']) {
        $node[1][count($node[1])] = $node[2];
        $node = $node[1];
        self::simplifyOrChain($node);
        return;
      } elseif ($node[2][0] == self::$proc['or']) {
        $node = array(self::$proc['orchain'], $node[1], $node[2][1], $node[2][2]);
        self::simplifyOrChain($node);
        return;
      } elseif ($node[2][0] == self::$proc['orchain']) {
        $chain = array(self::$proc['orchain'], $node[1]);
        $c = count($node[2]);
        for ($i = 1 ; $i < $c ; $i++) {
          $a[] = $node[2][$i];
        }
        $node = $chain;
        self::simplifyOrChain($node);
        return;
      } elseif (self::checkBoolResult($node[1], false)) { // if the left node always gives false, then return only the right node
        $node = $node[2];
        return;
      }
    } elseif ($proc == self::$proc['and']) {
      if (self::checkBoolResult($node[1], true)) { // if the left node always gives true, then return only the right node
        $node = $node[2];
        return;
      }
    } elseif ($proc == self::$proc['xor']) {
      if (self::checkBoolResult($node[1], true) && self::checkBoolResult($node[2], true)) { // if both nodes give true then empty string is returned
        $node = array($constant_proc, '');
        return;
      }
    }
    // If left operand is a constant
    if ($node[1][0] == $constant_proc) {
      // If both operands are constants, then just perfom the expression to calculate the result as a new constant
      if ($node[2][0] == $constant_proc) { 
        $res = $proc($node, false);
        $node = array($constant_proc, $res);
        return;
      }

      $lval = $node[1][1];
      if ($proc == self::$proc['and']) { 
        if ($lval != '') { // (true & X) => (bool)X;  
          $node = $node[2];
        } else {  // (false & anything) => false;
          $node = $node[1];
        }
        return;
      } elseif ($proc == self::$proc['or']) { 
        if ($lval != '') {  // (true | anything) => true;
          $node = $node[1];
        } else { // (false | X) => (bool)X;
          $node = $node[2];
        }
        return;
      } elseif ($proc == self::$proc['xor']) { 
        // (false ^^ X) => X
        if ($lval == '') {  
          $node = $node[2];
        }
        return;
      } elseif (($proc == self::$proc['add']) || ($proc == self::$proc['sub'])) {
        if ($lval == 0) {  // (0 + X) -> (num)(X) ; (0 - X) -> (-X)
          $node = array(($proc == self::$proc['add']) ? self::$proc['castnum'] : self::$proc['neg'], $node[2]);
          self::simplifyUnary($node);
          return;
        }
        $rnode = $node[2];
        if ((($rnode[0] == self::$proc['add']) || ($rnode[0] == self::$proc['sub'])) && ($rnode[1][0] == $constant_proc)) {
          // const1 +- (const2 +- X) =>  (const1 +- const2) +- X
          $rnode[0] = ($rnode[0] == $proc) ? self::$proc['add'] : self::$proc['sub'];
          if ($proc == self::$proc['add']) {
            $rnode[1][1] += $lval;
          } else {
            $rnode[1][1] = $lval - $rnode[1][1];
          }
          $node = $rnode;
          self::simplifyBinary($node);
          return;
        }
      } elseif ($proc == self::$proc['mul'])  {
        if ($lval == 1) {  // (1 * X) -> (num)(X)
          $node = array(self::$proc['castnum'], $node[2]);
          self::simplifyUnary($node);
          return;
        } // (0 * X) => 0 optimization was removed to keep handling right NAN and INF numbers
        $rnode = $node[2];
        if ((($rnode[0] == self::$proc['mul']) || ($rnode[0] == self::$proc['div'])) && ($rnode[1][0] == $constant_proc)) {
          // const1 * (const2 */ X) =>  (const1 * const2) */ X
          $rnode[1][1] *= $lval;
          $node = $rnode;
          self::simplifyBinary($node);
          return;
        }
      } elseif (($proc == self::$proc['eq']) || ($proc == self::$proc['neq'])) {
        if ($lval === '') {   // '' = X  => !X    '' <> X => (bool)X
          $node = array(($proc == self::$proc['eq']) ? self::$proc['not'] : self::$proc['castbool'], $node[2]);
          self::simplifyUnary($node);
          return;
        }
      } 
    }     
    // If right operand is a constant
    if ($node[2][0] == $constant_proc) {
      $rval = $node[2][1];

      if ($proc == self::$proc['xor']) { 
        // (X ^^ false) => X;
        if ($rval == '') $node = $node[1];
        return;
      } elseif (($proc == self::$proc['add']) || ($proc == self::$proc['sub'])) {
        $lnode = $node[1];
        if ($rval == 0) { // (X +- 0) => (num)X
          $node = array(self::$proc['castnum'], $lnode);
          self::simplifyUnary($node);
          return;
        } else if ((($lnode[0] == self::$proc['add']) || ($lnode[0] == self::$proc['sub'])) && ($lnode[2][0] == $constant_proc)) {
          // ((X +- const1) +- const2) => X +- (const1 +- const2)
          if ($lnode[0] == $proc) {
            $lnode[2][1] += $rval;
          } else {
            $lnode[2][1] -= $rval;
          }
          $node = $lnode;
          self::simplifyBinary($node);
          return;
        }
      } elseif (($proc == self::$proc['mul']) || ($proc == self::$proc['div'])) {
        if ($rval == 1) { // (X */ 1) => (num)X
          $node = array(self::$proc['castnum'], $node[1]);
          self::simplifyUnary($node);
          return;
        } // (X * 0) => 0 optimization was removed to keep handling right NAN and INF numbers
        $lnode = $node[1];
        // ((X */ const1) */ const2) => X */ (const1 */ const2)
        if ((($lnode[0] == self::$proc['mul']) || ($lnode[0] == self::$proc['div'])) && ($lnode[2][0] == $constant_proc)) {
          if ($lnode[0] == $proc) {
            $lnode[2][1] *= $rval;
          } else {
            if ($lnode[0] == self::$proc['div']) { // ( X / const1) * const2 => X * (const2 / const1); (not X / (const1 / const2) for the case const2 == 0)
              $a = $rval;
              $rval = $lnode[2][1];
              $lnode[0] = self::$proc['mul'];
            } else {
              $a = $lnode[2][1];
            }
            if ($rval == 0) {
              $lnode[2][1] = (is_nan($a) || !is_numeric($a) || ($a == 0)) ? NAN : (($a < 0) ? -INF : INF);
            } else {
              $lnode[2][1] = $a / $rval;
            }
          }
          $node = $lnode;
          self::simplifyBinary($node);
          return;
        }
      } elseif ($proc == self::$proc['pow']) {
        if ($rval == 1) { // (X ^ 1) = > (num)X
          $node = array(self::$proc['castnum'], $node[1]);
          self::simplifyUnary($node);
          return;
        } elseif ($rval == -1) { // (X ^ -1) = > (1 / X)
          $node = array(self::$proc['div'], array($constant_proc, 1), $node[1]);
          self::simplifyBinary($node);
          return;
        } 
      } elseif (($proc == self::$proc['eq']) || ($proc == self::$proc['neq'])) {
        if ($rval === '') {  // X = ''  => !X    X <> '' => (bool)X
          $node = array(($proc == self::$proc['eq']) ? self::$proc['not'] : self::$proc['castbool'], $node[1]);
          self::simplifyUnary($node);
          return;
        }
      }
    }
    // If not constants
    if ((($proc == self::$proc['add']) || ($proc == self::$proc['sub'])) && ($node[2][0] == self::$proc['neg'])) { // X +- (-Y) =>  X -+ Y
      $proc = ($proc == self::$proc['add']) ? self::$proc['sub'] : self::$proc['add'];
      $node[0] = $proc;
      $node[2] = $node[2][1];
      self::simplifyBinary($node);
      return;
    }
    if (($proc == self::$proc['add']) && ($node[1] === $node[2])) {
      $node[0] = self::$proc['mul'];
      $node[2] = array($constant_proc, 2);
      self::simplifyBinary($node);
      return;
    }
  }


  /**
   * Performs some optimizations on ternary conditional operator.
   * Modifies $node, if neccessary
   * @param $node - node of the parsed expression tree
   */
  protected static function simplifyTernary(&$node) {
    $constant_proc = self::$proc['constant'];
    $proc = $node[0];
    if ($proc == self::$proc['condition']) {
      if (self::checkBoolResult($node[1], true)) { // if expression always returns true, then just take the "if true" node
        $node = $node[2];
        return;
      } elseif (self::checkBoolResult($node[1], false)) { // the same for false
        $node = $node[3];
        return;
      }
      if ($node[2] === $node[3]) { // If result the same whatever happened...
        $node = $node[2];
        return;
      }
      if ($node[1][0] == self::$proc['castbool']) { // Ternary operator implicitly converts condition into boolean, additional cast is not neccessary
        $node[1] = $node[1][1];
      }
      if ($node[1][0] == self::$proc['not']) { // Instead of performing boolean inversion, just invert the condition
        $node[1] = $node[1][1];
        $t = $node[2];
        $node[2] = $node[3];
        $node[3] = $t;
      }
      if ($node[1] === $node[2]) { // A ? A : B =>  A | B
        $node = array(self::$proc['or'], $node[1], $node[3]);
        self::simplifyBinary($node);
        return;
      }
      if ($node[1] === $node[3]) { // A ? B : A =>  A & B
        $node = array(self::$proc['and'], $node[1], $node[2]);
        self::simplifyBinary($node);
        return;
      }

    }
  }


  /**
   * Parses the value source (constant, function, variable, parenthesis around expression, unary operation on another value etc.)
   * from the input string.
   * @param $tokenizer instance of SimpleExpressionTokenizer
   * @param $node node of the parsed expression tree
   * @return array, representing the parsed node
   * @throw SimpleExpressionParseError on parse error
   */
  protected function parseValue($tokenizer, $context) {
    $constant_proc = self::$proc['constant'];
    $val = $tokenizer->getValue();

    if ($tokenizer->isId()) {

      $func = $context ? $context->findFunction($val) : false;
      if (is_array($func)) {
        $next = $tokenizer->next();
        if ($next != '(') {
          $tokenizer->parseError('Parenthesis expected after the funtion name');
        }

        $reffn = $func['function'];
        $node = array(self::$proc['call'], $reffn);
        $all_const = true;
        do {
          if ($tokenizer->next() === ')') break;
          $expr = $this->parseExpression($tokenizer, $context, 0);
          $node[] = $expr;
          if ($expr[0] != $constant_proc) $all_const = false;
        } while ($tokenizer->getValue() === ',');
        if ($tokenizer->getValue() != ')') {
          $tokenizer->parseError('Expected closing parenthesis or comma');
        }
        $tokenizer->next();
        $num_params = (count($node) - 2);
        if ($num_params < $reffn->getNumberOfRequiredParameters()) {
          $tokenizer->parseError('Too few arguments for function ' . $func['alias'] . ': ' . $num_params . ' provided; at least ' . $reffn->getNumberOfRequiredParameters() . ' expected');
        }
        if (($num_params > $reffn->getNumberOfParameters())) {
          if (PHP_VERSION_ID >= 50600) {
            $multiarg = $reffn->isVariadic();
          } else if ($reffn->isInternal()) {
            $par = $reffn->getParameters();
            $multiarg = $par[$reffn->getNumberOfParameters() - 1]->getName() == '...';
          }
          if (!$multiarg) {
            $tokenizer->parseError('Too many arguments for function ' . $func['alias'] . ': ' . $num_params . ' provided; at most ' . $func['function']->getNumberOfParameters() . ' expected');
          }
        }
        if ($all_const && empty($func['volatile'])) {
          $v = $node[0]($node, false);
          $node = array($constant_proc, $v);
        }
      } else {
        $c = $context ? $context->findConstant($val) : NULL;
        if ($c !== NULL) {
          $node = array($constant_proc, $c);
        } else {
          $vn = strtolower($val);
          if (!isset($this->vars_used[$vn])) {
            $this->vars_used[$vn] = $tokenizer->getTokenPos();
          }
          $node = array(self::$proc['var'], $vn);
        }
        $tokenizer->next();
      }
    } elseif ($tokenizer->isNum() || $tokenizer->isStr()) {
      $node = array($constant_proc, $val);
      $tokenizer->next();
    } elseif (($val === '-') || ($val === '+') || ($val === '!')) {
      $tokenizer->next();
      $n = $this->parseValue($tokenizer, $context);
      if ($n === false) {
        $tokenizer->parseError('Expected value expression after unary operator ' . $val);
      }
      if ($val === '-') $func = self::$proc['neg'];
      elseif ($val === '!') $func = self::$proc['not'];
      else $func  = self::$proc['castnum'];
      $node = array($func, $n);
      self::simplifyUnary($node);
    } elseif ($val === '(') {
      $tokenizer->next();
      $node = $this->parseExpression($tokenizer, $context, 0);
      if ($tokenizer->getValue() != ')') {
        $tokenizer->parseError('Expected closing parenthesis');
      }
      $tokenizer->next();
    } else { 
      $node = false;
    }
    return $node;
  }

  /**
   * Parses the expression from the input string.
   * @param $tokenizer instance of SimpleExpressionTokenizer
   * @param $node node of the parsed expression tree
   * @return array, representing the parsed node
   * @throw SimpleExpressionParseError on parse error
   */
  protected function parseExpression($tokenizer, $context, $min_priority = 0) {
    $constant_proc = self::$proc['constant'];
    $node = $this->parseValue($tokenizer, $context);
    if ($node === false) {
      $tokenizer->parseError('Expected expression');
    }
    while (!$tokenizer->isEol()) {
      $val = $tokenizer->getValue();
      if (isset(self::$binary_op_priority[$val])) { // binary operation
        $pr = self::$binary_op_priority[$val];
        if ($pr < $min_priority) {
          return $node;
        }
        $tokenizer->next();
        $rnode = $this->parseExpression($tokenizer, $context, $pr + 1);
        if ($rnode === false) {
          $tokenizer->parseError('Expected expression at the right side of ' . $val . ' operator');
        }
        $proc_name = self::$binary_op_proc[$val];
        if ($proc_name == 'concat') {
          if ($node[0] == self::$proc['concat']) {
            $node[] = $rnode;
          } else {
            $node = array(self::$proc['concat'], $node, $rnode);
          }
          self::simplifyConcat($node);
        } else {
          $node = array(self::$proc[$proc_name], $node, $rnode);
          self::simplifyBinary($node);
        }
      } elseif ($val === '?') { // Ternary conditional operator
        if ($min_priority > 0) { // Exit from nested;
          return $node;
        }
        $tokenizer->next();
        $iftrue = self::parseExpression($tokenizer, $context, 0);
        if ($tokenizer->getValue() === ':') {
          $tokenizer->next();
          $iffalse = self::parseExpression($tokenizer, $context, 0);
        } else {
          $iffalse = array($constant_proc, '');
        }
        $node = array(self::$proc['condition'], $node, $iftrue, $iffalse);
        self::simplifyTernary($node);
        break;
      } else { // Trying to parse a value to make a string concatenation
        if ($context && !$context->implicitConcatenation()) {
          return $node; // if option is disabled, just return
        }
        $rval = $this->parseValue($tokenizer, $context);
        if ($rval === false) { // Not a value;
          return $node;
        }
        if ($node[0] == self::$proc['concat']) {
          $node[] = $rval;
        } else {
          $node = array(self::$proc['concat'], $node, $rval);
        }
        self::simplifyConcat($node);
      }
    }
    return $node;
    
  }


  /**
   * Creates new instance, parsing the expression string.
   * Generates E_USER_WARNING, is `$context` is not instance of SimpleContext
   * @param $expression the string with expression.
   * @param $context instance of `SimpleContext` - the context of compilation. May be `true` to use default context, or `false` to compile without context
   * @param $enclosed if `false` (default), then `$expression` is treated as a single expression; 
   * if `true`, then `$expression` is treated as a text string with multiple inlined expressions, each enclosed in a pair of square brackets.
   * @throw SimpleExpressionParseError on parse error
   */
  public function __construct($expression, $context = true, $enclosed = false)  {
    self::initProc();

    if ($context === true) {
      $context = SimpleContext::getDefaultContext();
    } elseif (empty($context)) {
      $context = NULL;
    } else if (!($context instanceof SimpleContext)) {
      trigger_error('$context should be a SimpleContext instance', E_USER_WARNING);
      $context = NULL;
    }
    $this->vars_used = array();

    $tok = new SimpleExpressionTokenizer($expression);

    if ($enclosed) {
      $node = array(self::$proc['concat']);
      for(;;) {
        $s = $tok->getOpen();
        if ($s !== '') $node[] = array(self::$proc['constant'], $s);
        if ($tok->isEol()) break;
        $tok->next();
        $n = $this->parseExpression($tok, $context);
        $node[] = $n;
        if ($tok->getValue() != ']') $tok->parseError('Expected closing square bracket');
      }
      self::simplifyConcat($node);
      $this->runtree = $node;
    } elseif ($tok->next() === NULL) {
      $this->runtree = array(self::$proc['constant'], '');
    } else {

      $this->runtree = $this->parseExpression($tok, $context);
      if (!$tok->isEol()) {
        $tok->parseError('Unexpected ' . $tok->getTypeName() . ': ' . $tok->getValue());
      }
    }

  }

  /**
   * Evaluates the compiled expression
   * @param $variables array with values of variables to use. Keys of the array contain variable names in lower case.
   * If parameter is omited, or array have no such a key, then NULL value is assumed without generating any warning messages.
   * @return result of expression evaluation
   */
  public function run($variables = NULL) {
    return $this->runtree[0]($this->runtree, $variables);
  }

  /**
   * Looks for the processing function key name by it's enclosure
   */
  private static function _find_proc_key($func) {
    foreach(self::$proc as $key => $f) {
      if ($f == $func) return $key;
    }
    return false;
  }

  /**
   * Dumps one node
   */
  private static function _debug_dump_node($node) {
    $nm = self::_find_proc_key($node[0]);
    switch ($nm) {
      case 'constant': 
        if (is_null($node[1])) {
          return '(const NULL)';
        } elseif (is_string($node[1])) {
          return '"' . str_replace('"', '""', $node[1]) . '"';
        } elseif (is_numeric($node[1])) {
          return '(const ' . $node[1] . ')';
        } elseif (is_bool($node[1])) {
          return '(const ' . ($node[1] ?  'TRUE' : 'FALSE') . ')';
        } else {
          return '(const ' . gettype($node[1]) . ' ' . $node[1] . ')';
        }
      case 'castbool': return '(BOOL)' . self::_debug_dump_node($node[1]);
      case 'castnum': return '(NUM)' . self::_debug_dump_node($node[1]);
      case 'caststr': return '(STR)' . self::_debug_dump_node($node[1]);

      case 'not': return '!' . self::_debug_dump_node($node[1]);
      case 'neg': return '-' . self::_debug_dump_node($node[1]);

      case 'add': return '(' . self::_debug_dump_node($node[1]) . ' + ' . self::_debug_dump_node($node[2]) . ')';
      case 'sub': return '(' . self::_debug_dump_node($node[1]) . ' - ' . self::_debug_dump_node($node[2]) . ')';
      case 'mul': return '(' . self::_debug_dump_node($node[1]) . ' * ' . self::_debug_dump_node($node[2]) . ')';
      case 'div': return '(' . self::_debug_dump_node($node[1]) . ' / ' . self::_debug_dump_node($node[2]) . ')';
      case 'mod': return '(' . self::_debug_dump_node($node[1]) . ' % ' . self::_debug_dump_node($node[2]) . ')';
      case 'pow': return '(' . self::_debug_dump_node($node[1]) . ' ^ ' . self::_debug_dump_node($node[2]) . ')';

      case 'and': return '(' . self::_debug_dump_node($node[1]) . ' & ' . self::_debug_dump_node($node[2]) . ')';
      case 'or': return '(' . self::_debug_dump_node($node[1]) . ' | ' . self::_debug_dump_node($node[2]) . ')';
      case 'xor': return '(' . self::_debug_dump_node($node[1]) . ' ^^ ' . self::_debug_dump_node($node[2]) . ')';

      case 'condition': return '(' . self::_debug_dump_node($node[1]) . ' ? ' . self::_debug_dump_node($node[2]) . ' : ' . self::_debug_dump_node($node[3]) . ')';

      case 'eq': return '(' . self::_debug_dump_node($node[1]) . ' = ' . self::_debug_dump_node($node[2]) . ')';
      case 'neq': return '(' . self::_debug_dump_node($node[1]) . ' != ' . self::_debug_dump_node($node[2]) . ')';
      case 'gt': return '(' . self::_debug_dump_node($node[1]) . ' > ' . self::_debug_dump_node($node[2]) . ')';
      case 'gte': return '(' . self::_debug_dump_node($node[1]) . ' >= ' . self::_debug_dump_node($node[2]) . ')';
      case 'lt': return '(' . self::_debug_dump_node($node[1]) . ' < ' . self::_debug_dump_node($node[2]) . ')';
      case 'lte': return '(' . self::_debug_dump_node($node[1]) . ' <= ' . self::_debug_dump_node($node[2]) . ')';
      case 'var' : return '{' . $node[1] . '}';

      case 'call': 
        if ($node[1]->isClosure()) {
          $s = '@' . basename($node[1]->getFileName()) . ':' . $node[1]->getStartLine() . '(';
        } else {
          $s = '@' . $node[1]->name . '(';
        }
        $c = count($node);
        for ($i = 2 ; $i < $c ; $i++) {
          if ($i > 2) $s .= ', ';
          $s .= self::_debug_dump_node($node[$i]);
        }
        return $s . ')';
      default:
        if ($nm == '') $nm = 'UNKNOWN!';
        $s = strtoupper($nm) . '(';
        $c = count($node);
        for ($i = 1 ; $i < $c ; $i++) {
          if ($i > 1) $s .= ', ';
          $p = $node[$i];
          if (is_array($p) && (count($p) > 0) && ($p[0] instanceof Closure)) {
            $s .= self::_debug_dump_node($p);
          } else {
            $s .= $p;
          }
        }
        return $s . ')';
    }
  }

  /**
   * @return a string representation of a comipled expression tree
   * For debug purposes only.
   */
  public function debugDump() {
    return self::_debug_dump_node($this->runtree);
  }

  /**
   * Retrives all variables declared in the expression
   * @return array where  keys represent the variable names, and values store position where this variable appears for the first time in the expression
   */
  public function getVars() {
    return $this->vars_used;
  }

  /**
   * Performs check against variables` names
   * @param $vars array, which keys represents all allowed variable names.
   * @throw SimpleExpressionParseError if one of used variables is not presented in the `$vars` array
   */
  public function checkVars($vars) {
    if (!is_array($vars)) {
      trigger_error('checkVars: array is expected', E_USER_WARNING);
      return;
    }
    foreach ($this->vars_used as $v => $p) {
      if (!array_key_exists($v, $vars)) {
        throw new SimpleExpressionParseError($p, 'Undefined variable: ' . $v);
      }
    }
  }

}