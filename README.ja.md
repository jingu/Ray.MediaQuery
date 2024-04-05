# Ray.MediaQuery

## 概要

`Ray.MediaQuery`はDBやWeb APIなどの外部メディアのクエリーのインターフェイスから、クエリー実行オブジェクトを生成しインジェクトします。

## モチベーション

* ドメイン層とインフラ層の境界を明確にします。
* ボイラープレートコードを削減します。
* 外部メディアの実体には無関係なので、後からストレージを変更することができます。並列開発やスタブ作成が容易です。

## インストール

    $ composer require ray/media-query

## 利用方法

メディアアクセスするインターフェイスを定義します。

### データベースの場合

`DbQuery`属性でSQLのIDを指定します。

```php
interface TodoAddInterface
{
    #[DbQuery('user_add')]
    public function add(string $id, string $title): void;
}
```

### Web APIの場合

`WebQuery`属性でWeb APIのIDを指定します。

```php
interface PostItemInterface
{
    #[WebQuery('user_item')]
    public function item(string $id): Post;
}
```

APIパスリストのファイルを`media_query.json`として作成します。

```json
{
    "$schema": "https://ray-di.github.io/Ray.MediaQuery/schema/web_query.json",
    "webQuery": [
        {"id": "user_item", "method": "GET", "path": "https://{domain}/users/{id}"}
    ]
}
```

MediaQueryModuleは、`DbQueryConfig`や`WebQueryConfig`、またはその両方の設定でSQLやWeb APIリクエストの実行をインターフェイスに束縛します。

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

注) MediaQueryModuleはAuraSqlModuleのインストールが必要です。

### 注入

実装クラスをコーディングすることなく、インターフェイスからクエリー実行オブジェクトが生成されインジェクトされます。

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

メソッドをコールするとIDで指定されたSQLをメソッドの引数でバインドして実行します。
例えばIDが`todo_item`の指定では`todo_item.sql`SQL文を`['id => $id]`でバインドして実行します。

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

* 結果が `row`(`array<string, scalar>`)の場合は`type:'row'`を指定します。`row_list`(`array<int, array<string, scalar>>`)にはtype指定は不要です。
* SQLファイルには複数のSQL文が記述できます。その場合には最後の行のSELECTが戻り値になります。

#### Entity

メソッドの戻り値をエンティティクラスにするとSQL実行結果がハイドレートされます。

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

プロパティをキャメルケースに変換する場合には`CameCaseTrait`を使います。

```php
use Ray\MediaQuery\CamelCaseTrait;

class Invoice
{
    use CamelCaseTrait;

    public $userName;
}
```

エンティティにコンストラクタがあると、フェッチしたデータでコールされます。

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

ファクトリークラスでエンティティを生成するには`factory`属性ででファクトリークラスを指定します。

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

ファクトリークラスの`factory`メソッドがフェッチしたデータでコールされます。データに応じてエンティティを変えることもできます。

```php
final class TodoEntityFactory
{
    public static function factory(string $id, string $name): Todo
    {
        return new Todo($id, $name);
    }
}
```

ファクトリーメソッドがstaticでない場合はfactoryクラスの依存解決が行われます。

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

### Web API

* メソッドの引数が `uri`で指定されたURI templateにバインドされ、Web APIリクエストオブジェクトが生成されます。
* 認証のためのヘッダーなどのカスタムはGuzzleの`ClinetInterface`をバインドして行います。

```php
$this->bind(ClientInterface::class)->toProvider(YourGuzzleClientProvicer::class);
```

## パラメーター

### 日付時刻

パラメーターにバリューオブジェクトを渡すことができます。
例えば、`DateTimeInterface`オブジェクトをこのように指定できます。

```php
interface TaskAddInterface
{
    #[DbQuery('task_add')]
    public function __invoke(string $title, DateTimeInterface $cratedAt = null): void;
}
```

値はSQL実行時やWeb APIリクエスト時に日付フォーマットされた文字列に変換されます。

```sql
INSERT INTO task (title, created_at) VALUES (:title, :createdAt); # 2021-2-14 00:00:00
```

値を渡さないとバインドされている現在時刻がインジェクションされます。
SQL内部で`NOW()`とハードコーディングする事や、毎回現在時刻を渡す手間を省きます。

### テスト時刻
テストの時には以下のように`DateTimeInterface`の束縛を１つの時刻にする事もできます。

```php
$this->bind(DateTimeInterface::class)->to(UnixEpochTime::class);
```

### VO

`DateTime`以外のバリューオブジェクトが渡されると`ToScalar`インターフェイスを実装した`toScalar()`メソッド、もしくは`__toString()`メソッドの返り値が引数になります。

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

