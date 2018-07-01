# PhpSimpleExpression
This is a simple expression parser and evaluator for PHP.

**Requires PHP 5.3 or greater**

It utilizes function enclosures (which are introduced in PHP 5.3) to evaluate expressions really fast, without using `eval` and keeping the user-entered code safely isolated.

It implements model "compile once - evaluate multiple times". The same expression can be evaluated against different variable values hundreds of thousands times per second.

It works in two modes:

## Single Expression Mode

This is default mode. In this mode input string is passed as a single expression. 
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

In this mode the input string is cosidered as a text string with one or many substitutions, each is an expression enclosed in square brackets.
For example: `I have [num_of_carrot] carrot[num_of_carrot != 1 ? 's'], [num_of_apple] apple[num_of_apple != 1 ? 's'] and [num_of_banana] banana[num_of_banana != 1 ? 's']`

```php
  $expression = "I have [num_of_carrot] carrot[num_of_carrot != 1 ? 's'], [num_of_apple] apple[num_of_apple != 1 ? 's'] and [num_of_banana] banana[num_of_banana != 1 ? 's']"; 
  $se = new SimpleExpression($expression, true, true); // Compile the expression, using default context
  // Second parameter is `true` to use the default context, third parameter is `true` to parse the expression in enclosed mode

  $vars = array(
    'num_of_carrot' => 20,
    'num_of_apple' => 1,
    'num_of_banana' => 5
   );

  print $se->run($vars) . PHP_EOL; // I have 20 carrots, 1 apple, 5 bananas
```


## Contexts

Class `SimpleContext` contains information about functions and named constants which can be used in the expression.
Contexts can be stacked in the tree-like structure, so, if function or variable is not found in a particular context it will be looked for in the parent context, and so on.

There is a singleton default context, which defines some useful functions and also PI constant. It possible to obtain it like this: `SimpleContext::getDefaultContext()`

Adding or removing functions or constants into/from the default context will affect all later expression compilations which are relying on the default context.
To use all the default functions, but not affecting the default context, it is possible to create a new context, setting the default context as a parent.
It could be achieved in several ways:
```php
$default_context = SimpleContext::getDefaultContext();
$my_context = new SimpleContext($default_context); // Parent context could be passed as a parameter into SimpleContext constructor.
$my_context = new SimpleContext(true); // If boolean `true` is passed, then default context will be used as a parent
$my_context = $default_context->derive(); // Or method `derive` on the context will create a new context with this context set as a parent
```
To create a root context omit the parameter, or pass NULL: `$my_context = new SimpleContext();`

### Constants
to register a constant use `registerConstant(name, value)` or `registerConstants(array)` methods.

```php
$my_context->registerConstant('THETA', 0.000001); // registerConstant method allows to register a single constant

$my_context->registerConstants(array('MAX_WIDTH' => 128, 'MAX_HEIGHT' => 64)); // registerConstants accepts an array to register multiple constants at once
```
To remove a constant you can use `unregisterConstant` method. Pass a constant name, or array of multiple names, or '*' string to remove all.

**NOTE** `NULL` can be registered in the context, but at the compile time will be considered as non-declared, and therefore replaced by an operation of accessing variable of the same name.
You can use `NULL` to supress unwanted constants, declared in the parent context, if their names are required as variable names.

### Functions

You can declare custom functions to be used in expressions.

To register a function you can use `registerFunction(function[, alias[, is_volatile]])` method. 
`function` can be name or function or function enclosure.
`alias` can be used to set make the function accessible by an alternative name from a script.
set `is_volatile` to true, if function has side-effects, i.e. it can return different values with the same parameters. `rand`, `date` - are examples of such functions.
Otherwise, if only constant parameters passed to the function, it will be calculated and replaced by a constant on the compile-time.

```php
  $my_context->registerFunction('my_func'); // Simple way to register a function is to pass it's name to `registerFunction`;

  // Passing a closure
  $f = function($x) { return $x * $x * 2; }; 
  $my_context->registerFunction($f, 'second_func'); // Register the function closure by a specified alias

  $my_context->registerFunction('time', 'get_time', true); // Register a volatile function with alternative alias
```

