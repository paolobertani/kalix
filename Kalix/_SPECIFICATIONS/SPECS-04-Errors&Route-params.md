# Kalix 04 Errors and "more" about routes

## 04.1 Errors

&nbsp;

The app may implement a `error/` controller with a `public error( int $code, string? $error = null, array? $stack_backtrace = null )` function.
The controller is istantiated and the function is called when an error is thown.
This is a mean for the app to present a custom error page.
If the error is a PHP error then the function receives the error and the stack backtrace.
The stack backtrace is an array of items like this:
```
[
	'file_path' => string,
	'function_name' => string,
	'line_number' => int
]
```
The last element of the array is where the error occurred (maximum depth)


&nbsp;

Only public functions in the controller are available actions. Attempts to call Protected or private ones lead to a 400 error.

&nbsp;

## 04.b Parameters in routes

An optional parameter can be passed as exactly `null` in the URL

Parameters in the URL must conform to the type specified:

int: an integer value
float: a float value
boolean: 0 | 1
string: a base 64 url safe string

If the url contains a parameter non conforming to the specified type a 400 error is trown.

The default controller provides a `toBase64` function to convert a string to base 64 url safe

The router automatically decodes bas64 strings before passing them as a parameter to the target function.