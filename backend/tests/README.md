# Testing rules

## 1. A test must be SEEN failing before it is accepted

Write the test, run it against the unfixed code, and confirm it fails **for the
reason you intend**. Only then fix the code and confirm it passes.

A test nobody has watched fail is not a test. It is a claim that something is
tested, and that claim is worse than no test at all, because the green result
is read as evidence.

This is not hypothetical here. `NoSurfaceReturns500Test` was written to catch a
whole class of fault — surfaces that 500 under sqlite and are therefore never
exercised. Its first version **passed while reaching no controller at all**:

- `Route::gatherMiddleware()` returns the middleware ALIAS, `auth:admin-panel`,
  not the resolved class `Illuminate\Auth\Middleware\Authenticate:admin-panel`.
- The matcher looked for the class name, matched nothing, and every route fell
  through to the unauthenticated actor.
- The walk asserted **76 x HTTP 401** — no controller body ever ran — and
  reported PASS.

The test written to stop "silence that looks green" was doing exactly that. It
was caught only by reintroducing a known-bad expression and being surprised the
suite stayed green. Had that step been skipped, the repo would carry a test that
proves nothing while looking like coverage.

How to do it, concretely:

```bash
# 1. break it on purpose (or check out the code before the fix)
# 2. run — the test MUST fail, and the message must name the real cause
docker exec -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: -e CACHE_STORE=array \
  -e SESSION_DRIVER=array -e QUEUE_CONNECTION=sync \
  mamsa_backend php artisan test --filter=YourTest
# 3. restore, run again — now it passes
```

If a test cannot be made to fail, it is asserting something that was already
true by construction, and it is testing nothing.

## 2. Running the suite

`RefreshDatabase` will wipe whatever database the container is pointed at, so
the connection is forced explicitly on every run:

```bash
docker exec -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: -e CACHE_STORE=array \
  -e SESSION_DRIVER=array -e QUEUE_CONNECTION=sync \
  mamsa_backend php artisan test
```

Never run it without those overrides. Without them the dev MySQL database is
the target and `RefreshDatabase` drops it.

## 3. Portable SQL only

Production is MySQL; the suite is sqlite. A MySQL-only construct does not fail
loudly — the endpoint throws, and an endpoint with no test is simply never
called, so the suite stays green while the surface is unverified.

Use `App\Support\Sql` (`ym`, `dayOfWeek`, `sumNights`, `avgDays`, `avgHours`)
rather than raw `DATE_FORMAT`, `DAYOFWEEK`, `DATEDIFF` or `TIMESTAMPDIFF`, and
never write `HAVING` without a `GROUP BY` — MySQL permits it, sqlite rejects it.

`NoSurfaceReturns500Test` enforces the runnable part of this automatically for
every registered GET route, including ones added later. `SqlPortabilityTest`
pins the meanings, because the dangerous version of this bug is not a 500 but an
expression that runs and returns a differently scaled answer.
