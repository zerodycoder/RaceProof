# Quote acceptance: duplicate one-time transition

The executable endpoints are in [routes.php](routes.php) and are exercised on
MySQL and PostgreSQL by the repository database suite.

Starting state: one pending quote and no acceptance record. The broken endpoint
reads `pending`, waits at `quote-read`, and lets both workers create an
acceptance:

```text
broken: 201 x 2; quote status = accepted; acceptances = 2
```

The fix claims the state transition conditionally:

```php
$claimed = DB::table('quotes')
    ->where('id', 1)
    ->where('status', 'pending')
    ->update(['status' => 'accepted']);
```

A regression test runs `/api/quote/fixed`, releases `quote-claim`, expects one
`201` and one `409`, then asserts the accepted state and one acceptance record.

```text
fixed: 201 x 1; 409 x 1; quote status = accepted; acceptances = 1
```

The executable fix wraps the state update and acceptance side effect in one
transaction. The conditional update prevents duplicate claims; the transaction
keeps the acceptance record consistent if a later write fails.
