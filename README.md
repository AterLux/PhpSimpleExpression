# PhpSimpleExpression

*Читайте также [это же описание на русском языке](README.ru.md)*

This is a simple expression parser and evaluator for PHP.
It can be used to calculate simple formulae or string substitutions, provided by a user

**Requires PHP 5.3 or greater**

It utilizes function closures (which were introduced in PHP 5.3) to evaluate expressions fast, but, in the same time, without using `eval` and keeping the user-entered code safely isolated.

It implements model "compile once - evaluate multiple times". The same expression can be evaluated against different variable values hundreds of thousands of times per second.

It works in two modes:

## Single Expression Mode

This is the default mode. In this mode, the input string is parsed as a single expression. 
For example: `x ^ 2 + sqrt(y) * 4`

```php
  $expression = "x ^ 2 + sqrt(y) * 4"; 
  $se = new SimpleExpression($expression); // Compile the expression, using default context
  ...
 
  $vars = array('x' => 20, 'y' => 16); // set list of variables
  $result = $se->run($vars); // evaluate the parsed expression against this variable values
  
  print $result . PHP_EOL; // prints the result: 416
```  
  
## Enclosed Expression Mode

In this mode the input string is considered as a text string with one or many substitutions, each is an expression enclosed in square brackets.
For example: `I have [num_of_carrot] carrot[num_of_carrot != 1 ? 's'], [num_of_apple] apple[num_of_apple != 1 ? 's'] and [num_of_banana] banana[num_of_banana != 1 ? 's']`

```php
  $expression = "I have [num_of_carrot] carrot[num_of_carrot != 1 ? 's'], [num_of_apple] apple[num_of_apple != 1 ? 's'] and [num_of_banana] banana[num_of_banana != 1 ? 's']"; 
  $se = new SimpleExpression($expression, true, true); // Compile the expression, using default context
  // Second parameter is `true` to use the default context, third parameter is `true` to parse the expression in enclosed mode

  $vars = array(
    'num_of_carrot' => 20,
    'num_of_apple' => 1,
    'num_of_banana' => 3
   );

  print $se->run($vars) . PHP_EOL; // I have 20 carrots, 1 apple, 5 bananas
```
*In Russian the expression may look a bit more complicated: `$expression = "У меня есть [num_of_carrot] морков[num_of_carrot % 100 >= 10 & num_of_carrot % 100 < 20 | num_of_carrot % 10 >= 5 | num_of_carrot % 10 = 0 ? 'ок' : num_of_carrot > 1 ? 'ки' : 'ка'], [num_of_apple] яблок[num_of_apple % 100 >= 10 & num_of_apple % 100 < 20 | num_of_apple % 10 >= 5 | num_of_apple % 10 = 0 ? '' : num_of_apple > 1 ? 'а' : 'о'], [num_of_banana] банан[num_of_banana % 100 >= 10 & num_of_banana % 100 < 20 | num_of_banana % 10 >= 5 | num_of_banana % 10 = 0 ? 'ов' : num_of_banana > 1 ? 'а']";`*


## Contexts (class SimpleContext)

Class `SimpleContext` contains information about functions and named constants which can be used in expressions.
Contexts can be stacked in the hierarchial tree-like structure, so, if function or constant is not found in a particular context it will be looked for in the parent context, and so on.

There is a singleton default context, which defines some useful functions and also PI constant. It possible to obtain it like this: `SimpleContext::getDefaultContext()`

Adding or removing functions or constants into/from the default context will affect all later expression compilations which are relying on the default context.
To use all the default functions, but not affecting the default context, it is possible to create a new context, setting the default context as a parent.
It could be achieved in several ways:
```php
$default_context = SimpleContext::getDefaultContext();
$my_context = new SimpleContext($default_context); // Parent context could be passed as a parameter into SimpleContext constructor.

$my_context = new SimpleContext(true); // If boolean `true` is passed, then default context will be used as a parent

$default_context = SimpleContext::getDefaultContext();
$my_context = $default_context->derive(); // Or method `derive` on the context will create a new context with this context set as a parent
```
To create a root context omit the parameter, or pass NULL: `$my_context = new SimpleContext();`

### Constants
to register a constant use `registerConstant(name, value)` or `registerConstants(array)` methods.

