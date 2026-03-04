# Kalix 03 Router and Controller

&nbsp;

## 03.a Routes

Routes are specifiled in one ore more files located in `routes/`, deafult and recomended one is `routes/routes.php`

The routes source file is a `return` statement tha returns a key-value pairs array.

The tipical is defined as follows:

```
<?php
return [
	'/@lang/path/to/controller/@action/@param1:type/@param2:type/@param3:type' => 'controllers\controller->"action"'
];
```


### 03.a.1

The @lang parameter is not required
Normally it is the first part of the URL, but it can be any part of the URL.
When it is present it must be a 2 lowercase chars string.
It must be one of the upported locales in `locale/` .
If it doesn't comply with the above a 400 response is returned (malformed param) or 404 for non supported locale.

### 03.a.2

The `/path/to/controller` part of the route is arbitrary.
In the shortest form is `/`, so `'/@lang/' => '.....'` .
The name of the controller in the URL does not have necessarily match with the controller name in the route.


### 03.a.3

There can be one ore more params.
The param name is preceeded by a `@` character
For each param the type is specified.
The type can be int, float, string, boolean.
The type must not be specified for the @lang param as it is always string
If the type is preceeded by '?' the associated parameter is optional (and will be passed as null to the function in the controller).
So let's make an example
```
	'/@lang/imgs/@action/@id:int/@width:?float/@height:?float' => 'controllers\images->action'
```
The `id` (it is the id of the image in the example) is compulsory while `width` and `height` are optional
The the right part of the route `controllers\images->action` instruct the router that it must look for a `images.php` source inside te directory `controllers/`; inside `images.php` must be defined a class called `images`; the function called is `@action`; the function receives the parametes `id`, `width`, `height`, with the specified types.
The left part of the route tells the router that `id` is required while `width` and `height` are optional. The function will receive `null` for a missing parameter.
If a optional parameter is missing in the URL then all of the subsequent paramter must be omitted.
If a parameter is optional in the route then all of the subsequent paramenters must be optional.
If any of the above rules are not respected then an exception is thrown.
It is not mandatory for the action to be paramterized, for example the tipycal root route: `'/@lang/ => 'controllers\home->index'`

### 03.a.4

When the user visits an unsupported/undefined route then 404 is returned.
When the visited URL matches a defined route but is malformed (mising a required parameter, the action is not defined, the parameter type is mismatching, etc...) then 400 error is returned.

&nbsp;

### 03.a.5 Localization

When the URL mathces a localized route but the `@lang` paramter is missing the user is rerouted (code 301) to the correspong localized route with default language.
How the default language is determined:
(1) the `lang` cookie is inspected, if it is present then this is the default language.
(2) If the `lang` cookie is is not present the language is retrieved, if possibile, from the header sent by the browser in the request. Then the `lang` cookie is set/saved then the user is rerouted
(3) If the browser do not specifies any language then if only one locale is defined in the locales this will be the default language, cookie is saved, user is reroute.
(4) If more locales are present, the locale with the label `'default' => true` is the default locale, cookie is saved, user is rerouted.

&nbsp;

## 03.b Default controller

Every controller is a class that extends the project's (web app's) default controller;
The app's default controller (that is implemented by the programmer using Kalix) extends the Kalix's default controller.

### 03.b.1 Kalix's default controller

The Kalix's default controller knows the user's language from the URL, updates/writes the `lang` cookie.
The `lang` is stored into `protected readonly string $lang;`
The `lang` will be exported as `$lang` into the eventually rendered view.

### 03.b.2 Kalix's default controller and the connection to the mysql database

The Kalix's default controller instantiate `protected readonly $db` instance of the `Db` class with connection properties.
`$db` will be passed when instantiating any model, the default model class, that every model extends, will use the connection properties to connect to the mysql database when needed.
Note: You may suggest a bettern pattern for this pattern specified in this paragraph (03.b.2)

### 03.b.3 App's default controller (extends Kalix's default controller)

This is the class every app's controller extends.
This is defined by the app's programmer (the programmer that uses Kalix framework)
It can be "empty" (no more properties or methods are defined)
It can be a good place to retrieve the user's session from a `session` cookie, eventually authenticate the user, load the `user` from the database, etc..

---

### 03.b.2 modification

Better long-term pattern: inject a small ConnectionProvider/DbConfig service into models (dependency injection), instead of controllers passing DB handles directly. This keeps controllers thinner and model construction more testable.





