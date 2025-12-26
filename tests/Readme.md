Testing notes

How to run the customer feature tests:

- Run all tests:
  php artisan test

- Run only the CustomerResourceTest (faster):
  php artisan test --filter CustomerResourceTest

What the tests do:
- `test_admin_sees_user_id_and_kpi_id` : verifies administrators receive `user_id` and `kpi_id` in the resource
- `test_sales_does_not_see_user_id_and_kpi_id_when_fetching_customer`: verifies non-owner sales cannot see another sales' customer (403) and an owner sales sees the customer but without `user_id`/`kpi_id`
- `test_index_shows_user_id_kpi_id_only_for_admins`: verifies the listing includes sensitive fields only for admins

Notes / gotchas:
- Tests create minimal tables (users, customers) using Schema::create instead of running the full migration set — this avoids SQLite incompatibilities with some migrations during CI/local tests.
- `Laravel\Sanctum::actingAs($user)` is used to authenticate in tests without creating real tokens.

If you want, I can:
- Add factories for `Customer` and expand tests
- Add CI job config to run tests on push