### パラメーターインジェクション

バリューオブジェクトの引数のデフォルトの値の`null`がSQLやWebリクエストで使われることは無い事に注意してください。値が渡されないと、nullの代わりにパラメーターの型でインジェクトされたバリューオブジェクトのスカラー値が使われます。

```php
public function __invoke(Uuid $uuid = null): void; // UUIDが生成され渡される
```

## ページネーション

DBの場合、`#[Pager]`属性でSELECTクエリーをページングする事ができます。

```php
use Ray\MediaQuery\PagesInterface;

interface TodoList
{
    #[DbQuery('todo_list'), Pager(perPage: 10, template: '/{?page}')]
    public function __invoke(): Pages;
}
```

ページ毎のアイテム数をperPageで指定しますが、動的な値の場合は以下のようにページ数を表す引数の名前を文字列を指定します。
```php
    #[DbQuery('todo_list'), Pager(perPage: 'pageNum', template: '/{?page}')]
    public function __invoke($pageNum): Pages;
```

`count()`で件数が取得でき、ページ番号で配列アクセスをするとページオブジェクトが取得できます。
`Pages`はSQL遅延実行オブジェクトです。

```php
$pages = ($todoList)();
$cnt = count($page); // count()をした時にカウントSQLが生成されクエリーが行われます。
$page = $pages[2]; // 配列アクセスをした時にそのページのDBクエリーが行われます。

// $page->data // sliced data
// $page->current;
// $page->total
// $page->hasNext
// $page->hasPrevious
// $page->maxPerPage;
// (string) $page // pager html
```

エンティティクラスにハイドレーションを行うときは`@return`で指定します。

```php
    #[DbQuery('todo_list'), Pager(perPage: 'pageNum', template: '/{?page}')]
    /** @return array<Todo> */
    public function __invoke($pageNum): Pages;
```
## SqlQuery

`SqlQuery`はSQLファイルのIDを指定してSQLを実行します。
実装クラスを用意して詳細な実装を行う時に使用します。

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

## Get* メソッド

SELECT結果を取得するためには取得する結果に応じた`get*`を使います。

```php
$sqlQuery->getRow($queryId, $params); // 結果が単数行
$sqlQuery->getRowList($queryId, $params); // 結果が複数行
$statement = $sqlQuery->getStatement(); // PDO Statementを取得
$pages = $sqlQuery->getPages(); // ページャーを取得
```

Ray.MediaQueryは[Ray.AuraSqlModule](https://github.com/ray-di/Ray.AuraSqlModule) を含んでいます。
さらに低レイヤーの操作が必要な時はAura.Sqlの[Query Builder](https://github.com/ray-di/Ray.AuraSqlModule#query-builder) やPDOを拡張した[Aura.Sql](https://github.com/auraphp/Aura.Sql) のExtended PDOをお使いください。
[doctrine/dbal](https://github.com/ray-di/Ray.DbalModule) も利用できます。


Parameter Injectionと同様、`DateTimeIntetface`オブジェクトを渡すと日付フォーマットされた文字列に変換されます。

```php
$sqlQuery->exec('memo_add', ['memo' => 'run', 'created_at' => new DateTime()]);
```

他のオブジェクトが渡されると`toScalar()`または`__toString()`の値に変換されます。


## プロファイラー

メディアアクセスはロガーで記録されます。標準ではテストに使うメモリロガーがバインドされています。

```php
public function testAdd(): void
{
    $this->sqlQuery->exec('todo_add', $todoRun);
    $this->assertStringContainsString('query: todo_add({"id":"1","title":"run"})', (string) $this->log);
}
```

独自の[MediaQueryLoggerInterface](src/MediaQueryLoggerInterface.php)を実装して、
各メディアクエリーのベンチマークを行ったり、インジェクトしたPSRロガーでログをする事もできます。

## アノテーション / アトリビュート

属性を表すのに[doctrineアノテーション](https://github.com/doctrine/annotations/) 、[アトリビュート](https://www.php.net/manual/ja/language.attributes.overview.php) どちらも利用できます。 次の2つは同じものです。

```php
use Ray\MediaQuery\Annotation\DbQuery;

#[DbQuery('user_add')]
public function add1(string $id, string $title): void;

/** @DbQuery("user_add") */
public function add2(string $id, string $title): void;
```

## Demo

テストとデモを行うためには以下のようにします。

```
$ git clone https://github.com/ray-di/Ray.MediaQuery.git
$ cd Ray.MediaQuery
$ composer tests
$ php demo/run.php
```
