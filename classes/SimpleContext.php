<?php
/*************************************************************************************
 *
 *  SimpleExpression 1.1.0
 *
 *  Copyright (C) 2018 Dmitry Pogrebnyak 
 *  (https://aterlux.ru/ dmitry@aterlux.ru)
 *
 *  This file is part of SimpleExpression.
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

/**
 * SimpleContext is used to describe functions and named constants which can be used at the expression
 */
class SimpleContext {

  /**
   * Determines the parent context. 
   * This should be NULL for root or an object of SimpleContext class otherwise
   * `findFunction` and `findConstant` methods will call same methods of the parent context, if this context has no requested function/constant 
   */
  protected $parentContext;
  
  /**
   * Array of all functions declared in this context. 
   * Key of each item is an alias of function in the lower case.
   * Value is an array with items:
   *   'function' => object of ReflectionFunction - the function to be called,
   *   'alias' => name of the function, the same as in corresponding key of the functions array
   *   'volatile' => boolean, true, if function has side-effects, i.e. cannot be replaced by a constant if all parameters are constants
   */
  protected $functions;

  /**
   * Array of all constants declared in this context.
   * Key of each item is a name of the constant, in the upper case. Value is a value of the constant.
   * If Value is `NULL`, then constant is considered existed in this context (`hasConstant` returns true), but not declared: 
   * this name will be compiled ass a parameter acces. This could be used to supress a constant declared in the parent contexts
   */
  protected $constants;


  /** Compiler option: implicit string concatenation (see method implicitConcatenation()) */
  protected $implicitConcatenation = false;

  /**
   * Creates a new instance of a SimpleContext
   * @param SimpleContext|true|NULL $parent specifies a parent context. 
   *   If boolean `true` is passed, then `SimpleContext::getDefaultContext()` will be used
   *   `NULL` specifies no parent context. Other values will genereate `E_USER_WARNING` level error.
   */
  public function __construct($parent = NULL) {
    if ($parent === true) {
      $this->parentContext = SimpleContext::getDefaultContext();
    } else if (isset($parent) && !($parent instanceof SimpleContext)) {
      trigger_error('SimpleContext constructor: $parent should be a SimpleContext instance', E_USER_WARNING);
    } else {
      $this->parentContext = $parent;
    }
    if (isset($this->paretnContext)) { 
      $this->implicitConcatenation = $this->paretnContext->implicitConcatenation();
    }
  }


  /**
   * Returns the default context. Creates one if it does not exists.
   * Default Context is a statically created object of SimpleContext.
   * It has predefined functions:
   * 'sin', 'asin', 'cos', 'acos', 'tan', 'atan', 'atan2', 'deg2rad', 'rad2deg', 
   * 'abs', 'floor', 'ceil', 'round', 'min', max', 'exp', 'sqrt', 'hypot',
   * 'ln' (natural logarithm), 'lg' (logarithm by base 10), 'log10' (logarithm by base 10'), 'log' (logarithm by arbitrary base, second parameter is the base)
   * *Note* the logarithm functions have diffrent names from in php.
   * 'random' (random number not less than 0, but less than 1 if no parameter; random integer not less that 0 but less than the parameter, if 1 parameter is given; 
   *           random integer number between two values, inclusive, if 2 parameters are provided).
   * 'regexp' (alias for `preg_match`), 'regexp_replace' (alias for `preg_replace`)
   * 'substr', 'strlen', 'upper' (alias for `strtoupper`), 'lower' (alias for `strtolower`), 'replace' (alias for `str_replace`),
   * 'number_format', 'format' (alias for 'sprintf')
   * 'date'
   * Also constant 'PI' is declared
   */
  public static function getDefaultContext() {
    static $default_context = NULL;
    if (isset($default_context)) return $default_context;

    $context = new SimpleContext();

    $context->registerFunctions(
        array(
          'sin', 'asin', 'cos', 'acos', 'tan', 'atan', 'atan2',
          'deg2rad', 'rad2deg', 
          'abs', 'floor', 'ceil', 'round',
          'exp', 'sqrt', 'hypot',
          'log', 'ln' => 'log', 'lg' => 'log10', 'log10' => 'log10', 
          'min', 'max',
          'substr', 'strlen', 'upper' => 'strtoupper', 'lower' => 'strtolower', 'replace' => 'str_replace',
          'regexp' => 'preg_match', 'regexp_replace' => 'preg_replace',
          'number_format', 'format' => 'sprintf'
        ),
        false
      );

    $context->registerFunctions(
        array(
          'random' => function($rangeormin = NULL, $max = NULL) { 
             if (!isset($rangeormin)) {
               return (mt_rand() / (mt_getrandmax() + 1.0));
             } elseif (!isset($max)) {
               return ($rangeormin > 1) ? mt_rand(0, $rangeormin - 1) : 0;
             } else {
               return ($max > $rangeormin) ? rand($rangeormin, $max) : $rangeormin;
             }
           },
          'date'
        ), 
        true
      );

    $context->registerConstant('PI', pi());

    $default_context = $context;

    return $context;
  }

