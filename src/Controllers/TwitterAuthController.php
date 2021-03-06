<?php

/*
 * This file is part of fof/oauth.
 *
 * Copyright (c) 2020 FriendsOfFlarum.
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

namespace FoF\OAuth\Controllers;

use Flarum\Forum\Auth\Registration;
use Flarum\Forum\Auth\ResponseFactory;
use Flarum\Http\UrlGenerator;
use Flarum\Settings\SettingsRepositoryInterface;
use FoF\OAuth\Errors\AuthenticationException;
use Illuminate\Support\Arr;
use Laminas\Diactoros\Response\RedirectResponse;
use League\OAuth1\Client\Credentials\CredentialsException;
use League\OAuth1\Client\Server\Twitter;
use League\OAuth1\Client\Server\User;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class TwitterAuthController implements RequestHandlerInterface
{
    /**
     * @var ResponseFactory
     */
    protected $response;

    /**
     * @var SettingsRepositoryInterface
     */
    protected $settings;

    /**
     * @var UrlGenerator
     */
    protected $url;

    /**
     * @param ResponseFactory             $response
     * @param SettingsRepositoryInterface $settings
     * @param UrlGenerator                $url
     */
    public function __construct(ResponseFactory $response, SettingsRepositoryInterface $settings, UrlGenerator $url)
    {
        $this->response = $response;
        $this->settings = $settings;
        $this->url = $url;
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        try {
            return $this->work($request);
        } catch (CredentialsException $e) {
            $originalMessage = $e->getMessage();
            $error = preg_replace('/Received HTTP status code \[400\] with message "(.+)" when getting temporary credentials\./m', '$1', $originalMessage);
            $details = Arr::get(json_decode($error, true), 'errors.0');

            throw new AuthenticationException(Arr::get($details, 'message', $originalMessage), Arr::get($details, 'code', 0), $e);
        }
    }

    public function work(ServerRequestInterface $request): ResponseInterface
    {
        $redirectUri = $this->url->to('forum')->route('auth.twitter');

        $server = new Twitter([
            'identifier'   => $this->getSetting('api_key'),
            'secret'       => $this->getSetting('api_secret'),
            'callback_uri' => $redirectUri,
        ]);

        $session = $request->getAttribute('session');

        $queryParams = $request->getQueryParams();
        $oAuthToken = Arr::get($queryParams, 'oauth_token');
        $oAuthVerifier = Arr::get($queryParams, 'oauth_verifier');

        if (!$oAuthToken || !$oAuthVerifier) {
            $temporaryCredentials = $server->getTemporaryCredentials();

            $session->put('temporary_credentials', serialize($temporaryCredentials));

            $authUrl = $server->getAuthorizationUrl($temporaryCredentials);

            return new RedirectResponse($authUrl);
        }

        $temporaryCredentials = unserialize($session->get('temporary_credentials'));

        $tokenCredentials = $server->getTokenCredentials($temporaryCredentials, $oAuthToken, $oAuthVerifier);

        $user = $server->getUserDetails($tokenCredentials);

        return $this->response->make(
            'twitter',
            $user->uid,
            function (Registration $registration) use ($user) {
                $this->setSuggestions($registration, $user);
            }
        );
    }

    protected function setSuggestions(Registration $registration, User $user)
    {
        $email = $user->email;

        if (!$email || empty($email)) {
            throw new AuthenticationException('invalid_email');
        }

        $registration
            ->provideTrustedEmail($email)
            ->suggestUsername($user->nickname ?: '')
            ->provideAvatar(str_replace('_normal', '', $user->imageUrl))
            ->setPayload(get_object_vars($user));
    }

    protected function getSetting($key): ?string
    {
        return $this->settings->get("fof-oauth.twitter.{$key}");
    }
}
