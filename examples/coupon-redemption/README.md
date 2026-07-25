# Coupon redemption: stale remaining-use check

The executable endpoints are in [routes.php](routes.php) and are exercised on
MySQL and PostgreSQL by the repository database suite.

Starting state: one coupon with one remaining use and no redemptions. The
broken endpoint reads the remaining count, waits at `coupon-read`, and then
both participants decrement and insert:

```text
broken: 201 x 2; remaining_uses = -1; redemptions = 2
```

The fix uses one conditional decrement as the claim:

```php
$claimed = DB::table('coupons')
    ->where('id', 1)
    ->where('remaining_uses', '>', 0)
    ->decrement('remaining_uses');
```

A regression test runs `/api/coupon/fixed`, releases `coupon-claim`, expects
one `201` and one `409`, then asserts `remaining_uses === 0` and exactly one
redemption.

```text
fixed: 201 x 1; 409 x 1; remaining_uses = 0; redemptions = 1
```

The database condition is the correctness boundary. A preceding application
`exists()` or count check cannot make a later write atomic. The executable fix
wraps the claim and redemption insert in one transaction.
