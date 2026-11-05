# MCP Server for Concrete CMS

A Concrete CMS package that turns your site into a [Model Context Protocol](https://modelcontextprotocol.io/) server, so AI assistants such as Claude can read and edit your content. Unlike the [Node based server](https://github.com/concrete5-community/concretecms-mcp-server), it runs inside Concrete in plain PHP: no Node, no extra process, no REST API integration to configure.

- MCP endpoint at `https://your-site/ccm/mcp` (Streamable HTTP transport, protocol versions 2024-11-05 to 2025-11-25)
- OAuth 2.1 sign-in with PKCE, dynamic client registration and discovery metadata, so Claude Desktop and claude.ai can connect with just the URL
- Personal access tokens for Claude Code, Cursor, scripts and other clients
- Every tool runs as the connected Concrete user and respects all Concrete permissions and workflows
- Optional developer mode to let the assistant read and change code in `application/` and `packages/`, with automatic backups and one-click undo
- Dashboard pages for settings, tokens and connected apps, the audit log and the file history

## Requirements

- Concrete CMS 9.5 or newer, PHP 8.1 or newer
- HTTPS and a site reachable from the internet if you connect Claude Desktop or claude.ai (they connect from Anthropic's servers)
- For OAuth discovery: the site at the root of its domain, with `/.well-known/` URLs reaching Concrete (they do with pretty URLs enabled, Concrete's default `.htaccess`, or a standard nginx `try_files` setup)

## Installation

```bash
cd /path/to/site/packages
git clone git@github.com:ortic/concretecms-ortic-mcp-server.git ortic_mcp_server
cd .. && vendor/bin/concrete c5:package:install ortic_mcp_server   # or concrete/bin/concrete, or Dashboard > Extend Concrete
```

The package adds **System & Settings > MCP Server** to the dashboard. Open **Settings** and click **Run connection check** to verify that the endpoint, the `Authorization` header and the OAuth metadata work on your server.

## Connecting a client

**Claude Desktop / claude.ai:** Settings > Connectors > Add custom connector, enter `https://your-site/ccm/mcp`, click Connect. Your browser opens your site: sign in and click **Allow access**.

**Claude Code** (OAuth or token):

```bash
claude mcp add --transport http concrete https://your-site/ccm/mcp
# or, with a token from System & Settings > MCP Server > Access:
claude mcp add --transport http concrete https://your-site/ccm/mcp --header "Authorization: Bearer cmcp_pat_..."
```

**Other clients:** any MCP client that supports Streamable HTTP works with OAuth or with a personal access token sent as `Authorization: Bearer <token>`.

## Who can use it

Two task permissions are installed (Dashboard > System & Settings > Permissions & Access > Task Permissions):

| Permission | Default | Meaning |
|---|---|---|
| Use the MCP server | Administrators | Connect apps and create tokens. The tools still check every normal permission (view page, edit page contents, approve versions, add files, edit users ...). |
| Use MCP developer tools | nobody (only the super administrator) | Use developer mode tools, when developer mode is enabled and the token or connection was granted developer access. |

## Tools

Enabled by default (each group can be switched off in the settings):

| Group | Tools |
|---|---|
| Site | `get_site_info`, `list_attribute_keys` |
| Pages | `search_pages`, `get_page`, `get_page_content`, `list_page_types`, `create_page`, `update_page`, `move_page`, `delete_page`, `list_page_versions`, `approve_page_version` |
| Blocks | `list_block_types`, `get_block_type`, `add_block`, `update_block`, `delete_block` |
| Files | `search_files`, `browse_file_folder`, `get_file`, `upload_file`, `update_file`, `delete_file`, `create_file_folder` |
| Users | `search_users`, `get_user`, `create_user`, `update_user`, `delete_user` |
| Groups | `list_groups`, `create_group`, `add_user_to_group`, `remove_user_from_group` |

Off by default: **Express** (`list_express_objects`, `search_express_entries`, `get_express_entry`, `create_express_entry`, `update_express_entry`, `delete_express_entry`) for the objects you select.

How content changes work:

- Page and block changes are saved to the newest **unapproved version**, like editing in the browser. Pass `approve: true` or call `approve_page_version` to publish; approval goes through the page workflow if one is configured.
- `update_block` only changes the fields you pass and keeps the others (Concrete's own block save would clear them).
- `delete_page` moves pages to the trash. Deleting files, users and Express entries is permanent.
- **Read-only mode** in the settings hides every tool that changes something.

## Developer mode

Off by default. When enabled in the settings, users with the "Use MCP developer tools" permission can grant developer access to a token or an OAuth connection, which adds:

| Tools | |
|---|---|
| Files | `dev_list_files`, `dev_read_file`, `dev_search_files`, `dev_write_file`, `dev_edit_file`, `dev_delete_file` |
| History | `dev_list_file_history`, `dev_restore_file` |
| Maintenance | `dev_clear_cache`, `dev_get_logs`, `dev_list_packages`, `dev_install_package`, `dev_upgrade_package`, `dev_install_block_type`, `dev_refresh_entities` |

Guard rails:

- Writes are limited to `application/` and `packages/` (configurable) and to a list of file extensions. `concrete/` can be read but never written.
- Always protected: `application/config/database.php`, `application/config/generated_overrides/`, `application/files/`, `vendor/`, `node_modules/` and VCS directories, `.env` files, and this package itself. Paths with `..` and symlinks leading out of the allowed directories are refused.
- PHP files are syntax-checked with PHP's own parser before they are saved; a file with a syntax error is not written.
- Every create, change and delete stores the previous content in the database. **System & Settings > MCP Server > File History** shows each change side by side with the current file and can undo it, even after developer mode has been switched off.
- Files are written atomically and the opcode cache is invalidated.

**Read this before enabling it.** An assistant that can write PHP can run any code on the server: it can read the database credentials and do anything the web server user can. The protections above prevent mistakes, they are not a security boundary. The assistant also reads content from your site (form submissions, comments, imported text), and such content can contain instructions meant to manipulate it. Use developer mode on development or staging sites, or on sites you can restore from a backup, and only grant it to people who would also get SFTP access. If a change breaks the site so badly that the dashboard no longer loads, restore the file through SFTP or Git; the previous content is also in the `OrticMcpFileHistory` table.

## Security notes

- Tokens are random 256-bit values; only their SHA-256 hash is stored. Personal tokens are shown once.
- OAuth: authorization codes are single-use, PKCE (S256) is mandatory, refresh tokens rotate on every use, and reusing an old refresh token revokes the whole connection. Access tokens last one hour by default.
- Dynamic client registration is limited to redirect URIs on an allowlist (by default Claude's callback and localhost), which prevents phishing with fake apps. The consent screen shows the app, the account and where you will be sent back to, and cannot be framed.
- Requests with an `Origin` header from another site are rejected (DNS rebinding protection required by the MCP spec). Add origins in the settings only for browser-based clients like the MCP Inspector.
- Every tool call is logged with its arguments (passwords and file contents redacted or truncated) under **Activity**.
- Revoke tokens and disconnect apps under **Access**; disabling a user or removing their permission blocks their tokens immediately.

## Troubleshooting

- **The connection check says the Authorization header is removed:** on Apache with PHP-FPM/CGI add `CGIPassAuth On` or `RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]` to `.htaccess`.
- **Claude cannot connect:** the site must be reachable over HTTPS from the internet, `https://your-site/.well-known/oauth-protected-resource/ccm/mcp` must return JSON, and `https://claude.ai/api/mcp/auth_callback` must be in the redirect URI allowlist.
- **"Unknown or disabled tool":** the tool group is switched off, read-only mode is on, or the token lacks developer access.

## Development

The repository root is the package. A disposable Concrete site for development can be created with [DDEV](https://ddev.com/):

```bash
ddev config --project-name=ortic-mcp --project-type=php --docroot=dev/public --php-version=8.3 --composer-root=dev
cat > .ddev/docker-compose.package.yaml <<'EOF'
services:
  web:
    volumes:
      - ../:/var/www/html/dev/public/packages/ortic_mcp_server
EOF
ddev start
ddev composer create-project -n concretecms/composer .
mkdir -p dev/public/application/files dev/public/packages/ortic_mcp_server
ddev restart
ddev exec -d /var/www/html/dev vendor/bin/concrete c5:install -n --db-server=db --db-username=db --db-password=db --db-database=db \
  --site="MCP Dev" --starting-point=atomik_full --admin-email=admin@example.com --admin-password=<password> --canonical-url=https://ortic-mcp.ddev.site/
ddev exec -d /var/www/html/dev vendor/bin/concrete c5:package:install ortic_mcp_server
```

`dev/` and `.ddev/` are not part of the package (see `.gitignore`).
