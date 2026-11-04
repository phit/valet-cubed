---
title: Databases
description: The MySQL or MariaDB server Valet installs, and the db commands for working with it.
---

`valet install` installs MySQL, or MariaDB where the distribution doesn't offer MySQL (Debian and openSUSE) or when you pass `--mariadb`. It creates a `valet` database user with the password you choose during the install, and the `db:` commands log in as that user.

If a database server was already installed, Valet uses it. The installer asks for a user and password it can log in with, and stores them in `~/.config/valet/config.json`. To change them later:

```bash
valet db:configure --force
```

## Commands

Most commands take an optional database name. Without one, Valet uses the current directory's name.

| Command | Effect |
| --- | --- |
| `valet db:list` | List databases. |
| `valet db:create [name]` | Create a database. |
| `valet db:drop [name] [--yes]` | Drop a database. |
| `valet db:reset [name] [--yes]` | Drop all tables in a database. |
| `valet db:export [name] [--sql]` | Export a database to a gzipped dump, or plain SQL with `--sql`. |
| `valet db:import [name] [file]` | Import a `.sql` or `.sql.gz` dump, with a progress bar. |

## Connecting from your application

The `valet` user is created for local connections (`'valet'@'localhost'`), so connect to `localhost`, which uses the server's Unix socket, with the password you chose. For Laravel:

```ini
DB_CONNECTION=mysql
DB_HOST=localhost
DB_USERNAME=valet
DB_PASSWORD=your-password
```
