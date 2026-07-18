# Overselling demonstration

The executable version lives in `tests/Fixtures/overselling-app` and is exercised by `OversellingDemoTest`.

With stock set to one, three worker processes read the same value and rendezvous at `stock-read`. The broken implementation checks the stale value and then decrements without a conditional write:

```php
$product = DB::table('products')->find(1);
race_point('stock-read');

if ($product->stock < 1) {
    abort(409);
}

DB::table('products')->where('id', 1)->decrement('stock');
DB::table('orders')->insert(['product_id' => 1]);
```

Observed invariant violation:

```text
201 responses: 3
orders:        3
stock:        -2
```

The demonstration fix claims stock with one conditional atomic update:

```php
$claimed = DB::table('products')
    ->where('id', 1)
    ->where('stock', '>', 0)
    ->decrement('stock');

if ($claimed === 0) {
    abort(409);
}
```

Observed fixed state:

```text
201 responses: 1
409 responses: 2
orders:        1
stock:         0
```

The repository fixture uses file-backed SQLite only to keep the package mechanics self-contained. Real application validation must run against its MySQL/MariaDB or PostgreSQL engine.
