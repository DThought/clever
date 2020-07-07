Cleverly
========

A tiny, fast, and easy HTML template engine


Installation
------------

The easiest way to use Cleverly is simply to clone the repository (such as with
a [git submodule](https://git-scm.com/docs/git-submodule)) and
[include](https://www.php.net/manual/en/function.include.php) or
[require](https://www.php.net/manual/en/function.require.php) it in your code.
No fancy package managers here.


Quick Start
-----------

index.php:

    <?php
    include(__DIR__ . '/Cleverly.class.php');

    $cleverly = new Cleverly();
    $cleverly->setTemplateDir(__DIR__ . '/templates');
    $cleverly->display('index.tpl', array('greeting' => 'Hello'));
    ?>

templates/index.tpl:

    <!DOCTYPE html>
    <html xmlns="http://www.w3.org/1999/xhtml" lang="en">
      <body>
        <p>{$greeting}, world!</p>
      </body>
    </html>

In general, Cleverly provides the same syntax, structure, and features as
[Smarty](https://www.smarty.net/), an older and heavier template engine. (In
fact, one of the main reasons Cleverly was written at all was to provide a
single-file alternative to Smarty.) As such, the extensive [Smarty 2 usage
documentation](https://www.smarty.net/docsv2/en/) is a good reference.

Check out [Hell Quotes](https://github.com/deeptoaster/hell-quotes) for an
example of a dynamic project that uses Cleverly.


Template Directives
-------------------

*   {**foreach** from=*from* item=*item*} ... {/**foreach**}

    {**foreach** loop=*loop* item=*item*} ... {/**foreach**}

    Either loops over an array of data (*from*) or just loops a certain number
    of times (*loop*). The current value being looped over is stored into a
    variable called *item*. This directive corresponds to both
    [`foreach`](https://www.smarty.net/docsv2/en/language.function.foreach.tpl)
    and
    [`section`](https://www.smarty.net/docsv2/en/language.function.section.tpl)
    in Smarty 2. The content between the opening and closing `foreach` tags is
    outputted once for each iteration of the loop.

*   {**include** file=*file*}

    {**include** from=*from*}

    Either includes another template (*file*) or the result of a function
    stored to a variable (*from*). If *file* is provided, it should either be a
    plain string delimited by single quotes or a variable containing the path
    to include. This directive corresponds to
    [`include`](https://www.smarty.net/docsv2/en/language.function.include.tpl)
    in Smarty 2, as well as its concept of plugins. All variables and
    configurations that apply to the current template also apply to the
    included template.

*   {**include_php** file=*file*}

    Includes the output of a PHP script. *file* should either be a plain string
    delimited by single quotes or a variable containing the path to include.
    This directive corresponds to
    [`include_php`](https://www.smarty.net/docs/en/language.function.include.php.tpl)
    in Smarty 2.

*   {**ldelim**}

    Outputs the left delimiter (the value of `leftDelimiter`, which defaults to
    `"{"`). This directive corresponds to
    [`ldelim`](https://www.smarty.net/docs/en/language.function.ldelim.tpl) in
    Smarty 2. This is basically a way to escape the template delimiter in the
    output.

*   {**literal**} ... {/**literal**}

    Outputs the content between the opening and closing `literal` tags without
    any further processing. This directive corresponds to
    [`literal`](https://www.smarty.net/docs/en/language.function.literal.tpl)
    in Smarty 2.

*   {**php**} ... {/**php**}

    Executes the content between the opening and closing `php` tags as PHP
    code. This directive corresponds to
    [`php`](https://www.smarty.net/docs/en/language.function.php.tpl) in Smarty
    2.

*   {**rdelim**}

    Outputs the right delimiter (the value of `rightDelimiter`, which defaults
    to `"}"`). This directive corresponds to
    [`rdelim`](https://www.smarty.net/docs/en/language.function.rdelim.tpl) in
    Smarty 2. This is basically a way to escape the template delimiter in the
    output.

*   {*$var*}

    Outputs the contents of variable *$var*.


Template Variables
------------------

In general, whenever a variable is called for---whether printing it directly or
using it as a parameter to `foreach`, `include`, or `include_php`---you should
provide a standard variable name (a string of letters, numbers, or
underscores), possibly with an index (to fetch a specific element in an array).
The only exception is the *item* parameter on `foreach`, which does not permit
an index.

The following are all valid invocations, assuming the variables are defined:

    {foreach from=array_var item=element_var} ... {/foreach}
    {foreach from=array_of_arrays_var.key item=element_var} ... {/foreach}
    {foreach from=array_of_arrays_var[42] item=element_var} ... {/foreach}
    {include file='file_name.tpl'}
    {include file=string_var}
    {include file=array_of_strings_var.key}
    {include file=array_of_strings_var[42]}
    {include from=string_var}
    {include from=array_of_strings_var.key}
    {include from=array_of_strings_var[42]}
    {include_php file='file_name.tpl'}
    {include_php file=string_var}
    {include_php file=array_of_strings_var.key}
    {include_php file=array_of_strings_var[42]}
    {$string_var}
    {$array_of_strings_var.key}
    {$array_of_strings_var[42]}

Variables are defined in one of two ways: they are either passed in through the
*$vars* array when calling `display` or `fetch` or assigned to *item* as part
of a `foreach` loop.


Plugins
-------

Plugins are a special type of template variable assigned an [anonymous
function](https://www.php.net/manual/en/functions.anonymous.php) and used with
`include`. The following example illustrates a plugin that displays the current
time.

index.php:

    <?php
    include(__DIR__ . '/Cleverly.class.php');

    $get_date = function() {
      return strftime('%c');
    }

    $cleverly = new Cleverly();
    $cleverly->setTemplateDir(__DIR__ . '/templates');
    $cleverly->display('index.tpl', array('get_date' => $get_date));
    ?>

templates/index.tpl:

    <!DOCTYPE html>
    <html xmlns="http://www.w3.org/1999/xhtml" lang="en">
      <body>
        <p>The current time is {include from=get_date}</p>
      </body>
    </html>


Class Methods
-------------

*   **addTemplateDir**(*$dir*, *$key=null*)

    Adds one (if *$dir* is a string) or more (if *$dir* is an array) paths to
    the list of template directories. If *$key* is provided, it is associated
    with a single *$dir* for the purposes of the `getTemplateDir` method.
    Similarly, if *$dir* is an associative array, each key is associated with
    each path provided. This method corresponds to
    [`addTemplateDir`](https://www.smarty.net/docs/en/api.add.template.dir.tpl)
    in Smarty 3.

*   **display**(*$template*, *$vars=array()*)

    Displays the output of the template *$template*. If *$template* starts with
    the special protocol `"string:"`, the remainder of *$template* is treated
    as the contents of the template to import. Otherwise, it treats *$template*
    as a path (either local or remote) to the template to use. Keys in the
    optional associative array *$vars* provides a list of variable names and
    values (as key--value pairs) which can be used in the template (or any
    included templates). This method corresponds to
    [`display`](https://www.smarty.net/docs/en/api.display.tpl) in Smarty 3.

*   **fetch**(*$template*, *$vars=array()*)

    Returns the output of the template *$template* as a string. If *$template*
    starts with the special protocol `"string:"`, the remainder of *$template*
    is treated as the contents of the template to import. Otherwise, it treats
    *$template* as a path (either local or remote) to the template to use. Keys
    in the optional associative array *$vars* provides a list of variable names
    and values (as key--value pairs) which can be used in the template (or any
    included templates). This method corresponds to
    [`fetch`](https://www.smarty.net/docs/en/api.fetch.tpl) in Smarty 3.

*   **getTemplateDir**(*$key=null)*

    Returns the list of known template directories (if *$key* is not provided)
    or a single template directory associated with a key (if *$key* is
    provided). This method corresponds to
    [`getTemplateDir`](https://www.smarty.net/docs/en/api.get.template.dir.tpl)
    in Smarty 3.

*   **setTemplateDir**(*$dir*)

    Sets the list of known template directories to one (if *$dir* is a string)
    or more (if *$dir* is an array) template directories. If *$dir* is an
    associative array, each key is associated with each path provided for the
    purposes of the `getTemplateDir` method. The `display` and `fetch` methods,
    as well as the `include` directive, look for files under known template
    directories. By default, the only template directory is ./templates. This
    method corresponds to
    [`setTemplateDir`](https://www.smarty.net/docs/en/api.set.template.dir.tpl)
    in Smarty 3.


Class Variables
---------------

*   **leftDelimiter**

    The left delimiter used by the template language, which defaults to `"{"`.

*   **preserveIndent**

    Whether or not to accumulate indentation in variable expansions and
    included files. This is basically a pretty-printing toggle.

*   **rightDelimiter**

    The right delimiter used by the template language, which defaults to `"}"`.