  /**
   * Creates a new instance of SimpleContext with this one set as a parent
   */
  public function derive() {
    return new SimpleContext($this);
  }


  /**
   * Registers one function.
   * Function name should match the pattern [A-Za-z_\x7F-\xFF][0-9A-Za-z_\x7F-\xFF]*
   * Names are case-insensetive, so it turned into the lower case.
   * Generates E_USER_WARNING level error, if name of wrong format is given, or function of the same name already exits in this context.
   * Generates E_USER_NOTICE level error, if this or one of parent contexts have a constant with the same name
   * @param mixed $function - the function (ReflectionFunction, closure or function name) to be called.
   * @param string $alias a name for the function, as it will be called from the script. If `false`, then original function name is used
   * @param bool $is_volatile, optional parameter, default false. Specifies if function is non-deterministic, i.e. may output different result with the same parameters passed
   */
  public function registerFunction($function, $alias = false, $is_volatile = false) {
    $func =($function instanceof \ReflectionFunction) ? $function : new \ReflectionFunction($function);

    $al = strtolower(($alias === false) ? (is_string($function) ? $function : $func->name) : $alias);
    if (!preg_match('#^[A-Za-z_\x7F-\xFF][0-9A-Za-z_\x7F-\xFF]*$#', $al)) {
      trigger_error('Given function alias ' . $al . ' cannot be used', E_USER_WARNING);
      return;
    }
    if (is_array($this->functions) && isset($this->functions[$al])) {
      trigger_error('The function with alias ' . $al . ' is already registered', E_USER_WARNING);
      return;
    }
    if ($this->findConstant($al) !== NULL) {
      trigger_error('The contexts tree has a constant with the same name ' . $al . ' as the function registered', E_USER_NOTICE);
    }
    if (!is_array($this->functions)) $this->functions = array();
    $this->functions[$al] = array('function' => $func, 'alias' => $al, 'volatile' => !empty($is_volatile));
  }

  /**
   * Registers multiple functions
   * @param mixed[] array of functions to register. If key of array is a string, then it used as an alias for the function.
   * @param bool $is_volatile Specifies if functions are non-deterministic.
   * {@see registerFunction}
   */
  public function registerFunctions($functions_array, $is_volatile = false) {
    if (!is_array($functions_array)) {
      trigger_error('array parameter expected', E_USER_WARNING);
      return;
    }
    foreach ($functions_array as $alias => $function) {
      $this->registerFunction($function, is_numeric($alias) ? false : $alias, $is_volatile);
    }
  }

  /**
   * Removes one or more registered functions from this context
   * @param mixed $alias. Name of function to remove, array to remove multiple items, or '*' to remove all
   * No warning messages are generated if specified functions do not exist
   */
  public function unregisterFunction($alias) {
    if (!is_array($this->functions)) return;
    if ($alias == '*') {
      unset($this->functions);
      return;
    }
    if (is_array($alias)) {
      foreach ($alias as $al) {
        $al_l = strtolower($al);
        if (isset($this->functions[$al_l])) unset($this->functions[$al_l]); 
      }
    } else {
      $al_l = strtolower($alias);
      if (isset($this->functions[$al_l])) unset($this->functions[$al_l]); 
    }
  }


  /**
   * Registers one constant in this context
   * Names are case-insensetive, so it turned into the upper case.
   * Generates E_USER_WARNING level error, if name of wrong format is given, or constant of the same name already exits in this context.
   * Generates E_USER_NOTICE level error, if this or one of parent contexts have a function with the same name
   * @param string $name - the name of the constant, should match the pattern [A-Za-z_\x7F-\xFF][0-9A-Za-z_\x7F-\xFF]*
   * @param mixed $value value of the constant.
   * NULL value denotes not declared constant. This value can be registered to supress a constant declared in the parent context
   */
  public function registerConstant($name, $value) {
    $nm = strtoupper($name);
    if (!preg_match('#^[A-Za-z_\x7F-\xFF][0-9A-Za-z_\x7F-\xFF]*$#', $nm)) {
      trigger_error('Given constant name ' . $nm . ' cannot be used', E_USER_WARNING);
      return;
    }
    if (is_array($this->constants) && isset($this->constants[$nm])) {
      trigger_error('The constant with name  ' . $nm . ' is already registered', E_USER_WARNING);
      return;
    }
    if (isset($value) && $this->findFunction($name)) {
      trigger_error('The contexts tree has a function with the same name ' . $name . ' as the constant registered', E_USER_NOTICE);
    }
    if (!is_array($this->constants)) $this->constants = array();
    $this->constants[$nm] = $value;
  }

