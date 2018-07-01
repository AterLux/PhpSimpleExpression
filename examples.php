<?php

  require_once('classes/SimpleContext.php');
  require_once('classes/SimpleExpression.php');

/*****************
 * USAGE EXAMPLE *
 *****************/

  $expression = "x ^ 2 + sqrt(y) * 4"; 
  try {
    $se = new SimpleExpression($expression); // Compile the expression, using default context

    // Parsing is register-independed, so all variables names should be in the lower case

    $vars = array('x' => 20, 'y' => 0);

    // All unknown literals in the expression will be treated as a variable access
    // If variable does not exist on the runtime, it will be silently treated as NULL
    // To avoid mistakes, we can check all variable names simply passing to checkVars()
    // an array which keys list all possible variable names.
    // It will throw SimpleExpressionParseError if there is a variable not listed in this array
    $se->checkVars($vars);



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


  print PHP_EOL;

/*****************************
 * ENCLOSED EXPRESSIONS MODE *
 *****************************/

  // In enclosed mode expression is treated as a text string, which contains multiple expressions in it.
  // Each expression is enclosed in a pair of square brackets

  $expression = "I have [num_of_carrot] carrot[num_of_carrot != 1 ? 's'], [num_of_apple] apple[num_of_apple != 1 ? 's'] and [num_of_banana] banana[num_of_banana != 1 ? 's']"; 
  try {
    $se = new SimpleExpression($expression, true, true); // Compile the expression, using default context
    // Second parameter is `true` to use the default context, third parameter is `true` to parse the expression in enclosed mode

    // Parsing is register-independed, so all variables names should be in the lower case

    $vars = array(
      'num_of_carrot' => 20,
      'num_of_apple' => 1,
      'num_of_banana' => 5
     );

    print $se->run($vars) . PHP_EOL;

  } catch (SimpleExpressionParseError $e) {
    print "Parse error at position {$e->getPosition()}: {$e->getMessage()}" . PHP_EOL;

    print $expression . PHP_EOL;
    print str_repeat(' ', $e->getPosition()) . '^' . PHP_EOL; 
  }


  print PHP_EOL;
  
/*****************
 * USING CONTEXT *
 *****************/

  // Context allows to define functions and constants, which will be used at the compile-time
  // Contexts can be stacked in the tree-like structure, so, if function or variable is not found in a particular context
  // it will be looked for in the parent context, and so on.

  // There is a singleton default context, which defines some useful functions and also PI constant.

  $default_context = SimpleContext::getDefaultContext();

  // Adding or removing functions or variables into/from there will affect all later expression compilations while the class is loaded.
  // To use all the default functions, but not affecting the default context, we can create a new context, setting the default context as a parent.
  // It could be achieved in several ways:
  // $my_context = new SimpleContext($default_context); // Parent context could be passed as a parameter into SimpleContext constructor.
  // $my_context = new SimpleContext(true); // If boolean `true` is passed, then default context will be used as a parent
  $my_context = $default_context->derive(); // Or method `derive` on the context will create a new context with this context set as a parent
  // To create root context omit the parameter, or pass NULL: $my_context = new SimpleContext();


  $my_context->registerConstant('THETA', 0.000001); // registerConstant method allows to register a single constant

  $my_context->registerConstants(array('MAX_WIDTH' => 128, 'MAX_HEIGHT' => 64)); // registerConstants accepts an array to register multiple constants at once

  // If the name of a constant appears in the expression, it will be replaced by it's value. So, altering context afterwards will not affect the compiled expression


  function my_func($a, $b) {
    return $a - $b * 2;
  }

  $my_context->registerFunction('my_func'); // Simple way to register a function is to pass it's name to `registerFunction`;

  // It is possible also pass a closure as a function. In this case, also if you want the function to be accessible on different name in the expression,
  // pass the alias name of the function in the second parameter

  $f = function($x) { return $x * $x * 2; };
  $my_context->registerFunction($f, 'second_func');

  // Normally, if in the expression constant arguments are passed to a function, then the function is calculated at the compile time 
  // and returned value is placed as a constant in the expression, so it never called again at the runtime.
  // If function have side effects, it could be marked as `volatile` by passing boolean `true` as optional third parameter

  $my_context->registerFunction('time', 'get_time', true);

  // As with constants, multiple functions can be registered at once, using `registerFunctions` method. 
  // The first parameter is an array, which values represent the function names or enclosures, while keys, if they are not numerical, represent aliases.
  // Second boolean parameter is `volatile`:
  // $my_context->regsiterFunctions(array('my_func', 'second_func' => $f)); // Register multiple functions
  // $my_context->regsiterFunctions(array('get_time' => 'time'), true); // Register volatile functions


  $expression = "Expression: [my_func(second_func(x), 5) / MAX_WIDTH], Time: [get_time()]"; 
  try {
    $se = new SimpleExpression($expression, $my_context, true); // Compile the expression, using created context and enclosed mode

    // Parsing is register-independed, so all variables names should be in the lower case

    $vars = array('x' => 20);
    print $se->run($vars) . PHP_EOL;

  } catch (SimpleExpressionParseError $e) {
    print "Parse error at position {$e->getPosition()}: {$e->getMessage()}" . PHP_EOL;

    print $expression . PHP_EOL;
    print str_repeat(' ', $e->getPosition()) . '^' . PHP_EOL; 
  }

  print PHP_EOL;

/******************************
 * NOTES ON EXPRESSION SYNTAX *
 ******************************/

  try {
    $vars = array('x' => 10);


    // String literals are enclosed in single or double quotes. To escape the quotes sign itself, pass 
    $expression = '"This is a string literal;" \'this is also a string literal;\' "This ""string"" is escaped;"';
    $se = new SimpleExpression($expression); // Compile the expression, using default context and single-expression mode
    print $se->run($vars) . PHP_EOL;



    // consequent expressions without any math operation in between, means string concatination
    // concatenation has a highest priority (after parenthesis)
    // In expression "x + '1' 2 '34'";  2 will be converted to string `2` for stringconcatenation,
    // then string '1234' will be converted to a number, to perform math operation
    $expression = "x + '1' 2 '34'"; 
    $se = new SimpleExpression($expression); 
    print $se->run($vars) . PHP_EOL;

    // boolean operations are | for OR and & for AND. Double circumflexes ^^ for boolean exclusive or 
    // exclamation mark ! is unary operation for boolean NOT.
    // not equal may be both <> and !=
    $expression = "((x > 20 | x < 30) & !(x = 25) ^^ (x <> 23) ? 'true' : 'false') ' for X = ' x"; 
    $se = new SimpleExpression($expression); 
    print $se->run($vars) . PHP_EOL;

    // Single circumflex ^ also double asterisk ** for power operation 
    $expression = "x ^ 3"; 
    $se = new SimpleExpression($expression); 
    print $se->run($vars) . PHP_EOL;

    // Ternary operator has the lowest priority. That means the whole expression at it's left will be considered as a condition
    // and whole expression after colon : will be considered as `else` block. 
    // This allows you to stack several ternary operators forming selectors:
    // condition1 ? variant1 : condition2 ? variant2 : ...  conditionN ? variantN : elsevariant
    // but precautions should be taken when combining conditionals with other operators. Conditional block should be put in parenthesis
    // If colon and else-part are omited, then constant empty string will be used as an else-expression

    $expression = "x > 100 ? 100 : x < 0 ? 0 : x";  
    $se = new SimpleExpression($expression); 
    print $se->run($vars) . PHP_EOL;


    // The compilier may perform several optimizations. For debug purposes you can always get textual representation
    // of the compiled expression by calling `debugDump()` method
    // For example, in the expression "x > 0 | 1 = 1 ? x / 5 * 0 : x", conditional part will be omited, because 1 = 1 is true, 
    // and logical OR always will be true, so, "else" block will be omited and always "true" block will be used
    // expression x / 5 * 0, will be replaced by a constant zero
    $expression = "x > 0 ^^ 1 = 1 ? x / 5 * 0 : x";  
    $se = new SimpleExpression($expression); 
    print $se->run($vars) . PHP_EOL;
    print $se->debugDump() . PHP_EOL;


  } catch (SimpleExpressionParseError $e) {
    print "Parse error at position {$e->getPosition()}: {$e->getMessage()}" . PHP_EOL;

    print $expression . PHP_EOL;
    print str_repeat(' ', $e->getPosition()) . '^' . PHP_EOL; 
  }

