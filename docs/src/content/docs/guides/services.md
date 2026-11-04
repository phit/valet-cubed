---
title: Services
description: Start and stop Valet's services, catch mail with Mailpit, and use Redis.
---

Valet manages Nginx, PHP-FPM, the database server, Redis, Mailpit and its name resolution. They start when your machine boots.

## Starting and stopping

```bash
valet start              # all services
valet restart php nginx  # only these
valet stop mailpit
valet status             # PHP-FPM and Nginx status
```

`start`, `restart` and `stop` accept any of `nginx`, `php`, `mysql`, `redis`, `mailpit` and `hosts` (name resolution). Without a service name they act on all of them.

## Catching mail with Mailpit

[Mailpit](https://mailpit.axllent.org/) catches every email your applications send, so nothing reaches real inboxes while you develop. Its web interface is at `https://mails.test`.

Point your application's SMTP settings at it. For Laravel:

```ini
MAIL_MAILER=smtp
MAIL_HOST=127.0.0.1
MAIL_PORT=1025
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_ENCRYPTION=null
```

Mailpit replaced MailHog. When you upgrade, Valet removes MailHog's service, binary, site and certificates left behind by older releases.

## Redis

Redis listens on the default port, `127.0.0.1:6379`. On RHEL 10 and its rebuilds, which ship Valkey instead of Redis, Valet installs Valkey, which speaks the same protocol.

## Opening projects in your editor

Run these in a project directory, or pass a folder:

| Command | Editor |
| --- | --- |
| `valet code` | Visual Studio Code |
| `valet ps` | PhpStorm |
| `valet subl` | Sublime Text |
| `valet atom` | Atom |
