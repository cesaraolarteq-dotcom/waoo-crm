# Test database reuse — step 2

Use Laravel's RefreshDatabase for single-process behavior/UI tests, retaining every test and assertion.
The initial migrate:fresh is reused while successive tests roll back their own outer transaction.
DatabaseMigrations remains mandatory for actual forked workers and DDL/migration roundtrips.

Mixed runs are supported by Laravel: DatabaseMigrations' teardown clears RefreshDatabaseState::$migrated;
the next transaction fixture rebuilds the schema. No application migrations or evidence retention guards
are changed. Each of the five CI shards still owns a separate MariaDB. File assignment is unchanged in
principle: every file belongs to exactly one shard; new files are included automatically.

Review candidate classes and their supporting traits for worker creation, independent connections,
DDL, explicit transaction termination, after-commit behavior, and production entry points that explicitly require transactionLevel() === 0 before changing their fixture.
Run converted tests together with an unchanged real concurrency class to exercise mixed setup/teardown.
Do not claim whole-suite success based on a subset or a local speedup as a new CI wall-time guarantee.

QuickBook intentionally refuses an enclosing transaction. LeadScreensTest, QuickDetailsFlowTest and
QuickBookingCostumeHoldPanelTest therefore retain DatabaseMigrations even without forks. The application
guard and their assertions are unchanged; never bypass the guard merely to speed up the tests.

Local controlled comparison (same PHP 8.3.35/MariaDB 11.8, same eight cases and 47 assertions):
The three original BookingAnalyticsTest cases + the five original ReportsPageTest cases took 18.950 s with DatabaseMigrations,
and 3.933 s after conversion. This is a local sample, not a promise about the five-shard CI duration.
The new analytics screen case is excluded from this timing comparison so the populations are identical.
