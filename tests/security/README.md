# Security regression suite (go-live A14)

Keeps S1–S8 fixed: one check per bullet in go-live A2–A8, over HTTP.
FluentCommunity validates only over HTTP, so in-process tests would pass
writes the API refuses.

    bash tests/security/run.sh          # everything, ~1.5 min
    bash tests/security/run.sh a2 a8    # some areas

Exit code = failures. `SKIP` lines are checks it could not run (no Mobile
repo, no unenrolled test course).

## What a run does to the site

- Mints app passwords `appza-a14-*` for `raju` and `appza_test_student`.
- Creates rows marked `[A14-PROBE]` / `a14-probe-`: posts, a scheduled
  post, a report, a comment, chat threads, media. The bookmark and raju's
  block of the student are kept in option `appza_a14_ledger`.
- A6 sets the student's FC status to `blocked`, then back (saved in
  option `appza_a14_student_status`).

An exit trap restores the student, deletes the rows by marker and the
ledger, and revokes the passwords, pass or fail. If a run is killed hard,
run it again: cleanup runs on every exit.

## Needs

- wp-cli for fixtures (`A14_WP_CLI`, default: this box's phar with the
  skip list). Users `raju` (admin) and `appza_test_student`.
- For the locked-lesson check: an `APPZA-TEST-COURSE` course the student
  is not enrolled in.
- The Mobile repo for its greps (`A14_MOBILE_REPO`).

Other hosts: `A14_SITE`, `A14_CORE`, `A14_WP_PATH`. To skip minting,
pass `A14_RAJU_AUTH` / `A14_STUDENT_AUTH` as `user:app-password` (and
`A14_LOGOUT_AUTH` + `A14_LOGOUT_UUID` for a password the logout check may
revoke).
