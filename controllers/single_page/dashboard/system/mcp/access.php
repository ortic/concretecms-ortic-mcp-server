<?php

namespace Concrete\Package\OrticMcpServer\Controller\SinglePage\Dashboard\System\Mcp;

use Concrete\Core\Page\Controller\DashboardPageController;
use Concrete\Core\User\User;
use Concrete\Core\User\UserInfoRepository;
use Ortic\McpServer\Audit\AuditLog;
use Ortic\McpServer\Auth\OAuthService;
use Ortic\McpServer\Auth\Permissions;
use Ortic\McpServer\Auth\Scopes;
use Ortic\McpServer\Auth\TokenService;
use Ortic\McpServer\Http\Urls;
use Ortic\McpServer\Settings;

defined('C5_EXECUTE') or die('Access Denied.');

class Access extends DashboardPageController
{
    private const FLASH_SECRET = 'ortic_mcp_secret';

    public function view()
    {
        $tokens = $this->app->make(TokenService::class);
        $settings = $this->app->make(Settings::class);
        $this->set('personalTokens', $tokens->listPersonalTokens());
        $this->set('grants', $tokens->listActiveGrants());
        $this->set('clients', $this->app->make(OAuthService::class)->listClients());
        $this->set('users', $this->app->make(UserInfoRepository::class));
        $this->set('canUseMcp', Permissions::canUseMcp());
        $this->set('canGrantDeveloper', $settings->isDeveloperEnabled() && Permissions::canUseDeveloperTools());
        $this->set('developerEnabled', $settings->isDeveloperEnabled());
        $this->set('endpoint', $this->app->make(Urls::class)->endpoint());
        $secret = $this->app->make('session')->getFlashBag()->get(self::FLASH_SECRET);
        $this->set('secret', $secret[0] ?? null);
    }

    public function create_token()
    {
        if (!$this->token->validate('ortic_mcp_create_token')) {
            $this->error->add($this->token->getErrorMessage());

            return $this->view();
        }
        if (!Permissions::canUseMcp()) {
            $this->error->add(t('You need the "Use the MCP server" task permission to create tokens.'));

            return $this->view();
        }
        $name = trim((string) $this->request->request->get('name'));
        if ($name === '') {
            $this->error->add(t('Give the token a name, for example the app or computer that will use it.'));

            return $this->view();
        }
        $days = (int) $this->request->request->get('expires_days');
        $expires = $days > 0 ? new \DateTimeImmutable('+' . $days . ' days') : null;
        $scopes = [Scopes::STANDARD];
        $settings = $this->app->make(Settings::class);
        if ($this->request->request->get('developer') && $settings->isDeveloperEnabled() && Permissions::canUseDeveloperTools()) {
            $scopes[] = Scopes::DEVELOPER;
        }
        $user = $this->app->make(User::class);
        $created = $this->app->make(TokenService::class)->createPersonalToken((int) $user->getUserID(), $name, $scopes, $expires);
        $this->app->make(AuditLog::class)->log('token_created', (int) $user->getUserID(), $created['id'], null, ['name' => $name, 'scopes' => $scopes], true, null, null, (string) $this->request->getClientIp());
        // FlashBag::set() takes a list of messages.
        $this->app->make('session')->getFlashBag()->set(self::FLASH_SECRET, [[
            'title' => t('Personal access token "%s"', $name),
            'values' => ['Token' => $created['token']],
        ]]);

        return $this->buildRedirect($this->action());
    }

    public function revoke_token()
    {
        if (!$this->token->validate('ortic_mcp_revoke')) {
            $this->error->add($this->token->getErrorMessage());

            return $this->view();
        }
        $tokens = $this->app->make(TokenService::class);
        $id = (int) $this->request->request->get('id');
        $row = $tokens->getById($id);
        if ($row) {
            $tokens->revokeById($id);
            $this->audit('token_revoked', ['id' => $id, 'name' => $row['name']]);
        }
        $this->flash('success', t('The token was revoked.'));

        return $this->buildRedirect($this->action());
    }

    public function revoke_grant()
    {
        if (!$this->token->validate('ortic_mcp_revoke')) {
            $this->error->add($this->token->getErrorMessage());

            return $this->view();
        }
        $grantId = (string) $this->request->request->get('grant');
        $this->app->make(TokenService::class)->revokeGrant($grantId);
        $this->audit('grant_revoked', ['grant' => $grantId]);
        $this->flash('success', t('The app was disconnected.'));

        return $this->buildRedirect($this->action());
    }

    public function create_client()
    {
        if (!$this->token->validate('ortic_mcp_create_client')) {
            $this->error->add($this->token->getErrorMessage());

            return $this->view();
        }
        $oauth = $this->app->make(OAuthService::class);
        $name = trim((string) $this->request->request->get('client_name'));
        $uris = $this->app->make(Settings::class)->stringList((string) $this->request->request->get('redirect_uris'));
        if ($name === '') {
            $this->error->add(t('Enter a name.'));
        }
        if ($uris === []) {
            $this->error->add(t('Enter at least one redirect URI.'));
        }
        foreach ($uris as $uri) {
            if (($reason = $oauth->whyRedirectUriIsNotAllowed($uri)) !== null) {
                $this->error->add($reason);
            }
        }
        if ($this->error->has()) {
            return $this->view();
        }
        $method = $this->request->request->get('confidential') ? 'client_secret_post' : 'none';
        $client = $oauth->createClient($name, $uris, $method, false, (string) $this->request->getClientIp());
        $this->audit('oauth_client_created', ['client_id' => $client['client_id'], 'name' => $name]);
        $values = ['Client ID' => $client['client_id']];
        if ($client['client_secret'] !== null) {
            $values['Client secret'] = $client['client_secret'];
        }
        $this->app->make('session')->getFlashBag()->set(self::FLASH_SECRET, [[
            'title' => t('OAuth client "%s"', $name),
            'values' => $values,
        ]]);

        return $this->buildRedirect($this->action());
    }

    public function delete_client()
    {
        if (!$this->token->validate('ortic_mcp_revoke')) {
            $this->error->add($this->token->getErrorMessage());

            return $this->view();
        }
        $clientId = (string) $this->request->request->get('client_id');
        $this->app->make(TokenService::class)->revokeAllForClient($clientId);
        $this->app->make(OAuthService::class)->deleteClient($clientId);
        $this->audit('oauth_client_deleted', ['client_id' => $clientId]);
        $this->flash('success', t('The client was removed and its tokens revoked.'));

        return $this->buildRedirect($this->action());
    }

    private function audit(string $event, array $data): void
    {
        $this->app->make(AuditLog::class)->log($event, (int) $this->app->make(User::class)->getUserID(), null, null, $data, true, null, null, (string) $this->request->getClientIp());
    }
}
