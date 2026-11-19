# Task Manager API (Laravel 12)

The main backend for the Task & Project Management System. It handles authentication, projects and tasks, and role-based access.
Every status change is checked against the overdue rules owned by the **Django overdue service** ([task-overdue-service](../task-overdue-service)).

| Part | Repo |
|---|---|
| Laravel API (this repo) | `task-manager-laravel` |
| Django overdue-rules service | `task-overdue-service` |
| React frontend | `task-manager-react` |

## Architecture

```
React SPA ──HttpOnly session cookie + CSRF──▶ Laravel API ──X-Service-Token──▶ Django overdue service
                                                 │                                   │
                                                 └──────────── MySQL (shared) ◀──────┘
```

* **Auth** uses Laravel Sanctum *SPA authentication*. After login the browser holds only an encrypted, **HttpOnly**, `SameSite=Lax` session cookie that JavaScript can't read. State-changing requests are CSRF-protected through the `XSRF-TOKEN` cookie and `X-XSRF-TOKEN` header. No tokens are stored in `localStorage`.

* **Laravel** owns the schema (migrations), auth, projects, tasks and permissions.
* **Django** owns the overdue rules:
  * `POST /api/v1/transitions/validate/`: Laravel asks before *every* status change.
  * `POST /api/v1/overdue/sync/`: a sweep that marks past-due tasks as `OVERDUE` (Laravel's scheduler calls it every 5 minutes, plus a nightly cron job as a safety net).
* If Django is unreachable, status changes **fail closed** with `503`. A status is never changed without the rules being checked.

### Code structure

```
app/
├── Enums/                 TaskStatus, TaskPriority, UserRole
├── Http/
│   ├── Controllers/Api/V1 Thin controllers: validate → call service → return resource
│   ├── Requests/          Form Requests (validation + authorization)
│   ├── Resources/         API Resources (output shape)
│   └── Responses/         ApiResponse: the single JSON envelope
├── Models/                Eloquent models + query scopes (visibleTo, filter)
├── Policies/              ProjectPolicy, TaskPolicy, UserPolicy
├── Services/              AuthService, ProjectService, TaskService
│   └── Overdue/           OverdueServiceClient (HTTP client for Django) + DTO
├── Exceptions/            Domain exceptions that render themselves as JSON
└── Console/Commands/      tasks:sync-overdue (scheduled every 5 minutes)
```

### Best practices applied

* **Form Requests** for all input validation *and* authorization (`authorize()` uses policies).
* **Service layer**: controllers contain no business logic.
* **Policies**: admins manage everything; members see and update only their assigned tasks.
* **Eager loading everywhere**: `with()` / `withCount()` with column selection. `Model::shouldBeStrict()` makes lazy loading (N+1) throw an exception outside production.
* **Consistent JSON envelope** for success *and* errors (validation, 401, 403, 404, 429, 500, 503):
  ```json
  { "success": true, "message": "OK", "data": { }, "errors": null, "meta": { } }
  ```
* **Mass-assignment safety**: `role` and `status` are not fillable. Registering always creates a `member`, and status changes only through the rule-checked endpoint.
* **Row locking** (`lockForUpdate`) during status changes, so concurrent requests can't both pass validation.
* **Rate limiting**: 10/min on login and register, 120/min on the API.
* **Indexes** on `(status, due_date)` for the overdue sweep and `(assigned_to, status)` for "my tasks".
* **Secure sessions**: HttpOnly + SameSite=Lax cookie (+ `Secure` on HTTPS), session regenerated on login (no session fixation), invalidated on logout, CSRF on every write.
* **API versioning** (`/api/v1`).

## API

All endpoints are prefixed with `/api/v1`. Authentication uses the session cookie:

1. `GET /sanctum/csrf-cookie` sets the `XSRF-TOKEN` cookie.
2. `POST /api/v1/auth/login` with the header `X-XSRF-TOKEN: <cookie value>` sets the HttpOnly session cookie.
3. Send later requests with cookies (and `X-XSRF-TOKEN` on POST/PUT/PATCH/DELETE).

Requests must come from a host listed in `SANCTUM_STATEFUL_DOMAINS` (the `Origin`/`Referer` header). Axios does all of this automatically with `withCredentials` and `withXSRFToken`. To test in Postman, send `Referer: http://localhost:5173` and copy the `XSRF-TOKEN` cookie into the `X-XSRF-TOKEN` header.

| Method | Endpoint | Who | Notes |
|---|---|---|---|
| POST | `/auth/register` | public | Always creates a `member` |
| POST | `/auth/login` | public | Returns the user; sets the HttpOnly session cookie |
| GET | `/auth/me` | any | |
| POST | `/auth/logout` | any | Invalidates the session |
| GET | `/users?role=member` | admin | For the "assign to" dropdown |
| GET | `/projects?search=&page=` | any | Members only see projects that contain their tasks |
| POST | `/projects` | admin | |
| GET | `/projects/{id}` | admin / member with tasks in it | Includes tasks (members: only their own) |
| PUT/PATCH | `/projects/{id}` | admin | |
| DELETE | `/projects/{id}` | admin | Cascades to tasks |
| GET | `/tasks?status=&priority=&project_id=&search=` | any | Members: only their tasks |
| POST | `/tasks` | admin | New tasks always start as `TODO` |
| GET | `/tasks/{id}` | admin / assignee | |
| PUT/PATCH | `/tasks/{id}` | admin | Title, description, priority, due date, assignee |
| PATCH | `/tasks/{id}/status` | admin / assignee | `{ "status": "IN_PROGRESS" }`, checked by Django |
| DELETE | `/tasks/{id}` | admin | |

**Task status rules** (enforced by Django):
* Past `due_date` and not `DONE` → `OVERDUE`
* `OVERDUE` → `IN_PROGRESS` / `TODO`: never allowed
* `OVERDUE` → `DONE`: admin only
* `OVERDUE` can't be set by hand. Reopening a `DONE` task is admin-only and only allowed before the due date.

Every task in the API also includes `is_overdue`, so the UI is correct even between two runs of the sweep.

## Local setup

Requirements: PHP 8.2+ (with the `pdo_mysql`, `mbstring`, `xml`, `curl`, `zip`, `bcmath` and `intl` extensions), Composer 2, MySQL 8.

`composer.lock` is committed, so `composer install` installs exactly the tested versions (Laravel 12.22, Sanctum 4.2).

```bash
composer install
cp .env.example .env
php artisan key:generate
# create the `task_manager` schema in MySQL Workbench, then set DB_USERNAME, DB_PASSWORD and OVERDUE_SERVICE_TOKEN in .env
php artisan migrate --seed
php artisan serve            # http://localhost:8000
php artisan schedule:work    # runs the overdue sync every 5 minutes (optional locally)
```

Start the Django service too (see its README). `OVERDUE_SERVICE_TOKEN` here must match `SERVICE_TOKEN` there.
Run React with `npm run dev`: the Vite dev server proxies `/api` and `/sanctum` to `localhost:8000`, so the browser sees one origin and the session cookie works exactly as it does in production.

## Test credentials (created by the seeder)

| Role | Email | Password |
|---|---|---|
| Admin | `admin@taskmanager.test` | `Admin@123` |
| User | `user@taskmanager.test` | `User@1234` |
| User | `priya@taskmanager.test` | `User@1234` |

## Deployment on AWS EC2 (with MySQL)

See [`deploy/aws-ec2/README.md`](deploy/aws-ec2/README.md). One script (`deploy/aws-ec2/setup.sh`) provisions Ubuntu 24.04 with
Nginx, PHP-FPM, MySQL 8 (installed on the instance), the Django service (gunicorn + systemd), the React build, cron jobs, nightly DB backups and optional Let's Encrypt HTTPS.
