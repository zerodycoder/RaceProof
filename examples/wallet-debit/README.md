# Wallet debit: stale balance and inconsistent ledger

The executable endpoints are in [routes.php](routes.php) and are exercised on
MySQL and PostgreSQL by the repository database suite.

Starting state: balance 100, two concurrent debits of 80. The broken endpoint
reads the same balance in both workers and waits at `wallet-read`. Both ledger
entries are committed even though the last balance write hides one debit:

```text
broken: 201 x 2; balance = 20; ledger entries = 2; ledger total = 160
```

The fix makes the balance predicate and decrement one database operation:

```php
$claimed = DB::table('wallets')
    ->where('id', 1)
    ->where('balance', '>=', 80)
    ->decrement('balance', 80);
```

A regression test runs `/api/wallet/fixed`, releases `wallet-claim`, expects
one `201` and one `409`, then asserts balance 20 and ledger total 80.

```text
fixed: 201 x 1; 409 x 1; balance = 20; ledger entries = 1; ledger total = 80
```

Money examples need both the account and ledger invariant. A plausible final
balance by itself can conceal a double side effect. The executable fix commits
the balance claim and ledger entry in one transaction.
