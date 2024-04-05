# Ray.MediaQuery

## Media access mapping framework
[![codecov](https://codecov.io/gh/ray-di/Ray.MediaQuery/branch/1.x/graph/badge.svg?token=QBOPCUPJQV)](https://codecov.io/gh/ray-di/Ray.MediaQuery)
[![Type Coverage](https://shepherd.dev/github/ray-di/Ray.MediaQuery/coverage.svg)](https://shepherd.dev/github/ray-di/Ray.MediaQuery)
![Continuous Integration](https://github.com/ray-di/Ray.MediaQuery/workflows/Continuous%20Integration/badge.svg)

[日本語 (Japanese)](./README.ja.md)

## Overview

`Ray.QueryModule` makes a query to an external media such as a database or Web API with a function object to be injected.

## Motivation

 * You can have a clear boundary between domain layer (usage code) and infrastructure layer (injected function) in code.
 * Execution objects are generated automatically so you do not need to write procedural code for execution.
 * Since usage codes are indifferent to the actual state of external media, storage can be changed later. Easy parallel development and stabbing.

## Composer install

    $ composer require ray/media-query

## Getting Started

Define the interface for media access.

### DB

Specify the SQL ID with the attribute `DbQuery`.

```php
interface TodoAddInterface
{
    #[DbQuery('user_add')]
    public function add(string $id, string $title): void;
}
```

### Web API

Specify the Web request ID with the attribute `WebQuery`.

```php
interface PostItemInterface
{
    #[WebQuery('user_item')]
    public function get(string $id): array;
}
```

Create the web api path list file as `web_query.json`.

```json
{
    "$schema": "https://ray-di.github.io/Ray.MediaQuery/schema/web_query.json",
    "webQuery": [
        {"id": "user_item", "method": "GET", "path": "https://{domain}/users/{id}"}
    ]
}
```

### Module

MediaQueryModule binds the execution of SQL and Web API requests to an interface by setting `DbQueryConfig` or `WebQueryConfig` or both.

```php
use Ray\AuraSqlModule\AuraSqlModule;
use Ray\MediaQuery\ApiDomainModule;
use Ray\MediaQuery\DbQueryConfig;
use Ray\MediaQuery\MediaQueryModule;
use Ray\MediaQuery\Queries;
use Ray\MediaQuery\WebQueryConfig;

protected function configure(): void
{
    $this->install(
        new MediaQueryModule(
            Queries::fromDir('/path/to/queryInterface'),[
                new DbQueryConfig('/path/to/sql'),
                new WebQueryConfig('/path/to/web_query.json', ['domain' => 'api.example.com'])
            ],
        ),
    );
    $this->install(new AuraSqlModule('mysql:host=localhost;dbname=test', 'username', 'password'));
}
```

Note: MediaQueryModule requires AuraSqlModule to be installed.

### Request object injection

You do not need to prepare an implementation class. It is generated and injected from the interface.

```php
class Todo
{
    public function __construct(
        private TodoAddInterface $todoAdd
    ) {}

    public function add(string $id, string $title): void
    {
        $this->todoAdd->add($id, $title);
    }
}
```

### DbQuery

When the method is called, the SQL specified by the ID is bound with the method argument and executed.
For example, if the ID is `todo_item`, the `todo_item.sql` SQL statement is bound with `['id => $id]` and executed.

```php
interface TodoItemInterface
{
    #[DbQuery('todo_item', type: 'row')]
    public function item(string $id): array;

    #[DbQuery('todo_list')]
    /** @return array<Todo> */
    public function list(string $id): array;
}
```

* If the result is a `row`(`array<string, scalar>`), specify `type:'row'`. The type is not necessary for `row_list`(`array<int, array<string, scalar>>`).
* SQL files can contain multiple SQL statements. In that case, the return value is the last line of the SELECT.

#### Entity

When the return value of a method is an entity class, the result of the SQL execution is hydrated.

```php
interface TodoItemInterface
{
    #[DbQuery('todo_item')]
    public function item(string $id): Todo;

    #[DbQuery('todo_list')]
    /** @return array<Todo> */
    public function list(string $id): array;
}
```

```php
final class Todo
{
    public readonly string $id;
    public readonly string $title;
}
```

Use `CameCaseTrait` to convert a property to camelCase.

```php
use Ray\MediaQuery\CamelCaseTrait;

class Invoice
{
    use CamelCaseTrait;

    public $userName;
}
```

If the entity has a constructor, the constructor will be called with the fetched data.

```php
final class Todo
{
    public function __construct(
        public readonly string $id,
        public readonly string $title
    ) {}
}
```

#### Entity factory

To create an entity with a factory class, specify the factory class in the `factory` attribute.

```php
interface TodoItemInterface
{
    #[DbQuery('todo_item', factory: TodoEntityFactory::class)]
    public function item(string $id): Todo;

    #[DbQuery('todo_list', factory: TodoEntityFactory::class)]
    /** @return array<Todo> */
    public function list(string $id): array;
}
```

The `factory` method of the factory class is called with the fetched data. You can also change the entity depending on the data.

```php
final class TodoEntityFactory
{
    public static function factory(string $id, string $name): Todo
    {
        return new Todo($id, $name);
    }
}
```

If the factory method is not static, the factory class dependency resolution is performed.

```php
final class TodoEntityFactory
{
    public function __construct(
        private HelperInterface $helper
    ){}
    
    public function factory(string $id, string $name): Todo
    {
        return new Todo($id, $this->helper($name));
    }
}
```

#### Web API

* Customization such as header for authentication is done by binding Guzzle's `ClinetInterface`.

```php
$this->bind(ClientInterface::class)->toProvider(YourGuzzleClientProvicer::class);
```

## Parameters

### DateTime

You can pass a value object as a parameter.
For example, you can specify a `DateTimeInterface` object like this.

```php
interface TaskAddInterface
{
    #[DbQuery('task_add')]
    public function __invoke(string $title, DateTimeInterface $cratedAt = null): void;
}
```

The value will be converted to a date formatted string at SQL execution time or Web API request time.

```sql
INSERT INTO task (title, created_at) VALUES (:title, :createdAt); # 2021-2-14 00:00:00
```

If no value is passed, the bound current time will be injected.
This eliminates the need to hard-code `NOW()` inside SQL and pass the current time every time.

### Test clock

When testing, you can also use a single time binding for the `DateTimeInterface`, as shown below.

```php
$this->bind(DateTimeInterface::class)->to(UnixEpochTime::class);
```

## VO

If a value object other than `DateTime` is passed, the return value of the `toScalar()` method that implements the `ToScalar` interface or the `__toString()` method will be the argument.

```php
interface MemoAddInterface
{
    #[DbQuery('memo_add')]
    public function __invoke(string $memo, UserId $userId = null): void;
}
```

```php
class UserId implements ToScalarInterface
{
    public function __construct(
        private LoginUser $user;
    ){}
    
    public function toScalar(): int
    {
        return $this->user->id;
    }
}
```

```sql
INSERT INTO memo (user_id, memo) VALUES (:userId, :memo);
```

### Parameter Injection

Note that the default value of `null` for the value object argument is never used in SQL. If no value is passed, the scalar value of the value object injected with the parameter type will be used instead of null.

```php
public function __invoke(Uuid $uuid = null): void; // UUID is generated and passed.
````

## Pagenation

The `#[Pager]` annotation allows paging of SELECT queries.

```php
use Ray\MediaQuery\PagesInterface;

interface TodoList
{
    #[DbQuery('todo_list'), Pager(perPage: 10, template: '/{?page}')]
    public function __invoke(): PagesInterface;
}
```

You can get the number of pages with `count()`, and you can get the page object with array access by page number.
`Pages` is a SQL lazy execution object.

The number of items per page is specified by `perPage`, but for dynamic values, specify a string with the name of the argument representing the number of pages as follows

```php
    #[DbQuery('todo_list'), Pager(perPage: 'pageNum', template: '/{?page}')]
    public function __invoke($pageNum): Pages;
```

```php
$pages = ($todoList)();
$cnt = count($page); // When count() is called, the count SQL is generated and queried.
$page = $pages[2]; // A page query is executed when an array access is made.

// $page->data // sliced data
// $page->current;
// $page->total
// $page->hasNext
// $page->hasPrevious
// $page->maxPerPage;
// (string) $page // pager html
```

Use `@return` to specify hydration to the entity class.

```php
    #[DbQuery('todo_list'), Pager(perPage: 'pageNum', template: '/{?page}')]
    /** @return array<Todo> */
    public function __invoke($pageNum): Pages;
```

# SqlQuery

`SqlQuery` executes SQL by specifying the ID of the SQL file.
It is used when detailed implementations with an implementation class.

```php
class TodoItem implements TodoItemInterface
{
    public function __construct(
        private SqlQueryInterface $sqlQuery
    ){}

    public function __invoke(string $id) : array
    {
        return $this->sqlQuery->getRow('todo_item', ['id' => $id]);
    }
}
```

## Get* Method

To get the SELECT result, use `get*` method depending on the result you want to get.

```php
$sqlQuery->getRow($queryId, $params); // Result is a single row
$sqlQuery->getRowList($queryId, $params); // result is multiple rows
$statement = $sqlQuery->getStatement(); // Retrieve the PDO Statement
$pages = $sqlQuery->getPages(); // Get the pager
```

Ray.MediaQuery contains the [Ray.AuraSqlModule](https://github.com/ray-di/Ray.AuraSqlModule).
If you need more lower layer operations, you can use Aura.Sql's [Query Builder](https://github.com/ray-di/Ray.AuraSqlModule#query-builder) or [Aura.Sql](https://github.com/auraphp/Aura.Sql) which extends PDO.
[doctrine/dbal](https://github.com/ray-di/Ray.DbalModule) is also available.

## Profiler

Media accesses are logged by a logger. By default, a memory logger is bound to be used for testing.

```php
public function testAdd(): void
{
    $this->sqlQuery->exec('todo_add', $todoRun);
    $this->assertStringContainsString('query: todo_add({"id": "1", "title": "run"})', (string) $this->log);
}
```

Implement your own [MediaQueryLoggerInterface](src/MediaQueryLoggerInterface.php) and run
You can also implement your own [MediaQueryLoggerInterface](src/MediaQueryLoggerInterface.php) to benchmark each media query and log it with the injected PSR logger.

## Annotations / Attributes

You can use either [doctrine annotations](https://github.com/doctrine/annotations/) or [PHP8 attributes](https://www.php.net/manual/en/language.attributes.overview.php) can both be used. 
The next two are the same.

```php
use Ray\MediaQuery\Annotation\DbQuery;

#[DbQuery('user_add')]
public function add1(string $id, string $title): void;

/** @DbQuery("user_add") */
public function add2(string $id, string $title): void;
```

## Testing Ray.MediaQuery

Here's how to install Ray.MediaQuery from the source and run the unit tests and demos.

```
$ git clone https://github.com/ray-di/Ray.MediaQuery.git
$ cd Ray.MediaQuery
$ composer tests
$ php demo/run.php
```
