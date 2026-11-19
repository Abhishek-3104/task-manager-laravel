# Deploy everything on one AWS EC2 instance

One Ubuntu server runs the whole stack, database included:

```
Internet ──▶ EC2 (Ubuntu 24.04)
              Nginx :80 / :443
               ├── /               React build (static files)
               └── /api, /sanctum, /up   Laravel (PHP 8.3-FPM)
                                      │  X-Service-Token
                                      ▼
              Django overdue service   gunicorn on 127.0.0.1:8001 (not public)
                                      │
              MySQL 8                  127.0.0.1:3306 (not public), nightly backups
              Cron                     overdue sync every 5 minutes, plus a daily safety run
```

React and the API share one address, so the Sanctum session cookie (HttpOnly, SameSite=Lax, plus `Secure` on HTTPS) is first-party and you don't need CORS. Only ports 22, 80 and 443 are open to the internet.

---

## 1. Launch the instance (AWS Console → EC2 → Launch instance)

| Setting | Value |
|---|---|
| Name | `task-manager` |
| AMI | **Ubuntu Server 24.04 LTS** (64-bit x86) |
| Instance type | `t3.small` (2 GB) recommended. `t2.micro` / `t3.micro` (free tier) also works because the script adds 2 GB of swap |
| Key pair | Create one → download `task-manager.pem` |
| Storage | 20 GB gp3 |
| Security group | Inbound: **SSH 22 → My IP**, **HTTP 80 → Anywhere**, **HTTPS 443 → Anywhere** |

Then go to **EC2 → Elastic IPs → Allocate → Associate** with the instance. This keeps the IP the same if you stop and start the server, so your submitted link keeps working.

## 2. Upload the code

From your computer, in the folder that contains `full-stack-assignment.zip`:

```bash
chmod 400 task-manager.pem
scp -i task-manager.pem full-stack-assignment.zip ubuntu@<ELASTIC_IP>:~
ssh -i task-manager.pem ubuntu@<ELASTIC_IP>
```

On the server:

```bash
sudo apt-get update && sudo apt-get install -y unzip
unzip full-stack-assignment.zip -d app
cd app/task-manager-laravel/deploy/aws-ec2
```

> **Using GitHub instead?** Clone the three repos side by side, for example `~/app/task-manager-laravel`, `~/app/task-overdue-service` and `~/app/task-manager-react`, then `cd ~/app/task-manager-laravel/deploy/aws-ec2`.

## 3. Configure and run

```bash
cp deploy.env.example deploy.env
nano deploy.env        # the defaults work as they are: IP over HTTP, MySQL on the instance, demo data
sudo bash setup.sh
```

It takes about 5–10 minutes. When it finishes it prints the URL and the test logins, after running smoke tests: Laravel health, React page, admin login, and the Laravel → Django overdue sync.

Open **http://<ELASTIC_IP>** and log in:

| Role | Email | Password |
|---|---|---|
| Admin | `admin@taskmanager.test` | `Admin@123` |
| User | `user@taskmanager.test` | `User@1234` |

## 4. (Optional) Domain + free HTTPS

1. Create a DNS **A record** for your domain (e.g. `tasks.yourname.com`) pointing to the Elastic IP.
2. In `deploy.env` set `DOMAIN=tasks.yourname.com` and `LETSENCRYPT_EMAIL=you@email.com`.
3. Run `sudo bash setup.sh` again. Certbot installs a certificate, redirects HTTP to HTTPS, and renews automatically.

## 5. Redeploying after code changes

Update the code in `~/app/...` (upload a new zip, or `git pull` in each repo), then:

```bash
cd ~/app/task-manager-laravel/deploy/aws-ec2 && sudo bash setup.sh
```

The script is safe to re-run. It keeps the same secrets and database, runs new migrations, rebuilds React and restarts the services. Demo data is seeded only on the first run.

---

## View the EC2 database in MySQL Workbench (SSH tunnel)

MySQL is installed on the instance and only listens on `127.0.0.1`. Port 3306 stays **closed** in the security group, and Workbench reaches it through SSH instead:

1. On the server, get the database password:
   ```bash
   sudo grep SAVED_DB_PASSWORD /etc/task-manager/secrets.env
   ```
2. In MySQL Workbench → **+ (new connection)** → Connection Method **Standard TCP/IP over SSH**:

   | Field | Value |
   |---|---|
   | SSH Hostname | `<ELASTIC_IP>:22` |
   | SSH Username | `ubuntu` |
   | SSH Key File | your `task-manager.pem` |
   | MySQL Hostname | `127.0.0.1` |
   | MySQL Server Port | `3306` |
   | Username | `task_manager` |
   | Password | *Store in Vault…* → the password from step 1 |
   | Default Schema | `task_manager` |

3. **Test Connection** → OK. You can now browse the `users`, `projects` and `tasks` tables on the live server.

---

## Where things are

| What | Where |
|---|---|
| Generated secrets (DB password, APP_KEY, service token) | `/etc/task-manager/secrets.env` (root only) |
| Laravel app / logs | `/var/www/task-manager-laravel`, `storage/logs/` |
| Django service | `/var/www/task-overdue-service`, systemd unit `task-overdue` |
| React build | `/var/www/task-manager-react/dist` |
| Nginx site | `/etc/nginx/sites-available/task-manager` |
| Cron jobs | `/etc/cron.d/task-manager`, `/etc/cron.d/task-manager-backup` |
| MySQL database / app user | `task_manager` / `task_manager` (password in `secrets.env` as `SAVED_DB_PASSWORD`) |
| DB backups | `/var/backups/task-manager/` (7 days) |

## Troubleshooting

```bash
sudo systemctl status nginx php8.3-fpm task-overdue mysql
sudo journalctl -u task-overdue -n 100          # Django service logs
sudo tail -n 100 /var/www/task-manager-laravel/storage/logs/laravel-*.log
sudo tail -n 100 /var/log/nginx/error.log
curl -s http://127.0.0.1/up                       # Laravel health
curl -s http://127.0.0.1:8001/health/             # Django health (from the server)
sudo mysql task_manager -e "select id,title,status,due_date from tasks"
```

| Symptom | Fix |
|---|---|
| Browser can't reach the site | Security group must allow port 80 (and 443) from Anywhere |
| `502 Bad Gateway` on `/api` | `sudo systemctl restart php8.3-fpm`, then check the Laravel log |
| Login fails with 419 or 401 | Open the site using exactly the host in `deploy.env` (the Elastic IP, or `DOMAIN`). `SANCTUM_STATEFUL_DOMAINS` is set from it. If you add a domain later, re-run `setup.sh` |
| Logged out right after logging in on HTTPS | Make sure you're on `https://`. On HTTPS the session cookie is `Secure` |
| Status change returns 503 | Django is down: `sudo systemctl restart task-overdue`, then check `journalctl` |
| Composer or npm killed during setup | Out of memory: use `t3.small`, or check swap with `free -h` |
| Certbot fails | The DNS A record must already point to the Elastic IP, and port 80 must be open |

## Cost tips

* A `t2.micro`/`t3.micro` plus 20 GB is covered by the AWS free tier for 12 months on new accounts. A `t3.small` costs roughly $15/month.
* An Elastic IP is free only while it's attached to a running instance.
* **Stop** the instance when you're not using it (the Elastic IP and data are kept). **Terminate** it, and release the Elastic IP, once the review is over.
