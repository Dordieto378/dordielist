# Initial administrator on Laravel Cloud

Before the next deployment, configure `ADMIN_EMAIL`, `ADMIN_USERNAME` (defaults to
`admin`), and `ADMIN_PASSWORD` in the Cloud environment. Use a password of 15-72
characters containing uppercase, lowercase, a number, and a symbol. Do not commit
the password to source control.

Keep the deploy command `php artisan migrate --force`. The new migration creates
the Admin and Viewer roles by name, then creates an active administrator whose
password is hashed by the User model. If an admin already exists, it leaves that
account's password, email, role, and status unchanged. An email or username already
owned by a non-admin causes setup to fail rather than granting that account access.

If deployment stops at this migration, its error identifies the rejected setting
without printing credential values. Check the variables in the Cloud environment
being deployed: missing values, password requirements, and email or username
collisions are reported separately. A blank `ADMIN_USERNAME` does not use the
default; remove that variable or supply an unused username. Password examples are
placeholders, not passwords to copy. Save corrected variables and redeploy; the
failed migration can be retried without resetting the database.

After setup succeeds, remove `ADMIN_PASSWORD` from the Cloud environment and
redeploy. Existing admin credentials and API keys remain stored in the database;
the bootstrap variables are not needed for subsequent logins or deployments.

Registrations create pending Viewer accounts. Approval notifications go to every
active Admin account's current database email address, using the configured mail
transport. Configure and test SMTP before accepting registrations. If delivery to
one admin fails, the app logs the error and still attempts delivery to the others.
The existing signed approval link remains valid for seven days.