You also can use `registerFunctions(array[, is_volatile])` method to register multiple functions at once. If keys of array items is not numerical, then it used as an alias.

To unregister functions use `unregisterFunction` method. The alias, array of multiple aliases or '*' to remove all can be passed.

**NOTE:** At the compile time, all named constants replaced by it's values, and functions calls replaced by corresponding `ReflectionFunction`. So, altering the context after the expession is compiled will not have any effect on the compiled expression.

## Expression syntax

Supports:
* Unary operators `+` (cast to numric), `-` (negative), `!` (logical negation)
* Math operations: '+' (addition), '-' (subtaction), `*` (multiplication), `/` (division), '%' (remainder), `^` or `**` (power);
* Logical opartaions: `&` (logical and), `|` (logical or), `^^` (logical exclusive or)
* Comparison: `=` (equals), `<>` or `!=` (not equals), `>` (greater than), `>=` (greater than, or equal), `<` (less than), `<=` (less than or equal)
* Ternary conditional operator <condition> `?` <expression_if_true> [`:` <expression_if_false>] (If 'false' part is omited, it considered as an empty string)
* Implicit string concatenation (expressions without operators in between are considered as a concatenation of their string values)
* Parentheses

**NOTE:** division and reminder operation handles the case, when second operand is zero, passing INF, -INF or NAN as a result. It made to avoid runtime warnings, if expression has division by zero

Numeric constants starts with digit and can contain decimal point.
String constants are enclosed in single or double quotes (`'` or `"`). To escape the quotes symbol itself it can be typed twice.
Identifiers are starts with a letter or underscore

Priority of operations (from highest to lowest):
1. Expressions in **parentheses** are calculated first;
1. Unary operators;
1. Implicit **string concatenation** (if no operators between expressions);
1. Powers (`^` or `**`);
1. Multiplications, divisions, reminders (`*`, '/', '%');
1. Additions and substrations (`+`, '-')
1. Comparisons (`=`, `<>` or `!=`, `>`, `>=`, `<`, `<=`)
1. Logical and (`&`)
1. Logical or (`|`)
1. Logical exclusive or (`^^`)
1. Ternary conditional operators

**NOTE:** Conditional operator has the lowest priority. That means the whole expression at it's left will be considered as a condition
and whole expression after colon `:` will be considered as `else` block. This allows you to stack several ternary operators forming selectors:
`condition1 ? variant1 : condition2 ? variant2 : ...  conditionN ? variantN : elsevariant`


# Usage

At the compile time it may throw `SimpleExpressionParseError` exception, which has method `getPosition()` to return a position of the error

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

## Implicit variable access

*NOTE:* All unknown identifiers will be treated as a variable access at the compile time, but at the runtime, all non-existent variables will be silently treated as `NULL`s.
It may lead to some unwanted situations.

Consider two expressions: "`sin(x)`" and "`sinus(x)`". 

The first one (considering fuction `sin` is declared in the context) will be compiled as a function call with value of variable `x` passed as the parameter.
The second one (considering function `sinus` is not declared) will be silently compiled as a string concatenation of variables `sinus` and `x`.

To avoid such mistakes, you can check all variable names that were used in the expression.
You can obtain their list by calling method `getVars()` of the SimpleExpression object. 
It will return an array, which keys represent variable names, and values show the position in the expression, where the variable was referenced for the first time.
But to simplify checking process it is possible to use `checkVars(array)` method. Pass an array which keys list all allowed variable names. 
If the expression references some unlisted variable, the `SimpleExpressionParseError` exception will be thrown.

```php
    $expression = 'sinus(x)';
    $se = new SimpleExpression($expression); // Compile the expression, using default context
    $vars = array('x' => 0, 'y' => 0);
    $se->checkVars($vars); // Exception will be thrown, since `sinus` is unknown variable name
```

## Case insensetive

**NOTE:** expression is case insensetive. All variable names are turned into the lower case. 
So, array passed to `run` method should have keys in lower case
