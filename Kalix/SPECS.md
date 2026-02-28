# Kalix

&nbsp;

## Introduction

We, at the company **Kalei**, are bulding **Kalix**: a Web micro framework written in PHP.

You, **Codex**, and me, **Paolo**, are Kalix lead developers.

Kalix is inspired by Fat Free Framework [Fat Free Framework](https://fatfreeframework.com/3.9/sql) but even more lightweight and minimal.

Kalix has nothing to do with **AKKA**'s Kalix PaaS, they only share the same name.

Kalix official website (still to be built) is www.kalixphp.com -- we will build the website later.

Kalix does not use Composer and has no dependencies.

Kalix is strictly typed.

Kalix is oriented to simplicity and performance.

&nbsp;

## Coding rules and principles

Kalix is written following modern coding styles and patterns, is PSR-12 compliant, uses strict types.

Kalix uses namespaces.

Make small functions, and make small files.

Before each function write a small, syntetic description of the function.

```
/*
 *  Function name
 *
 *  function description
 *  etc... etc...
 */
```
 
Inside each function write a commend always and only before key and/or non-obvious parts.

Leave three newlines between each function

&nbsp;

## Loading and bootstrapping

Kalix is loaded and bootstrapped with

`$kalix = require_once __DIR__ . "path/to/kalix";`

Kalix autoloads necessary files automatically.

&nbsp;

## Kalix (and project) directory structure

This is the project and Kalix dir structure:

...just inspect `/Users/administrator/www/kalei.com`

&nbsp;

## Routing and localization

Kalix is localized by default.

Routes are defined by files into `routes/` automatically loaded.

Locales are defined into `locale/`

Every routes begins with `/@lang`

The typical route pattern is

`/@lang/name-of-the-controller/@action/@param`

This route is mapped the the named controller; `@action` method is executed, `@param` is passed.

Only public methods can be called

If the language prefix is missing from the URL but the URL match a route then the user agent is redirected to a localized URL.

How user language preference is determined: when the URL is localized, then this is the user's language; the language is stored into the `lang` cookie. If the URL is not localized the the `lang` cookie is inspected. If the cookie is missing the language is determined by inspecting the connection header. If the language cannot be determined then it is assumed `en`. If the `en` locale is missing then the first locale in the `locale/` directory is assumed.

&nbsp;

## ORM Engine

Kalix uses mysqli.

Kalix make a single connection (per database, usually one) when the first "model" object is istantiated. Then connection is then reused until the script execution is over.

Kalix ships with a tool named `MakeMappers.php`: MakeMappers inspect the database schema and writes a mapper for each table and each view (a view is treated as a read-only table).

Each mapper extends the base mapper. Each model extends the corresponding mapper. Mappers source code must not be modified (since it is overwritten at the next rebuild),

The developer using Kalix must rebuild the mappers each time the database is modified.    

&nbsp;

### Type mappings:

Kalix's ORM engine maps mySql types to PHP types as follows:

* **int** and other numeric integer types to PHP's **int**
* **char** and other string/text types, plus **blob** to PHP's **string**
* **float** and **double** to PHP's **float**
* **bit** to PHP's **boolean**
* **date** and **datetime** to PHP's **Date Object**
* **json** to PHP's **Array**

The mySql types that ***should*** be used for each php type:

* int => signed bigint
* float => signed double
* boolean => bit
* string => char (or text when may exceed 255 chars in length) utf8_mb4

The `makemappers.php` tool raise a warning when a mysql type different than the one above is used.

The tool stores every detail about each table into comments in the source code of the corresponding mapper. Field-comments are also reported. This is achieved by storing the table schema dumps into a large comment at the beginning of each mapper. This serves a dual purpose: first, every table detail may be inspected by the developer just by looking at the mapper source code; second, the database may be rebuilt from scratch from the mappers source.

&nbsp;

## Views and rendering

From the controller the corresponding view is rendered by calling `$this->render( $params );`

The view filename is `controller` + `_` + `action` + `.php`

`$params` is a key-value pairs array of the values exported to the view, for example `[ 'name' => $name ]`

Every occurrence in the view of `{{name}}` will be replaced with the value of `$name`; Furthemore the variable `$name` will be available for access into the view.

The current locale tokens are accessible from the controller from `$this->intl[$label]`

All the tokens for the current locale are exported to the view. They are accessible from the array `$intl`. A token can be represented is represented into the view html with `|label|` e.g. the token's label between two pipe characters.

A variable is HTML-escaped when rendered into `{{variable}}`. It is exported RAW into `$variable`









