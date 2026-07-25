# Overselling: stale stock check

The executable broken and fixed endpoints are in [routes.php](routes.php). The
regular integration suite runs them with three independent Laravel processes,
and the MySQL/PostgreSQL suite runs the same file with two processes.

With stock set to one, three worker processes read the same value and rendezvous at `oversell-read`. The broken implementation checks the stale value and then decrements without a conditional write:

```php
$stock = (int) DB::table('products')->where('id', 1)->value('stock');
race_point('oversell-read');

if ($stock < 1) {
    abort(409);
}

DB::table('products')->where('id', 1)->decrement('stock');
DB::table('orders')->insert(['product_id' => 1]);
```

Observed broken:

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

Observed fixed:

```text
201 responses: 1
409 responses: 2
orders:        1
stock:         0
```

The regression test targets `/api/oversell/fixed`, releases `oversell-claim`,
expects one `201` and the remaining responses to be `409`, then asserts one
order and stock zero. The executable route wraps the conditional claim and order
insert in one transaction. HTTP counts alone are not the invariant.

The SQLite fixture keeps the fast package test self-contained. The release
evidence for this example comes from the same routes on MySQL 8.4 and
PostgreSQL 17.