```php
$my_context->registerConstant('THETA', 0.000001); // registerConstant method allows to register a single constant

$my_context->registerConstants(array('MAX_WIDTH' => 128, 'MAX_HEIGHT' => 64)); // registerConstants accepts an array to register multiple constants at once
```
To remove a constant you can use `unregisterConstant` method. Pass a constant name, or array of multiple names, or '&ast;' string to remove all from the current context. Note: this operation does not affect the parent contexts.

**NOTE** a constant with `NULL` value can be registered in the context, but at the compile time will be considered as non-declared, and therefore replaced by an operation of accessing a variable of the same name.
You can use `NULL` to suppress unwanted constants, declared in the parent context, if their names are required as variable names.

### Functions

You can declare custom functions to be used in expressions.

To register a function you can use `registerFunction(function[, alias[, is_volatile]])` method. 
`function` can be name or function or function closure.
`alias` can be used to set make the function accessible by an alternative name from an expression.
set `is_volatile` to `true`, if the function has side-effects, i.e. it can return different values with the same parameters. `rand`, `date` - are examples of such functions.
Otherwise, if only constant parameters passed to the function, it will be calculated and replaced by a constant on the compile-time.

```php
  function my_func($a, $b) {
    return $a - $b * 2;
  }

  $my_context->registerFunction('my_func'); // Simple way to register a function is to pass it's name to `registerFunction`;

  // Passing a closure
  $f = function($x) { 
    return $x * $x * 2; 
  }; 
  $my_context->registerFunction($f, 'second_func'); // Register the function closure by a specified alias

  $my_context->registerFunction('time', 'get_time', true); // Register a volatile function with alternative alias
```

You also can use `registerFunctions(array[, is_volatile])` method to register multiple functions at once. If key of array item is not numerical, then it is used as an alias.

To unregister functions use `unregisterFunction` method. The alias, array of multiple aliases or '&ast;' to remove all can be passed.

**NOTE:** At the compile time, all named constants replaced by its values, and functions calls replaced by corresponding `ReflectionFunction`. No information about the used context is stored. So, altering the context after the expression is compiled will not have any effect on the compiled expression.

### Default Context

Default context contains constant `PI` and also has several functions registered