  /**
   * Registers multiple constants
   * @param mixed[] array of constants to register. Keys represent names of constants given in values of array's items
   * {@see registerConstant}
   */
  public function registerConstants($constants_array) {
    if (!is_array($constants_array)) {
      throw new InvalidArgumentException('array parameter expected');
    }
    foreach ($constants_array as $name => $value) {
      $this->registerConstant($name, $value);
    }
  }

  /**
   * Removes one or more registered constants from this context
   * @param mixed $name. Name of the constant to remove, array to remove multiple items, or '*' to remove all
   * No warning messages are generated if specified constant does not exist
   */
  public function unregisterConstant($name) {
    if (!is_array($this->constants)) return;
    if ($name == '*') {
      unset($this->constants);
      return;
    }
    if (is_array($name)) {
      foreach ($name as $nm) {
        $nm_u = strtoupper($nm);
        if (isset($this->constants[$nm_u])) unset($this->constants[$nm_u]); 
      }
    } else {
      $nm_u = strtoupper($nm);
      if (isset($this->constants[$nm_u])) unset($this->constants[$nm_u]); 
    }
  }

  /**
   * Returns true, if this context (not considering parents) has a registered function of the given name
   * @param string $alias the name of a function to look for
   * @return bool true, if function is registered
   */
  public function hasFunction($alias) {
    if (!is_array($this->functions) || empty($this->functions)) return false;
    $al = strtolower($alias);
    return isset($this->functions[$al]);
  }

  /**
   * Looks for the function of the specified name in the context tree.
   * If this context has no such a function, then `findFunction` of the parent context is called.
   * @param string $alias the name of function to look for
   * @return mixed array, describing the function, of false if no function found 
   */
  public function findFunction($alias) {
    if (is_array($this->functions) && !empty($this->functions)) {
      $al = strtolower($alias);
      if (isset($this->functions[$al])) {
        return $this->functions[$al];
      }
    }
    return isset($this->parentContext) ? $this->parentContext->findFunction($alias) : false;
  }

  /**
   * Returns true, if this context (not considering parents) has a registered constant of the given name
   * @param string $name the name of a constant to look for
   * @return bool true, if constant is registered
   */
  public function hasConstant($name) {
    if (!is_array($this->constants) || empty($this->constants)) return false;
    $nm = strtoupper($name);
    return array_key_exists($nm, $this->constants);
  }

  /**
   * Looks for the constant of the specified name in the context tree.
   * If this context has no constant with the same name registered, then `findConstant` of the parent context is called.
   * Note, if context has a constant registered with NULL value, the NULL will be returned
   * @param string $alias the name of function to look for
   * @return mixed value of a constant, NULL if no constant found
   */
  public function findConstant($name) {
    if (is_array($this->constants) && !empty($this->constants)) {
      $nm = strtoupper($name);
      if (array_key_exists($nm, $this->constants)) {
        return $this->constants[$nm];
      }
    }
    return (isset($this->parentContext)) ? $this->parentContext->findConstant($name) : NULL;
  }

  /**
   * Sets or returns the value of the compiler option "implicit string concatentation".
   * When enabled, any two expressions without any operator in between are considered as a string concatenation.
   * Implicit concatenation has a highest priority.
   * When option is disabled then absense of an operator generates an error.
   * In either case explicit string concatenation operator # is available.
   * The # operator has lesser priority than math operators, but greater priority than comparisons and logic operators
   * Initial value of the parameter is "false".
   * The option is set individualy for each context, but when context is created with a parent context specified,
   * the option value is copied from the parent context.
   * @param boolean $value (optional) if specified, sets new value, if NULL then the value remains unchanged
   * @return previous value
   */
  public function implicitConcatenation($value = NULL) {
    $prev = $this->implicitConcatenation;
    if (isset($value)) {
      $this->implicitConcatenation = (bool)$value;
    }
    return $prev;
  }

}