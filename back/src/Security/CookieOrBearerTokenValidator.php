<?php

declare(strict_types=1);

namespace App\Security;

use BadMethodCallException;
use InvalidArgumentException;
use Lcobucci\JWT\Parser;
use Lcobucci\JWT\Signer\Rsa\Sha256;
use Lcobucci\JWT\ValidationData;
use League\OAuth2\Server\AuthorizationValidators\BearerTokenValidator;
use League\OAuth2\Server\CryptTrait;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use Psr\Http\Message\ServerRequestInterface;
use RuntimeException;

class CookieOrBearerTokenValidator extends BearerTokenValidator
{
    use CryptTrait;

    const OAUTH_COOKIE_NAME = 'colllect_oauth2';

    /**
     * @var AccessTokenRepositoryInterface
     */
    private $accessTokenRepository;

    public function __construct(AccessTokenRepositoryInterface $accessTokenRepository)
    {
        parent::__construct($accessTokenRepository);

        $this->accessTokenRepository = $accessTokenRepository;
    }

    /**
     * {@inheritdoc}
     */
    public function validateAuthorization(ServerRequestInterface $request)
    {
        if (!$this->isAuthorizedRequest($request)) {
            throw OAuthServerException::accessDenied('Missing "Authorization" header or "colllect_oauth2" cookie"');
        }

        $jwt = $this->retrieveJwtFromRequest($request);

        try {
            // Attempt to parse and validate the JWT
            $token = (new Parser())->parse($jwt);
            try {
                if ($token->verify(new Sha256(), $this->publicKey->getKeyPath()) === false) {
                    throw OAuthServerException::accessDenied('Access token could not be verified');
                }
            } catch (BadMethodCallException $exception) {
                throw OAuthServerException::accessDenied('Access token is not signed', null, $exception);
            }

            // Ensure access token hasn't expired
            $data = new ValidationData();
            $data->setCurrentTime(time());

            if ($token->validate($data) === false) {
                throw OAuthServerException::accessDenied('Access token is invalid');
            }

            // Check if token has been revoked
            if ($this->accessTokenRepository->isAccessTokenRevoked($token->getClaim('jti'))) {
                throw OAuthServerException::accessDenied('Access token has been revoked');
            }

            // Return the request with additional attributes
            return $request
                ->withAttribute('oauth_access_token_id', $token->getClaim('jti'))
                ->withAttribute('oauth_client_id', $token->getClaim('aud'))
                ->withAttribute('oauth_user_id', $token->getClaim('sub'))
                ->withAttribute('oauth_scopes', $token->getClaim('scopes'))
                ;
        } catch (InvalidArgumentException $exception) {
            // JWT couldn't be parsed so return the request as is
            throw OAuthServerException::accessDenied($exception->getMessage(), null, $exception);
        } catch (RuntimeException $exception) {
            //JWR couldn't be parsed so return the request as is
            throw OAuthServerException::accessDenied('Error while decoding to JSON', null, $exception);
        }
    }

    private function isAuthorizedRequest(ServerRequestInterface $request): bool
    {
        return $request->hasHeader('authorization')
            || \array_key_exists(self::OAUTH_COOKIE_NAME, $request->getCookieParams());
    }

    private function retrieveJwtFromRequest(ServerRequestInterface $request): string
    {
        if ($request->hasHeader('authorization')) {
            $header = $request->getHeader('authorization');
            $jwt = trim(preg_replace('/^(?:\s+)?Bearer\s/', '', $header[0]));

            return $jwt;
        }

        $jwt = (string) $request->getCookieParams()[self::OAUTH_COOKIE_NAME];

        return $jwt;
    }
}