Function alias | Comment
-------------- | -------
`sin(x)` | Sine
`cos(x)` | Cosine
`asin(x)` | Inverse sine
`acos(x)` | Inverse consine
`tan(x)` | Tangent
`atan(x)` | Inverse tangent
`atan2(x, y)` | Arc tangen of y / x in correct quadrant ([see](http://php.net/manual/en/function.atan2.php))
`deg2rad(x)`  | Degree to radians
`rad2deg(x)` | Radians to degree
`abs(x)` | Absolute value
`floor(x)` | Round down to integer
`ceil(x)` | Round up to integer
`round(x[, precision])` | Round to closest value ([see](http://php.net/manual/en/function.round.php))
`exp(x)`| Exponent
`sqrt(x)`| Square root
`hypot(x, y)` | Hypotenuse (Square root of sum of squares)
`ln(x)` | Natural logarithm (see notes)
`log(x, base)` | Logarithm by arbitrary base (see notes)
`lg(x)`, `log10(x)` | Logarithm by base 10 ([see](http://php.net/manual/en/function.log10.php))
`min(...)` | Lowest value of multiple arguments
`max(...)` | Highest value of multiple arguments
`substr(string, start[, length])` | Substring ([see](http://php.net/manual/en/function.substr.php))
`strlen(string)` | Length of the string
`upper(string)` | Uppercase of the string ([see](http://php.net/manual/en/function.strtoupper.php))
`lower(string)` | Lowercase of the string ([see](http://php.net/manual/en/function.strtolower.php))
`replace(search, replace, subject)` | Replaces occurrences of search string in the subject ([See](http://php.net/manual/en/function.str-replace.php))
`regexp(pattern, subject)` | Performs a regular expression match (Uses php's [preg_match](http://php.net/manual/en/function.preg-match.php))
`regexp_replace(pattern, replacement, subject[, limit])` | Performs a regular expression-based replace (Uses php's [preg_replace](http://php.net/manual/en/function.preg-replace.php))
`number_format(number[, decimals[, dec_point, thousands_sep]])` | Formats a number ([See](http://php.net/manual/en/function.number-format.php))
`format(format, ...)` | Formats a string (Uses php's [sprintf](http://php.net/manual/en/function.sprintf.php))
`random([a[, b]])` (volatile) | Returns random number: 1) if no parameters, then float number not less than 0 but less than 1; 2) if one parameter, then integer less than this number, but not less than zero; 3) if two parameters then integer between first and second, inclusive. if second parameter less than first, then first parameter is returned.
`date(format[, timestamp])` (volatile) | formats date/time into a string ([see](http://php.net/manual/en/function.date.php))

**NOTES:**
* Some function names are different from names of corresponding functions in php. It made to compatibility to such expression parsers made on other languages.
* `ln` and `log` functions are both aliases for php's [log function](http://php.net/manual/en/function.log.php), and therefore share the functionality with optional second parameter. But it is recommended to use `ln` for natural logarithm, and `log` for base-specified.


## Expression syntax

Supports:
* Unary operators `+` (cast to numric), `-` (negative), `!` (logical negation)
* Math operations: '+' (addition), '-' (subtaction), `*` (multiplication), `/` (division), '%' (remainder), `^` or `**` (power);
* Logical opartaions: `&` (logical and), `|` (logical or), `^^` (logical exclusive or)
* Comparison: `=` (equals), `<>` or `!=` (not equals), `>` (greater than), `>=` (greater than, or equal), `<` (less than), `<=` (less than or equal)
* Ternary conditional operator <condition> `?` <expression_if_true> [`:` <expression_if_false>] (If 'false' part is omited, it considered as an empty string)
* String concatenation operator '#'. Also optional implicit string concatenation mode available (expressions without operators in between are considered as a concatenation of their string values)
* Parentheses
* Function calls
* Named constants
* Variable access

**NOTE:** division and reminder operation handle the case, when the second operand is zero, passing INF, -INF or NAN as a result. It made to avoid runtime warnings if the expression has division by zero

Numeric constants start with a digit and can contain the decimal point.
String constants are enclosed in single or double quotes (`'` or `"`). To escape the quotes symbol itself it can be typed twice.
Identifiers contain letters or underscore, and can contain digits (except for first char) 

Priority of operations (from highest to lowest):
1. Expressions in **parentheses** are calculated first;
1. Unary operators;
1. Implicit **string concatenation** (when no operators between expressions), if enabled;
1. Powers (`^` or `**`);
1. Multiplications, divisions, reminders (`*`, `/`, `%`);
1. Additions and subtractions (`+`, `-`)
1. String concatenation (`#`)
1. Comparisons (`=`, `<>` or `!=`, `>`, `>=`, `<`, `<=`)
1. Logical and (`&`)
1. Logical or (`|`)
1. Logical exclusive or (`^^`)
1. Ternary conditional operator

**NOTE:** Conditional operator has the lowest priority. That means the whole expression at it's left will be considered as a condition
and the whole expression after colon `:` will be considered as `else` block. This allows you to stack several ternary operators forming selectors:
`condition1 ? variant1 : condition2 ? variant2 : ...  conditionN ? variantN : elsevariant`


# Usage

At the compile time, it may throw `SimpleExpressionParseError` exception, which has the method `getPosition()` to return a position of the error

```php
  $expression = "x ^ 2 + sqrt(y) * 4"; 
  try {
    $se = new SimpleExpression($expression); // Compile the expression, using default context

    $vars = array('x' => 0, 'y' => 0);

    // Once expression is compiled, it could be evaluated many time against different variable values 
    for ($x = 10 ; $x < 100 ; $x += 40) {
      for ($y = 10 ; $y < 100 ; $y += 40) { // Run it multiple times
        $vars['x'] = $x;
        $vars['y'] = $y;

        $result = $se->run($vars);

        print "for x = $x, y = $y, result is $result" . PHP_EOL;
      }
    }

  } catch (SimpleExpressionParseError $e) {
    print "Parse error at position {$e->getPosition()}: {$e->getMessage()}" . PHP_EOL;

    print $expression . PHP_EOL;
    print str_repeat(' ', $e->getPosition()) . '^' . PHP_EOL; 
  }
```

## Implicit string concatenation (optional)

The option can be enabled by calling `->implicitConcatenation(true)` on the `SimpleContext` object before the compilation.

When option is enabled, any expressions in succession without operators in between are treated as a concatenation of their string values. For example:
```php
    $my_context = new SimpleContext(true); // If boolean `true` is passed, then default context will be used as a parent
    $my_context->implicitConcatenation(true); // Enable the option
    $expression = "'Hello, ' obj '!'";
    $se = new SimpleExpression($expression, $my_context); // Compile the expression, using the provided context
    $vars = array('obj' => 'World');
    print $se->run($vars); // Prints "Hello, World!";
```

In version 1.0 it was the only way to concatenate strings and therefore enabled by default. 
In version 1.1 explicit string concatenation operator `#` was introduced, and implicit string concatenation is disabled
                                                                                                                           
## Implicit variable access

**NOTE:** All unknown identifiers will be treated as a variable access at the compile time, but at the runtime, all non-existent variables will be silently treated as `NULL`s.
It may lead to some unwanted situations.

Consider two expressions: "`sin(x)`" and "`sinus(x)`". 

The first one (considering function `sin` is declared in the context) will be compiled as a function call with a value of variable `x` passed as the parameter.
The second one (considering function `sinus` is not declared and implicit string concatenation is enabled) will be silently compiled as a string concatenation of variables `sinus` and `x`.

To avoid such mistakes, it is possible to check all variable names that were used in the expression.
Their list can be obtained by calling method `getVars()` of the SimpleExpression object. 
It will return an array, which keys represent variable names, and values show the position in the expression, where the variable was referenced for the first time.
But to simplify checking process it is possible to use `checkVars(array)` method. Pass an array which keys list all allowed variable names. 
If the expression references some unlisted variable, the `SimpleExpressionParseError` exception will be thrown.

```php
    $expression = 'sinus(x)';
    $se = new SimpleExpression($expression); // Compile the expression, using default context
    $vars = array('x' => 0, 'y' => 0);
    $se->checkVars($vars); // Exception will be thrown, since `sinus` is unknown variable name
```

## Case insensitive

**NOTE:** expression is case insensitive. All variable names are turned into the lower case. 
So, array passed to `run` method should have keys in lower case

## Optimizations

At the compile time, some optimizations may be performed.

One and most important optimization is precalculation of constant expressions. for example in the expression `sin(PI / 2)` (considering the usage of default context) at the compile-time, `PI` will be replaced by the constant value, then `PI / 2` expression is calculated, then `sin` function performed. The returned value will be stored as a constant in the resulting expression, avoiding recalculation of the same expression on each run.

Some less obvious optimizations also can be performed. Such as combining of nested concatenations in a single, or combining math operands (e.g. `(x * 4) / 2` => `(x * 2)` etc.

To check how optimizations were done, you can use `debugDump()` method of a `SimpleExpression`. It will return a textual representation of evaluation tree. 

```php
  $s = "(PI > 3) ? sin(x * 2 * PI) : sqrt(y)"; 
  $e = new SimpleExpression($s);
  print $e->debugDump();
```
will output `@sin(({x} * (const 6.2831853071796)))`

In this string `@` means function call; `{...}` - variable access; `(const ...)` - constant node.

Here, PI is a constant, which is greater than 3, so the whole conditional expression is replaced by it's "if_true" part, where named constant is replaced by its value and two constant-on-the-right multiplications `* 2 * PI` are combined into a single one. 


## Changelog

### ver 1.1.0:

* added `#` (explicit string concatenation) operator.
* implicit string concatenation (without operators) now disabled by default it can be enabled in the `SimpleContext` (see `SimpleContext::implicitConcatenation()` method)
* conditional and boolean logic was changed. Now string `'0'` (also empty array) is considered as 'true'. `NULL`, `false`, `0`, `0.0`, '' are still 'false'
* `&` (or), `|` (and) and `^^` (exclusive or) are not "boolean" operators anymore. Their result depend on the operands types:
        `A | B`  equals to  `A ? A : B`
        `A & B`  equals to  `A ? B : A`
        `A ^^ B` equals to  `!A ? B : (!B ? A : '')`
* engine now has OR-chain processor which is used when construction `A | B | C ...` is detected, it returns the first true operand, or the last one if not a such.
* several new optimizations.
* (X * 0) => 0 optimization was removed to handle right NAN and INF values of the X expression
