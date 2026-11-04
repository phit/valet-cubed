---
title: Serving sites
description: Park directories, link single projects, secure sites with HTTPS, and proxy local ports.
---

Sites are served at `http://<name>.test`. To use a different domain, run `valet domain <domain>`, for example `valet domain wip`. Avoid `local`, which belongs to mDNS: Valet won't delegate `local` or `localhost` and falls back to `/etc/hosts` entries for them.

## Parking a directory

`valet park` registers a directory. Every folder inside it becomes a site named after the folder:

```bash
cd ~/sites
valet park
```

Now `~/sites/blog` is served at `http://blog.test`, `~/sites/shop` at `http://shop.test`, and so on. Pass a path to park another directory without changing into it: `valet park ~/work`.

On systems where Valet uses `/etc/hosts` for name resolution, run `valet restart hosts` after creating a new project folder, so its name resolves. See [DNS](/how-it-works/dns/).

| Command | Effect |
| --- | --- |
| `valet paths` | List the parked directories. |
| `valet forget [path]` | Stop serving a parked directory. |

## Linking a single project

To serve one project without parking its parent directory, link it:

```bash
cd ~/projects/api
valet link              # http://api.test
valet link backend      # http://backend.test
```

| Command | Effect |
| --- | --- |
| `valet links` | List all links. |
| `valet unlink [name]` | Remove a link. |

## Securing sites with HTTPS

Valet creates its own certificate authority and adds it to your system's trust store and to the NSS databases browsers use. To serve a site over HTTPS with HTTP/2:

```bash
valet secure blog       # https://blog.test
valet unsecure blog     # back to plain HTTP
valet secured           # list secured sites
```

## Proxying a local port

To give a Node app, a Docker container or anything else listening on a local port a Valet domain:

```bash
valet proxy app http://localhost:3000 --secure    # https://app.test
valet proxies                                     # list proxies
valet unproxy app
```

## Opening and sharing

| Command | Effect |
| --- | --- |
| `valet open [domain]` | Open the current (or given) site in your browser. |
| `valet which` | Show which [driver](/how-it-works/drivers/) serves the current directory. |
| `valet share` | Share the current site on a public URL through [ngrok](https://ngrok.com/). |
| `valet fetch-share-url` | Print the URL of the running ngrok tunnel. |
| `valet ngrok-auth <token>` | Store your ngrok auth token. Sharing needs an ngrok account. |

## Ports

Valet listens on ports 80 and 443. To change them:

```bash
valet port 8080          # HTTP
valet port 8443 --https  # HTTPS
```

## Wildcard subdomains

Where Valet can delegate its domain through systemd-resolved, any subdomain of a site works too: `api.blog.test` reaches `blog.test`. Otherwise only the exact site names resolve. See [DNS](/how-it-works/dns/) for when each applies.
